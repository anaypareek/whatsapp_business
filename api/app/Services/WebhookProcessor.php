<?php
namespace App\Services;

use App\Models\AutoReplyRule;
use App\Models\GreetingGroup;
use App\Models\GreetingSetting;
use App\Models\Message;
use App\Models\MessageStatus;
use App\Models\PhoneNumber;
use App\Models\MessageTemplate;
use App\Models\TemplateEvent;
use Illuminate\Support\Carbon;

class WebhookProcessor
{
    public function __construct(protected MessagingService $messaging, protected FlowEngine $flows) {}

    public function handle(array $payload): array
    {
        $stats = ['messages' => 0, 'statuses' => 0, 'auto_replies' => 0, 'flows' => 0, 'template_events' => 0, 'greetings' => 0];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;
                if (in_array($field, ['message_template_status_update', 'message_template_quality_update', 'template_category_update'])) {
                    $this->templateEvent($field, $change['value'] ?? [], $entry['id'] ?? null);
                    $stats['template_events']++;
                    continue;
                }
                if ($field !== 'messages') continue;
                $v = $change['value'] ?? [];
                $meta = $v['metadata'] ?? [];
                $phone = null;
                if (! empty($meta['phone_number_id'])) {
                    $phone = PhoneNumber::firstOrCreate(
                        ['phone_number_id' => $meta['phone_number_id']],
                        ['display_number' => $meta['display_phone_number'] ?? $meta['phone_number_id'], 'waba_id' => $entry['id'] ?? null]
                    );
                }
                $names = collect($v['contacts'] ?? [])->mapWithKeys(fn ($c) => [$c['wa_id'] => $c['profile']['name'] ?? null]);
                foreach ($v['messages'] ?? [] as $m) {
                    if (Message::where('wamid', $m['id'])->exists()) continue; // Meta retries; dedupe
                    $type = $m['type'] ?? 'unknown';
                    $body = match ($type) {
                        'text' => $m['text']['body'] ?? null,
                        'image', 'video', 'document', 'audio', 'sticker' => $m[$type]['caption'] ?? null,
                        'button' => $m['button']['text'] ?? null,
                        'interactive' => $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? null,
                        'location' => trim(($m['location']['name'] ?? '').' '.($m['location']['latitude'] ?? '').','.($m['location']['longitude'] ?? '')),
                        default => null,
                    };
                    $msg = Message::create([
                        'phone_number_id' => $phone?->id, 'contact_wa_id' => $m['from'],
                        'contact_name' => $names[$m['from']] ?? null, 'direction' => 'in', 'type' => $type,
                        'body' => $body, 'media_id' => $m[$type]['id'] ?? null,
                        'filename' => $m[$type]['filename'] ?? null, 'mime_type' => $m[$type]['mime_type'] ?? null,
                        'wamid' => $m['id'], 'status' => 'received', 'source' => 'webhook', 'raw' => $m,
                        'created_at' => isset($m['timestamp']) ? Carbon::createFromTimestamp((int) $m['timestamp']) : now(),
                    ]);
                    $stats['messages']++;
                    if ($phone) \App\Jobs\SendIncomingMessagePush::dispatchAfterResponse($msg->id); // client phones + Master app
                    // one auto-reply per inbound: keyword rules, then flows/sessions, then greeting / off-hours
                    if (! $phone) continue;
                    if ($body && $this->autoReply($phone, $msg)) $stats['auto_replies']++;
                    elseif ($this->flows->handle($phone, $msg)) $stats['flows']++;
                    elseif ($this->greeting($phone, $msg)) $stats['greetings']++;
                }
                foreach ($v['statuses'] ?? [] as $s) {
                    $orig = Message::where('wamid', $s['id'])->first();
                    MessageStatus::create([
                        'message_id' => $orig?->id, 'wamid' => $s['id'], 'status' => $s['status'],
                        'recipient_id' => $s['recipient_id'] ?? null, 'pricing_category' => isset($s['pricing']['category']) ? substr((string) $s['pricing']['category'], 0, 32) : null,
                        'status_at' => isset($s['timestamp']) ? Carbon::createFromTimestamp((int) $s['timestamp']) : now(), 'raw' => $s,
                    ]);
                    if ($orig && isset($s['pricing']['category'])) {
                        // Meta's billing category (marketing/utility/authentication/service…) — used by BillingService when present
                        $orig->pricing_category = substr((string) $s['pricing']['category'], 0, 32);
                        if (isset($s['pricing']['billable'])) $orig->billable = (bool) $s['pricing']['billable'];
                        if (! empty($s['conversation']['id'])) $orig->conversation_id = substr((string) $s['conversation']['id'], 0, 64);
                    }
                    if ($orig) {
                        $rank = ['pending' => 0, 'failed' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
                        if ($s['status'] === 'failed' || ($rank[$s['status']] ?? 0) > ($rank[$orig->status] ?? 0)) {
                            $orig->status = $s['status'];
                            if ($s['status'] === 'failed') $orig->error = $s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? 'failed';
                        }
                        if ($orig->isDirty()) $orig->save();
                    }
                    $stats['statuses']++;
                }
            }
        }
        return $stats;
    }

    protected function templateEvent(string $field, array $v, ?string $waba): void
    {
        $id = (string) ($v['message_template_id'] ?? '');
        $t = $id !== '' ? MessageTemplate::withoutGlobalScope('not_sample')->where('meta_id', $id)->first() : null;
        if (! $t && ! empty($v['message_template_name'])) {
            $t = MessageTemplate::withoutGlobalScope('not_sample')->where('name', $v['message_template_name'])->where('language', $v['message_template_language'] ?? '')->first();
        }
        $event = null; $reason = null;
        if ($field === 'message_template_status_update') {
            $event = strtoupper($v['event'] ?? '');
            $reason = $v['reason'] ?? null;
            if ($reason === 'NONE') $reason = null;
            if ($t) {
                $upd = ['status' => $event === 'FLAGGED' ? $t->status : $event];
                if ($event === 'REJECTED') $upd['rejected_reason'] = $reason ?? 'UNKNOWN';
                elseif ($event === 'APPROVED') $upd['rejected_reason'] = null;
                if (! empty($v['other_info']['title'])) $reason = trim(($reason ? "$reason: " : '').$v['other_info']['title'].' '.($v['other_info']['description'] ?? ''));
                $t->update($upd);
            }
        } elseif ($field === 'message_template_quality_update') {
            $event = $v['new_quality_score'] ?? null;
            $t?->update(['quality_score' => $event]);
        } elseif ($field === 'template_category_update') {
            $event = $v['new_category'] ?? null;
            if ($t && $event) $t->update(['previous_category' => $v['previous_category'] ?? $t->category, 'category' => $event]);
        }
        TemplateEvent::create(['message_template_id' => $t?->id, 'field' => $field, 'event' => $event, 'reason' => $reason, 'raw' => $v]);
    }

    /** Greeting / off-hours auto-reply (off unless enabled per number). Returns true if one was sent. */
    public function greeting(PhoneNumber $phone, Message $in): bool
    {
        $g = GreetingSetting::where('phone_number_id', $phone->id)->first();
        if (! $g || ! $g->enabled) return false;
        $off = $g->isOffHours();
        $grp = GreetingGroup::forContact($phone->id, $in->contact_wa_id);
        // group text wins when set; otherwise fall back to the number's default text
        $text = trim((string) ($off ? ($grp?->off_hours_text ?: $g->off_hours_text) : ($grp?->greeting_text ?: $g->greeting_text)));
        if ($text === '') return false;
        $contact = $in->contact_wa_id;
        $conv = Message::where('phone_number_id', $phone->id)->where('contact_wa_id', $contact);
        if ($g->mode === 'new_only' && (clone $conv)->where('direction', 'in')->where('id', '!=', $in->id)->exists()) return false;
        $since = now()->subHours(max(1, (int) $g->cooldown_hours));
        // cooldown: at most one greeting/off-hours message per customer per window
        if ((clone $conv)->where('direction', 'out')->whereIn('source', ['greeting', 'off_hours'])->where('created_at', '>=', $since)->exists()) return false;
        // a person (admin/staff) already replied manually in the window
        if ((clone $conv)->where('direction', 'out')->where('is_auto', false)->whereNotIn('source', MessagingService::AUTO_SOURCES)->where('created_at', '>=', $since)->exists()) return false;
        $text = str_replace('{name}', trim((string) $in->contact_name), $text);
        $this->messaging->send($phone, $contact, $text, mediaUrl: (! $off && $g->image_url) ? $g->image_url : null, source: $off ? 'off_hours' : 'greeting');
        return true;
    }

    protected function autoReply(PhoneNumber $phone, Message $in): bool
    {
        $rule = AutoReplyRule::where('enabled', true)
            ->where(fn ($q) => $q->whereNull('phone_number_id')->orWhere('phone_number_id', $phone->id))
            ->orderByDesc('priority')->orderBy('id')->get()
            ->first(fn ($r) => $r->matches($in->body));
        if (! $rule) return false;
        $this->messaging->send($phone, $in->contact_wa_id, $rule->reply, source: 'auto_reply');
        return true;
    }
}
