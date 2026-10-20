<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Mobile app: FCM device tokens + indexes that keep GET /client/chat/sync cheap. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $t) {
                $t->id();
                $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $t->string('token', 512)->unique();
                $t->string('platform', 16)->default('android');
                $t->string('app_version', 32)->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
                $t->index('client_id');
            });
        }
        // sync: MAX(updated_at) / "updated since" per number → index-only lookups
        if (! $this->hasIndex('messages', 'messages_pn_updated_index')) {
            Schema::table('messages', fn (Blueprint $t) => $t->index(['phone_number_id', 'updated_at'], 'messages_pn_updated_index'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        if ($this->hasIndex('messages', 'messages_pn_updated_index')) Schema::table('messages', fn (Blueprint $t) => $t->dropIndex('messages_pn_updated_index'));
    }

    protected function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $name);
    }
};
