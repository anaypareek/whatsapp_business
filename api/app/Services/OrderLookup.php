<?php
namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only order status from the business's own API (docs/oswal-order-status-api.md).
 * ALWAYS scoped to the sender's WhatsApp phone; an order_id is sent together with that phone and the response is re-filtered.
 */
class OrderLookup
{
    public const TIMEOUT = 8;

    public static function configured(): bool { return (bool) (Setting::get('orders_api_base_url') && Setting::get('orders_api_key')); }

    /** @return array|null list of orders for this phone (optionally one order_id); null on error / not configured. */
    public function lookup(string $senderWaId, ?string $orderId = null): ?array
    {
        if (! self::configured()) return null;
        $phone = preg_replace('/\D/', '', $senderWaId);
        if ($phone === '') return null;
        $orderId = $orderId !== null ? mb_substr(trim($orderId), 0, 64) : null;
        $q = ['phone' => $phone] + ($orderId ? ['order_id' => $orderId] : []);
        try {
            $res = Http::withHeaders(['X-Api-Key' => Setting::get('orders_api_key')])->acceptJson()->timeout(self::TIMEOUT)->connectTimeout(4)
                ->get(rtrim(Setting::get('orders_api_base_url'), '/').'/orders/status', $q);
            if ($res->status() === 404) return [];
            if (! $res->successful()) { Log::warning('Order lookup: HTTP '.$res->status()); return null; }
            $orders = $res->json('orders');
            if (! is_array($orders)) return null;
        } catch (Throwable $e) {
            Log::warning('Order lookup failed ('.class_basename($e).')');
            return null;
        }
        return collect($orders)->filter(fn ($o) => is_array($o))
            // defence in depth: if the API echoes a phone, it must be the sender's; an order_id request returns only that order
            ->filter(fn ($o) => ! isset($o['phone']) || preg_replace('/\D/', '', (string) $o['phone']) === $phone)
            ->filter(fn ($o) => ! $orderId || strcasecmp((string) ($o['order_id'] ?? ''), $orderId) === 0)
            ->take(3)->values()->all();
    }

    public static function format(array $orders): string
    {
        $lines = array_map(function ($o) {
            $items = $o['items'] ?? null;
            if (is_array($items)) $items = implode(', ', array_map(fn ($i) => is_array($i) ? trim(($i['qty'] ?? $i['quantity'] ?? '').' '.($i['name'] ?? '')) : (string) $i, array_slice($items, 0, 5)));
            $parts = ["📦 Order #".($o['order_id'] ?? '?').': *'.($o['status'] ?? 'unknown').'*'];
            if ($items) $parts[] = 'Items: '.mb_substr((string) $items, 0, 200);
            if (isset($o['total'])) $parts[] = 'Total: ₹'.$o['total'];
            if (! empty($o['placed_at'])) $parts[] = 'Placed: '.$o['placed_at'];
            if (! empty($o['expected_delivery'])) $parts[] = 'Expected delivery: '.$o['expected_delivery'];
            return implode("\n", $parts);
        }, $orders);
        return implode("\n\n", $lines)."\n\nAnything else I can help with? 😊";
    }
}
