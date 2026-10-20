<?php
namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Firebase Cloud Messaging HTTP v1 sender. Auth = OAuth2 access token minted from a service-account JSON
 * (RS256 JWT signed with openssl, exchanged at oauth2.googleapis.com), cached ~55 min.
 * Not configured (nothing uploaded in Admin → Settings → Push Notifications, no FCM_CREDENTIALS_PATH) => every call is a no-op with one warning.
 */
class FcmService
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const PACKAGE = 'com.fineoutput.wabusiness';

    /** Service-account JSON: FCM_CREDENTIALS_PATH file (optional override) or the one uploaded in Admin → Settings (encrypted in DB). */
    public function credentials(): ?array
    {
        $path = (string) config('services.fcm.credentials_path');
        if ($path !== '' && str_starts_with($path, '~/')) $path = rtrim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')), '/').substr($path, 1);
        $raw = $path !== '' && is_readable($path) ? (string) file_get_contents($path) : \App\Models\Setting::get('fcm_service_account');
        $j = $raw ? json_decode($raw, true) : null;
        return is_array($j) && ! empty($j['client_email']) && ! empty($j['private_key']) ? $j : null;
    }

    /** Public Firebase options for the Android app (parsed from the uploaded google-services.json). */
    public function clientOptions(): ?array
    {
        $j = json_decode((string) \App\Models\Setting::get('fcm_client_options'), true);
        return is_array($j) && ! empty($j['appId']) ? $j : null;
    }

    public function projectId(): ?string { return config('services.fcm.project_id') ?: ($this->credentials()['project_id'] ?? $this->clientOptions()['projectId'] ?? null); }

    /** Validate + extract FirebaseOptions from google-services.json for our package. Throws InvalidArgumentException. */
    public static function parseGoogleServices(string $raw): array
    {
        $j = json_decode($raw, true);
        if (! is_array($j) || empty($j['project_info']) || empty($j['client'])) throw new \InvalidArgumentException('This is not a google-services.json file (missing project_info/client).');
        $client = collect($j['client'])->first(fn ($c) => ($c['client_info']['android_client_info']['package_name'] ?? null) === self::PACKAGE);
        if (! $client) {
            $pk = collect($j['client'])->pluck('client_info.android_client_info.package_name')->filter()->implode(', ');
            throw new \InvalidArgumentException('google-services.json has no Android app with package name '.self::PACKAGE.($pk ? " (found: $pk)" : '').'. Add an Android app with exactly that package name in Firebase and download the file again.');
        }
        $key = collect($client['api_key'] ?? [])->pluck('current_key')->filter()->first();
        $opt = ['apiKey' => $key, 'appId' => $client['client_info']['mobilesdk_app_id'] ?? null, 'messagingSenderId' => (string) ($j['project_info']['project_number'] ?? ''),
            'projectId' => $j['project_info']['project_id'] ?? null, 'storageBucket' => $j['project_info']['storage_bucket'] ?? null];
        foreach (['apiKey', 'appId', 'messagingSenderId', 'projectId'] as $k) if (empty($opt[$k])) throw new \InvalidArgumentException("google-services.json is missing $k.");
        return $opt;
    }

    /** Validate a service-account key file. Throws InvalidArgumentException. */
    public static function parseServiceAccount(string $raw): array
    {
        $j = json_decode($raw, true);
        if (! is_array($j) || ($j['type'] ?? null) !== 'service_account' || empty($j['client_email']) || empty($j['private_key']) || empty($j['project_id'])) {
            throw new \InvalidArgumentException('This is not a Firebase service-account key (expected "type": "service_account" with client_email, private_key, project_id).');
        }
        if (! openssl_pkey_get_private($j['private_key'])) throw new \InvalidArgumentException('The private_key in this file could not be read.');
        return $j;
    }

    public function enabled(): bool { return $this->credentials() !== null && $this->projectId(); }

    public function accessToken(): ?string
    {
        $c = $this->credentials();
        if (! $c) return null;
        return Cache::remember('fcm:access_token:'.md5($c['client_email']), 3300, function () use ($c) {
            $now = time();
            $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
            $jwt = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
                'iss' => $c['client_email'], 'scope' => self::SCOPE, 'aud' => $c['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            if (! openssl_sign($jwt, $sig, $c['private_key'], OPENSSL_ALGO_SHA256)) throw new \RuntimeException('FCM: could not sign JWT with the service-account key');
            $res = Http::asForm()->timeout(10)->post($c['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt.'.'.$b64($sig),
            ]);
            if (! $res->ok() || ! $res->json('access_token')) throw new \RuntimeException('FCM OAuth failed: HTTP '.$res->status().' '.mb_substr($res->body(), 0, 300));
            return $res->json('access_token');
        });
    }

    /**
     * Send one notification to every device of a client. Returns ['sent' => n, 'removed' => n, 'failed' => n].
     * $data values must be strings (FCM requirement).
     */
    public function sendToClient(int $clientId, string $title, string $body, array $data = [], ?string $collapseKey = null): array
    {
        $out = ['sent' => 0, 'removed' => 0, 'failed' => 0];
        if (! $this->enabled()) {
            if (! Cache::has('fcm:warned')) { Log::warning('FCM push skipped: no Firebase service account uploaded (Admin → Settings → Push Notifications, see docs/FIREBASE-SETUP.md).'); Cache::put('fcm:warned', 1, 3600); }
            return $out + ['skipped' => true];
        }
        $tokens = DeviceToken::where('client_id', $clientId)->get();
        if ($tokens->isEmpty()) return $out;
        try { $access = $this->accessToken(); } catch (Throwable $e) { ErrorLogger::exception($e, 'warning'); return $out + ['failed' => $tokens->count()]; }
        $url = 'https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send';
        $data = array_map('strval', $data);
        foreach ($tokens as $t) {
            $msg = ['message' => [
                'token' => $t->token,
                'notification' => ['title' => mb_substr($title, 0, 100), 'body' => mb_substr($body, 0, 240)],
                'data' => $data,
                'android' => array_filter([
                    'priority' => 'HIGH', 'collapse_key' => $collapseKey, 'ttl' => '86400s',
                    'notification' => array_filter(['channel_id' => 'chat_messages', 'tag' => $collapseKey, 'click_action' => 'FLUTTER_NOTIFICATION_CLICK', 'notification_priority' => 'PRIORITY_HIGH']),
                ]),
            ]];
            $res = Http::withToken($access)->timeout(10)->post($url, $msg);
            if ($res->successful()) { $out['sent']++; continue; }
            $codes = collect($res->json('error.details') ?? [])->pluck('errorCode')->filter()->all();
            $status = $res->json('error.status');
            if (in_array('UNREGISTERED', $codes, true) || $status === 'NOT_FOUND' || ($status === 'INVALID_ARGUMENT' && str_contains((string) $res->json('error.message'), 'registration token'))) {
                $t->delete(); $out['removed']++;
            } else {
                $out['failed']++;
                ErrorLogger::record(['level' => 'warning', 'source' => 'app', 'message' => 'FCM send failed: HTTP '.$res->status().' '.($res->json('error.message') ?? ''),
                    'error_code' => 'FCM_'.($status ?? $res->status()), 'status_code' => $res->status(), 'context' => ['client_id' => $clientId, 'device_token_id' => $t->id, 'codes' => $codes]]);
            }
        }
        return $out;
    }
}
