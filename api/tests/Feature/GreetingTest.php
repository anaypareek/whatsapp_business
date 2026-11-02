<?php
namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\Client;
use App\Models\Flow;
use App\Models\GreetingGroup;
use App\Models\GreetingSetting;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GreetingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $a;
    protected Client $b;
    protected PhoneNumber $na;
    protected PhoneNumber $nb;
    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->a = Client::create(['name' => 'Alpha', 'email' => 'alpha@c.t', 'password' => 'password123']);
        $this->b = Client::create(['name' => 'Beta', 'email' => 'beta@c.t', 'password' => 'password123']);
        $this->na = PhoneNumber::create(['client_id' => $this->a->id, 'phone_number_id' => '7001', 'display_number' => '+91 90000 70001']);
        $this->nb = PhoneNumber::create(['client_id' => $this->b->id, 'phone_number_id' => '7002', 'display_number' => '+91 90000 70002']);
        Http::fake(['graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.G'.bin2hex(random_bytes(5))]]])]);
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00', 'Asia/Kolkata')); // Monday noon IST
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function as(Client $c) { $this->app['auth']->forgetGuards(); return $this->withToken($c->createToken('c')->plainTextToken); }

    protected function inbound(array $msg, string $from = '919811000001', string $pnid = '7001')
    {
        $this->seq++;
        $p = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '919000070001', 'phone_number_id' => $pnid],
            'contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => 'wamid.IN'.$this->seq, 'timestamp' => (string) now()->timestamp] + $msg],
        ]]]]]];
        $raw = json_encode($p);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw);
    }

    protected function text(string $t, string $from = '919811000001') { return $this->inbound(['type' => 'text', 'text' => ['body' => $t]], $from); }

    protected function sentTexts(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages'))
            ->map(fn ($r) => $r['text']['body'] ?? $r['image']['caption'] ?? $r['interactive']['body']['text'] ?? '?')->values()->all();
    }

    protected function enable(array $over = []): GreetingSetting
    {
        return GreetingSetting::create(['phone_number_id' => $this->na->id, 'enabled' => true, 'greeting_text' => 'Hello {name}, welcome!'] + $over);
    }

    public function test_off_by_default(): void
    {
        $this->text('hi')->assertOk()->assertJsonPath('greetings', 0);
        GreetingSetting::create(['phone_number_id' => $this->na->id, 'greeting_text' => 'Hello']);
        $this->assertFalse(GreetingSetting::first()->fresh()->enabled);
        $this->text('hi again')->assertJsonPath('greetings', 0);
        $this->assertSame([], $this->sentTexts());
        $this->admin()->getJson('/api/greetings')->assertOk()->assertJsonPath('1.settings.enabled', false)->assertJsonPath('1.settings.cooldown_hours', 24);
    }

    public function test_greeting_sent_once_per_cooldown_with_name_and_auto_flag(): void
    {
        $this->enable(['cooldown_hours' => 24]);
        $this->text('hi')->assertJsonPath('greetings', 1);
        $this->text('anyone there?')->assertJsonPath('greetings', 0);
        $this->assertSame(['Hello Ravi, welcome!'], $this->sentTexts());
        $this->assertDatabaseHas('messages', ['direction' => 'out', 'source' => 'greeting', 'is_auto' => true, 'body' => 'Hello Ravi, welcome!']);
        Carbon::setTestNow(now()->addHours(25));
        $this->text('back')->assertJsonPath('greetings', 1);
    }

    public function test_skip_when_staff_replied_manually_within_cooldown(): void
    {
        $this->enable();
        Message::create(['phone_number_id' => $this->na->id, 'contact_wa_id' => '919811000001', 'direction' => 'out', 'type' => 'text', 'body' => 'Hi from staff', 'status' => 'sent', 'source' => 'manual']);
        $this->text('hi')->assertJsonPath('greetings', 0);
        Carbon::setTestNow(now()->addHours(25));
        $this->text('hi')->assertJsonPath('greetings', 1);
    }

    public function test_new_customers_only(): void
    {
        $this->enable(['mode' => 'new_only', 'cooldown_hours' => 1]);
        $this->text('first')->assertJsonPath('greetings', 1);
        Carbon::setTestNow(now()->addHours(5));
        $this->text('second')->assertJsonPath('greetings', 0);
        $this->text('hello', '919811000002')->assertJsonPath('greetings', 1);
    }

    public function test_off_hours_text_outside_business_hours(): void
    {
        $this->enable(['hours_enabled' => true, 'hours_start' => '09:00', 'hours_end' => '18:00', 'hours_days' => [1, 2, 3, 4, 5], 'off_hours_text' => 'We are closed, {name}.']);
        $this->text('noon')->assertJsonPath('greetings', 1); // Mon 12:00 IST: open
        Carbon::setTestNow(Carbon::parse('2026-11-03 21:00', 'Asia/Kolkata'));
        $this->text('night', '919811000002')->assertJsonPath('greetings', 1);
        $this->text('again', '919811000002')->assertJsonPath('greetings', 0); // same cooldown
        Carbon::setTestNow(Carbon::parse('2026-11-08 11:00', 'Asia/Kolkata')); // Sunday
        $this->text('sunday', '919811000003')->assertJsonPath('greetings', 1);
        $this->assertSame(['Hello Ravi, welcome!', 'We are closed, Ravi.', 'We are closed, Ravi.'], $this->sentTexts());
        $this->assertDatabaseHas('messages', ['source' => 'off_hours', 'is_auto' => true]);
    }

    public function test_keyword_rule_takes_precedence_and_no_double_reply(): void
    {
        $this->enable();
        AutoReplyRule::create(['phone_number_id' => $this->na->id, 'match_type' => 'contains', 'trigger' => 'price', 'reply' => 'Price list', 'enabled' => true]);
        $this->text('price?')->assertJsonPath('auto_replies', 1)->assertJsonPath('greetings', 0);
        $this->assertSame(['Price list'], $this->sentTexts());
        $this->assertSame(1, Message::where('direction', 'out')->count());
    }

    public function test_flow_takes_precedence_over_greeting(): void
    {
        $this->enable();
        Flow::create(['phone_number_id' => $this->na->id, 'client_id' => $this->a->id, 'name' => 'F', 'is_active' => true, 'trigger_type' => 'keyword', 'keywords' => ['menu'],
            'nodes' => [['id' => 'trigger', 'type' => 'trigger', 'data' => []], ['id' => 'm1', 'type' => 'message', 'data' => ['text' => 'Flow says hi']]],
            'edges' => [['source' => 'trigger', 'target' => 'm1']]]);
        $this->text('menu')->assertJsonPath('flows', 1)->assertJsonPath('greetings', 0);
        $this->assertSame(['Flow says hi'], $this->sentTexts());
    }

    public function test_unsupported_message_treated_like_any_inbound(): void
    {
        $this->enable();
        $this->inbound(['type' => 'unsupported', 'errors' => [['code' => 131051]]])->assertJsonPath('greetings', 1)->assertJsonPath('auto_replies', 0);
        $this->assertDatabaseHas('messages', ['direction' => 'in', 'type' => 'unsupported']);
    }

    public function test_group_greeting_priority_and_default_fallback(): void
    {
        $this->enable(['hours_enabled' => true, 'hours_start' => '09:00', 'hours_end' => '18:00', 'off_hours_text' => 'Closed (default)']);
        $emp = $this->as($this->a)->postJson("/api/client/greetings/{$this->na->id}/groups", ['name' => 'Employees', 'greeting_text' => 'Hi team {name}', 'members' => ['+91 98110 00001']])
            ->assertCreated()->assertJsonPath('members.0.contact_wa_id', '919811000001')->json('id');
        $this->as($this->a)->postJson("/api/client/greetings/{$this->na->id}/groups", ['name' => 'VIP', 'greeting_text' => 'Hi VIP', 'priority' => 5, 'members' => ['919811000002']])->assertCreated();
        $this->as($this->a)->postJson("/api/client/greetings/{$this->na->id}/groups", ['name' => 'Customers', 'greeting_text' => 'Hi customer', 'members' => ['919811000002']])->assertCreated();
        $this->text('a', '919811000001');
        $this->text('b', '919811000002'); // in VIP + Customers: higher priority wins
        $this->text('c', '919811000003'); // no group: default
        Carbon::setTestNow(Carbon::parse('2026-11-02 22:00', 'Asia/Kolkata'));
        $this->text('d', '919811000004'); // off-hours, group has none: default off-hours
        $this->assertSame(['Hi team Ravi', 'Hi VIP', 'Hello Ravi, welcome!', 'Closed (default)'], $this->sentTexts());
        // remove member -> falls back to default
        $this->as($this->a)->putJson("/api/client/greeting-groups/$emp", ['members' => []])->assertOk()->assertJsonCount(0, 'members');
        $this->assertNull(GreetingGroup::forContact($this->na->id, '919811000001'));
    }

    public function test_client_isolation(): void
    {
        $gb = GreetingGroup::create(['phone_number_id' => $this->nb->id, 'name' => 'Beta staff']);
        $list = $this->as($this->a)->getJson('/api/client/greetings')->assertOk()->json();
        $this->assertSame([$this->na->id], array_column(array_column($list, 'phone_number'), 'id'));
        $this->as($this->a)->putJson("/api/client/greetings/{$this->nb->id}", ['enabled' => false])->assertNotFound();
        $this->as($this->a)->postJson("/api/client/greetings/{$this->nb->id}/groups", ['name' => 'x'])->assertNotFound();
        $this->as($this->a)->putJson("/api/client/greeting-groups/{$gb->id}", ['name' => 'hacked'])->assertNotFound();
        $this->as($this->a)->deleteJson("/api/client/greeting-groups/{$gb->id}")->assertNotFound();
        $this->as($this->a)->getJson("/api/client/greetings/{$this->nb->id}/contacts")->assertNotFound();
        $this->assertSame('Beta staff', $gb->fresh()->name);
        $this->as($this->a)->putJson("/api/client/greetings/{$this->na->id}", ['enabled' => true, 'greeting_text' => 'Hi'])->assertSuccessful()->assertJsonPath('enabled', true);
        $this->as($this->a)->getJson('/api/greetings')->assertForbidden(); // admin endpoint
        $this->admin()->getJson('/api/greetings')->assertOk()->assertJsonCount(2);
        $this->admin()->putJson("/api/greetings/{$this->nb->id}", ['enabled' => true])->assertStatus(422); // needs text
    }
}
