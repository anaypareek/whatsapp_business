<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageStatus extends Model
{
    protected $guarded = [];
    protected $casts = ['raw' => 'array', 'status_at' => 'datetime'];
}
