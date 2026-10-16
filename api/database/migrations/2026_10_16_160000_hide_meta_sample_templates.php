<?php
use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Adds message_templates.is_sample and removes Meta's sample templates (hidden instead when messages reference them). Idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_templates', 'is_sample')) {
            Schema::table('message_templates', fn (Blueprint $t) => $t->boolean('is_sample')->default(false)->index()->after('language'));
        }
        MessageTemplate::purgeSamples();
    }

    public function down(): void
    {
        if (Schema::hasColumn('message_templates', 'is_sample')) Schema::table('message_templates', fn (Blueprint $t) => $t->dropColumn('is_sample'));
    }
};
