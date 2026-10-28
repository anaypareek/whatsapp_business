<?php
namespace App\Services;

use RuntimeException;

/** A failed Graph API call, carrying Meta's error fields so the UI can show the real reason. */
class MetaApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus, public readonly string $method, public readonly string $endpoint, public readonly array $error = [], ?string $body = null,
    ) {
        $msg = $error['error_user_msg'] ?? $error['message'] ?? ($body ? mb_substr($body, 0, 300) : 'Unknown error');
        $title = $error['error_user_title'] ?? null;
        $code = isset($error['code']) ? ' (#'.$error['code'].(isset($error['error_subcode']) ? '/'.$error['error_subcode'] : '').')' : '';
        if (isset($error['code'])) $msg = preg_replace('/^\(#'.preg_quote((string) $error['code'], '/').'\)\s*/', '', $msg); // code is already in the prefix
        $hint = self::SUBCODE_HINTS[(int) ($error['error_subcode'] ?? 0)] ?? self::HINTS[(int) ($error['code'] ?? 0)] ?? null;
        parent::__construct('Meta API error'.$code.': '.($title && ! str_contains($msg, $title) ? "$title – " : '').$msg.($hint ? (preg_match('/[.!?]$/', trim($msg)) ? ' ' : '. ').$hint : ''), $httpStatus);
    }

    /** What to do next, appended to the message the UI shows (Cloud API phone-number registration errors). */
    public const HINTS = [
        133010 => 'This number is not registered with the Cloud API yet. Go to Number Master and click Register.',
        133005 => 'The PIN does not match this number\'s two-step verification PIN. Enter the existing PIN, or reset it in WhatsApp Manager → Phone numbers → Two-step verification.',
        133006 => 'The phone number must be verified first (WhatsApp Manager → Phone numbers → verify by SMS/voice code), then click Register again.',
        133008 => 'Too many PIN attempts. Wait for the time Meta mentions, then try again.',
        133009 => 'PIN entered too quickly. Wait a moment, then try again.',
        133015 => 'This number was recently deleted. Wait about 5 minutes, then try again.',
        133016 => 'Too many register/deregister attempts for this number. Wait (up to 72 hours) before trying again.',
    ];

    /** Hints keyed by error_subcode (checked first). 2388339 = template create rejected because the WABA is blocked. */
    public const SUBCODE_HINTS = [
        2388339 => 'Your WhatsApp account is blocked by Meta, often a payment-method issue (141006). Check Business Settings → WhatsApp accounts → Payment settings.',
    ];

    public function hint(): ?string { return self::SUBCODE_HINTS[$this->metaSubcode() ?? 0] ?? self::HINTS[$this->metaCode() ?? 0] ?? null; }

    public function metaCode(): ?int { return isset($this->error['code']) ? (int) $this->error['code'] : null; }
    public function metaSubcode(): ?int { return isset($this->error['error_subcode']) ? (int) $this->error['error_subcode'] : null; }
    public function errorCode(): string { return 'META_'.($this->metaCode() ?? $this->httpStatus); }

    /** Meta says the object doesn't exist (already deleted on Meta, or never created there). */
    public function isNotFound(): bool
    {
        $m = strtolower(($this->error['message'] ?? '').' '.($this->error['error_user_msg'] ?? ''));
        return $this->httpStatus === 404 || $this->metaSubcode() === 33 || str_contains($m, 'does not exist') || str_contains($m, 'not found')
            || str_contains($m, "doesn't exist") || str_contains($m, 'no template');
    }

    public function details(): array
    {
        return ['endpoint' => $this->method.' '.$this->endpoint, 'http_status' => $this->httpStatus, 'meta_code' => $this->metaCode(),
            'meta_subcode' => $this->metaSubcode(), 'meta_type' => $this->error['type'] ?? null, 'meta_message' => $this->error['message'] ?? null,
            'error_user_title' => $this->error['error_user_title'] ?? null, 'error_user_msg' => $this->error['error_user_msg'] ?? null,
            'fbtrace_id' => $this->error['fbtrace_id'] ?? null, 'error_details' => $this->error['error_data']['details'] ?? null, 'hint' => $this->hint()];
    }
}
