<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCreditLedger;
use App\Models\Client;
use App\Services\AiUsageService;
use Illuminate\Http\Request;

/** Admin + client AI usage / credit balance. */
class AiUsageController extends Controller
{
    public function __construct(protected AiUsageService $usage) {}

    /** GET /ai-usage — admin table of every client. */
    public function summary()
    {
        AiUsageService::seedDefaults();
        $clients = $this->usage->clientStats();
        return [
            'currency' => 'INR',
            'usd_inr_rate' => AiUsageService::usdInr(),
            'prices' => AiUsageService::prices(),
            'totals' => [
                'used_month' => round(array_sum(array_column($clients, 'used_month')), 4),
                'used_all' => round(array_sum(array_column($clients, 'used_all')), 4),
                'credits' => round(array_sum(array_column($clients, 'credits')), 4),
                'calls' => (int) array_sum(array_column($clients, 'calls')),
                'calls_month' => (int) array_sum(array_column($clients, 'calls_month')),
            ],
            'clients' => $clients,
        ];
    }

    /** GET /ai-usage/clients/{client} — drill-down. */
    public function show(Client $client)
    {
        $stats = $this->usage->clientStats($client->id)[0] ?? null;
        return [
            'client' => $client->only(['id', 'name', 'company', 'is_active', 'ai_block_when_empty']),
            'balance' => $this->usage->balance($client->id),
            'stats' => $stats,
            'by_number' => $this->usage->byNumber($client->id),
            'by_day' => $this->usage->byDay($client->id, 30),
            'recent' => $this->usage->recent($client->id, 50),
            'ledger' => AiCreditLedger::where('client_id', $client->id)->with('creator:id,name')->latest('id')->limit(50)->get(),
            'currency' => 'INR',
        ];
    }

    /** POST /ai-usage/clients/{client}/credits {amount_inr, note?} */
    public function addCredit(Request $r, Client $client)
    {
        $d = $r->validate([
            'amount_inr' => 'required|numeric|not_in:0|min:-1000000|max:1000000',
            'note' => 'nullable|string|max:500',
        ]);
        $row = $this->usage->addCredit($client, (float) $d['amount_inr'], $d['note'] ?? null, $r->user()->id);
        return response()->json([
            'ledger' => $row->load('creator:id,name'),
            'balance' => $this->usage->balance($client->id),
        ], 201);
    }

    /** PUT /ai-usage/clients/{client} {ai_block_when_empty?} */
    public function updateClient(Request $r, Client $client)
    {
        $d = $r->validate(['ai_block_when_empty' => 'sometimes|boolean']);
        if (array_key_exists('ai_block_when_empty', $d)) {
            $client->ai_block_when_empty = (bool) $d['ai_block_when_empty'];
            $client->save();
        }
        return ['client' => $client->only(['id', 'name', 'company', 'ai_block_when_empty']), 'balance' => $this->usage->balance($client->id)];
    }

    /** GET /client/ai-usage — own data only. */
    public function mine(Request $r)
    {
        $c = $r->user();
        $stats = $this->usage->clientStats($c->id)[0] ?? null;
        return [
            'client' => $c->only(['id', 'name', 'company']),
            'balance' => $this->usage->balance($c->id),
            'stats' => $stats,
            'by_number' => $this->usage->byNumber($c->id),
            'by_day' => $this->usage->byDay($c->id, 30),
            'recent' => $this->usage->recent($c->id, 30),
            'currency' => 'INR',
        ];
    }
}
