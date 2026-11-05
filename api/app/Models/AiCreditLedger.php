<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Admin top-up or adjustment of a client's AI credit balance (INR). */
class AiCreditLedger extends Model
{
    public $timestamps = false;
    protected $table = 'ai_credit_ledger';
    protected $fillable = ['client_id', 'amount_inr', 'note', 'created_by', 'created_at'];
    protected $casts = ['amount_inr' => 'float', 'created_at' => 'datetime'];

    public function client() { return $this->belongsTo(Client::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
