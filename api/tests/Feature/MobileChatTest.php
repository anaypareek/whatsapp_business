<?php
namespace Tests\Feature;

use App\Http\Controllers\Api\ClientChatController;
use App\Models\Client;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileChatTest extends TestCase
{
    use RefreshDatabase;

    protected Client $a; protected Client $b; protected PhoneNumber $na; protected PhoneNumber $nb;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['access_token' => 'EAAtoken', 'waba_id' => '999', 'app_secret' => 'shh'] as $k => $v) Setting::put($k, $v);
        $this->a = Client::create(['name' => 'Alpha', 'email' => 'alpha@c.t', 'password' => 'password123']);
        $this->b = Client::create(['name' => 'Beta', 'email' => 'beta@c.t', 'password' => 'password123']);
        $this->na = PhoneNumber::create(['phone_number_id' => '1111', 'display_number' => '+91 1', 'client_id' => $this->a->id, 'status' => 'CONNECTED']);
        $this->nb = PhoneNumber::create(['phone_number_id' => '2222', 'display_number' => '+91 2', 'client_id' => $this->b->id, 'status' => 'CONNECTED']);
        foreach ([[$this->na, '919000000001', 'Ravi'], [$this->na, '919000000002', 'Sita'], [$this->nb, '919000000009', 'Secret']] as [$n, $c, $name]) {
            Message::create(['phone_number_id' => $n->id, 'contact_wa_id' => $c, 'contact_name' => $name, 'direction' => 'in', 'body' => "hi from $name", 'status' => 'received']);
        }
        Cache::flush();
    }

    protected function as(Client $c) { $this->app['auth']->forgetGuards(); return $this->withToken($c->createToken('m')->plainTextToken); }

    protected function inbound(string $from, string $text, string $pnid = '1111', string $name = 'Ravi')
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $pnid, 'display_phone_number' => '+91 1'],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
            'messages' => [['from' => $from, 'id' => 'wamid.'.uniqid(), 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]]]]]]]]);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw);
    }

    public function test_mobile_login_returns_token(): void
    {
        $this->postJson('/api/client/auth/login', ['email' => 'alpha@c.t', 'password' => 'password123', 'device_name' => 'Pixel 8'])->assertOk()->assertJsonStructure(['token', 'client' => ['id', 'name']]);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'mobile:Pixel 8']);
        $this->postJson('/api/client/auth/login', ['email' => 'alpha@c.t', 'password' => 'nope'])->assertStatus(422);
        User::create(['name' => 'Admin', 'email' => 'ad@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->postJson('/api/client/auth/login', ['email' => 'ad@t.l', 'password' => 'password123'])->assertStatus(422); // staff can't use the app
    }

    public function test_conversations_and_thread_are_scoped_to_own_numbers(): void
    {
        $res = $this->as($this->a)->getJson('/api/client/chat/conversations')->assertOk()->assertJsonCount(2, 'conversations')->assertJsonPath('unread_total', 2)->assertJsonCount(1, 'numbers');
        $this->assertStringNotContainsString('Secret', $res->getContent());
        $this->as($this->a)->getJson('/api/client/chat/conversations?phone_number_id='.$this->nb->id)->assertJsonCount(0, 'conversations');
        $this->as($this->a)->getJson("/api/client/chat/thread?phone_number_id={$this->nb->id}&contact=919000000009")->assertNotFound();
        $this->as($this->a)->postJson('/api/client/chat/read', ['phone_number_id' => $this->nb->id, 'contact' => '919000000009'])->assertNotFound();
        $other = Message::where('phone_number_id', $this->nb->id)->first();
        $this->as($this->a)->get("/api/client/chat/media/{$other->id}")->assertNotFound();
        $this->as($this->a)->postJson('/api/client/chat/send', ['phone_number_id' => $this->nb->id, 'to' => '919000000009', 'text' => 'x'])->assertNotFound();
        // sync never leaks another client's messages
        $sync = $this->as($this->a)->getJson('/api/client/chat/sync?cursor=0.0')->assertOk()->assertJsonPath('changed', true);
        $this->assertSame([$this->na->id], collect($sync->json('messages'))->pluck('phone_number_id')->unique()->values()->all());
        // staff tokens are rejected
        $u = User::create(['name' => 'S', 'email' => 's@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($u->createToken('s')->plainTextToken)->getJson('/api/client/chat/sync')->assertForbidden();
    }

    public function test_thread_paging_and_read(): void
    {
        for ($i = 1; $i <= 60; $i++) Message::create(['phone_number_id' => $this->na->id, 'contact_wa_id' => '919000000001', 'direction' => $i % 2 ? 'in' : 'out', 'body' => "m$i", 'status' => $i % 2 ? 'received' : 'delivered']);
        $p1 = $this->as($this->a)->getJson("/api/client/chat/thread?phone_number_id={$this->na->id}&contact=919000000001")->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('has_more', true);
        $this->assertSame('m60', $p1->json('messages.49.body'));
        $p2 = $this->as($this->a)->getJson("/api/client/chat/thread?phone_number_id={$this->na->id}&contact=919000000001&before_id=".$p1->json('messages.0.id'))->assertJsonPath('has_more', false);
        $this->assertSame('hi from Ravi', $p2->json('messages.0.body'));
        $this->assertSame(0, Message::where('contact_wa_id', '919000000001')->where('direction', 'in')->whereNull('read_at')->count());
    }

    public function test_sync_no_change_path_is_cheap_and_deltas_arrive(): void
    {
        $first = $this->as($this->a)->getJson('/api/client/chat/sync')->assertOk()->assertJsonPath('reset', true);
        $cursor = $first->json('cursor');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $cursor);

        // unchanged: cached cursor => no message queries at all
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->as($this->a)->getJson('/api/client/chat/sync?cursor='.$cursor)->assertOk()->assertExactJson(['changed' => false, 'cursor' => $cursor]);
        $this->as($this->a)->getJson('/api/client/chat/sync?since='.$cursor)->assertOk()->assertExactJson(['changed' => false, 'cursor' => $cursor]); // app uses ?since=
        $this->assertSame([], collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, '`messages`'))->values()->all());
        // ETag/If-None-Match => 304
        $this->as($this->a)->withHeaders(['If-None-Match' => '"c'.$cursor.'"'])->get('/api/client/chat/sync')->assertStatus(304);
        $this->flushHeaders();

        // another client's activity does not change A's cursor
        Message::create(['phone_number_id' => $this->nb->id, 'contact_wa_id' => '919000000009', 'direction' => 'in', 'body' => 'b2', 'status' => 'received']);
        $this->as($this->a)->getJson('/api/client/chat/sync?cursor='.$cursor)->assertJsonPath('changed', false);

        // new inbound for A (webhook) => delta with message + conversation summary
        Http::fake();
        $this->inbound('919000000002', 'new one', '1111', 'Sita')->assertOk();
        $d = $this->as($this->a)->getJson('/api/client/chat/sync?cursor='.$cursor)->assertOk()->assertJsonPath('changed', true)->assertJsonPath('reset', false);
        $this->assertContains('new one', collect($d->json('messages'))->pluck('body')->all());
        $this->assertNotContains('b2', collect($d->json('messages'))->pluck('body')->all());
        $this->assertSame('919000000002', $d->json('conversations.0.contact_wa_id'));
        $this->assertSame(2, $d->json('conversations.0.unread'));
        $this->assertSame(3, $d->json('unread_total'));
        $this->assertNotSame($cursor, $d->json('cursor'));

        // status updates (updated_at) also produce deltas
        $c2 = $d->json('cursor');
        $this->travel(2)->seconds();
        Message::where('body', 'new one')->first()->update(['read_at' => now()]);
        $d2 = $this->as($this->a)->getJson('/api/client/chat/sync?cursor='.$c2)->assertJsonPath('changed', true);
        $this->assertContains('new one', collect($d2->json('messages'))->pluck('body')->all());
    }

    public function test_sync_is_rate_limited(): void
    {
        $tok = $this->a->createToken('m')->plainTextToken;
        for ($i = 0; $i < 30; $i++) $this->withToken($tok)->getJson('/api/client/chat/sync?cursor=1.1')->assertOk();
        $this->withToken($tok)->getJson('/api/client/chat/sync?cursor=1.1')->assertStatus(429)->assertJsonPath('error_code', 'RATE_LIMITED');
    }

    public function test_device_register_and_remove(): void
    {
        $tok = str_repeat('f', 40);
        $this->as($this->a)->postJson('/api/client/devices', ['token' => $tok, 'platform' => 'android', 'app_version' => '1.0.0'])->assertCreated();
        $this->as($this->b)->postJson('/api/client/devices', ['token' => $tok])->assertCreated(); // same phone, other account => moves
        $this->assertSame($this->b->id, DeviceToken::first()->client_id);
        $this->assertSame(1, DeviceToken::count());
        $this->as($this->a)->deleteJson('/api/client/devices', ['token' => $tok])->assertJsonPath('removed', 0);
        $this->as($this->b)->deleteJson('/api/client/devices', ['token' => $tok])->assertJsonPath('removed', 1);
    }

    // ---------- push ----------
    protected function fakeFirebase(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $sa = ['type' => 'service_account', 'project_id' => 'wab-test', 'private_key_id' => 'k1', 'private_key' => $pem,
            'client_email' => 'firebase-adminsdk@wab-test.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token'];
        $gs = ['project_info' => ['project_number' => '123456789', 'project_id' => 'wab-test', 'storage_bucket' => 'wab-test.appspot.com'],
            'client' => [['client_info' => ['mobilesdk_app_id' => '1:123456789:android:abc', 'android_client_info' => ['package_name' => 'com.fineoutput.wabusiness']],
                'api_key' => [['current_key' => 'AIzaTestKey']]]]];
        return [$sa, $gs];
    }

    protected function adminUpload(array $sa, array $gs)
    {
        $admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->app['auth']->forgetGuards();
        $t = $admin->createToken('a')->plainTextToken;
        $this->withToken($t)->post('/api/push-settings/client-config', ['file' => UploadedFile::fake()->createWithContent('google-services.json', json_encode($gs))], ['Accept' => 'application/json'])->assertOk();
        $this->withToken($t)->post('/api/push-settings/service-account', ['file' => UploadedFile::fake()->createWithContent('sa.json', json_encode($sa))], ['Accept' => 'application/json'])->assertOk();
        return $t;
    }

    public function test_push_settings_upload_validation_and_secrecy(): void
    {
        [$sa, $gs] = $this->fakeFirebase();
        $this->getJson('/api/mobile/config')->assertOk()->assertJsonPath('push.enabled', false)->assertJsonPath('package_name', 'com.fineoutput.wabusiness');
        $t = $this->adminUpload($sa, $gs);
        $s = $this->withToken($t)->getJson('/api/push-settings')->assertOk()->assertJsonPath('enabled', true)
            ->assertJsonPath('service_account.client_email', $sa['client_email'])->assertJsonPath('client_config.project_id', 'wab-test');
        $this->assertStringNotContainsString('PRIVATE KEY', $s->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', $this->withToken($t)->getJson('/api/settings')->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', DB::table('settings')->pluck('value')->implode(' ')); // encrypted at rest
        $cfg = $this->getJson('/api/mobile/config')->assertJsonPath('push.enabled', true)->assertJsonPath('push.options.appId', '1:123456789:android:abc')
            ->assertJsonPath('push.options.apiKey', 'AIzaTestKey')->assertJsonPath('push.options.messagingSenderId', '123456789');
        $this->assertStringNotContainsString('PRIVATE', $cfg->getContent());
        // wrong package / not a service account
        $bad = $gs; $bad['client'][0]['client_info']['android_client_info']['package_name'] = 'com.other.app';
        $this->withToken($t)->post('/api/push-settings/client-config', ['file' => UploadedFile::fake()->createWithContent('g.json', json_encode($bad))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'com.fineoutput.wabusiness') && str_contains($m, 'com.other.app'));
        $this->withToken($t)->post('/api/push-settings/service-account', ['file' => UploadedFile::fake()->createWithContent('x.json', '{"type":"user"}')], ['Accept' => 'application/json'])->assertStatus(422);
        // clients cannot touch push settings
        $this->as($this->a)->getJson('/api/push-settings')->assertForbidden();
    }

    public function test_inbound_webhook_pushes_to_owner_devices_with_collapse_key_and_prunes_unregistered(): void
    {
        [$sa, $gs] = $this->fakeFirebase();
        $this->adminUpload($sa, $gs);
        DeviceToken::create(['client_id' => $this->a->id, 'token' => 'good-token-'.str_repeat('a', 20)]);
        DeviceToken::create(['client_id' => $this->a->id, 'token' => 'dead-token-'.str_repeat('b', 20)]);
        DeviceToken::create(['client_id' => $this->b->id, 'token' => 'beta-token-'.str_repeat('c', 20)]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => fn (HttpRequest $r) => str_starts_with($r['message']['token'], 'dead')
                ? Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404)
                : Http::response(['name' => 'projects/wab-test/messages/1']),
        ]);
        $this->inbound('919000000001', 'Is my order ready?')->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && substr_count($r['assertion'], '.') === 2);
        $fcm = collect(Http::recorded())->map(fn ($x) => $x[0])->filter(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com/v1/projects/wab-test/messages:send'));
        $this->assertCount(2, $fcm); // only client A's devices
        $m = $fcm->first()['message'];
        $this->assertSame('Bearer ya29.test', $fcm->first()->header('Authorization')[0]);
        $this->assertSame('Ravi', $m['notification']['title']);
        $this->assertSame('Is my order ready?', $m['notification']['body']);
        $this->assertSame('chat_'.$this->na->id.'_919000000001', $m['android']['collapse_key']);
        $this->assertSame('chat_'.$this->na->id.'_919000000001', $m['android']['notification']['tag']);
        $this->assertSame(['type' => 'chat_message', 'phone_number_id' => (string) $this->na->id, 'contact' => '919000000001', 'contact_name' => 'Ravi'],
            array_intersect_key($m['data'], array_flip(['type', 'phone_number_id', 'contact', 'contact_name'])));
        $this->assertDatabaseMissing('device_tokens', ['token' => 'dead-token-'.str_repeat('b', 20)]);
        $this->assertDatabaseHas('device_tokens', ['token' => 'good-token-'.str_repeat('a', 20)]);
    }

    public function test_push_is_a_noop_when_firebase_not_configured(): void
    {
        DeviceToken::create(['client_id' => $this->a->id, 'token' => 'good-token-'.str_repeat('a', 20)]);
        Http::fake();
        $this->inbound('919000000001', 'hello')->assertOk();
        Http::assertNothingSent();
        $this->assertDatabaseHas('messages', ['body' => 'hello']);
    }

    public function test_admin_test_push(): void
    {
        [$sa, $gs] = $this->fakeFirebase();
        $t = $this->adminUpload($sa, $gs);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.t']), 'fcm.googleapis.com/*' => Http::response(['name' => 'x'])]);
        $this->withToken($t)->postJson('/api/push-settings/test', ['client_id' => $this->a->id])->assertStatus(422); // no devices yet
        DeviceToken::create(['client_id' => $this->a->id, 'token' => 'good-token-'.str_repeat('a', 20)]);
        $this->withToken($t)->postJson('/api/push-settings/test', ['client_id' => $this->a->id])->assertOk()->assertJsonPath('sent', 1);
    }
}
