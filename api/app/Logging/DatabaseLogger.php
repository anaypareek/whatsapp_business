<?php
namespace App\Logging;

use App\Services\ErrorLogger;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Throwable;

/** Custom "database" log channel: stores warning+ records in error_logs (see config/logging.php). */
class DatabaseLogger
{
    public function __invoke(array $config): Logger
    {
        return new Logger('database', [new class(Level::fromName($config['level'] ?? 'warning')) extends AbstractProcessingHandler {
            protected function write(LogRecord $r): void
            {
                $ctx = $r->context;
                $e = $ctx['exception'] ?? null;
                if ($e instanceof Throwable) {
                    if (ErrorLogger::alreadyLogged($e)) return;
                    unset($ctx['exception']);
                    ErrorLogger::exception($e, strtolower($r->level->getName()), $ctx ? ['context' => $ctx] : []);
                    return;
                }
                $caller = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25))
                    ->first(fn ($f) => isset($f['file']) && str_contains($f['file'], '/app/') && ! str_contains($f['file'], '/app/Logging/'));
                ErrorLogger::record(['level' => strtolower($r->level->getName()), 'source' => $ctx['source'] ?? 'app', 'message' => $r->message,
                    'error_code' => $ctx['error_code'] ?? null, 'context' => $ctx ?: null,
                    'file' => ErrorLogger::relPath($caller['file'] ?? null), 'line' => $caller['line'] ?? null]);
            }
        }]);
    }
}
