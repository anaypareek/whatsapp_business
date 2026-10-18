<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneNumber extends Model
{
    protected $fillable = ['client_id', 'phone_number_id', 'display_number', 'name', 'waba_id', 'quality_rating', 'status',
        'name_status', 'platform_type', 'code_verification_status', 'account_mode', 'messaging_limit_tier', 'meta_checked_at'];
    protected $casts = ['meta_checked_at' => 'datetime'];

    /** Store the fields returned by GET /{phone-number-id} (missing fields keep their current value). */
    public function applyMeta(array $m): self
    {
        $map = ['display_phone_number' => 'display_number', 'verified_name' => 'name', 'quality_rating' => 'quality_rating', 'status' => 'status',
            'name_status' => 'name_status', 'platform_type' => 'platform_type', 'code_verification_status' => 'code_verification_status',
            'account_mode' => 'account_mode', 'messaging_limit_tier' => 'messaging_limit_tier'];
        foreach ($map as $k => $col) if (isset($m[$k]) && $m[$k] !== '') $this->{$col} = is_scalar($m[$k]) ? (string) $m[$k] : json_encode($m[$k]);
        $this->meta_checked_at = now();
        $this->save();
        return $this;
    }
    public function client() { return $this->belongsTo(Client::class); }
    public function messages() { return $this->hasMany(Message::class); }
    public function flows() { return $this->hasMany(Flow::class); }

    /** Resolve by local id, Meta phone_number_id, or display number (digits). */
    public static function resolve($v): ?self
    {
        $v = (string) $v;
        $digits = preg_replace('/\D/', '', $v);
        return static::where('phone_number_id', $v)->first()
            ?? (ctype_digit($v) && strlen($v) < 10 ? static::find($v) : null)
            ?? static::all()->first(fn ($p) => $digits !== '' && preg_replace('/\D/', '', $p->display_number) === $digits);
    }
}
