<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_trainer_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('phone_number_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16); // user | assistant
            $t->text('content');
            $t->json('attachments')->nullable(); // [{ref,type,filename,path?,saved?,note?}]
            $t->json('saved')->nullable();       // ["Rule: ...", ...]
            $t->timestamps();
            $t->index(['phone_number_id', 'id']);
        });
        Schema::create('ai_open_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('phone_number_id')->constrained()->cascadeOnDelete();
            $t->string('contact_wa_id', 32)->nullable();
            $t->text('question');
            $t->string('status', 16)->default('open'); // open | resolved
            $t->text('answer')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['phone_number_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('ai_open_questions'); Schema::dropIfExists('ai_trainer_messages'); }
};
