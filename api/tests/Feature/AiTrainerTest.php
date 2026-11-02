<?php
namespace Tests\Feature;

use App\Models\AiContactState;
use App\Models\AiExample;
use App\Models\AiKnowledgeChunk;
use App\Models\AiOpenQuestion;
use App\Models\AiProductImage;
use App\Models\AiReplySetting;
use App\Models\AiTrainerMessage;
use App\Models\Client;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiTrainerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected PhoneNumber $n;
    protected PhoneNumber $other;
    protected string $answer = 'Okay!';
    protected int $status = 200;
    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999', 'ai_api_key' => 'sk-test-SECRET1234', 'ai_base_url' => 'https://llm.test/v1'] as $k => $v) Setting::put($k, $v);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->n = PhoneNumber::create(['phone_number_id' => '7001', 'display_number' => '+91 90909 00894', 'name' => 'Oswal']);
        $this->other = PhoneNumber::create(['phone_number_id' => '7002', 'display_number' => '+91 90000 70002', 'name' => 'Other']);
        Http::fake([
            'llm.test/*' => fn () => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $this->answer]]]], $this->status),
            'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.O'.bin2hex(random_bytes(5))]]]),
        ]);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function say(string $msg, array $files = [], ?PhoneNumber $n = null)
    {
        return $this->admin()->post('/api/ai-train/'.($n ?? $this->n)->id.'/trainer', ['message' => $msg, 'files' => $files], ['Accept' => 'application/json']);
    }
    protected function llm() { return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), 'llm.test'))->values(); }
    protected function metaCount() { return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'graph.facebook.com'))->count(); }

    public function test_actions_are_applied_and_reported(): void
    {
        $this->answer = "Noted! Toor dal is ₹145/kg and Sunday delivery is off 👍\n<actions>[{\"type\":\"save_knowledge\",\"title\":\"Dal prices\",\"text\":\"Toor dal costs 145 rupees per kg.\"},"
            .'{"type":"save_rule","question":"Do you deliver on Sunday?","answer":"Sorry, no Sunday delivery 🙏 Monday to Saturday only."},{"type":"bogus"}]</actions>';
        $r = $this->say('toor dal 145 per kg. no delivery on sunday')->assertOk();
        $r->assertJsonPath('saved', ['Knowledge: Dal prices', 'Rule: “Do you deliver on Sunday?”'])
          ->assertJsonPath('reply.content', 'Noted! Toor dal is ₹145/kg and Sunday delivery is off 👍');
        $this->assertSame('Sorry, no Sunday delivery 🙏 Monday to Saturday only.', AiExample::where('phone_number_id', $this->n->id)->value('answer'));
        $this->assertStringContainsString('145 rupees', AiKnowledgeChunk::where('phone_number_id', $this->n->id)->value('content'));
        $sys = $this->llm()->last()['messages'][0]['content'];
        $this->assertStringContainsString('TRAINER assistant', $sys);
        $this->assertStringNotContainsString('PRODUCT IMAGES', $sys); // separate from customer prompt
        $this->assertSame(0, $this->metaCount());
        $this->assertSame(2, AiTrainerMessage::count());
        // history persisted & returned
        $this->admin()->getJson("/api/ai-train/{$this->n->id}/trainer")->assertOk()->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.1.saved.0', 'Knowledge: Dal prices');
    }

    public function test_clarifying_flow_then_image_saved(): void
    {
        $this->answer = 'Nice photo! Which product is this, and any tags (e.g. size, brand)?';
        $this->say('', [UploadedFile::fake()->image('IMG_2041.jpg')])->assertOk()->assertJsonPath('saved', []);
        $this->assertSame(0, AiProductImage::count());
        $pending = AiTrainer::pendingImages($this->n);
        $this->assertCount(1, $pending);
        $ref = $pending[0]['ref'];
        Storage::disk('public')->assertExists($pending[0]['path']);
        $this->assertStringContainsString($ref, $this->llm()->last()['messages'][0]['content']);
        $this->answer = "Saved 👍 <actions>[{\"type\":\"save_image\",\"ref\":\"$ref\",\"name\":\"Basmati rice 5kg\",\"tags\":\"rice, basmati\"}]</actions>";
        $this->say('basmati rice 5kg bag')->assertOk()->assertJsonPath('saved.0', 'Image: Basmati rice 5kg (rice, basmati)');
        $img = AiProductImage::first();
        $this->assertSame([$this->n->id, $pending[0]['path']], [$img->phone_number_id, $img->path]);
        $this->assertCount(0, AiTrainer::pendingImages($this->n));
        // context: earlier turns included, compact
        $msgs = $this->llm()->last()['messages'];
        $this->assertCount(4, $msgs); // system + user/assistant/user
        $this->assertStringContainsString('[attached image', $msgs[1]['content']);
        // documents in the composer are ingested immediately
        $this->answer = 'Got your price list 👍';
        $this->say('price list', [UploadedFile::fake()->createWithContent('prices.csv', "product,price\nMoong dal,130\n")])->assertOk()->assertJsonPath('saved.0', 'Knowledge file: prices.csv (1 chunks)');
    }

    public function test_context_is_limited_to_last_ten_messages(): void
    {
        foreach (range(1, 14) as $i) AiTrainerMessage::create(['phone_number_id' => $this->n->id, 'role' => $i % 2 ? 'user' : 'assistant', 'content' => "m$i"]);
        $this->say('latest');
        $this->assertCount(1 + AiTrainer::CONTEXT, $this->llm()->last()['messages']);
    }

    public function test_handoffs_become_open_questions_and_answers_resolve_them(): void
    {
        AiReplySetting::create(['phone_number_id' => $this->n->id, 'enabled' => true, 'allowlist' => ['919811000001'], 'max_per_hour' => 50]);
        $this->answer = '[HANDOFF]';
        $this->seq++;
        $p = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '919090900894', 'phone_number_id' => '7001'], 'contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => '919811000001']],
            'messages' => [['from' => '919811000001', 'id' => 'wamid.IN1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Do you have organic jaggery?']]]]]]]]];
        $raw = json_encode($p);
        $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw)->assertOk();
        $q = AiOpenQuestion::firstOrFail();
        $this->assertSame(['Do you have organic jaggery?', 'open', '919811000001'], [$q->question, $q->status, $q->contact_wa_id]);
        $this->admin()->getJson("/api/ai-train/{$this->n->id}/trainer")->assertJsonPath('open_questions.0.question', 'Do you have organic jaggery?')
            ->assertJsonPath('greeting', fn ($g) => str_contains($g, 'organic jaggery'));
        $this->admin()->getJson("/api/ai-train/{$this->other->id}/trainer")->assertJsonCount(0, 'open_questions')->assertJsonPath('greeting', null);

        $this->answer = "Thanks! I'll tell customers that 😊 <actions>[{\"type\":\"resolve_question\",\"id\":{$q->id},\"answer\":\"Yes! Organic jaggery 1kg is ₹120 😊\"}]</actions>";
        $this->say('yes we have organic jaggery, 120 per kg')->assertOk()->assertJsonPath('saved.0', 'Answered: “Do you have organic jaggery?”')->assertJsonPath('open_questions', 0);
        $this->assertStringContainsString("#{$q->id}: \"Do you have organic jaggery?\"", $this->llm()->last()['messages'][0]['content']);
        $this->assertSame('resolved', $q->fresh()->status);
        $this->assertSame('Yes! Organic jaggery 1kg is ₹120 😊', AiExample::where('phone_number_id', $this->n->id)->where('question', 'Do you have organic jaggery?')->value('answer'));
        $this->assertTrue(AiContactState::first()->needs_human); // a human still owes that customer a reply
    }

    public function test_actions_are_scoped_to_the_number(): void
    {
        $q = AiOpenQuestion::create(['phone_number_id' => $this->other->id, 'question' => 'other q']);
        AiTrainerMessage::create(['phone_number_id' => $this->other->id, 'role' => 'user', 'content' => 'x', 'attachments' => [['ref' => 'img-abc', 'type' => 'image', 'filename' => 'a.jpg', 'path' => 'ai-products/2/a.jpg', 'saved' => false]]]);
        $this->answer = "ok <actions>[{\"type\":\"resolve_question\",\"id\":{$q->id},\"answer\":\"leak\"},{\"type\":\"save_image\",\"ref\":\"img-abc\",\"name\":\"stolen\"}]</actions>";
        $this->say('hello')->assertOk()->assertJsonPath('saved', []);
        $this->assertSame('open', $q->fresh()->status);
        $this->assertSame(0, AiProductImage::count() + AiExample::count());
        $this->assertStringNotContainsString('other q', $this->llm()->last()['messages'][0]['content']);
        $this->admin()->deleteJson("/api/ai-train/{$this->n->id}/questions/{$q->id}")->assertNotFound();
    }

    public function test_admin_only_errors_and_clear(): void
    {
        $c = Client::create(['name' => 'Oswal', 'email' => 'o@c.t', 'password' => 'password123']);
        $this->app['auth']->forgetGuards();
        $tok = $c->createToken('c')->plainTextToken;
        $this->withToken($tok)->getJson("/api/ai-train/{$this->n->id}/trainer")->assertForbidden();
        $this->withToken($tok)->postJson("/api/ai-train/{$this->n->id}/trainer", ['message' => 'hi'])->assertForbidden();
        $this->say('')->assertStatus(422);
        $this->say('x', [UploadedFile::fake()->create('a.exe', 1)])->assertStatus(422);
        $this->status = 500;
        $this->say('hi')->assertStatus(502);
        $this->assertSame(1, AiTrainerMessage::where('role', 'user')->count());
        Setting::put('ai_api_key', null);
        $this->say('hi')->assertStatus(422);
        $this->admin()->deleteJson("/api/ai-train/{$this->n->id}/trainer")->assertOk();
        $this->assertSame(0, AiTrainerMessage::count());
    }
}
