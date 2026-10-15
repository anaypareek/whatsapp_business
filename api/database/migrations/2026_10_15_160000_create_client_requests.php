<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive only: two new tables and one nullable column on messages. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('phone_number_requests')) Schema::create('phone_number_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $t->string('phone_number', 32);
            $t->string('display_name');
            $t->string('business_name');
            $t->string('category')->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 16)->default('pending')->index(); // pending, approved, rejected
            $t->text('rejection_reason')->nullable();
            $t->foreignId('created_phone_number_id')->nullable()->constrained('phone_numbers')->nullOnDelete();
            $t->string('registration_status', 16)->nullable(); // registered, failed
            $t->text('registration_error')->nullable();
            $t->json('registration_response')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });

        if (! Schema::hasTable('template_requests')) Schema::create('template_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $t->string('name', 512);
            $t->string('language', 16);
            $t->string('category', 32);
            $t->json('form');        // builder form (header/body/footer/buttons), editable by admin before approval
            $t->json('components');  // Meta components generated from the form
            $t->string('status', 16)->default('pending')->index(); // pending, approved, rejected
            $t->text('rejection_reason')->nullable();
            $t->text('meta_error')->nullable();
            $t->foreignId('message_template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });

        if (! Schema::hasColumn('messages', 'message_template_id')) {
            Schema::table('messages', fn (Blueprint $t) => $t->foreignId('message_template_id')->nullable()->after('template_name')->constrained('message_templates')->nullOnDelete());
            // backfill from the stored "name (language)" label
            foreach (DB::table('message_templates')->get(['id', 'name', 'language']) as $tpl) {
                DB::table('messages')->whereNull('message_template_id')->where('template_name', "{$tpl->name} ({$tpl->language})")->update(['message_template_id' => $tpl->id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages', 'message_template_id')) Schema::table('messages', fn (Blueprint $t) => $t->dropConstrainedForeignId('message_template_id'));
        Schema::dropIfExists('template_requests');
        Schema::dropIfExists('phone_number_requests');
    }
};
