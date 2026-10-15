<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\TemplateRequest;
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

    /** Only APPROVED templates assigned to this client. */
    public function templates(Request $r) { return $r->user()->templates()->where('status', 'APPROVED')->orderBy('name')->get(); }

    // ---- Phone number requests ----
    public function numberRequests(Request $r)
    {
        return $r->user()->numberRequests()->with('createdNumber:id,display_number,status')->latest('id')
            ->get()->makeHidden(['reviewed_by', 'registration_response']);
    }
    public function storeNumberRequest(Request $r)
    {
        $d = $r->validate([
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'], 'display_name' => 'required|string|max:120',
            'business_name' => 'required|string|max:160', 'category' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:2000',
        ]);
        return response()->json($r->user()->numberRequests()->create($d + ['status' => 'pending']), 201);
    }

    // ---- Template requests ----
    public function templateRequests(Request $r)
    {
        return $r->user()->templateRequests()->with('template:id,name,language,status,rejected_reason')->latest('id')->get()->makeHidden(['reviewed_by']);
    }
    public function storeTemplateRequest(Request $r)
    {
        [$d, $form, $components] = TemplateRequestController::build($r);
        return response()->json($r->user()->templateRequests()->create([
            'name' => $d['name'], 'language' => $d['language'], 'category' => $d['category'], 'form' => $form, 'components' => $components, 'status' => 'pending',
        ]), 201);
    }

    // ---- Stats (scoped to the client's numbers) ----
    public function stats(Request $r)
    {
        $ids = $this->numberIds($r);
        $out = fn () => Message::whereIn('phone_number_id', $ids)->where('direction', 'out');
        $from = now()->subDays(6)->startOfDay();
        $rows = $out()->where('created_at', '>=', $from)->selectRaw('DATE(created_at) d, COUNT(*) c')->groupBy('d')->pluck('c', 'd');
        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $series[] = ['date' => $day->toDateString(), 'label' => $day->format('D'), 'sent' => (int) ($rows[$day->toDateString()] ?? 0)];
        }
        $tpl = $out()->where('type', 'template')
            ->selectRaw("message_template_id, MAX(template_name) template_name, COUNT(*) sent,
                SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) `read`,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) failed, MAX(created_at) last_sent")
            ->whereNotNull('message_template_id')->groupBy('message_template_id')->get();
        $meta = MessageTemplate::whereIn('id', $tpl->pluck('message_template_id'))->get(['id', 'name', 'language'])->keyBy('id');
        $templates = $tpl->map(fn ($t) => [
            'template_id' => $t->message_template_id, 'name' => $meta[$t->message_template_id]->name ?? $t->template_name,
            'language' => $meta[$t->message_template_id]->language ?? null, 'sent' => (int) $t->sent, 'delivered' => (int) $t->delivered,
            'read' => (int) $t->read, 'failed' => (int) $t->failed, 'last_sent' => $t->last_sent,
        ])->sortByDesc('last_sent')->values();
        return [
            'total_sent' => $out()->count(), 'numbers' => $ids->count(),
            'sent_today' => $out()->where('created_at', '>=', now()->startOfDay())->count(),
            'sent_month' => $out()->where('created_at', '>=', now()->startOfMonth())->count(),
            'series' => $series, 'templates' => $templates,
        ];
    }

    /** Every send of one template from this client's numbers: paginated, searchable by recipient/sender/status. */
    public function templateSends(Request $r, int $template)
    {
        $ids = $this->numberIds($r);
        $base = Message::whereIn('phone_number_id', $ids)->where('direction', 'out')->where('message_template_id', $template);
        $t = MessageTemplate::find($template);
        abort_unless($t && ($t->client_id === $r->user()->id || (clone $base)->exists()), 404, 'Template not found');
        $q = (clone $base)->with('phoneNumber:id,display_number,name')->orderByDesc('id');
        if ($r->filled('status')) $q->where('status', $r->status);
        if ($r->filled('q')) {
            $s = trim($r->q); $digits = preg_replace('/\D/', '', $s);
            $q->where(function ($w) use ($s, $digits) {
                $w->where('status', 'like', "%$s%");
                if ($digits !== '') $w->orWhere('contact_wa_id', 'like', "%$digits%")
                    ->orWhereHas('phoneNumber', fn ($p) => $p->where('display_number', 'like', "%$digits%"));
                $w->orWhereHas('phoneNumber', fn ($p) => $p->where('display_number', 'like', "%$s%")->orWhere('name', 'like', "%$s%"));
            });
        }
        $page = $q->paginate(min(100, max(5, (int) $r->input('per_page', 20))), ['id', 'phone_number_id', 'contact_wa_id', 'status', 'error', 'wamid', 'created_at', 'updated_at']);
        $agg = (clone $base)->selectRaw("COUNT(*) sent, SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) delivered,
            SUM(CASE WHEN status='read' THEN 1 ELSE 0 END) `read`, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed")->first();
        return ['template' => ['id' => $t->id, 'name' => $t->name, 'language' => $t->language, 'category' => $t->category],
            'totals' => array_map('intval', $agg->only(['sent', 'delivered', 'read', 'failed'])), 'sends' => $page];
    }

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
