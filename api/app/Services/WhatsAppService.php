<?php
namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** All outbound calls to Meta's Graph API go through this class (faked in tests via Http::fake). */
class WhatsAppService
{
    public function baseUrl(): string
    {
        return rtrim(config('services.whatsapp.graph_base', 'https://graph.facebook.com'), '/').'/'.config('services.whatsapp.graph_version', 'v26.0');
    }

    protected function client(): PendingRequest
    {
        $token = Setting::get('access_token');
        if (! $token) throw new RuntimeException('Meta access token is not configured (Settings).');
        return Http::withToken($token)->acceptJson()->timeout(30);
    }

    /** Throws MetaApiException (with Meta's error fields) for failed calls; each failure is stored in error_logs. */
    protected function check($res): array
    {
        if ($res->failed()) {
            $req = $res->transferStats?->getRequest();
            $endpoint = $req ? preg_replace('#^https?://[^/]+#', '', (string) $req->getUri()) : '?';
            $e = new MetaApiException($res->status(), $req?->getMethod() ?? '?', ErrorLogger::redactString($endpoint), (array) ($res->json('error') ?? []), $res->body());
            ErrorLogger::exception($e, 'error', ['source' => 'meta', 'status_code' => $res->status()]);
            throw $e;
        }
        return $res->json() ?? [];
    }

    public function sendText(string $phoneNumberId, string $to, string $text): array
    {
        return $this->send($phoneNumberId, $to, 'text', ['preview_url' => true, 'body' => $text]);
    }

    /** $type: image|document|audio|video. $media: ['id'=>..] or ['link'=>..], plus optional caption/filename. */
    public function sendMedia(string $phoneNumberId, string $to, string $type, array $media): array
    {
        return $this->send($phoneNumberId, $to, $type, $media);
    }

    /** Interactive reply buttons / list message. $interactive is Meta's "interactive" object. */
    public function sendInteractive(string $phoneNumberId, string $to, array $interactive): array
    {
        return $this->send($phoneNumberId, $to, 'interactive', $interactive);
    }

    /** Download an incoming media object: GET /{media-id} → short-lived URL, then fetch it with the token. */
    public function downloadMedia(string $mediaId): array
    {
        $info = $this->check($this->client()->get($this->baseUrl()."/{$mediaId}"));
        $res = $this->client()->withHeaders(['Accept' => '*/*'])->get($info['url'] ?? '');
        if (! $res->successful()) throw new RuntimeException('Media download failed: HTTP '.$res->status());
        return ['body' => $res->body(), 'mime_type' => $info['mime_type'] ?? $res->header('Content-Type'), 'size' => $info['file_size'] ?? strlen($res->body())];
    }

    protected function send(string $phoneNumberId, string $to, string $type, array $payload): array
    {
        return $this->check($this->client()->post($this->baseUrl()."/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D/', '', $to),
            'type' => $type,
            $type => $payload,
        ]));
    }

    public function markRead(string $phoneNumberId, string $wamid): array
    {
        return $this->check($this->client()->post($this->baseUrl()."/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $wamid,
        ]));
    }

    /** Upload media; returns Meta media id. */
    public function uploadMedia(string $phoneNumberId, UploadedFile|string $file, ?string $mime = null, ?string $filename = null): string
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $mime ??= $file instanceof UploadedFile ? $file->getMimeType() : (mime_content_type($path) ?: 'application/octet-stream');
        $filename ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);
        $res = $this->client()
            ->attach('file', file_get_contents($path), $filename, ['Content-Type' => $mime])
            ->post($this->baseUrl()."/{$phoneNumberId}/media", ['messaging_product' => 'whatsapp', 'type' => $mime]);
        $id = $this->check($res)['id'] ?? null;
        if (! $id) throw new RuntimeException('Meta media upload returned no id');
        return $id;
    }

    public function listPhoneNumbers(?string $wabaId = null): array
    {
        $wabaId ??= Setting::get('waba_id');
        if (! $wabaId) throw new RuntimeException('WABA ID is not configured (Settings).');
        return $this->check($this->client()->get($this->baseUrl()."/{$wabaId}/phone_numbers", [
            'fields' => self::PHONE_FIELDS,
        ]))['data'] ?? [];
    }

    /** GET /{waba-id}: health_status (can_send_message + per-entity errors), review and business verification status. */
    public function getWabaHealth(?string $wabaId = null): array
    {
        $wabaId ??= Setting::get('waba_id');
        if (! $wabaId) throw new RuntimeException('WABA ID is not configured (Settings).');
        return $this->check($this->client()->get($this->baseUrl()."/{$wabaId}", ['fields' => 'health_status,account_review_status,business_verification_status']));
    }

    public const PHONE_FIELDS = 'id,display_phone_number,verified_name,quality_rating,code_verification_status,status,name_status,platform_type,account_mode,messaging_limit_tier';

    /** GET /{phone-number-id}: registration/quality/name status of one number. */
    public function getPhoneNumber(string $phoneNumberId): array
    {
        return $this->check($this->client()->get($this->baseUrl()."/{$phoneNumberId}", ['fields' => self::PHONE_FIELDS]));
    }

    public function registerNumber(string $phoneNumberId, string $pin): array
    {
        return $this->check($this->client()->post($this->baseUrl()."/{$phoneNumberId}/register", [
            'messaging_product' => 'whatsapp', 'pin' => $pin,
        ]));
    }

    // ---- Message templates (WABA /message_templates) ----
    protected function waba(?string $wabaId): string
    {
        $wabaId ??= Setting::get('waba_id');
        if (! $wabaId) throw new RuntimeException('WABA ID is not configured (Settings).');
        return $wabaId;
    }

    public function listTemplates(?string $wabaId = null): array
    {
        $url = $this->baseUrl().'/'.$this->waba($wabaId).'/message_templates';
        $query = ['fields' => 'id,name,language,status,category,components,rejected_reason,quality_score,previous_category', 'limit' => 100];
        $all = [];
        for ($i = 0; $i < 50 && $url; $i++) { // follow paging.next
            $res = $this->check($this->client()->get($url, $query));
            array_push($all, ...($res['data'] ?? []));
            $url = $res['paging']['next'] ?? null; $query = [];
        }
        return $all;
    }

    /** Returns {id, status, category}. */
    public function createTemplate(array $payload, ?string $wabaId = null): array
    {
        return $this->check($this->client()->post($this->baseUrl().'/'.$this->waba($wabaId).'/message_templates', $payload));
    }

    /** Edit by template id: components and/or category. */
    public function editTemplate(string $templateId, array $payload): array
    {
        return $this->check($this->client()->post($this->baseUrl()."/{$templateId}", $payload));
    }

    /** Delete one language version (hsm_id) by name. */
    public function deleteTemplate(string $name, ?string $templateId = null, ?string $wabaId = null): array
    {
        $q = ['name' => $name] + ($templateId ? ['hsm_id' => $templateId] : []);
        return $this->check($this->client()->delete($this->baseUrl().'/'.$this->waba($wabaId).'/message_templates?'.http_build_query($q)));
    }

    public function sendTemplate(string $phoneNumberId, string $to, string $name, string $language, array $components = []): array
    {
        $tpl = ['name' => $name, 'language' => ['code' => $language]];
        if ($components) $tpl['components'] = $components;
        return $this->send($phoneNumberId, $to, 'template', $tpl);
    }

    public static function verifySignature(string $rawBody, ?string $header, ?string $appSecret): bool
    {
        if (! $appSecret || ! $header || ! str_starts_with($header, 'sha256=')) return false;
        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $appSecret), $header);
    }
}
