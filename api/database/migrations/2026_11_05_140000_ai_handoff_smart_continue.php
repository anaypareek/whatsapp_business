<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Editable handoff texts + human-active quiet window for smart-continue during needs_human. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_reply_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('ai_reply_settings', 'handoff_text')) {
                $t->text('handoff_text')->nullable()->after('handoff_cooldown_hours');
            }
            if (! Schema::hasColumn('ai_reply_settings', 'handoff_text_hi')) {
                $t->text('handoff_text_hi')->nullable()->after('handoff_text');
            }
            if (! Schema::hasColumn('ai_reply_settings', 'human_active_quiet_minutes')) {
                $t->unsignedInteger('human_active_quiet_minutes')->default(30)->after('handoff_text_hi');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_reply_settings', function (Blueprint $t) {
            foreach (['handoff_text', 'handoff_text_hi', 'human_active_quiet_minutes'] as $c) {
                if (Schema::hasColumn('ai_reply_settings', $c)) $t->dropColumn($c);
            }
        });
    }
};
