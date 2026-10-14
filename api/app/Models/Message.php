<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = [];
    protected $casts = ['raw' => 'array', 'read_at' => 'datetime'];
    protected $hidden = ['raw'];
    public function phoneNumber() { return $this->belongsTo(PhoneNumber::class); }
}
