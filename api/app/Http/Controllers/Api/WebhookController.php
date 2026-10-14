<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\WebhookProcessor;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /** GET verification handshake. PHP converts "hub.mode" to "hub_mode" in query parsing. */
    public function verify(Request $r)
    {
        $mode = $r->query('hub_mode', $r->query('hub.mode'));
        $token = $r->query('hub_verify_token', $r->query('hub.verify_token'));
        $challenge = $r->query('hub_challenge', $r->query('hub.challenge'));
        $expected = Setting::get('verify_token');
        if ($mode === 'subscribe' && $expected && is_string($token) && hash_equals($expected, $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }
        return response('Forbidden', 403);
    }

    public function receive(Request $r, WebhookProcessor $proc)
    {
        $raw = $r->getContent();
        if (! WhatsAppService::verifySignature($raw, $r->header('X-Hub-Signature-256'), Setting::get('app_secret'))) {
            Log::warning('WhatsApp webhook: invalid signature');
            return response()->json(['message' => 'Invalid signature'], 401);
        }
        $stats = $proc->handle(json_decode($raw, true) ?? []);
        return response()->json(['ok' => true] + $stats);
    }
}
