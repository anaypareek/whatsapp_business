<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-number switch: show the customer WhatsApp's "typing…" indicator while the AI generates a reply (default on). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_reply_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('ai_reply_settings', 'typing_indicator')) {
                $t->boolean('typing_indicator')->default(true)->after('human_active_quiet_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_reply_settings', function (Blueprint $t) {
            if (Schema::hasColumn('ai_reply_settings', 'typing_indicator')) $t->dropColumn('typing_indicator');
        });
    }
};
