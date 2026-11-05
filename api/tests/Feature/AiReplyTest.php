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

class AiReplyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $c;
    protected PhoneNumber $n;
    protected int $seq = 0;
    protected string $aiAnswer = "Hi Ravi! 👋 I'm doing great, thanks for asking! 😊 How can I help you? 😊";
    protected int $aiStatus = 200;
    protected const FROM = '919811000001';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999', 'ai_api_key' => 'sk-test-SECRET1234', 'ai_base_url' => 'https://llm.test/v1'] as $k => $v) Setting::put($k, $v);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->c = Client::create(['name' => 'Oswal', 'email' => 'o@c.t', 'password' => 'password123', 'ai_block_when_empty' => false]);
        $this->n = PhoneNumber::create(['client_id' => $this->c->id, 'phone_number_id' => '7001', 'display_number' => '+91 90909 00894', 'name' => 'Oswal']);
        Http::fake([
            'llm.test/*' => fn () => Http::response($this->aiStatus === 200 ? ['choices' => [['message' => ['role' => 'assistant', 'content' => $this->aiAnswer]]]] : ['error' => 'boom'], $this->aiStatus),
            'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.O'.bin2hex(random_bytes(5))]]]),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }

    protected function hook(array $value)
    {
        $p = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '919090900894', 'phone_number_id' => '7001']] + $value]]]]];
        $raw = json_encode($p);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw);
    }

    protected function text(string $t, string $from = self::FROM, ?int $ts = null)
    {
        $this->seq++;
        return $this->hook(['contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => 'wamid.IN'.$this->seq, 'timestamp' => (string) ($ts ?? now()->timestamp), 'type' => 'text', 'text' => ['body' => $t]]]]);
    }

    protected function sent(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages'))->map(fn ($r) => $r['text']['body'] ?? '?')->values()->all();
    }

    protected function aiCalls() { return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), 'llm.test'))->values(); }

    protected function enable(array $over = []): AiReplySetting
    {
        return AiReplySetting::create(['phone_number_id' => $this->n->id, 'enabled' => true, 'allowlist' => [self::FROM], 'knowledge_base' => 'Basmati rice 5kg: Rs 650.'] + $over);
    }

    public function test_off_by_default_and_test_mode_default_on(): void
    {
        $this->text('hi')->assertOk()->assertJsonPath('ai', 0);
        $this->assertSame([], $this->sent());
        $s = AiReplySetting::create(['phone_number_id' => $this->n->id]);
        $this->assertFalse($s->fresh()->enabled);
        $this->assertTrue($s->fresh()->test_mode);
        $this->admin()->getJson('/api/ai-replies')->assertOk()->assertJsonPath('numbers.0.settings.enabled', false)->assertJsonPath('provider.configured', true);
    }

    public function test_small_talk_reply_is_auto_labelled_ai_with_short_history(): void
    {
        $this->enable();
        $this->text('hi, how are you?')->assertOk()->assertJsonPath('ai', 1);
        $this->assertSame([$this->aiAnswer], $this->sent());
        $out = Message::where('direction', 'out')->first();
        $this->assertSame('ai', $out->source);
        $this->assertTrue((bool) $out->is_auto);
        $req = $this->aiCalls()->first();
        $this->assertSame('https://llm.test/v1/chat/completions', $req->url());
        $this->assertSame('Bearer sk-test-SECRET1234', $req->header('Authorization')[0]);
        $this->assertSame('gpt-4o-mini', $req['model']);
        $this->assertSame(200, $req['max_tokens']);
        $this->assertStringContainsString('How can I help you? 😊', $req['messages'][0]['content']);
        $this->assertStringContainsString('Never invent facts', $req['messages'][0]['content']);
        $this->assertStringContainsString('Basmati rice 5kg: Rs 650.', $req['messages'][0]['content']);
        $this->assertSame(['role' => 'user', 'content' => 'hi, how are you?'], $req['messages'][1]);
        for ($i = 0; $i < 8; $i++) Message::create(['phone_number_id' => $this->n->id, 'contact_wa_id' => self::FROM, 'direction' => 'in', 'type' => 'text', 'body' => "old $i", 'status' => 'received']);
        $this->text('thanks');
        $this->assertCount(1 + 6, $this->aiCalls()->last()['messages']); // system + last 6
    }

    public function test_precedence_keyword_and_greeting_win_no_double_reply(): void
    {
        $this->enable();
        AutoReplyRule::create(['match_type' => 'contains', 'trigger' => 'price', 'reply' => 'See our price list', 'enabled' => true]);
        $this->text('price please')->assertJsonPath('auto_replies', 1)->assertJsonPath('ai', 0);
        $this->assertSame(['See our price list'], $this->sent());
        GreetingSetting::create(['phone_number_id' => $this->n->id, 'enabled' => true, 'greeting_text' => 'Welcome!']);
        $this->text('hello')->assertJsonPath('greetings', 1)->assertJsonPath('ai', 0);
        $this->assertSame(['See our price list', 'Welcome!'], $this->sent());
        $this->assertCount(0, $this->aiCalls());
        // greeting cooldown applies now -> AI answers the next message, once
        $this->text('how are you')->assertJsonPath('greetings', 0)->assertJsonPath('ai', 1);
        $this->assertCount(3, $this->sent());
        $this->assertSame(1, Message::where('source', 'ai')->count());
    }

    public function test_outside_24h_window_skipped(): void
    {
        $this->enable();
        $this->text('hi', ts: now()->subHours(25)->timestamp)->assertJsonPath('ai', 0);
        $this->assertSame([], $this->sent());
        $this->assertCount(0, $this->aiCalls());
    }

    public function test_rate_limit_per_contact_and_global(): void
    {
        $this->enable(['max_per_hour' => 2]);
        foreach (['hi', 'hey', 'yo'] as $t) $this->text($t);
        $this->assertSame(2, Message::where('source', 'ai')->count());
        Carbon::setTestNow(now()->addMinutes(61));
        $this->text('back')->assertJsonPath('ai', 1);
        Setting::put('ai_global_max_per_hour', '3');
        AiReplySetting::first()->update(['test_mode' => false, 'max_per_hour' => 10]);
        $this->text('hi', '919811000002')->assertJsonPath('ai', 1);
        $this->text('hi', '919811000003')->assertJsonPath('ai', 1);
        $this->text('hi', '919811000004')->assertJsonPath('ai', 0); // 3 in the last hour globally
        $this->assertSame(5, Message::where('source', 'ai')->count());
    }

    public function test_allowlist_in_test_mode(): void
    {
        $this->enable();
        $this->text('hi', '919811999999')->assertJsonPath('ai', 0);
        $this->assertSame([], $this->sent());
        $this->text('hi')->assertJsonPath('ai', 1);
        AiReplySetting::first()->update(['test_mode' => false]);
        $this->text('hi', '919811999999')->assertJsonPath('ai', 1);
    }

    public function test_loop_prevention(): void
    {
        $this->enable(['test_mode' => false]);
        // statuses only
        $this->hook(['statuses' => [['id' => 'wamid.X', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp, 'recipient_id' => self::FROM]]])->assertJsonPath('ai', 0);
        // message from a business number we own (echo / number-to-number)
        PhoneNumber::create(['phone_number_id' => '7002', 'display_number' => '+91 90000 70002']);
        $this->text('hi', '919000070002')->assertJsonPath('ai', 0);
        $this->text('hi', '919090900894')->assertJsonPath('ai', 0);
        // non-text (image) is ignored
        $this->seq++;
        $this->hook(['messages' => [['from' => self::FROM, 'id' => 'wamid.IMG', 'timestamp' => (string) now()->timestamp, 'type' => 'image', 'image' => ['id' => 'm1']]]])->assertJsonPath('ai', 0);
        // never reply to an is_auto message
        $auto = Message::create(['phone_number_id' => $this->n->id, 'contact_wa_id' => self::FROM, 'direction' => 'in', 'type' => 'text', 'body' => 'x', 'is_auto' => true, 'status' => 'received']);
        $this->assertSame('not_text', app(\App\Services\AiReplyService::class)->skipReason($this->n, $auto));
        $this->assertSame([], $this->sent());
        $this->assertCount(0, $this->aiCalls());
    }

    public function test_handoff_flags_conversation_and_pauses_until_human_or_cooldown(): void
    {
        $this->enable(['handoff_cooldown_hours' => 24, 'human_active_quiet_minutes' => 0]);
        $this->aiAnswer = '[HANDOFF]';
        $this->text('Do you deliver to Pune tomorrow?')->assertJsonPath('ai', 1);
        $this->assertSame([\App\Services\AiReplyService::HANDOFF_TEXT], $this->sent());
        $this->assertTrue(AiContactState::first()->needs_human);
        $this->admin()->getJson('/api/conversations')->assertJsonPath('0.needs_human', true);
        $this->admin()->getJson('/api/ai-replies')->assertJsonPath('numbers.0.handoffs.0.contact_wa_id', self::FROM);
        // smart continue: known answer during cooldown is sent; needs_human stays
        $this->aiAnswer = 'Our number is +91 90909 00894 😊';
        $this->text('Number do')->assertJsonPath('ai', 1);
        $this->assertSame([\App\Services\AiReplyService::HANDOFF_TEXT, 'Our number is +91 90909 00894 😊'], $this->sent());
        $this->assertTrue(AiContactState::first()->needs_human);
        // unknown follow-up during cooldown: silent, no second handoff text
        $before = count($this->sent());
        $this->aiAnswer = '[HANDOFF]';
        $this->text('price of gold?')->assertJsonPath('ai', 1);
        $this->assertCount($before, $this->sent());
        $this->assertTrue(\App\Models\AiOpenQuestion::where('question', 'price of gold?')->where('status', 'open')->exists());
        // staff replies manually -> flag cleared, AI resumes (quiet minutes = 0)
        app(\App\Services\MessagingService::class)->send($this->n, self::FROM, 'Yes we do, Rs 50', source: 'manual');
        $this->assertFalse(AiContactState::first()->needs_human);
        $this->admin()->getJson('/api/conversations')->assertJsonPath('0.needs_human', false);
        $this->aiAnswer = 'Hello! 😊';
        $this->text('great thanks')->assertJsonPath('ai', 1);
        // after a fresh handoff, cooldown must expire before another handoff message is sent
        $this->aiAnswer = '[HANDOFF]';
        $this->text('discount?');
        $this->assertTrue(AiContactState::first()->needs_human);
        $handoffs = count(array_filter($this->sent(), fn ($b) => $b === \App\Services\AiReplyService::HANDOFF_TEXT));
        $this->assertSame(2, $handoffs);
        $this->text('still?')->assertJsonPath('ai', 1); // silent re-handoff
        $this->assertSame(2, count(array_filter($this->sent(), fn ($b) => $b === \App\Services\AiReplyService::HANDOFF_TEXT)));
        Carbon::setTestNow(now()->addHours(25));
        $this->text('anyone?')->assertJsonPath('ai', 1); // cooldown over → new handoff message
        $this->assertSame(3, count(array_filter($this->sent(), fn ($b) => $b === \App\Services\AiReplyService::HANDOFF_TEXT)));
    }

    public function test_handoff_sends_connecting_text_and_one_push(): void
    {
        $this->enable(['human_active_quiet_minutes' => 0]);
        \App\Models\DeviceToken::create(['client_id' => $this->c->id, 'token' => 'tok-'.str_repeat('a', 20), 'platform' => 'android']);
        \App\Models\UserDeviceToken::create(['user_id' => $this->admin->id, 'token' => 'staff-'.str_repeat('b', 20)]);
        $fcm = \Mockery::mock(\App\Services\FcmService::class)->makePartial();
        $fcm->shouldReceive('sendToClient')->once()->withArgs(fn ($cid, $title, $body, $data) => $cid === $this->c->id
            && str_starts_with($title, 'Needs human:')
            && $data['type'] === 'needs_human'
            && $data['contact'] === self::FROM)->andReturn(['sent' => 1]);
        $fcm->shouldReceive('sendToStaff')->once()->withArgs(fn ($title, $body, $data) => $data['type'] === 'needs_human')->andReturn(['sent' => 1]);
        $this->app->instance(\App\Services\FcmService::class, $fcm);
        $this->aiAnswer = '[HANDOFF]';
        $this->text('Do you deliver to Pune?')->assertJsonPath('ai', 1);
        $this->assertSame([\App\Services\AiReplyService::HANDOFF_TEXT], $this->sent());
        // second unknown question: no second push, no second connecting text
        $this->text('and discount?')->assertJsonPath('ai', 1);
        $this->assertSame([\App\Services\AiReplyService::HANDOFF_TEXT], $this->sent());
    }

    public function test_handoff_uses_hindi_text_and_custom_override(): void
    {
        $this->enable([
            'human_active_quiet_minutes' => 0,
            'handoff_text' => 'Connecting you now, please wait.',
            'handoff_text_hi' => 'Team se connect kar rahe hain, wait karein 🙏',
        ]);
        $this->aiAnswer = '[HANDOFF]';
        $this->text('Number do')->assertJsonPath('ai', 1); // hinglish → hi text
        $this->assertSame(['Team se connect kar rahe hain, wait karein 🙏'], $this->sent());
        AiContactState::query()->update(['needs_human' => false]);
        Carbon::setTestNow(now()->addHours(25));
        $this->text('Do you deliver tomorrow?')->assertJsonPath('ai', 1);
        $this->assertSame(['Team se connect kar rahe hain, wait karein 🙏', 'Connecting you now, please wait.'], $this->sent());
    }

    public function test_human_active_keeps_ai_quiet(): void
    {
        $this->enable(['human_active_quiet_minutes' => 30]);
        $this->aiAnswer = 'Hi! 😊';
        $this->text('hello')->assertJsonPath('ai', 1);
        app(\App\Services\MessagingService::class)->send($this->n, self::FROM, 'Staff here', source: 'manual');
        $this->text('are you there?')->assertJsonPath('ai', 0);
        $this->assertSame(1, Message::where('source', 'ai')->count());
        Carbon::setTestNow(now()->addMinutes(31));
        $this->text('hello again')->assertJsonPath('ai', 1);
        $this->assertSame(2, Message::where('source', 'ai')->count());
    }

    public function test_provider_failure_is_graceful_and_key_never_exposed(): void
    {
        $this->enable();
        $this->aiStatus = 500;
        $this->text('hi')->assertOk();
        $this->assertSame(0, Message::where('source', 'ai')->count());
        $s = $this->admin()->getJson('/api/settings')->assertOk();
        $this->assertStringNotContainsString('sk-test-SECRET1234', $s->getContent());
        $s->assertJsonPath('ai_api_key', '••••1234')->assertJsonPath('ai_api_key_set', true)->assertJsonPath('ai_defaults.model', 'gpt-4o-mini');
        $this->admin()->putJson('/api/settings', ['ai_api_key' => '••••1234', 'ai_model' => 'gpt-4.1-mini'])->assertOk();
        $this->assertSame('sk-test-SECRET1234', Setting::get('ai_api_key'));
        $this->assertSame('gpt-4.1-mini', Setting::get('ai_model'));
        $this->assertStringNotContainsString('SECRET1234', (string) @file_get_contents(storage_path('logs/laravel.log')));
    }

    public function test_ai_settings_are_admin_only(): void
    {
        $this->app['auth']->forgetGuards();
        $tok = $this->c->createToken('c')->plainTextToken;
        $this->withToken($tok)->getJson('/api/client/ai-replies')->assertNotFound();
        $this->withToken($tok)->putJson("/api/client/ai-replies/{$this->n->id}", ['enabled' => true])->assertNotFound();
        $this->withToken($tok)->postJson("/api/client/ai-replies/{$this->n->id}/resolve", ['contact' => self::FROM])->assertNotFound();
        $this->assertSame(0, AiReplySetting::count());
        $this->withToken($tok)->getJson('/api/client/conversations')->assertOk(); // needs_human badge data still harmless
        $this->admin()->putJson("/api/ai-replies/{$this->n->id}", ['enabled' => true, 'allowlist' => ['+91 98110 00001']])
            ->assertSuccessful()->assertJsonPath('allowlist.0', '919811000001')->assertJsonPath('test_mode', true);
    }
}
