<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateRequest extends Model
{
    protected $guarded = [];
    protected $casts = ['form' => 'array', 'components' => 'array', 'reviewed_at' => 'datetime'];
    public function client() { return $this->belongsTo(Client::class); }
    public function template() { return $this->belongsTo(MessageTemplate::class, 'message_template_id'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
