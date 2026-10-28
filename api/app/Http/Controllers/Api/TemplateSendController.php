<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Services\MessagingService;
use Illuminate\Http\Request;

/**
 * POST /api/v1/templates/send  (API key, server-side only)
 * {from_number_id | phone_number, to, template, language?, variables: {body: [..], header: "..", buttons: [..]}}
 * Client keys may only use their own numbers and templates.
 */
class TemplateSendController extends Controller
{
    public function __invoke(Request $r, MessagingService $svc)
    {
        $d = $r->validate([
            'from_number_id' => 'nullable|string|max:64|required_without:phone_number',
            'phone_number' => 'nullable|string|max:32',
            'to' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'template' => 'required|string|max:512', 'language' => 'nullable|string|max:16',
            'variables' => 'nullable|array',
            'variables.body' => 'nullable|array', 'variables.body.*' => 'nullable|string|max:1024',
            'variables.header' => 'nullable|string|max:2000',
            'variables.buttons' => 'nullable|array', 'variables.buttons.*' => 'nullable|string|max:2000',
        ]);
        $key = $r->attributes->get('api_key');
        $phone = null;
        if (! empty($d['from_number_id'])) {
            $id = (string) $d['from_number_id'];
            $phone = PhoneNumber::where('phone_number_id', $id)->first() ?? (ctype_digit($id) && strlen($id) < 10 ? PhoneNumber::find($id) : null);
        } else {
            $phone = PhoneNumber::resolve($d['phone_number']);
        }
        if (! $phone || ! $key->canUsePhone($phone)) {
            return response()->json(['message' => 'Unknown sender number for this API key. Use from_number_id (Meta phone number id) or phone_number.',
                'error_code' => 'UNKNOWN_NUMBER'], 422);
        }
        $q = MessageTemplate::where('name', $d['template'])->when($key->client_id, fn ($q) => $q->where('client_id', $key->client_id));
        if (! empty($d['language'])) $q->where('language', $d['language']);
        $tpls = $q->get();
        if ($tpls->isEmpty()) return response()->json(['message' => "Template '{$d['template']}'".(! empty($d['language']) ? " ({$d['language']})" : '').' not found for this API key.', 'error_code' => 'UNKNOWN_TEMPLATE'], 422);
        if ($tpls->count() > 1) return response()->json(['message' => 'Template exists in several languages; pass "language".', 'languages' => $tpls->pluck('language')], 422);
        $tpl = $tpls->first();
        if ($tpl->status !== 'APPROVED') return response()->json(['message' => "Template '{$tpl->name}' is {$tpl->status}; only APPROVED templates can be sent.", 'error_code' => 'TEMPLATE_NOT_APPROVED'], 422);
        $body = array_values($d['variables']['body'] ?? []);
        if (count($body) !== $tpl->body_variable_count) {
            return response()->json(['message' => "Template '{$tpl->name}' needs {$tpl->body_variable_count} body variable(s); got ".count($body).'.',
                'error_code' => 'VARIABLE_COUNT', 'expected' => $tpl->body_variable_count], 422);
        }
        $msg = $svc->sendTemplate($phone, $d['to'], $tpl, $body, $d['variables']['header'] ?? null, $d['variables']['buttons'] ?? [], 'api');
        return response()->json([
            'success' => $msg->status !== 'failed', 'id' => $msg->id, 'wamid' => $msg->wamid, 'status' => $msg->status, 'error' => $msg->error,
            'template' => $tpl->name, 'language' => $tpl->language,
        ], $msg->status === 'failed' ? 502 : 201);
    }
}
