<?php
namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class InstagramMedia extends Model
{
    protected $table = 'instagram_media';
    protected $fillable = ['client_id', 'user_id', 'kind', 'path', 'mime', 'original_name', 'size', 'width', 'height', 'duration', 'warnings', 'purged_at'];
    protected $casts = ['warnings' => 'array', 'duration' => 'float', 'purged_at' => UtcDateTime::class];
    protected $appends = ['url'];
    protected $hidden = ['path'];

    /** Publicly reachable URL: Instagram's servers download the file from here. Set IG_MEDIA_BASE_URL if APP_URL is not the public origin. */
    public function getUrlAttribute(): string
    {
        return rtrim(config('services.instagram.media_base_url') ?: config('app.url'), '/').'/storage/'.$this->path;
    }

    public function items() { return $this->hasMany(InstagramPostItem::class); }

    public function deleteFile(): void
    {
        if ($this->path && Storage::disk('public')->exists($this->path)) Storage::disk('public')->delete($this->path);
    }
}
