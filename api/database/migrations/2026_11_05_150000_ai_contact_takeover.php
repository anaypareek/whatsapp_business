<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual per-chat takeover: while taken_over is true, ALL automatic replies
 * (AI incl. trained answers, keyword rules, greetings, flows, button replies)
 * stay silent for that (phone_number_id, contact_wa_id). Manual outbound does
 * not clear taken_over (only needs_human). Release ("Give to AI") clears
 * taken_over + needs_human/handoff cooldown so AI resumes on the next inbound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_contact_states', function (Blueprint $t) {
            if (! Schema::hasColumn('ai_contact_states', 'taken_over')) {
                $t->boolean('taken_over')->default(false)->after('reason');
            }
            if (! Schema::hasColumn('ai_contact_states', 'taken_over_at')) {
                $t->timestamp('taken_over_at')->nullable()->after('taken_over');
            }
            if (! Schema::hasColumn('ai_contact_states', 'taken_over_by_id')) {
                $t->unsignedBigInteger('taken_over_by_id')->nullable()->after('taken_over_at');
            }
            if (! Schema::hasColumn('ai_contact_states', 'taken_over_by_type')) {
                $t->string('taken_over_by_type', 64)->nullable()->after('taken_over_by_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_contact_states', function (Blueprint $t) {
            foreach (['taken_over_by_type', 'taken_over_by_id', 'taken_over_at', 'taken_over'] as $c) {
                if (Schema::hasColumn('ai_contact_states', $c)) $t->dropColumn($c);
            }
        });
    }
};
