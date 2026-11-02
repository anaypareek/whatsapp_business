<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiExample extends Model
{
    protected $fillable = ['phone_number_id', 'question', 'answer'];
}
