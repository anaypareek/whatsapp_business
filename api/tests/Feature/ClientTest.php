<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        Setting::put('access_token', 'EAAtoken');
        Http::fake(['graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.C'.bin2hex(random_bytes(5))]]])]);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function as(Client $c) { $this->app['auth']->forgetGuards(); return $this->withToken($c->createToken('c')->plainTextToken); }

    public function test_admin_creates_edits_resets_and_deactivates_clients(): void
    {
        $id = $this->admin()->postJson('/api/clients', ['name' => 'Ravi', 'company' => 'Oswal Retail', 'email' => 'ravi@client.test', 'password' => 'secret-pass-1'])
            ->assertCreated()->assertJsonPath('is_active', true)->assertJsonMissingPath('password')->json('id');
        $this->admin()->postJson('/api/clients', ['name' => 'Dup', 'email' => 'ravi@client.test', 'password' => 'secret-pass-1'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->admin()->postJson('/api/clients', ['name' => 'Short', 'email' => 's@client.test', 'password' => 'x'])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->admin()->putJson("/api/clients/$id", ['company' => 'Oswal Wholesale', 'name' => 'Ravi K'])->assertOk()->assertJsonPath('company', 'Oswal Wholesale');

        // set an explicit password
        $this->admin()->postJson("/api/clients/$id/reset-password", ['password' => 'new-pass-123'])->assertOk()->assertJsonPath('generated', false);
        $this->assertTrue(Hash::check('new-pass-123', Client::find($id)->password));
        // generated password is returned once and works
        $gen = $this->admin()->postJson("/api/clients/$id/reset-password")->assertOk()->assertJsonPath('generated', true)->json('password');
        $this->assertGreaterThanOrEqual(12, strlen($gen));
        $this->postJson('/api/client/auth/login', ['email' => 'ravi@client.test', 'password' => $gen])->assertOk();

        // deactivate: tokens revoked, login refused
        $c = Client::find($id); $tok = $c->createToken('x')->plainTextToken;
        $this->admin()->putJson("/api/clients/$id", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->assertSame(0, $c->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($tok)->getJson('/api/client/auth/me')->assertStatus(401);
        $this->postJson('/api/client/auth/login', ['email' => 'ravi@client.test', 'password' => $gen])->assertStatus(422);

        $this->admin()->getJson('/api/clients?status=inactive')->assertJsonCount(1);
        $this->admin()->getJson('/api/clients?status=active')->assertJsonCount(0);
    }

    public function test_clients_list_shows_numbers_and_template_counts(): void
    {
        $c = Client::create(['name' => 'Ravi', 'email' => 'r@c.t', 'password' => 'password123']);
        PhoneNumber::create(['client_id' => $c->id, 'phone_number_id' => '1', 'display_number' => '+91 1']);
        PhoneNumber::create(['client_id' => $c->id, 'phone_number_id' => '2', 'display_number' => '+91 2']);
        PhoneNumber::create(['phone_number_id' => '3', 'display_number' => '+91 3']);
        $t = MessageTemplate::create(['name' => 't', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => []]);
        $this->admin()->putJson("/api/templates/{$t->id}/client", ['client_id' => $c->id])->assertOk()->assertJsonPath('client.name', 'Ravi');
        $this->admin()->getJson('/api/clients')->assertOk()->assertJsonPath('0.phone_numbers_count', 2)->assertJsonPath('0.templates_count', 1)
            ->assertJsonCount(2, '0.phone_numbers');
        $this->admin()->getJson("/api/clients/{$c->id}")->assertJsonCount(1, 'templates');
        $this->admin()->getJson('/api/clients?q=rav')->assertJsonCount(1);
    }

    public function test_number_master_client_assignment_and_filter(): void
    {
        $a = Client::create(['name' => 'Alpha', 'email' => 'a@c.t', 'password' => 'password123']);
        $b = Client::create(['name' => 'Beta', 'email' => 'b@c.t', 'password' => 'password123']);
        $id = $this->admin()->postJson('/api/phone-numbers', ['phone_number_id' => '10', 'display_number' => '+91 10', 'client_id' => $a->id])
            ->assertCreated()->assertJsonPath('client.name', 'Alpha')->json('id');
        $this->admin()->postJson('/api/phone-numbers', ['phone_number_id' => '11', 'display_number' => '+91 11'])->assertCreated()->assertJsonPath('client', null);
        $this->admin()->postJson('/api/phone-numbers', ['phone_number_id' => '12', 'display_number' => '+91 12', 'client_id' => 9999])->assertStatus(422);
        $this->admin()->putJson("/api/phone-numbers/$id", ['client_id' => $b->id])->assertOk()->assertJsonPath('client.name', 'Beta');
        $this->admin()->getJson("/api/phone-numbers?client_id={$b->id}")->assertJsonCount(1)->assertJsonPath('0.phone_number_id', '10');
        $this->admin()->getJson('/api/phone-numbers?client_id=none')->assertJsonCount(1)->assertJsonPath('0.phone_number_id', '11');
        $this->admin()->getJson('/api/phone-numbers')->assertJsonCount(2);
        $this->admin()->putJson("/api/phone-numbers/$id", ['client_id' => null])->assertOk()->assertJsonPath('client', null);
        $this->admin()->getJson('/api/clients-list')->assertOk()->assertJsonCount(2);
    }

    public function test_client_portal_is_scoped_to_own_numbers(): void
    {
        $c = Client::create(['name' => 'Ravi', 'email' => 'r@c.t', 'password' => 'password123']);
        $mine = PhoneNumber::create(['client_id' => $c->id, 'phone_number_id' => '100', 'display_number' => '+91 100']);
        $other = PhoneNumber::create(['phone_number_id' => '200', 'display_number' => '+91 200']);
        Message::create(['phone_number_id' => $mine->id, 'contact_wa_id' => '9101', 'direction' => 'in', 'body' => 'hi', 'status' => 'received']);
        Message::create(['phone_number_id' => $other->id, 'contact_wa_id' => '9202', 'direction' => 'in', 'body' => 'secret', 'status' => 'received']);

        $tok = $this->postJson('/api/client/auth/login', ['email' => 'r@c.t', 'password' => 'password123'])->assertOk()->json('token');
        $this->assertNotNull($c->fresh()->last_login_at);
        $this->app['auth']->forgetGuards();
        $h = fn () => $this->withToken($tok);
        $h()->getJson('/api/client/numbers')->assertJsonCount(1)->assertJsonPath('0.phone_number_id', '100');
        $h()->getJson('/api/client/conversations')->assertJsonCount(1)->assertJsonPath('0.contact_wa_id', '9101');
        $h()->getJson('/api/client/dashboard')->assertJsonPath('numbers', 1)->assertJsonPath('total_received', 1)->assertJsonPath('unread', 1);
        $h()->getJson("/api/client/messages/thread?phone_number_id={$mine->id}&contact=9101")->assertJsonCount(1);
        $h()->getJson("/api/client/messages/thread?phone_number_id={$other->id}&contact=9202")->assertNotFound();
        $h()->postJson('/api/client/messages/send', ['phone_number_id' => $mine->id, 'to' => '9101000000', 'text' => 'Hello'])->assertCreated()->assertJsonPath('source', 'client');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/100/messages'));
        $h()->postJson('/api/client/messages/send', ['phone_number_id' => $other->id, 'to' => '9202000000', 'text' => 'x'])->assertNotFound();

        // client tokens can't use staff/admin APIs, and staff tokens can't use client APIs
        $h()->getJson('/api/phone-numbers')->assertForbidden();
        $h()->getJson('/api/clients')->assertForbidden();
        $h()->getJson('/api/dashboard')->assertForbidden();
        $this->admin()->getJson('/api/client/numbers')->assertForbidden();
        $this->postJson('/api/client/auth/login', ['email' => 'r@c.t', 'password' => 'wrong'])->assertStatus(422);
    }

    public function test_non_admin_staff_cannot_manage_clients(): void
    {
        $u = User::create(['name' => 'Op', 'email' => 'op@t.l', 'password' => 'password123']);
        $this->app['auth']->forgetGuards();
        $this->withToken($u->createToken('t')->plainTextToken)->postJson('/api/clients', ['name' => 'X', 'email' => 'x@c.t', 'password' => 'password123'])->assertForbidden();
    }
}
