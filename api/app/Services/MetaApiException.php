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
        parent::__construct('Meta API error'.$code.': '.($title && ! str_contains($msg, $title) ? "$title – " : '').$msg, $httpStatus);
    }

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
            'fbtrace_id' => $this->error['fbtrace_id'] ?? null];
    }
}
