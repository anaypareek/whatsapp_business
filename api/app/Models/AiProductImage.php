<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProductImage extends Model
{
    protected $fillable = ['phone_number_id', 'name', 'tags', 'path', 'mime_type'];
    protected $appends = ['url'];

    public function getUrlAttribute(): string { return rtrim(config('app.url'), '/').'/storage/'.$this->path; }
}
