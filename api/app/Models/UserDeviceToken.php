<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** FCM tokens of staff phones (WA Fineoutput Master app). */
class UserDeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'app_version', 'last_seen_at'];
    protected $casts = ['last_seen_at' => 'datetime'];
    protected $hidden = ['token'];
    public function user() { return $this->belongsTo(User::class); }
}
