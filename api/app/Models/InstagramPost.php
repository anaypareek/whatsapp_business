<?php
namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;

class InstagramPost extends Model
{
    public const FORMATS = ['image', 'reel', 'carousel', 'story'];
    public const EDITABLE = ['draft', 'scheduled', 'failed'];

    protected $fillable = ['instagram_account_id', 'batch_id', 'format', 'caption', 'share_to_feed', 'status', 'scheduled_at', 'stage', 'container_id', 'container_status',
        'container_created_at', 'ig_media_id', 'permalink', 'attempts', 'next_attempt_at', 'locked_until', 'deferred_reason', 'error', 'error_code', 'error_log_id',
        'posted_at', 'created_by_type', 'created_by_id'];
    protected $casts = [
        'share_to_feed' => 'boolean', 'scheduled_at' => UtcDateTime::class, 'container_created_at' => UtcDateTime::class, 'next_attempt_at' => UtcDateTime::class,
        'locked_until' => UtcDateTime::class, 'posted_at' => UtcDateTime::class,
    ];

    public function account() { return $this->belongsTo(InstagramAccount::class, 'instagram_account_id'); }
    public function items() { return $this->hasMany(InstagramPostItem::class)->orderBy('position'); }

    /** Not currently being worked on by the publisher. */
    public function isLocked(): bool { return $this->locked_until && $this->locked_until->isFuture(); }
    public function isEditable(): bool
    {
        if (! in_array($this->status, self::EDITABLE, true) || $this->isLocked()) return false;
        return ! ($this->status === 'scheduled' && $this->scheduled_at && $this->scheduled_at->lte(now())); // already due: the publisher takes it next minute
    }
}
