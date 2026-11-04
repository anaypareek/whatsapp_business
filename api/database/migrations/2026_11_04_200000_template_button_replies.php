<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('template_button_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_template_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('button_index');
            $t->string('button_text', 64);
            $t->string('reply_type', 16)->default('text'); // text | template | media
            $t->text('text')->nullable();                  // text reply, or media caption
            $t->foreignId('reply_template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            $t->json('template_params')->nullable();
            $t->string('media_type', 16)->nullable();      // image | document | video
            $t->string('media_url', 1024)->nullable();
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('sent_count')->default(0);
            $t->timestamps();
            $t->unique(['message_template_id', 'button_index']);
        });
    }
    public function down(): void { Schema::dropIfExists('template_button_replies'); }
};
