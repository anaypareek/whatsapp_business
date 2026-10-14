<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const KEYS = ['app_id', 'app_secret', 'access_token', 'waba_id', 'verify_token'];
    public const SECRET = ['app_secret', 'access_token'];
    protected $fillable = ['key', 'value'];
    protected $casts = ['value' => 'encrypted'];

    public static function get(string $key, $default = null)
    {
        return static::where('key', $key)->first()?->value ?? $default;
    }
    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
