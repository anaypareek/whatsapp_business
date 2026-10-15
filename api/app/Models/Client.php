<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/** A customer ("client" in the UI) who logs in to the client portal and sees only their own numbers. */
class Client extends Authenticatable
{
    use HasApiTokens;
    protected $fillable = ['name', 'company', 'email', 'password', 'is_active', 'last_login_at'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }
    public function phoneNumbers() { return $this->hasMany(PhoneNumber::class); }
    public function templates() { return $this->hasMany(MessageTemplate::class); }
}
