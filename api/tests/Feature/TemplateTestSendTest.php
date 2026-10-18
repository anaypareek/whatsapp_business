<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\ErrorLog;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\TemplateRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TemplateTestSendTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected PhoneNumber $num;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        foreach (['access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        $this->client = Client::create(['name' => 'Acme', 'email' => 'acme@c.t', 'password' => 'password123']);
        $this->num = PhoneNumber::create(['phone_number_id' => '1111', 'display_number' => '+91 98765 43210', 'client_id' => $this->client->id]);
        Http::fake([
            'graph.facebook.com/*/1111/messages' => function (HttpRequest $r) {
                return $r['to'] === '919000000000'
                    ? Http::response(['error' => ['message' => '(#131030) Recipient phone number not in allowed list', 'code' => 131030, 'error_data' => ['details' => 'Recipient phone number not in allowed list: Add recipient phone number to recipient list and try again.'], 'fbtrace_id' => 'X']], 400)
                    : Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => $r['to'], 'wa_id' => $r['to']]], 'messages' => [['id' => 'wamid.TEST123']]]);
            },
            'graph.facebook.com/*/999/message_templates' => fn () => Http::response(['id' => 'NEW'.random_int(1, 1e9), 'status' => 'PENDING', 'category' => 'UTILITY']),
            'graph.facebook.com/*/M1' => Http::response(['success' => true]),
        ]);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function asClient(Client $c) { $this->app['auth']->forgetGuards(); return $this->withToken($c->createToken('c')->plainTextToken); }

    protected function tpl(array $components, array $x = []): MessageTemplate
    {
        return MessageTemplate::create($x + ['name' => 'order_update', 'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED', 'meta_id' => 'M1', 'components' => $components]);
    }

    public function test_test_send_fills_demo_values_for_every_component(): void
    {
        $t = $this->tpl([
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Hi {{1}}, order {{2}} ships on {{3}}.'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'OK'], ['type' => 'URL', 'text' => 'Track', 'url' => 'https://x.test/t/{{1}}']]],
        ]);
        $this->admin()->getJson("/api/templates/{$t->id}/test")->assertOk()->assertJsonPath('can_send', true)
            ->assertJsonPath('params.body', ['Demo 1', 'Demo 2', 'Demo 3'])->assertJsonPath('params.buttons.1', 'demo');
        Http::assertNothingSent();

        $res = $this->admin()->postJson("/api/templates/{$t->id}/test", ['phone_number_id' => $this->num->id, 'to' => '+91 98111 22233'])
            ->assertOk()->assertJsonPath('sent', true)->assertJsonPath('wamid', 'wamid.TEST123');

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/1111/messages')) return false;
            $c = collect($r['template']['components']);
            $h = $c->firstWhere('type', 'header'); $b = $c->firstWhere('type', 'body'); $btn = $c->firstWhere('type', 'button');
            return $r['to'] === '919811122233' && $r['type'] === 'template' && $r['template']['name'] === 'order_update' && $r['template']['language']['code'] === 'en_US'
                && $h['parameters'][0]['type'] === 'image' && str_starts_with($h['parameters'][0]['image']['link'], 'https://')
                && array_column($b['parameters'], 'text') === ['Demo 1', 'Demo 2', 'Demo 3']
                && $btn['sub_type'] === 'url' && $btn['index'] === '1' && $btn['parameters'][0]['text'] === 'demo';
        });
        $m = Message::find($res->json('message_id'));
        $this->assertSame('sent', $m->status);
        $this->assertSame('test', $m->source);
        $this->assertSame($t->id, $m->message_template_id);
    }

    public function test_named_params_text_header_document_and_otp(): void
    {
        $t = $this->tpl([['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hello {{customer}}'], ['type' => 'BODY', 'text' => 'Order {{order_id}} for {{customer}}']]);
        $this->admin()->postJson("/api/templates/{$t->id}/test", ['phone_number_id' => $this->num->id, 'to' => '919811122233'])->assertOk();
        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/messages')) return false;
            $c = collect($r['template']['components']);
            return $c->firstWhere('type', 'header')['parameters'][0] == ['type' => 'text', 'text' => 'Demo', 'parameter_name' => 'customer']
                && $c->firstWhere('type', 'body')['parameters'] == [['type' => 'text', 'parameter_name' => 'order_id', 'text' => 'Order id'], ['type' => 'text', 'parameter_name' => 'customer', 'text' => 'Customer']];
        });

        $doc = MessageTemplate::create(['name' => 'invoice', 'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED', 'meta_id' => 'M2',
            'components' => [['type' => 'HEADER', 'format' => 'DOCUMENT'], ['type' => 'BODY', 'text' => 'Your invoice']]]);
        $p = $this->admin()->getJson("/api/templates/{$doc->id}/test")->json('components');
        $this->assertSame('document', $p[0]['parameters'][0]['type']);
        $this->assertStringEndsWith('.pdf', $p[0]['parameters'][0]['document']['filename']);

        $otp = MessageTemplate::create(['name' => 'otp', 'language' => 'en_US', 'category' => 'AUTHENTICATION', 'status' => 'APPROVED', 'meta_id' => 'M3',
            'components' => [['type' => 'BODY', 'text' => '*{{1}}* is your verification code.'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy code']]]]]);
        $c = $this->admin()->getJson("/api/templates/{$otp->id}/test")->json('components');
        $this->assertSame('123456', $c[0]['parameters'][0]['text']);
        $this->assertSame(['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '123456']]], $c[1]);
    }

    public function test_meta_error_is_returned_logged_and_stored(): void
    {
        $t = $this->tpl([['type' => 'BODY', 'text' => 'Hello']]);
        $res = $this->admin()->postJson("/api/templates/{$t->id}/test", ['phone_number_id' => $this->num->id, 'to' => '+91 90000 00000'])
            ->assertStatus(502)->assertJsonPath('sent', false)->assertJsonPath('error_code', 'META_131030');
        $this->assertStringContainsString('Recipient phone number not in allowed list', $res->json('message'));
        $this->assertSame('meta', ErrorLog::find($res->json('log_id'))->source);
        $this->assertSame('failed', Message::find($res->json('message_id'))->status);
    }

    public function test_only_approved_and_validation(): void
    {
        $t = $this->tpl([['type' => 'BODY', 'text' => 'Hello']], ['status' => 'PENDING']);
        $this->admin()->getJson("/api/templates/{$t->id}/test")->assertJsonPath('can_send', false);
        $this->admin()->postJson("/api/templates/{$t->id}/test", ['phone_number_id' => $this->num->id, 'to' => '919811122233'])->assertStatus(422);
        $t->update(['status' => 'APPROVED']);
        $this->admin()->postJson("/api/templates/{$t->id}/test", ['phone_number_id' => $this->num->id, 'to' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->admin()->postJson("/api/templates/{$t->id}/test", ['phone_number_id' => $this->num->id, 'to' => '12345'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_client_can_test_own_templates_from_own_numbers_only(): void
    {
        $other = Client::create(['name' => 'Other', 'email' => 'o@c.t', 'password' => 'password123']);
        $otherNum = PhoneNumber::create(['phone_number_id' => '2222', 'display_number' => '+91 2', 'client_id' => $other->id]);
        $mine = $this->tpl([['type' => 'BODY', 'text' => 'Hi {{1}}']], ['client_id' => $this->client->id]);
        $theirs = MessageTemplate::create(['name' => 'x', 'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED', 'meta_id' => 'M9', 'components' => [], 'client_id' => $other->id]);
        $this->asClient($this->client)->postJson("/api/client/templates/{$mine->id}/test", ['phone_number_id' => $this->num->id, 'to' => '919811122233'])->assertOk()->assertJsonPath('sent', true);
        $this->asClient($this->client)->postJson("/api/client/templates/{$mine->id}/test", ['phone_number_id' => $otherNum->id, 'to' => '919811122233'])->assertForbidden();
        $this->asClient($this->client)->getJson("/api/client/templates/{$theirs->id}/test")->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_client_assignment_on_create_edit_filter_and_request_approval(): void
    {
        $body = ['name' => 'welcome_msg', 'language' => 'en_US', 'category' => 'UTILITY', 'body' => ['text' => 'Welcome!'], 'client_id' => $this->client->id];
        $id = $this->admin()->postJson('/api/templates', $body)->assertCreated()->assertJsonPath('client.name', 'Acme')->json('id');
        $this->admin()->postJson('/api/templates', ['name' => 'internal_msg'] + $body + [])->assertCreated();
        MessageTemplate::where('name', 'internal_msg')->update(['client_id' => null]);

        $this->admin()->getJson('/api/templates?client_id='.$this->client->id)->assertJsonCount(1)->assertJsonPath('0.name', 'welcome_msg');
        $this->admin()->getJson('/api/templates?client_id=none')->assertJsonCount(1)->assertJsonPath('0.name', 'internal_msg');
        $this->admin()->getJson('/api/templates')->assertJsonCount(2);

        // edit (also allowed to change client) and the tag endpoint for synced templates
        $t = MessageTemplate::find($id); $t->update(['status' => 'APPROVED', 'meta_id' => 'M1']);
        $this->admin()->putJson("/api/templates/$id", ['body' => ['text' => 'Welcome again!'], 'client_id' => null])->assertOk()->assertJsonPath('client_id', null);
        $this->admin()->putJson("/api/templates/$id/client", ['client_id' => $this->client->id])->assertOk()->assertJsonPath('client.id', $this->client->id);
        $this->admin()->postJson('/api/templates', ['name' => 'bad_client', 'client_id' => 9999] + $body)->assertStatus(422);

        // tagged APPROVED templates show in that client's portal
        MessageTemplate::find($id)->update(['status' => 'APPROVED']);
        $this->asClient($this->client)->getJson('/api/client/templates')->assertJsonCount(1)->assertJsonPath('0.id', $id);

        // approving a client's request tags the template with that client
        $req = TemplateRequest::create(['client_id' => $this->client->id, 'name' => 'from_request', 'language' => 'en_US', 'category' => 'UTILITY',
            'form' => ['body' => ['text' => 'Hi']], 'components' => [['type' => 'BODY', 'text' => 'Hi']], 'status' => 'pending']);
        $this->admin()->postJson("/api/template-requests/{$req->id}/approve")->assertOk();
        $this->assertSame($this->client->id, MessageTemplate::where('name', 'from_request')->value('client_id'));
    }
}
