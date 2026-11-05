<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/** A customer ("client" in the UI) who logs in to the client portal and sees only their own numbers. */
class Client extends Authenticatable
{
    use HasApiTokens;
    protected $fillable = ['name', 'company', 'email', 'password', 'is_active', 'ai_block_when_empty', 'last_login_at'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'ai_block_when_empty' => 'boolean', 'last_login_at' => 'datetime'];
    }
    public function phoneNumbers() { return $this->hasMany(PhoneNumber::class); }
    public function templates() { return $this->hasMany(MessageTemplate::class); }
    public function numberRequests() { return $this->hasMany(PhoneNumberRequest::class); }
    public function templateRequests() { return $this->hasMany(TemplateRequest::class); }
    public function aiCreditLedger() { return $this->hasMany(AiCreditLedger::class); }
    public function aiUsageLogs() { return $this->hasMany(AiUsageLog::class); }
}
