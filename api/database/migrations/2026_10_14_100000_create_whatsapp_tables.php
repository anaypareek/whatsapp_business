<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('is_admin')->default(false)->after('password'));

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable(); // encrypted
            $t->timestamps();
        });

        Schema::create('phone_numbers', function (Blueprint $t) {
            $t->id();
            $t->string('phone_number_id')->unique();
            $t->string('display_number');
            $t->string('name')->nullable();
            $t->string('waba_id')->nullable();
            $t->string('quality_rating')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('phone_number_id')->nullable()->constrained('phone_numbers')->nullOnDelete();
            $t->string('contact_wa_id')->index();
            $t->string('contact_name')->nullable();
            $t->enum('direction', ['in', 'out']);
            $t->string('type')->default('text');
            $t->text('body')->nullable();
            $t->string('media_id')->nullable();
            $t->string('media_url', 1024)->nullable();
            $t->string('filename')->nullable();
            $t->string('mime_type')->nullable();
            $t->string('wamid')->nullable()->unique();
            $t->string('status')->default('pending'); // pending,sent,delivered,read,failed,received
            $t->text('error')->nullable();
            $t->string('source')->default('manual'); // manual, auto_reply, api, webhook
            $t->json('raw')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['phone_number_id', 'contact_wa_id']);
        });

        Schema::create('message_statuses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('wamid')->index();
            $t->string('status');
            $t->string('recipient_id')->nullable();
            $t->timestamp('status_at')->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();
        });

        Schema::create('auto_reply_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('phone_number_id')->nullable()->constrained('phone_numbers')->cascadeOnDelete(); // null = all numbers
            $t->enum('match_type', ['keyword', 'contains', 'exact']);
            $t->string('trigger');
            $t->text('reply');
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('priority')->default(0);
            $t->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('prefix', 16);
            $t->string('key_hash', 64)->unique();
            $t->timestamp('last_used_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['api_keys', 'auto_reply_rules', 'message_statuses', 'messages', 'phone_numbers', 'settings'] as $t) Schema::dropIfExists($t);
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
