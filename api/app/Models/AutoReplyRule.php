<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoReplyRule extends Model
{
    protected $fillable = ['phone_number_id', 'match_type', 'trigger', 'reply', 'enabled', 'priority'];
    protected $casts = ['enabled' => 'boolean', 'priority' => 'integer'];
    public function phoneNumber() { return $this->belongsTo(PhoneNumber::class); }

    public function matches(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $trig = mb_strtolower(trim($this->trigger));
        if ($trig === '') return false;
        return match ($this->match_type) {
            'exact' => $t === $trig,
            'contains' => str_contains($t, $trig),
            // keyword: whole-word match of any comma-separated keyword
            'keyword' => collect(explode(',', $trig))->map(fn ($k) => trim($k))->filter()
                ->contains(fn ($k) => preg_match('/(^|\W)'.preg_quote($k, '/').'($|\W)/u', $t)),
            default => false,
        };
    }
}
