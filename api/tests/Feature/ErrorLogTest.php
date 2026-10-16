<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\ErrorLog;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Services\ErrorLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ErrorLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        foreach (['access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        Http::fake(['graph.facebook.com/*/999/message_templates?*' => function (HttpRequest $r) {
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
            return match (true) {
                str_starts_with($q['name'], 'sample_') => Http::response(['error' => ['message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => 100, 'error_subcode' => 2593002,
                    'error_user_title' => 'Cannot delete sample template', 'error_user_msg' => 'Sample templates can not be deleted.', 'fbtrace_id' => 'Ab1']], 400),
                $q['name'] === 'gone' => Http::response(['error' => ['message' => 'Template does not exist', 'code' => 100, 'error_subcode' => 33]], 400),
                default => Http::response(['success' => true]),
            };
        }]);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function tpl(string $name) { return MessageTemplate::create(['name' => $name, 'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED', 'meta_id' => '4662', 'components' => []]); }

    public function test_meta_refusal_is_surfaced_with_user_message_and_log_id(): void
    {
        $t = $this->tpl('sample_shipping_confirmation');
        $res = $this->admin()->deleteJson("/api/templates/{$t->id}")->assertStatus(502)
            ->assertJsonPath('error_code', 'META_100')->assertJsonPath('can_remove_locally', true)->assertJsonPath('meta.error_user_msg', 'Sample templates can not be deleted.');
        $this->assertStringContainsString('Sample templates can not be deleted.', $res->json('message'));
        $this->assertStringContainsString('#100/2593002', $res->json('message'));
        $log = ErrorLog::find($res->json('log_id'));
        $this->assertSame('meta', $log->source);
        $this->assertSame(400, $log->status_code);
        $this->assertStringNotContainsString('EAAtoken', json_encode($log->toArray()));
        $this->assertDatabaseHas('message_templates', ['id' => $t->id]);
        // fallback: remove from the portal only, without calling Meta
        Http::assertSentCount(1);
        $this->admin()->deleteJson("/api/templates/{$t->id}?local_only=1")->assertOk()->assertJsonPath('deleted', 'local');
        Http::assertSentCount(1);
        $this->assertDatabaseMissing('message_templates', ['id' => $t->id]);
    }

    public function test_template_missing_on_meta_is_deleted_locally(): void
    {
        $t = $this->tpl('gone');
        $this->admin()->deleteJson("/api/templates/{$t->id}")->assertOk()->assertJsonPath('deleted', 'local');
        $this->assertDatabaseMissing('message_templates', ['id' => $t->id]);
    }

    public function test_normal_delete_still_works(): void
    {
        $t = $this->tpl('order_update');
        $this->admin()->deleteJson("/api/templates/{$t->id}")->assertNoContent();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'hsm_id=4662'));
    }

    public function test_redaction(): void
    {
        $id = ErrorLogger::record(['message' => 'call failed Bearer abcdefghijklmnop token EAAB'.str_repeat('x', 30).' ?access_token=zzz', 'context' => ['password' => 'p', 'nested' => ['api_key' => 'k', 'ok' => 'fine']]]);
        $l = ErrorLog::find($id);
        $this->assertStringNotContainsString('abcdefghijklmnop', $l->message);
        $this->assertStringNotContainsString('zzz', $l->message);
        $this->assertStringNotContainsString('EAAB', $l->message);
        $this->assertSame('[REDACTED]', $l->context['password']);
        $this->assertSame('[REDACTED]', $l->context['nested']['api_key']);
        $this->assertSame('fine', $l->context['nested']['ok']);
    }

    public function test_log_channel_and_exceptions_are_stored(): void
    {
        Log::warning('disk almost full', ['secret' => 's3']);
        $this->assertDatabaseHas('error_logs', ['message' => 'disk almost full', 'level' => 'warning']);
        Log::info('just info');
        $this->assertDatabaseMissing('error_logs', ['message' => 'just info']);
        \Illuminate\Support\Facades\Route::get('/api/_boom', fn () => throw new \RuntimeException('kaboom'));
        $res = $this->getJson('/api/_boom')->assertStatus(500)->assertJsonPath('error_code', 'SERVER_ERROR');
        $this->assertNotNull($res->json('log_id'));
        $this->assertSame('RuntimeException: kaboom', ErrorLog::find($res->json('log_id'))->message);
        $this->assertSame(1, ErrorLog::where('message', 'like', '%kaboom')->count()); // not duplicated by the log channel
    }

    public function test_consistent_error_shape(): void
    {
        $this->getJson('/api/error-logs')->assertStatus(401)->assertJsonStructure(['message', 'error_code', 'log_id'])->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $this->admin()->postJson('/api/clients', [])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_FAILED')->assertJsonStructure(['message', 'errors', 'log_id']);
        $this->admin()->getJson('/api/error-logs/999999')->assertStatus(404)->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_admin_endpoints_and_access(): void
    {
        $a = ErrorLogger::record(['message' => 'first', 'source' => 'meta']);
        ErrorLogger::record(['message' => 'second', 'level' => 'warning']);
        $this->admin()->getJson('/api/error-logs?source=meta')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'first');
        $this->admin()->getJson('/api/error-logs?q=second')->assertJsonCount(1, 'data');
        $this->admin()->getJson('/api/error-logs/count')->assertJsonPath('open', 2);
        $this->admin()->postJson("/api/error-logs/$a/resolve")->assertOk()->assertJsonPath('resolver.name', 'A');
        $this->admin()->getJson('/api/error-logs/count')->assertJsonPath('open', 1);
        $this->admin()->getJson('/api/error-logs?status=resolved')->assertJsonCount(1, 'data');

        $staff = User::create(['name' => 'S', 'email' => 's@t.l', 'password' => 'password123', 'is_admin' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($staff->createToken('s')->plainTextToken)->getJson('/api/error-logs')->assertForbidden();
        $c = Client::create(['name' => 'C', 'email' => 'c@c.t', 'password' => 'password123']);
        $this->app['auth']->forgetGuards();
        $this->withToken($c->createToken('c')->plainTextToken)->getJson('/api/error-logs')->assertStatus(403);
    }

    public function test_frontend_endpoint_and_pruning(): void
    {
        $id = $this->postJson('/api/client-errors', ['message' => 'x is undefined', 'stack' => 'at foo (app.js:1:2)', 'url' => 'https://p.test/flows', 'line' => 1, 'app' => 'portal'])
            ->assertCreated()->json('log_id');
        $l = ErrorLog::find($id);
        $this->assertSame('frontend', $l->source);
        $this->assertSame('https://p.test/flows', $l->url);
        $this->postJson('/api/client-errors', ['message' => str_repeat('a', 3000)])->assertStatus(422);

        $old = ErrorLog::find(ErrorLogger::record(['message' => 'old']));
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $this->artisan('error-logs:prune')->assertSuccessful();
        $this->assertDatabaseMissing('error_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('error_logs', ['id' => $id]);
        $this->admin()->deleteJson('/api/error-logs/clear?older_than_days=0')->assertOk();
        $this->assertSame(0, ErrorLog::count());
    }
}
