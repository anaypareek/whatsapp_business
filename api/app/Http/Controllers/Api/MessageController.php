<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiContactState;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\MessageTemplate;
use App\Services\MessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public const FILE_RULE = 'nullable|file|max:16384|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,mp4,mp3,ogg,aac,amr';

    /** Portal send (Sanctum). */
    public function send(Request $r, MessagingService $svc)
    {
        $d = $r->validate([
            'phone_number_id' => 'required|exists:phone_numbers,id',
            'to' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'text' => 'nullable|string|max:4096|required_without_all:file,media_url',
            'file' => self::FILE_RULE, 'media_url' => 'nullable|url',
        ]);
        $msg = $svc->send(PhoneNumber::find($d['phone_number_id']), $d['to'], $d['text'] ?? null, $r->file('file'), $d['media_url'] ?? null);
        return response()->json($msg, $msg->status === 'failed' ? 502 : 201);
    }

    public function sendTemplate(Request $r, MessagingService $svc)
    {
        $d = $r->validate([
            'phone_number_id' => 'required|exists:phone_numbers,id',
            'to' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'template_id' => 'required|exists:message_templates,id',
            'params' => 'nullable|array', 'params.*' => 'nullable|string|max:1024',
            'header_param' => 'nullable|string|max:2000',
            'button_params' => 'nullable|array', 'button_params.*' => 'nullable|string|max:2000',
        ]);
        $msg = $svc->sendTemplate(PhoneNumber::find($d['phone_number_id']), $d['to'], MessageTemplate::find($d['template_id']),
            $d['params'] ?? [], $d['header_param'] ?? null, $d['button_params'] ?? []);
        return response()->json($msg, $msg->status === 'failed' ? 502 : 201);
    }

    /** Conversation list: one row per (number, contact). */
    public function conversations(Request $r)
    {
        $q = Message::query()->select('phone_number_id', 'contact_wa_id', DB::raw('MAX(id) as last_id'),
            DB::raw("SUM(CASE WHEN direction='in' AND read_at IS NULL THEN 1 ELSE 0 END) as unread"))
            ->groupBy('phone_number_id', 'contact_wa_id');
        if ($r->filled('phone_number_id')) $q->where('phone_number_id', $r->phone_number_id);
        $rows = $q->get();
        $last = Message::whereIn('id', $rows->pluck('last_id'))->get()->keyBy('id');
        $flag = AiContactState::flagged();
        $taken = AiContactState::takenOverMap();
        $names = Message::whereIn('contact_wa_id', $rows->pluck('contact_wa_id'))->whereNotNull('contact_name')
            ->orderByDesc('id')->get(['contact_wa_id', 'contact_name'])->unique('contact_wa_id')->pluck('contact_name', 'contact_wa_id');
        return $rows->map(fn ($row) => [
            'phone_number_id' => $row->phone_number_id, 'contact_wa_id' => $row->contact_wa_id,
            'contact_name' => $names[$row->contact_wa_id] ?? null, 'unread' => (int) $row->unread,
            'last_message' => $last[$row->last_id] ?? null,
            'needs_human' => isset($flag[$row->phone_number_id.'-'.$row->contact_wa_id]),
            'taken_over' => isset($taken[$row->phone_number_id.'-'.$row->contact_wa_id]),
        ])->sortByDesc(fn ($c) => $c['last_message']?->id)->values();
    }

    public function thread(Request $r)
    {
        $d = $r->validate(['phone_number_id' => 'required|integer', 'contact' => 'required|string', 'after_id' => 'nullable|integer']);
        $q = Message::where('phone_number_id', $d['phone_number_id'])->where('contact_wa_id', $d['contact'])->orderBy('id');
        if (! empty($d['after_id'])) $q->where('id', '>', $d['after_id']); else $q->limit(500);
        $msgs = $q->get();
        Message::where('phone_number_id', $d['phone_number_id'])->where('contact_wa_id', $d['contact'])
            ->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);
        return [
            'messages' => $msgs,
            'taken_over' => AiContactState::isTakenOver((int) $d['phone_number_id'], $d['contact']),
            'needs_human' => (bool) AiContactState::where('phone_number_id', $d['phone_number_id'])->where('contact_wa_id', $d['contact'])->value('needs_human'),
        ];
    }

    /** Lightweight poll: latest message id + unread count. */
    public function poll()
    {
        return ['latest_id' => (int) Message::max('id'), 'updated_at' => Message::max('updated_at'),
            'unread' => Message::where('direction', 'in')->whereNull('read_at')->count()];
    }

    /** Manual takeover: stop ALL automatic replies for this chat until release. */
    public function takeover(Request $r)
    {
        $d = $r->validate(['phone_number_id' => 'required|integer|exists:phone_numbers,id', 'contact' => 'required|string|max:32']);
        $st = AiContactState::takeOver((int) $d['phone_number_id'], $d['contact'], $r->user());
        return $st->toChatMeta() + ['phone_number_id' => (int) $d['phone_number_id'], 'contact_wa_id' => $d['contact']];
    }

    /** Give chat back to AI: clear takeover + needs_human/handoff cooldown. */
    public function release(Request $r)
    {
        $d = $r->validate(['phone_number_id' => 'required|integer|exists:phone_numbers,id', 'contact' => 'required|string|max:32']);
        $st = AiContactState::release((int) $d['phone_number_id'], $d['contact']);
        return $st->toChatMeta() + ['phone_number_id' => (int) $d['phone_number_id'], 'contact_wa_id' => $d['contact']];
    }
}
