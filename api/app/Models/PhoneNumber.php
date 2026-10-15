<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneNumber extends Model
{
    protected $fillable = ['client_id', 'phone_number_id', 'display_number', 'name', 'waba_id', 'quality_rating', 'status'];
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
