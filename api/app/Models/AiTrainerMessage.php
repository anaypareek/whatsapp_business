<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiTrainerMessage extends Model
{
    protected $fillable = ['phone_number_id', 'role', 'content', 'attachments', 'saved'];
    protected $casts = ['attachments' => 'array', 'saved' => 'array'];
    protected $hidden = ['phone_number_id'];
}
