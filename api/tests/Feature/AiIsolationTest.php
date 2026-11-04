<?php
namespace Tests\Feature;

use App\Models\AiExample;
use App\Models\AiOpenQuestion;
use App\Models\AiProductImage;
use App\Models\AiReplySetting;
use App\Models\AiTrainerMessage;
use App\Models\Client;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Client-portal Train AI + strict per-number knowledge isolation + unanswered questions. */
class AiIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Client $ca; protected Client $cb;
    protected PhoneNumber $a; protected PhoneNumber $b;
    protected string $answer = 'ok';
    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999', 'ai_api_key' => 'sk-test-SECRET1234', 'ai_base_url' => 'https://llm.test/v1',
            'orders_api_base_url' => 'https://orders.test/api', 'orders_api_key' => 'ord-SECRET9876'] as $k => $v) Setting::put($k, $v);
        $this->ca = Client::create(['name' => 'Oswal', 'email' => 'o@c.t', 'password' => 'password123']);
        $this->cb = Client::create(['name' => 'Beta', 'email' => 'b@c.t', 'password' => 'password123']);
        $this->a = PhoneNumber::create(['client_id' => $this->ca->id, 'phone_number_id' => '7001', 'display_number' => '+91 90909 00894', 'name' => 'Oswal']);
        $this->b = PhoneNumber::create(['client_id' => $this->cb->id, 'phone_number_id' => '7002', 'display_number' => '+91 90000 70002', 'name' => 'Beta']);
        foreach ([$this->a, $this->b] as $n) AiReplySetting::create(['phone_number_id' => $n->id, 'enabled' => true, 'allowlist' => ['919811000001'], 'max_per_hour' => 50]);
        Http::fake([
            'llm.test/*' => fn () => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $this->answer]]]]),
            'orders.test/*' => fn () => Http::response(['orders' => [['order_id' => 'OS-1', 'status' => 'Shipped']]]),
            'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.O'.bin2hex(random_bytes(5))]]]),
        ]);
    }

    protected function as(Client|User $u) { $this->app['auth']->forgetGuards(); return $this->withToken($u->createToken('t')->plainTextToken); }
    protected function llm() { return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), 'llm.test'))->values(); }
    protected function hook(string $pnid, string $body, string $from = '919811000001')
    {
        $this->seq++;
        $p = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '91', 'phone_number_id' => $pnid], 'contacts' => [['profile' => ['name' => 'Ravi Kumar'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => 'wamid.IN'.$this->seq, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $body]]]]]]]]];
        $raw = json_encode($p);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw)->assertOk();
    }

    /** Seed number A with rich data, all containing the word "saffron". */
    protected function seedA(): void
    {
        $this->a->update([]);
        AiReplySetting::where('phone_number_id', $this->a->id)->update(['knowledge_base' => 'KB-A-SECRET saffron 450']);
        AiExample::create(['phone_number_id' => $this->a->id, 'question' => 'saffron price?', 'answer' => 'RULE-A-SECRET saffron 450']);
        AiKnowledge::ingestText($this->a, 'A doc', 'CHUNK-A-SECRET kashmiri saffron 1g 450');
        AiProductImage::create(['phone_number_id' => $this->a->id, 'name' => 'IMG-A-SECRET saffron', 'tags' => 'saffron', 'path' => 'ai-products/1/s.jpg']);
        AiOpenQuestion::create(['phone_number_id' => $this->a->id, 'question' => 'OPENQ-A-SECRET saffron?']);
        AiTrainerMessage::create(['phone_number_id' => $this->a->id, 'role' => 'user', 'content' => 'TRAINER-A-SECRET saffron']);
    }

    public function test_number_a_knowledge_never_reaches_number_b_customer_prompt(): void
    {
        $this->seedA();
        $this->hook('7002', 'saffron price? photo of saffron please');
        $p = json_encode($this->llm()->last()['messages']);
        foreach (['KB-A', 'RULE-A', 'CHUNK-A', 'IMG-A', 'OPENQ-A', 'TRAINER-A'] as $s) $this->assertStringNotContainsString($s, $p);
        // sanity: A's own prompt does contain them
        $this->hook('7001', 'saffron price? photo of saffron please');
        $pa = $this->llm()->last()['messages'][0]['content'];
        foreach (['KB-A', 'RULE-A', 'CHUNK-A', 'IMG-A'] as $s) $this->assertStringContainsString($s, $pa);
    }

    public function test_number_a_data_never_reaches_number_b_trainer_or_playground(): void
    {
        $this->seedA();
        $this->as($this->cb)->post("/api/client/ai-train/{$this->b->id}/trainer", ['message' => 'saffron?'], ['Accept' => 'application/json'])->assertOk();
        $this->as($this->cb)->postJson("/api/client/ai-train/{$this->b->id}/chat", ['messages' => [['role' => 'user', 'content' => 'saffron price photo']]])->assertOk();
        $all = $this->llm()->map(fn ($r) => json_encode($r['messages']))->implode('');
        foreach (['KB-A', 'RULE-A', 'CHUNK-A', 'IMG-A', 'OPENQ-A', 'TRAINER-A'] as $s) $this->assertStringNotContainsString($s, $all);
        // image id of A is ignored for B
        $img = AiProductImage::first();
        $this->answer = "here [IMAGE:{$img->id}]";
        $this->as($this->cb)->postJson("/api/client/ai-train/{$this->b->id}/chat", ['messages' => [['role' => 'user', 'content' => 'photo']]])->assertJsonPath('image', null);
    }

    public function test_order_lookup_is_opt_in_per_number(): void
    {
        AiReplySetting::where('phone_number_id', $this->a->id)->update(['orders_lookup' => true]);
        $this->hook('7002', 'where is my order');
        $this->assertStringNotContainsString('ORDER_LOOKUP', $this->llm()->last()['messages'][0]['content']);
        $this->answer = '[ORDER_LOOKUP:mine]'; // model emits it anyway on B -> no lookup, handoff
        $this->hook('7002', 'order status OS-1');
        $this->assertCount(0, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'orders.test')));
        $this->hook('7001', 'order status');
        $this->assertStringContainsString('ORDER_LOOKUP', $this->llm()->last()['messages'][0]['content']);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'orders.test')));
    }

    public function test_client_trains_only_own_numbers_and_never_sees_keys(): void
    {
        $this->seedA();
        $c = $this->as($this->ca);
        $c->getJson("/api/client/ai-train/{$this->a->id}")->assertOk()->assertJsonCount(1, 'examples');
        $res = $this->as($this->ca)->getJson("/api/client/ai-train/{$this->a->id}/trainer")->assertOk();
        $this->assertStringNotContainsString('SECRET1234', $res->getContent());
        $this->assertStringNotContainsString('9876', $res->getContent());
        foreach (['', '/trainer', '/questions'] as $p) $this->as($this->ca)->getJson("/api/client/ai-train/{$this->b->id}$p")->assertNotFound();
        $this->as($this->ca)->post("/api/client/ai-train/{$this->b->id}/trainer", ['message' => 'x'], ['Accept' => 'application/json'])->assertNotFound();
        $this->as($this->ca)->postJson("/api/client/ai-train/{$this->b->id}/examples", ['question' => 'q', 'answer' => 'a'])->assertNotFound();
        $this->as($this->ca)->post("/api/client/ai-train/{$this->b->id}/files", ['file' => UploadedFile::fake()->createWithContent('a.txt', 'x')], ['Accept' => 'application/json'])->assertNotFound();
        // B's row via A's number id -> 404
        $eb = AiExample::create(['phone_number_id' => $this->b->id, 'question' => 'q', 'answer' => 'a']);
        $this->as($this->ca)->deleteJson("/api/client/ai-train/{$this->a->id}/examples/{$eb->id}")->assertNotFound();
        $this->assertNotNull($eb->fresh());
        // trainer via client works and saves to own number
        $this->answer = 'Saved <actions>[{"type":"save_rule","question":"Open on Sunday?","answer":"Yes 10-2"}]</actions>';
        $this->as($this->ca)->post("/api/client/ai-train/{$this->a->id}/trainer", ['message' => 'open sunday 10-2'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('saved.0', 'Rule: “Open on Sunday?”');
        $this->assertSame($this->a->id, AiExample::where('question', 'Open on Sunday?')->value('phone_number_id'));
        // global settings / admin AI settings unavailable to clients
        $this->as($this->ca)->getJson('/api/settings')->assertForbidden();
        $this->as($this->ca)->getJson('/api/ai-replies')->assertForbidden();
        $this->as($this->ca)->getJson("/api/client/ai-replies")->assertNotFound();
    }

    public function test_unanswered_questions_from_handoffs_list_and_answer(): void
    {
        $this->answer = '[HANDOFF]';
        $this->hook('7001', 'Do you have organic jaggery?');
        $this->hook('7001', 'Do you have organic jaggery?', '919811000002'); // duplicate text -> one open row
        $this->hook('7002', 'B question');
        $admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $r = $this->as($admin)->getJson("/api/ai-train/{$this->a->id}/questions")->assertOk()->assertJsonCount(1, 'open');
        $r->assertJsonPath('open.0.question', 'Do you have organic jaggery?')->assertJsonPath('open.0.contact_wa_id', '919811000001')->assertJsonPath('open.0.contact_name', 'Ravi Kumar');
        $this->assertNotNull($r->json('open.0.created_at'));
        $id = $r->json('open.0.id');
        $qb = AiOpenQuestion::where('phone_number_id', $this->b->id)->first();
        // client A cannot answer B's question, even via own number id
        $this->as($this->ca)->postJson("/api/client/ai-train/{$this->a->id}/questions/{$qb->id}/answer", ['answer' => 'x'])->assertNotFound();
        $this->as($this->ca)->postJson("/api/client/ai-train/{$this->a->id}/questions/$id/answer", ['answer' => ''])->assertStatus(422);
        $this->as($this->ca)->postJson("/api/client/ai-train/{$this->a->id}/questions/$id/answer", ['answer' => 'Yes, ₹120/kg 😊'])->assertOk()->assertJsonPath('rule.phone_number_id', $this->a->id);
        $this->assertSame(0, AiExample::where('phone_number_id', $this->b->id)->count());
        $this->as($this->ca)->getJson("/api/client/ai-train/{$this->a->id}/questions")->assertJsonCount(0, 'open')->assertJsonPath('resolved.0.answer', 'Yes, ₹120/kg 😊');
        $this->assertSame('open', $qb->fresh()->status);
        // the saved rule now reaches A's customer prompt, not B's
        AiReplySetting::query()->update(['allowlist' => json_encode(['919811000003'])]);
        $this->answer = 'Yes, ₹120/kg 😊';
        $this->hook('7001', 'organic jaggery available?', '919811000003');
        $this->assertStringContainsString('Yes, ₹120/kg', $this->llm()->last()['messages'][0]['content']);
        $this->hook('7002', 'organic jaggery available?', '919811000003');
        $this->assertStringNotContainsString('₹120', $this->llm()->last()['messages'][0]['content']);
    }
}
