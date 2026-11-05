<?php
namespace Tests\Feature;

use App\Models\AiContactState;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ClientSendTest extends TestCase
{
    use RefreshDatabase;

    protected Client $client;
    protected Client $other;
    protected PhoneNumber $mine;
    protected PhoneNumber $theirs;
    protected string $tok;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('access_token', 'EAAtoken');
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA123']),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.TEST']]]),
        ]);
        $this->client = Client::create(['name' => 'Ravi', 'email' => 'r@c.t', 'password' => 'password123']);
        $this->other = Client::create(['name' => 'Other', 'email' => 'o@c.t', 'password' => 'password123']);
        $this->mine = PhoneNumber::create(['client_id' => $this->client->id, 'phone_number_id' => '100', 'display_number' => '+91 100', 'status' => 'CONNECTED']);
        $this->theirs = PhoneNumber::create(['client_id' => $this->other->id, 'phone_number_id' => '200', 'display_number' => '+91 200', 'status' => 'CONNECTED']);
        Message::create(['phone_number_id' => $this->mine->id, 'contact_wa_id' => '919811000001', 'direction' => 'in', 'body' => 'hi', 'status' => 'received']);
        $this->tok = $this->postJson('/api/client/auth/login', ['email' => 'r@c.t', 'password' => 'password123'])->json('token');
        $this->app['auth']->forgetGuards();
    }

    protected function asClient() { return $this->withToken($this->tok); }

    protected function tpl(array $over = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'client_id' => $this->client->id, 'name' => 'welcome_note', 'language' => 'en_US', 'category' => 'UTILITY',
            'status' => 'APPROVED', 'meta_id' => 'M'.bin2hex(random_bytes(3)),
            'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}', 'example' => ['body_text' => [['Ravi']]]]],
        ], $over));
    }

    public function test_client_sends_text_inside_window_as_manual(): void
    {
        $m = $this->asClient()->postJson('/api/client/messages/send', [
            'phone_number_id' => $this->mine->id, 'to' => '919811000001', 'text' => 'Thanks!',
        ])->assertCreated()->assertJsonPath('source', 'manual')->assertJsonPath('direction', 'out')->assertJsonPath('status', 'sent')->json();
        $this->assertDatabaseHas('messages', ['id' => $m['id'], 'source' => 'manual', 'body' => 'Thanks!', 'contact_wa_id' => '919811000001']);
    }

    public function test_client_sends_media_inside_window(): void
    {
        $this->asClient()->post('/api/client/messages/send', [
            'phone_number_id' => $this->mine->id, 'to' => '919811000001', 'text' => 'Pic',
            'file' => UploadedFile::fake()->create('a.jpg', 20, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('type', 'image')->assertJsonPath('media_id', 'MEDIA123')->assertJsonPath('source', 'manual');
    }

    public function test_outside_window_rejects_freeform_allows_template(): void
    {
        $this->asClient()->postJson('/api/client/messages/send', [
            'phone_number_id' => $this->mine->id, 'to' => '919900000099', 'text' => 'cold',
        ])->assertStatus(422)->assertJsonValidationErrors('to');

        $tpl = $this->tpl();
        $this->asClient()->postJson('/api/client/messages/send-template', [
            'phone_number_id' => $this->mine->id, 'to' => '919900000099', 'template_id' => $tpl->id, 'params' => ['Ravi'],
        ])->assertCreated()->assertJsonPath('type', 'template')->assertJsonPath('source', 'manual')->assertJsonPath('status', 'sent');

        // new conversation appears in client's chat list
        $this->asClient()->getJson('/api/client/conversations')
            ->assertOk()
            ->assertJsonFragment(['contact_wa_id' => '919900000099']);
    }

    public function test_thread_reports_in_window_false_when_stale(): void
    {
        $old = Message::create([
            'phone_number_id' => $this->mine->id, 'contact_wa_id' => '919811000002', 'direction' => 'in',
            'body' => 'old', 'status' => 'received',
        ]);
        Message::whereKey($old->id)->update(['created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)]);
        $this->asClient()->getJson("/api/client/messages/thread?phone_number_id={$this->mine->id}&contact=919811000002")
            ->assertOk()->assertJsonPath('in_window', false);
        $this->asClient()->getJson("/api/client/messages/thread?phone_number_id={$this->mine->id}&contact=919811000001")
            ->assertOk()->assertJsonPath('in_window', true);
    }

    public function test_isolation_cannot_send_from_or_use_others_template(): void
    {
        $tplMine = $this->tpl(['name' => 'mine']);
        $tplTheirs = $this->tpl(['client_id' => $this->other->id, 'name' => 'theirs']);
        Message::create(['phone_number_id' => $this->theirs->id, 'contact_wa_id' => '919811000001', 'direction' => 'in', 'body' => 'hi', 'status' => 'received']);

        $this->asClient()->postJson('/api/client/messages/send', [
            'phone_number_id' => $this->theirs->id, 'to' => '919811000001', 'text' => 'x',
        ])->assertNotFound();

        $this->asClient()->postJson('/api/client/messages/send-template', [
            'phone_number_id' => $this->mine->id, 'to' => '919900000001', 'template_id' => $tplTheirs->id, 'params' => ['X'],
        ])->assertNotFound();

        $this->asClient()->postJson('/api/client/messages/send-template', [
            'phone_number_id' => $this->theirs->id, 'to' => '919900000001', 'template_id' => $tplMine->id, 'params' => ['X'],
        ])->assertNotFound();
    }

    public function test_inactive_number_cannot_send(): void
    {
        $bad = PhoneNumber::create(['client_id' => $this->client->id, 'phone_number_id' => '300', 'display_number' => '+91 300', 'status' => 'DELETED']);
        Message::create(['phone_number_id' => $bad->id, 'contact_wa_id' => '919811000001', 'direction' => 'in', 'body' => 'hi', 'status' => 'received']);
        $this->asClient()->postJson('/api/client/messages/send', [
            'phone_number_id' => $bad->id, 'to' => '919811000001', 'text' => 'nope',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number_id');
    }

    public function test_manual_send_clears_needs_human_not_taken_over(): void
    {
        AiContactState::create([
            'phone_number_id' => $this->mine->id, 'contact_wa_id' => '919811000001',
            'needs_human' => true, 'taken_over' => true,
        ]);
        $this->asClient()->postJson('/api/client/messages/send', [
            'phone_number_id' => $this->mine->id, 'to' => '919811000001', 'text' => 'Human here',
        ])->assertCreated();
        $st = AiContactState::where('phone_number_id', $this->mine->id)->where('contact_wa_id', '919811000001')->first();
        $this->assertFalse((bool) $st->needs_human);
        $this->assertTrue((bool) $st->taken_over);
    }

    public function test_client_send_rate_limit(): void
    {
        RateLimiter::clear('cs:'.$this->client->id);
        $last = null;
        for ($i = 0; $i < 31; $i++) {
            $last = $this->asClient()->postJson('/api/client/messages/send', [
                'phone_number_id' => $this->mine->id, 'to' => '919811000001', 'text' => "m$i",
            ]);
            if ($last->status() === 429) break;
        }
        $this->assertSame(429, $last->status(), 'Expected 429 after exceeding 30 client-send requests per minute');
    }
}
