<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: application error log (errors/warnings, failed Meta calls, frontend JS errors). Kept 30 days. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('error_logs')) return;
        Schema::create('error_logs', function (Blueprint $t) {
            $t->id();
            $t->string('level', 16)->index();          // error, warning, critical…
            $t->string('source', 16)->index();         // app, exception, meta, frontend
            $t->text('message');
            $t->string('error_code', 64)->nullable();   // e.g. META_100, SERVER_ERROR, JS_ERROR
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->json('context')->nullable();            // redacted
            $t->longText('trace')->nullable();
            $t->string('file', 512)->nullable();
            $t->unsignedInteger('line')->nullable();
            $t->string('url', 2048)->nullable();
            $t->string('method', 10)->nullable();
            $t->string('user_type', 16)->nullable();    // user, client
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_label')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('resolved_at')->nullable()->index();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent()->index();
        });
    }
    public function down(): void { Schema::dropIfExists('error_logs'); }
};
