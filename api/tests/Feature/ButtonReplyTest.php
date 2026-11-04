<?php
namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ButtonReplyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin; protected Client $c; protected PhoneNumber $num;
    protected MessageTemplate $a; protected MessageTemplate $b; protected MessageTemplate $follow;
    protected array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->c = Client::create(['name' => 'Alpha', 'email' => 'alpha@c.t', 'password' => 'password123']);
        $this->num = PhoneNumber::create(['phone_number_id' => '7001', 'display_number' => '+91 90909 00894', 'waba_id' => '999', 'client_id' => $this->c->id]);
        $yn = fn ($body) => [['type' => 'BODY', 'text' => $body], ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes'], ['type' => 'QUICK_REPLY', 'text' => 'No'], ['type' => 'URL', 'text' => 'Site', 'url' => 'https://x.y']]]];
        $mk = fn ($name, $comp, $client = null) => MessageTemplate::create(['meta_id' => 'M'.$name, 'waba_id' => '999', 'name' => $name, 'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => $comp, 'client_id' => $client]);
        $this->a = $mk('confirm_order', $yn('Confirm your order?'), $this->c->id);
        $this->b = $mk('want_catalog', $yn('Want our catalog?'), $this->c->id);
        $this->follow = $mk('thanks_name', [['type' => 'BODY', 'text' => 'Thanks {{1}}, order confirmed.']], $this->c->id);
        $mk('other_client_tpl', $yn('Hi'));
        Http::fake(['graph.facebook.com/*/7001/messages' => function (HttpRequest $r) { $this->sent[] = $r->data(); return Http::response(['messages' => [['id' => 'wamid.OUT'.count($this->sent)]]]); }]);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function client() { $this->app['auth']->forgetGuards(); return $this->withToken($this->c->createToken('c')->plainTextToken); }

    protected function outTemplate(MessageTemplate $t, string $wamid): void
    {
        Message::create(['phone_number_id' => $this->num->id, 'contact_wa_id' => '919811000001', 'direction' => 'out', 'type' => 'template', 'message_template_id' => $t->id,
            'template_name' => $t->name, 'wamid' => $wamid, 'status' => 'delivered', 'source' => 'manual']);
    }

    protected function tap(string $text, ?string $ctx, string $id, string $kind = 'button')
    {
        $m = ['from' => '919811000001', 'id' => $id, 'timestamp' => (string) time(), 'type' => $kind] + ($ctx ? ['context' => ['from' => '919090900894', 'id' => $ctx]] : []);
        $m += $kind === 'button' ? ['button' => ['text' => $text, 'payload' => $text]] : ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $text, 'title' => $text]]];
        $p = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '919090900894', 'phone_number_id' => '7001'], 'contacts' => [['wa_id' => '919811000001', 'profile' => ['name' => 'Ravi']]], 'messages' => [$m]]]]]]];
        $raw = json_encode($p);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw);
    }

    protected function configure(): void
    {
        $this->admin()->putJson("/api/button-replies/{$this->a->id}", ['replies' => [
            ['button_index' => 0, 'reply_type' => 'template', 'reply_template_id' => $this->follow->id, 'template_params' => ['body' => [['type' => 'name', 'fallback' => 'Customer']]]],
            ['button_index' => 1, 'reply_type' => 'text', 'text' => 'Okay {name}, order cancelled.'],
        ]])->assertOk()->assertJsonCount(2, 'replies');
        $this->client()->putJson("/api/client/button-replies/{$this->b->id}", ['replies' => [
            ['button_index' => 0, 'reply_type' => 'media', 'media_type' => 'document', 'media_url' => 'https://cdn.example.com/catalog.pdf', 'text' => 'Here is our catalog 📄'],
            ['button_index' => 1, 'reply_type' => 'text', 'text' => 'No problem!'],
        ]])->assertOk();
    }

    public function test_listing_and_scoping_both_portals(): void
    {
        $this->admin()->getJson('/api/button-replies')->assertOk()->assertJsonCount(3); // thanks_name has no buttons
        $r = $this->client()->getJson('/api/client/button-replies')->assertOk()->assertJsonCount(2);
        $this->assertSame([['index' => 0, 'text' => 'Yes'], ['index' => 1, 'text' => 'No']], $r->json('0.buttons'));
        $other = MessageTemplate::where('name', 'other_client_tpl')->first();
        $this->client()->getJson("/api/client/button-replies/{$other->id}")->assertNotFound();
        $this->client()->putJson("/api/client/button-replies/{$other->id}", ['replies' => []])->assertNotFound();
    }

    public function test_validation(): void
    {
        $this->admin()->putJson("/api/button-replies/{$this->a->id}", ['replies' => [['button_index' => 2, 'reply_type' => 'text', 'text' => 'x']]])->assertStatus(422)->assertJsonValidationErrors('replies.0.button_index');
        $this->admin()->putJson("/api/button-replies/{$this->a->id}", ['replies' => [['button_index' => 0, 'reply_type' => 'text', 'text' => ' ']]])->assertStatus(422)->assertJsonValidationErrors('replies.0.text');
        $this->admin()->putJson("/api/button-replies/{$this->a->id}", ['replies' => [['button_index' => 0, 'reply_type' => 'media', 'media_type' => 'image', 'media_url' => 'https://x.y/a.pdf']]])->assertStatus(422)->assertJsonValidationErrors('replies.0.media_url');
        $this->admin()->putJson("/api/button-replies/{$this->a->id}", ['replies' => [['button_index' => 0, 'reply_type' => 'template', 'reply_template_id' => $this->follow->id, 'template_params' => ['body' => []]]]])->assertStatus(422)->assertJsonValidationErrors('replies.0.reply_template_id');
        $this->a->update(['status' => 'PAUSED']);
        $this->admin()->putJson("/api/button-replies/{$this->a->id}", ['replies' => []])->assertStatus(422);
    }

    public function test_same_yes_no_buttons_get_different_follow_ups_by_context(): void
    {
        $this->configure();
        AutoReplyRule::create(['trigger' => 'yes', 'match_type' => 'exact', 'reply' => 'KEYWORD REPLY', 'enabled' => true, 'priority' => 1]);
        $this->outTemplate($this->a, 'wamid.A'); $this->outTemplate($this->b, 'wamid.B');

        $this->tap('Yes', 'wamid.A', 'wamid.in1')->assertOk();
        $this->assertCount(1, $this->sent); // exactly one reply, keyword rule not used
        $this->assertSame('template', $this->sent[0]['type']);
        $this->assertSame('thanks_name', $this->sent[0]['template']['name']);
        $this->assertSame('Ravi', $this->sent[0]['template']['components'][0]['parameters'][0]['text']);

        $this->tap('Yes', 'wamid.B', 'wamid.in2', 'interactive')->assertOk();
        $this->assertCount(2, $this->sent);
        $this->assertSame('document', $this->sent[1]['type']);
        $this->assertSame('https://cdn.example.com/catalog.pdf', $this->sent[1]['document']['link']);
        $this->assertSame('Here is our catalog 📄', $this->sent[1]['document']['caption']);

        $this->tap('No', 'wamid.A', 'wamid.in3')->assertOk();
        $this->assertSame('Okay Ravi, order cancelled.', $this->sent[2]['text']['body']);
        $out = Message::where('direction', 'out')->where('source', 'button')->get();
        $this->assertCount(3, $out);
        $this->assertTrue($out->every(fn ($m) => $m->is_auto));
    }

    public function test_fallback_without_context_uses_latest_template_sent_to_contact(): void
    {
        $this->configure();
        $this->outTemplate($this->a, 'wamid.A'); $this->outTemplate($this->b, 'wamid.B'); // B is newer
        $this->tap('No', null, 'wamid.in1')->assertOk();
        $this->assertSame('No problem!', $this->sent[0]['text']['body']);
        $this->tap('No', 'wamid.UNKNOWN', 'wamid.in2')->assertOk(); // unknown context: same fallback
        $this->assertSame('No problem!', $this->sent[1]['text']['body']);
    }

    public function test_template_without_configured_reply_falls_through_to_keywords(): void
    {
        $this->configure();
        AutoReplyRule::create(['trigger' => 'yes', 'match_type' => 'exact', 'reply' => 'KEYWORD REPLY', 'enabled' => true, 'priority' => 1]);
        $other = MessageTemplate::where('name', 'other_client_tpl')->first();
        $this->outTemplate($other, 'wamid.O');
        $this->tap('Yes', 'wamid.O', 'wamid.in1')->assertOk();
        $this->assertCount(1, $this->sent);
        $this->assertSame('KEYWORD REPLY', $this->sent[0]['text']['body']);
    }
}
