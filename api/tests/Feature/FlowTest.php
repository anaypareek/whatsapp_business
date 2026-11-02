<?php
namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowSession;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowTest extends TestCase
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
        Http::fake(['graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.F'.bin2hex(random_bytes(5))]]])]);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function as(Client $c) { $this->app['auth']->forgetGuards(); return $this->withToken($c->createToken('c')->plainTextToken); }

    protected function graph(): array
    {
        $n = fn ($id, $type, $data, $x = 0) => ['id' => $id, 'type' => $type, 'position' => ['x' => $x, 'y' => 0], 'data' => $data];
        $e = fn ($s, $h, $t) => ['source' => $s, 'sourceHandle' => $h, 'target' => $t];
        return [
            'nodes' => [
                $n('trigger', 'trigger', []),
                $n('m0', 'message', ['text' => 'Welcome to Oswal Soap!', 'media_type' => 'image', 'media_url' => 'https://cdn.example.com/banner.jpg']),
                $n('q1', 'question', ['text' => 'How can we help?', 'options' => [
                    ['id' => 'o1', 'title' => 'Order status', 'keywords' => 'track, where is my order'], ['id' => 'o2', 'title' => 'Products'], ['id' => 'o3', 'title' => 'Support']]]),
                $n('m1', 'message', ['text' => 'Your order is on the way.']),
                $n('q2', 'question', ['text' => 'Pick a range', 'list_button' => 'See ranges', 'options' => [
                    ['id' => 'l1', 'title' => 'Bathing soap'], ['id' => 'l2', 'title' => 'Laundry soap'], ['id' => 'l3', 'title' => 'Dishwash bar'], ['id' => 'l4', 'title' => 'Hand wash'], ['id' => 'l5', 'title' => 'Other']]]),
                $n('m2', 'message', ['text' => 'Laundry soap: Rs 30 per bar.']),
                $n('c1', 'condition', ['keywords' => ['urgent', 'asap'], 'match_type' => 'contains']),
                $n('m3', 'message', ['text' => 'Connecting you to an agent now.']),
                $n('m4', 'message', ['text' => 'We will reply within a day.']),
            ],
            'edges' => [$e('trigger', 'next', 'm0'), $e('m0', 'next', 'q1'), $e('q1', 'opt-o1', 'm1'), $e('q1', 'opt-o2', 'q2'), $e('q2', 'opt-l2', 'm2'),
                $e('q1', 'opt-o3', 'c1'), $e('c1', 'match', 'm3'), $e('c1', 'nomatch', 'm4')],
        ];
    }

    protected function makeFlow(array $over = []): Flow
    {
        $id = $this->admin()->postJson('/api/flows', $over + ['phone_number_id' => $this->na->id, 'name' => 'Main menu', 'trigger_type' => 'contains',
            'keywords' => ['hi', 'menu'], 'is_active' => true] + $this->graph())->assertCreated()->json('id');
        return Flow::find($id);
    }

    protected function inbound(array $msg, string $from = '919811000001', string $pnid = '7001')
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '919000070001', 'phone_number_id' => $pnid],
            'contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => 'wamid.IN'.(++$this->seq), 'timestamp' => (string) time()] + $msg],
        ]]]]]];
        $raw = json_encode($payload);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw)->assertOk();
    }
    protected function text(string $t, ...$a) { return $this->inbound(['type' => 'text', 'text' => ['body' => $t]], ...$a); }
    protected function button(string $id, string $title) { return $this->inbound(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]]]); }
    protected function listReply(string $id, string $title) { return $this->inbound(['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => $title]]]); }

    /** @return array<array> bodies of messages sent to Meta, in order */
    protected function sent(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages'))->map(fn ($r) => $r->data())->values()->all();
    }

    public function test_max_ten_steps_enforced_by_api(): void
    {
        $nodes = [['id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0]]];
        for ($i = 1; $i <= 11; $i++) $nodes[] = ['id' => "m$i", 'type' => 'message', 'position' => ['x' => 0, 'y' => 0], 'data' => ['text' => "Step $i"]];
        $base = ['phone_number_id' => $this->na->id, 'name' => 'Big', 'trigger_type' => 'contains', 'keywords' => ['go']];
        $this->admin()->postJson('/api/flows', $base + ['nodes' => $nodes, 'edges' => []])->assertStatus(422)->assertJsonValidationErrors('nodes');
        $this->assertSame(0, Flow::count());
        $id = $this->admin()->postJson('/api/flows', $base + ['nodes' => array_slice($nodes, 0, 11), 'edges' => []])->assertCreated()->json('id');
        $this->admin()->getJson('/api/flows')->assertJsonPath('0.steps', 10);
        $nodes[] = ['id' => 'm12', 'type' => 'question', 'position' => ['x' => 0, 'y' => 0], 'data' => ['text' => 'q', 'options' => [['id' => 'a', 'title' => 'A']]]];
        $this->admin()->putJson("/api/flows/$id", ['nodes' => array_values(array_filter($nodes, fn ($n) => $n['id'] !== 'm11'))])->assertStatus(422)->assertJsonValidationErrors('nodes');
        // the simulator uses the same validation
        $this->admin()->postJson('/api/flows-simulate', ['flow' => ['trigger_type' => 'contains', 'keywords' => ['go'], 'nodes' => $nodes, 'edges' => []], 'text' => 'go'])->assertStatus(422);
    }

    public function test_keyword_trigger_sends_message_and_interactive_buttons(): void
    {
        $f = $this->makeFlow();
        AutoReplyRule::create(['match_type' => 'contains', 'trigger' => 'hi', 'reply' => 'old auto reply', 'enabled' => true]);
        $this->text('machine wash?');   // "hi" inside a word does not trigger the flow; no auto-reply match either ("hi" contains rule would!)
        $this->assertSame(0, $f->fresh()->triggered_count);
        Http::fake(['graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.X'.bin2hex(random_bytes(4))]]])]);

        $this->text('HI there');
        $s = $this->sent();
        $this->assertCount(2, $s, 'flow replies only; the old auto-reply is skipped');
        $this->assertSame('image', $s[0]['type']);
        $this->assertSame('https://cdn.example.com/banner.jpg', $s[0]['image']['link']);
        $this->assertSame('Welcome to Oswal Soap!', $s[0]['image']['caption']);
        // Meta interactive reply-button payload shape
        $this->assertSame('interactive', $s[1]['type']);
        $this->assertSame('button', $s[1]['interactive']['type']);
        $this->assertSame('How can we help?', $s[1]['interactive']['body']['text']);
        $this->assertSame([
            ['type' => 'reply', 'reply' => ['id' => "f{$f->id}.q1.o1", 'title' => 'Order status']],
            ['type' => 'reply', 'reply' => ['id' => "f{$f->id}.q1.o2", 'title' => 'Products']],
            ['type' => 'reply', 'reply' => ['id' => "f{$f->id}.q1.o3", 'title' => 'Support']],
        ], $s[1]['interactive']['action']['buttons']);
        $this->assertSame('individual', $s[1]['recipient_type']);
        $this->assertSame(1, $f->fresh()->triggered_count);
        $this->assertDatabaseHas('flow_sessions', ['flow_id' => $f->id, 'contact_wa_id' => '919811000001', 'status' => 'active', 'current_node_id' => 'q1']);
        $this->assertDatabaseHas('messages', ['type' => 'interactive', 'source' => 'flow', 'direction' => 'out']);

        // inactive flows don't trigger; the old auto-reply works again
        $this->admin()->putJson("/api/flows/{$f->id}", ['is_active' => false])->assertOk();
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.AR']]])]);
        $this->text('hi', '919811000009');
        $this->assertSame('old auto reply', $this->sent()[0]['text']['body']);
    }

    public function test_button_reply_branches_to_next_node_and_completes(): void
    {
        $f = $this->makeFlow();
        $this->text('menu');
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.B']]])]);
        $this->button("f{$f->id}.q1.o1", 'Order status');
        $s = $this->sent();
        $this->assertCount(1, $s);
        $this->assertSame('Your order is on the way.', $s[0]['text']['body']);
        $this->assertDatabaseHas('flow_sessions', ['flow_id' => $f->id, 'status' => 'completed', 'current_node_id' => null]);
        $this->assertSame(1, $f->fresh()->completed_count);
        $this->admin()->getJson("/api/flows/{$f->id}/stats")->assertJson(['triggered' => 1, 'completed' => 1, 'active_sessions' => 0]);
    }

    public function test_list_message_for_more_than_three_options_and_list_reply(): void
    {
        $f = $this->makeFlow();
        $this->text('menu');
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.L']]])]);
        $this->button("f{$f->id}.q1.o2", 'Products');
        $list = $this->sent()[0]['interactive'];
        $this->assertSame('list', $list['type']);
        $this->assertSame('See ranges', $list['action']['button']);
        $this->assertCount(5, $list['action']['sections'][0]['rows']);
        $this->assertSame(['id' => "f{$f->id}.q2.l2", 'title' => 'Laundry soap'], $list['action']['sections'][0]['rows'][1]);
        $this->assertArrayNotHasKey('buttons', $list['action']);
        $this->assertDatabaseHas('flow_sessions', ['flow_id' => $f->id, 'current_node_id' => 'q2', 'status' => 'active']);

        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.L2']]])]);
        $this->listReply("f{$f->id}.q2.l2", 'Laundry soap');
        $this->assertSame('Laundry soap: Rs 30 per bar.', $this->sent()[0]['text']['body']);
        $this->assertSame(1, $f->fresh()->completed_count);
    }

    public function test_typed_text_matches_options_by_keyword_and_condition_branches(): void
    {
        $f = $this->makeFlow();
        $this->text('hi');
        // unrecognised reply: re-ask (retry text + the question again), session keeps waiting
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.R']]])]);
        $this->text('blah');
        $s = $this->sent();
        $this->assertCount(2, $s);
        $this->assertStringContainsString('choose one of the options', $s[0]['text']['body']);
        $this->assertSame('button', $s[1]['interactive']['type']);
        // option keyword ("track") typed as text
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.T']]])]);
        $this->text('Please TRACK my parcel');
        $this->assertSame('Your order is on the way.', $this->sent()[0]['text']['body']);

        // option title typed + condition on that reply ("urgent" -> match branch)
        $this->text('hi', '919811000002');
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.U']]])]);
        $this->text('support, urgent please', '919811000002');
        $this->assertSame('Connecting you to an agent now.', $this->sent()[0]['text']['body']);
        // option number typed + condition no match
        $this->text('hi', '919811000003');
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.V']]])]);
        $this->text('3', '919811000003');
        $this->assertSame('We will reply within a day.', $this->sent()[0]['text']['body']);
        // a significant word of one option title ("status" -> "Order status")
        $this->text('hi', '919811000005');
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.W2']]])]);
        $this->text("what's the status?", '919811000005');
        $this->assertSame('Your order is on the way.', $this->sent()[0]['text']['body']);
        $this->assertSame(4, $f->fresh()->completed_count);
    }

    public function test_session_times_out_after_24_hours(): void
    {
        $f = $this->makeFlow();
        $this->text('hi');
        $this->travel(25)->hours();
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.E']]])]);
        $this->button("f{$f->id}.q1.o1", 'Order status');
        $this->assertCount(0, $this->sent(), 'expired session: no branch is followed');
        $this->assertDatabaseHas('flow_sessions', ['flow_id' => $f->id, 'status' => 'expired']);
        $this->assertSame(0, $f->fresh()->completed_count);
        // a new trigger starts a fresh session
        $this->text('menu');
        $this->assertSame(1, FlowSession::where('status', 'active')->count());
        $this->assertSame(2, $f->fresh()->triggered_count);
    }

    public function test_flows_only_run_for_their_own_number(): void
    {
        $this->makeFlow();
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.N']]])]);
        $this->text('hi', '919811000004', '7002');
        $this->assertCount(0, $this->sent());
        $this->assertSame(0, FlowSession::count());
    }

    public function test_client_access_control(): void
    {
        $f = $this->makeFlow();
        $g = $this->graph();
        // client A sees and edits flows on its own number; client B can't see or touch them
        $this->as($this->a)->getJson('/api/client/flows')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Main menu');
        $this->as($this->a)->getJson('/api/client/flow-numbers')->assertJsonCount(1)->assertJsonPath('0.id', $this->na->id);
        $this->as($this->b)->getJson('/api/client/flows')->assertJsonCount(0);
        $this->as($this->b)->getJson('/api/client/flow-numbers')->assertJsonCount(1)->assertJsonPath('0.id', $this->nb->id);
        $this->as($this->b)->getJson("/api/client/flows/{$f->id}")->assertNotFound();
        $this->as($this->b)->putJson("/api/client/flows/{$f->id}", ['name' => 'pwned'])->assertNotFound();
        $this->as($this->b)->postJson("/api/client/flows/{$f->id}/duplicate")->assertNotFound();
        $this->as($this->b)->deleteJson("/api/client/flows/{$f->id}")->assertNotFound();
        $this->as($this->b)->getJson("/api/client/flows/{$f->id}/stats")->assertNotFound();
        $this->as($this->b)->postJson('/api/client/flows', ['phone_number_id' => $this->na->id, 'name' => 'x', 'trigger_type' => 'exact', 'keywords' => ['x']] + $g)->assertNotFound();
        $this->as($this->b)->getJson('/api/flows')->assertForbidden(); // admin API
        $this->assertSame('Main menu', $f->fresh()->name);

        $this->as($this->a)->putJson("/api/client/flows/{$f->id}", ['name' => 'Menu v2'])->assertOk()->assertJsonPath('name', 'Menu v2');
        $id = $this->as($this->b)->postJson('/api/client/flows', ['phone_number_id' => $this->nb->id, 'name' => 'Beta flow', 'trigger_type' => 'exact', 'keywords' => ['start']] + $g)
            ->assertCreated()->assertJsonPath('client_id', $this->b->id)->assertJsonPath('is_active', false)->json('id');
        $copy = $this->as($this->b)->postJson("/api/client/flows/$id/duplicate")->assertCreated()->assertJsonPath('name', 'Beta flow (copy)')->assertJsonPath('is_active', false)->json('id');
        $this->as($this->b)->getJson('/api/client/flows')->assertJsonCount(2);
        $this->admin()->getJson('/api/flows')->assertJsonCount(3);
        $this->admin()->getJson("/api/flows?phone_number_id={$this->nb->id}")->assertJsonCount(2);
        $this->as($this->b)->deleteJson("/api/client/flows/$copy")->assertNoContent();
    }

    public function test_activation_requires_keywords_and_a_connected_trigger(): void
    {
        $base = ['phone_number_id' => $this->na->id, 'name' => 'x', 'trigger_type' => 'contains', 'is_active' => true];
        $this->admin()->postJson('/api/flows', $base + ['keywords' => []] + $this->graph())->assertStatus(422)->assertJsonValidationErrors('keywords');
        $g = $this->graph(); $g['edges'] = array_values(array_filter($g['edges'], fn ($e) => $e['source'] !== 'trigger'));
        $this->admin()->postJson('/api/flows', $base + ['keywords' => ['go']] + $g)->assertStatus(422)->assertJsonValidationErrors('edges');
        $bad = $this->graph(); $bad['nodes'][2]['data']['options'][0]['title'] = str_repeat('x', 21); // button titles max 20
        $this->admin()->postJson('/api/flows', $base + ['keywords' => ['go']] + $bad)->assertStatus(422)->assertJsonValidationErrors('nodes.2.options.0');
    }

    public function test_simulator_runs_draft_without_sending(): void
    {
        $draft = ['trigger_type' => 'starts_with', 'keywords' => ['order']] + $this->graph();
        $this->as($this->a)->postJson('/api/client/flows-simulate', ['flow' => $draft, 'text' => 'hello'])->assertOk()->assertJsonPath('event', 'no_trigger');
        $r = $this->as($this->a)->postJson('/api/client/flows-simulate', ['flow' => $draft, 'text' => 'Order please'])->assertOk()
            ->assertJsonPath('event', 'start')->assertJsonPath('waiting', 'q1')->json();
        $this->assertSame(['message', 'question'], array_column($r['outputs'], 'kind'));
        $this->assertSame('button', $r['outputs'][1]['interactive']['type']);
        $this->as($this->a)->postJson('/api/client/flows-simulate', ['flow' => $draft, 'waiting' => 'q1', 'interactive_id' => 'f0.q1.o2'])
            ->assertJsonPath('event', 'reply')->assertJsonPath('waiting', 'q2')->assertJsonPath('outputs.0.interactive.type', 'list');
        $this->as($this->a)->postJson('/api/client/flows-simulate', ['flow' => $draft, 'waiting' => 'q2', 'text' => 'laundry'])
            ->assertJsonPath('completed', true)->assertJsonPath('outputs.0.text', 'Laundry soap: Rs 30 per bar.');
        Http::assertNothingSent();
    }

    public function test_webhook_node_posts_contact_data(): void
    {
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.W']]]), 'hooks.example.com/*' => Http::response(['ok' => true])]);
        $nodes = [['id' => 'trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'w1', 'type' => 'webhook', 'position' => ['x' => 0, 'y' => 0], 'data' => ['url' => 'https://hooks.example.com/lead']],
            ['id' => 'm1', 'type' => 'message', 'position' => ['x' => 0, 'y' => 0], 'data' => ['text' => 'Thanks, we got it']]];
        $this->admin()->postJson('/api/flows', ['phone_number_id' => $this->na->id, 'name' => 'Lead', 'trigger_type' => 'exact', 'keywords' => ['quote'], 'is_active' => true,
            'nodes' => $nodes, 'edges' => [['source' => 'trigger', 'target' => 'w1'], ['source' => 'w1', 'target' => 'm1']]])->assertCreated();
        $this->text('Quote');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.example.com/lead' && $r['contact'] === '919811000001' && $r['last_input'] === 'Quote');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages') && ($r['text']['body'] ?? null) === 'Thanks, we got it');
    }
}
