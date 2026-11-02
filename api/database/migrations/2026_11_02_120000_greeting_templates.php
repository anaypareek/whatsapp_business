<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive only: optional APPROVED-template slots (+ variable mapping) for greeting and off-hours, on settings and groups. All nullable. */
return new class extends Migration
{
    protected array $tables = ['greeting_settings', 'greeting_groups'];

    public function up(): void
    {
        foreach ($this->tables as $tbl) {
            foreach (['greeting', 'off_hours'] as $slot) {
                if (! Schema::hasColumn($tbl, "{$slot}_template_id")) {
                    Schema::table($tbl, function (Blueprint $t) use ($slot) {
                        $t->foreignId("{$slot}_template_id")->nullable()->constrained('message_templates')->nullOnDelete();
                        $t->json("{$slot}_template_params")->nullable();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tbl) {
            foreach (['greeting', 'off_hours'] as $slot) {
                if (Schema::hasColumn($tbl, "{$slot}_template_id")) {
                    Schema::table($tbl, function (Blueprint $t) use ($slot) {
                        $t->dropConstrainedForeignId("{$slot}_template_id");
                        $t->dropColumn("{$slot}_template_params");
                    });
                }
            }
        }
    }
};
