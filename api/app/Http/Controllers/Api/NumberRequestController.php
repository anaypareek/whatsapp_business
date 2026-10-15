<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberRequest;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Admin review of client phone-number requests. */
class NumberRequestController extends Controller
{
    public function index(Request $r)
    {
        $q = PhoneNumberRequest::with(['client:id,name,company', 'createdNumber:id,phone_number_id,display_number', 'reviewer:id,name'])->latest('id');
        if ($r->filled('status')) $q->where('status', $r->status);
        if ($r->filled('q')) $q->where(fn ($w) => $w->where('phone_number', 'like', "%{$r->q}%")->orWhere('display_name', 'like', "%{$r->q}%")->orWhere('business_name', 'like', "%{$r->q}%"));
        return $q->get();
    }

    /**
     * Approve: create the number assigned to the client, then run the Meta registration (POST /{id}/register with the PIN)
     * through WhatsAppService. The outcome and any error are stored on the request; the number stays created either way.
     */
    public function approve(Request $r, PhoneNumberRequest $numberRequest, WhatsAppService $wa)
    {
        abort_unless($numberRequest->status === 'pending', 422, 'Only pending requests can be approved.');
        $d = $r->validate([
            'phone_number_id' => 'required|string|max:64|unique:phone_numbers,phone_number_id',
            'waba_id' => 'nullable|string|max:64',
            'pin' => 'required|digits:6',
        ]);
        $number = DB::transaction(function () use ($numberRequest, $d, $r) {
            $n = PhoneNumber::create([
                'client_id' => $numberRequest->client_id, 'phone_number_id' => $d['phone_number_id'],
                'display_number' => $numberRequest->phone_number, 'name' => $numberRequest->display_name,
                'waba_id' => $d['waba_id'] ?? Setting::get('waba_id'),
            ]);
            $numberRequest->update(['status' => 'approved', 'created_phone_number_id' => $n->id, 'rejection_reason' => null,
                'reviewed_by' => $r->user()->id, 'reviewed_at' => now()]);
            return $n;
        });
        try {
            $res = $wa->registerNumber($number->phone_number_id, $d['pin']);
            $numberRequest->update(['registration_status' => 'registered', 'registration_error' => null, 'registration_response' => $res]);
        } catch (Throwable $e) {
            $numberRequest->update(['registration_status' => 'failed', 'registration_error' => $e->getMessage()]);
        }
        return $numberRequest->fresh()->load(['client:id,name,company', 'createdNumber:id,phone_number_id,display_number', 'reviewer:id,name']);
    }

    /** Retry the Meta registration for an approved request whose registration failed. */
    public function retryRegistration(Request $r, PhoneNumberRequest $numberRequest, WhatsAppService $wa)
    {
        abort_unless($numberRequest->status === 'approved' && $numberRequest->createdNumber, 422, 'Only approved requests with a number can be registered.');
        $d = $r->validate(['pin' => 'required|digits:6']);
        try {
            $res = $wa->registerNumber($numberRequest->createdNumber->phone_number_id, $d['pin']);
            $numberRequest->update(['registration_status' => 'registered', 'registration_error' => null, 'registration_response' => $res]);
        } catch (Throwable $e) {
            $numberRequest->update(['registration_status' => 'failed', 'registration_error' => $e->getMessage()]);
        }
        return $numberRequest->fresh()->load(['client:id,name,company', 'createdNumber:id,phone_number_id,display_number', 'reviewer:id,name']);
    }

    public function reject(Request $r, PhoneNumberRequest $numberRequest)
    {
        abort_unless($numberRequest->status === 'pending', 422, 'Only pending requests can be rejected.');
        $d = $r->validate(['reason' => 'required|string|max:1000']);
        $numberRequest->update(['status' => 'rejected', 'rejection_reason' => $d['reason'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now()]);
        return $numberRequest->fresh()->load(['client:id,name,company', 'reviewer:id,name']);
    }
}
