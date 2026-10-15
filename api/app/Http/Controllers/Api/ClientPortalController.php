<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Services\MessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Client portal API: everything is scoped to the logged-in client's own numbers. */
class ClientPortalController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required']);
        $c = Client::where('email', $d['email'])->first();
        if (! $c || ! Hash::check($d['password'], $c->password)) throw ValidationException::withMessages(['email' => 'Invalid credentials']);
        if (! $c->is_active) throw ValidationException::withMessages(['email' => 'Your account is inactive. Contact the administrator.']);
        $c->forceFill(['last_login_at' => now()])->save();
        return ['token' => $c->createToken('client-portal')->plainTextToken, 'client' => $c];
    }
    public function me(Request $r) { return $r->user(); }
    public function logout(Request $r) { $r->user()->currentAccessToken()?->delete(); return ['ok' => true]; }

    protected function numberIds(Request $r) { return $r->user()->phoneNumbers()->pluck('id'); }
    protected function ownNumber(Request $r, $id): PhoneNumber
    {
        $n = $r->user()->phoneNumbers()->find($id);
        abort_unless($n, 404, 'Number not found');
        return $n;
    }

    public function numbers(Request $r) { return $r->user()->phoneNumbers()->orderBy('id')->get(); }

    public function templates(Request $r) { return $r->user()->templates()->orderBy('name')->get(); }

    public function dashboard(Request $r)
    {
        $ids = $this->numberIds($r);
        $m = fn () => Message::whereIn('phone_number_id', $ids);
        $today = now()->startOfDay();
        $tpl = $r->user()->templates()->select('status', DB::raw('COUNT(*) c'))->groupBy('status')->pluck('c', 'status');
        return [
            'numbers' => $ids->count(),
            'sent_today' => $m()->where('direction', 'out')->where('created_at', '>=', $today)->count(),
            'received_today' => $m()->where('direction', 'in')->where('created_at', '>=', $today)->count(),
            'total_sent' => $m()->where('direction', 'out')->count(),
            'total_received' => $m()->where('direction', 'in')->count(),
            'unread' => $m()->where('direction', 'in')->whereNull('read_at')->count(),
            'conversations' => $m()->select('phone_number_id', 'contact_wa_id')->distinct()->get()->count(),
            'templates' => ['total' => (int) $tpl->sum(), 'approved' => (int) ($tpl['APPROVED'] ?? 0), 'pending' => (int) ($tpl['PENDING'] ?? 0), 'rejected' => (int) ($tpl['REJECTED'] ?? 0)],
        ];
    }

    public function conversations(Request $r)
    {
        $ids = $this->numberIds($r);
        if ($r->filled('phone_number_id')) $ids = $ids->intersect([(int) $r->phone_number_id])->values();
        $rows = Message::whereIn('phone_number_id', $ids)
            ->select('phone_number_id', 'contact_wa_id', DB::raw('MAX(id) as last_id'), DB::raw("SUM(CASE WHEN direction='in' AND read_at IS NULL THEN 1 ELSE 0 END) as unread"))
            ->groupBy('phone_number_id', 'contact_wa_id')->get();
        $last = Message::whereIn('id', $rows->pluck('last_id'))->get()->keyBy('id');
        $names = Message::whereIn('contact_wa_id', $rows->pluck('contact_wa_id'))->whereNotNull('contact_name')->orderByDesc('id')
            ->get(['contact_wa_id', 'contact_name'])->unique('contact_wa_id')->pluck('contact_name', 'contact_wa_id');
        return $rows->map(fn ($row) => ['phone_number_id' => $row->phone_number_id, 'contact_wa_id' => $row->contact_wa_id,
            'contact_name' => $names[$row->contact_wa_id] ?? null, 'unread' => (int) $row->unread, 'last_message' => $last[$row->last_id] ?? null])
            ->sortByDesc(fn ($c) => $c['last_message']?->id)->values();
    }

    public function thread(Request $r)
    {
        $d = $r->validate(['phone_number_id' => 'required|integer', 'contact' => 'required|string']);
        $n = $this->ownNumber($r, $d['phone_number_id']);
        $q = Message::where('phone_number_id', $n->id)->where('contact_wa_id', $d['contact']);
        $msgs = (clone $q)->orderBy('id')->limit(500)->get();
        (clone $q)->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);
        return $msgs;
    }

    public function poll(Request $r)
    {
        $m = Message::whereIn('phone_number_id', $this->numberIds($r));
        return ['latest_id' => (int) (clone $m)->max('id'), 'updated_at' => (clone $m)->max('updated_at'),
            'unread' => (clone $m)->where('direction', 'in')->whereNull('read_at')->count()];
    }

    public function send(Request $r, MessagingService $svc)
    {
        $d = $r->validate([
            'phone_number_id' => 'required|integer', 'to' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'text' => 'nullable|string|max:4096|required_without:file', 'file' => MessageController::FILE_RULE,
        ]);
        $msg = $svc->send($this->ownNumber($r, $d['phone_number_id']), $d['to'], $d['text'] ?? null, $r->file('file'), null, 'client');
        return response()->json($msg, $msg->status === 'failed' ? 502 : 201);
    }
}
