<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $t) {
            $t->id();
            $t->string('meta_id')->nullable()->unique();
            $t->string('waba_id')->nullable();
            $t->string('name');
            $t->string('language', 16);
            $t->string('category'); // MARKETING, UTILITY, AUTHENTICATION
            $t->string('status')->default('PENDING'); // APPROVED, PENDING, REJECTED, PAUSED, DISABLED, IN_APPEAL, ...
            $t->json('components');
            $t->string('rejected_reason')->nullable();
            $t->string('quality_score')->nullable(); // GREEN/YELLOW/RED/UNKNOWN
            $t->string('previous_category')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
            $t->unique(['name', 'language']);
        });
        Schema::create('template_edits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_template_id')->constrained()->cascadeOnDelete();
            $t->string('status_at_edit');
            $t->timestamps();
        });
        Schema::create('template_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $t->string('field');
            $t->string('event')->nullable();
            $t->string('reason')->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();
        });
        Schema::table('messages', function (Blueprint $t) {
            $t->string('template_name')->nullable()->after('body');
        });
    }
    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('template_name'));
        Schema::dropIfExists('template_events'); Schema::dropIfExists('template_edits'); Schema::dropIfExists('message_templates');
    }
};
