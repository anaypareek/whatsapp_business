<?php
namespace App\Services;

use App\Models\InstagramAccount;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Token validity + renewal.
 *  - The Page token we publish with is derived from a long-lived user token and does not expire, but it dies when the user
 *    changes their password, removes the app, or loses access to the Page → we validate it (/debug_token, falling back to a profile call).
 *  - The long-lived user token lasts ≈60 days; we keep it (encrypted) and re-exchange it when it has < RENEW_DAYS left, then re-read the Page token.
 */
class InstagramTokens
{
    public const RENEW_DAYS = 14;
    public const REQUIRED_SCOPES = ['instagram_basic', 'instagram_content_publish'];

    public function __construct(protected InstagramGraph $graph) {}

    /** Validate (and when due, renew) one account. Never throws; the outcome is on the account (status/status_reason/last_checked_at). */
    public function check(InstagramAccount $a, bool $refresh = true): InstagramAccount
    {
        try {
            $info = null;
            if (InstagramGraph::configured()) {
                $info = $this->graph->debugToken($a->access_token);
                if (empty($info['is_valid'])) { $a->markReconnect($this->invalidReason($info)); return $a; }
                $missing = $info['scopes'] ?? null ? array_values(array_diff(self::REQUIRED_SCOPES, $info['scopes'])) : [];
                if ($missing) { $a->markReconnect('Missing permission: '.implode(', ', $missing).'. Reconnect and accept all permissions.'); return $a; }
            }
            $profile = $this->graph->profile($a); // also proves the Page ↔ IG link and the Page role are intact
            $exp = fn ($ts) => $ts ? now()->setTimestamp((int) $ts) : null;
            $a->forceFill(array_filter([
                'username' => $profile['username'] ?? null, 'name' => $profile['name'] ?? null, 'profile_picture_url' => $profile['profile_picture_url'] ?? null,
                'scopes' => $info['scopes'] ?? null,
                'data_access_expires_at' => $info ? $exp($info['data_access_expires_at'] ?? null) : null,
            ], fn ($v) => $v !== null));
            if ($info && ! empty($info['expires_at'])) $a->token_expires_at = $exp($info['expires_at']); // a page token that does expire
            $a->forceFill(['status' => InstagramAccount::ACTIVE, 'status_reason' => null, 'last_checked_at' => now()])->save();
            if ($refresh) $this->renewIfDue($a);
        } catch (MetaApiException $e) {
            $oauth = in_array($e->metaCode(), [190, 102, 463, 467, 10, 200], true) || $e->httpStatus === 401;
            if ($oauth) $a->markReconnect($e->getMessage());
            else $a->forceFill(['last_checked_at' => now()])->save(); // transient (rate limit, 5xx): keep the status, try again at the next check
        } catch (ConnectionException) {
            $a->forceFill(['last_checked_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
        }
        return $a;
    }

    protected function invalidReason(array $info): string
    {
        $m = $info['error']['message'] ?? null;
        return 'Instagram access was revoked or expired'.($m ? ': '.$m : '.').' Reconnect this account.';
    }

    /** Re-exchange the user token when close to expiry, then fetch the matching (non-expiring) Page token again. */
    public function renewIfDue(InstagramAccount $a): void
    {
        if (! $a->user_access_token || ! InstagramGraph::configured()) return;
        if ($a->token_expires_at && $a->token_expires_at->gt(now()->addDays(self::RENEW_DAYS))) return;
        if (! $a->token_expires_at) return; // unknown expiry: nothing to base a decision on
        try {
            $long = $this->graph->longLivedUserToken($a->user_access_token);
            $token = $long['access_token'] ?? null;
            if (! $token) return;
            $upd = ['user_access_token' => $token, 'token_expires_at' => isset($long['expires_in']) ? now()->addSeconds((int) $long['expires_in']) : $a->token_expires_at];
            foreach ($this->graph->pagesWithInstagram($token) as $p) {
                if ($p['ig_user_id'] === $a->ig_user_id) { $upd['access_token'] = $p['page_token']; break; }
            }
            $a->forceFill($upd)->save();
        } catch (MetaApiException $e) {
            if ($a->token_expires_at->isPast()) $a->markReconnect('The Instagram login expired and could not be renewed. Reconnect this account.');
        }
    }

    /** Scheduler entry: check every account that was ever connected (needs_reconnect ones too, in case the user fixed it on Facebook). */
    public function checkAll(): array
    {
        $stats = ['checked' => 0, 'active' => 0, 'needs_reconnect' => 0];
        InstagramAccount::orderBy('id')->each(function (InstagramAccount $a) use (&$stats) {
            $a = $this->check($a);
            $stats['checked']++; $stats[$a->status === 'active' ? 'active' : 'needs_reconnect']++;
        });
        return $stats;
    }
}
