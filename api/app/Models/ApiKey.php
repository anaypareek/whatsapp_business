<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $fillable = ['name', 'prefix', 'key_hash', 'last_used_at', 'created_by'];
    protected $hidden = ['key_hash'];
    protected $casts = ['last_used_at' => 'datetime'];

    /** @return array{0: ApiKey, 1: string} */
    public static function generate(string $name, ?int $userId = null): array
    {
        $plain = 'wab_'.Str::random(40);
        $key = static::create(['name' => $name, 'prefix' => substr($plain, 0, 10), 'key_hash' => hash('sha256', $plain), 'created_by' => $userId]);
        return [$key, $plain];
    }
    public static function findByPlain(string $plain): ?self
    {
        return static::where('key_hash', hash('sha256', $plain))->first();
    }
}
