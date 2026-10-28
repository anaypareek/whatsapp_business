<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** Cached WABA health (refreshed on Settings save, number/template sync, and daily). */
class WabaHealth
{
    public const KEY = 'waba_health';

    public static function get(): ?array { return Cache::get(self::KEY); }

    /** Fetch from Meta and cache. On failure the previous result is kept, with fetch_error set. */
    public static function refresh(): array
    {
        try {
            $r = app(WhatsAppService::class)->getWabaHealth();
            $h = $r['health_status'] ?? [];
            $entities = collect($h['entities'] ?? [])->map(fn ($e) => [
                'entity_type' => $e['entity_type'] ?? null, 'id' => $e['id'] ?? null, 'can_send_message' => $e['can_send_message'] ?? null,
                'errors' => collect($e['errors'] ?? [])->map(fn ($x) => ['error_code' => $x['error_code'] ?? null,
                    'error_description' => $x['error_description'] ?? null, 'possible_solution' => $x['possible_solution'] ?? null])->values()->all(),
            ])->values()->all();
            $data = ['can_send_message' => $h['can_send_message'] ?? null, 'entities' => $entities,
                'account_review_status' => $r['account_review_status'] ?? null, 'business_verification_status' => $r['business_verification_status'] ?? null,
                'checked_at' => now()->toIso8601String(), 'fetch_error' => null];
        } catch (Throwable $e) {
            $data = array_merge(self::get() ?? ['can_send_message' => null, 'entities' => []], ['fetch_error' => $e->getMessage(), 'fetch_failed_at' => now()->toIso8601String()]);
        }
        $data['blocked'] = in_array($data['can_send_message'] ?? null, ['BLOCKED', 'LIMITED'], true);
        Cache::forever(self::KEY, $data);
        return $data;
    }

    /** Best-effort refresh that never throws (used after settings save / syncs). */
    public static function refreshQuietly(): void { try { self::refresh(); } catch (Throwable) {} }
}
