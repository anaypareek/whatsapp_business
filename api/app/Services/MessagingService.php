<?php
namespace App\Services;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Throwable;

/** Sends via WhatsAppService and persists outbound messages. */
class MessagingService
{
    public function __construct(protected WhatsAppService $wa) {}

    public static function mediaTypeFor(?string $mime): string
    {
        $mime = (string) $mime;
        return match (true) {
            str_starts_with($mime, 'image/') && in_array($mime, ['image/jpeg', 'image/png']) => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    /** Send an APPROVED template. Throws ValidationException for bad params/unapproved templates. */
    public function sendTemplate(PhoneNumber $from, string $to, MessageTemplate $t, array $bodyParams = [], ?string $headerParam = null, array $buttonParams = [], string $source = 'manual'): Message
    {
        if ($t->status !== 'APPROVED') {
            throw \Illuminate\Validation\ValidationException::withMessages(['template' => "Template '{$t->name}' ({$t->language}) is {$t->status}; only APPROVED templates can be sent."]);
        }
        $components = TemplateBuilder::sendComponents($t, $bodyParams, $headerParam, $buttonParams);
        $to = preg_replace('/\D/', '', $to);
        $hf = $t->header_format;
        $msg = Message::create([
            'phone_number_id' => $from->id, 'contact_wa_id' => $to, 'direction' => 'out', 'type' => 'template',
            'body' => TemplateBuilder::render($t, $bodyParams, $headerParam), 'template_name' => $t->name.' ('.$t->language.')', 'message_template_id' => $t->id,
            'media_url' => in_array($hf, ['IMAGE', 'DOCUMENT', 'VIDEO']) && preg_match('#^https?://#', (string) $headerParam) ? $headerParam : null,
            'status' => 'pending', 'source' => $source,
        ]);
        try {
            $res = $this->wa->sendTemplate($from->phone_number_id, $to, $t->name, $t->language, $components);
            $msg->fill(['wamid' => $res['messages'][0]['id'] ?? null, 'status' => 'sent', 'raw' => $res]);
        } catch (Throwable $e) {
            $msg->fill(['status' => 'failed', 'error' => $e->getMessage()]);
        }
        $msg->save();
        return $msg;
    }

    public function sendInteractive(PhoneNumber $from, string $to, array $interactive, ?string $text, string $source = 'flow'): Message
    {
        $to = preg_replace('/\D/', '', $to);
        $opts = collect($interactive['action']['buttons'] ?? [])->pluck('reply.title')
            ->merge(collect($interactive['action']['sections'] ?? [])->flatMap(fn ($sec) => collect($sec['rows'] ?? [])->pluck('title')));
        $msg = Message::create(['phone_number_id' => $from->id, 'contact_wa_id' => $to, 'direction' => 'out', 'type' => 'interactive',
            'body' => trim($text."\n".$opts->map(fn ($t, $i) => ($i + 1).'. '.$t)->implode("\n")), 'status' => 'pending', 'source' => $source]);
        try {
            $res = $this->wa->sendInteractive($from->phone_number_id, $to, $interactive);
            $msg->fill(['wamid' => $res['messages'][0]['id'] ?? null, 'status' => 'sent', 'raw' => $res]);
        } catch (Throwable $e) {
            $msg->fill(['status' => 'failed', 'error' => $e->getMessage()]);
        }
        $msg->save();
        return $msg;
    }

    public function send(PhoneNumber $from, string $to, ?string $text, ?UploadedFile $file = null, ?string $mediaUrl = null, string $source = 'manual'): Message
    {
        $to = preg_replace('/\D/', '', $to);
        $msg = Message::create([
            'phone_number_id' => $from->id, 'contact_wa_id' => $to, 'direction' => 'out',
            'type' => 'text', 'body' => $text, 'status' => 'pending', 'source' => $source,
        ]);
        try {
            if ($file) {
                $mime = $file->getMimeType();
                $type = self::mediaTypeFor($mime);
                $stored = $file->store('media', 'public');
                $mediaId = $this->wa->uploadMedia($from->phone_number_id, $file);
                $payload = ['id' => $mediaId];
                if ($text && $type !== 'audio') $payload['caption'] = $text;
                if ($type === 'document') $payload['filename'] = $file->getClientOriginalName();
                $msg->fill(['type' => $type, 'media_id' => $mediaId, 'media_url' => '/storage/'.$stored,
                    'filename' => $file->getClientOriginalName(), 'mime_type' => $mime]);
                $res = $this->wa->sendMedia($from->phone_number_id, $to, $type, $payload);
            } elseif ($mediaUrl) {
                $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                $type = in_array($ext, ['jpg', 'jpeg', 'png']) ? 'image' : (in_array($ext, ['mp4', '3gp']) ? 'video' : 'document');
                $payload = ['link' => $mediaUrl];
                if ($text) $payload['caption'] = $text;
                if ($type === 'document') $payload['filename'] = basename(parse_url($mediaUrl, PHP_URL_PATH) ?: 'file');
                $msg->fill(['type' => $type, 'media_url' => $mediaUrl, 'filename' => $payload['filename'] ?? null]);
                $res = $this->wa->sendMedia($from->phone_number_id, $to, $type, $payload);
            } else {
                $res = $this->wa->sendText($from->phone_number_id, $to, (string) $text);
            }
            $msg->fill(['wamid' => $res['messages'][0]['id'] ?? null, 'status' => 'sent', 'raw' => $res]);
            if ($name = $res['contacts'][0]['wa_id'] ?? null) $msg->contact_wa_id = $name;
        } catch (Throwable $e) {
            $msg->fill(['status' => 'failed', 'error' => $e->getMessage()]);
        }
        $msg->save();
        return $msg;
    }
}
