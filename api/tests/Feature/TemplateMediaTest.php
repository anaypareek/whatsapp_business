<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\Setting;
use App\Models\TemplateRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TemplateMediaTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Client $c;
    protected array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        foreach (['app_id' => '4242', 'access_token' => 'EAAtoken', 'waba_id' => '999'] as $k => $v) Setting::put($k, $v);
        $this->admin = User::create(['name' => 'A', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->c = Client::create(['name' => 'Alpha', 'email' => 'alpha@c.t', 'password' => 'password123']);
        Http::fake([
            'graph.facebook.com/*/4242/uploads*' => Http::response(['id' => 'upload:SESS1']),
            'graph.facebook.com/*/upload:SESS1' => Http::response(['h' => 'HANDLE_XYZ']),
            'graph.facebook.com/*/999/message_templates' => function (HttpRequest $r) { $this->created[] = $r->data(); return Http::response(['id' => 'T9', 'status' => 'PENDING', 'category' => 'MARKETING']); },
        ]);
    }

    protected function admin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }
    protected function client() { $this->app['auth']->forgetGuards(); return $this->withToken($this->c->createToken('c')->plainTextToken); }
    protected function body(string $sample, string $name = 'promo_img'): array
    {
        return ['name' => $name, 'language' => 'en_US', 'category' => 'MARKETING', 'header' => ['format' => 'IMAGE', 'sample' => $sample, 'sample_name' => 'p.png'], 'body' => ['text' => 'Big sale today']];
    }

    public function test_admin_upload_then_create_puts_handle_in_header(): void
    {
        $s = $this->admin()->post('/api/template-samples', ['format' => 'IMAGE', 'file' => UploadedFile::fake()->image('p.png', 50, 50)])->assertCreated()->json('sample');
        $this->assertStringStartsWith('a/', $s);
        Http::assertNothingSent(); // stored locally only
        $this->admin()->postJson('/api/templates', $this->body($s))->assertCreated();
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/4242/uploads?') && str_contains($r->url(), 'file_type=image%2Fpng')
            && str_contains($r->url(), 'access_token=EAAtoken') && str_contains($r->url(), 'file_length='));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/upload:SESS1') && $r->header('Authorization')[0] === 'OAuth EAAtoken'
            && $r->header('file_offset')[0] === '0' && strlen($r->body()) > 0);
        $h = collect($this->created[0]['components'])->firstWhere('type', 'HEADER');
        $this->assertSame(['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['HANDLE_XYZ']]], $h);
    }

    public function test_validation_type_size_missing_sample_and_app_id(): void
    {
        $this->admin()->post('/api/template-samples', ['format' => 'IMAGE', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'Wrong file type for a IMAGE header: use JPG or PNG up to 5 MB.');
        $this->admin()->post('/api/template-samples', ['format' => 'VIDEO', 'file' => UploadedFile::fake()->create('v.mp4', 17 * 1024, 'video/mp4')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->admin()->post('/api/template-samples', ['format' => 'DOCUMENT', 'file' => UploadedFile::fake()->create('d.pdf', 200, 'application/pdf')])->assertCreated();
        $b = $this->body(''); unset($b['header']['sample']);
        $this->admin()->postJson('/api/templates', $b)->assertStatus(422)->assertJsonValidationErrors('header.sample');
        $this->admin()->postJson('/api/templates', $this->body('a/AAAAAAAAAAAAAAAAAAAAAAAA.png'))->assertStatus(422)->assertJsonValidationErrors('header.sample');
        Setting::put('app_id', '');
        $s = $this->admin()->post('/api/template-samples', ['format' => 'IMAGE', 'file' => UploadedFile::fake()->image('p.jpg')])->json('sample');
        $r = $this->admin()->postJson('/api/templates', $this->body($s))->assertStatus(422);
        $this->assertStringContainsString('Meta App ID is not set', $r->json('errors')['header.sample'][0]);
        $this->assertEmpty($this->created);
    }

    public function test_client_request_stores_sample_and_upload_happens_on_approval(): void
    {
        $s = $this->client()->post('/api/client/template-samples', ['format' => 'IMAGE', 'file' => UploadedFile::fake()->image('p.png')])->assertCreated()->json('sample');
        $this->assertStringStartsWith('c'.$this->c->id.'/', $s);
        $id = $this->client()->postJson('/api/client/template-requests', $this->body($s))->assertCreated()->json('id');
        Http::assertNothingSent();
        $this->admin()->postJson("/api/template-requests/{$id}/approve", ['note' => 'ok'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/upload:SESS1'));
        $this->assertSame(['HANDLE_XYZ'], collect($this->created[0]['components'])->firstWhere('type', 'HEADER')['example']['header_handle']);
        $this->assertSame(['HANDLE_XYZ'], collect(TemplateRequest::find($id)->template->components)->firstWhere('type', 'HEADER')['example']['header_handle']);
    }

    public function test_client_cannot_use_another_owners_sample(): void
    {
        $s = $this->admin()->post('/api/template-samples', ['format' => 'IMAGE', 'file' => UploadedFile::fake()->image('p.png')])->json('sample');
        $this->client()->postJson('/api/client/template-requests', $this->body($s))->assertStatus(422);
    }
}
