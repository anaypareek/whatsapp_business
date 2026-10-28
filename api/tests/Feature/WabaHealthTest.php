<?php
namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\WabaHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WabaHealthTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(WabaHealth::KEY);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        foreach (['access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
    }

    protected function auth() { return $this->withToken($this->admin->createToken('t')->plainTextToken); }

    private function blocked(): array
    {
        return ['id' => '999', 'account_review_status' => 'APPROVED', 'business_verification_status' => 'verified', 'health_status' => [
            'can_send_message' => 'BLOCKED', 'entities' => [
                ['entity_type' => 'WABA', 'id' => '999', 'can_send_message' => 'BLOCKED', 'errors' => [['error_code' => 141006,
                    'error_description' => 'There was an error with your payment method.', 'possible_solution' => 'Add a valid payment method in Business Settings.']]],
                ['entity_type' => 'PHONE_NUMBER', 'id' => '1111', 'can_send_message' => 'AVAILABLE'],
            ]]];
    }

    public function test_refresh_fetches_fields_and_caches_blocked(): void
    {
        Http::fake(['graph.facebook.com/*/999?*' => Http::response($this->blocked())]);
        $this->auth()->postJson('/api/waba-health/refresh')->assertOk()->assertJsonPath('blocked', true)
            ->assertJsonPath('can_send_message', 'BLOCKED')->assertJsonPath('entities.0.errors.0.possible_solution', 'Add a valid payment method in Business Settings.');
        Http::assertSent(fn (HttpRequest $r) => str_contains(urldecode($r->url()), 'fields=health_status,account_review_status,business_verification_status'));
        // cached: GET does not call Meta again
        Http::fake(fn () => throw new \RuntimeException('should not be called'));
        $this->auth()->getJson('/api/waba-health')->assertOk()->assertJsonPath('blocked', true)->assertJsonPath('entities.0.errors.0.error_code', 141006);
    }

    public function test_available_and_limited(): void
    {
        Http::fake(['graph.facebook.com/*/999?*' => Http::sequence()
            ->push(['health_status' => ['can_send_message' => 'AVAILABLE', 'entities' => []]])
            ->push(['health_status' => ['can_send_message' => 'LIMITED', 'entities' => [['entity_type' => 'BUSINESS', 'id' => '5', 'can_send_message' => 'LIMITED',
                'errors' => [['error_code' => 141010, 'error_description' => 'Business not verified', 'possible_solution' => 'Verify your business']]]]]])]);
        $this->assertFalse(WabaHealth::refresh()['blocked']);
        $this->assertTrue(WabaHealth::refresh()['blocked']);
    }

    public function test_fetch_error_keeps_previous_result(): void
    {
        Http::fake(['graph.facebook.com/*/999?*' => Http::sequence()->push($this->blocked())->push(['error' => ['message' => 'boom', 'code' => 1]], 500)]);
        WabaHealth::refresh();
        $h = WabaHealth::refresh();
        $this->assertTrue($h['blocked']);
        $this->assertStringContainsString('boom', $h['fetch_error']);
    }

    public function test_settings_save_and_sync_and_schedule_refresh(): void
    {
        Http::fake(['graph.facebook.com/*/999?*' => Http::response($this->blocked()), 'graph.facebook.com/*/999/phone_numbers*' => Http::response(['data' => []])]);
        $this->auth()->putJson('/api/settings', ['waba_id' => '999'])->assertOk();
        $this->assertTrue(WabaHealth::get()['blocked']);
        Cache::forget(WabaHealth::KEY);
        $this->auth()->postJson('/api/phone-numbers-sync')->assertOk();
        $this->assertNotNull(WabaHealth::get());
        Cache::forget(WabaHealth::KEY);
        $this->artisan('waba:health')->assertSuccessful();
        $this->assertSame('BLOCKED', WabaHealth::get()['can_send_message']);
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('waba:health', $events);
    }

    public function test_template_create_subcode_2388339_hint(): void
    {
        Http::fake(['graph.facebook.com/*/999/message_templates' => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100,
            'error_subcode' => 2388339, 'error_user_msg' => 'The WhatsApp Business Account is restricted.']], 400)]);
        $res = $this->auth()->postJson('/api/templates', ['name' => 'new_order', 'language' => 'en_US', 'category' => 'UTILITY', 'body' => ['text' => 'Hello']])->assertStatus(502);
        $this->assertStringContainsString('Your WhatsApp account is blocked by Meta, often a payment-method issue (141006). Check Business Settings → WhatsApp accounts → Payment settings.', $res->json('message'));
        $this->assertSame(2388339, $res->json('meta.meta_subcode'));
        $this->assertStringContainsString('141006', $res->json('meta.hint'));
    }
}
