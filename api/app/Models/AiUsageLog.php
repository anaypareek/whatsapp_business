<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One OpenAI-compatible chat-completions call (tokens + computed cost). */
class AiUsageLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['client_id', 'phone_number_id', 'feature', 'model', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost_usd', 'cost_inr', 'created_at'];
    protected $casts = [
        'prompt_tokens' => 'integer', 'completion_tokens' => 'integer', 'total_tokens' => 'integer',
        'cost_usd' => 'float', 'cost_inr' => 'float', 'created_at' => 'datetime',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function phoneNumber() { return $this->belongsTo(PhoneNumber::class); }
}
