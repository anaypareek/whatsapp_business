<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssignAndAdminAppTest extends TestCase
{
    use RefreshDatabase;

    protected Client $a; protected User $admin; protected PhoneNumber $free;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['access_token' => 'EAAtoken', 'waba_id' => '999', 'app_secret' => 'shh'] as $k => $v) Setting::put($k, $v);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'ad@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->a = Client::create(['name' => 'Alpha', 'email' => 'alpha@c.t', 'password' => 'password123']);
        $this->free = PhoneNumber::create(['phone_number_id' => '3333', 'display_number' => '+91 3']);
        foreach (range(1, 4) as $i) Message::create(['phone_number_id' => $this->free->id, 'contact_wa_id' => '9190000000'.$i, 'direction' => 'in', 'body' => "old $i", 'status' => 'received']);
        Cache::flush();
    }

    protected function as($u) { $this->app['auth']->forgetGuards(); return $this->withToken($u->createToken('t')->plainTextToken); }

    public function test_assigning_a_number_exposes_past_chats_to_the_client_immediately(): void
    {
        $before = $this->as($this->a)->getJson('/api/client/chat/conversations')->assertOk()->json();
        $this->assertCount(0, $before['conversations']);
        $cur = $this->as($this->a)->getJson('/api/client/chat/sync?cursor='.$before['cursor'])->json('cursor');

        $this->as($this->admin)->putJson("/api/phone-numbers/{$this->free->id}/client", ['client_id' => $this->a->id])->assertOk()
            ->assertJsonPath('client.id', $this->a->id)->assertJsonPath('messages_count', 4);

        // the app's old cursor no longer matches → reset (full reload), which then includes the history
        $s = $this->as($this->a)->getJson('/api/client/chat/sync?cursor='.$cur)->assertOk()->json();
        $this->assertTrue($s['changed']); $this->assertTrue($s['reset']);
        $this->assertCount(4, $this->as($this->a)->getJson('/api/client/chat/conversations')->json('conversations'));
        $this->as($this->a)->getJson('/api/client/chat/thread?phone_number_id='.$this->free->id.'&contact=91900000001')->assertOk()->assertJsonCount(1, 'messages');
        $this->assertCount(4, $this->as($this->a)->getJson('/api/client/conversations')->json()); // web portal Chat

        // unassign → hidden again
        $this->as($this->admin)->putJson("/api/phone-numbers/{$this->free->id}/client", ['client_id' => null])->assertOk();
        $this->assertCount(0, $this->as($this->a)->getJson('/api/client/chat/conversations')->json('conversations'));
        $this->as($this->a)->putJson("/api/phone-numbers/{$this->free->id}/client", ['client_id' => $this->a->id])->assertStatus(403);
    }

    public function test_sync_numbers_from_meta_imports_and_keeps_assignment(): void
    {
        $this->free->update(['client_id' => $this->a->id]);
        Http::fake(['*/phone_numbers*' => Http::response(['data' => [
            ['id' => '3333', 'display_phone_number' => '+91 3', 'verified_name' => 'Old', 'status' => 'CONNECTED'],
            ['id' => '4444', 'display_phone_number' => '+91 4', 'verified_name' => 'New', 'status' => 'CONNECTED']]]), '*' => Http::response(['data' => []])]);
        $this->as($this->admin)->postJson('/api/phone-numbers-sync')->assertOk()->assertJsonPath('synced', 2);
        $this->assertSame($this->a->id, $this->free->fresh()->client_id);
        $this->assertDatabaseHas('phone_numbers', ['phone_number_id' => '4444', 'client_id' => null]);
    }

    public function test_bulk_assign_templates_shows_approved_in_my_templates(): void
    {
        $t1 = MessageTemplate::create(['name' => 'one', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => []]);
        $t2 = MessageTemplate::create(['name' => 'two', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => []]);
        $t3 = MessageTemplate::create(['name' => 'three', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'PENDING', 'components' => []]);
        $this->as($this->admin)->postJson('/api/templates-assign', ['ids' => [$t1->id, $t2->id, $t3->id], 'client_id' => $this->a->id])->assertOk()->assertJsonPath('updated', 3);
        $mine = collect($this->as($this->a)->getJson('/api/client/templates')->assertOk()->json())->pluck('name')->sort()->values()->all();
        $this->assertSame(['one', 'two'], $mine); // pending isn't listed
        $this->as($this->admin)->postJson('/api/templates-assign', ['ids' => [$t2->id], 'client_id' => null])->assertOk();
        $this->assertCount(1, $this->as($this->a)->getJson('/api/client/templates')->json());
        $this->as($this->admin)->postJson('/api/templates-assign', ['ids' => []])->assertStatus(422);
    }

    public function test_admin_chat_spans_all_numbers_with_filter(): void
    {
        $mine = PhoneNumber::create(['phone_number_id' => '5555', 'display_number' => '+91 5', 'client_id' => $this->a->id]);
        Message::create(['phone_number_id' => $mine->id, 'contact_wa_id' => '91800', 'direction' => 'in', 'body' => 'yo', 'status' => 'received']);
        $all = $this->as($this->admin)->getJson('/api/admin/chat/conversations')->assertOk()->json();
        $this->assertCount(5, $all['conversations']);
        $this->assertSame(5, $all['unread_total']);
        $this->assertCount(2, $all['numbers']);
        $this->assertCount(1, $this->as($this->admin)->getJson('/api/admin/chat/conversations?phone_number_id='.$mine->id)->json('conversations'));
        $th = $this->as($this->admin)->getJson('/api/admin/chat/thread?phone_number_id='.$mine->id.'&contact=91800')->assertOk()->json();
        $this->assertCount(1, $th['messages']);
        $this->assertNotNull(Message::where('contact_wa_id', '91800')->first()->read_at);
        $s = $this->as($this->admin)->getJson('/api/admin/chat/sync?cursor='.$th['cursor'])->assertOk()->json();
        $this->assertFalse($s['changed']);
        Message::create(['phone_number_id' => $this->free->id, 'contact_wa_id' => '91700', 'direction' => 'in', 'body' => 'new', 'status' => 'received']);
        $s = $this->as($this->admin)->getJson('/api/admin/chat/sync?cursor='.$th['cursor'])->assertOk()->json();
        $this->assertTrue($s['changed']); $this->assertFalse($s['reset']); $this->assertContains('new', array_column($s['messages'], 'body')); // deltas (same-second rows may repeat; the app dedupes by id)
        // clients can't use the admin chat
        $this->as($this->a)->getJson('/api/admin/chat/conversations')->assertStatus(403);
    }

    public function test_admin_reply_text_goes_through_existing_send_endpoint(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
        $this->as($this->admin)->postJson('/api/messages/send', ['phone_number_id' => $this->free->id, 'to' => '919000000001', 'text' => 'hello'])->assertCreated();
        $this->assertDatabaseHas('messages', ['wamid' => 'wamid.OUT1', 'direction' => 'out']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages') && $r['text']['body'] === 'hello');
    }

    public function test_staff_device_registration_and_inbound_push_to_staff(): void
    {
        $tok = str_repeat('t', 40);
        $this->as($this->admin)->postJson('/api/admin/devices', ['token' => $tok, 'app_version' => '1.0.0'])->assertCreated();
        $this->assertDatabaseHas('user_device_tokens', ['user_id' => $this->admin->id, 'app_version' => '1.0.0']);
        $this->as($this->a)->postJson('/api/admin/devices', ['token' => $tok])->assertStatus(403);

        Setting::put('fcm_service_account', json_encode(['type' => 'service_account', 'project_id' => 'p1', 'client_email' => 'x@p1.iam', 'private_key' => 'k']));
        $fcm = \Mockery::mock(\App\Services\FcmService::class)->makePartial();
        $fcm->shouldReceive('sendToStaff')->once()->withArgs(fn ($title, $body, $data) => $data['contact'] === '91700' && str_contains($title, '+91 3'))->andReturn(['sent' => 1]);
        $fcm->shouldNotReceive('sendToClient'); // unassigned number → staff only
        $this->app->instance(\App\Services\FcmService::class, $fcm);
        $m = Message::create(['phone_number_id' => $this->free->id, 'contact_wa_id' => '91700', 'direction' => 'in', 'body' => 'ping', 'status' => 'received']);
        (new \App\Jobs\SendIncomingMessagePush($m->id))->handle($fcm);

        $this->as($this->admin)->deleteJson('/api/admin/devices', ['token' => $tok])->assertOk()->assertJsonPath('removed', 1);
        $this->assertSame(0, UserDeviceToken::count());
    }

    public function test_master_app_requests_endpoints_work_for_admin_token(): void
    {
        $this->as($this->admin)->getJson('/api/requests/pending-counts')->assertOk()->assertJsonStructure(['numbers']);
        $this->as($this->admin)->getJson('/api/number-requests')->assertOk();
        $this->as($this->admin)->getJson('/api/template-requests')->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'ad@t.l', 'password' => 'password123'])->assertOk()->assertJsonStructure(['token', 'user' => ['is_admin']]);
    }
}
