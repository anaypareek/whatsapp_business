<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AI token usage logs + per-client credit ledger. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            Schema::create('ai_usage_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $t->foreignId('phone_number_id')->nullable()->constrained('phone_numbers')->nullOnDelete();
                $t->string('feature', 32); // auto_reply | train_ai | other
                $t->string('model', 64);
                $t->unsignedInteger('prompt_tokens')->default(0);
                $t->unsignedInteger('completion_tokens')->default(0);
                $t->unsignedInteger('total_tokens')->default(0);
                $t->decimal('cost_usd', 12, 8)->default(0);
                $t->decimal('cost_inr', 12, 4)->default(0);
                $t->timestamp('created_at')->useCurrent();
                $t->index(['client_id', 'created_at']);
                $t->index(['phone_number_id', 'created_at']);
                $t->index(['feature', 'created_at']);
            });
        }
        if (! Schema::hasTable('ai_credit_ledger')) {
            Schema::create('ai_credit_ledger', function (Blueprint $t) {
                $t->id();
                $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $t->decimal('amount_inr', 12, 4); // positive top-up or negative adjustment
                $t->string('note', 500)->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['client_id', 'created_at']);
            });
        }
        if (! Schema::hasColumn('clients', 'ai_block_when_empty')) {
            Schema::table('clients', function (Blueprint $t) {
                $t->boolean('ai_block_when_empty')->default(true)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'ai_block_when_empty')) {
            Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('ai_block_when_empty'));
        }
        Schema::dropIfExists('ai_credit_ledger');
        Schema::dropIfExists('ai_usage_logs');
    }
};
