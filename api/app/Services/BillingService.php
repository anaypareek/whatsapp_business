<?php
namespace App\Services;

use App\Models\ClientPayment;
use App\Models\ClientRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Usage-based billing. Charges = outbound messages (sent/delivered/read; failed & pending are free) counted by category
 * × the client's INR rate for that category. Category = Meta's pricing.category from status webhooks when known, else the
 * template's category for template sends, else "service" (free-form replies). Due = all-time charges − all-time payments.
 */
class BillingService
{
    public const CATEGORIES = ['marketing', 'utility', 'authentication', 'service'];
    /** Fallback rate card (INR / message, Meta India list prices) used until the admin saves a default or per-client card. */
    public const FALLBACK = ['marketing' => 0.7846, 'utility' => 0.1150, 'authentication' => 0.1150, 'service' => 0.0];

    public static function normalize(?string $c): string
    {
        $c = strtolower(trim((string) $c));
        return match (true) {
            str_starts_with($c, 'marketing') => 'marketing',
            str_starts_with($c, 'authentication') => 'authentication',
            $c === 'utility' => 'utility',
            default => 'service',
        };
    }

    public function defaultRates(): array
    {
        $rows = ClientRate::whereNull('client_id')->pluck('rate', 'category')->all();
        return array_map('floatval', array_merge(self::FALLBACK, array_intersect_key($rows, self::FALLBACK)));
    }

    /** Effective rates for a client: own card over the default card over FALLBACK. */
    public function rates(int $clientId, ?array $defaults = null): array
    {
        $own = ClientRate::where('client_id', $clientId)->pluck('rate', 'category')->all();
        return array_map('floatval', array_merge($defaults ?? $this->defaultRates(), array_intersect_key($own, self::FALLBACK)));
    }

    public function saveRates(?int $clientId, array $rates): void
    {
        foreach (self::CATEGORIES as $c) {
            if (! array_key_exists($c, $rates) || $rates[$c] === null || $rates[$c] === '') continue;
            ClientRate::updateOrCreate(['client_id' => $clientId, 'category' => $c], ['rate' => round((float) $rates[$c], 4)]);
        }
        self::bust();
    }

    /** [client_id => [category => count]] for outbound billable messages in [from, to] (inclusive dates; null = unbounded). */
    public function counts(?int $clientId = null, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $q = DB::table('messages as m')->join('phone_numbers as p', 'p.id', '=', 'm.phone_number_id')
            ->leftJoin('message_templates as t', 't.id', '=', 'm.message_template_id')
            ->whereNotNull('p.client_id')->where('m.direction', 'out')->whereNotIn('m.status', ['failed', 'pending'])
            ->when($clientId, fn ($x) => $x->where('p.client_id', $clientId))
            ->when($from, fn ($x) => $x->where('m.created_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($x) => $x->where('m.created_at', '<=', $to->copy()->endOfDay()))
            ->selectRaw("p.client_id, m.pricing_category pc, CASE WHEN m.message_template_id IS NOT NULL OR m.template_name IS NOT NULL THEN COALESCE(t.category, 'MARKETING') ELSE 'SERVICE' END tc, COUNT(*) n")
            ->groupBy('p.client_id', 'pc', 'tc');
        $out = [];
        foreach ($q->get() as $r) {
            $cat = self::normalize($r->pc ?: $r->tc);
            $out[$r->client_id] ??= array_fill_keys(self::CATEGORIES, 0);
            $out[$r->client_id][$cat] += (int) $r->n;
        }
        return $out;
    }

    /** Usage statement for one client over a period: lines per category, totals, payments in period, all-time due. */
    public function statement(int $clientId, ?Carbon $from, ?Carbon $to): array
    {
        $rates = $this->rates($clientId);
        $counts = $this->counts($clientId, $from, $to)[$clientId] ?? array_fill_keys(self::CATEGORIES, 0);
        $lines = []; $total = 0.0;
        foreach (self::CATEGORIES as $c) {
            $amt = round($counts[$c] * $rates[$c], 2);
            $lines[] = ['category' => $c, 'count' => $counts[$c], 'rate' => $rates[$c], 'amount' => $amt];
            $total += $amt;
        }
        $payments = ClientPayment::where('client_id', $clientId)
            ->when($from, fn ($x) => $x->where('paid_at', '>=', $from->toDateString()))->when($to, fn ($x) => $x->where('paid_at', '<=', $to->toDateString()))
            ->orderByDesc('paid_at')->orderByDesc('id')->get();
        return ['from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'currency' => 'INR', 'rates' => $rates, 'lines' => $lines,
            'total' => round($total, 2), 'messages' => array_sum($counts), 'payments' => $payments, 'payments_total' => round((float) $payments->sum('amount'), 2),
            'balance' => $this->balance($clientId)];
    }

    /** All-time {charges, paid, due} for one client. */
    public function balance(int $clientId): array
    {
        return $this->balances()[$clientId] ?? ['charges' => 0.0, 'paid' => 0.0, 'due' => 0.0];
    }

    /** All-time balances per client (cached 60 s; busted on payments/rates). [client_id => {charges, paid, due}] */
    public function balances(): array
    {
        return Cache::remember('billing:balances', 60, function () {
            $defaults = $this->defaultRates();
            $paid = ClientPayment::groupBy('client_id')->selectRaw('client_id, SUM(amount) s')->pluck('s', 'client_id');
            $out = [];
            foreach ($this->counts() as $cid => $counts) {
                $rates = $this->rates($cid, $defaults);
                $out[$cid] = ['charges' => round(array_sum(array_map(fn ($c) => $counts[$c] * $rates[$c], self::CATEGORIES)), 2)];
            }
            foreach ($paid as $cid => $s) $out[$cid] ??= ['charges' => 0.0];
            foreach ($out as $cid => &$b) { $b['paid'] = round((float) ($paid[$cid] ?? 0), 2); $b['due'] = round($b['charges'] - $b['paid'], 2); }
            return $out;
        });
    }

    public static function bust(): void { Cache::forget('billing:balances'); }

    /** Parse ?from&to; default = this month. "all" = unbounded. */
    public static function period(?string $from, ?string $to): array
    {
        if ($from === 'all') return [null, null];
        $f = $from ? Carbon::parse($from) : now()->startOfMonth();
        $t = $to ? Carbon::parse($to) : now();
        return [$f->startOfDay(), $t->endOfDay()];
    }
}
