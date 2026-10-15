<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowSession extends Model
{
    public const TIMEOUT_HOURS = 24;
    protected $guarded = [];
    protected $casts = ['history' => 'array', 'expires_at' => 'datetime'];
    public function flow() { return $this->belongsTo(Flow::class); }
    public function scopeLive($q) { return $q->where('status', 'active')->where('expires_at', '>', now()); }
}
