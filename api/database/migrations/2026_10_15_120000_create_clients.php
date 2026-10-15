<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('company')->nullable();
            $t->string('email')->unique(); // login
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });
        Schema::table('phone_numbers', fn (Blueprint $t) => $t->foreignId('client_id')->nullable()->after('id')->constrained('clients')->nullOnDelete());
        Schema::table('message_templates', fn (Blueprint $t) => $t->foreignId('client_id')->nullable()->after('id')->constrained('clients')->nullOnDelete());
    }
    public function down(): void
    {
        Schema::table('message_templates', fn (Blueprint $t) => $t->dropConstrainedForeignId('client_id'));
        Schema::table('phone_numbers', fn (Blueprint $t) => $t->dropConstrainedForeignId('client_id'));
        Schema::dropIfExists('clients');
    }
};
