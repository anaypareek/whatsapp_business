<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Human-handoff flag per conversation (set when the AI could not answer from the knowledge base). */
class AiContactState extends Model
{
    protected $fillable = ['phone_number_id', 'contact_wa_id', 'needs_human', 'handoff_at', 'reason'];
    protected $casts = ['needs_human' => 'boolean', 'handoff_at' => 'datetime'];

    /** [phone_number_id-contact => true] for flagged conversations. */
    public static function flagged(): array
    {
        return static::where('needs_human', true)->get(['phone_number_id', 'contact_wa_id'])
            ->mapWithKeys(fn ($s) => [$s->phone_number_id.'-'.$s->contact_wa_id => true])->all();
    }
}
