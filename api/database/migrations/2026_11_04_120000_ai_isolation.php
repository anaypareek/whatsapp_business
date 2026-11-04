<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // order lookup must be opted in per number (the Orders API belongs to one business)
        Schema::table('ai_reply_settings', fn (Blueprint $t) => $t->boolean('orders_lookup')->default(false)->after('handoff_cooldown_hours'));
        Schema::table('ai_open_questions', fn (Blueprint $t) => $t->string('contact_name')->nullable()->after('contact_wa_id'));
    }

    public function down(): void
    {
        Schema::table('ai_reply_settings', fn (Blueprint $t) => $t->dropColumn('orders_lookup'));
        Schema::table('ai_open_questions', fn (Blueprint $t) => $t->dropColumn('contact_name'));
    }
};
