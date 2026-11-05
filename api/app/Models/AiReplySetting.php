<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-number AI chat reply settings. Off by default; test mode (allowlist only) on by default. */
class AiReplySetting extends Model
{
    protected $fillable = [
        'phone_number_id', 'enabled', 'test_mode', 'allowlist', 'knowledge_base',
        'max_per_hour', 'handoff_cooldown_hours', 'handoff_text', 'handoff_text_hi',
        'human_active_quiet_minutes', 'orders_lookup', 'typing_indicator',
    ];
    protected $casts = [
        'enabled' => 'boolean', 'test_mode' => 'boolean', 'allowlist' => 'array',
        'max_per_hour' => 'integer', 'handoff_cooldown_hours' => 'integer',
        'human_active_quiet_minutes' => 'integer', 'orders_lookup' => 'boolean', 'typing_indicator' => 'boolean',
    ];
    protected $attributes = [
        'enabled' => false, 'test_mode' => true, 'max_per_hour' => 5,
        'handoff_cooldown_hours' => 24, 'human_active_quiet_minutes' => 30, 'typing_indicator' => true,
    ];

    public function allows(string $waId): bool
    {
        if (! $this->test_mode) return true;
        $w = preg_replace('/\D/', '', $waId);
        foreach ($this->allowlist ?? [] as $a) {
            $a = preg_replace('/\D/', '', (string) $a);
            if ($a !== '' && ($a === $w || (strlen($a) >= 10 && str_ends_with($w, $a)))) return true;
        }
        return false;
    }
}
