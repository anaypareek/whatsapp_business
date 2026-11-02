<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiKnowledgeFile extends Model
{
    protected $fillable = ['phone_number_id', 'filename', 'kind', 'chunks', 'chars'];
}
