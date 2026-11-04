<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Follow-up sent when a customer taps a QUICK_REPLY button of one specific template. */
class TemplateButtonReply extends Model
{
    public const TYPES = ['text', 'template', 'media'];
    protected $guarded = ['id'];
    protected $casts = ['template_params' => 'array', 'enabled' => 'boolean'];

    public function template() { return $this->belongsTo(MessageTemplate::class, 'message_template_id'); }
    public function replyTemplate() { return $this->belongsTo(MessageTemplate::class, 'reply_template_id'); }
}
