<?php
namespace Tests\Feature;

use App\Models\AiContactState;
use App\Models\AiReplySetting;
use App\Models\AutoReplyRule;
use App\Models\Client;
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

/**
 * Manual per-chat takeover: while taken_over, ALL automatic replies are silent
 * (AI incl. trained/KB answers, keywords, greeting, flows, button replies).
 * Manual outbound clears needs_human only — never taken_over.
 * Release ("Give to AI") clears takeover + handoff so AI resumes.
 */
class AiTakeoverTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $c;
    protected Client $other;
    protected PhoneNumber $n;
    protected PhoneNumber $otherN;
    protected int $seq = 0;
    protected string $aiAnswer = 'Basmati rice 5kg is Rs 650 😊';
    protected const FROM = '919811000001';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999', 'ai_api_key' => 'sk-test-SECRET1234', 'ai_base_url' => 'https://llm.test/v1'] as $k => $v) {
            Setting::put($k, $v);
        }
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->c = Client::create(['name' => 'Oswal', 'email' => 'o@c.t', 'password' => 'password123', 'ai_block_when_empty' => false]);
        $this->other = Client::create(['name' => 'Other', 'email' => 'x@c.t', 'password' => 'password123', 'ai_block_when_empty' => false]);
        $this->n = PhoneNumber::create(['client_id' => $this->c->id, 'phone_number_id' => '7001', 'display_number' => '+91 90909 00894', 'name' => 'Oswal']);
        $this->otherN = PhoneNumber::create(['client_id' => $this->other->id, 'phone_number_id' => '7002', 'display_number' => '+91 90000 70002', 'name' => 'Other']);
        Http::fake([
            'llm.test/*' => fn () => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $this->aiAnswer]]]]),
            'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.O'.bin2hex(random_bytes(5))]]]),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 12:00', 'Asia/Kolkata'));
        AiReplySetting::create([
            'phone_number_id' => $this->n->id, 'enabled' => true, 'allowlist' => [self::FROM],
            'knowledge_base' => 'Basmati rice 5kg: Rs 650.', 'human_active_quiet_minutes' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function asAdmin()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    }

    protected function asClient(Client $c)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($c->createToken('t')->plainTextToken);
    }

    protected function hook(array $value)
    {
        $p = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '919090900894', 'phone_number_id' => '7001']] + $value]]]]];
        $raw = json_encode($p);

        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw);
    }

    protected function text(string $t, string $from = self::FROM)
    {
        $this->seq++;

        return $this->hook(['contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => 'wamid.IN'.$this->seq, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $t]]]]);
    }

    protected function sent(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages'))
            ->map(fn ($r) => $r['text']['body'] ?? '?')->values()->all();
    }

    protected function aiCalls()
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), 'llm.test'))->values();
    }

    public function test_takeover_silences_ai_including_trained_answers(): void
    {
        $this->text('price of basmati?')->assertJsonPath('ai', 1);
        $this->assertNotEmpty($this->sent());
        $this->assertCount(1, $this->aiCalls());

        $this->asAdmin()->postJson('/api/conversations/takeover', [
            'phone_number_id' => $this->n->id, 'contact' => self::FROM,
        ])->assertOk()->assertJsonPath('taken_over', true)->assertJsonPath('needs_human', false);

        $st = AiContactState::first();
        $this->assertTrue($st->taken_over);
        $this->assertSame($this->admin->id, $st->taken_over_by_id);
        $this->assertSame(User::class, $st->taken_over_by_type);

        $before = count($this->sent());
        $this->aiAnswer = 'Basmati rice 5kg is Rs 650 😊'; // trained / KB answer
        $this->text('price of basmati again?')->assertOk()->assertJsonPath('ai', 0)->assertJsonPath('auto_replies', 0)->assertJsonPath('greetings', 0);
        $this->assertCount($before, $this->sent());
        $this->assertCount(1, $this->aiCalls()); // no new LLM call
        $this->assertSame('taken_over', app(\App\Services\AiReplyService::class)->skipReason(
            $this->n, Message::where('direction', 'in')->latest('id')->first()
        ));
    }

    public function test_takeover_silences_keyword_and_greeting_auto_replies(): void
    {
        AutoReplyRule::create(['match_type' => 'contains', 'trigger' => 'price', 'reply' => 'See our price list', 'enabled' => true, 'phone_number_id' => $this->n->id]);
        GreetingSetting::create(['phone_number_id' => $this->n->id, 'enabled' => true, 'greeting_text' => 'Welcome!']);
        AiContactState::takeOver($this->n->id, self::FROM, $this->admin);

        $this->text('price please')->assertJsonPath('auto_replies', 0)->assertJsonPath('ai', 0)->assertJsonPath('greetings', 0);
        $this->text('hello there')->assertJsonPath('greetings', 0)->assertJsonPath('ai', 0);
        $this->assertSame([], $this->sent());
    }

    public function test_manual_send_does_not_release_takeover(): void
    {
        AiContactState::takeOver($this->n->id, self::FROM, $this->admin);
        // also set needs_human to prove manual send still clears that flag only
        AiContactState::where('phone_number_id', $this->n->id)->where('contact_wa_id', self::FROM)
            ->update(['needs_human' => true, 'handoff_at' => now()]);

        app(\App\Services\MessagingService::class)->send($this->n, self::FROM, 'Staff here', source: 'manual');
        $st = AiContactState::first();
        $this->assertTrue($st->taken_over);
        $this->assertFalse($st->needs_human);

        $before = count($this->sent());
        $this->text('still there?')->assertJsonPath('ai', 0);
        $this->assertCount($before, $this->sent()); // only the staff Graph send recorded before; inbound triggers none
        $this->assertTrue(AiContactState::first()->taken_over);
    }

    public function test_release_resumes_ai_and_clears_handoff(): void
    {
        AiContactState::updateOrCreate(
            ['phone_number_id' => $this->n->id, 'contact_wa_id' => self::FROM],
            ['taken_over' => true, 'taken_over_at' => now(), 'needs_human' => true, 'handoff_at' => now(), 'reason' => 'old']
        );
        $this->asAdmin()->postJson('/api/conversations/release', [
            'phone_number_id' => $this->n->id, 'contact' => self::FROM,
        ])->assertOk()->assertJsonPath('taken_over', false)->assertJsonPath('needs_human', false);

        $st = AiContactState::first();
        $this->assertFalse($st->taken_over);
        $this->assertFalse($st->needs_human);
        $this->assertNull($st->handoff_at);
        $this->assertNull($st->reason);

        $this->aiAnswer = 'Hi again! 😊';
        $this->text('hello')->assertJsonPath('ai', 1);
        $this->assertSame(['Hi again! 😊'], $this->sent());
    }

    public function test_conversations_and_thread_expose_taken_over(): void
    {
        Message::create(['phone_number_id' => $this->n->id, 'contact_wa_id' => self::FROM, 'contact_name' => 'Ravi',
            'direction' => 'in', 'type' => 'text', 'body' => 'hi', 'status' => 'received']);
        AiContactState::takeOver($this->n->id, self::FROM, $this->admin);

        $this->asAdmin()->getJson('/api/conversations')->assertOk()
            ->assertJsonPath('0.taken_over', true)->assertJsonPath('0.needs_human', false);
        $this->asAdmin()->getJson('/api/messages/thread?phone_number_id='.$this->n->id.'&contact='.self::FROM)
            ->assertOk()->assertJsonPath('taken_over', true)->assertJsonCount(1, 'messages');

        $this->asClient($this->c)->getJson('/api/client/conversations')->assertOk()->assertJsonPath('0.taken_over', true);
        $this->asClient($this->c)->getJson('/api/client/messages/thread?phone_number_id='.$this->n->id.'&contact='.self::FROM)
            ->assertOk()->assertJsonPath('taken_over', true);
    }

    public function test_client_isolation_cannot_takeover_other_clients_number(): void
    {
        $this->asClient($this->other)->postJson('/api/client/conversations/takeover', [
            'phone_number_id' => $this->n->id, 'contact' => self::FROM,
        ])->assertNotFound();
        $this->assertFalse(AiContactState::isTakenOver($this->n->id, self::FROM));

        $this->asClient($this->c)->postJson('/api/client/conversations/takeover', [
            'phone_number_id' => $this->n->id, 'contact' => self::FROM,
        ])->assertOk()->assertJsonPath('taken_over', true);
        $st = AiContactState::first();
        $this->assertSame($this->c->id, $st->taken_over_by_id);
        $this->assertSame(Client::class, $st->taken_over_by_type);

        $this->asClient($this->other)->postJson('/api/client/conversations/release', [
            'phone_number_id' => $this->n->id, 'contact' => self::FROM,
        ])->assertNotFound();
        $this->assertTrue(AiContactState::isTakenOver($this->n->id, self::FROM));
    }

    public function test_takeover_clears_needs_human_badge(): void
    {
        AiContactState::updateOrCreate(
            ['phone_number_id' => $this->n->id, 'contact_wa_id' => self::FROM],
            ['needs_human' => true, 'handoff_at' => now(), 'reason' => 'unknown']
        );
        Message::create(['phone_number_id' => $this->n->id, 'contact_wa_id' => self::FROM, 'direction' => 'in', 'type' => 'text', 'body' => 'x', 'status' => 'received']);
        $this->asAdmin()->getJson('/api/conversations')->assertJsonPath('0.needs_human', true)->assertJsonPath('0.taken_over', false);
        $this->asAdmin()->postJson('/api/conversations/takeover', ['phone_number_id' => $this->n->id, 'contact' => self::FROM])->assertOk();
        $this->asAdmin()->getJson('/api/conversations')->assertJsonPath('0.needs_human', false)->assertJsonPath('0.taken_over', true);
    }
}
