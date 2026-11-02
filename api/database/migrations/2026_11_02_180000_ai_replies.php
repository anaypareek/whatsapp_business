<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_reply_settings')) {
            Schema::create('ai_reply_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('phone_number_id')->unique()->constrained('phone_numbers')->cascadeOnDelete();
                $t->boolean('enabled')->default(false);
                $t->boolean('test_mode')->default(true);
                $t->json('allowlist')->nullable();
                $t->text('knowledge_base')->nullable();
                $t->unsignedInteger('max_per_hour')->default(5);
                $t->unsignedInteger('handoff_cooldown_hours')->default(24);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_contact_states')) {
            Schema::create('ai_contact_states', function (Blueprint $t) {
                $t->id();
                $t->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
                $t->string('contact_wa_id', 32);
                $t->boolean('needs_human')->default(false);
                $t->timestamp('handoff_at')->nullable();
                $t->string('reason', 255)->nullable();
                $t->timestamps();
                $t->unique(['phone_number_id', 'contact_wa_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_contact_states');
        Schema::dropIfExists('ai_reply_settings');
    }
};
