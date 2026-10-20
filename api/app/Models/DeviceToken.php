<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    protected $fillable = ['client_id', 'token', 'platform', 'app_version', 'last_seen_at'];
    protected $casts = ['last_seen_at' => 'datetime'];
    protected $hidden = ['token'];
    public function client() { return $this->belongsTo(Client::class); }
}
