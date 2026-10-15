<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneNumberRequest extends Model
{
    protected $guarded = [];
    protected $casts = ['registration_response' => 'array', 'reviewed_at' => 'datetime'];
    public function client() { return $this->belongsTo(Client::class); }
    public function createdNumber() { return $this->belongsTo(PhoneNumber::class, 'created_phone_number_id'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
