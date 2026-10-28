<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\MessageTemplate;
use App\Services\MessagingService;
use Illuminate\Http\Request;

/** POST /api/v1/messages/send — API-key auth, e.g. for Oswal Soap new-order alerts. */
class ExternalSendController extends Controller
{
    public function __invoke(Request $r, MessagingService $svc)
    {
        // accept "from", "from_number" or "from-number"
        // template may be given as a string name or as {name, language, params}
        if (is_array($r->input('template'))) {
            $tp = $r->input('template');
            $r->merge(['template' => $tp['name'] ?? null, 'language' => $r->input('language', $tp['language'] ?? null),
                'params' => $r->input('params', $tp['params'] ?? null), 'header_param' => $r->input('header_param', $tp['header_param'] ?? null),
                'button_params' => $r->input('button_params', $tp['button_params'] ?? null)]);
        }
        if (is_string($r->input('params'))) $r->merge(['params' => json_decode($r->input('params'), true) ?? array_map('trim', explode('|', $r->input('params')))]);
        $from = $r->input('from') ?? $r->input('from_number') ?? $r->input('from-number');
        $r->merge(['from' => $from]);
        $d = $r->validate([
            'from' => 'required|string', 'to' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'text' => 'nullable|string|max:4096|required_without_all:file,media_url,template',
            'template' => 'nullable|string|max:512', 'language' => 'nullable|string|max:16',
            'params' => 'nullable|array', 'params.*' => 'nullable|string|max:1024',
            'header_param' => 'nullable|string|max:2000', 'button_params' => 'nullable|array', 'button_params.*' => 'nullable|string|max:2000',
            'media_url' => 'nullable|url', 'file' => MessageController::FILE_RULE,
        ]);
        $key = $r->attributes->get('api_key');
        $phone = PhoneNumber::resolve($d['from']);
        if (! $phone) return response()->json(['message' => 'Unknown sender number. Use the phone_number_id or display number from Number Master.'], 422);
        if ($key && ! $key->canUsePhone($phone)) return response()->json(['message' => 'This API key cannot send from that number.', 'error_code' => 'NUMBER_NOT_ALLOWED'], 403);
        if (! empty($d['template'])) {
            $q = MessageTemplate::where('name', $d['template']);
            if ($key?->client_id) $q->where('client_id', $key->client_id);
            if (! empty($d['language'])) $q->where('language', $d['language']);
            $tpls = $q->get();
            if ($tpls->isEmpty()) return response()->json(['message' => "Template '{$d['template']}'".(! empty($d['language']) ? " ({$d['language']})" : '').' not found. Sync templates in the portal.'], 422);
            if ($tpls->count() > 1) return response()->json(['message' => 'Template exists in several languages; pass "language".', 'languages' => $tpls->pluck('language')], 422);
            $msg = $svc->sendTemplate($phone, $d['to'], $tpls->first(), $d['params'] ?? [], $d['header_param'] ?? null, $d['button_params'] ?? [], 'api');
        } else {
            $msg = $svc->send($phone, $d['to'], $d['text'] ?? null, $r->file('file'), $d['media_url'] ?? null, 'api');
        }
        return response()->json([
            'success' => $msg->status !== 'failed', 'id' => $msg->id, 'wamid' => $msg->wamid, 'status' => $msg->status, 'error' => $msg->error,
        ], $msg->status === 'failed' ? 502 : 201);
    }
}
