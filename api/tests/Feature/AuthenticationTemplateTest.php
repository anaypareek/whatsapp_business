<?php
namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use App\Services\TemplateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthenticationTemplateTest extends TestCase
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
            'graph.facebook.com/*/999/message_templates' => Http::response(['id' => 'TAUTH', 'status' => 'PENDING', 'category' => 'AUTHENTICATION']),
            'graph.facebook.com/*/1111/messages' => Http::response(['messages' => [['id' => 'wamid.AUTH1']]]),
        ]);
    }

    protected function auth()
    {
        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    }

    protected function copyCodeForm(array $extra = []): array
    {
        return $extra + [
            'name' => 'verification_code',
            'language' => 'en_US',
            'category' => 'AUTHENTICATION',
            'body' => ['add_security_recommendation' => true],
            'code_expiration_minutes' => 10,
            'message_send_ttl_seconds' => 60,
            'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy Code']],
        ];
    }

    public function test_create_copy_code_payload_matches_meta_rules(): void
    {
        $this->auth()->postJson('/api/templates', $this->copyCodeForm())->assertCreated()
            ->assertJsonPath('category', 'AUTHENTICATION')->assertJsonPath('meta_id', 'TAUTH');

        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/999/message_templates')) return false;
            $expected = [
                'name' => 'verification_code',
                'language' => 'en_US',
                'category' => 'AUTHENTICATION',
                'message_send_ttl_seconds' => 60,
                'components' => [
                    ['type' => 'BODY', 'add_security_recommendation' => true],
                    ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy Code']]],
                ],
            ];
            $this->assertSame($expected, [
                'name' => $r['name'],
                'language' => $r['language'],
                'category' => $r['category'],
                'message_send_ttl_seconds' => $r['message_send_ttl_seconds'],
                'components' => $r['components'],
            ]);
            $this->assertArrayNotHasKey('text', $r['components'][0]);
            $this->assertArrayNotHasKey('text', $r['components'][1]);
            return true;
        });
    }

    public function test_create_one_tap_payload_includes_supported_apps(): void
    {
        $this->auth()->postJson('/api/templates', [
            'name' => 'otp_onetap',
            'language' => 'en_US',
            'category' => 'AUTHENTICATION',
            'body' => ['add_security_recommendation' => true],
            'code_expiration_minutes' => 10,
            'buttons' => [[
                'type' => 'OTP',
                'otp_type' => 'ONE_TAP',
                'text' => 'Copy Code',
                'autofill_text' => 'Autofill',
                'supported_apps' => [['package_name' => 'com.example.app', 'signature_hash' => 'K8a/AINcGX7']],
            ]],
        ])->assertCreated();

        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/999/message_templates')) return false;
            $this->assertSame('AUTHENTICATION', $r['category']);
            $this->assertSame(['type' => 'BODY', 'add_security_recommendation' => true], $r['components'][0]);
            $this->assertSame(['type' => 'FOOTER', 'code_expiration_minutes' => 10], $r['components'][1]);
            $this->assertSame([
                'type' => 'OTP',
                'otp_type' => 'ONE_TAP',
                'text' => 'Copy Code',
                'autofill_text' => 'Autofill',
                'supported_apps' => [['package_name' => 'com.example.app', 'signature_hash' => 'K8a/AINcGX7']],
            ], $r['components'][2]['buttons'][0]);
            $this->assertArrayNotHasKey('text', $r['components'][0]);
            return true;
        });
    }

    public function test_validation_rejects_header_and_extra_buttons(): void
    {
        $this->auth()->postJson('/api/templates', $this->copyCodeForm([
            'header' => ['format' => 'TEXT', 'text' => 'OTP'],
        ]))->assertStatus(422)->assertJsonValidationErrors('header');

        $this->auth()->postJson('/api/templates', $this->copyCodeForm([
            'buttons' => [
                ['type' => 'OTP', 'otp_type' => 'COPY_CODE'],
                ['type' => 'QUICK_REPLY', 'text' => 'Help'],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('buttons');

        $this->auth()->postJson('/api/templates', $this->copyCodeForm([
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'OK']],
        ]))->assertStatus(422)->assertJsonValidationErrors('buttons.0.type');
    }

    public function test_custom_body_text_is_stripped_not_sent_to_meta(): void
    {
        $this->auth()->postJson('/api/templates', $this->copyCodeForm([
            'name' => 'verification_code_legacy',
            'body' => ['text' => '{{1}} is your verification code.', 'add_security_recommendation' => true],
        ]))->assertCreated();

        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/999/message_templates')) return false;
            $this->assertSame(['type' => 'BODY', 'add_security_recommendation' => true], $r['components'][0]);
            $this->assertArrayNotHasKey('text', $r['components'][0]);
            return $r['name'] === 'verification_code_legacy';
        });
    }

    public function test_auth_send_components_use_code_for_body_and_url_button(): void
    {
        $t = MessageTemplate::create([
            'name' => 'otp', 'language' => 'en_US', 'category' => 'AUTHENTICATION', 'status' => 'APPROVED', 'meta_id' => 'M3',
            'components' => [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => 10],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy Code']]],
            ],
        ]);
        $this->assertSame(1, $t->body_variable_count);

        $c = TemplateBuilder::sendComponents($t, ['654321']);
        $this->assertSame([
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '654321']]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '654321']]],
        ], $c);

        $demo = TemplateBuilder::demoParams($t);
        $this->assertSame(['123456'], $demo['body']);
        $this->assertSame('123456', $demo['buttons'][0]);

        $this->auth()->postJson('/api/messages/send-template', [
            'phone_number_id' => $this->num->id, 'to' => '919000000001', 'template_id' => $t->id, 'params' => ['654321'],
        ])->assertCreated();

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/1111/messages')) return false;
            $this->assertSame('template', $r['type']);
            $this->assertSame('otp', $r['template']['name']);
            $this->assertSame([
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '654321']]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '654321']]],
            ], $r['template']['components']);
            return true;
        });
        $this->assertSame('sent', Message::first()->status);
    }

    public function test_one_tap_requires_supported_apps(): void
    {
        $this->auth()->postJson('/api/templates', [
            'name' => 'otp_bad',
            'language' => 'en_US',
            'category' => 'AUTHENTICATION',
            'buttons' => [['type' => 'OTP', 'otp_type' => 'ONE_TAP']],
        ])->assertStatus(422)->assertJsonValidationErrors('buttons.0.supported_apps');
    }
}
