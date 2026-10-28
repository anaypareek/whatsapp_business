<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('error-logs:prune {--days=30}', function () {
    $n = \App\Services\ErrorLogger::prune((int) $this->option('days'));
    $this->info("Deleted $n error log rows older than {$this->option('days')} days.");
})->purpose('Delete error_logs rows past the retention window');

\Illuminate\Support\Facades\Schedule::command('error-logs:prune')->daily();

// ---- Instagram (shared hosting: driven by the one cron `* * * * * php artisan schedule:run`; no queue worker) ----
Artisan::command('instagram:publish {--limit=10}', function () {
    $s = app(\App\Services\InstagramPublisher::class)->runDue((int) $this->option('limit'));
    if ($s['claimed']) $this->info(json_encode($s));
})->purpose('Publish due Instagram posts (create containers, poll status, media_publish) — non-blocking, resumes across runs');

Artisan::command('instagram:check-tokens', function () {
    $s = app(\App\Services\InstagramTokens::class)->checkAll();
    $this->info("Checked {$s['checked']} account(s): {$s['active']} active, {$s['needs_reconnect']} need reconnect.");
})->purpose('Validate Instagram tokens, renew long-lived user tokens close to expiry, flag accounts needing reconnect');

Artisan::command('instagram:cleanup {--days=7}', function () {
    $s = app(\App\Services\InstagramMediaService::class)->cleanup((int) $this->option('days'));
    $this->info("Removed {$s['files']} media file(s) already published or abandoned.");
})->purpose('Delete uploaded Instagram media that is no longer needed (published > N days ago, or never used)');

\Illuminate\Support\Facades\Schedule::command('instagram:publish')->everyMinute()->withoutOverlapping(10);
\Illuminate\Support\Facades\Schedule::command('instagram:check-tokens')->dailyAt('03:30')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('instagram:cleanup')->dailyAt('04:00')->withoutOverlapping();

Artisan::command('api-logs:prune {--days=30}', function () {
    $n = \App\Models\ApiRequestLog::where('created_at', '<', now()->subDays((int) $this->option('days')))->delete();
    $this->info("Pruned {$n} API request log rows.");
})->purpose('Delete API request logs older than N days');
\Illuminate\Support\Facades\Schedule::command('api-logs:prune')->daily();

Artisan::command('waba:health', function () {
    $h = \App\Services\WabaHealth::refresh();
    $this->info('can_send_message: '.($h['can_send_message'] ?? 'unknown').($h['fetch_error'] ? ' (fetch error: '.$h['fetch_error'].')' : ''));
})->purpose('Refresh the cached WABA health status from Meta');
\Illuminate\Support\Facades\Schedule::command('waba:health')->dailyAt('03:30');
