<?php
namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;

/** A connected Instagram professional account (via its linked Facebook Page). Tokens are encrypted at rest and never serialized. */
class InstagramAccount extends Model
{
    protected $fillable = ['client_id', 'page_id', 'page_name', 'ig_user_id', 'username', 'name', 'profile_picture_url', 'access_token', 'user_access_token',
        'token_expires_at', 'data_access_expires_at', 'scopes', 'status', 'status_reason', 'last_checked_at', 'quota_usage', 'quota_total', 'quota_checked_at',
        'connected_by_type', 'connected_by_id'];
    protected $hidden = ['access_token', 'user_access_token'];
    protected $casts = [
        'access_token' => 'encrypted', 'user_access_token' => 'encrypted', 'scopes' => 'array',
        'token_expires_at' => UtcDateTime::class, 'data_access_expires_at' => UtcDateTime::class,
        'last_checked_at' => UtcDateTime::class, 'quota_checked_at' => UtcDateTime::class,
    ];

    public const ACTIVE = 'active';
    public const NEEDS_RECONNECT = 'needs_reconnect';

    public function client() { return $this->belongsTo(Client::class); }
    public function posts() { return $this->hasMany(InstagramPost::class); }
    public function isActive(): bool { return $this->status === self::ACTIVE; }

    public function markReconnect(string $reason): void
    {
        $this->forceFill(['status' => self::NEEDS_RECONNECT, 'status_reason' => mb_substr($reason, 0, 500), 'last_checked_at' => now()])->save();
    }
}
