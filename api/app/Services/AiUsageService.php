<?php
namespace App\Services;

use App\Models\AiCreditLedger;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\PhoneNumber;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI token metering + per-client INR credit balance.
 * Cost = (prompt/1e6 * input_usd + completion/1e6 * output_usd) * USD→INR rate.
 * Balance = sum(ledger) − sum(usage cost_inr). When balance ≤ 0 and client.ai_block_when_empty, auto-replies are skipped.
 */
class AiUsageService
{
    public const FEATURE_AUTO = 'auto_reply';
    public const FEATURE_TRAIN = 'train_ai';
    public const FEATURE_OTHER = 'other';
    public const DEFAULT_USD_INR = 88.0;
    /** USD per 1M tokens. Seeded into settings when missing. */
    public const DEFAULT_PRICES = [
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
    ];

    public static function usdInr(): float
    {
        $v = Setting::get('ai_usd_inr_rate');
        $n = is_numeric($v) ? (float) $v : self::DEFAULT_USD_INR;
        return $n > 0 ? $n : self::DEFAULT_USD_INR;
    }

    /** [model => ['input'=>USD/1M, 'output'=>USD/1M]] */
    public static function prices(): array
    {
        $raw = Setting::get('ai_model_prices');
        $parsed = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = self::DEFAULT_PRICES;
        if (is_array($parsed)) {
            foreach ($parsed as $model => $p) {
                if (! is_array($p)) continue;
                $out[$model] = [
                    'input' => (float) ($p['input'] ?? $out[$model]['input'] ?? 0.15),
                    'output' => (float) ($p['output'] ?? $out[$model]['output'] ?? 0.60),
                ];
            }
        }
        return $out;
    }

    /** Ensure admin settings have the seed defaults (idempotent). */
    public static function seedDefaults(): void
    {
        if (Setting::get('ai_usd_inr_rate') === null) Setting::put('ai_usd_inr_rate', (string) self::DEFAULT_USD_INR);
        if (Setting::get('ai_model_prices') === null) Setting::put('ai_model_prices', json_encode(self::DEFAULT_PRICES));
    }

    /** Compute {cost_usd, cost_inr} for a call. Unknown models fall back to gpt-4o-mini rates. */
    public static function cost(string $model, int $prompt, int $completion): array
    {
        $prices = self::prices();
        $p = $prices[$model] ?? $prices[AiReplyService::DEFAULT_MODEL] ?? ['input' => 0.15, 'output' => 0.60];
        $usd = ($prompt / 1_000_000) * (float) $p['input'] + ($completion / 1_000_000) * (float) $p['output'];
        $inr = $usd * self::usdInr();
        return ['cost_usd' => round($usd, 8), 'cost_inr' => round($inr, 6)];
    }

    /**
     * Persist one call. $usage = OpenAI usage object (prompt_tokens, completion_tokens, total_tokens).
     * Returns the log row (even when tokens are 0).
     */
    public function record(?PhoneNumber $phone, string $feature, string $model, array $usage = []): AiUsageLog
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));
        $c = self::cost($model, $prompt, $completion);
        $feat = in_array($feature, [self::FEATURE_AUTO, self::FEATURE_TRAIN, self::FEATURE_OTHER], true) ? $feature : self::FEATURE_OTHER;
        return AiUsageLog::create([
            'client_id' => $phone?->client_id,
            'phone_number_id' => $phone?->id,
            'feature' => $feat,
            'model' => mb_substr($model, 0, 64),
            'prompt_tokens' => max(0, $prompt),
            'completion_tokens' => max(0, $completion),
            'total_tokens' => max(0, $total),
            'cost_usd' => $c['cost_usd'],
            'cost_inr' => $c['cost_inr'],
            'created_at' => now(),
        ]);
    }

    public function credits(int $clientId): float
    {
        return round((float) AiCreditLedger::where('client_id', $clientId)->sum('amount_inr'), 4);
    }

    public function used(int $clientId, ?Carbon $from = null, ?Carbon $to = null): float
    {
        $q = AiUsageLog::where('client_id', $clientId)
            ->when($from, fn ($x) => $x->where('created_at', '>=', $from))
            ->when($to, fn ($x) => $x->where('created_at', '<=', $to));
        return round((float) $q->sum('cost_inr'), 4);
    }

    /** {credits, used, remaining, block_when_empty, blocked} — remaining = credits − all-time used. */
    public function balance(int $clientId): array
    {
        $credits = $this->credits($clientId);
        $used = $this->used($clientId);
        $remaining = round($credits - $used, 4);
        $block = (bool) (Client::where('id', $clientId)->value('ai_block_when_empty') ?? true);
        return [
            'credits' => $credits,
            'used' => $used,
            'remaining' => $remaining,
            'currency' => 'INR',
            'block_when_empty' => $block,
            'blocked' => $block && $remaining <= 0,
        ];
    }

    /**
     * Null if the AI may run; otherwise a skip reason ('no_ai_credits').
     * Only applies when the number belongs to a client with block_when_empty and remaining ≤ 0.
     * Numbers without a client are never blocked by credits.
     */
    public function skipReason(?PhoneNumber $phone): ?string
    {
        if (! $phone?->client_id) return null;
        $b = $this->balance($phone->client_id);
        if ($b['blocked']) {
            Log::info('AI blocked: no credits', ['client_id' => $phone->client_id, 'phone_number_id' => $phone->id, 'remaining' => $b['remaining']]);
            return 'no_ai_credits';
        }
        return null;
    }

    public function addCredit(Client $client, float $amountInr, ?string $note, ?int $adminId): AiCreditLedger
    {
        return AiCreditLedger::create([
            'client_id' => $client->id,
            'amount_inr' => round($amountInr, 4),
            'note' => $note ? mb_substr($note, 0, 500) : null,
            'created_by' => $adminId,
            'created_at' => now(),
        ]);
    }

    /** Aggregate for admin table / client summary. */
    public function clientStats(?int $clientId = null, ?Carbon $monthFrom = null): array
    {
        $monthFrom ??= now()->copy()->startOfMonth();
        $usage = AiUsageLog::query()
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->whereNotNull('client_id')
            ->selectRaw('client_id,
                COUNT(*) calls,
                COALESCE(SUM(prompt_tokens),0) prompt_tokens,
                COALESCE(SUM(completion_tokens),0) completion_tokens,
                COALESCE(SUM(total_tokens),0) total_tokens,
                COALESCE(SUM(cost_inr),0) used_all,
                COALESCE(SUM(CASE WHEN created_at >= ? THEN cost_inr ELSE 0 END),0) used_month,
                COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END),0) calls_month', [$monthFrom, $monthFrom])
            ->groupBy('client_id')->get()->keyBy('client_id');

        $credits = AiCreditLedger::query()
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->selectRaw('client_id, COALESCE(SUM(amount_inr),0) s')->groupBy('client_id')->pluck('s', 'client_id');

        $clients = Client::query()->when($clientId, fn ($q) => $q->where('id', $clientId))
            ->orderBy('name')->get(['id', 'name', 'company', 'is_active', 'ai_block_when_empty']);

        return $clients->map(function (Client $c) use ($usage, $credits) {
            $u = $usage[$c->id] ?? null;
            $cred = round((float) ($credits[$c->id] ?? 0), 4);
            $usedAll = round((float) ($u->used_all ?? 0), 4);
            $remaining = round($cred - $usedAll, 4);
            $block = (bool) $c->ai_block_when_empty;
            return [
                'id' => $c->id, 'name' => $c->name, 'company' => $c->company, 'is_active' => $c->is_active,
                'calls' => (int) ($u->calls ?? 0), 'calls_month' => (int) ($u->calls_month ?? 0),
                'prompt_tokens' => (int) ($u->prompt_tokens ?? 0), 'completion_tokens' => (int) ($u->completion_tokens ?? 0),
                'total_tokens' => (int) ($u->total_tokens ?? 0),
                'used_month' => round((float) ($u->used_month ?? 0), 4), 'used_all' => $usedAll,
                'credits' => $cred, 'remaining' => $remaining,
                'block_when_empty' => $block, 'blocked' => $block && $remaining <= 0,
            ];
        })->values()->all();
    }

    /** Breakdown by phone number for one client. */
    public function byNumber(int $clientId): array
    {
        $rows = AiUsageLog::where('client_id', $clientId)
            ->selectRaw('phone_number_id, COUNT(*) calls, COALESCE(SUM(prompt_tokens),0) prompt_tokens, COALESCE(SUM(completion_tokens),0) completion_tokens, COALESCE(SUM(total_tokens),0) total_tokens, COALESCE(SUM(cost_inr),0) cost_inr')
            ->groupBy('phone_number_id')->get()->keyBy('phone_number_id');
        $phones = PhoneNumber::where('client_id', $clientId)->get(['id', 'display_number', 'name']);
        return $phones->map(function ($p) use ($rows) {
            $r = $rows[$p->id] ?? null;
            return [
                'phone_number_id' => $p->id, 'display_number' => $p->display_number, 'name' => $p->name,
                'calls' => (int) ($r->calls ?? 0), 'prompt_tokens' => (int) ($r->prompt_tokens ?? 0),
                'completion_tokens' => (int) ($r->completion_tokens ?? 0), 'total_tokens' => (int) ($r->total_tokens ?? 0),
                'cost_inr' => round((float) ($r->cost_inr ?? 0), 4),
            ];
        })->values()->all();
    }

    /** Daily cost/calls for one client (last N days, default 30). */
    public function byDay(int $clientId, int $days = 30): array
    {
        $from = now()->copy()->subDays(max(1, $days) - 1)->startOfDay();
        $rows = AiUsageLog::where('client_id', $clientId)->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) d, COUNT(*) calls, COALESCE(SUM(total_tokens),0) total_tokens, COALESCE(SUM(cost_inr),0) cost_inr')
            ->groupBy(DB::raw('DATE(created_at)'))->orderBy('d')->get();
        return $rows->map(fn ($r) => [
            'date' => (string) $r->d, 'calls' => (int) $r->calls,
            'total_tokens' => (int) $r->total_tokens, 'cost_inr' => round((float) $r->cost_inr, 4),
        ])->all();
    }

    public function recent(int $clientId, int $limit = 50)
    {
        return AiUsageLog::where('client_id', $clientId)->with('phoneNumber:id,display_number,name')
            ->latest('id')->limit($limit)->get();
    }
}
