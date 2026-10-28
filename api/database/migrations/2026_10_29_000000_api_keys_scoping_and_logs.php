<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-client API keys (allowlists, rate limit, soft revoke) + API request logs. Existing keys keep working (client_id NULL = admin/global). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $t) {
            $t->foreignId('client_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $t->json('allowed_ips')->nullable()->after('key_hash');
            $t->json('allowed_origins')->nullable()->after('allowed_ips');
            $t->unsignedInteger('rate_limit_per_minute')->default(60)->after('allowed_origins');
            $t->timestamp('revoked_at')->nullable()->after('last_used_at');
            $t->string('last_used_ip', 45)->nullable()->after('last_used_at');
            $t->index('prefix');
        });
        Schema::create('api_request_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('ip', 45)->nullable();
            $t->string('method', 8);
            $t->string('endpoint', 191);
            $t->unsignedSmallInteger('status');
            $t->unsignedInteger('latency_ms')->default(0);
            $t->string('template', 191)->nullable();
            $t->string('to', 32)->nullable();
            $t->string('error', 500)->nullable();
            $t->timestamp('created_at')->nullable()->index();
            $t->index(['api_key_id', 'created_at']);
            $t->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::table('api_keys', function (Blueprint $t) {
            $t->dropConstrainedForeignId('client_id');
            $t->dropIndex(['prefix']);
            $t->dropColumn(['allowed_ips', 'allowed_origins', 'rate_limit_per_minute', 'revoked_at', 'last_used_ip']);
        });
    }
};
