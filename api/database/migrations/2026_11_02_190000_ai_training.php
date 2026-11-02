<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_examples')) {
            Schema::create('ai_examples', function (Blueprint $t) {
                $t->id();
                $t->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
                $t->text('question');
                $t->text('answer');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_knowledge_files')) {
            Schema::create('ai_knowledge_files', function (Blueprint $t) {
                $t->id();
                $t->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
                $t->string('filename', 255);
                $t->string('kind', 10); // pdf, txt, csv
                $t->unsignedInteger('chunks')->default(0);
                $t->unsignedInteger('chars')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_knowledge_chunks')) {
            Schema::create('ai_knowledge_chunks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
                $t->foreignId('ai_knowledge_file_id')->constrained('ai_knowledge_files')->cascadeOnDelete();
                $t->text('content');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_product_images')) {
            Schema::create('ai_product_images', function (Blueprint $t) {
                $t->id();
                $t->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
                $t->string('name', 150);
                $t->string('tags', 500)->nullable();
                $t->string('path', 255);
                $t->string('mime_type', 100)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_product_images');
        Schema::dropIfExists('ai_knowledge_chunks');
        Schema::dropIfExists('ai_knowledge_files');
        Schema::dropIfExists('ai_examples');
    }
};
