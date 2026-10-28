<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class PhoneNumberController extends Controller
{
    public function index(Request $r)
    {
        $q = PhoneNumber::with('client:id,name,company,is_active')->orderBy('id');
        if ($r->filled('client_id')) $r->client_id === 'none' ? $q->whereNull('client_id') : $q->where('client_id', $r->client_id);
        return $q->get();
    }
    public function store(Request $r)
    {
        $d = $r->validate(['phone_number_id' => 'required|string|unique:phone_numbers', 'display_number' => 'required|string', 'name' => 'nullable|string', 'waba_id' => 'nullable|string', 'client_id' => 'nullable|exists:clients,id']);
        $d['waba_id'] ??= Setting::get('waba_id');
        return response()->json(PhoneNumber::create($d)->load('client:id,name,company,is_active'), 201);
    }
    public function show(PhoneNumber $phoneNumber) { return $phoneNumber; }
    public function update(Request $r, PhoneNumber $phoneNumber)
    {
        $d = $r->validate(['phone_number_id' => ['sometimes', 'string', Rule::unique('phone_numbers')->ignore($phoneNumber->id)], 'display_number' => 'sometimes|string', 'name' => 'nullable|string', 'waba_id' => 'nullable|string', 'client_id' => 'nullable|exists:clients,id']);
        $phoneNumber->update($d);
        return $phoneNumber->load('client:id,name,company,is_active');
    }
    public function destroy(PhoneNumber $phoneNumber) { $phoneNumber->delete(); return response()->noContent(); }

    public function sync(WhatsAppService $wa)
    {
        try { $remote = $wa->listPhoneNumbers(); } catch (Throwable $e) { return $this->fail($e); }
        \App\Services\WabaHealth::refreshQuietly();
        $waba = Setting::get('waba_id');
        foreach ($remote as $n) {
            $p = PhoneNumber::firstOrNew(['phone_number_id' => $n['id']]);
            $p->fill(['display_number' => $n['display_phone_number'] ?? $p->display_number ?? $n['id'], 'waba_id' => $waba]);
            if (! isset($n['status']) && isset($n['code_verification_status'])) $n['status'] = $n['code_verification_status'];
            $p->applyMeta($n);
        }
        return ['synced' => count($remote), 'numbers' => PhoneNumber::with('client:id,name,company,is_active')->orderBy('id')->get()];
    }
    /**
     * POST /phone-numbers/{n}/register {pin}: POST /{phone-number-id}/register on Meta, then refresh the number's status.
     * The PIN becomes (or must match) the number's two-step verification PIN. It is never stored or logged.
     */
    public function register(Request $r, PhoneNumber $phoneNumber, WhatsAppService $wa)
    {
        abort_unless($r->user()?->is_admin, 403, 'Only admins can register numbers.');
        $d = $r->validate(['pin' => ['required', 'string', 'regex:/^\d{6}$/']], ['pin.regex' => 'The PIN must be exactly 6 digits.']);
        try { $res = $wa->registerNumber($phoneNumber->phone_number_id, $d['pin']); }
        catch (Throwable $e) { return $this->fail($e, 502, ['registered' => false, 'number' => $phoneNumber->load('client:id,name,company,is_active')]); }
        unset($d);
        $refreshError = null;
        try { $phoneNumber->applyMeta($wa->getPhoneNumber($phoneNumber->phone_number_id)); }
        catch (Throwable $e) { $refreshError = $e->getMessage(); }
        return ['registered' => (bool) ($res['success'] ?? true), 'message' => 'Number registered with the Cloud API.'.($refreshError ? ' (Status refresh failed: '.$refreshError.')' : ''),
            'refresh_error' => $refreshError, 'number' => $phoneNumber->fresh()->load('client:id,name,company,is_active')];
    }

    /** POST /phone-numbers/{n}/refresh: re-fetch status/quality/name from Meta. */
    public function refresh(PhoneNumber $phoneNumber, WhatsAppService $wa)
    {
        try { $m = $wa->getPhoneNumber($phoneNumber->phone_number_id); }
        catch (Throwable $e) { return $this->fail($e); }
        return ['number' => $phoneNumber->applyMeta($m)->fresh()->load('client:id,name,company,is_active'), 'meta' => $m];
    }
}
