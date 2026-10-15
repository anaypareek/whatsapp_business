<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    public const EDITABLE = ['APPROVED', 'REJECTED', 'PAUSED'];
    public const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];
    // Meta: an APPROVED template may be edited at most once per 24h and 10 times per 30 days.
    public const MAX_EDITS_24H = 1;
    public const MAX_EDITS_30D = 10;

    protected $guarded = [];
    protected $casts = ['components' => 'array', 'last_synced_at' => 'datetime'];
    protected $appends = ['edit_check', 'body_variable_count', 'header_variable_count', 'header_format'];

    public function client() { return $this->belongsTo(Client::class); }
    public function edits() { return $this->hasMany(TemplateEdit::class); }
    public function events() { return $this->hasMany(TemplateEvent::class); }

    public function component(string $type): ?array
    {
        return collect($this->components ?? [])->first(fn ($c) => strtoupper($c['type'] ?? '') === $type);
    }
    public static function countVars(?string $text): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $text, $m);
        return $m[1] ? max(array_map('intval', $m[1])) : 0;
    }
    public function getBodyVariableCountAttribute(): int { return self::countVars($this->component('BODY')['text'] ?? null); }
    public function getHeaderFormatAttribute(): ?string { return $this->component('HEADER')['format'] ?? null; }
    public function getHeaderVariableCountAttribute(): int
    {
        $h = $this->component('HEADER');
        return ($h && ($h['format'] ?? 'TEXT') === 'TEXT') ? self::countVars($h['text'] ?? '') : 0;
    }

    /** @return array{allowed: bool, reason: ?string} */
    public function getEditCheckAttribute(): array
    {
        if (! $this->meta_id) return ['allowed' => false, 'reason' => 'Template not yet created on Meta.'];
        if (! in_array($this->status, self::EDITABLE)) {
            return ['allowed' => false, 'reason' => "Meta only allows editing APPROVED, REJECTED or PAUSED templates (current status: {$this->status})."];
        }
        if ($this->status === 'APPROVED') {
            $day = $this->edits()->where('status_at_edit', 'APPROVED')->where('created_at', '>=', now()->subDay())->count();
            $month = $this->edits()->where('status_at_edit', 'APPROVED')->where('created_at', '>=', now()->subDays(30))->count();
            if ($day >= self::MAX_EDITS_24H) return ['allowed' => false, 'reason' => 'Approved templates can be edited only once per 24 hours. Try again later.'];
            if ($month >= self::MAX_EDITS_30D) return ['allowed' => false, 'reason' => 'Approved templates can be edited at most 10 times in 30 days.'];
        }
        return ['allowed' => true, 'reason' => null];
    }

    public static function fromMeta(array $t, ?string $waba): self
    {
        $m = static::where('meta_id', $t['id'])->first()
            ?? static::where('name', $t['name'])->where('language', $t['language'])->first() ?? new static;
        $m->fill([
            'meta_id' => $t['id'], 'waba_id' => $waba, 'name' => $t['name'], 'language' => $t['language'],
            'category' => $t['category'] ?? 'UTILITY', 'status' => $t['status'] ?? 'PENDING', 'components' => $t['components'] ?? [],
            'rejected_reason' => ($t['rejected_reason'] ?? null) === 'NONE' ? null : ($t['rejected_reason'] ?? null),
            'quality_score' => $t['quality_score']['score'] ?? null, 'previous_category' => $t['previous_category'] ?? null,
            'last_synced_at' => now(),
        ])->save();
        return $m;
    }
}
