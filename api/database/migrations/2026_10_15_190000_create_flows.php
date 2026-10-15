<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: automation flows (per phone number) and per-contact flow sessions. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('flows')) Schema::create('flows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
            $t->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete(); // owner (copied from the number)
            $t->string('name');
            $t->boolean('is_active')->default(false);
            $t->string('trigger_type', 16)->default('contains'); // contains, exact, starts_with
            $t->json('keywords');
            $t->json('nodes');  // [{id, type: trigger|message|question|condition|webhook, position, data}]
            $t->json('edges');  // [{id, source, sourceHandle, target}]
            $t->unsignedInteger('triggered_count')->default(0);
            $t->unsignedInteger('completed_count')->default(0);
            $t->timestamps();
            $t->index(['phone_number_id', 'is_active']);
        });
        if (! Schema::hasTable('flow_sessions')) Schema::create('flow_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            $t->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
            $t->string('contact_wa_id');
            $t->string('current_node_id')->nullable(); // question node waiting for a reply
            $t->string('status', 16)->default('active'); // active, completed, expired, abandoned
            $t->text('last_input')->nullable();
            $t->json('history')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['phone_number_id', 'contact_wa_id', 'status']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('flow_sessions');
        Schema::dropIfExists('flows');
    }
};
