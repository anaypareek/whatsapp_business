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
    public static function sendComponents(MessageTemplate $t, array $bodyParams = [], ?string $headerParam = null, array $buttonParams = []): array
    {
        $errors = [];
        $out = [];
        $fmt = $t->header_format;
        if ($fmt === 'TEXT' && $t->header_variable_count > 0) {
            if (! $headerParam) $errors['header'] = 'This template needs a header text parameter.';
            $out[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => (string) $headerParam]]];
        } elseif (in_array($fmt, ['IMAGE', 'DOCUMENT', 'VIDEO'])) {
            if (! $headerParam) $errors['header'] = 'This template needs a header media URL (or media id).';
            $k = strtolower($fmt);
            $media = preg_match('#^https?://#', (string) $headerParam) ? ['link' => $headerParam] : ['id' => $headerParam];
            $out[] = ['type' => 'header', 'parameters' => [['type' => $k, $k => $media]]];
        }
        $n = $t->body_variable_count;
        $bodyParams = array_values($bodyParams);
        if (count($bodyParams) < $n) $errors['params'] = "Template '{$t->name}' needs {$n} body parameter(s); got ".count($bodyParams).'.';
        if ($n > 0) $out[] = ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_slice($bodyParams, 0, $n))];
        foreach ($t->component('BUTTONS')['buttons'] ?? [] as $i => $b) {
            if (($b['type'] ?? '') === 'URL' && MessageTemplate::countVars($b['url'] ?? '') > 0) {
                $v = $buttonParams[$i] ?? $buttonParams[(string) $i] ?? null;
                if ($v === null || $v === '') { $errors["button_params.$i"] = "URL button ".($i + 1)." needs a parameter."; continue; }
                $out[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string) $i, 'parameters' => [['type' => 'text', 'text' => (string) $v]]];
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);
        return $out;
    }

    public static function render(MessageTemplate $t, array $bodyParams, ?string $headerParam = null): string
    {
        $fill = fn ($s, $p) => preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn ($m) => $p[(int) $m[1] - 1] ?? $m[0], (string) $s);
        $parts = [];
        if ($t->header_format === 'TEXT') $parts[] = '*'.$fill($t->component('HEADER')['text'] ?? '', [$headerParam]).'*';
        $parts[] = $fill($t->component('BODY')['text'] ?? '', array_values($bodyParams));
        if ($f = $t->component('FOOTER')['text'] ?? null) $parts[] = "_{$f}_";
        return implode("\n", $parts);
    }
}
