<?php
namespace App\Services;

use App\Models\Client;
use App\Models\ErrorLog;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Writes rows to error_logs. Never throws (logging must not break the request), redacts secrets,
 * and remembers which exceptions it already stored so the log channel doesn't store them twice.
 */
class ErrorLogger
{
    protected static array $logged = [];   // spl_object_id(exception) => log id
    protected static bool $writing = false;
    public static ?int $lastId = null;

    public const SECRET_KEYS = '/(pass(word)?|secret|token|authori[sz]ation|api[_-]?key|access[_-]?key|pin|signature|cookie|session|credential|private)/i';

    public static function redact(mixed $v, int $depth = 0): mixed
    {
        if ($depth > 6) return '[…]';
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) $out[$k] = is_string($k) && preg_match(self::SECRET_KEYS, $k) ? '[REDACTED]' : self::redact($x, $depth + 1);
            return $out;
        }
        if (is_object($v)) return $v instanceof Throwable ? get_class($v).': '.self::redactString($v->getMessage()) : (method_exists($v, 'toArray') ? self::redact($v->toArray(), $depth + 1) : get_class($v));
        if (is_string($v)) return self::redactString(mb_substr($v, 0, 5000));
        return $v;
    }

    public static function redactString(string $s): string
    {
        return preg_replace([
            '/\bBearer\s+[A-Za-z0-9._|~+\/=-]{8,}/i',
            '/\bEAA[A-Za-z0-9]{20,}/',                     // Meta access tokens
            '/\b(ghp|gho|github_pat)_[A-Za-z0-9_]{20,}/',
            '/\b\d+\|[A-Za-z0-9]{30,}/',                   // Sanctum plain-text tokens
            '/([?&](access_token|token|key|password|secret)=)[^&\s]+/i',
            '/sha256=[a-f0-9]{64}/i',
        ], ['Bearer [REDACTED]', '[REDACTED_TOKEN]', '[REDACTED_TOKEN]', '[REDACTED_TOKEN]', '$1[REDACTED]', 'sha256=[REDACTED]'], $s);
    }

    public static function alreadyLogged(Throwable $e): ?int { return self::$logged[spl_object_id($e)] ?? null; }

    public static function exception(Throwable $e, string $level = 'error', array $extra = []): ?int
    {
        if ($id = self::alreadyLogged($e)) return $id;
        $id = self::record([
            'level' => $level, 'source' => $extra['source'] ?? ($e instanceof MetaApiException ? 'meta' : 'exception'),
            'message' => get_class($e).': '.$e->getMessage(), 'error_code' => $extra['error_code'] ?? self::codeFor($e),
            'status_code' => $extra['status_code'] ?? null, 'file' => self::relPath($e->getFile()), 'line' => $e->getLine(),
            'trace' => self::redactString(mb_substr($e->getTraceAsString(), 0, 60000)),
            'context' => $extra['context'] ?? ($e instanceof MetaApiException ? $e->details() : null),
        ]);
        if ($id) self::$logged[spl_object_id($e)] = $id;
        return $id;
    }

    public static function codeFor(Throwable $e): string
    {
        if ($e instanceof MetaApiException) return $e->errorCode();
        return 'SERVER_ERROR';
    }

    public static function relPath(?string $f): ?string { return $f ? str_replace(base_path().'/', '', $f) : null; }

    /** @param array $a level, source, message, error_code, status_code, context, trace, file, line */
    public static function record(array $a): ?int
    {
        if (self::$writing) return null; // a failure while logging must not recurse
        self::$writing = true;
        try {
            if (! Schema::hasTable('error_logs')) return null;
            $req = app()->bound('request') && ! app()->runningInConsole() || app()->runningUnitTests() ? request() : null;
            $user = null;
            try { $user = $req?->user(); } catch (Throwable) {}
            $row = ErrorLog::create([
                'level' => strtolower($a['level'] ?? 'error'), 'source' => $a['source'] ?? 'app',
                'message' => self::redactString(mb_substr((string) ($a['message'] ?? ''), 0, 60000)),
                'error_code' => isset($a['error_code']) ? mb_substr((string) $a['error_code'], 0, 64) : null,
                'status_code' => $a['status_code'] ?? null,
                'context' => isset($a['context']) ? self::redact($a['context']) : null,
                'trace' => $a['trace'] ?? null, 'file' => isset($a['file']) ? mb_substr($a['file'], 0, 512) : null, 'line' => $a['line'] ?? null,
                'url' => mb_substr(self::redactString((string) ($a['url'] ?? $req?->fullUrl() ?? '')), 0, 2048) ?: null,
                'method' => $req?->method(), 'ip' => $req?->ip(),
                'user_type' => $user ? ($user instanceof Client ? 'client' : 'user') : null, 'user_id' => $user?->id,
                'user_label' => $user ? mb_substr(trim(($user->name ?? '').' <'.($user->email ?? '').'>'), 0, 255) : null,
            ]);
            self::$lastId = $row->id;
            if (random_int(1, 100) === 1) self::prune(); // also pruned daily by the scheduler
            return $row->id;
        } catch (Throwable) {
            return null;
        } finally {
            self::$writing = false;
        }
    }

    public static function prune(int $days = ErrorLog::RETENTION_DAYS): int
    {
        return ErrorLog::where('created_at', '<', now()->subDays($days))->delete();
    }
}
