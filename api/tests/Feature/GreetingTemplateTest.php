<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\GreetingGroup;
use App\Models\GreetingSetting;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GreetingTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $a;
    protected Client $b;
    protected PhoneNumber $na;
    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->a = Client::create(['name' => 'Alpha', 'email' => 'alpha@c.t', 'password' => 'password123']);
        $this->b = Client::create(['name' => 'Beta', 'email' => 'beta@c.t', 'password' => 'password123']);
        $this->na = PhoneNumber::create(['client_id' => $this->a->id, 'phone_number_id' => '7001', 'display_number' => '+91 90000 70001', 'waba_id' => '999']);
        Http::fake(['graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.T'.bin2hex(random_bytes(5))]]])]);
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function as(Client $c) { $this->app['auth']->forgetGuards(); return $this->withToken($c->createToken('c')->plainTextToken); }

    protected function tpl(string $name, string $body, array $over = [], array $extra = []): MessageTemplate
    {
        return MessageTemplate::create($over + ['name' => $name, 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'waba_id' => '999',
            'client_id' => $this->a->id, 'meta_id' => 'm'.$name, 'components' => array_merge($extra, [['type' => 'BODY', 'text' => $body]])]);
    }

    protected function inbound(string $from = '919811000001', ?string $name = 'Ravi')
    {
        $this->seq++;
        $contact = ['wa_id' => $from] + ($name === null ? [] : ['profile' => ['name' => $name]]);
        $p = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '919000070001', 'phone_number_id' => '7001'], 'contacts' => [$contact],
            'messages' => [['from' => $from, 'id' => 'wamid.IN'.$this->seq, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'hi']]],
        ]]]]]];
        $raw = json_encode($p);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw);
    }

    /** @return array<int, array> template payloads sent to Meta */
    protected function sentTemplates(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages') && ($r['type'] ?? '') === 'template')
            ->map(fn ($r) => $r['template'])->values()->all();
    }

    protected function bodyParams(array $t): array
    {
        $b = collect($t['components'] ?? [])->firstWhere('type', 'body');
        return array_map(fn ($p) => $p['text'], $b['parameters'] ?? []);
    }

    protected array $map2 = ['body' => [['type' => 'name', 'fallback' => 'Customer'], ['type' => 'fixed', 'value' => 'Oswal']]];

    public function test_template_greeting_with_name_mapping_and_fallback(): void
    {
        $t = $this->tpl('welcome_v1', 'Hi {{1}}, welcome to {{2}}!');
        $this->as($this->a)->putJson("/api/client/greetings/{$this->na->id}", ['enabled' => true, 'greeting_template_id' => $t->id, 'greeting_template_params' => $this->map2])->assertSuccessful();
        $this->assertFalse(GreetingSetting::first()->hours_enabled);

        $this->inbound()->assertOk()->assertJsonPath('greetings', 1);
        $sent = $this->sentTemplates();
        $this->assertCount(1, $sent);
        $this->assertSame('welcome_v1', $sent[0]['name']);
        $this->assertSame(['Ravi', 'Oswal'], $this->bodyParams($sent[0]));
        $m = Message::where('direction', 'out')->first();
        $this->assertSame(['template', 'greeting', true, $t->id], [$m->type, $m->source, (bool) $m->is_auto, $m->message_template_id]);
        $this->assertSame('Hi Ravi, welcome to Oswal!', $m->body);

        // contact without a profile name -> fallback text; {phone} token
        $s = GreetingSetting::first();
        $s->update(['greeting_template_params' => ['body' => [['type' => 'name', 'fallback' => 'Customer'], ['type' => 'phone']]]]);
        $this->inbound('919811000002', null)->assertJsonPath('greetings', 1);
        $this->assertSame(['Customer', '+919811000002'], $this->bodyParams($this->sentTemplates()[1]));
    }

    public function test_template_off_hours(): void
    {
        $t = $this->tpl('closed_v1', 'Sorry {{1}}, we are closed.');
        $this->admin()->putJson("/api/greetings/{$this->na->id}", ['enabled' => true, 'greeting_text' => 'Hello', 'hours_enabled' => true, 'hours_start' => '09:00', 'hours_end' => '10:00',
            'off_hours_template_id' => $t->id, 'off_hours_template_params' => ['body' => [['type' => 'name', 'fallback' => 'there']]]])->assertSuccessful();
        $this->inbound()->assertJsonPath('greetings', 1);
        $sent = $this->sentTemplates();
        $this->assertSame('closed_v1', $sent[0]['name']);
        $this->assertSame(['Ravi'], $this->bodyParams($sent[0]));
        $m = Message::where('direction', 'out')->first();
        $this->assertSame(['template', 'off_hours', true], [$m->type, $m->source, (bool) $m->is_auto]);
    }

    public function test_group_template_overrides_default_text(): void
    {
        $t = $this->tpl('vip_v1', 'Dear {{1}}, VIP desk here.');
        GreetingSetting::create(['phone_number_id' => $this->na->id, 'enabled' => true, 'greeting_text' => 'Default hello']);
        $this->as($this->a)->postJson("/api/client/greetings/{$this->na->id}/groups", ['name' => 'VIP', 'members' => ['919811000001'],
            'greeting_template_id' => $t->id, 'greeting_template_params' => ['body' => [['type' => 'name', 'fallback' => 'Sir']]]])->assertSuccessful();
        $this->inbound()->assertJsonPath('greetings', 1);
        $this->inbound('919811000009')->assertJsonPath('greetings', 1);
        $this->assertSame(['Ravi'], $this->bodyParams($this->sentTemplates()[0]));
        $this->assertCount(1, $this->sentTemplates());
        $this->assertSame(['template', 'text'], Message::where('direction', 'out')->orderBy('id')->pluck('type')->all());
    }

    public function test_rejects_unapproved_other_client_other_waba_and_count_mismatch(): void
    {
        $pending = $this->tpl('pending_v1', 'Hi {{1}}', ['status' => 'PENDING']);
        $other = $this->tpl('beta_v1', 'Hi {{1}}', ['client_id' => $this->b->id]);
        $waba = $this->tpl('waba_v1', 'Hi {{1}}', ['waba_id' => '888']);
        $ok = $this->tpl('ok_v1', 'Hi {{1}} from {{2}}');
        $url = "/api/client/greetings/{$this->na->id}";
        $one = ['body' => [['type' => 'fixed', 'value' => 'x']]];
        $this->as($this->a)->putJson($url, ['greeting_template_id' => $pending->id, 'greeting_template_params' => $one])->assertStatus(422)->assertJsonValidationErrors('greeting_template_id');
        $this->as($this->a)->putJson($url, ['greeting_template_id' => $other->id, 'greeting_template_params' => $one])->assertStatus(422)->assertJsonValidationErrors('greeting_template_id');
        $this->as($this->a)->putJson($url, ['off_hours_template_id' => $waba->id, 'off_hours_template_params' => $one])->assertStatus(422)->assertJsonValidationErrors('off_hours_template_id');
        // admin may pick any client's template, but still never an unapproved one
        $this->admin()->putJson("/api/greetings/{$this->na->id}", ['greeting_template_id' => $pending->id, 'greeting_template_params' => $one])->assertStatus(422);
        // count mismatch (1 mapped, 2 needed) and missing {name} fallback
        $this->as($this->a)->putJson($url, ['greeting_template_id' => $ok->id, 'greeting_template_params' => $one])->assertStatus(422)
            ->assertJsonValidationErrors('greeting_template_params')->assertJsonFragment(['greeting_template_params' => ["Template 'ok_v1' has 2 body variable(s); you mapped 1."]]);
        $this->as($this->a)->putJson($url, ['greeting_template_id' => $ok->id, 'greeting_template_params' => ['body' => [['type' => 'name'], ['type' => 'phone']]]])->assertStatus(422);
        $this->assertSame(0, GreetingSetting::count());
        // client picker lists only its own approved, same-WABA templates; another client's number is 404
        $this->as($this->a)->getJson("/api/client/greetings/{$this->na->id}/templates")->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'ok_v1');
        $nb = PhoneNumber::create(['client_id' => $this->b->id, 'phone_number_id' => '7002', 'display_number' => '+91 7002', 'waba_id' => '999']);
        $this->as($this->a)->getJson("/api/client/greetings/{$nb->id}/templates")->assertNotFound();
        $g = GreetingGroup::create(['phone_number_id' => $nb->id, 'name' => 'B']);
        $this->as($this->a)->putJson("/api/client/greeting-groups/{$g->id}", ['greeting_template_id' => $ok->id])->assertNotFound();
        $this->as($this->a)->putJson($url, ['enabled' => true, 'greeting_template_id' => $ok->id, 'greeting_template_params' => ['body' => [['type' => 'name', 'fallback' => 'Customer'], ['type' => 'fixed', 'value' => 'Us']]]])->assertSuccessful();
    }

    public function test_blocks_header_params_it_cannot_fill_and_maps_media_header(): void
    {
        $btn = $this->tpl('btn_v1', 'Hi', [], [['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Go', 'url' => 'https://x.test/{{1}}']]]]);
        $img = $this->tpl('img_v1', 'Hi {{1}}', [], [['type' => 'HEADER', 'format' => 'IMAGE']]);
        $url = "/api/client/greetings/{$this->na->id}";
        $this->as($this->a)->putJson($url, ['greeting_template_id' => $btn->id, 'greeting_template_params' => ['body' => []]])->assertStatus(422)->assertJsonValidationErrors('greeting_template_id');
        $body = ['body' => [['type' => 'name', 'fallback' => 'Customer']]];
        $this->as($this->a)->putJson($url, ['greeting_template_id' => $img->id, 'greeting_template_params' => $body])->assertStatus(422)
            ->assertJsonFragment(['greeting_template_params' => ["Template 'img_v1' has a IMAGE header: enter a public media URL for it."]]);
        $this->as($this->a)->putJson($url, ['enabled' => true, 'greeting_template_id' => $img->id, 'greeting_template_params' => $body + ['header' => ['type' => 'fixed', 'value' => 'https://cdn.test/w.jpg']]])->assertSuccessful();
        $this->inbound()->assertJsonPath('greetings', 1);
        $h = collect($this->sentTemplates()[0]['components'])->firstWhere('type', 'header');
        $this->assertSame('https://cdn.test/w.jpg', $h['parameters'][0]['image']['link']);
    }

    public function test_template_greeting_skipped_if_template_no_longer_approved(): void
    {
        $t = $this->tpl('welcome_v1', 'Hi {{1}}');
        GreetingSetting::create(['phone_number_id' => $this->na->id, 'enabled' => true, 'greeting_template_id' => $t->id, 'greeting_template_params' => ['body' => [['type' => 'phone']]]]);
        $t->update(['status' => 'PAUSED']);
        $this->inbound()->assertJsonPath('greetings', 0);
        $this->assertSame([], $this->sentTemplates());
    }
}
