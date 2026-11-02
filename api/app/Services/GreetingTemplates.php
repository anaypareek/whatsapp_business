<?php
namespace App\Services;

use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;

/**
 * Template slots for greeting / off-hours messages.
 * params = ['body' => [ {type: fixed|name|phone, value?, fallback?}, ... ], 'header' => {type, value?, fallback?} | null]
 */
class GreetingTemplates
{
    public const SLOTS = ['greeting', 'off_hours'];

    /** APPROVED templates this number may use: same WABA; for a client, only templates assigned to that client. */
    public static function usable(PhoneNumber $n, ?Client $client = null)
    {
        return MessageTemplate::where('status', 'APPROVED')
            ->when($n->waba_id, fn ($q) => $q->where(fn ($w) => $w->where('waba_id', $n->waba_id)->orWhereNull('waba_id')))
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->orderBy('name');
    }

    /** What the UI needs to build the mapping form. */
    public static function describe(MessageTemplate $t): array
    {
        $fmt = $t->header_format;
        return ['id' => $t->id, 'name' => $t->name, 'language' => $t->language, 'category' => $t->category,
            'body' => $t->component('BODY')['text'] ?? '', 'body_vars' => $t->body_variable_count,
            'header_format' => $fmt, 'header_text' => $t->component('HEADER')['text'] ?? null,
            'header_needs' => in_array($fmt, ['IMAGE', 'DOCUMENT', 'VIDEO']) ? 'media' : ($fmt === 'TEXT' && $t->header_variable_count > 0 ? 'text' : null),
            'blocked' => self::unsupported($t)];
    }

    /** Reason this template can't be used as an automatic greeting, or null. */
    public static function unsupported(MessageTemplate $t): ?string
    {
        if (TemplateBuilder::isNamed($t->component('BODY')['text'] ?? '') || TemplateBuilder::isNamed($t->component('HEADER')['text'] ?? '')) {
            return 'Templates with named variables ({{name}}) are not supported for greetings; use one with {{1}}, {{2}}…';
        }
        if ($t->header_format === 'LOCATION') return 'Location-header templates can’t be sent automatically.';
        foreach ($t->component('BUTTONS')['buttons'] ?? [] as $b) {
            $type = strtoupper($b['type'] ?? '');
            if (($type === 'URL' && MessageTemplate::countVars($b['url'] ?? '') > 0) || in_array($type, ['OTP', 'COPY_CODE', 'CATALOG', 'MPM', 'FLOW'])) {
                return 'This template has a button that needs a value per message ('.$type.'); greetings can’t fill it.';
            }
        }
        return null;
    }

    /** Validate one slot. Returns [errorsByField]. */
    public static function validate(PhoneNumber $n, ?Client $client, string $slot, ?int $templateId, $params): array
    {
        if (! $templateId) return [];
        $f = "{$slot}_template_id";
        $t = MessageTemplate::find($templateId);
        if (! $t) return [$f => 'Template not found.'];
        if ($client && $t->client_id !== $client->id) return [$f => 'Template not found.'];
        if ($t->status !== 'APPROVED') return [$f => "Template '{$t->name}' is {$t->status}; only APPROVED templates can be used."];
        if (! self::usable($n, $client)->whereKey($t->id)->exists()) return [$f => "Template '{$t->name}' belongs to a different WhatsApp Business Account than this number."];
        if ($why = self::unsupported($t)) return [$f => $why];
        $params = is_array($params) ? $params : [];
        $body = array_values($params['body'] ?? []);
        $need = $t->body_variable_count;
        if (count($body) !== $need) return ["{$slot}_template_params" => "Template '{$t->name}' has {$need} body variable(s); you mapped ".count($body).'.'];
        foreach ($body as $i => $p) if ($e = self::badParam($p)) return ["{$slot}_template_params" => 'Variable {{'.($i + 1).'}}: '.$e];
        $d = self::describe($t);
        if ($d['header_needs']) {
            $h = $params['header'] ?? null;
            if (! is_array($h)) return ["{$slot}_template_params" => $d['header_needs'] === 'media'
                ? "Template '{$t->name}' has a {$t->header_format} header: enter a public media URL for it."
                : "Template '{$t->name}' has a header variable: map a value for it."];
            if ($d['header_needs'] === 'media' && (($h['type'] ?? '') !== 'fixed' || ! preg_match('#^https?://\S+$#', (string) ($h['value'] ?? '')))) {
                return ["{$slot}_template_params" => "Header {$t->header_format}: enter a valid https:// media URL."];
            }
            if ($e = self::badParam($h)) return ["{$slot}_template_params" => 'Header: '.$e];
        }
        return [];
    }

    protected static function badParam($p): ?string
    {
        if (! is_array($p) || ! in_array($p['type'] ?? null, ['fixed', 'name', 'phone'], true)) return 'choose Fixed text, {name} or {phone}.';
        if ($p['type'] === 'fixed' && trim((string) ($p['value'] ?? '')) === '') return 'fixed value is empty.';
        if ($p['type'] === 'name' && trim((string) ($p['fallback'] ?? '')) === '') return 'enter fallback text for {name} (used when the contact has no profile name).';
        if (mb_strlen((string) ($p['value'] ?? '').(string) ($p['fallback'] ?? '')) > 1000) return 'value too long.';
        return null;
    }

    public static function resolve(array $p, Message $in): string
    {
        return match ($p['type'] ?? 'fixed') {
            'name' => trim((string) $in->contact_name) !== '' ? trim((string) $in->contact_name) : (string) ($p['fallback'] ?? ''),
            'phone' => '+'.$in->contact_wa_id,
            default => (string) ($p['value'] ?? ''),
        };
    }

    /** Send a template slot via the standard template-send service. */
    public static function send(MessagingService $m, PhoneNumber $phone, Message $in, MessageTemplate $t, ?array $params, string $source): ?Message
    {
        if ($t->status !== 'APPROVED' || self::unsupported($t)) return null;
        $body = array_map(fn ($p) => self::resolve($p, $in), array_values($params['body'] ?? []));
        $header = isset($params['header']) && is_array($params['header']) ? self::resolve($params['header'], $in) : null;
        try { return $m->sendTemplate($phone, $in->contact_wa_id, $t, $body, $header, [], $source); }
        catch (\Illuminate\Validation\ValidationException) { return null; }
    }
}
