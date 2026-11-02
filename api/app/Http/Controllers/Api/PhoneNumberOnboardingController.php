<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Throwable;

/**
 * Number Master → "Add number": register a brand-new number on a WABA via the Cloud API.
 *   1. POST /{waba-id}/phone_numbers      (row saved here with onboarding_step=created, so the flow can be resumed)
 *   2. POST /{phone-number-id}/request_code (SMS|VOICE)
 *   3. POST /{phone-number-id}/verify_code
 *   4. POST /{phone-number-id}/register    (PhoneNumberController@register, 6-digit PIN, never stored)
 *   5. GET  /{phone-number-id}             (PhoneNumberController@refresh: name_status etc.)
 * Admin only. Meta errors come back as {message, error_code, meta:{meta_code, meta_message, error_user_msg, ...}}.
 */
class PhoneNumberOnboardingController extends Controller
{
    public const STILL_ON_APP_HINT = 'This number may still be registered on the WhatsApp or WhatsApp Business app. Delete that WhatsApp account on the phone (Settings > Account > Delete my account), wait 1 hour, then try again.';

    /** request_code / register errors that usually mean the number is still on the consumer/Business app → friendly hint shown above Meta's raw error. */
    public static function stillOnAppHint(Throwable $e): ?string
    {
        if (! $e instanceof \App\Services\MetaApiException) return null;
        $code = (int) $e->metaCode(); $sub = (int) $e->metaSubcode();
        return ($code === 136024 || in_array($sub, [2388001, 2388012, 2388091], true)) ? self::STILL_ON_APP_HINT : null;
    }

    protected function admin(Request $r): void { abort_unless($r->user()?->is_admin, 403, 'Only admins can add numbers.'); }
    protected function out(PhoneNumber $n) { return $n->fresh()->load('client:id,name,company,is_active'); }

    /** GET /phone-numbers-wabas: WABA ids known from Settings + existing numbers (default first). */
    public function wabas(Request $r)
    {
        $this->admin($r);
        $default = Setting::get('waba_id');
        $ids = collect([$default])->merge(PhoneNumber::whereNotNull('waba_id')->where('waba_id', '!=', '')->distinct()->pluck('waba_id'))->filter()->unique()->values();
        return ['default' => $default, 'wabas' => $ids->map(fn ($id) => ['id' => (string) $id, 'numbers' => PhoneNumber::where('waba_id', $id)->count(), 'is_default' => (string) $id === (string) $default])];
    }

    /** POST /phone-numbers-onboard {waba_id, cc, phone_number, verified_name, client_id?} */
    public function store(Request $r, WhatsAppService $wa)
    {
        $this->admin($r);
        $d = $r->validate([
            'waba_id' => ['required', 'string', 'regex:/^\d{5,25}$/'],
            'cc' => ['required', 'string', 'regex:/^\+?\d{1,4}$/'],
            'phone_number' => ['required', 'string', 'regex:/^[\d\s\-()]{4,20}$/'],
            'verified_name' => 'required|string|min:3|max:100',
            'client_id' => 'nullable|exists:clients,id',
        ], ['waba_id.regex' => 'The WABA ID must be numeric.', 'cc.regex' => 'The country code must be 1-4 digits (e.g. 91).', 'phone_number.regex' => 'Enter the phone number without the country code (digits only).']);
        $cc = ltrim($d['cc'], '+'); $local = preg_replace('/\D/', '', $d['phone_number']);
        try { $res = $wa->createPhoneNumber($d['waba_id'], $cc, $local, trim($d['verified_name'])); }
        catch (Throwable $e) { return $this->fail($e); }
        $id = (string) ($res['id'] ?? '');
        if ($id === '') return response()->json(['message' => 'Meta did not return a phone number ID.', 'meta_response' => $res], 502);
        $n = PhoneNumber::firstOrNew(['phone_number_id' => $id]);
        $n->fill(['display_number' => $n->display_number ?: "+$cc $local", 'name' => trim($d['verified_name']), 'waba_id' => $d['waba_id'],
            'country_code' => $cc, 'local_number' => $local, 'onboarding_step' => $n->onboarding_step ?: 'created', 'onboarding_error' => null]);
        if (array_key_exists('client_id', $d)) $n->client_id = $d['client_id'] ?: null;
        $n->save();
        return response()->json(['message' => 'Number added to the WABA. Next: request a verification code.', 'number' => $this->out($n)], 201);
    }

    /** POST /phone-numbers/{n}/request-code {code_method: SMS|VOICE, language} */
    public function requestCode(Request $r, PhoneNumber $phoneNumber, WhatsAppService $wa)
    {
        $this->admin($r);
        $d = $r->validate(['code_method' => 'required|in:SMS,VOICE', 'language' => ['nullable', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/']]);
        try { $wa->requestCode($phoneNumber->phone_number_id, $d['code_method'], $d['language'] ?? 'en_US'); }
        catch (Throwable $e) { $phoneNumber->update(['onboarding_error' => $e->getMessage()]); return $this->fail($e, 502, ['number' => $this->out($phoneNumber), 'hint' => self::stillOnAppHint($e)]); }
        $phoneNumber->update(['code_method' => $d['code_method'], 'code_requested_at' => now(), 'onboarding_error' => null,
            'onboarding_step' => in_array($phoneNumber->onboarding_step, ['verified', 'registered'], true) ? $phoneNumber->onboarding_step : 'code_requested']);
        return ['message' => 'Verification code sent by '.($d['code_method'] === 'SMS' ? 'SMS' : 'voice call').'.', 'number' => $this->out($phoneNumber)];
    }

    /** POST /phone-numbers/{n}/verify-code {code} */
    public function verifyCode(Request $r, PhoneNumber $phoneNumber, WhatsAppService $wa)
    {
        $this->admin($r);
        $d = $r->validate(['code' => ['required', 'string', 'regex:/^\d{4,8}$/']], ['code.regex' => 'Enter the numeric code Meta sent (usually 6 digits).']);
        try { $wa->verifyCode($phoneNumber->phone_number_id, $d['code']); }
        catch (Throwable $e) { $phoneNumber->update(['onboarding_error' => $e->getMessage()]); return $this->fail($e, 502, ['number' => $this->out($phoneNumber)]); }
        $phoneNumber->update(['onboarding_step' => $phoneNumber->onboarding_step === 'registered' ? 'registered' : 'verified', 'verified_at' => now(),
            'code_verification_status' => 'VERIFIED', 'onboarding_error' => null]);
        return ['message' => 'Phone number verified. Next: register it with a 6-digit PIN.', 'number' => $this->out($phoneNumber)];
    }
}
