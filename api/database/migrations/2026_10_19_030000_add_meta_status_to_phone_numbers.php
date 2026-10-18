<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Extra Cloud API status fields fetched from GET /{phone-number-id} (Register / Refresh status in Number Master). */
return new class extends Migration
{
    protected array $cols = ['name_status', 'platform_type', 'code_verification_status', 'account_mode', 'messaging_limit_tier'];

    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $t) {
            foreach ($this->cols as $c) if (! Schema::hasColumn('phone_numbers', $c)) $t->string($c, 64)->nullable();
            if (! Schema::hasColumn('phone_numbers', 'meta_checked_at')) $t->timestamp('meta_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $t) {
            foreach ([...$this->cols, 'meta_checked_at'] as $c) if (Schema::hasColumn('phone_numbers', $c)) $t->dropColumn($c);
        });
    }
};
