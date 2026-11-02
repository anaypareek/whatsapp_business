<?php
namespace App\Services;

use App\Models\AiContactState;
use App\Models\AiProductImage;
use App\Models\AiReplySetting;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI chat reply (OpenAI-compatible chat completions). Runs only when flows, keyword rules and greeting did not reply.
 * Off by default per number; test mode (allowlist only) on by default. The API key is never logged or returned.
 */
class AiReplyService
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    public const DEFAULT_MODEL = 'gpt-4o-mini';
    public const DEFAULT_GLOBAL_PER_HOUR = 200;
    public const HISTORY = 6;
    public const MAX_TOKENS = 200;
    public const TIMEOUT = 20;
    public const HANDOFF_TOKEN = '[HANDOFF]';
    public const HANDOFF_TEXT = "Thanks for your patience 🙏 I'm not fully sure about that, so a team member will get back to you shortly. 😊";

    public function __construct(protected MessagingService $messaging) {}

    public static function config(): array
    {
        return [
            'base_url' => rtrim(Setting::get('ai_base_url') ?: self::DEFAULT_BASE_URL, '/'),
            'model' => Setting::get('ai_model') ?: self::DEFAULT_MODEL,
            'key' => Setting::get('ai_api_key'),
            'global_per_hour' => (int) (Setting::get('ai_global_max_per_hour') ?: self::DEFAULT_GLOBAL_PER_HOUR),
        ];
    }

    /** Cheap synchronous checks; returns the reason to skip, or null if the AI may reply. */
    public function skipReason(PhoneNumber $phone, Message $in): ?string
    {
        $s = AiReplySetting::where('phone_number_id', $phone->id)->first();
        if (! $s || ! $s->enabled) return 'disabled';
        if ($in->direction !== 'in' || $in->is_auto || $in->type !== 'text' || trim((string) $in->body) === '') return 'not_text';
        $from = preg_replace('/\D/', '', (string) $in->contact_wa_id);
        // loop prevention: never answer our own / another business number
        $own = PhoneNumber::pluck('display_number')->map(fn ($d) => preg_replace('/\D/', '', (string) $d))->filter()->all();
        if (in_array($from, $own, true)) return 'business_number';
        if (! $s->allows($from)) return 'not_allowlisted';
        // 24h customer service window: the inbound itself must be recent (Meta retries / backfills of old messages are skipped)
        if ($in->created_at && $in->created_at->lt(now()->subHours(24))) return 'outside_window';
        if (! static::config()['key']) return 'no_api_key';
        $st = AiContactState::where('phone_number_id', $phone->id)->where('contact_wa_id', $in->contact_wa_id)->first();
        if ($st && $st->needs_human && $st->handoff_at && $st->handoff_at->gt(now()->subHours(max(1, $s->handoff_cooldown_hours)))) return 'handoff';
        $conv = Message::where('phone_number_id', $phone->id)->where('contact_wa_id', $in->contact_wa_id)->where('direction', 'out')->where('source', 'ai');
        if ((clone $conv)->where('created_at', '>=', now()->subHour())->count() >= max(1, $s->max_per_hour)) return 'rate_limit_contact';
        if (Message::where('direction', 'out')->where('source', 'ai')->where('created_at', '>=', now()->subHour())->count() >= max(1, static::config()['global_per_hour'])) return 'rate_limit_global';
        return null;
    }

    public static function systemPrompt(PhoneNumber $phone, ?string $kb, string $query = ''): string
    {
        $biz = $phone->name ?: 'our business';
        $kb = trim((string) $kb);
        $p = "You are the friendly WhatsApp assistant for {$biz}. Reply in the customer's language, warm and conversational, short (1-3 sentences), with a few fitting emojis.\n"
            ."For small talk (hi, hello, how are you, thanks, etc.) reply warmly and then ask: \"How can I help you? 😊\".\n"
            ."STRICT RULES: Never invent facts, prices, stock, delivery times, policies, links or contact details. Only use facts from the KNOWLEDGE BASE, CONTEXT and EXAMPLES below. "
            ."If the customer asks something that is not answered there, or you are unsure, or they ask for a human, reply with exactly ".self::HANDOFF_TOKEN." and nothing else.\n"
            ."KNOWLEDGE BASE:\n".($kb !== '' ? mb_substr($kb, 0, 4000) : '(empty)');
        $chunks = AiKnowledge::chunks($phone, $query);
        if ($chunks->isNotEmpty()) $p .= "\nCONTEXT (from uploaded documents):\n".$chunks->pluck('content')->implode("\n---\n");
        $ex = AiKnowledge::examples($phone, $query);
        if ($ex->isNotEmpty()) $p .= "\nEXAMPLES (approved answers; follow them):\n".$ex->map(fn ($e) => 'Q: '.mb_substr($e->question, 0, 300)."\nA: ".mb_substr($e->answer, 0, 500))->implode("\n");
        $imgs = AiKnowledge::images($phone, $query);
        if ($imgs->isNotEmpty()) $p .= "\nPRODUCT IMAGES: if the customer asks for a picture/photo of one of these, add the marker [IMAGE:id] to your reply (at most one): "
            .$imgs->map(fn ($i) => "[IMAGE:{$i->id}] {$i->name}".($i->tags ? " ({$i->tags})" : ''))->implode('; ').'. Never use any other id.';
        if (OrderLookup::configured()) $p .= "\nORDERS: if the customer asks about their order status/delivery, reply with exactly [ORDER_LOOKUP:<order id>] or [ORDER_LOOKUP:mine] if they gave no id. Never guess order details.";
        return $p;
    }

    /** One chat-completions call. Returns the text or null (error / timeout); never logs the key or bodies. */
    public function complete(PhoneNumber $phone, ?string $kb, array $history): ?string
    {
        $cfg = static::config();
        if (! $cfg['key']) return null;
        $query = collect($history)->where('role', 'user')->last()['content'] ?? '';
        try {
            $res = Http::withToken($cfg['key'])->acceptJson()->timeout(self::TIMEOUT)->connectTimeout(5)
                ->post($cfg['base_url'].'/chat/completions', [
                    'model' => $cfg['model'], 'max_tokens' => self::MAX_TOKENS, 'temperature' => 0.4,
                    'messages' => array_merge([['role' => 'system', 'content' => static::systemPrompt($phone, $kb, $query)]], array_slice($history, -self::HISTORY)),
                ]);
            if (! $res->successful()) { Log::warning('AI reply: provider HTTP '.$res->status(), ['phone_number_id' => $phone->id]); return null; }
            return trim((string) ($res->json('choices.0.message.content') ?? ''));
        } catch (Throwable $e) {
            Log::warning('AI reply: provider unreachable ('.class_basename($e).')', ['phone_number_id' => $phone->id]);
            return null;
        }
    }

    /** Parse markers in a model answer: ['text', 'handoff', 'image' (AiProductImage|null), 'order' (string|null)]. Images are scoped to the number. */
    public static function parse(PhoneNumber $phone, string $text): array
    {
        $out = ['text' => $text, 'handoff' => str_contains($text, self::HANDOFF_TOKEN), 'image' => null, 'order' => null];
        if (preg_match('/\[ORDER_LOOKUP:\s*([^\]]{1,64})\]/i', $text, $m)) $out['order'] = trim($m[1]);
        if (preg_match('/\[IMAGE:\s*(\d+)\]/i', $text, $m)) $out['image'] = AiProductImage::where('phone_number_id', $phone->id)->find((int) $m[1]);
        $out['text'] = trim(preg_replace('/\s*\[(IMAGE|ORDER_LOOKUP):[^\]]*\]\s*/i', ' ', str_replace(self::HANDOFF_TOKEN, '', $text)));
        return $out;
    }

    protected function handoff(PhoneNumber $phone, Message $in, string $reason): string
    {
        AiContactState::updateOrCreate(['phone_number_id' => $phone->id, 'contact_wa_id' => $in->contact_wa_id],
            ['needs_human' => true, 'handoff_at' => now(), 'reason' => mb_substr($reason, 0, 255)]);
        $this->messaging->send($phone, $in->contact_wa_id, self::HANDOFF_TEXT, source: 'ai');
        return 'handoff';
    }

    /** Generate and send the reply. Returns 'sent', 'image', 'order', 'handoff' or the skip/error reason. */
    public function reply(PhoneNumber $phone, Message $in): string
    {
        if ($why = $this->skipReason($phone, $in)) return $why;
        // another reply (staff/flow) already went out after this message: stay quiet
        if (Message::where('phone_number_id', $phone->id)->where('contact_wa_id', $in->contact_wa_id)->where('direction', 'out')->where('id', '>', $in->id)->exists()) return 'already_replied';
        $s = AiReplySetting::where('phone_number_id', $phone->id)->first();
        $history = Message::where('phone_number_id', $phone->id)->where('contact_wa_id', $in->contact_wa_id)->where('id', '<=', $in->id)
            ->whereNotNull('body')->orderByDesc('id')->limit(self::HISTORY)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->direction === 'in' ? 'user' : 'assistant', 'content' => mb_substr((string) $m->body, 0, 1000)])->values()->all();
        $text = $this->complete($phone, $s->knowledge_base, $history);
        if ($text === null) return 'provider_error';
        if ($text === '') return 'empty';
        $r = static::parse($phone, $text);
        if ($r['order'] !== null) {
            $id = strtolower($r['order']) === 'mine' ? null : $r['order'];
            $orders = app(OrderLookup::class)->lookup($in->contact_wa_id, $id); // always the sender's own phone
            if (! $orders) return $this->handoff($phone, $in, 'Order lookup '.($orders === null ? 'failed' : 'not found').': '.$in->body);
            $this->messaging->send($phone, $in->contact_wa_id, OrderLookup::format($orders), source: 'ai');
            return 'order';
        }
        if ($r['handoff']) {
            \App\Models\AiOpenQuestion::record($phone->id, $in->contact_wa_id, (string) $in->body); // teachable in Train AI
            return $this->handoff($phone, $in, (string) $in->body);
        }
        if ($r['image']) {
            $this->messaging->send($phone, $in->contact_wa_id, mb_substr($r['text'], 0, 1000) ?: $r['image']->name, mediaUrl: $r['image']->url, source: 'ai');
            return 'image';
        }
        if ($r['text'] === '') return 'empty';
        $this->messaging->send($phone, $in->contact_wa_id, mb_substr($r['text'], 0, 4000), source: 'ai');
        return 'sent';
    }
}
