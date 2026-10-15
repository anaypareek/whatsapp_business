<?php
namespace App\Services;

use App\Models\Flow;
use App\Models\FlowSession;
use App\Models\Message;
use App\Models\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs automation flows for inbound webhook messages; per-contact state lives in flow_sessions (MySQL). */
class FlowEngine
{
    public function __construct(protected MessagingService $messaging) {}

    /** @return bool true when a flow handled the message (auto-replies are then skipped). */
    public function handle(PhoneNumber $phone, Message $in): bool
    {
        $raw = $in->raw ?? [];
        $iid = $raw['interactive']['button_reply']['id'] ?? $raw['interactive']['list_reply']['id'] ?? $raw['button']['payload'] ?? null;
        $text = $in->body;
        $contact = $in->contact_wa_id;

        FlowSession::where('phone_number_id', $phone->id)->where('contact_wa_id', $contact)->where('status', 'active')
            ->where('expires_at', '<=', now())->update(['status' => 'expired']);
        $s = FlowSession::live()->with('flow')->where('phone_number_id', $phone->id)->where('contact_wa_id', $contact)->latest('id')->first();

        if ($s && $s->current_node_id && $s->flow?->is_active) {
            $def = $s->flow->definition();
            $r = FlowRunner::reply($def, $s->current_node_id, $text, $iid);
            if ($r['matched']) { $this->apply($s, $r, $phone, $text); return true; }
            if ($flow = $this->triggered($phone, $text)) { $s->update(['status' => 'abandoned']); return $this->start($flow, $phone, $contact, $text); }
            $this->apply($s, FlowRunner::reask($def, $s->current_node_id), $phone, $text);
            return true;
        }
        if ($s) $s->update(['status' => 'abandoned']);

        $flow = $this->triggered($phone, $text);
        return $flow ? $this->start($flow, $phone, $contact, $text) : false;
    }

    public function triggered(PhoneNumber $phone, ?string $text): ?Flow
    {
        if ($text === null || trim($text) === '') return null;
        return Flow::where('phone_number_id', $phone->id)->where('is_active', true)->orderBy('id')->get()
            ->first(fn (Flow $f) => FlowRunner::triggerMatches($f->definition(), $text));
    }

    protected function start(Flow $flow, PhoneNumber $phone, string $contact, ?string $text): bool
    {
        $flow->increment('triggered_count');
        $s = FlowSession::create(['flow_id' => $flow->id, 'phone_number_id' => $phone->id, 'contact_wa_id' => $contact, 'status' => 'active',
            'last_input' => $text, 'history' => [], 'expires_at' => now()->addHours(FlowSession::TIMEOUT_HOURS)]);
        $s->setRelation('flow', $flow);
        $this->apply($s, FlowRunner::start($flow->definition(), $text), $phone, $text);
        return true;
    }

    protected function apply(FlowSession $s, array $r, PhoneNumber $phone, ?string $text): void
    {
        $history = $s->history ?? [];
        foreach ($r['outputs'] as $o) {
            $history[] = ['node' => $o['node_id'], 'kind' => $o['kind'], 'at' => now()->toIso8601String()] + (isset($o['matched']) ? ['matched' => $o['matched']] : []);
            try {
                match ($o['kind']) {
                    'message' => $this->messaging->send($phone, $s->contact_wa_id, $o['text'] !== '' ? $o['text'] : null, null, $o['media_url'], 'flow'),
                    'question' => $this->messaging->sendInteractive($phone, $s->contact_wa_id, $o['interactive'], $o['text'], 'flow'),
                    'webhook' => $this->callWebhook($o['url'], $s, $phone, $text),
                    default => null,
                };
            } catch (Throwable $e) { Log::warning('flow output failed', ['flow' => $s->flow_id, 'node' => $o['node_id'], 'error' => $e->getMessage()]); }
        }
        $upd = ['history' => array_slice($history, -100), 'last_input' => $text, 'expires_at' => now()->addHours(FlowSession::TIMEOUT_HOURS)];
        if ($r['waiting']) $upd['current_node_id'] = $r['waiting'];
        else { $upd['status'] = 'completed'; $upd['current_node_id'] = null; Flow::whereKey($s->flow_id)->increment('completed_count'); }
        $s->update($upd);
    }

    protected function callWebhook(?string $url, FlowSession $s, PhoneNumber $phone, ?string $text): void
    {
        if (! $url || ! self::safeUrl($url)) throw new \RuntimeException('Webhook URL not allowed');
        Http::timeout(8)->acceptJson()->post($url, [
            'event' => 'flow.webhook', 'flow_id' => $s->flow_id, 'session_id' => $s->id, 'phone_number' => $phone->display_number,
            'contact' => $s->contact_wa_id, 'last_input' => $text, 'at' => now()->toIso8601String(),
        ]);
    }

    /** Blocks webhooks to private, loopback and reserved addresses (SSRF). */
    public static function safeUrl(string $url): bool
    {
        $p = parse_url($url);
        if (! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
        if (app()->runningUnitTests()) return true;
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) return false;
        foreach ($ips as $ip) if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        return true;
    }
}
