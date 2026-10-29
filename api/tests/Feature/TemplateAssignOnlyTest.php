<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Editing a template where only local fields change must never reach Meta. */
class TemplateAssignOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $client;
    protected MessageTemplate $tpl;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        foreach (['access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        $this->client = Client::create(['name' => 'Oswal', 'email' => 'c@t.l', 'password' => 'password123']);
        $this->tpl = MessageTemplate::create(['meta_id' => 'T1', 'waba_id' => '999', 'name' => 'order_update', 'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, welcome.', 'example' => ['body_text' => [['Ravi']]]], ['type' => 'FOOTER', 'text' => 'Thanks']]]);
    }

    protected function auth() { return $this->withToken($this->admin->createToken('t')->plainTextToken); }

    protected function form(array $over = []): array
    {
        return array_replace(['name' => 'order_update', 'language' => 'en_US', 'category' => 'UTILITY', 'header' => null,
            'body' => ['text' => 'Hi {{1}}, welcome.', 'examples' => ['Ravi']], 'footer' => 'Thanks', 'buttons' => []], $over);
    }

    public function test_assign_only_endpoint_makes_no_meta_request(): void
    {
        $this->auth()->putJson("/api/templates/{$this->tpl->id}/client", ['client_id' => $this->client->id])->assertOk()->assertJsonPath('client_id', $this->client->id);
        Http::assertNothingSent();
        $this->assertDatabaseHas('message_templates', ['id' => $this->tpl->id, 'client_id' => $this->client->id, 'status' => 'APPROVED']);
    }

    public function test_full_edit_with_unchanged_content_saves_locally_only(): void
    {
        $this->auth()->putJson("/api/templates/{$this->tpl->id}", $this->form(['client_id' => $this->client->id]))
            ->assertOk()->assertJsonPath('meta_submitted', false)->assertJsonPath('client_id', $this->client->id);
        Http::assertNothingSent();
        $this->assertDatabaseHas('message_templates', ['id' => $this->tpl->id, 'client_id' => $this->client->id, 'status' => 'APPROVED']);
        $this->assertDatabaseCount('template_edits', 0);
    }

    public function test_unchanged_content_saves_even_when_meta_edit_limit_reached(): void
    {
        $this->tpl->update(['status' => 'PENDING']); // pending templates can't be edited on Meta
        $this->auth()->putJson("/api/templates/{$this->tpl->id}", $this->form(['client_id' => $this->client->id]))->assertOk();
        Http::assertNothingSent();
    }

    public function test_body_change_is_submitted_to_meta(): void
    {
        $this->auth()->putJson("/api/templates/{$this->tpl->id}", $this->form(['body' => ['text' => 'Hello {{1}}, welcome!', 'examples' => ['Ravi']], 'client_id' => $this->client->id]))
            ->assertOk()->assertJsonPath('meta_submitted', true);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/T1') && str_contains($r->body(), 'Hello {{1}}, welcome!'));
        $this->assertDatabaseHas('message_templates', ['id' => $this->tpl->id, 'status' => 'PENDING', 'client_id' => $this->client->id]);
    }

    public function test_footer_change_is_submitted_to_meta(): void
    {
        $this->auth()->putJson("/api/templates/{$this->tpl->id}", $this->form(['footer' => 'Cheers']))->assertOk()->assertJsonPath('meta_submitted', true);
        Http::assertSentCount(1);
    }
}
