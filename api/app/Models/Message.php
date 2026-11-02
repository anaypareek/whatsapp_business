<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = [];
    protected $casts = ['raw' => 'array', 'read_at' => 'datetime'];
    protected $hidden = ['raw'];
    /** Any new/updated message invalidates the owning client's cached chat cursor (mobile sync sees it on the next poll). */
    protected static function booted(): void
    {
        static::saved(function (Message $m) {
            if (! $m->phone_number_id) return;
            $client = \Illuminate\Support\Facades\Cache::remember("chat:pnclient:{$m->phone_number_id}", 300, fn () => (int) PhoneNumber::whereKey($m->phone_number_id)->value('client_id'));
            if ($client) \App\Http\Controllers\Api\ClientChatController::bust($client);
            \App\Http\Controllers\Api\ClientChatController::bust('admin'); // Master app (all numbers)
        });
        // a person replied: clear the AI human-handoff flag so the AI may answer again later
        static::created(function (Message $m) {
            if ($m->direction !== 'out' || $m->is_auto || ! $m->phone_number_id || in_array($m->source, \App\Services\MessagingService::AUTO_SOURCES, true)) return;
            AiContactState::where('phone_number_id', $m->phone_number_id)->where('contact_wa_id', $m->contact_wa_id)->where('needs_human', true)->update(['needs_human' => false]);
        });
    }

    public function phoneNumber() { return $this->belongsTo(PhoneNumber::class); }
}
