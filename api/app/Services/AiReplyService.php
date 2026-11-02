<?php
namespace App\Services;

use App\Models\AiContactState;
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

    public static function systemPrompt(PhoneNumber $phone, ?string $kb): string
    {
        $biz = $phone->name ?: 'our business';
        $kb = trim((string) $kb);
        return "You are the friendly WhatsApp assistant for {$biz}. Reply in the customer's language, warm and conversational, short (1-3 sentences), with a few fitting emojis.\n"
            ."For small talk (hi, hello, how are you, thanks, etc.) reply warmly and then ask: \"How can I help you? 😊\".\n"
            ."STRICT RULES: Never invent facts, prices, stock, delivery times, policies, links or contact details. Only use facts from the KNOWLEDGE BASE below. "
            ."If the customer asks something that is not answered in the knowledge base, or you are unsure, or they ask for a human, reply with exactly ".self::HANDOFF_TOKEN." and nothing else.\n"
            ."KNOWLEDGE BASE:\n".($kb !== '' ? $kb : '(empty - answer only small talk; anything else needs '.self::HANDOFF_TOKEN.')');
    }

    /** Generate and send the reply. Returns 'sent', 'handoff' or the skip/error reason. */
    public function reply(PhoneNumber $phone, Message $in): string
    {
        if ($why = $this->skipReason($phone, $in)) return $why;
        // another reply (staff/flow) already went out after this message: stay quiet
        if (Message::where('phone_number_id', $phone->id)->where('contact_wa_id', $in->contact_wa_id)->where('direction', 'out')->where('id', '>', $in->id)->exists()) return 'already_replied';
        $s = AiReplySetting::where('phone_number_id', $phone->id)->first();
        $cfg = static::config();
        $history = Message::where('phone_number_id', $phone->id)->where('contact_wa_id', $in->contact_wa_id)->where('id', '<=', $in->id)
            ->whereNotNull('body')->orderByDesc('id')->limit(self::HISTORY)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->direction === 'in' ? 'user' : 'assistant', 'content' => mb_substr((string) $m->body, 0, 1000)])->values()->all();
        try {
            $res = Http::withToken($cfg['key'])->acceptJson()->timeout(self::TIMEOUT)->connectTimeout(5)
                ->post($cfg['base_url'].'/chat/completions', [
                    'model' => $cfg['model'], 'max_tokens' => self::MAX_TOKENS, 'temperature' => 0.4,
                    'messages' => array_merge([['role' => 'system', 'content' => static::systemPrompt($phone, $s->knowledge_base)]], $history),
                ]);
            if (! $res->successful()) {
                Log::warning('AI reply: provider HTTP '.$res->status(), ['phone_number_id' => $phone->id]); // no key, no body
                return 'provider_error';
            }
            $text = trim((string) ($res->json('choices.0.message.content') ?? ''));
        } catch (Throwable $e) {
            Log::warning('AI reply: provider unreachable ('.class_basename($e).')', ['phone_number_id' => $phone->id]);
            return 'provider_error';
        }
        if ($text === '') return 'empty';
        $handoff = str_contains($text, self::HANDOFF_TOKEN);
        if ($handoff) {
            $text = self::HANDOFF_TEXT;
            AiContactState::updateOrCreate(['phone_number_id' => $phone->id, 'contact_wa_id' => $in->contact_wa_id],
                ['needs_human' => true, 'handoff_at' => now(), 'reason' => mb_substr((string) $in->body, 0, 255)]);
        }
        $this->messaging->send($phone, $in->contact_wa_id, mb_substr($text, 0, 4000), source: 'ai');
        return $handoff ? 'handoff' : 'sent';
    }
}
