<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SampleTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken(User::firstOrCreate(['email' => 'a@t.l'], ['name' => 'A', 'password' => 'password123', 'is_admin' => true])->createToken('t')->plainTextToken); }
    protected function tpl(string $name, string $lang = 'en_US', array $x = []) { return MessageTemplate::create(['name' => $name, 'language' => $lang, 'category' => 'UTILITY', 'status' => 'APPROVED', 'meta_id' => 'M'.random_int(1, 1e9), 'components' => []] + $x); }

    public function test_detection_rule(): void
    {
        foreach (['hello_world', 'sample_shipping_confirmation', 'sample_movie_ticket_confirmation', 'Sample_Flight_Confirmation'] as $n) $this->assertTrue(MessageTemplate::isSample($n), $n);
        foreach (['order_update', 'hello_world_2', 'my_sample_offer', 'shipping_confirmation'] as $n) $this->assertFalse(MessageTemplate::isSample($n), $n);
        config(['whatsapp.sample_templates.names' => ['hello_world', 'demo_tpl']]);
        $this->assertTrue(MessageTemplate::isSample('demo_tpl'));
    }

    public function test_sync_skips_samples_and_purges_existing_ones(): void
    {
        foreach (['access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        $old = $this->tpl('sample_issue_resolution', 'es');
        $row = fn ($id, $n, $l = 'en_US', $x = []) => ['id' => $id, 'name' => $n, 'language' => $l, 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'x']]] + $x;
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            $row('1', 'hello_world'), $row('2', 'sample_shipping_confirmation', 'pt_BR'), $row('3', 'sample_purchase_feedback', 'id'),
            $row('4', 'order_update'), $row('5', 'delivery_update', 'hi', ['library_template_name' => 'delivery_update_1']),
        ], 'paging' => ['cursors' => []]])]);
        $this->admin()->postJson('/api/templates-sync')->assertOk()->assertJsonPath('synced', 2)->assertJsonPath('skipped_samples', 3);
        $this->assertEqualsCanonicalizing(['order_update', 'delivery_update'], MessageTemplate::withoutGlobalScopes()->pluck('name')->all());
        $this->assertDatabaseMissing('message_templates', ['id' => $old->id]);
        $this->admin()->getJson('/api/templates')->assertJsonCount(2);
    }

    public function test_purge_deletes_unused_and_hides_referenced_samples(): void
    {
        $num = PhoneNumber::create(['phone_number_id' => '1111', 'display_number' => '+91 1']);
        $c = Client::create(['name' => 'C', 'email' => 'c@c.t', 'password' => 'password123']);
        $own = $this->tpl('order_update', 'en_US', ['client_id' => $c->id]);
        $unused = $this->tpl('sample_flight_confirmation', 'id');
        $used = $this->tpl('hello_world', 'en_US', ['client_id' => $c->id]);
        Message::create(['phone_number_id' => $num->id, 'contact_wa_id' => '91', 'direction' => 'out', 'type' => 'template', 'body' => 'hi', 'status' => 'sent',
            'template_name' => 'hello_world (en_US)', 'message_template_id' => $used->id]);

        // run the migration logic (also proves it is re-runnable)
        $m = require database_path('migrations/2026_10_16_160000_hide_meta_sample_templates.php');
        $m->up(); $m->up();

        $this->assertDatabaseMissing('message_templates', ['id' => $unused->id]);
        $this->assertDatabaseHas('message_templates', ['id' => $used->id, 'is_sample' => true]);
        $this->assertDatabaseHas('messages', ['message_template_id' => $used->id]);
        $this->assertNull(MessageTemplate::find($used->id));

        // every list / count excludes it
        $this->admin()->getJson('/api/templates')->assertJsonCount(1)->assertJsonPath('0.name', 'order_update');
        $this->admin()->getJson("/api/templates/{$used->id}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($c->createToken('c')->plainTextToken)->getJson('/api/client/templates')->assertJsonCount(1)->assertJsonPath('0.id', $own->id);
        $this->assertSame(1, $c->templates()->count());
        $this->assertSame(1, MessageTemplate::count());
    }
}
