<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Add number" flow in Number Master (POST /{waba}/phone_numbers → request_code → verify_code → register).
 * Additive only: every column is NULLABLE, existing rows are untouched (onboarding_step NULL = number added the old way / synced).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $t) {
            $add = fn (string $c) => ! Schema::hasColumn('phone_numbers', $c);
            if ($add('onboarding_step')) $t->string('onboarding_step', 32)->nullable(); // created|code_requested|verified|registered
            if ($add('country_code')) $t->string('country_code', 8)->nullable();
            if ($add('local_number')) $t->string('local_number', 32)->nullable();
            if ($add('code_method')) $t->string('code_method', 8)->nullable();
            if ($add('code_requested_at')) $t->timestamp('code_requested_at')->nullable();
            if ($add('verified_at')) $t->timestamp('verified_at')->nullable();
            if ($add('registered_at')) $t->timestamp('registered_at')->nullable();
            if ($add('onboarding_error')) $t->text('onboarding_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $t) {
            foreach (['onboarding_step', 'country_code', 'local_number', 'code_method', 'code_requested_at', 'verified_at', 'registered_at', 'onboarding_error'] as $c)
                if (Schema::hasColumn('phone_numbers', $c)) $t->dropColumn($c);
        });
    }
};
