<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive only: three new tables (per-number greeting settings, disabled by default; contact groups + members) and one defaulted flag on messages. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('greeting_settings')) {
            Schema::create('greeting_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('phone_number_id')->unique()->constrained('phone_numbers')->cascadeOnDelete();
                $t->boolean('enabled')->default(false);
                $t->string('mode', 20)->default('every'); // every | new_only
                $t->text('greeting_text')->nullable();
                $t->string('image_url', 1024)->nullable();
                $t->unsignedInteger('cooldown_hours')->default(24);
                $t->boolean('hours_enabled')->default(false);
                $t->string('hours_start', 5)->nullable(); // HH:MM Asia/Kolkata
                $t->string('hours_end', 5)->nullable();
                $t->json('hours_days')->nullable(); // [1..7] ISO weekdays
                $t->text('off_hours_text')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('greeting_groups')) {
            Schema::create('greeting_groups', function (Blueprint $t) {
                $t->id();
                $t->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
                $t->string('name', 100);
                $t->text('greeting_text')->nullable();
                $t->text('off_hours_text')->nullable();
                $t->integer('priority')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('greeting_group_members')) {
            Schema::create('greeting_group_members', function (Blueprint $t) {
                $t->id();
                $t->foreignId('greeting_group_id')->constrained('greeting_groups')->cascadeOnDelete();
                $t->string('contact_wa_id', 32);
                $t->timestamps();
                $t->unique(['greeting_group_id', 'contact_wa_id']);
                $t->index('contact_wa_id');
            });
        }
        if (! Schema::hasColumn('messages', 'is_auto')) {
            Schema::table('messages', fn (Blueprint $t) => $t->boolean('is_auto')->default(false));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages', 'is_auto')) Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('is_auto'));
        Schema::dropIfExists('greeting_group_members');
        Schema::dropIfExists('greeting_groups');
        Schema::dropIfExists('greeting_settings');
    }
};
