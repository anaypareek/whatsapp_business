<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Every API error response is JSON with at least {message, error_code, log_id}. */
class ConsistentErrors
{
    public const CODES = [400 => 'BAD_REQUEST', 401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT', 413 => 'PAYLOAD_TOO_LARGE', 419 => 'SESSION_EXPIRED', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED', 502 => 'UPSTREAM_ERROR', 503 => 'UNAVAILABLE'];

    public function handle(Request $request, Closure $next)
    {
        $res = $next($request);
        if ($res instanceof JsonResponse && $res->getStatusCode() >= 400) {
            $d = $res->getData(true);
            if (is_array($d) && ! array_is_list($d)) {
                $s = $res->getStatusCode();
                $d['message'] ??= self::CODES[$s] ?? 'Error';
                $d['error_code'] ??= self::CODES[$s] ?? ($s >= 500 ? 'SERVER_ERROR' : 'HTTP_'.$s);
                if (! array_key_exists('log_id', $d)) $d['log_id'] = null;
                $res->setData($d);
            }
        }
        return $res;
    }
}
