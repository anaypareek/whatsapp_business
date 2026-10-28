<?php
namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $oswal;
    protected Client $other;
    protected PhoneNumber $mine;
    protected PhoneNumber $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.api.require_https' => false]);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        $this->oswal = Client::create(['name' => 'Oswal', 'email' => 'o@c.t', 'password' => 'password123', 'is_active' => true]);
        $this->other = Client::create(['name' => 'Other', 'email' => 'x@c.t', 'password' => 'password123', 'is_active' => true]);
        $this->mine = PhoneNumber::create(['phone_number_id' => '1111', 'display_number' => '+91 98765 43210', 'client_id' => $this->oswal->id]);
        $this->theirs = PhoneNumber::create(['phone_number_id' => '2222', 'display_number' => '+91 90000 00002', 'client_id' => $this->other->id]);
        $comp = [['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order {{1}}'], ['type' => 'BODY', 'text' => 'Hi {{1}}, order {{2}} confirmed.']];
        MessageTemplate::create(['meta_id' => 'T1', 'name' => 'order_update', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => $comp, 'client_id' => $this->oswal->id]);
        MessageTemplate::create(['meta_id' => 'T2', 'name' => 'other_tpl', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => $comp, 'client_id' => $this->other->id]);
        Http::fake(['graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.K'.bin2hex(random_bytes(4))]]])]);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function as(Client $c) { $this->app['auth']->forgetGuards(); return $this->withToken($c->createToken('c')->plainTextToken); }
    protected function send(string $key, array $body = [], array $headers = [], string $uri = '/api/v1/templates/send')
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        return $this->withHeaders(['X-API-Key' => $key] + $headers)->postJson($uri, $body + ['from_number_id' => '1111', 'to' => '+91 90000 00001',
            'template' => 'order_update', 'language' => 'en_US', 'variables' => ['body' => ['Ravi', 'OS-7'], 'header' => 'OS-7']]);
    }

    public function test_key_is_hashed_shown_once_and_revocable(): void
    {
        $res = $this->as($this->oswal)->postJson('/api/client/api-keys', ['name' => 'Oswal website'])->assertCreated();
        $plain = $res->json('plain_key');
        $this->assertStringStartsWith('wab_', $plain);
        $this->assertArrayNotHasKey('key_hash', $res->json('api_key'));
        $k = ApiKey::first();
        $this->assertSame(hash('sha256', $plain), $k->key_hash);
        $this->assertSame(substr($plain, 0, 10), $k->prefix);
        $this->assertSame($this->oswal->id, $k->client_id);
        $this->assertSame(60, $k->rate_limit_per_minute);
        $this->as($this->oswal)->getJson('/api/client/api-keys')->assertJsonCount(1)->assertJsonMissingPath('0.key_hash')->assertJsonPath('0.prefix', $k->prefix);

        $this->send($plain)->assertCreated()->assertJsonPath('success', true);
        $this->as($this->oswal)->deleteJson("/api/client/api-keys/{$k->id}")->assertNoContent();
        $this->assertNotNull($k->fresh()->revoked_at);
        $this->send($plain)->assertStatus(401)->assertJsonPath('error_code', 'KEY_REVOKED');
        $this->send($plain.'x')->assertStatus(401);
    }

    public function test_client_cannot_touch_other_clients_keys_admin_can(): void
    {
        [$k] = ApiKey::generate('other', null, ['client_id' => $this->other->id]);
        $this->as($this->oswal)->getJson('/api/client/api-keys')->assertJsonCount(0);
        $this->as($this->oswal)->deleteJson("/api/client/api-keys/{$k->id}")->assertNotFound();
        $this->as($this->oswal)->putJson("/api/client/api-keys/{$k->id}", ['name' => 'x'])->assertNotFound();
        // client cannot pick another client_id
        $this->as($this->oswal)->postJson('/api/client/api-keys', ['name' => 'x', 'client_id' => $this->other->id])->assertCreated()->assertJsonPath('api_key.client_id', $this->oswal->id);
        // admin manages any client
        $this->admin()->postJson('/api/api-keys', ['name' => 'for oswal', 'client_id' => $this->oswal->id, 'rate_limit_per_minute' => 10])->assertCreated()
            ->assertJsonPath('api_key.client_id', $this->oswal->id)->assertJsonPath('api_key.rate_limit_per_minute', 10);
        $this->admin()->getJson('/api/api-keys?client_id='.$this->oswal->id)->assertJsonCount(2);
        $this->admin()->deleteJson("/api/api-keys/{$k->id}")->assertNoContent();
        $this->assertFalse($k->fresh()->active);
        // clients can't hit admin routes
        $this->as($this->oswal)->getJson('/api/api-keys')->assertStatus(403);
    }

    public function test_scoping_to_client_numbers_and_templates(): void
    {
        [, $plain] = ApiKey::generate('o', null, ['client_id' => $this->oswal->id]);
        $this->send($plain, ['from_number_id' => '2222'])->assertStatus(422)->assertJsonPath('error_code', 'UNKNOWN_NUMBER');
        $this->send($plain, ['template' => 'other_tpl'])->assertStatus(422)->assertJsonPath('error_code', 'UNKNOWN_TEMPLATE');
        $this->send($plain, ['phone_number' => '+91 98765 43210', 'from_number_id' => null])->assertCreated();
        $this->send($plain, ['variables' => ['body' => ['only one']]])->assertStatus(422)->assertJsonPath('expected', 2);
        // legacy /v1/messages/send is scoped too for client keys
        $this->send($plain, ['from' => '2222', 'text' => 'hi'], [], '/api/v1/messages/send')->assertStatus(403);
        Http::assertSent(fn (HttpRequest $r) => ($r['template']['name'] ?? null) === 'order_update' && $r['to'] === '919000000001');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/2222/'));
    }

    public function test_only_approved_templates_can_be_sent(): void
    {
        MessageTemplate::where('name', 'order_update')->update(['status' => 'PENDING']);
        [, $plain] = ApiKey::generate('o', null, ['client_id' => $this->oswal->id]);
        $this->send($plain)->assertStatus(422)->assertJsonPath('error_code', 'TEMPLATE_NOT_APPROVED');
        Http::assertNothingSent();
    }

    public function test_legacy_global_key_still_works_everywhere(): void
    {
        [, $plain] = ApiKey::generate('legacy');
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/messages/send', ['from' => '2222', 'to' => '919000000001', 'text' => 'x'])->assertCreated();
        $this->send($plain, ['from_number_id' => '2222', 'template' => 'other_tpl'])->assertCreated();
        // bearer auth on the new endpoint
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/templates/send', ['from_number_id' => '1111', 'to' => '919000000001', 'template' => 'order_update',
            'variables' => ['body' => ['A', 'B'], 'header' => 'B']])->assertCreated();
    }

    public function test_key_in_query_string_rejected(): void
    {
        [, $plain] = ApiKey::generate('q', null, ['client_id' => $this->oswal->id]);
        $this->postJson('/api/v1/templates/send?api_key='.$plain, [])->assertStatus(400)->assertJsonPath('error_code', 'KEY_IN_QUERY');
        $this->send($plain, [], [], '/api/v1/templates/send?token=abc')->assertStatus(400);
        $this->assertDatabaseHas('api_request_logs', ['status' => 400]);
        $this->assertFalse(ApiRequestLog::where('endpoint', 'like', '%'.$plain.'%')->exists());
    }

    public function test_ip_allowlist_with_cidr(): void
    {
        [, $plain] = ApiKey::generate('ip', null, ['client_id' => $this->oswal->id, 'allowed_ips' => ['10.0.0.0/8', '203.0.113.5']]);
        $this->send($plain)->assertStatus(403)->assertJsonPath('error_code', 'IP_NOT_ALLOWED'); // 127.0.0.1
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.3.4']);
        $this->send($plain)->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5']);
        $this->send($plain)->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.6']);
        $this->send($plain)->assertStatus(403);
    }

    public function test_origin_allowlist(): void
    {
        [, $plain] = ApiKey::generate('o', null, ['client_id' => $this->oswal->id, 'allowed_origins' => ['oswalsoap.com', '*.oswal.in']]);
        $this->send($plain)->assertStatus(403)->assertJsonPath('error_code', 'ORIGIN_NOT_ALLOWED');
        $this->send($plain, [], ['Origin' => 'https://evil.com'])->assertStatus(403);
        $this->send($plain, [], ['Origin' => 'https://oswalsoap.com.evil.com'])->assertStatus(403);
        $this->send($plain, [], ['Origin' => 'https://oswalsoap.com'])->assertCreated();
        $this->send($plain, [], ['Referer' => 'https://shop.oswal.in/checkout'])->assertCreated();
    }

    public function test_allowlist_validation_on_create(): void
    {
        $this->as($this->oswal)->postJson('/api/client/api-keys', ['name' => 'x', 'allowed_ips' => ['not-an-ip']])->assertStatus(422)->assertJsonValidationErrors('allowed_ips');
        $this->as($this->oswal)->postJson('/api/client/api-keys', ['name' => 'x', 'allowed_ips' => ['1.2.3.0/33']])->assertStatus(422);
        $this->as($this->oswal)->postJson('/api/client/api-keys', ['name' => 'x', 'allowed_origins' => ['bad domain!']])->assertStatus(422);
        $this->as($this->oswal)->postJson('/api/client/api-keys', ['name' => 'x', 'allowed_ips' => [' 1.2.3.4 ', '2001:db8::/32'], 'allowed_origins' => ['https://oswalsoap.com/']])
            ->assertCreated()->assertJsonPath('api_key.allowed_ips', ['1.2.3.4', '2001:db8::/32']);
    }

    public function test_per_key_rate_limit(): void
    {
        RateLimiter::clear('api-key:1');
        [$k, $plain] = ApiKey::generate('rl', null, ['client_id' => $this->oswal->id, 'rate_limit_per_minute' => 3]);
        for ($i = 0; $i < 3; $i++) $this->send($plain)->assertCreated()->assertHeader('X-RateLimit-Limit', '3');
        $this->send($plain)->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error_code', 'RATE_LIMITED');
        // another key is unaffected
        [, $p2] = ApiKey::generate('rl2', null, ['client_id' => $this->oswal->id]);
        $this->send($p2)->assertCreated();
        $this->assertSame(1, ApiRequestLog::where('api_key_id', $k->id)->where('status', 429)->count());
    }

    public function test_https_required_in_production(): void
    {
        config(['services.api.require_https' => null]);
        $this->app['env'] = 'production';
        [, $plain] = ApiKey::generate('h', null, ['client_id' => $this->oswal->id]);
        $this->send($plain)->assertStatus(403)->assertJsonPath('error_code', 'HTTPS_REQUIRED');
        $this->send($plain, [], ['X-Forwarded-Proto' => 'https'])->assertCreated();
        $this->app['env'] = 'testing';
    }

    public function test_request_logging_and_log_views(): void
    {
        [$k, $plain] = ApiKey::generate('log', null, ['client_id' => $this->oswal->id]);
        [, $p2] = ApiKey::generate('other', null, ['client_id' => $this->other->id]);
        $this->send($plain)->assertCreated();
        $this->send($plain, ['template' => 'nope'])->assertStatus(422);
        $this->send($p2, ['from_number_id' => '2222', 'template' => 'other_tpl'])->assertCreated();
        $log = ApiRequestLog::where('api_key_id', $k->id)->orderBy('id')->first();
        $this->assertSame('/api/v1/templates/send', $log->endpoint);
        $this->assertSame(201, $log->status);
        $this->assertSame('order_update', $log->template);
        $this->assertSame('+919000000001', $log->to);
        $this->assertSame($this->oswal->id, $log->client_id);
        $this->assertNotNull($log->latency_ms);
        // no secrets or variable values in the log
        foreach (ApiRequestLog::all() as $row) { $j = json_encode($row); $this->assertStringNotContainsString($plain, $j); $this->assertStringNotContainsString('Ravi', $j); }
        $this->assertStringContainsString('not found', ApiRequestLog::where('status', 422)->first()->error);

        $this->as($this->oswal)->getJson('/api/client/api-keys/logs')->assertJsonPath('total', 2);
        $this->as($this->oswal)->getJson('/api/client/api-keys/logs?status=error')->assertJsonPath('total', 1);
        $this->admin()->getJson('/api/api-keys/logs')->assertJsonPath('total', 3);
        $this->admin()->getJson('/api/api-keys/logs?client_id='.$this->other->id)->assertJsonPath('total', 1)->assertJsonPath('data.0.api_key.name', 'other');
    }
}
