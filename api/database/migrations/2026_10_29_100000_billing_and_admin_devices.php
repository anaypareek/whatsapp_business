<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive only: new tables + nullable columns. No existing rows are modified. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_rates')) {
            Schema::create('client_rates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete(); // null = default rate card
                $t->string('category', 20);  // marketing | utility | authentication | service
                $t->decimal('rate', 10, 4)->default(0); // INR per message
                $t->timestamps();
                $t->unique(['client_id', 'category']);
            });
        }
        if (! Schema::hasTable('client_payments')) {
            Schema::create('client_payments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $t->decimal('amount', 12, 2);
                $t->date('paid_at');
                $t->string('method', 40)->nullable();
                $t->string('reference', 120)->nullable();
                $t->string('note', 500)->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['client_id', 'paid_at']);
            });
        }
        if (! Schema::hasColumn('messages', 'pricing_category')) {
            Schema::table('messages', function (Blueprint $t) {
                $t->string('pricing_category', 32)->nullable();
                $t->boolean('billable')->nullable();
                $t->string('conversation_id', 64)->nullable();
            });
        }
        if (! Schema::hasColumn('message_statuses', 'pricing_category')) {
            Schema::table('message_statuses', fn (Blueprint $t) => $t->string('pricing_category', 32)->nullable());
        }
        if (! Schema::hasTable('user_device_tokens')) {
            Schema::create('user_device_tokens', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->string('token', 512)->unique();
                $t->string('platform', 16)->default('android');
                $t->string('app_version', 32)->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
                $t->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');
        if (Schema::hasColumn('message_statuses', 'pricing_category')) Schema::table('message_statuses', fn (Blueprint $t) => $t->dropColumn('pricing_category'));
        if (Schema::hasColumn('messages', 'pricing_category')) Schema::table('messages', fn (Blueprint $t) => $t->dropColumn(['pricing_category', 'billable', 'conversation_id']));
        Schema::dropIfExists('client_payments');
        Schema::dropIfExists('client_rates');
    }
};
