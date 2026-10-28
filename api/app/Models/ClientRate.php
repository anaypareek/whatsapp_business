<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientRate extends Model
{
    protected $fillable = ['client_id', 'category', 'rate'];
    protected $casts = ['rate' => 'float'];
}
