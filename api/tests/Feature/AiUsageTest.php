<?php
namespace Tests\Feature;

use App\Models\AiCreditLedger;
use App\Models\AiReplySetting;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AI cost metering, credit balance, block-when-empty, and client isolation. */
class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $ca;
    protected Client $cb;
    protected PhoneNumber $a;
    protected PhoneNumber $b;
    protected string $answer = 'Hello from AI';
    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999', 'ai_api_key' => 'sk-test-SECRET1234', 'ai_base_url' => 'https://llm.test/v1'] as $k => $v) Setting::put($k, $v);
        Setting::put('ai_usd_inr_rate', '88');
        Setting::put('ai_model_prices', json_encode(AiUsageService::DEFAULT_PRICES));
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->ca = Client::create(['name' => 'Oswal', 'email' => 'o@c.t', 'password' => 'password123', 'ai_block_when_empty' => true]);
        $this->cb = Client::create(['name' => 'Beta', 'email' => 'b@c.t', 'password' => 'password123', 'ai_block_when_empty' => true]);
        $this->a = PhoneNumber::create(['client_id' => $this->ca->id, 'phone_number_id' => '7001', 'display_number' => '+91 90909 00894', 'name' => 'Oswal']);
        $this->b = PhoneNumber::create(['client_id' => $this->cb->id, 'phone_number_id' => '7002', 'display_number' => '+91 90000 70002', 'name' => 'Beta']);
        foreach ([$this->a, $this->b] as $n) AiReplySetting::create(['phone_number_id' => $n->id, 'enabled' => true, 'allowlist' => ['919811000001'], 'max_per_hour' => 50]);
        Http::fake([
            'llm.test/*' => fn () => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $this->answer]]],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'total_tokens' => 1500],
            ]),
            'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.O'.bin2hex(random_bytes(5))]]]),
        ]);
    }

    protected function as($u) { $this->app['auth']->forgetGuards(); return $this->withToken($u->createToken('t')->plainTextToken); }

    protected function credit(Client $c, float $amt, ?string $note = 'top-up'): void
    {
        app(AiUsageService::class)->addCredit($c, $amt, $note, $this->admin->id);
    }

    protected function hook(string $pnid, string $body = 'hi', string $from = '919811000001')
    {
        $this->seq++;
        $p = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '91', 'phone_number_id' => $pnid], 'contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => 'wamid.IN'.$this->seq, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $body]]]]]]]]];
        $raw = json_encode($p);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw)->assertOk();
    }

    public function test_cost_calc_uses_price_table_and_usd_inr_rate(): void
    {
        // gpt-4o-mini: $0.15/1M in, $0.60/1M out; 1000+500 tokens @ 88 INR
        $c = AiUsageService::cost('gpt-4o-mini', 1000, 500);
        $usd = (1000 / 1e6) * 0.15 + (500 / 1e6) * 0.60; // 0.00015 + 0.0003 = 0.00045
        $this->assertEqualsWithDelta(0.00045, $c['cost_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.00045 * 88, $c['cost_inr'], 1e-9);
        Setting::put('ai_usd_inr_rate', '100');
        Setting::put('ai_model_prices', json_encode(['gpt-4o-mini' => ['input' => 1.0, 'output' => 2.0]]));
        $c2 = AiUsageService::cost('gpt-4o-mini', 1_000_000, 1_000_000);
        $this->assertEqualsWithDelta(3.0, $c2['cost_usd'], 1e-9); // 1+2
        $this->assertEqualsWithDelta(300.0, $c2['cost_inr'], 1e-6);
        // unknown model falls back to gpt-4o-mini defaults after re-seed... prices() merges DEFAULT
        Setting::put('ai_model_prices', json_encode([]));
        $c3 = AiUsageService::cost('totally-unknown-model', 1_000_000, 0);
        $this->assertEqualsWithDelta(0.15, $c3['cost_usd'], 1e-9);
    }

    public function test_auto_reply_logs_usage_and_reduces_balance(): void
    {
        $this->credit($this->ca, 10.0);
        $this->hook('7001', 'hello');
        $this->assertDatabaseHas('ai_usage_logs', [
            'client_id' => $this->ca->id,
            'phone_number_id' => $this->a->id,
            'feature' => 'auto_reply',
            'model' => 'gpt-4o-mini',
            'prompt_tokens' => 1000,
            'completion_tokens' => 500,
        ]);
        $log = AiUsageLog::first();
        $this->assertEqualsWithDelta(0.00045 * 88, $log->cost_inr, 1e-6);
        $b = app(AiUsageService::class)->balance($this->ca->id);
        $this->assertEqualsWithDelta(10.0, $b['credits'], 1e-6);
        $this->assertEqualsWithDelta($log->cost_inr, $b['used'], 1e-6);
        $this->assertEqualsWithDelta(10.0 - $log->cost_inr, $b['remaining'], 1e-6);
        $this->assertFalse($b['blocked']);
    }

    public function test_zero_balance_blocks_auto_reply_and_logs_reason(): void
    {
        // default: block_when_empty ON, credits 0 → blocked
        $this->hook('7001', 'hello')->assertJsonPath('ai', 0);
        $this->assertSame(0, AiUsageLog::count());
        $this->assertCount(0, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'llm.test')));
        // top-up unlocks
        $this->credit($this->ca, 5);
        $this->hook('7001', 'hello again')->assertJsonPath('ai', 1);
        $this->assertSame(1, AiUsageLog::count());
        // toggle off → works even at remaining ~0 after draining
        $this->as($this->admin)->putJson("/api/ai-usage/clients/{$this->ca->id}", ['ai_block_when_empty' => false])->assertOk();
        // burn remaining by recording a huge fake cost
        AiUsageLog::create(['client_id' => $this->ca->id, 'phone_number_id' => $this->a->id, 'feature' => 'other', 'model' => 'gpt-4o-mini',
            'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'cost_usd' => 1, 'cost_inr' => 100, 'created_at' => now()]);
        $this->assertTrue(app(AiUsageService::class)->balance($this->ca->id)['remaining'] < 0);
        $this->assertNull(app(AiUsageService::class)->skipReason($this->a->fresh()));
        $this->hook('7001', 'still ok')->assertJsonPath('ai', 1);
    }

    public function test_admin_can_add_credits_and_see_summary_drilldown(): void
    {
        $this->credit($this->ca, 25, 'starter pack');
        $this->hook('7001', 'hi');
        $sum = $this->as($this->admin)->getJson('/api/ai-usage')->assertOk()->json();
        $row = collect($sum['clients'])->firstWhere('id', $this->ca->id);
        $this->assertSame(1, $row['calls']);
        $this->assertEqualsWithDelta(25.0, $row['credits'], 1e-6);
        $this->assertGreaterThan(0, $row['used_all']);
        $detail = $this->as($this->admin)->getJson("/api/ai-usage/clients/{$this->ca->id}")->assertOk()->json();
        $this->assertCount(1, $detail['by_number']);
        $this->assertSame($this->a->id, $detail['by_number'][0]['phone_number_id']);
        $this->assertNotEmpty($detail['by_day']);
        $this->assertCount(1, $detail['recent']);
        $this->assertSame('starter pack', $detail['ledger'][0]['note']);
        $this->as($this->admin)->postJson("/api/ai-usage/clients/{$this->ca->id}/credits", ['amount_inr' => -5, 'note' => 'adjustment'])
            ->assertCreated()->assertJsonPath('balance.credits', 20);
        $this->as($this->admin)->postJson("/api/ai-usage/clients/{$this->ca->id}/credits", ['amount_inr' => 0])->assertStatus(422);
        $staff = User::create(['name' => 'S', 'email' => 's@t.l', 'password' => 'password123', 'is_admin' => false]);
        $this->as($staff)->getJson('/api/ai-usage')->assertForbidden();
    }

    public function test_client_sees_only_own_usage_isolation(): void
    {
        $this->credit($this->ca, 10);
        $this->credit($this->cb, 10);
        $this->hook('7001', 'hi A');
        $this->hook('7002', 'hi B');
        $mine = $this->as($this->ca)->getJson('/api/client/ai-usage')->assertOk()->json();
        $this->assertSame($this->ca->id, $mine['client']['id']);
        $this->assertSame(1, $mine['stats']['calls']);
        $this->assertSame($this->a->id, $mine['by_number'][0]['phone_number_id']);
        foreach ($mine['recent'] as $r) $this->assertSame($this->ca->id, $r['client_id']);
        // cannot peek at B via admin endpoints
        $this->as($this->ca)->getJson('/api/ai-usage')->assertForbidden();
        $this->as($this->ca)->getJson("/api/ai-usage/clients/{$this->cb->id}")->assertForbidden();
        $this->as($this->ca)->postJson("/api/ai-usage/clients/{$this->ca->id}/credits", ['amount_inr' => 1])->assertForbidden();
        // B's portal never includes A's costs
        $b = $this->as($this->cb)->getJson('/api/client/ai-usage')->assertOk()->json();
        $this->assertSame(1, $b['stats']['calls']);
        $this->assertEqualsWithDelta($mine['stats']['used_all'], $mine['balance']['used'], 1e-6);
        $this->assertNotEquals($mine['balance']['used'], 0);
        // each client's used matches their own ledger sum; totals do not cross-leak
        $this->assertEqualsWithDelta($mine['balance']['used'], app(AiUsageService::class)->used($this->ca->id), 1e-6);
        $this->assertEqualsWithDelta($b['balance']['used'], app(AiUsageService::class)->used($this->cb->id), 1e-6);
        $this->assertEqualsWithDelta(
            $mine['balance']['used'] + $b['balance']['used'],
            (float) AiUsageLog::sum('cost_inr'),
            1e-6
        );
        $this->assertSame(1, AiUsageLog::where('client_id', $this->ca->id)->count());
        $this->assertSame(1, AiUsageLog::where('client_id', $this->cb->id)->count());
    }

    public function test_trainer_and_playground_record_features(): void
    {
        $this->credit($this->ca, 10);
        $this->answer = 'Saved <actions>[]</actions>';
        $this->as($this->admin)->post("/api/ai-train/{$this->a->id}/trainer", ['message' => 'price is 10'], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseHas('ai_usage_logs', ['feature' => 'train_ai', 'client_id' => $this->ca->id, 'phone_number_id' => $this->a->id]);
        $this->as($this->admin)->postJson("/api/ai-train/{$this->a->id}/chat", ['messages' => [['role' => 'user', 'content' => 'hi']]])->assertOk();
        $this->assertDatabaseHas('ai_usage_logs', ['feature' => 'other', 'client_id' => $this->ca->id]);
        $this->assertSame(2, AiUsageLog::count());
    }

    public function test_settings_expose_price_table_and_rate(): void
    {
        $r = $this->as($this->admin)->getJson('/api/settings')->assertOk()->json();
        $this->assertArrayHasKey('ai_usd_inr_rate', $r);
        $this->assertArrayHasKey('ai_model_prices_parsed', $r);
        $this->assertEquals(0.15, $r['ai_model_prices_parsed']['gpt-4o-mini']['input']);
        $this->assertEquals(88.0, (float) $r['ai_defaults']['usd_inr_rate']);
        $this->as($this->admin)->putJson('/api/settings', ['ai_usd_inr_rate' => '90', 'ai_model_prices' => json_encode(['gpt-4o-mini' => ['input' => 0.2, 'output' => 0.8]])])->assertOk();
        $this->assertEquals(90.0, AiUsageService::usdInr());
        $this->assertEquals(0.2, AiUsageService::prices()['gpt-4o-mini']['input']);
    }
}
