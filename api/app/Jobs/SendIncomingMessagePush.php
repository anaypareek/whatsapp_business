<?php
namespace App\Jobs;

use App\Models\Message;
use App\Services\ErrorLogger;
use App\Services\FcmService;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Push "new WhatsApp message" to the owning client's phones and to all staff phones (Master app). Dispatched with dispatchAfterResponse() from the
 * webhook (shared hosting has no queue worker), so Meta gets its 200 first. One notification tag per conversation.
 */
class SendIncomingMessagePush
{
    use Dispatchable;

    public function __construct(public int $messageId) {}

    public function handle(FcmService $fcm): void
    {
        try {
            $m = Message::with('phoneNumber:id,client_id,display_number')->find($this->messageId);
            $clientId = $m?->phoneNumber?->client_id;
            if (! $m || $m->direction !== 'in') return;
            $title = $m->contact_name ?: '+'.$m->contact_wa_id;
            $data = ['type' => 'chat_message', 'phone_number_id' => $m->phone_number_id, 'contact' => $m->contact_wa_id,
                'contact_name' => $m->contact_name ?? '', 'message_id' => $m->id];
            $tag = 'chat_'.$m->phone_number_id.'_'.$m->contact_wa_id;
            if ($clientId) $fcm->sendToClient($clientId, $title, self::preview($m), $data, $tag);
            // staff (Master app) get every inbound message, on all numbers
            $fcm->sendToStaff($title.' · '.($m->phoneNumber->display_number ?? ''), self::preview($m), $data, $tag);
        } catch (Throwable $e) {
            ErrorLogger::exception($e, 'warning');
        }
    }

    public static function preview(Message $m): string
    {
        $label = ['image' => '📷 Photo', 'video' => '🎥 Video', 'audio' => '🎤 Audio', 'document' => '📄 '.($m->filename ?: 'Document'), 'sticker' => 'Sticker', 'location' => '📍 Location'][$m->type] ?? null;
        $text = trim((string) $m->body);
        $s = $label ? ($text !== '' ? "$label · $text" : $label) : ($text !== '' ? $text : 'New message');
        return mb_strlen($s) > 120 ? mb_substr($s, 0, 117).'…' : $s;
    }
}
