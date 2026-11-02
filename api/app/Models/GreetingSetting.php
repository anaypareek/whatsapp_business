<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class GreetingSetting extends Model
{
    public const TZ = 'Asia/Kolkata';
    protected $fillable = ['phone_number_id', 'enabled', 'mode', 'greeting_text', 'image_url', 'cooldown_hours', 'hours_enabled', 'hours_start', 'hours_end', 'hours_days', 'off_hours_text', 'greeting_template_id', 'greeting_template_params', 'off_hours_template_id', 'off_hours_template_params'];
    protected $casts = ['greeting_template_params' => 'array', 'off_hours_template_params' => 'array', 'enabled' => 'boolean', 'hours_enabled' => 'boolean', 'cooldown_hours' => 'integer', 'hours_days' => 'array'];
    protected $attributes = ['enabled' => false, 'mode' => 'every', 'cooldown_hours' => 24, 'hours_enabled' => false];

    /** Business hours in Asia/Kolkata; overnight ranges (e.g. 20:00-02:00) are supported. */
    public function isOffHours(?Carbon $at = null): bool
    {
        if (! $this->hours_enabled || ! $this->hours_start || ! $this->hours_end) return false;
        $now = ($at ?? now())->copy()->setTimezone(self::TZ);
        $days = $this->hours_days ?: [1, 2, 3, 4, 5, 6, 7];
        $t = $now->format('H:i');
        $open = $this->hours_start <= $this->hours_end
            ? ($t >= $this->hours_start && $t < $this->hours_end && in_array($now->isoWeekday(), $days))
            : (($t >= $this->hours_start && in_array($now->isoWeekday(), $days)) || ($t < $this->hours_end && in_array($now->copy()->subDay()->isoWeekday(), $days)));
        return ! $open;
    }
}
