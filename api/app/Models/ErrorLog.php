<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorLog extends Model
{
    public const UPDATED_AT = null;
    public const RETENTION_DAYS = 30;
    protected $guarded = [];
    protected $casts = ['context' => 'array', 'resolved_at' => 'datetime', 'created_at' => 'datetime'];
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }
}
