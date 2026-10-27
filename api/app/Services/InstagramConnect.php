<?php
namespace App\Services;

use App\Models\Client;
use App\Models\InstagramAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connect-accounts flow: state → Meta dialog → callback (code → long-lived user token → pages with an IG account)
 * → the user ticks the accounts to keep → rows saved. Pending state/results live in the cache (database store; 15–30 min TTL),
 * and the candidate list (which carries page tokens) is encrypted.
 */
class InstagramConnect
{
    public function __construct(protected InstagramGraph $graph) {}

    public static function actorKey($user): array { return [$user instanceof Client ? 'client' : 'user', $user->id]; }

    /** @return string the Facebook Login dialog URL */
    public function start($user, ?string $returnTo): string
    {
        [$type, $id] = self::actorKey($user);
        $state = Str::random(40);
        Cache::put("ig:state:$state", ['type' => $type, 'id' => $id, 'return_to' => self::safePath($returnTo, $type)], 900);
        return $this->graph->dialogUrl($state);
    }

    public static function safePath(?string $p, string $type): string
    {
        $p = (string) $p;
        if ($p === '' || $p[0] !== '/' || str_starts_with($p, '//') || str_contains($p, '\\') || preg_match('/[\r\n]/', $p)) return $type === 'client' ? '/instagram' : '/wab-control-7x/instagram';
        return strtok($p, '?#');
    }

    /** @return array{0:string,1:?string} [redirect path, session id|null]; on failure the path carries ?connect_error= */
    public function callback(?string $code, ?string $state, ?string $error = null, ?string $errorDescription = null): array
    {
        $st = $state ? Cache::pull("ig:state:$state") : null;
        if (! $st) return ['/', null]; // unknown/expired state: nothing to tell the user beyond "start again"
        $back = fn (string $msg) => [$st['return_to'].'?'.http_build_query(['connect_error' => mb_substr($msg, 0, 300)]), null];
        if ($error || ! $code) return $back($errorDescription ?: ($error === 'access_denied' ? 'Authorization was cancelled on Facebook.' : 'Facebook did not return an authorization code.'));
        try {
            $short = $this->graph->exchangeCode($code);
            $long = $this->graph->longLivedUserToken($short['access_token']);
            $token = $long['access_token'] ?? $short['access_token'];
            $expiresIn = $long['expires_in'] ?? $short['expires_in'] ?? null;
            $pages = $this->graph->pagesWithInstagram($token);
        } catch (\Throwable $e) {
            return $back($e instanceof MetaApiException ? $e->getMessage() : 'Could not complete the connection: '.$e->getMessage());
        }
        if (! $pages) return $back('No Facebook Page with a linked Instagram professional account was found for this login. Link your Instagram Business/Creator account to a Page, and tick that Page when Facebook asks.');
        $sid = Str::random(32);
        Cache::put("ig:sess:$sid", Crypt::encryptString(json_encode(['type' => $st['type'], 'id' => $st['id'], 'user_token' => $token,
            'expires_at' => $expiresIn ? now()->addSeconds((int) $expiresIn)->utc()->toIso8601String() : null, 'pages' => $pages])), 1800);
        return [$st['return_to'].'?'.http_build_query(['connect' => $sid]), $sid];
    }

    /** The pending connection for this actor, or null (unknown, expired, or someone else's). */
    public function session(string $sid, $user): ?array
    {
        $raw = Cache::get("ig:sess:$sid");
        if (! $raw) return null;
        $d = json_decode(Crypt::decryptString($raw), true);
        [$type, $id] = self::actorKey($user);
        return $d && $d['type'] === $type && (int) $d['id'] === (int) $id ? $d : null;
    }

    /** Candidate list for the picker (no tokens). */
    public function candidates(array $session): array
    {
        $have = InstagramAccount::whereIn('ig_user_id', array_column($session['pages'], 'ig_user_id'))->get()->keyBy('ig_user_id');
        return array_map(fn ($p) => ['ig_user_id' => $p['ig_user_id'], 'page_id' => $p['page_id'], 'page_name' => $p['page_name'], 'username' => $p['username'],
            'name' => $p['name'], 'profile_picture_url' => $p['profile_picture_url'],
            'already_connected' => isset($have[$p['ig_user_id']]),
            'connected_to_other_client' => $session['type'] === 'client' && ($o = $have[$p['ig_user_id']] ?? null) && $o->client_id && $o->client_id !== (int) $session['id']],
            $session['pages']);
    }

    /** Save the ticked accounts. $clientId only applies to admins (null = leave unassigned / keep current). @return array{saved: InstagramAccount[], skipped: array} */
    public function save(string $sid, $user, array $igIds, ?int $clientId = null): array
    {
        $s = $this->session($sid, $user) ?? throw ValidationException::withMessages(['connect' => 'This connection has expired. Click "Connect Instagram" again.']);
        $isClient = $user instanceof Client;
        [$type, $id] = self::actorKey($user);
        $saved = []; $skipped = [];
        foreach ($s['pages'] as $p) {
            if (! in_array($p['ig_user_id'], $igIds, true)) continue;
            $existing = InstagramAccount::where('ig_user_id', $p['ig_user_id'])->first();
            if ($isClient && $existing && $existing->client_id && $existing->client_id !== $user->id) {
                $skipped[] = ['ig_user_id' => $p['ig_user_id'], 'username' => $p['username'], 'reason' => 'Already connected to another client. Ask the administrator to reassign it.'];
                continue;
            }
            $attrs = ['page_id' => $p['page_id'], 'page_name' => $p['page_name'], 'username' => $p['username'], 'name' => $p['name'], 'profile_picture_url' => $p['profile_picture_url'],
                'access_token' => $p['page_token'], 'user_access_token' => $s['user_token'], 'token_expires_at' => $s['expires_at'], 'status' => InstagramAccount::ACTIVE, 'status_reason' => null,
                'connected_by_type' => $type, 'connected_by_id' => $id];
            if ($isClient) $attrs['client_id'] = $user->id;
            elseif ($clientId) $attrs['client_id'] = $clientId;
            $acct = $existing ? tap($existing)->update($attrs) : InstagramAccount::create($attrs + ['ig_user_id' => $p['ig_user_id']]);
            app(InstagramTokens::class)->check($acct->fresh(), refresh: false);
            $saved[] = $acct->fresh();
        }
        if (! $saved && ! $skipped) throw ValidationException::withMessages(['ig_user_ids' => 'Choose at least one account.']);
        if ($saved) Cache::forget("ig:sess:$sid");
        return compact('saved', 'skipped');
    }
}
