<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Per-conversation AI / automation state.
 *
 * - needs_human: set when the AI hands off (unanswered); smart-continue may still answer known KB questions during cooldown.
 * - taken_over: manual staff/client takeover. While true, ALL automatic replies are silent
 *   (AI including trained answers, keyword rules, greetings, flows, button replies).
 *   Manual outbound clears needs_human only — never taken_over.
 */
class AiContactState extends Model
{
    protected $fillable = [
        'phone_number_id', 'contact_wa_id', 'needs_human', 'handoff_at', 'reason',
        'taken_over', 'taken_over_at', 'taken_over_by_id', 'taken_over_by_type',
    ];
    protected $casts = [
        'needs_human' => 'boolean',
        'handoff_at' => 'datetime',
        'taken_over' => 'boolean',
        'taken_over_at' => 'datetime',
    ];

    public function takenOverBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'taken_over_by_type', 'taken_over_by_id');
    }

    /** [phone_number_id-contact => true] for flagged (needs_human) conversations. */
    public static function flagged(): array
    {
        return static::where('needs_human', true)->get(['phone_number_id', 'contact_wa_id'])
            ->mapWithKeys(fn ($s) => [$s->phone_number_id.'-'.$s->contact_wa_id => true])->all();
    }

    /** [phone_number_id-contact => true] for manually taken-over conversations. */
    public static function takenOverMap(): array
    {
        return static::where('taken_over', true)->get(['phone_number_id', 'contact_wa_id'])
            ->mapWithKeys(fn ($s) => [$s->phone_number_id.'-'.$s->contact_wa_id => true])->all();
    }

    public static function isTakenOver(int $phoneNumberId, string $contactWaId): bool
    {
        return static::where('phone_number_id', $phoneNumberId)->where('contact_wa_id', $contactWaId)
            ->where('taken_over', true)->exists();
    }

    /**
     * Staff/client takes the chat: AI + all auto-replies go silent.
     * Also clears needs_human so the list badge becomes "Human handling" only.
     */
    public static function takeOver(int $phoneNumberId, string $contactWaId, ?Model $by = null): self
    {
        $attrs = [
            'taken_over' => true,
            'taken_over_at' => now(),
            'needs_human' => false,
            'taken_over_by_id' => $by?->getKey(),
            'taken_over_by_type' => $by ? $by->getMorphClass() : null,
        ];
        return static::updateOrCreate(
            ['phone_number_id' => $phoneNumberId, 'contact_wa_id' => $contactWaId],
            $attrs
        );
    }

    /**
     * "Give to AI": clear takeover and handoff cooldown so AI resumes normally on the next inbound.
     */
    public static function release(int $phoneNumberId, string $contactWaId): self
    {
        return static::updateOrCreate(
            ['phone_number_id' => $phoneNumberId, 'contact_wa_id' => $contactWaId],
            [
                'taken_over' => false,
                'taken_over_at' => null,
                'taken_over_by_id' => null,
                'taken_over_by_type' => null,
                'needs_human' => false,
                'handoff_at' => null,
                'reason' => null,
            ]
        );
    }

    /** Compact API shape for a conversation row / thread meta. */
    public function toChatMeta(): array
    {
        return [
            'taken_over' => (bool) $this->taken_over,
            'taken_over_at' => $this->taken_over_at?->toIso8601String(),
            'needs_human' => (bool) $this->needs_human,
        ];
    }
}
