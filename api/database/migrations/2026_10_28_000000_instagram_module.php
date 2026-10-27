<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Instagram module (docs/INSTAGRAM.md). All *_at columns below except created_at/updated_at hold UTC. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('instagram_accounts')) Schema::create('instagram_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->string('page_id', 40);
            $t->string('page_name')->nullable();
            $t->string('ig_user_id', 40)->unique();
            $t->string('username')->nullable();
            $t->string('name')->nullable();
            $t->text('profile_picture_url')->nullable();
            $t->text('access_token');                    // Facebook Page token (encrypted cast)
            $t->text('user_access_token')->nullable();   // long-lived user token used to renew (encrypted cast)
            $t->dateTime('token_expires_at')->nullable();        // UTC; null = does not expire (page token) / unknown
            $t->dateTime('data_access_expires_at')->nullable();  // UTC
            $t->text('scopes')->nullable();
            $t->string('status', 20)->default('active')->index(); // active | needs_reconnect
            $t->string('status_reason', 500)->nullable();
            $t->dateTime('last_checked_at')->nullable();
            $t->unsignedInteger('quota_usage')->nullable();
            $t->unsignedInteger('quota_total')->nullable();
            $t->dateTime('quota_checked_at')->nullable();
            $t->string('connected_by_type', 10)->nullable();
            $t->unsignedBigInteger('connected_by_id')->nullable();
            $t->timestamps();
        });

        if (! Schema::hasTable('instagram_media')) Schema::create('instagram_media', function (Blueprint $t) {   // uploaded assets (public disk)
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('kind', 10);                       // image | video
            $t->string('path');                           // on the public disk, e.g. instagram/20261028/abc.jpg
            $t->string('mime', 60);
            $t->string('original_name')->nullable();
            $t->unsignedBigInteger('size');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->decimal('duration', 8, 2)->nullable();
            $t->text('warnings')->nullable();
            $t->dateTime('purged_at')->nullable();
            $t->timestamps();
        });

        if (! Schema::hasTable('instagram_posts')) Schema::create('instagram_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $t->string('batch_id', 40)->nullable()->index();   // one composer submit to N accounts = N posts sharing a batch id
            $t->string('format', 10);                          // image | reel | carousel | story
            $t->text('caption')->nullable();
            $t->boolean('share_to_feed')->default(true);       // reels only
            $t->string('status', 12)->default('draft');        // draft | scheduled | publishing | posted | failed | cancelled
            $t->dateTime('scheduled_at')->nullable();          // UTC
            $t->string('stage', 12)->default('new');           // new | processing | ready | published
            $t->string('container_id', 40)->nullable();        // the container that gets media_publish (single item, or the carousel parent)
            $t->string('container_status', 20)->nullable();
            $t->dateTime('container_created_at')->nullable();
            $t->string('ig_media_id', 40)->nullable();
            $t->string('permalink', 500)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->dateTime('next_attempt_at')->nullable();
            $t->dateTime('locked_until')->nullable();
            $t->string('deferred_reason', 500)->nullable();
            $t->text('error')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->unsignedBigInteger('error_log_id')->nullable();
            $t->dateTime('posted_at')->nullable();
            $t->string('created_by_type', 10)->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
            $t->index(['status', 'scheduled_at']);
        });

        if (! Schema::hasTable('instagram_post_items')) Schema::create('instagram_post_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instagram_post_id')->constrained('instagram_posts')->cascadeOnDelete();
            $t->foreignId('instagram_media_id')->constrained('instagram_media')->cascadeOnDelete();
            $t->unsignedTinyInteger('position')->default(0);
            $t->string('container_id', 40)->nullable();
            $t->string('container_status', 20)->nullable();
            $t->string('container_error', 500)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_post_items');
        Schema::dropIfExists('instagram_posts');
        Schema::dropIfExists('instagram_media');
        Schema::dropIfExists('instagram_accounts');
    }
};
