<?php
namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NumberRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected PhoneNumber $num;
    protected bool $registered = false;
    const HINT = 'This number is not registered with the Cloud API yet. Go to Number Master and click Register.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        foreach (['access_token' => 'EAAtoken', 'waba_id' => '2176511699577909'] as $k => $v) Setting::put($k, $v);
        $this->num = PhoneNumber::create(['phone_number_id' => '1388744380990899', 'display_number' => '+91 88249 30146', 'status' => 'PENDING']);
        Http::fake([
            'graph.facebook.com/*/1388744380990899/register' => function (HttpRequest $r) {
                if ($r['pin'] === '937415') return Http::response(['error' => ['message' => '(#133005) Two step verification PIN Mismatch', 'type' => 'OAuthException', 'code' => 133005, 'fbtrace_id' => 'F1']], 400);
                $this->registered = true;
                return Http::response(['success' => true]);
            },
            'graph.facebook.com/*/1388744380990899/messages' => fn () => $this->registered
                ? Http::response(['messages' => [['id' => 'wamid.OK']]])
                : Http::response(['error' => ['message' => '(#133010) Account not registered', 'type' => 'OAuthException', 'code' => 133010, 'fbtrace_id' => 'F2']], 400),
            'graph.facebook.com/*/1388744380990899?*' => fn () => Http::response(['id' => '1388744380990899', 'display_phone_number' => '+91 88249 30146',
                'verified_name' => 'Fineoutput', 'quality_rating' => $this->registered ? 'GREEN' : 'UNKNOWN', 'status' => $this->registered ? 'CONNECTED' : 'PENDING',
                'name_status' => 'APPROVED', 'platform_type' => $this->registered ? 'CLOUD_API' : 'NOT_APPLICABLE', 'code_verification_status' => 'VERIFIED']),
        ]);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }

    public function test_register_calls_meta_then_refreshes_and_never_stores_the_pin(): void
    {
        $res = $this->admin()->postJson("/api/phone-numbers/{$this->num->id}/register", ['pin' => '482916'])->assertOk()
            ->assertJsonPath('registered', true)->assertJsonPath('number.status', 'CONNECTED')->assertJsonPath('number.quality_rating', 'GREEN')
            ->assertJsonPath('number.name', 'Fineoutput')->assertJsonPath('number.platform_type', 'CLOUD_API');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v26.0/1388744380990899/register')
            && $r->data() === ['messaging_product' => 'whatsapp', 'pin' => '482916']);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_contains($r->url(), '/1388744380990899?') && str_contains(urldecode($r->url()), 'name_status'));
        $this->assertStringNotContainsString('482916', $res->getContent());
        // the PIN is nowhere in the database
        foreach (['phone_numbers', 'error_logs', 'settings', 'messages'] as $t) $this->assertStringNotContainsString('482916', json_encode(DB::table($t)->get()));
    }

    public function test_register_failure_shows_meta_error_with_hint_and_logs_it(): void
    {
        $res = $this->admin()->postJson("/api/phone-numbers/{$this->num->id}/register", ['pin' => '937415'])->assertStatus(502)
            ->assertJsonPath('registered', false)->assertJsonPath('error_code', 'META_133005');
        $this->assertStringContainsString('Two step verification PIN Mismatch', $res->json('message'));
        $this->assertStringContainsString('two-step verification PIN', $res->json('meta.hint'));
        $log = ErrorLog::find($res->json('log_id'));
        $this->assertSame('meta', $log->source);
        $this->assertStringNotContainsString('937415', json_encode($log->toArray()));
        $this->assertSame('PENDING', $this->num->fresh()->status);
    }

    public function test_pin_validation_and_admin_only(): void
    {
        foreach (['12345', '1234567', 'abcdef', '12 456', ''] as $bad) {
            $this->admin()->postJson("/api/phone-numbers/{$this->num->id}/register", ['pin' => $bad])->assertStatus(422)->assertJsonValidationErrors('pin');
        }
        $staff = User::create(['name' => 'S', 'email' => 's@t.l', 'password' => 'password123', 'is_admin' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($staff->createToken('s')->plainTextToken)->postJson("/api/phone-numbers/{$this->num->id}/register", ['pin' => '123456'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_refresh_status(): void
    {
        $this->admin()->postJson("/api/phone-numbers/{$this->num->id}/refresh")->assertOk()
            ->assertJsonPath('number.status', 'PENDING')->assertJsonPath('number.platform_type', 'NOT_APPLICABLE')->assertJsonPath('number.name_status', 'APPROVED');
        $this->assertNotNull($this->num->fresh()->meta_checked_at);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/register'));
    }

    public function test_133010_send_errors_carry_the_register_hint(): void
    {
        // plain send (Send Message / Chat)
        $res = $this->admin()->postJson('/api/messages/send', ['phone_number_id' => $this->num->id, 'to' => '919811122233', 'text' => 'hi']);
        $this->assertStringContainsString(self::HINT, $res->getContent());
        $this->assertStringContainsString(self::HINT, Message::latest('id')->first()->error);
        // template test modal
        $t = MessageTemplate::create(['name' => 'hello', 'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED', 'meta_id' => 'M1', 'components' => [['type' => 'BODY', 'text' => 'Hi']]]);
        $r2 = $this->admin()->postJson("/api/templates/{$t->id}/test", ['phone_number_id' => $this->num->id, 'to' => '919811122233'])->assertStatus(502)->assertJsonPath('error_code', 'META_133010');
        $this->assertStringContainsString('Account not registered', $r2->json('message'));
        $this->assertStringContainsString(self::HINT, $r2->json('message'));
        // after registering, sending works
        $this->admin()->postJson("/api/phone-numbers/{$this->num->id}/register", ['pin' => '123456'])->assertOk();
        $this->admin()->postJson("/api/templates/{$t->id}/test", ['phone_number_id' => $this->num->id, 'to' => '919811122233'])->assertOk()->assertJsonPath('wamid', 'wamid.OK');
    }
}
