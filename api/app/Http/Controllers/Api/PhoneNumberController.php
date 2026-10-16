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
        $waba = Setting::get('waba_id');
        foreach ($remote as $n) {
            PhoneNumber::updateOrCreate(['phone_number_id' => $n['id']], [
                'display_number' => $n['display_phone_number'] ?? $n['id'], 'name' => $n['verified_name'] ?? null,
                'quality_rating' => $n['quality_rating'] ?? null, 'status' => $n['status'] ?? $n['code_verification_status'] ?? null, 'waba_id' => $waba,
            ]);
        }
        return ['synced' => count($remote), 'numbers' => PhoneNumber::with('client:id,name,company,is_active')->orderBy('id')->get()];
    }
    public function register(Request $r, PhoneNumber $phoneNumber, WhatsAppService $wa)
    {
        $d = $r->validate(['pin' => 'required|digits:6']);
        try { return $wa->registerNumber($phoneNumber->phone_number_id, $d['pin']); }
        catch (Throwable $e) { return $this->fail($e); }
    }
}
