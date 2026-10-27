<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramPostItem extends Model
{
    protected $fillable = ['instagram_post_id', 'instagram_media_id', 'position', 'container_id', 'container_status', 'container_error'];
    public function post() { return $this->belongsTo(InstagramPost::class, 'instagram_post_id'); }
    public function media() { return $this->belongsTo(InstagramMedia::class, 'instagram_media_id'); }
}
