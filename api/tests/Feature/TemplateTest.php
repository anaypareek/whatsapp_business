<?php
namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\TemplateEdit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected PhoneNumber $num;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999', 'verify_token' => 'v'] as $k => $v) Setting::put($k, $v);
        $this->num = PhoneNumber::create(['phone_number_id' => '1111', 'display_number' => '+91 98765 43210']);
        Http::fake([
            'graph.facebook.com/*/999/message_templates?*' => fn (HttpRequest $r) => $r->method() === 'DELETE'
                ? Http::response(['success' => true])
                : Http::response(['data' => [
                    ['id' => 'T1', 'name' => 'order_update', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY', 'quality_score' => ['score' => 'GREEN'],
                        'components' => [['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order {{1}}'], ['type' => 'BODY', 'text' => 'Hi {{1}}, your order {{2}} is confirmed.'],
                            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Track', 'url' => 'https://oswal.example/t/{{1}}']]]]],
                    ['id' => 'T2', 'name' => 'promo', 'language' => 'hi', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'INVALID_FORMAT',
                        'components' => [['type' => 'BODY', 'text' => 'Sale!']]],
                ], 'paging' => ['cursors' => []]]),
            'graph.facebook.com/*/999/message_templates' => Http::response(['id' => 'T9', 'status' => 'PENDING', 'category' => 'UTILITY']),
            'graph.facebook.com/*/T*' => Http::response(['success' => true]),
            'graph.facebook.com/*/1111/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.T'.bin2hex(random_bytes(6))]]]),
        ]);
    }

    protected function auth() { return $this->withToken($this->admin->createToken('t')->plainTextToken); }

    protected function syncTemplates(): void { $this->auth()->postJson('/api/templates-sync')->assertOk()->assertJsonPath('synced', 2); }

    public function test_sync_and_filters(): void
    {
        $this->syncTemplates();
        $this->assertDatabaseHas('message_templates', ['meta_id' => 'T1', 'status' => 'APPROVED', 'quality_score' => 'GREEN']);
        $this->assertDatabaseHas('message_templates', ['meta_id' => 'T2', 'rejected_reason' => 'INVALID_FORMAT']);
        $this->auth()->getJson('/api/templates?status=REJECTED')->assertJsonCount(1)->assertJsonPath('0.name', 'promo');
        $this->auth()->getJson('/api/templates?category=UTILITY&q=order')->assertJsonCount(1)->assertJsonPath('0.body_variable_count', 2);
    }

    public function test_create_template_builds_components(): void
    {
        $this->auth()->postJson('/api/templates', [
            'name' => 'new_order', 'language' => 'en_US', 'category' => 'UTILITY',
            'header' => ['format' => 'TEXT', 'text' => 'Order {{1}}', 'example' => 'OS-1'],
            'body' => ['text' => 'Hello {{1}}, total {{2}}.', 'examples' => ['Ravi', '₹500']],
            'footer' => 'Oswal Soap', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Thanks'], ['type' => 'URL', 'text' => 'View', 'url' => 'https://x.example/{{1}}', 'example' => 'https://x.example/1']],
        ])->assertCreated()->assertJsonPath('meta_id', 'T9')->assertJsonPath('status', 'PENDING');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/999/message_templates')
            && $r['name'] === 'new_order' && $r['components'][1]['example']['body_text'] === [['Ravi', '₹500']]
            && $r['components'][0]['example']['header_text'] === ['OS-1'] && $r['components'][3]['buttons'][1]['example'] === ['https://x.example/1']);
    }

    public function test_create_validation(): void
    {
        $this->auth()->postJson('/api/templates', ['name' => 'Bad Name', 'language' => 'en_US', 'category' => 'UTILITY', 'body' => ['text' => 'x']])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->auth()->postJson('/api/templates', ['name' => 'a', 'language' => 'en_US', 'category' => 'UTILITY', 'body' => ['text' => 'Hi {{1}} there']])->assertStatus(422)->assertJsonValidationErrors('body.examples');
        $this->auth()->postJson('/api/templates', ['name' => 'a', 'language' => 'en_US', 'category' => 'UTILITY', 'body' => ['text' => 'Hi {{1}} and {{3}} x', 'examples' => ['a', 'b', 'c']]])->assertStatus(422)->assertJsonValidationErrors('body.text');
        $this->auth()->postJson('/api/templates', ['name' => 'a', 'language' => 'en_US', 'category' => 'UTILITY', 'header' => ['format' => 'IMAGE'], 'body' => ['text' => 'x']])->assertStatus(422)->assertJsonValidationErrors('header.example');
    }

    public function test_edit_limits(): void
    {
        $this->syncTemplates();
        $t = MessageTemplate::where('meta_id', 'T1')->first();
        $body = ['category' => 'UTILITY', 'header' => ['format' => 'TEXT', 'text' => 'Order {{1}}', 'example' => 'OS-1'], 'body' => ['text' => 'Hi {{1}}, order {{2}} confirmed!', 'examples' => ['A', 'B']]];
        $this->auth()->putJson("/api/templates/{$t->id}", ['category' => 'MARKETING'] + $body)->assertStatus(422); // can't change approved category
        $this->auth()->putJson("/api/templates/{$t->id}", $body)->assertOk()->assertJsonPath('status', 'PENDING');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/T1') && $r->method() === 'POST');
        // now PENDING -> not editable
        $this->auth()->putJson("/api/templates/{$t->id}", $body)->assertStatus(422)->assertJsonPath('edit_check.allowed', false);
        // approved again, but edited within 24h
        $t->refresh()->update(['status' => 'APPROVED']);
        $this->auth()->putJson("/api/templates/{$t->id}", $body)->assertStatus(422)->assertSee('once per 24 hours');
        // 10 edits in 30 days
        TemplateEdit::query()->update(['created_at' => now()->subDays(2)]);
        for ($i = 0; $i < 9; $i++) TemplateEdit::create(['message_template_id' => $t->id, 'status_at_edit' => 'APPROVED', 'created_at' => now()->subDays(3 + $i)]);
        $this->auth()->putJson("/api/templates/{$t->id}", $body)->assertStatus(422)->assertSee('10 times in 30 days');
        // rejected templates are editable regardless
        $r = MessageTemplate::where('meta_id', 'T2')->first();
        $this->assertTrue($r->edit_check['allowed']);
        $r->update(['status' => 'DISABLED']);
        $this->assertFalse($r->fresh()->edit_check['allowed']);
    }

    public function test_delete(): void
    {
        $this->syncTemplates();
        $t = MessageTemplate::where('meta_id', 'T2')->first();
        $this->auth()->deleteJson("/api/templates/{$t->id}")->assertNoContent();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'name=promo') && str_contains($r->url(), 'hsm_id=T2'));
        $this->assertDatabaseMissing('message_templates', ['id' => $t->id]);
    }

    protected function postSigned(array $payload)
    {
        $raw = json_encode($payload);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw);
    }

    public function test_status_quality_category_webhooks(): void
    {
        $this->syncTemplates();
        $wrap = fn ($field, $value) => ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'time' => time(), 'changes' => [['field' => $field, 'value' => $value]]]]];
        $this->postSigned($wrap('message_template_status_update', ['event' => 'REJECTED', 'message_template_id' => 'T1', 'message_template_name' => 'order_update', 'message_template_language' => 'en_US', 'reason' => 'INCORRECT_CATEGORY']))
            ->assertOk()->assertJsonPath('template_events', 1);
        $t = MessageTemplate::where('meta_id', 'T1')->first();
        $this->assertSame('REJECTED', $t->status);
        $this->assertSame('INCORRECT_CATEGORY', $t->rejected_reason);
        $this->postSigned($wrap('message_template_status_update', ['event' => 'APPROVED', 'message_template_id' => 'T1', 'message_template_name' => 'order_update', 'message_template_language' => 'en_US', 'reason' => 'NONE']));
        $this->assertSame('APPROVED', $t->fresh()->status);
        $this->assertNull($t->fresh()->rejected_reason);
        $this->postSigned($wrap('message_template_quality_update', ['previous_quality_score' => 'GREEN', 'new_quality_score' => 'YELLOW', 'message_template_id' => 'T1', 'message_template_name' => 'order_update', 'message_template_language' => 'en_US']));
        $this->assertSame('YELLOW', $t->fresh()->quality_score);
        $this->postSigned($wrap('template_category_update', ['message_template_id' => 'T1', 'message_template_name' => 'order_update', 'message_template_language' => 'en_US', 'previous_category' => 'UTILITY', 'new_category' => 'MARKETING']));
        $this->assertSame('MARKETING', $t->fresh()->category);
        $this->assertDatabaseCount('template_events', 4);
        $this->auth()->getJson("/api/templates/{$t->id}")->assertJsonCount(4, 'events');
    }

    public function test_send_template_from_portal(): void
    {
        $this->syncTemplates();
        $t = MessageTemplate::where('meta_id', 'T1')->first();
        $this->auth()->postJson('/api/messages/send-template', ['phone_number_id' => $this->num->id, 'to' => '919000000001', 'template_id' => $t->id,
            'params' => ['Ravi', 'OS-1001'], 'header_param' => 'OS-1001', 'button_params' => ['OS-1001']])
            ->assertCreated()->assertJsonPath('type', 'template')->assertJsonPath('body', "*Order OS-1001*\nHi Ravi, your order OS-1001 is confirmed.");
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/1111/messages') && $r['type'] === 'template'
            && $r['template']['name'] === 'order_update' && $r['template']['language']['code'] === 'en_US'
            && $r['template']['components'][0] === ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'OS-1001']]]
            && $r['template']['components'][1]['parameters'][1]['text'] === 'OS-1001'
            && $r['template']['components'][2] === ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'OS-1001']]]);
        // missing params
        $this->auth()->postJson('/api/messages/send-template', ['phone_number_id' => $this->num->id, 'to' => '919000000001', 'template_id' => $t->id, 'params' => ['Ravi']])
            ->assertStatus(422)->assertJsonValidationErrors(['params', 'header', 'button_params.0']);
        // rejected template can't be sent
        $rej = MessageTemplate::where('meta_id', 'T2')->first();
        $this->auth()->postJson('/api/messages/send-template', ['phone_number_id' => $this->num->id, 'to' => '919000000001', 'template_id' => $rej->id])
            ->assertStatus(422)->assertJsonValidationErrors('template');
    }

    public function test_send_template_via_api_key(): void
    {
        $this->syncTemplates();
        [, $key] = ApiKey::generate('k');
        $this->withToken($key)->postJson('/api/v1/messages/send', ['from' => '1111', 'to' => '919000000001', 'template' => 'order_update', 'language' => 'en_US',
            'params' => ['Ravi', 'OS-7'], 'header_param' => 'OS-7', 'button_params' => ['OS-7']])->assertCreated()->assertJsonPath('success', true);
        Http::assertSent(fn (HttpRequest $r) => ($r['template']['name'] ?? null) === 'order_update' && $r['template']['components'][1]['parameters'][0]['text'] === 'Ravi');
        // nested object form
        $this->withToken($key)->postJson('/api/v1/messages/send', ['from' => '1111', 'to' => '919000000001',
            'template' => ['name' => 'order_update', 'language' => 'en_US', 'params' => ['A', 'B'], 'header_param' => 'B', 'button_params' => ['B']]])->assertCreated();
        // multipart with pipe-separated params
        $this->withToken($key)->post('/api/v1/messages/send', ['from' => '1111', 'to' => '919000000001', 'template' => 'order_update',
            'params' => 'A|B', 'header_param' => 'B', 'button_params' => ['B']], ['Accept' => 'application/json'])->assertCreated();
        $this->withToken($key)->postJson('/api/v1/messages/send', ['from' => '1111', 'to' => '919000000001', 'template' => 'nope'])->assertStatus(422);
        $this->withToken($key)->postJson('/api/v1/messages/send', ['from' => '1111', 'to' => '919000000001', 'template' => 'promo'])->assertStatus(422);
    }
}
