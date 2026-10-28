<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per external API call. Never stores keys, bodies or variable values. */
class ApiRequestLog extends Model
{
    public const UPDATED_AT = null;
    public const RETENTION_DAYS = 30;
    protected $fillable = ['api_key_id', 'client_id', 'ip', 'method', 'endpoint', 'status', 'latency_ms', 'template', 'to', 'error'];
    protected $casts = ['status' => 'integer', 'latency_ms' => 'integer'];
    public function apiKey() { return $this->belongsTo(ApiKey::class); }
    public function client() { return $this->belongsTo(Client::class); }
}
