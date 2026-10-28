<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPayment;
use App\Services\BillingService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(protected BillingService $billing) {}

    protected function dates(Request $r): array
    {
        $r->validate(['from' => 'nullable|string|max:10', 'to' => 'nullable|date']);
        if ($r->filled('from') && $r->from !== 'all') $r->validate(['from' => 'date']);
        return BillingService::period($r->query('from'), $r->query('to'));
    }

    // ---- admin ----
    /** GET /billing/summary → {total_due, clients:[{id,name,company,charges,paid,due}]} for the badge + dues page. */
    public function summary()
    {
        $bal = $this->billing->balances();
        $rows = Client::orderBy('name')->get(['id', 'name', 'company', 'is_active'])->map(fn ($c) => $c->only(['id', 'name', 'company', 'is_active'])
            + ($bal[$c->id] ?? ['charges' => 0.0, 'paid' => 0.0, 'due' => 0.0]));
        return ['currency' => 'INR', 'total_due' => round($rows->sum(fn ($x) => max(0, $x['due'])), 2), 'total_charges' => round($rows->sum('charges'), 2),
            'total_paid' => round($rows->sum('paid'), 2), 'clients' => $rows->values(), 'default_rates' => $this->billing->defaultRates()];
    }

    /** GET /billing/clients/{client}?from&to */
    public function show(Request $r, Client $client)
    {
        [$f, $t] = $this->dates($r);
        $own = \App\Models\ClientRate::where('client_id', $client->id)->pluck('rate', 'category');
        return ['client' => $client->only(['id', 'name', 'company'])] + $this->billing->statement($client->id, $f, $t)
            + ['custom_rates' => $own, 'all_payments' => ClientPayment::where('client_id', $client->id)->with('creator:id,name')->orderByDesc('paid_at')->orderByDesc('id')->get()];
    }

    /** PUT /billing/clients/{client}/rates {marketing,utility,authentication,service} */
    public function updateRates(Request $r, Client $client)
    {
        $this->billing->saveRates($client->id, $this->validRates($r));
        return ['rates' => $this->billing->rates($client->id)];
    }

    /** PUT /billing/default-rates */
    public function updateDefaultRates(Request $r)
    {
        $this->billing->saveRates(null, $this->validRates($r));
        return ['rates' => $this->billing->defaultRates()];
    }

    protected function validRates(Request $r): array
    {
        return $r->validate(collect(BillingService::CATEGORIES)->mapWithKeys(fn ($c) => [$c => 'nullable|numeric|min:0|max:1000'])->all());
    }

    /** POST /billing/clients/{client}/payments {amount, paid_at, method?, reference?, note?} */
    public function storePayment(Request $r, Client $client)
    {
        $d = $r->validate(['amount' => 'required|numeric|min:0.01|max:100000000', 'paid_at' => 'nullable|date', 'method' => 'nullable|string|max:40',
            'reference' => 'nullable|string|max:120', 'note' => 'nullable|string|max:500']);
        $p = ClientPayment::create($d + ['client_id' => $client->id, 'paid_at' => $d['paid_at'] ?? now()->toDateString(), 'created_by' => $r->user()->id]);
        BillingService::bust();
        return response()->json(['payment' => $p, 'balance' => $this->billing->balance($client->id)], 201);
    }

    // ---- client ----
    /** GET /client/billing?from&to */
    public function mine(Request $r)
    {
        [$f, $t] = $this->dates($r);
        $s = $this->billing->statement($r->user()->id, $f, $t);
        $s['payments'] = $s['payments']->map->only(['id', 'amount', 'paid_at', 'method', 'reference', 'note']);
        return $s;
    }

    /** GET /client/billing/due → {due, charges, paid} */
    public function myDue(Request $r) { return ['currency' => 'INR'] + $this->billing->balance($r->user()->id); }
}
