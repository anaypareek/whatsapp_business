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
            'header.sample' => 'nullable|string|max:200', // stored media sample token (uploaded to Meta at submit time)
            'header.sample_name' => 'nullable|string|max:200',
            'body' => 'nullable|array',
            'body.text' => 'nullable|required_unless:category,AUTHENTICATION|string|max:1024',
            'body.add_security_recommendation' => 'nullable|boolean',
            'body.examples' => 'nullable|array',
            'body.examples.*' => 'nullable|string|max:500',
            'footer' => 'nullable|string|max:60',
            'code_expiration_minutes' => 'nullable|integer|min:1|max:90',
            'message_send_ttl_seconds' => 'nullable|integer|min:30|max:900',
            'buttons' => 'nullable|array|max:10',
            'buttons.*.type' => 'required|in:QUICK_REPLY,URL,PHONE_NUMBER,OTP',
            'buttons.*.text' => 'nullable|required_unless:buttons.*.type,OTP|string|max:25',
            'buttons.*.url' => 'nullable|required_if:buttons.*.type,URL|string|max:2000',
            'buttons.*.phone_number' => 'nullable|required_if:buttons.*.type,PHONE_NUMBER|string|max:20',
            'buttons.*.example' => 'nullable|string|max:2000',
            'buttons.*.otp_type' => 'nullable|string|in:COPY_CODE,ONE_TAP,ZERO_TAP,copy_code,one_tap,zero_tap',
            'buttons.*.autofill_text' => 'nullable|string|max:25',
            'buttons.*.zero_tap_terms_accepted' => 'nullable|boolean',
            'buttons.*.supported_apps' => 'nullable|array|max:5',
            'buttons.*.supported_apps.*.package_name' => 'nullable|string|max:255',
            'buttons.*.supported_apps.*.signature_hash' => 'nullable|string|max:32',
        ];
    }

    /** Top-level fields Meta accepts on create/edit, including optional auth TTL. */
    public static function metaPayload(array $d, array $components): array
    {
        $p = ['name' => $d['name'], 'language' => $d['language'], 'category' => $d['category'], 'components' => $components];
        if (! empty($d['message_send_ttl_seconds'])) $p['message_send_ttl_seconds'] = (int) $d['message_send_ttl_seconds'];
        return $p;
    }

    public static function components(array $d): array
    {
        if (strtoupper($d['category'] ?? '') === 'AUTHENTICATION') {
            return self::authComponents($d);
        }
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
            if (empty($h['example']) && empty($h['sample'])) $errors['header.sample'] = 'Upload a sample '.strtolower($fmt).' for the header ('.TemplateSamples::LIMITS[$fmt][3].').';
            elseif (! empty($h['sample']) && ! TemplateSamples::valid($h['sample'])) $errors['header.sample'] = 'The header sample file is missing or expired. Upload it again.';
            // with a stored sample the handle is filled in by TemplateSamples::resolve() right before submitting to Meta
            $out[] = ['type' => 'HEADER', 'format' => $fmt, 'example' => ['header_handle' => [empty($h['sample']) ? ($h['example'] ?? '') : '']]];
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


    /**
     * Authentication templates use Meta's fixed content builder:
     * no HEADER, BODY has no custom text (only optional add_security_recommendation),
     * optional FOOTER with only code_expiration_minutes, and exactly one OTP button.
     */
    private static function authComponents(array $d): array
    {
        $errors = [];
        $h = $d['header'] ?? null;
        $fmt = strtoupper(is_array($h) ? ($h['format'] ?? 'NONE') : 'NONE');
        if ($h && $fmt !== '' && $fmt !== 'NONE') {
            $errors['header'] = 'AUTHENTICATION templates cannot have a header.';
        }
        // Custom BODY.text / FOOTER.text are stripped, never forwarded (Meta #100/2388042).
        $btns = array_values($d['buttons'] ?? []);
        if ($btns === []) {
            $btns = [['type' => 'OTP', 'otp_type' => 'COPY_CODE']];
        } elseif (count($btns) !== 1) {
            $errors['buttons'] = 'AUTHENTICATION templates need exactly one OTP button (COPY_CODE, ONE_TAP, or ZERO_TAP).';
        } elseif (strtoupper($btns[0]['type'] ?? '') !== 'OTP') {
            $errors['buttons.0.type'] = 'AUTHENTICATION templates only support an OTP button, not quick replies, URLs, or phone numbers.';
        }

        $otp = [];
        if (count($btns) === 1 && strtoupper($btns[0]['type'] ?? '') === 'OTP') {
            $b = $btns[0];
            $otpType = strtoupper($b['otp_type'] ?? 'COPY_CODE');
            if (! in_array($otpType, ['COPY_CODE', 'ONE_TAP', 'ZERO_TAP'], true)) {
                $errors['buttons.0.otp_type'] = 'OTP type must be COPY_CODE, ONE_TAP, or ZERO_TAP.';
            }
            $otp = ['type' => 'OTP', 'otp_type' => $otpType];
            if (! empty($b['text'])) $otp['text'] = $b['text'];
            if (in_array($otpType, ['ONE_TAP', 'ZERO_TAP'], true)) {
                if (! empty($b['autofill_text'])) $otp['autofill_text'] = $b['autofill_text'];
                $apps = [];
                foreach ($b['supported_apps'] ?? [] as $i => $app) {
                    $pkg = trim((string) ($app['package_name'] ?? ''));
                    $hash = trim((string) ($app['signature_hash'] ?? ''));
                    if ($pkg === '' && $hash === '') continue;
                    if ($pkg === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/', $pkg)) {
                        $errors["buttons.0.supported_apps.$i.package_name"] = 'Each app needs an Android package name with at least two segments (e.g. com.example.app).';
                    }
                    if ($hash === '' || ! preg_match('/^[A-Za-z0-9+\/=_-]{8,20}$/', $hash)) {
                        $errors["buttons.0.supported_apps.$i.signature_hash"] = 'Each app needs its 11-character signing key hash.';
                    }
                    if ($pkg && $hash) $apps[] = ['package_name' => $pkg, 'signature_hash' => $hash];
                }
                if (! $apps) $errors['buttons.0.supported_apps'] = ($otpType === 'ONE_TAP' ? 'ONE_TAP' : 'ZERO_TAP').' requires at least one supported app (package_name and signature_hash).';
                else $otp['supported_apps'] = $apps;
            }
            if ($otpType === 'ZERO_TAP' && empty($b['zero_tap_terms_accepted'])) {
                $errors['buttons.0.zero_tap_terms_accepted'] = 'ZERO_TAP requires accepting the zero-tap terms (zero_tap_terms_accepted = true).';
            } elseif ($otpType === 'ZERO_TAP') {
                $otp['zero_tap_terms_accepted'] = true;
            }
        }

        if ($errors) throw ValidationException::withMessages($errors);

        $body = ['type' => 'BODY'];
        // Never include `text` — Meta error #100/2388042 rejects it on AUTHENTICATION bodies.
        if (! empty($d['body']['add_security_recommendation'])) $body['add_security_recommendation'] = true;
        $out = [$body];
        if (! empty($d['code_expiration_minutes'])) {
            $out[] = ['type' => 'FOOTER', 'code_expiration_minutes' => (int) $d['code_expiration_minutes']];
        }
        $out[] = ['type' => 'BUTTONS', 'buttons' => [$otp]];
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
        if (strtoupper((string) $t->category) === 'AUTHENTICATION') {
            $vals = array_values($bodyParams);
            $code = $bodyParams['code'] ?? ($vals[0] ?? null);
            if ($code === null || $code === '') throw ValidationException::withMessages(['params' => "Template '{$t->name}' needs a verification code."]);
            $code = (string) $code;
            $out[] = ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]];
            if ($t->component('BUTTONS')['buttons'] ?? []) {
                $v = $buttonParams[0] ?? $buttonParams['0'] ?? $code;
                if ($v === null || $v === '') $v = $code;
                $out[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string) $v]]];
            }
            return $out;
        }
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
        $auth = strtoupper((string) $t->category) === 'AUTHENTICATION';
        $btext = $t->component('BODY')['text'] ?? '';
        if (self::isNamed($btext)) {
            $body = [];
            foreach (self::vars($btext) as $n) $body[$n] = $auth ? '123456' : ucfirst(str_replace('_', ' ', $n));
        } else {
            $n = $auth ? max(1, $t->body_variable_count) : $t->body_variable_count;
            $body = $n ? array_map(fn ($i) => $auth ? '123456' : "Demo $i", range(1, $n)) : [];
        }
        $buttons = [];
        foreach ($t->component('BUTTONS')['buttons'] ?? [] as $i => $b) {
            $type = strtoupper($b['type'] ?? '');
            if ($type === 'URL' && MessageTemplate::countVars($b['url'] ?? '') > 0) $buttons[$i] = $auth ? '123456' : 'demo';
            elseif ($type === 'OTP' || ($auth && $type === 'URL')) $buttons[$i] = '123456';
            elseif ($type === 'COPY_CODE') $buttons[$i] = $auth ? '123456' : 'DEMO10';
        }
        return ['header' => $header, 'body' => $body, 'buttons' => $buttons];
    }

    public static function render(MessageTemplate $t, array $bodyParams, ?string $headerParam = null): string
    {
        $fill = fn ($s, $p) => preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', fn ($m) => ctype_digit($m[1]) ? ($p[(int) $m[1] - 1] ?? $m[0]) : ($p[$m[1]] ?? $m[0]), (string) $s);
        if (strtoupper((string) $t->category) === 'AUTHENTICATION') {
            $bt = $t->component('BODY')['text'] ?? '';
            $codeParams = self::isNamed($bt) ? $bodyParams : array_values($bodyParams);
            if ($bt !== '') {
                $parts = [$fill($bt, $codeParams)];
            } else {
                $code = $codeParams[0] ?? '{{1}}';
                $txt = $code.' is your verification code.';
                if (! empty($t->component('BODY')['add_security_recommendation'])) $txt .= ' For your security, do not share this code.';
                $parts = [$txt];
            }
            $mins = $t->component('FOOTER')['code_expiration_minutes'] ?? null;
            $ft = $t->component('FOOTER')['text'] ?? null;
            if ($mins) $parts[] = '_This code expires in '.$mins.' minutes._';
            elseif ($ft) $parts[] = "_{$ft}_";
            return implode("\n", $parts);
        }
        $parts = [];
        if ($t->header_format === 'TEXT') $parts[] = '*'.$fill($t->component('HEADER')['text'] ?? '', [$headerParam]).'*';
        $bt = $t->component('BODY')['text'] ?? '';
        $parts[] = $fill($bt, self::isNamed($bt) ? $bodyParams : array_values($bodyParams));
        if ($f = $t->component('FOOTER')['text'] ?? null) $parts[] = "_{$f}_";
        return implode("\n", $parts);
    }
}
