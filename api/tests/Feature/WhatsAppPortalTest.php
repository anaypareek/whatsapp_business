<?php
namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AutoReplyRule;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppPortalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected PhoneNumber $num;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'secret-pass-123', 'is_admin' => true]);
        foreach (['app_id' => '123', 'app_secret' => 'shh-secret', 'access_token' => 'EAAtoken', 'waba_id' => '999', 'verify_token' => 'verify-me'] as $k => $v) Setting::put($k, $v);
        $this->num = PhoneNumber::create(['phone_number_id' => '1111', 'display_number' => '+91 98765 43210', 'name' => 'Oswal Soap']);
        Http::fake([
            'graph.facebook.com/*/1111/media' => Http::response(['id' => 'MEDIA123']),
            'graph.facebook.com/*/1111/messages' => fn () => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '919000000001', 'wa_id' => '919000000001']], 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(8))]]]),
            'graph.facebook.com/*/4444/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401),
            'graph.facebook.com/*/999/phone_numbers*' => Http::response(['data' => [
                ['id' => '1111', 'display_phone_number' => '+91 98765 43210', 'verified_name' => 'Oswal Soap', 'quality_rating' => 'GREEN'],
                ['id' => '2222', 'display_phone_number' => '+91 90000 00002', 'verified_name' => 'Oswal Support', 'quality_rating' => 'GREEN'],
            ]]),
            'graph.facebook.com/*/2222/register' => Http::response(['success' => true]),
        ]);
    }

    protected function auth() { return $this->withToken($this->admin->createToken('t')->plainTextToken); }

    public function test_login_and_me(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@test.local', 'password' => 'wrong'])->assertStatus(422);
        $token = $this->postJson('/api/auth/login', ['email' => 'admin@test.local', 'password' => 'secret-pass-123'])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('is_admin', true);
        $this->app['auth']->forgetGuards();
        $this->withToken('nope')->getJson('/api/phone-numbers')->assertStatus(401);
    }

    public function test_admin_user_management_and_non_admin_forbidden(): void
    {
        $id = $this->auth()->postJson('/api/users', ['name' => 'Op', 'email' => 'op@test.local', 'password' => 'password123'])->assertCreated()->json('id');
        $this->auth()->getJson('/api/users')->assertOk()->assertJsonCount(2);
        $op = User::find($id);
        $this->app['auth']->forgetGuards();
        $this->withToken($op->createToken('t')->plainTextToken)->getJson('/api/users')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->auth()->deleteJson("/api/users/$id")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $id]);
    }

    public function test_number_crud_and_sync_and_register(): void
    {
        $id = $this->auth()->postJson('/api/phone-numbers', ['phone_number_id' => '3333', 'display_number' => '+91 1', 'name' => 'X'])->assertCreated()->assertJsonPath('waba_id', '999')->json('id');
        $this->auth()->putJson("/api/phone-numbers/$id", ['name' => 'Renamed'])->assertOk()->assertJsonPath('name', 'Renamed');
        $this->auth()->deleteJson("/api/phone-numbers/$id")->assertNoContent();
        $this->auth()->postJson('/api/phone-numbers-sync')->assertOk()->assertJsonPath('synced', 2);
        $this->assertDatabaseHas('phone_numbers', ['phone_number_id' => '2222', 'name' => 'Oswal Support']);
        $p2 = PhoneNumber::where('phone_number_id', '2222')->first();
        $this->auth()->postJson("/api/phone-numbers/{$p2->id}/register", ['pin' => '123456'])->assertOk()->assertJsonPath('success', true);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/2222/register') && $r['pin'] === '123456');
    }

    public function test_send_text_and_image(): void
    {
        $this->auth()->postJson('/api/messages/send', ['phone_number_id' => $this->num->id, 'to' => '+91 90000 00001', 'text' => 'Hello'])
            ->assertCreated()->assertJsonPath('status', 'sent');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/1111/messages') && $r['type'] === 'text' && $r['text']['body'] === 'Hello' && $r->hasHeader('Authorization', 'Bearer EAAtoken'));

        Storage::fake('public');
        $this->auth()->post('/api/messages/send', ['phone_number_id' => $this->num->id, 'to' => '919000000001', 'text' => 'Pic', 'file' => UploadedFile::fake()->create('a.jpg', 20, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('type', 'image')->assertJsonPath('media_id', 'MEDIA123');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/1111/messages') && $r['type'] === 'image' && $r['image']['id'] === 'MEDIA123' && $r['image']['caption'] === 'Pic');

        $this->auth()->post('/api/messages/send', ['phone_number_id' => $this->num->id, 'to' => '919000000001', 'file' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('type', 'document');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/1111/messages') && $r['type'] === 'document' && $r['document']['filename'] === 'inv.pdf');
    }

    public function test_meta_error_is_recorded(): void
    {
        $bad = PhoneNumber::create(['phone_number_id' => '4444', 'display_number' => '4444']);
        $this->auth()->postJson('/api/messages/send', ['phone_number_id' => $bad->id, 'to' => '919000000001', 'text' => 'x'])
            ->assertStatus(502)->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('Invalid OAuth', Message::latest('id')->first()->error);
    }

    public function test_webhook_verification(): void
    {
        $this->get('/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=CH4LL')->assertOk()->assertSeeText('CH4LL');
        $this->get('/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=bad&hub.challenge=CH4LL')->assertForbidden();
    }

    protected function inbound(string $text, string $wamid = 'wamid.IN1'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '919876543210', 'phone_number_id' => '1111'],
            'contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => '919000000001']],
            'messages' => [['from' => '919000000001', 'id' => $wamid, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    protected function postSigned(array $payload, ?string $secret = 'shh-secret')
    {
        $raw = json_encode($payload);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
        ], $raw);
    }

    public function test_inbound_webhook_signature_storage_and_auto_reply(): void
    {
        AutoReplyRule::create(['match_type' => 'contains', 'trigger' => 'price', 'reply' => 'Our price list: ...', 'enabled' => true]);
        AutoReplyRule::create(['match_type' => 'exact', 'trigger' => 'hi', 'reply' => 'disabled', 'enabled' => false]);

        $this->postSigned($this->inbound('What is the PRICE?'), 'wrong')->assertStatus(401);
        $this->postSigned($this->inbound('What is the PRICE?'))->assertOk()->assertJsonPath('messages', 1)->assertJsonPath('auto_replies', 1);
        $this->postSigned($this->inbound('What is the PRICE?'))->assertOk()->assertJsonPath('messages', 0); // dedupe retry

        $this->assertDatabaseHas('messages', ['wamid' => 'wamid.IN1', 'direction' => 'in', 'contact_name' => 'Ravi', 'body' => 'What is the PRICE?']);
        $this->assertDatabaseHas('messages', ['direction' => 'out', 'source' => 'auto_reply', 'body' => 'Our price list: ...', 'status' => 'sent']);
        Http::assertSent(fn (HttpRequest $r) => ($r['text']['body'] ?? null) === 'Our price list: ...' && $r['to'] === '919000000001');

        $this->postSigned($this->inbound('hi', 'wamid.IN2'))->assertJsonPath('auto_replies', 0); // disabled rule

        $conv = $this->auth()->getJson('/api/conversations')->assertOk()->json();
        $this->assertCount(1, $conv);
        $this->auth()->getJson("/api/messages/thread?phone_number_id={$this->num->id}&contact=919000000001")->assertOk()->assertJsonCount(3);
    }

    public function test_rule_matching_types_and_per_number_scope(): void
    {
        $r = new AutoReplyRule(['match_type' => 'keyword', 'trigger' => 'order, status']);
        $this->assertTrue($r->matches('my ORDER please'));
        $this->assertFalse($r->matches('reorder'));
        $this->assertTrue((new AutoReplyRule(['match_type' => 'exact', 'trigger' => 'Hi']))->matches(' hi '));
        $this->assertFalse((new AutoReplyRule(['match_type' => 'exact', 'trigger' => 'Hi']))->matches('hi there'));

        $other = PhoneNumber::create(['phone_number_id' => '5555', 'display_number' => '5555']);
        AutoReplyRule::create(['phone_number_id' => $other->id, 'match_type' => 'contains', 'trigger' => 'hello', 'reply' => 'x']);
        $this->postSigned($this->inbound('hello'))->assertJsonPath('auto_replies', 0);
    }

    public function test_status_webhook_updates_message(): void
    {
        $m = Message::create(['phone_number_id' => $this->num->id, 'contact_wa_id' => '919000000001', 'direction' => 'out', 'wamid' => 'wamid.S1', 'status' => 'sent']);
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1111', 'display_phone_number' => '919876543210'],
            'statuses' => [['id' => 'wamid.S1', 'status' => 'delivered', 'timestamp' => (string) time(), 'recipient_id' => '919000000001'],
                ['id' => 'wamid.S1', 'status' => 'read', 'timestamp' => (string) time(), 'recipient_id' => '919000000001']],
        ]]]]]];
        $this->postSigned($payload)->assertOk()->assertJsonPath('statuses', 2);
        $this->assertSame('read', $m->fresh()->status);
        $this->assertDatabaseCount('message_statuses', 2);
    }

    public function test_api_key_endpoint(): void
    {
        $plain = $this->auth()->postJson('/api/api-keys', ['name' => 'Oswal orders'])->assertCreated()->json('plain_key');
        $this->postJson('/api/v1/messages/send', ['from' => '1111', 'to' => '919000000001', 'text' => 'x'])->assertStatus(401);
        $this->withToken('wab_bad')->postJson('/api/v1/messages/send', ['from' => '1111', 'to' => '919000000001', 'text' => 'x'])->assertStatus(401);

        $this->withToken($plain)->postJson('/api/v1/messages/send', ['from' => '+91 98765 43210', 'to' => '919000000001', 'text' => 'New order #1001'])
            ->assertCreated()->assertJsonPath('success', true);
        $this->withToken($plain)->postJson('/api/v1/messages/send', ['from_number' => '1111', 'to' => '919000000001', 'text' => 'Invoice', 'media_url' => 'https://example.com/inv.pdf'])
            ->assertCreated();
        Http::assertSent(fn (HttpRequest $r) => ($r['document']['link'] ?? null) === 'https://example.com/inv.pdf');
        $this->withToken($plain)->postJson('/api/v1/messages/send', ['from' => 'nope', 'to' => '919000000001', 'text' => 'x'])->assertStatus(422);
        $this->assertNotNull(ApiKey::first()->last_used_at);
        $this->assertDatabaseHas('messages', ['source' => 'api', 'body' => 'New order #1001']);
    }

    public function test_settings_encrypted_and_masked(): void
    {
        $this->auth()->putJson('/api/settings', ['access_token' => 'NEWTOKEN1234', 'app_secret' => '', 'waba_id' => '777'])->assertOk()
            ->assertJsonPath('access_token', '••••1234')->assertJsonPath('waba_id', '777');
        $raw = \DB::table('settings')->where('key', 'access_token')->value('value');
        $this->assertStringNotContainsString('NEWTOKEN', $raw);
        $this->assertSame('shh-secret', Setting::get('app_secret')); // blank kept
    }
}
