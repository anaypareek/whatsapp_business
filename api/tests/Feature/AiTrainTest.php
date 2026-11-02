<?php
namespace Tests\Feature;

use App\Models\AiContactState;
use App\Models\AiExample;
use App\Models\AiKnowledgeChunk;
use App\Models\AiProductImage;
use App\Models\AiReplySetting;
use App\Models\Client;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiTrainTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected PhoneNumber $n;
    protected PhoneNumber $other;
    protected int $seq = 0;
    protected string $aiAnswer = 'Hello! 😊';
    protected array $orders = ['orders' => []];
    protected int $orderStatus = 200;
    protected const FROM = '919811000001';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        foreach (['app_secret' => 'shh', 'access_token' => 'EAAtoken', 'waba_id' => '999', 'ai_api_key' => 'sk-test-SECRET1234', 'ai_base_url' => 'https://llm.test/v1',
            'orders_api_base_url' => 'https://orders.test/api', 'orders_api_key' => 'ord-SECRET9876'] as $k => $v) Setting::put($k, $v);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->n = PhoneNumber::create(['phone_number_id' => '7001', 'display_number' => '+91 90909 00894', 'name' => 'Oswal']);
        $this->other = PhoneNumber::create(['phone_number_id' => '7002', 'display_number' => '+91 90000 70002', 'name' => 'Other']);
        Http::fake([
            'llm.test/*' => fn () => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $this->aiAnswer]]]]),
            'orders.test/*' => fn () => Http::response($this->orders, $this->orderStatus),
            'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.O'.bin2hex(random_bytes(5))]]]),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00', 'Asia/Kolkata'));
        AiReplySetting::create(['phone_number_id' => $this->n->id, 'enabled' => true, 'allowlist' => [self::FROM], 'max_per_hour' => 50]);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }

    protected function text(string $t, string $from = self::FROM)
    {
        $this->seq++;
        $p = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '999', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['display_phone_number' => '919090900894', 'phone_number_id' => '7001'],
            'contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => 'wamid.IN'.$this->seq, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $t]]]]]]]]];
        $raw = json_encode($p);
        return $this->call('POST', '/api/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'shh')], $raw);
    }

    protected function reqs(string $host) { return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), $host))->values(); }
    protected function metaSends() { return $this->reqs('graph.facebook.com')->filter(fn ($r) => str_ends_with($r->url(), '/messages'))->values(); }
    protected function lastPrompt(): string { return $this->reqs('llm.test')->last()['messages'][0]['content']; }

    /** Minimal one-page PDF with the given text. */
    protected static function pdf(string $text): string
    {
        $stream = 'BT /F1 12 Tf 72 720 Td ('.addcslashes($text, '()\\').') Tj ET';
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $out = "%PDF-1.4\n"; $off = [];
        foreach ($objs as $i => $o) { $off[] = strlen($out); $out .= ($i + 1)." 0 obj\n$o\nendobj\n"; }
        $x = strlen($out);
        $out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
        return $out."trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
    }

    public function test_admin_only_and_scoped_per_number(): void
    {
        $c = Client::create(['name' => 'Oswal', 'email' => 'o@c.t', 'password' => 'password123']);
        $this->app['auth']->forgetGuards();
        $tok = $c->createToken('c')->plainTextToken;
        $this->withToken($tok)->getJson("/api/ai-train/{$this->n->id}")->assertForbidden();
        $this->withToken($tok)->getJson("/api/client/ai-train/{$this->n->id}")->assertNotFound();
        $e = AiExample::create(['phone_number_id' => $this->other->id, 'question' => 'q', 'answer' => 'a']);
        $this->admin()->deleteJson("/api/ai-train/{$this->n->id}/examples/{$e->id}")->assertNotFound();
        $this->admin()->getJson("/api/ai-train/{$this->n->id}")->assertOk()->assertJsonCount(0, 'examples');
    }

    public function test_playground_uses_saved_key_and_sends_nothing(): void
    {
        $this->aiAnswer = 'Hi there! 😊 How can I help you? 😊';
        $this->admin()->postJson("/api/ai-train/{$this->n->id}/chat", ['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertOk()->assertJsonPath('reply', $this->aiAnswer)->assertJsonPath('handoff', false);
        $this->assertSame('Bearer sk-test-SECRET1234', $this->reqs('llm.test')->first()->header('Authorization')[0]);
        $this->assertCount(0, $this->metaSends());
        $this->assertCount(0, $this->reqs('orders.test'));
        $this->assertSame(0, Message::count());
        Setting::put('ai_api_key', null);
        $this->admin()->postJson("/api/ai-train/{$this->n->id}/chat", ['messages' => [['role' => 'user', 'content' => 'hi']]])->assertStatus(422);
    }

    public function test_corrections_are_injected_top_few_by_keyword_overlap(): void
    {
        $this->admin()->postJson("/api/ai-train/{$this->n->id}/examples", ['question' => 'What is the price of basmati rice?', 'answer' => 'Basmati rice 5kg is ₹650 🍚'])->assertSuccessful();
        foreach (range(1, 6) as $i) AiExample::create(['phone_number_id' => $this->n->id, 'question' => "Do you sell sugar type $i", 'answer' => "sugar $i"]);
        AiExample::create(['phone_number_id' => $this->other->id, 'question' => 'basmati rice price other', 'answer' => 'OTHER NUMBER ANSWER']);
        $this->text('basmati rice price?');
        $p = $this->lastPrompt();
        $this->assertStringContainsString('Basmati rice 5kg is ₹650', $p);
        $this->assertStringNotContainsString('OTHER NUMBER ANSWER', $p);
        $this->assertStringNotContainsString('sugar', $p); // no overlap -> not injected
        $this->text('sugar please');
        $this->assertSame(AiKnowledge::TOP_EXAMPLES, substr_count($this->lastPrompt(), 'Q: Do you sell sugar'));
    }

    public function test_knowledge_files_pdf_txt_csv_chunked_and_retrieved(): void
    {
        $up = fn ($name, $body) => $this->admin()->post("/api/ai-train/{$this->n->id}/files", ['file' => UploadedFile::fake()->createWithContent($name, $body)], ['Accept' => 'application/json']);
        $up('prices.csv', "product,price,unit\nToor dal,145,kg\nMoong dal,130,kg\n")->assertSuccessful()->assertJsonPath('kind', 'csv');
        $up('policy.txt', "Returns accepted within 7 days with bill.\n")->assertSuccessful()->assertJsonPath('kind', 'txt');
        $up('catalog.pdf', self::pdf('Kashmiri saffron 1g costs 450 rupees'))->assertSuccessful()->assertJsonPath('kind', 'pdf');
        $up('evil.exe', 'MZ')->assertStatus(422);
        $this->assertStringContainsString('saffron', AiKnowledgeChunk::where('content', 'like', '%saffron%')->value('content'));
        $this->text('toor dal rate?');
        $p = $this->lastPrompt();
        $this->assertStringContainsString('product: Toor dal, price: 145', $p);
        $this->assertStringNotContainsString('Returns accepted', $p);
        $this->text('saffron price');
        $this->assertStringContainsString('Kashmiri saffron', $this->lastPrompt());
        $long = implode("\n", array_map(fn ($i) => "line $i ".str_repeat('x', 90), range(1, 100)));
        $this->assertLessThanOrEqual(AiKnowledge::CHUNK_CHARS, max(array_map('mb_strlen', AiKnowledge::chunk($long))));
        $fid = $this->admin()->getJson("/api/ai-train/{$this->n->id}")->json('files.0.id');
        $this->admin()->deleteJson("/api/ai-train/{$this->n->id}/files/$fid")->assertOk();
        $this->assertSame(0, AiKnowledgeChunk::where('ai_knowledge_file_id', $fid)->count());
    }

    public function test_product_image_marker_sends_image_with_ai_label(): void
    {
        $id = $this->admin()->post("/api/ai-train/{$this->n->id}/images", ['image' => UploadedFile::fake()->image('rice.jpg'), 'name' => 'Basmati rice 5kg', 'tags' => 'rice, basmati'], ['Accept' => 'application/json'])
            ->assertSuccessful()->json('id');
        $otherImg = AiProductImage::create(['phone_number_id' => $this->other->id, 'name' => 'x', 'path' => 'x.jpg']);
        $this->aiAnswer = "Here's our Basmati rice 🍚 [IMAGE:$id]";
        $this->text('send me a photo of basmati rice');
        $this->assertStringContainsString("[IMAGE:$id] Basmati rice 5kg", $this->lastPrompt());
        $r = $this->metaSends()->last();
        $this->assertSame('image', $r['type']);
        $this->assertStringEndsWith(AiProductImage::find($id)->path, $r['image']['link']);
        $this->assertSame("Here's our Basmati rice 🍚", $r['image']['caption']);
        $m = Message::where('direction', 'out')->latest('id')->first();
        $this->assertSame(['ai', true, 'image'], [$m->source, (bool) $m->is_auto, $m->type]);
        // another number's image id is never sent
        $this->aiAnswer = "Sure [IMAGE:{$otherImg->id}]";
        $this->text('photo of x');
        $this->assertSame('text', $this->metaSends()->last()['type']);
        $this->assertSame('Sure', $this->metaSends()->last()['text']['body']);
    }

    public function test_order_lookup_always_scoped_to_sender_phone(): void
    {
        $this->orders = ['orders' => [['order_id' => 'OS-1001', 'status' => 'Shipped', 'items' => [['name' => 'Toor dal', 'qty' => 2]], 'total' => 290, 'placed_at' => '2026-10-30', 'expected_delivery' => '2026-11-04']]];
        $this->aiAnswer = '[ORDER_LOOKUP:mine]';
        $this->text('where is my order?');
        $req = $this->reqs('orders.test')->last();
        $this->assertSame('https://orders.test/api/orders/status?phone='.self::FROM, $req->url());
        $this->assertSame('ord-SECRET9876', $req->header('X-Api-Key')[0]);
        $body = $this->metaSends()->last()['text']['body'];
        $this->assertStringContainsString('Order #OS-1001: *Shipped*', $body);
        $this->assertStringContainsString('Expected delivery: 2026-11-04', $body);
        $this->assertStringContainsString('ORDERS:', $this->lastPrompt());
        // with an order id: phone + order_id both sent; mismatching order in response is dropped -> handoff
        $this->aiAnswer = '[ORDER_LOOKUP:OS-2002]';
        $this->text('status of OS-2002');
        $this->assertSame('https://orders.test/api/orders/status?phone='.self::FROM.'&order_id=OS-2002', $this->reqs('orders.test')->last()->url());
        $this->assertSame(\App\Services\AiReplyService::HANDOFF_TEXT, $this->metaSends()->last()['text']['body']);
        $this->assertTrue(AiContactState::first()->needs_human);
    }

    public function test_order_lookup_never_leaks_other_phone_and_errors_hand_off(): void
    {
        $this->orders = ['orders' => [['order_id' => 'OS-9', 'phone' => '919999999999', 'status' => 'Delivered']]];
        $this->aiAnswer = '[ORDER_LOOKUP:OS-9]';
        $this->text('order OS-9?');
        $this->assertStringNotContainsString('Delivered', $this->metaSends()->last()['text']['body']);
        $this->assertSame(\App\Services\AiReplyService::HANDOFF_TEXT, $this->metaSends()->last()['text']['body']);
        AiContactState::query()->update(['needs_human' => false]);
        $this->orderStatus = 500;
        $this->text('my order?');
        $this->assertSame(\App\Services\AiReplyService::HANDOFF_TEXT, $this->metaSends()->last()['text']['body']);
        $this->assertTrue(AiContactState::first()->needs_human);
        $this->assertStringNotContainsString('SECRET9876', (string) @file_get_contents(storage_path('logs/laravel.log')));
    }

    public function test_orders_settings_masked(): void
    {
        $this->admin()->getJson('/api/settings')->assertJsonPath('orders_api_key', '••••9876')->assertJsonPath('orders_api_key_set', true)
            ->assertJsonPath('orders_api_base_url', 'https://orders.test/api');
        $this->admin()->putJson('/api/settings', ['orders_api_key' => '••••9876', 'orders_api_base_url' => 'https://o2.test'])->assertOk();
        $this->assertSame('ord-SECRET9876', Setting::get('orders_api_key'));
        Setting::put('orders_api_key', null);
        $this->text('hi');
        $this->assertStringNotContainsString('ORDERS:', $this->lastPrompt());
    }
}
