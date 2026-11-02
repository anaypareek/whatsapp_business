<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiKnowledgeChunk extends Model
{
    protected $fillable = ['phone_number_id', 'ai_knowledge_file_id', 'content'];
}
