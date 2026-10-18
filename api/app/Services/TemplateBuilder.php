<?php
namespace App\Services;

use App\Models\MessageTemplate;
use Illuminate\Validation\ValidationException;

/** Validates the portal's structured template form and converts it to Meta "components". */
class TemplateBuilder
{
    public static function rules(bool $isEdit = false): array
    {
        return [
            'name' => ($isEdit ? 'sometimes' : 'required').'|string|max:512|regex:/^[a-z0-9_]+$/',
            'language' => ($isEdit ? 'sometimes' : 'required').'|string|max:16|regex:/^[a-z]{2,3}(_[A-Z]{2})?$/',
            'category' => ($isEdit ? 'sometimes' : 'required').'|in:'.implode(',', MessageTemplate::CATEGORIES),
            'header' => 'nullable|array',
            'header.format' => 'nullable|in:NONE,TEXT,IMAGE,DOCUMENT,VIDEO',
            'header.text' => 'nullable|string|max:60',
            'header.example' => 'nullable|string|max:1000', // text var example, or media header_handle
            'body' => 'required|array',
            'body.text' => 'required|string|max:1024',
            'body.examples' => 'nullable|array',
            'body.examples.*' => 'nullable|string|max:500',
            'footer' => 'nullable|string|max:60',
            'buttons' => 'nullable|array|max:10',
            'buttons.*.type' => 'required|in:QUICK_REPLY,URL,PHONE_NUMBER',
            'buttons.*.text' => 'required|string|max:25',
            'buttons.*.url' => 'nullable|required_if:buttons.*.type,URL|string|max:2000',
            'buttons.*.phone_number' => 'nullable|required_if:buttons.*.type,PHONE_NUMBER|string|max:20',
            'buttons.*.example' => 'nullable|string|max:2000',
        ];
    }

    public static function components(array $d): array
    {
        $errors = [];
        $out = [];
        $h = $d['header'] ?? null;
        $fmt = strtoupper($h['format'] ?? 'NONE');
        if ($fmt === 'TEXT') {
            if (empty($h['text'])) $errors['header.text'] = 'Header text is required for a TEXT header.';
            $c = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $h['text'] ?? ''];
            $n = MessageTemplate::countVars($h['text'] ?? '');
            if ($n > 1) $errors['header.text'] = 'A text header supports at most one variable {{1}}.';
            if ($n === 1) {
                if (empty($h['example'])) $errors['header.example'] = 'Provide an example value for the header variable.';
                $c['example'] = ['header_text' => [$h['example'] ?? '']];
            }
            $out[] = $c;
        } elseif (in_array($fmt, ['IMAGE', 'DOCUMENT', 'VIDEO'])) {
            if (empty($h['example'])) $errors['header.example'] = 'Media headers need a sample: a header_handle from Meta\'s Resumable Upload API.';
            $out[] = ['type' => 'HEADER', 'format' => $fmt, 'example' => ['header_handle' => [$h['example'] ?? '']]];
        }

        $body = $d['body']['text'];
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m);
        $nums = array_map('intval', $m[1]);
        $c = ['type' => 'BODY', 'text' => $body];
        if ($nums) {
            $max = max($nums);
            $u = array_unique($nums); sort($u);
            if ($u !== range(1, $max)) {
                $errors['body.text'] = 'Body variables must be sequential: {{1}}, {{2}}, …';
            }
            $ex = array_values(array_filter($d['body']['examples'] ?? [], fn ($v) => $v !== null && $v !== ''));
            if (count($ex) < $max) $errors['body.examples'] = "Provide an example for each of the {$max} body variable(s).";
            $c['example'] = ['body_text' => [array_slice($ex, 0, $max)]];
        }
        if (preg_match('/^\s*\{\{\d+\}\}|\{\{\d+\}\}\s*$/', $body)) $errors['body.text'] = 'Meta rejects bodies that start or end with a variable.';
        $out[] = $c;

        if (! empty($d['footer'])) $out[] = ['type' => 'FOOTER', 'text' => $d['footer']];

        if (! empty($d['buttons'])) {
            $btns = [];
            foreach ($d['buttons'] as $i => $b) {
                $x = ['type' => $b['type'], 'text' => $b['text']];
                if ($b['type'] === 'URL') {
                    $x['url'] = $b['url'];
                    if (MessageTemplate::countVars($b['url']) > 0) {
                        if (empty($b['example'])) $errors["buttons.$i.example"] = 'Dynamic URL buttons need an example URL.';
                        $x['example'] = [$b['example'] ?? ''];
                    }
                }
                if ($b['type'] === 'PHONE_NUMBER') $x['phone_number'] = $b['phone_number'];
                $btns[] = $x;
            }
            $out[] = ['type' => 'BUTTONS', 'buttons' => $btns];
        }
        if ($errors) throw ValidationException::withMessages($errors);
        return $out;
    }

    /** Build send-time components from parameters. */
    /** Variable names used in a text: ['1','2'] for positional {{n}}, ['first_name'] for Meta NAMED parameters. */
    public static function vars(?string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', (string) $text, $m);
        return array_values(array_unique($m[1]));
    }
    public static function isNamed(?string $text): bool
    {
        foreach (self::vars($text) as $v) if (! ctype_digit($v)) return true;
        return false;
    }

    public static function sendComponents(MessageTemplate $t, array $bodyParams = [], ?string $headerParam = null, array $buttonParams = []): array
    {
        $errors = [];
        $out = [];
        $fmt = $t->header_format;
        $htext = $t->component('HEADER')['text'] ?? '';
        if ($fmt === 'TEXT' && ($t->header_variable_count > 0 || self::isNamed($htext))) {
            if (! $headerParam) $errors['header'] = 'This template needs a header text parameter.';
            $p = ['type' => 'text', 'text' => (string) $headerParam];
            if (self::isNamed($htext)) $p['parameter_name'] = self::vars($htext)[0];
            $out[] = ['type' => 'header', 'parameters' => [$p]];
        } elseif (in_array($fmt, ['IMAGE', 'DOCUMENT', 'VIDEO'])) {
            if (! $headerParam) $errors['header'] = 'This template needs a header media URL (or media id).';
            $k = strtolower($fmt);
            $media = preg_match('#^https?://#', (string) $headerParam) ? ['link' => $headerParam] : ['id' => $headerParam];
            if ($k === 'document' && isset($media['link'])) $media['filename'] = basename(parse_url($headerParam, PHP_URL_PATH) ?: 'document.pdf');
            $out[] = ['type' => 'header', 'parameters' => [['type' => $k, $k => $media]]];
        }
        $btext = $t->component('BODY')['text'] ?? '';
        if (self::isNamed($btext)) {
            $names = self::vars($btext);
            $missing = array_filter($names, fn ($n) => ! isset($bodyParams[$n]) || $bodyParams[$n] === '');
            if ($missing) $errors['params'] = "Template '{$t->name}' needs named parameter(s): ".implode(', ', $missing).'.';
            $out[] = ['type' => 'body', 'parameters' => array_map(fn ($n) => ['type' => 'text', 'parameter_name' => $n, 'text' => (string) ($bodyParams[$n] ?? '')], $names)];
        } else {
            $n = $t->body_variable_count;
            $bodyParams = array_values($bodyParams);
            if (count($bodyParams) < $n) $errors['params'] = "Template '{$t->name}' needs {$n} body parameter(s); got ".count($bodyParams).'.';
            if ($n > 0) $out[] = ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_slice($bodyParams, 0, $n))];
        }
        foreach ($t->component('BUTTONS')['buttons'] ?? [] as $i => $b) {
            $type = strtoupper($b['type'] ?? '');
            $v = $buttonParams[$i] ?? $buttonParams[(string) $i] ?? null;
            if (($type === 'URL' && MessageTemplate::countVars($b['url'] ?? '') > 0) || $type === 'OTP') {
                // OTP (authentication) buttons are sent as a url button carrying the code
                if ($v === null || $v === '') { $errors["button_params.$i"] = ($type === 'OTP' ? 'The OTP button' : 'URL button '.($i + 1)).' needs a parameter.'; continue; }
                $out[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string) $i, 'parameters' => [['type' => 'text', 'text' => (string) $v]]];
            } elseif ($type === 'COPY_CODE') {
                if ($v === null || $v === '') { $errors["button_params.$i"] = 'The copy-code button needs a coupon code.'; continue; }
                $out[] = ['type' => 'button', 'sub_type' => 'copy_code', 'index' => (string) $i, 'parameters' => [['type' => 'coupon_code', 'coupon_code' => (string) $v]]];
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);
        return $out;
    }

    /**
     * Demo values for every parameter a template needs (used by the "Test" button):
     * header text → "Demo", media header → a public sample file, body {{n}} → "Demo n" (named → the name),
     * URL button → "demo", OTP/copy-code buttons → a code. Authentication bodies get a numeric code.
     */
    public static function demoParams(MessageTemplate $t): array
    {
        $media = config('whatsapp.demo_media', []);
        $fmt = $t->header_format;
        $header = null;
        if ($fmt === 'TEXT' && ($t->header_variable_count > 0 || self::isNamed($t->component('HEADER')['text'] ?? ''))) $header = 'Demo';
        elseif (in_array($fmt, ['IMAGE', 'VIDEO', 'DOCUMENT'])) $header = $media[strtolower($fmt)] ?? null;
        $auth = $t->category === 'AUTHENTICATION';
        $btext = $t->component('BODY')['text'] ?? '';
        if (self::isNamed($btext)) {
            $body = [];
            foreach (self::vars($btext) as $n) $body[$n] = $auth ? '123456' : ucfirst(str_replace('_', ' ', $n));
        } else {
            $body = $t->body_variable_count ? array_map(fn ($i) => $auth ? '123456' : "Demo $i", range(1, $t->body_variable_count)) : [];
        }
        $buttons = [];
        foreach ($t->component('BUTTONS')['buttons'] ?? [] as $i => $b) {
            $type = strtoupper($b['type'] ?? '');
            if ($type === 'URL' && MessageTemplate::countVars($b['url'] ?? '') > 0) $buttons[$i] = 'demo';
            elseif ($type === 'OTP') $buttons[$i] = '123456';
            elseif ($type === 'COPY_CODE') $buttons[$i] = 'DEMO10';
        }
        return ['header' => $header, 'body' => $body, 'buttons' => $buttons];
    }

    public static function render(MessageTemplate $t, array $bodyParams, ?string $headerParam = null): string
    {
        $fill = fn ($s, $p) => preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', fn ($m) => ctype_digit($m[1]) ? ($p[(int) $m[1] - 1] ?? $m[0]) : ($p[$m[1]] ?? $m[0]), (string) $s);
        $parts = [];
        if ($t->header_format === 'TEXT') $parts[] = '*'.$fill($t->component('HEADER')['text'] ?? '', [$headerParam]).'*';
        $bt = $t->component('BODY')['text'] ?? '';
        $parts[] = $fill($bt, self::isNamed($bt) ? $bodyParams : array_values($bodyParams));
        if ($f = $t->component('FOOTER')['text'] ?? null) $parts[] = "_{$f}_";
        return implode("\n", $parts);
    }
}
