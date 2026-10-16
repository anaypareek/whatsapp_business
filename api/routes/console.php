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
