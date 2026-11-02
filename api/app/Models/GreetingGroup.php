<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GreetingGroup extends Model
{
    protected $fillable = ['phone_number_id', 'name', 'greeting_text', 'off_hours_text', 'priority', 'greeting_template_id', 'greeting_template_params', 'off_hours_template_id', 'off_hours_template_params'];
    protected $casts = ['greeting_template_params' => 'array', 'off_hours_template_params' => 'array', 'priority' => 'integer'];
    public function members() { return $this->hasMany(GreetingGroupMember::class); }

    /** Highest priority (then lowest id) group on this number that contains the contact. */
    public static function forContact(int $phoneId, string $contact): ?self
    {
        return self::where('phone_number_id', $phoneId)->whereHas('members', fn ($q) => $q->where('contact_wa_id', $contact))
            ->orderByDesc('priority')->orderBy('id')->first();
    }
}
