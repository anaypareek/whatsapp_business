<?php
namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * External API auth (server-to-server). Header only: X-API-Key or Authorization: Bearer.
 * Enforces: HTTPS (production), no key in query string, revoke, IP + Origin/Referer allowlists, per-key rate limit; logs every call.
 */
class ApiKeyAuth
{
    public const QUERY_KEYS = ['api_key', 'apikey', 'api-key', 'key', 'token', 'access_token', 'x-api-key'];

    public function handle(Request $request, Closure $next)
    {
        $t0 = microtime(true);
        $key = null;
        $fail = function (int $status, string $msg, string $code, array $headers = []) use ($request, &$key, $t0) {
            $this->log($request, $key, $status, $t0, $msg);
            return response()->json(['message' => $msg, 'error_code' => $code], $status, $headers);
        };

        if ($this->httpsRequired() && ! $request->secure() && strtolower((string) $request->header('X-Forwarded-Proto')) !== 'https') {
            return $fail(403, 'HTTPS is required for the API.', 'HTTPS_REQUIRED');
        }
        foreach (self::QUERY_KEYS as $q) {
            if ($request->query->has($q)) {
                return $fail(400, 'Do not put the API key in the URL. Send it in the X-API-Key header or as Authorization: Bearer <key>.', 'KEY_IN_QUERY');
            }
        }
        $plain = $request->bearerToken() ?? $request->header('X-API-Key');
        $key = $plain ? ApiKey::findByPlain($plain) : null;
        if (! $key) return $fail(401, 'Invalid or missing API key', 'UNAUTHENTICATED');
        if ($key->revoked_at) return $fail(401, 'This API key has been revoked.', 'KEY_REVOKED');
        if (! $key->ipAllowed($request->ip())) return $fail(403, 'Requests from this IP address are not allowed for this API key.', 'IP_NOT_ALLOWED');
        if (! $key->originAllowed($request->header('Origin'), $request->header('Referer'))) {
            return $fail(403, 'Origin/Referer domain is not allowed for this API key.', 'ORIGIN_NOT_ALLOWED');
        }
        $limit = max(1, (int) ($key->rate_limit_per_minute ?: config('services.api.rate_limit', 60)));
        $rk = 'api-key:'.$key->id;
        if (RateLimiter::tooManyAttempts($rk, $limit)) {
            $retry = RateLimiter::availableIn($rk);
            return $fail(429, "Rate limit exceeded ({$limit} requests/minute). Retry in {$retry}s.", 'RATE_LIMITED',
                ['Retry-After' => $retry, 'X-RateLimit-Limit' => $limit, 'X-RateLimit-Remaining' => 0]);
        }
        RateLimiter::hit($rk, 60);

        $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();
        $request->attributes->set('api_key', $key);
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->log($request, $key, 500, $t0, $e->getMessage());
            throw $e;
        }
        $err = null;
        if ($response->getStatusCode() >= 400 && method_exists($response, 'getData')) {
            $d = $response->getData(true);
            $err = is_array($d) ? ($d['error'] ?? $d['message'] ?? null) : null;
        }
        $this->log($request, $key, $response->getStatusCode(), $t0, $err);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($rk, $limit)));
        return $response;
    }

    private function httpsRequired(): bool
    {
        $v = config('services.api.require_https');
        return $v === null ? app()->environment('production') : (bool) $v;
    }

    private function log(Request $r, ?ApiKey $key, int $status, float $t0, ?string $error): void
    {
        try {
            $tpl = $r->input('template');
            if (is_array($tpl)) $tpl = $tpl['name'] ?? null;
            $to = $r->input('to');
            ApiRequestLog::create([
                'api_key_id' => $key?->id, 'client_id' => $key?->client_id, 'ip' => $r->ip(), 'method' => $r->method(),
                'endpoint' => substr('/'.ltrim($r->path(), '/'), 0, 191), 'status' => $status,
                'latency_ms' => (int) round((microtime(true) - $t0) * 1000),
                'template' => is_string($tpl) ? substr($tpl, 0, 191) : null,
                'to' => is_string($to) || is_numeric($to) ? substr(preg_replace('/[^0-9+]/', '', (string) $to), 0, 32) : null,
                'error' => $error ? substr(is_string($error) ? $error : json_encode($error), 0, 500) : null,
            ]);
        } catch (Throwable) { /* logging must never break the API */ }
    }
}
