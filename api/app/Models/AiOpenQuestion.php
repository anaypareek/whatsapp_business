<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A customer question the AI handed off / could not answer, kept so the admin can teach the answer in Train AI. */
class AiOpenQuestion extends Model
{
    protected $fillable = ['phone_number_id', 'contact_wa_id', 'question', 'status', 'answer', 'resolved_at'];
    protected $casts = ['resolved_at' => 'datetime'];

    public static function record(int $phoneNumberId, ?string $wa, string $question): void
    {
        $q = trim(mb_substr($question, 0, 1000));
        if ($q === '') return;
        $exists = static::where('phone_number_id', $phoneNumberId)->where('status', 'open')->where('question', $q)->exists();
        if (! $exists) static::create(['phone_number_id' => $phoneNumberId, 'contact_wa_id' => $wa, 'question' => $q]);
    }
}
