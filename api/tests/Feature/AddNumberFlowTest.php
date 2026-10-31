<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Number Master → Add number: every Meta call is faked (no real Graph API traffic). */
class AddNumberFlowTest extends TestCase
{
    use RefreshDatabase;

    const WABA = '2176511699577909';
    const NEW_ID = '1999000000000077';
    protected User $admin;
    protected string $stage = 'new'; // new|verified|registered

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        foreach (['access_token' => 'EAAtoken', 'waba_id' => self::WABA] as $k => $v) Setting::put($k, $v);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected static function err(int $code, string $msg, array $x = [], int $http = 400) { return Http::response(['error' => ['message' => $msg, 'type' => 'OAuthException', 'code' => $code, 'fbtrace_id' => 'FT1'] + $x], $http); }

    protected function fakeHappy(): void
    {
        Http::fake([
            'graph.facebook.com/v26.0/'.self::WABA.'/phone_numbers' => Http::response(['id' => self::NEW_ID]),
            'graph.facebook.com/v26.0/'.self::NEW_ID.'/request_code' => Http::response(['success' => true]),
            'graph.facebook.com/v26.0/'.self::NEW_ID.'/verify_code' => function () { $this->stage = 'verified'; return Http::response(['success' => true]); },
            'graph.facebook.com/v26.0/'.self::NEW_ID.'/register' => function () { $this->stage = 'registered'; return Http::response(['success' => true]); },
            'graph.facebook.com/v26.0/'.self::NEW_ID.'?*' => fn () => Http::response(['id' => self::NEW_ID, 'display_phone_number' => '+91 98110 22334', 'verified_name' => 'Oswal Home Care',
                'name_status' => 'PENDING_REVIEW', 'code_verification_status' => $this->stage === 'new' ? 'NOT_VERIFIED' : 'VERIFIED', 'quality_rating' => 'UNKNOWN',
                'status' => $this->stage === 'registered' ? 'CONNECTED' : 'PENDING', 'platform_type' => $this->stage === 'registered' ? 'CLOUD_API' : 'NOT_APPLICABLE']),
        ]);
    }

    public function test_full_flow_create_request_verify_register_status(): void
    {
        $this->fakeHappy();
        $client = Client::create(['name' => 'Oswal', 'email' => 'o@x.l', 'password' => 'password123']);
        $r = $this->admin()->postJson('/api/phone-numbers-onboard', ['waba_id' => self::WABA, 'cc' => '+91', 'phone_number' => '98110 22334', 'verified_name' => 'Oswal Home Care', 'client_id' => $client->id])
            ->assertCreated()->assertJsonPath('number.onboarding_step', 'created')->assertJsonPath('number.phone_number_id', self::NEW_ID)
            ->assertJsonPath('number.client_id', $client->id)->assertJsonPath('number.display_number', '+91 9811022334');
        Http::assertSent(fn (HttpRequest $q) => $q->method() === 'POST' && str_ends_with($q->url(), '/'.self::WABA.'/phone_numbers')
            && $q->data() === ['cc' => '91', 'phone_number' => '9811022334', 'verified_name' => 'Oswal Home Care']);
        $id = $r->json('number.id');
        $this->assertDatabaseHas('phone_numbers', ['id' => $id, 'onboarding_step' => 'created', 'country_code' => '91', 'local_number' => '9811022334', 'waba_id' => self::WABA]);

        $this->admin()->postJson("/api/phone-numbers/$id/request-code", ['code_method' => 'VOICE', 'language' => 'en_US'])->assertOk()->assertJsonPath('number.onboarding_step', 'code_requested')->assertJsonPath('number.code_method', 'VOICE');
        Http::assertSent(fn (HttpRequest $q) => str_ends_with($q->url(), '/'.self::NEW_ID.'/request_code') && $q->data() === ['code_method' => 'VOICE', 'language' => 'en_US']);

        $this->admin()->postJson("/api/phone-numbers/$id/verify-code", ['code' => '123456'])->assertOk()->assertJsonPath('number.onboarding_step', 'verified');
        Http::assertSent(fn (HttpRequest $q) => str_ends_with($q->url(), '/'.self::NEW_ID.'/verify_code') && $q->data() === ['code' => '123456']);

        $this->admin()->postJson("/api/phone-numbers/$id/register", ['pin' => '482916'])->assertOk()->assertJsonPath('number.onboarding_step', 'registered')
            ->assertJsonPath('number.status', 'CONNECTED')->assertJsonPath('number.name_status', 'PENDING_REVIEW');
        Http::assertSent(fn (HttpRequest $q) => str_ends_with($q->url(), '/'.self::NEW_ID.'/register') && $q->data() === ['messaging_product' => 'whatsapp', 'pin' => '482916']);
        foreach (['phone_numbers', 'error_logs'] as $t) $this->assertStringNotContainsString('482916', json_encode(DB::table($t)->get()));

        $this->admin()->postJson("/api/phone-numbers/$id/refresh")->assertOk()->assertJsonPath('meta.name_status', 'PENDING_REVIEW')->assertJsonPath('number.verified_at', fn ($v) => $v !== null);
        Http::assertSent(fn (HttpRequest $q) => $q->method() === 'GET' && str_contains(urldecode($q->url()), 'name_status') && str_contains(urldecode($q->url()), 'code_verification_status'));
    }

    public function test_wabas_lists_settings_and_number_wabas(): void
    {
        PhoneNumber::create(['phone_number_id' => '1', 'display_number' => '+1', 'waba_id' => '555000111']);
        PhoneNumber::create(['phone_number_id' => '2', 'display_number' => '+2', 'waba_id' => self::WABA]);
        $this->admin()->getJson('/api/phone-numbers-wabas')->assertOk()->assertJsonPath('default', self::WABA)->assertJsonCount(2, 'wabas')
            ->assertJsonPath('wabas.0.id', self::WABA)->assertJsonPath('wabas.0.is_default', true)->assertJsonPath('wabas.1.id', '555000111');
    }

    public function test_create_error_shows_meta_fields_and_saves_nothing(): void
    {
        Http::fake(['graph.facebook.com/*' => self::err(100, 'Invalid parameter', ['error_subcode' => 2388001, 'error_user_title' => 'Phone number already in use',
            'error_user_msg' => 'This phone number is already registered to a WhatsApp account.'])]);
        $this->admin()->postJson('/api/phone-numbers-onboard', ['waba_id' => self::WABA, 'cc' => '91', 'phone_number' => '9811022334', 'verified_name' => 'Oswal Home Care'])
            ->assertStatus(502)->assertJsonPath('meta.meta_code', 100)->assertJsonPath('meta.meta_message', 'Invalid parameter')
            ->assertJsonPath('meta.error_user_msg', 'This phone number is already registered to a WhatsApp account.')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already registered'));
        $this->assertDatabaseCount('phone_numbers', 0);
        $this->assertDatabaseHas('error_logs', ['source' => 'meta']);
    }

    public function test_validation(): void
    {
        Http::fake();
        $this->admin()->postJson('/api/phone-numbers-onboard', ['waba_id' => 'abc', 'cc' => '9999x', 'phone_number' => 'xx', 'verified_name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['waba_id', 'cc', 'phone_number', 'verified_name']);
        $n = PhoneNumber::create(['phone_number_id' => self::NEW_ID, 'display_number' => '+91 1', 'onboarding_step' => 'created']);
        $this->admin()->postJson("/api/phone-numbers/{$n->id}/request-code", ['code_method' => 'EMAIL'])->assertStatus(422)->assertJsonValidationErrors('code_method');
        $this->admin()->postJson("/api/phone-numbers/{$n->id}/verify-code", ['code' => '12ab'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->admin()->postJson("/api/phone-numbers/{$n->id}/register", ['pin' => '12345'])->assertStatus(422)->assertJsonValidationErrors('pin');
        Http::assertNothingSent();
    }

    public function test_request_code_error_keeps_step_and_records_error(): void
    {
        Http::fake(['graph.facebook.com/*' => self::err(136024, 'Request code error', ['error_user_msg' => 'Too many requests. Please wait before requesting another code.'])]);
        $n = PhoneNumber::create(['phone_number_id' => self::NEW_ID, 'display_number' => '+91 1', 'onboarding_step' => 'created']);
        $this->admin()->postJson("/api/phone-numbers/{$n->id}/request-code", ['code_method' => 'SMS'])->assertStatus(502)
            ->assertJsonPath('meta.meta_code', 136024)->assertJsonPath('meta.error_user_msg', 'Too many requests. Please wait before requesting another code.')
            ->assertJsonPath('number.onboarding_step', 'created');
        $this->assertStringContainsString('Too many requests', $n->fresh()->onboarding_error);
    }

    public function test_wrong_otp_error(): void
    {
        Http::fake(['graph.facebook.com/*' => self::err(136025, 'Code couldn\'t be verified', ['error_user_msg' => 'The code you entered is incorrect.'])]);
        $n = PhoneNumber::create(['phone_number_id' => self::NEW_ID, 'display_number' => '+91 1', 'onboarding_step' => 'code_requested']);
        $this->admin()->postJson("/api/phone-numbers/{$n->id}/verify-code", ['code' => '000000'])->assertStatus(502)
            ->assertJsonPath('meta.meta_code', 136025)->assertJsonPath('meta.error_user_msg', 'The code you entered is incorrect.')->assertJsonPath('number.onboarding_step', 'code_requested');
    }

    public function test_register_error_keeps_verified_step(): void
    {
        Http::fake(['graph.facebook.com/*' => self::err(133005, '(#133005) Two step verification PIN Mismatch')]);
        $n = PhoneNumber::create(['phone_number_id' => self::NEW_ID, 'display_number' => '+91 1', 'onboarding_step' => 'verified']);
        $this->admin()->postJson("/api/phone-numbers/{$n->id}/register", ['pin' => '111111'])->assertStatus(502)->assertJsonPath('meta.meta_code', 133005)->assertJsonPath('registered', false);
        $this->assertSame('verified', $n->fresh()->onboarding_step);
        $this->assertStringContainsString('PIN', $n->fresh()->onboarding_error);
    }

    public function test_refresh_error_and_refresh_advances_step_from_meta(): void
    {
        $n = PhoneNumber::create(['phone_number_id' => self::NEW_ID, 'display_number' => '+91 1', 'onboarding_step' => 'code_requested']);
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Unsupported get request.', 'code' => 100, 'error_subcode' => 33, 'type' => 'GraphMethodException']], 400)
            ->push(['id' => self::NEW_ID, 'code_verification_status' => 'VERIFIED', 'name_status' => 'DECLINED', 'verified_name' => 'Oswal Home Care'])]);
        $this->admin()->postJson("/api/phone-numbers/{$n->id}/refresh")->assertStatus(502)->assertJsonPath('meta.meta_code', 100)->assertJsonPath('meta.meta_subcode', 33);
        $this->admin()->postJson("/api/phone-numbers/{$n->id}/refresh")->assertOk()->assertJsonPath('number.onboarding_step', 'verified')->assertJsonPath('number.name_status', 'DECLINED');
    }

    public function test_non_admin_forbidden(): void
    {
        Http::fake();
        $u = User::create(['name' => 'S', 'email' => 's@t.l', 'password' => 'password123', 'is_admin' => false]);
        $this->withToken($u->createToken('t')->plainTextToken)->postJson('/api/phone-numbers-onboard', ['waba_id' => self::WABA, 'cc' => '91', 'phone_number' => '9811022334', 'verified_name' => 'Oswal Home Care'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_existing_rows_untouched_by_new_columns(): void
    {
        $n = PhoneNumber::create(['phone_number_id' => '42', 'display_number' => '+91 2', 'status' => 'CONNECTED']);
        $this->assertNull($n->fresh()->onboarding_step);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '42', 'status' => 'CONNECTED', 'platform_type' => 'CLOUD_API'])]);
        $this->admin()->postJson("/api/phone-numbers/{$n->id}/refresh")->assertOk()->assertJsonPath('number.onboarding_step', null);
    }
}
