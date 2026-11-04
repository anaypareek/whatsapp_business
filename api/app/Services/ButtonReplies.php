<?php
namespace App\Services;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\TemplateButtonReply;

/**
 * Per-template QUICK_REPLY follow-ups. An inbound button tap is tied to the template we sent via context.id
 * (= wamid of our outbound template message), so two templates with identical "Yes"/"No" buttons get different replies.
 * Fallback when context is missing: the most recent template we sent this contact on this number that has a matching button reply.
 */
class ButtonReplies
{
    public function __construct(protected MessagingService $messaging) {}

    public static function quickReplies(MessageTemplate $t): array
    {
        $out = [];
        foreach ($t->component('BUTTONS')['buttons'] ?? [] as $i => $b) if (strtoupper($b['type'] ?? '') === 'QUICK_REPLY') $out[] = ['index' => $i, 'text' => (string) ($b['text'] ?? '')];
        return $out;
    }

    /** The tapped button's text and payload, or null if the message is not a button tap. */
    public static function tap(Message $in): ?array
    {
        $raw = $in->raw ?? [];
        if (($raw['type'] ?? $in->type) === 'button') return ['text' => (string) ($raw['button']['text'] ?? ''), 'payload' => (string) ($raw['button']['payload'] ?? '')];
        if (isset($raw['interactive']['button_reply'])) return ['text' => (string) ($raw['interactive']['button_reply']['title'] ?? ''), 'payload' => (string) ($raw['interactive']['button_reply']['id'] ?? '')];
        return null;
    }

    protected static function norm(?string $s): string { return mb_strtolower(trim((string) $s)); }

    public static function match(int $templateId, array $tap): ?TemplateButtonReply
    {
        $t = self::norm($tap['text']); $p = self::norm($tap['payload']);
        return TemplateButtonReply::where('message_template_id', $templateId)->where('enabled', true)->orderBy('button_index')->get()
            ->first(fn ($r) => ($t !== '' && self::norm($r->button_text) === $t) || ($p !== '' && self::norm($r->button_text) === $p));
    }

    /** Returns true when a follow-up was sent (exactly one per inbound tap). */
    public function handle(PhoneNumber $phone, Message $in): bool
    {
        $tap = self::tap($in);
        if (! $tap || ($tap['text'] === '' && $tap['payload'] === '')) return false;
        $rule = null;
        $ctx = $in->raw['context']['id'] ?? null;
        if ($ctx) {
            $orig = Message::where('wamid', $ctx)->where('direction', 'out')->where('phone_number_id', $phone->id)->whereNotNull('message_template_id')->first();
            if ($orig) $rule = self::match($orig->message_template_id, $tap);
        }
        if (! $rule && ! ($ctx && isset($orig) && $orig)) {
            // no usable context: newest template sent to this contact on this number that has a matching reply
            $ids = Message::where('phone_number_id', $phone->id)->where('contact_wa_id', $in->contact_wa_id)->where('direction', 'out')
                ->whereNotNull('message_template_id')->where('created_at', '>=', now()->subDays(30))->latest('id')->limit(50)->pluck('message_template_id')->unique();
            foreach ($ids as $tid) if ($rule = self::match($tid, $tap)) break;
        }
        if (! $rule) return false;
        $sent = match ($rule->reply_type) {
            'template' => ($tpl = $rule->replyTemplate) ? GreetingTemplates::send($this->messaging, $phone, $in, $tpl, $rule->template_params, 'button') : null,
            'media' => $rule->media_url ? $this->messaging->send($phone, $in->contact_wa_id, $this->fill($rule->text, $in), mediaUrl: $rule->media_url, source: 'button') : null,
            default => trim((string) $rule->text) !== '' ? $this->messaging->send($phone, $in->contact_wa_id, $this->fill($rule->text, $in), source: 'button') : null,
        };
        if (! $sent) return false;
        $rule->increment('sent_count');
        return true;
    }

    protected function fill(?string $text, Message $in): ?string
    {
        if ($text === null || trim($text) === '') return null;
        return str_replace('{name}', trim((string) $in->contact_name) ?: 'there', $text);
    }
}
