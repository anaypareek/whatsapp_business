<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected Client $a; protected Client $b; protected PhoneNumber $na; protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // never reach Meta
        Setting::put('app_secret', 'shh');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'ad@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->a = Client::create(['name' => 'Alpha', 'email' => 'alpha@c.t', 'password' => 'password123']);
        $this->b = Client::create(['name' => 'Beta', 'email' => 'beta@c.t', 'password' => 'password123']);
        $this->na = PhoneNumber::create(['phone_number_id' => '1111', 'display_number' => '+91 1', 'client_id' => $this->a->id]);
        $nb = PhoneNumber::create(['phone_number_id' => '2222', 'display_number' => '+91 2', 'client_id' => $this->b->id]);
        $mk = MessageTemplate::create(['name' => 'promo', 'language' => 'en', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => []]);
        $ut = MessageTemplate::create(['name' => 'order', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => []]);
        $out = fn ($n, $extra) => Message::create(['phone_number_id' => $n->id, 'contact_wa_id' => '91900', ] + $extra + ['direction' => 'out', 'status' => 'delivered']);
        foreach (range(1, 3) as $i) $out($this->na, ['message_template_id' => $mk->id, 'template_name' => 'promo (en)']);
        foreach (range(1, 2) as $i) $out($this->na, ['message_template_id' => $ut->id, 'template_name' => 'order (en)']);
        $out($this->na, ['message_template_id' => $ut->id, 'template_name' => 'order (en)', 'pricing_category' => 'authentication']); // Meta's category wins
        $out($this->na, ['body' => 'free-form reply']);                                                      // service
        $out($this->na, ['message_template_id' => $mk->id, 'template_name' => 'promo (en)', 'status' => 'failed']); // not billed
        Message::create(['phone_number_id' => $this->na->id, 'contact_wa_id' => '91900', 'direction' => 'in', 'status' => 'received', 'body' => 'hi']); // inbound: free
        $out($nb, ['message_template_id' => $mk->id, 'template_name' => 'promo (en)']);
        Cache::flush();
    }

    protected function as($u) { $this->app['auth']->forgetGuards(); return $this->withToken($u->createToken('t')->plainTextToken); }

    public function test_counts_by_category_and_math_with_custom_rates(): void
    {
        $this->as($this->admin)->putJson("/api/billing/clients/{$this->a->id}/rates", ['marketing' => 1, 'utility' => 0.2, 'authentication' => 0.15, 'service' => 0])->assertOk();
        $s = $this->as($this->admin)->getJson("/api/billing/clients/{$this->a->id}?from=all")->assertOk()->json();
        $lines = collect($s['lines'])->keyBy('category');
        $this->assertSame([3, 2, 1, 1], [$lines['marketing']['count'], $lines['utility']['count'], $lines['authentication']['count'], $lines['service']['count']]);
        $this->assertEquals(3.55, $s['total']); // 3×1 + 2×0.2 + 1×0.15 + 1×0
        $this->assertEquals(3.55, $s['balance']['due']);
    }

    public function test_default_rates_apply_and_client_card_overrides(): void
    {
        $this->as($this->admin)->putJson('/api/billing/default-rates', ['marketing' => 0.5, 'utility' => 0.1, 'authentication' => 0.1])->assertOk();
        $sum = $this->as($this->admin)->getJson('/api/billing/summary')->assertOk()->json();
        $rows = collect($sum['clients'])->keyBy('id');
        $this->assertEquals(1.8, $rows[$this->a->id]['charges']); // 1.5 + 0.2 + 0.1
        $this->assertEquals(0.5, $rows[$this->b->id]['charges']);
        $this->assertEquals(2.3, $sum['total_due']);
        $this->assertEquals(0.0, BillingService::FALLBACK['service']);
    }

    public function test_payments_reduce_due_and_are_admin_only(): void
    {
        $this->as($this->admin)->putJson("/api/billing/clients/{$this->a->id}/rates", ['marketing' => 10, 'utility' => 0, 'authentication' => 0])->assertOk();
        $this->as($this->admin)->postJson("/api/billing/clients/{$this->a->id}/payments", ['amount' => 12.5, 'paid_at' => now()->toDateString(), 'method' => 'UPI', 'reference' => 'TX1'])
            ->assertCreated()->assertJsonPath('balance.due', 17.5);
        $this->assertDatabaseHas('client_payments', ['client_id' => $this->a->id, 'created_by' => $this->admin->id, 'reference' => 'TX1']);
        $this->as($this->admin)->postJson("/api/billing/clients/{$this->a->id}/payments", ['amount' => 0])->assertStatus(422);
        $staff = User::create(['name' => 'S', 'email' => 's@t.l', 'password' => 'password123', 'is_admin' => false]);
        $this->as($staff)->getJson('/api/billing/summary')->assertStatus(403);
        $this->as($this->a)->postJson("/api/billing/clients/{$this->a->id}/payments", ['amount' => 1])->assertStatus(403);
    }

    public function test_client_sees_only_own_usage_due_and_payments(): void
    {
        $this->as($this->admin)->putJson("/api/billing/clients/{$this->a->id}/rates", ['marketing' => 1, 'utility' => 1, 'authentication' => 1])->assertOk();
        $this->as($this->admin)->postJson("/api/billing/clients/{$this->b->id}/payments", ['amount' => 99])->assertCreated();
        $this->as($this->admin)->postJson("/api/billing/clients/{$this->a->id}/payments", ['amount' => 2])->assertCreated();
        $me = $this->as($this->a)->getJson('/api/client/billing')->assertOk()->json(); // default period = this month
        $this->assertSame(7, $me['messages']);
        $this->assertEquals(6.0, $me['total']);
        $this->assertCount(1, $me['payments']);
        $this->assertEquals(2.0, $me['payments_total']);
        $this->as($this->a)->getJson('/api/client/billing/due')->assertOk()->assertJsonPath('due', 4);
        // date filter: a period before any usage
        $old = $this->as($this->a)->getJson('/api/client/billing?from=2020-01-01&to=2020-01-31')->assertOk()->json();
        $this->assertSame(0, $old['messages']);
        $this->as($this->a)->getJson('/api/client/billing?from=garbage')->assertStatus(422);
    }

    public function test_status_webhook_stores_pricing_category(): void
    {
        $m = Message::create(['phone_number_id' => $this->na->id, 'contact_wa_id' => '91900', 'direction' => 'out', 'status' => 'sent', 'wamid' => 'wamid.P1', 'body' => 'x']);
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '9', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1111'],
            'statuses' => [['id' => 'wamid.P1', 'status' => 'delivered', 'timestamp' => (string) time(), 'recipient_id' => '91900',
                'conversation' => ['id' => 'conv1'], 'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'marketing']]]]]]]]]);
        $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw)->assertOk();
        $m->refresh();
        $this->assertSame(['delivered', 'marketing', true, 'conv1'], [$m->status, $m->pricing_category, (bool) $m->billable, $m->conversation_id]);
        $this->assertDatabaseHas('message_statuses', ['wamid' => 'wamid.P1', 'pricing_category' => 'marketing']);
        $this->assertSame('marketing', BillingService::normalize('marketing_lite'));
        $this->assertSame('authentication', BillingService::normalize('authentication_international'));
        $this->assertSame('service', BillingService::normalize('referral_conversion'));
    }
}
