<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const KEYS = [
        'app_id', 'app_secret', 'access_token', 'waba_id', 'verify_token',
        'ai_base_url', 'ai_model', 'ai_api_key', 'ai_global_max_per_hour',
        'ai_usd_inr_rate', 'ai_model_prices',
        'orders_api_base_url', 'orders_api_key',
    ];
    public const SECRET = ['app_secret', 'access_token', 'ai_api_key', 'orders_api_key'];
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
