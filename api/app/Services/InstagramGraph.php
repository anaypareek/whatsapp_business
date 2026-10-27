<?php
namespace App\Services;

use App\Models\InstagramAccount;
use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Every outbound call of the Instagram module (Facebook Login OAuth + Instagram Graph content publishing). Faked in tests with Http::fake. */
class InstagramGraph
{
    public const SCOPES = ['instagram_basic', 'instagram_content_publish', 'pages_show_list', 'pages_read_engagement', 'business_management'];

    public function baseUrl(): string
    {
        return rtrim(config('services.instagram.graph_base'), '/').'/'.config('services.instagram.graph_version');
    }

    // ---- app credentials (Settings: Instagram App ID/secret, falling back to the WhatsApp Meta app's) ----
    public static function appId(): ?string { return Setting::get('ig_app_id') ?: Setting::get('app_id') ?: null; }
    public static function appSecret(): ?string { return Setting::get('ig_app_secret') ?: Setting::get('app_secret') ?: null; }
    public static function configId(): ?string { return Setting::get('ig_config_id') ?: null; }
    public static function redirectUri(): string
    {
        return Setting::get('ig_redirect_uri') ?: rtrim(config('app.url'), '/').'/api/instagram/callback';
    }
    public static function configured(): bool { return (bool) (self::appId() && self::appSecret()); }

    protected function http(?string $token = null): PendingRequest
    {
        $r = Http::acceptJson()->timeout(45);
        return $token ? $r->withToken($token) : $r;
    }

    /** Throws MetaApiException (Meta's error fields) for failed calls; each failure is stored in error_logs. */
    protected function check(Response $res): array
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

    // ---- OAuth (Facebook Login for Business) ----
    public function dialogUrl(string $state): string
    {
        if (! self::configured()) throw new RuntimeException('Instagram App ID / secret are not configured (Admin → Settings → Instagram).');
        $q = ['client_id' => self::appId(), 'redirect_uri' => self::redirectUri(), 'state' => $state, 'response_type' => 'code'];
        if ($cfg = self::configId()) { $q['config_id'] = $cfg; $q['override_default_response_type'] = 'true'; } // Facebook Login for Business login configuration
        else $q['scope'] = implode(',', self::SCOPES);
        return rtrim(config('services.instagram.dialog_base'), '/').'/'.config('services.instagram.graph_version').'/dialog/oauth?'.http_build_query($q);
    }

    /** code → short-lived user token (POST so the secret never appears in a URL). */
    public function exchangeCode(string $code): array
    {
        return $this->check($this->http()->asForm()->post($this->baseUrl().'/oauth/access_token', [
            'client_id' => self::appId(), 'client_secret' => self::appSecret(), 'redirect_uri' => self::redirectUri(), 'code' => $code]));
    }

    /** short-lived (or still-valid long-lived) user token → long-lived user token (≈60 days). */
    public function longLivedUserToken(string $token): array
    {
        return $this->check($this->http()->asForm()->post($this->baseUrl().'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => self::appId(), 'client_secret' => self::appSecret(), 'fb_exchange_token' => $token]));
    }

    /** Pages the user manages that have a linked instagram_business_account; follows paging up to 5 pages of results. */
    public function pagesWithInstagram(string $userToken): array
    {
        $url = $this->baseUrl().'/me/accounts';
        $params = ['fields' => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}', 'limit' => 50];
        $out = [];
        for ($i = 0; $i < 5 && $url; $i++) {
            $res = $this->check($this->http($userToken)->get($url, $params));
            foreach ($res['data'] ?? [] as $p) {
                $ig = $p['instagram_business_account'] ?? null;
                if (! $ig || empty($ig['id']) || empty($p['access_token'])) continue;
                $out[] = ['page_id' => (string) $p['id'], 'page_name' => $p['name'] ?? null, 'page_token' => $p['access_token'], 'ig_user_id' => (string) $ig['id'],
                    'username' => $ig['username'] ?? null, 'name' => $ig['name'] ?? null, 'profile_picture_url' => $ig['profile_picture_url'] ?? null];
            }
            $url = $res['paging']['next'] ?? null; $params = [];
        }
        return $out;
    }

    /** Token validity via /debug_token (needs the app token app_id|app_secret). */
    public function debugToken(string $inputToken): array
    {
        return $this->check($this->http()->get($this->baseUrl().'/debug_token', ['input_token' => $inputToken, 'access_token' => self::appId().'|'.self::appSecret()]))['data'] ?? [];
    }

    /** Cheap "is this account still reachable with this token" call that also refreshes username/picture. */
    public function profile(InstagramAccount $a): array
    {
        return $this->check($this->http($a->access_token)->get($this->baseUrl()."/{$a->ig_user_id}", ['fields' => 'id,username,name,profile_picture_url']));
    }

    // ---- content publishing ----
    /** POST /{ig-user-id}/media. $params: image_url | video_url, media_type, caption, is_carousel_item, children, share_to_feed. Returns the container id. */
    public function createContainer(InstagramAccount $a, array $params): string
    {
        foreach ($params as $k => $v) if (is_bool($v)) $params[$k] = $v ? 'true' : 'false';
        $res = $this->check($this->http($a->access_token)->asForm()->post($this->baseUrl()."/{$a->ig_user_id}/media", $params));
        if (empty($res['id'])) throw new RuntimeException('Instagram did not return a container id.');
        return (string) $res['id'];
    }

    /** GET /{container}?fields=status_code,status → status_code: IN_PROGRESS | FINISHED | ERROR | EXPIRED | PUBLISHED. */
    public function containerStatus(InstagramAccount $a, string $containerId): array
    {
        $r = $this->check($this->http($a->access_token)->get($this->baseUrl()."/{$containerId}", ['fields' => 'status_code,status']));
        return ['status_code' => strtoupper((string) ($r['status_code'] ?? 'IN_PROGRESS')), 'status' => $r['status'] ?? null];
    }

    /** POST /{ig-user-id}/media_publish → the published media id. */
    public function publish(InstagramAccount $a, string $containerId): string
    {
        $res = $this->check($this->http($a->access_token)->asForm()->post($this->baseUrl()."/{$a->ig_user_id}/media_publish", ['creation_id' => $containerId]));
        if (empty($res['id'])) throw new RuntimeException('Instagram did not return a media id.');
        return (string) $res['id'];
    }

    public function permalink(InstagramAccount $a, string $mediaId): ?string
    {
        return $this->check($this->http($a->access_token)->get($this->baseUrl()."/{$mediaId}", ['fields' => 'permalink']))['permalink'] ?? null;
    }

    /** GET /{ig-user-id}/content_publishing_limit → ['usage' => int, 'total' => int]. */
    public function publishingLimit(InstagramAccount $a): array
    {
        $r = $this->check($this->http($a->access_token)->get($this->baseUrl()."/{$a->ig_user_id}/content_publishing_limit", ['fields' => 'quota_usage,config']));
        $d = $r['data'][0] ?? [];
        return ['usage' => (int) ($d['quota_usage'] ?? 0), 'total' => (int) ($d['config']['quota_total'] ?? config('services.instagram.daily_limit'))];
    }
}
