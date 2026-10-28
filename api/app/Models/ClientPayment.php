<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientPayment extends Model
{
    protected $fillable = ['client_id', 'amount', 'paid_at', 'method', 'reference', 'note', 'created_by'];
    protected $casts = ['amount' => 'float', 'paid_at' => 'date:Y-m-d'];
    public function client() { return $this->belongsTo(Client::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
