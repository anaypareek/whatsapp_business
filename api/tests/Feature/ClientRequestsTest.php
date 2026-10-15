<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberRequest;
use App\Models\Setting;
use App\Models\TemplateRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $a;
    protected Client $b;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->a = Client::create(['name' => 'Alpha', 'email' => 'alpha@c.t', 'password' => 'password123']);
        $this->b = Client::create(['name' => 'Beta', 'email' => 'beta@c.t', 'password' => 'password123']);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function as(Client $c) { $this->app['auth']->forgetGuards(); return $this->withToken($c->createToken('c')->plainTextToken); }

    protected function tplBody(string $name = 'order_update'): array
    {
        return ['name' => $name, 'language' => 'en_US', 'category' => 'UTILITY', 'header' => ['format' => 'TEXT', 'text' => 'Order update'],
            'body' => ['text' => 'Hi {{1}}, your order {{2}} has shipped.', 'examples' => ['Ravi', '#123']], 'footer' => 'Oswal Soap',
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Thanks']]];
    }

    public function test_number_request_approve_creates_number_and_registers_on_meta(): void
    {
        Http::fake(['graph.facebook.com/*/5551/register' => Http::response(['success' => true])]);
        $id = $this->as($this->a)->postJson('/api/client/number-requests', ['phone_number' => '+91 98765 43210', 'display_name' => 'Alpha Store',
            'business_name' => 'Alpha Pvt Ltd', 'category' => 'Retail', 'notes' => 'Main line'])->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->admin()->getJson('/api/requests/pending-counts')->assertJson(['numbers' => 1, 'templates' => 0]);
        $this->admin()->getJson('/api/dashboard')->assertJsonPath('pending_requests.numbers', 1);

        $this->admin()->postJson("/api/number-requests/$id/approve", ['phone_number_id' => '5551'])->assertStatus(422)->assertJsonValidationErrors('pin');
        $res = $this->admin()->postJson("/api/number-requests/$id/approve", ['phone_number_id' => '5551', 'pin' => '123456'])->assertOk()
            ->assertJsonPath('status', 'approved')->assertJsonPath('registration_status', 'registered')->json();
        $n = PhoneNumber::where('phone_number_id', '5551')->firstOrFail();
        $this->assertSame($this->a->id, $n->client_id);
        $this->assertSame('Alpha Store', $n->name);
        $this->assertSame($n->id, $res['created_phone_number_id']);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/5551/register') && $r['pin'] === '123456');
        // can't approve twice; client now sees the number
        $this->admin()->postJson("/api/number-requests/$id/approve", ['phone_number_id' => '5552', 'pin' => '123456'])->assertStatus(422);
        $this->as($this->a)->getJson('/api/client/numbers')->assertJsonCount(1)->assertJsonPath('0.phone_number_id', '5551');
    }

    public function test_number_request_registration_error_is_stored(): void
    {
        Http::fake(['graph.facebook.com/*/5553/register' => Http::response(['error' => ['message' => 'Invalid PIN', 'code' => 100]], 400)]);
        $r = $this->a->numberRequests()->create(['phone_number' => '919000000001', 'display_name' => 'X', 'business_name' => 'X Ltd']);
        $this->admin()->postJson("/api/number-requests/{$r->id}/approve", ['phone_number_id' => '5553', 'pin' => '000000'])->assertOk()
            ->assertJsonPath('status', 'approved')->assertJsonPath('registration_status', 'failed');
        $this->assertStringContainsString('Invalid PIN', $r->fresh()->registration_error);
        $this->assertDatabaseHas('phone_numbers', ['phone_number_id' => '5553', 'client_id' => $this->a->id]);
    }

    public function test_number_request_reject_needs_reason(): void
    {
        Http::fake();
        $r = $this->a->numberRequests()->create(['phone_number' => '919000000002', 'display_name' => 'X', 'business_name' => 'X Ltd']);
        $this->admin()->postJson("/api/number-requests/{$r->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->admin()->postJson("/api/number-requests/{$r->id}/reject", ['reason' => 'Number already on another WABA'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->as($this->a)->getJson('/api/client/number-requests')->assertJsonPath('0.status', 'rejected')->assertJsonPath('0.rejection_reason', 'Number already on another WABA');
        $this->assertSame(0, PhoneNumber::count());
        Http::assertNothingSent();
    }

    public function test_template_request_edit_and_approve_calls_meta_and_links_template(): void
    {
        Http::fake(['graph.facebook.com/*/999/message_templates' => Http::response(['id' => 'MT77', 'status' => 'PENDING', 'category' => 'UTILITY'])]);
        $id = $this->as($this->a)->postJson('/api/client/template-requests', $this->tplBody())->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->as($this->a)->postJson('/api/client/template-requests', $this->tplBody())->assertStatus(422); // duplicate pending
        $this->admin()->getJson('/api/requests/pending-counts')->assertJson(['templates' => 1]);

        $edit = $this->tplBody('order_shipped'); $edit['footer'] = 'Thank you';
        $this->admin()->putJson("/api/template-requests/$id", $edit)->assertOk()->assertJsonPath('name', 'order_shipped');
        $this->admin()->postJson("/api/template-requests/$id/approve")->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('template.status', 'PENDING');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/999/message_templates')
            && $r['name'] === 'order_shipped' && collect($r['components'])->firstWhere('type', 'FOOTER')['text'] === 'Thank you');
        $t = MessageTemplate::where('meta_id', 'MT77')->firstOrFail();
        $this->assertSame($this->a->id, $t->client_id);
        $this->assertSame($t->id, TemplateRequest::find($id)->message_template_id);

        // only APPROVED templates appear under Templates; after the webhook/sync approves it, it shows up
        $this->as($this->a)->getJson('/api/client/templates')->assertJsonCount(0);
        $t->update(['status' => 'APPROVED']);
        $this->as($this->a)->getJson('/api/client/templates')->assertJsonCount(1)->assertJsonPath('0.name', 'order_shipped');
        $this->as($this->a)->getJson('/api/client/template-requests')->assertJsonPath('0.template.status', 'APPROVED');
    }

    public function test_template_request_meta_error_keeps_request_pending(): void
    {
        Http::fake(['graph.facebook.com/*/999/message_templates' => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400)]);
        $r = TemplateRequest::create(['client_id' => $this->a->id, 'name' => 'x_tpl', 'language' => 'en_US', 'category' => 'UTILITY', 'form' => [], 'components' => [['type' => 'BODY', 'text' => 'Hi']]]);
        $this->admin()->postJson("/api/template-requests/{$r->id}/approve")->assertStatus(502);
        $this->assertSame('pending', $r->fresh()->status);
        $this->assertStringContainsString('Invalid parameter', $r->fresh()->meta_error);
        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_template_request_reject(): void
    {
        Http::fake();
        $id = $this->as($this->a)->postJson('/api/client/template-requests', $this->tplBody())->assertCreated()->json('id');
        $this->admin()->postJson("/api/template-requests/$id/reject", ['reason' => 'Promotional wording in a UTILITY template'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->as($this->a)->getJson('/api/client/template-requests')->assertJsonPath('0.rejection_reason', 'Promotional wording in a UTILITY template');
        Http::assertNothingSent();
        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_clients_are_isolated_and_cannot_approve(): void
    {
        Http::fake();
        $nr = $this->a->numberRequests()->create(['phone_number' => '919000000003', 'display_name' => 'A', 'business_name' => 'A Ltd']);
        $tr = $this->as($this->a)->postJson('/api/client/template-requests', $this->tplBody())->json('id');
        $na = PhoneNumber::create(['client_id' => $this->a->id, 'phone_number_id' => 'PA', 'display_number' => '911111111111']);
        $tpl = MessageTemplate::create(['client_id' => $this->a->id, 'name' => 'promo', 'language' => 'en_US', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => []]);
        Message::create(['phone_number_id' => $na->id, 'contact_wa_id' => '919999', 'direction' => 'out', 'type' => 'template', 'message_template_id' => $tpl->id, 'status' => 'sent']);

        $this->as($this->b)->getJson('/api/client/number-requests')->assertJsonCount(0);
        $this->as($this->b)->getJson('/api/client/template-requests')->assertJsonCount(0);
        $this->as($this->b)->getJson('/api/client/stats')->assertJsonPath('total_sent', 0)->assertJsonCount(0, 'templates');
        $this->as($this->b)->getJson("/api/client/stats/templates/{$tpl->id}")->assertNotFound();
        $this->as($this->a)->getJson("/api/client/stats/templates/{$tpl->id}")->assertOk()->assertJsonPath('sends.total', 1);

        foreach ([$this->a, $this->b] as $c) {
            $this->as($c)->postJson("/api/number-requests/{$nr->id}/approve", ['phone_number_id' => 'Z', 'pin' => '123456'])->assertForbidden();
            $this->as($c)->postJson("/api/number-requests/{$nr->id}/reject", ['reason' => 'x'])->assertForbidden();
            $this->as($c)->postJson("/api/template-requests/$tr/approve")->assertForbidden();
            $this->as($c)->putJson("/api/template-requests/$tr", $this->tplBody('hacked'))->assertForbidden();
            $this->as($c)->getJson('/api/number-requests')->assertForbidden();
        }
        $this->assertSame('pending', $nr->fresh()->status);
        $this->assertSame('pending', TemplateRequest::find($tr)->status);
        // staff tokens can't use client endpoints
        $this->admin()->getJson('/api/client/stats')->assertForbidden();
    }

    public function test_template_stats_counts_and_details_listing(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $n1 = PhoneNumber::create(['client_id' => $this->a->id, 'phone_number_id' => 'P1', 'display_number' => '+91 90000 11111', 'name' => 'Sales']);
        $n2 = PhoneNumber::create(['client_id' => $this->a->id, 'phone_number_id' => 'P2', 'display_number' => '+91 90000 22222']);
        $other = PhoneNumber::create(['client_id' => $this->b->id, 'phone_number_id' => 'P3', 'display_number' => '+91 90000 33333']);
        $t1 = MessageTemplate::create(['client_id' => $this->a->id, 'name' => 'order_alert', 'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => []]);
        $t2 = MessageTemplate::create(['name' => 'promo', 'language' => 'hi', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => []]);
        $mk = fn ($n, $t, $to, $status, $at = null, $err = null) => tap(Message::create(['phone_number_id' => $n->id, 'contact_wa_id' => $to, 'direction' => 'out', 'type' => 'template',
            'message_template_id' => $t->id, 'template_name' => "{$t->name} ({$t->language})", 'status' => $status, 'error' => $err]), fn ($m) => $at && $m->forceFill(['created_at' => $at])->save());
        $mk($n1, $t1, '919811100001', 'sent');
        $mk($n1, $t1, '919811100002', 'delivered');
        $mk($n2, $t1, '919811100003', 'read');
        $mk($n2, $t1, '919811100004', 'failed', null, '(#131026) Message undeliverable');
        $mk($n1, $t1, '919811100005', 'read', now()->subDays(3));
        $mk($n1, $t2, '919811100006', 'delivered', now()->subDays(40));
        $mk($other, $t1, '919811100007', 'read'); // other client's number: excluded
        Message::create(['phone_number_id' => $n1->id, 'contact_wa_id' => '919811100008', 'direction' => 'out', 'type' => 'text', 'body' => 'hi', 'status' => 'sent']);
        Message::create(['phone_number_id' => $n1->id, 'contact_wa_id' => '919811100008', 'direction' => 'in', 'type' => 'text', 'body' => 'yo', 'status' => 'received']);

        $s = $this->as($this->a)->getJson('/api/client/stats')->assertOk()->json();
        $this->assertSame(7, $s['total_sent']);
        $this->assertSame(2, $s['numbers']);
        $this->assertSame(5, $s['sent_today']);
        $this->assertSame(now()->day >= 4 ? 6 : 5, $s['sent_month']);
        $this->assertCount(7, $s['series']);
        $this->assertSame(5, end($s['series'])['sent']);
        $row = collect($s['templates'])->firstWhere('template_id', $t1->id);
        $this->assertSame(['name' => 'order_alert', 'language' => 'en_US', 'sent' => 5, 'delivered' => 3, 'read' => 2, 'failed' => 1],
            collect($row)->only(['name', 'language', 'sent', 'delivered', 'read', 'failed'])->all());
        $this->assertNotNull($row['last_sent']);
        $this->assertSame(1, collect($s['templates'])->firstWhere('template_id', $t2->id)['sent']);

        $d = $this->as($this->a)->getJson("/api/client/stats/templates/{$t1->id}?per_page=5")->assertOk()->json();
        $this->assertSame(['sent' => 5, 'delivered' => 3, 'read' => 2, 'failed' => 1], $d['totals']);
        $this->assertSame(5, $d['sends']['total']);
        $this->assertCount(5, $d['sends']['data']);
        $this->assertNotContains('919811100007', array_column($d['sends']['data'], 'contact_wa_id'));
        $failed = collect($d['sends']['data'])->firstWhere('status', 'failed');
        $this->assertSame('(#131026) Message undeliverable', $failed['error']);
        $this->assertSame('+91 90000 22222', $failed['phone_number']['display_number']);

        $this->as($this->a)->getJson("/api/client/stats/templates/{$t1->id}?q=100003")->assertJsonPath('sends.total', 1)->assertJsonPath('sends.data.0.contact_wa_id', '919811100003');
        $this->as($this->a)->getJson("/api/client/stats/templates/{$t1->id}?q=22222")->assertJsonPath('sends.total', 2);
        $this->as($this->a)->getJson("/api/client/stats/templates/{$t1->id}?status=read")->assertJsonPath('sends.total', 2);
        $this->as($this->a)->getJson("/api/client/stats/templates/{$t1->id}?per_page=5&page=2")->assertJsonCount(0, 'sends.data');
    }
}
