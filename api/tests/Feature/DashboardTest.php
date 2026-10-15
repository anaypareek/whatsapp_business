<?php
namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AutoReplyRule;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    public function test_dashboard_stats(): void
    {
        $u = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $p = PhoneNumber::create(['phone_number_id' => '1', 'display_number' => '+91 1', 'name' => 'Main']);
        PhoneNumber::create(['phone_number_id' => '2', 'display_number' => '+91 2']);
        $mk = fn ($dir, $contact, $status = 'sent', $at = null, $name = null) => Message::create(['phone_number_id' => $p->id, 'contact_wa_id' => $contact,
            'contact_name' => $name, 'direction' => $dir, 'body' => 'x', 'status' => $status, 'created_at' => $at ?? now()]);
        $mk('out', '911', 'sent'); $mk('out', '911', 'failed'); $mk('in', '911', 'received', null, 'Ravi'); $mk('in', '912', 'received');
        $mk('out', '913', 'delivered', now()->subDays(2)); $mk('in', '913', 'received', now()->subDays(10));
        foreach (['APPROVED', 'APPROVED', 'PENDING', 'REJECTED', 'PAUSED'] as $i => $s) MessageTemplate::create(['name' => "t$i", 'language' => 'en', 'category' => 'UTILITY', 'status' => $s, 'components' => []]);
        AutoReplyRule::create(['match_type' => 'exact', 'trigger' => 'a', 'reply' => 'b', 'enabled' => true]);
        AutoReplyRule::create(['match_type' => 'exact', 'trigger' => 'c', 'reply' => 'd', 'enabled' => false]);
        ApiKey::generate('k');

        $r = $this->withToken($u->createToken('t')->plainTextToken)->getJson('/api/dashboard')->assertOk();
        $r->assertJsonPath('messages.sent_today', 2)->assertJsonPath('messages.received_today', 2)->assertJsonPath('messages.failed_today', 1)
            ->assertJsonPath('messages.total_sent', 3)->assertJsonPath('messages.total_received', 3)
            ->assertJsonPath('phone_numbers', 2)->assertJsonPath('templates.approved', 2)->assertJsonPath('templates.pending', 1)
            ->assertJsonPath('templates.rejected', 1)->assertJsonPath('templates.other', 1)->assertJsonPath('templates.total', 5)
            ->assertJsonPath('auto_replies_active', 1)->assertJsonPath('conversations', 3)->assertJsonPath('contacts', 3)->assertJsonPath('api_keys', 1)
            ->assertJsonCount(7, 'series')->assertJsonCount(3, 'recent_conversations');
        $series = $r->json('series');
        $this->assertSame(now()->toDateString(), $series[6]['date']);
        $this->assertSame(['sent' => 2, 'received' => 2], ['sent' => $series[6]['sent'], 'received' => $series[6]['received']]);
        $this->assertSame(1, $series[4]['sent']);
        $this->assertSame('Ravi', collect($r->json('recent_conversations'))->firstWhere('contact_wa_id', '911')['contact_name']);
    }
}
