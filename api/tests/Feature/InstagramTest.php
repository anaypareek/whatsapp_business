<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\ErrorLog;
use App\Models\InstagramAccount;
use App\Models\InstagramMedia;
use App\Models\InstagramPost;
use App\Models\Setting;
use App\Models\User;
use App\Services\InstagramPublisher;
use App\Services\InstagramTokens;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Instagram module: OAuth (faked), container → publish (image / reel / carousel / story), async video polling, quota, retry/cancel, scoping. No real Meta calls: Http::fake. */
class InstagramTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin; protected Client $a; protected Client $b; protected InstagramAccount $acctA; protected InstagramAccount $acctB;
    /** @var array<int,array{0:string,1:string,2:array}> every faked Graph call: [method, path, params] */
    protected array $calls = [];
    protected int $seq = 0;
    protected array $statusSeq = [];          // container id => remaining status codes (the last one sticks)
    protected array $quota = ['usage' => 3, 'total' => 100];
    protected bool $slowVideo = true;
    protected ?\Closure $onCreate = null;     // fn(array $params): ?Response-array to inject an error
    protected ?array $publishError = null;
    protected array $debug = ['is_valid' => true];
    protected bool $rename = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
        config(['services.instagram.frontend_url' => null, 'services.instagram.media_base_url' => null]);
        Setting::put('app_id', 'APPID'); Setting::put('app_secret', 'APPSECRET');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@t.l', 'password' => 'password123', 'is_admin' => true]);
        $this->a = Client::create(['name' => 'Alpha', 'email' => 'alpha@c.t', 'password' => 'password123']);
        $this->b = Client::create(['name' => 'Beta', 'email' => 'beta@c.t', 'password' => 'password123']);
        $this->acctA = $this->account('17841400000000001', 'alpha_ig', $this->a);
        $this->acctB = $this->account('17841400000000002', 'beta_ig', $this->b);
        $this->fakeGraph();
    }

    protected function account(string $ig, string $user, ?Client $c, array $x = []): InstagramAccount
    {
        return InstagramAccount::create($x + ['client_id' => $c?->id, 'page_id' => 'P'.$ig, 'page_name' => "$user page", 'ig_user_id' => $ig, 'username' => $user, 'access_token' => 'PAGETOKEN'.$ig,
            'user_access_token' => 'USERTOKEN'.$ig, 'token_expires_at' => now()->addDays(50), 'status' => 'active']);
    }

    protected function asClient(Client $c) { $this->app['auth']->forgetGuards(); return $this->withToken($c->createToken('t')->plainTextToken); }
    protected function asAdmin() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin->createToken('t')->plainTextToken); }

    // ---- fake Graph API ----
    protected function fakeGraph(): void
    {
        Http::fake(function (HttpRequest $r) {
            $u = parse_url($r->url()); $path = preg_replace('#^/v\d+\.\d+#', '', $u['path']); parse_str($u['query'] ?? '', $q);
            $params = $r->method() === 'GET' ? $q : ($r->data() ?: []);
            $this->calls[] = [$r->method(), $path, $params, $r->header('Authorization')[0] ?? null];
            $err = fn (string $m, int $code, int $http = 400, ?int $sub = null) => Http::response(['error' => ['message' => $m, 'type' => 'OAuthException', 'code' => $code] + ($sub ? ['error_subcode' => $sub] : []) + ['fbtrace_id' => 'T']], $http);
            if ($path === '/oauth/access_token') {
                return ($params['grant_type'] ?? '') === 'fb_exchange_token'
                    ? Http::response(['access_token' => 'LONGUSER'.++$this->seq, 'token_type' => 'bearer', 'expires_in' => 5183944])
                    : Http::response(['access_token' => 'SHORTUSER', 'token_type' => 'bearer', 'expires_in' => 3600]);
            }
            if ($path === '/me/accounts') return Http::response(['data' => [
                ['id' => 'PG1', 'name' => 'Shop', 'access_token' => 'PAGETOKEN_PG1', 'instagram_business_account' => ['id' => '17841499990000001', 'username' => 'shop_ig', 'name' => 'Shop', 'profile_picture_url' => 'https://cdn.example/p1.jpg']],
                ['id' => 'PG2', 'name' => 'No IG page', 'access_token' => 'PAGETOKEN_PG2'],
                ['id' => 'PG3', 'name' => 'Cafe', 'access_token' => 'PAGETOKEN_PG3', 'instagram_business_account' => ['id' => '17841499990000003', 'username' => 'cafe_ig', 'name' => 'Cafe']],
                ['id' => 'P17841400000000001', 'name' => 'Alpha page', 'access_token' => 'PAGETOKEN_RENEWED', 'instagram_business_account' => ['id' => '17841400000000001', 'username' => 'alpha_ig']],
            ]]);
            if ($path === '/debug_token') return Http::response(['data' => $this->debug + ['scopes' => ['instagram_basic', 'instagram_content_publish', 'pages_show_list'], 'expires_at' => 0, 'data_access_expires_at' => time() + 86400 * 80]]);
            if (preg_match('#^/(\d{17})$#', $path, $m)) return Http::response(['id' => $m[1], 'username' => $this->rename ? 'renamed_'.$m[1] : ['17841499990000001' => 'shop_ig', '17841499990000003' => 'cafe_ig', '17841400000000001' => 'alpha_ig', '17841400000000002' => 'beta_ig'][$m[1]] ?? 'u'.$m[1], 'name' => 'N', 'profile_picture_url' => 'https://cdn.example/new.jpg']);
            if (preg_match('#^/(\d{17})/content_publishing_limit$#', $path)) return Http::response(['data' => [['quota_usage' => $this->quota['usage'], 'config' => ['quota_total' => $this->quota['total'], 'quota_duration' => 86400]]]]);
            if (preg_match('#^/(\d{17})/media$#', $path)) {
                if ($this->onCreate && ($e = ($this->onCreate)($params))) return $e;
                $id = 'C'.++$this->seq;
                $this->statusSeq[$id] = isset($params['video_url']) && $this->slowVideo ? ['IN_PROGRESS', 'FINISHED'] : ['FINISHED'];
                return Http::response(['id' => $id]);
            }
            if (preg_match('#^/(\d{17})/media_publish$#', $path)) {
                if ($this->publishError) return Http::response($this->publishError, 400);
                $this->quota['usage']++;
                return Http::response(['id' => '9000'.$params['creation_id']]);
            }
            if (preg_match('#^/(C\d+)$#', $path, $m)) {
                $s = $this->statusSeq[$m[1]] ?? ['FINISHED'];
                $code = count($s) > 1 ? array_shift($this->statusSeq[$m[1]]) : $s[0];
                return Http::response(['id' => $m[1], 'status_code' => $code, 'status' => $code === 'ERROR' ? 'Error: Video format not supported (2207026)' : ucfirst(strtolower($code))]);
            }
            if (preg_match('#^/(9000C\d+)$#', $path, $m)) return Http::response(['id' => $m[1], 'permalink' => 'https://www.instagram.com/p/'.$m[1].'/']);
            return Http::response(['error' => ['message' => "unexpected $path", 'code' => 100]], 404);
        });
    }

    protected function graph(string $method, string $re): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === $method && preg_match($re, $c[1])));
    }

    // ---- helpers ----
    protected function upload($as, string $format, UploadedFile $f): array
    {
        $r = $as->post($as === null ? '' : '/api/client/instagram/media', ['format' => $format, 'file' => $f], ['Accept' => 'application/json']);
        return $r->assertCreated()->json();
    }
    protected function img(int $w = 1080, int $h = 1080, string $name = 'a.jpg') { return UploadedFile::fake()->image($name, $w, $h); }
    protected function vid(string $f = 'v3s.mp4') { return new UploadedFile(__DIR__.'/../Fixtures/'.$f, $f, 'video/mp4', null, true); }

    protected function mediaId(Client $c, string $format, UploadedFile $f): int
    {
        return $this->asClient($c)->post('/api/client/instagram/media', ['format' => $format, 'file' => $f], ['Accept' => 'application/json'])->assertCreated()->json('id');
    }

    protected function makePost(Client $c, InstagramAccount $a, string $format, array $mediaIds, array $x = [])
    {
        return $this->asClient($c)->postJson('/api/client/instagram/posts', $x + ['format' => $format, 'account_ids' => [$a->id], 'media_ids' => $mediaIds, 'caption' => 'Hello #world', 'mode' => 'now']);
    }

    protected function run1(): array { return app(InstagramPublisher::class)->runDue(); }

    // ======================================================================
    // OAuth
    // ======================================================================
    public function test_connect_start_builds_the_facebook_dialog_with_the_five_scopes(): void
    {
        $url = $this->asClient($this->a)->postJson('/api/client/instagram/connect/start', ['return_to' => '/instagram'])->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringContainsString('/dialog/oauth', $url);
        $this->assertSame('APPID', $q['client_id']);
        $this->assertSame('instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement,business_management', $q['scope']);
        $this->assertSame(url('/api/instagram/callback'), $q['redirect_uri']);
        $this->assertSame(40, strlen($q['state']));
    }

    public function test_connect_start_needs_credentials_and_can_use_a_login_configuration(): void
    {
        Setting::whereIn('key', ['app_id', 'app_secret'])->delete();
        $this->asClient($this->a)->postJson('/api/client/instagram/connect/start')->assertStatus(422);
        Setting::put('ig_app_id', 'IGAPP'); Setting::put('ig_app_secret', 's'); Setting::put('ig_config_id', 'CFG1'); Setting::put('ig_redirect_uri', 'https://x.example/cb');
        $url = $this->asClient($this->a)->postJson('/api/client/instagram/connect/start')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('IGAPP', $q['client_id']); $this->assertSame('CFG1', $q['config_id']); $this->assertArrayNotHasKey('scope', $q); $this->assertSame('https://x.example/cb', $q['redirect_uri']);
    }

    protected function connectSession($as, string $prefix): string
    {
        $url = $as->postJson("$prefix/connect/start", ['return_to' => str_contains($prefix, 'client') ? '/instagram' : '/wab-control-7x/instagram'])->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $loc = $this->get('/api/instagram/callback?code=CODE1&state='.$q['state'])->assertRedirect()->headers->get('Location');
        parse_str(parse_url($loc, PHP_URL_QUERY), $r);
        $this->assertNotEmpty($r['connect'] ?? null, $loc);
        return $r['connect'];
    }

    public function test_callback_exchanges_for_a_long_lived_token_lists_only_pages_with_instagram_and_saves_the_picked_accounts(): void
    {
        $sid = $this->connectSession($this->asClient($this->a), '/api/client/instagram');
        $ex = $this->graph('POST', '#/oauth/access_token#');
        $this->assertSame('CODE1', $ex[0][2]['code']); $this->assertSame('fb_exchange_token', $ex[1][2]['grant_type']); $this->assertSame('SHORTUSER', $ex[1][2]['fb_exchange_token']);

        $c = $this->asClient($this->a)->getJson("/api/client/instagram/connect/$sid")->assertOk()->json('candidates');
        $this->assertSame(['shop_ig', 'cafe_ig', 'alpha_ig'], array_column($c, 'username')); // the page without an Instagram account is not offered
        $this->assertTrue($c[2]['already_connected']);

        $res = $this->asClient($this->a)->postJson("/api/client/instagram/connect/$sid", ['ig_user_ids' => ['17841499990000001']])->assertOk()->json();
        $this->assertCount(1, $res['saved']); $this->assertSame('shop_ig', $res['saved'][0]['username']);
        $this->assertArrayNotHasKey('access_token', $res['saved'][0]);

        $acct = InstagramAccount::where('ig_user_id', '17841499990000001')->first();
        $this->assertSame($this->a->id, $acct->client_id);
        $this->assertSame('PG1', $acct->page_id); $this->assertSame('PAGETOKEN_PG1', $acct->access_token); $this->assertStringStartsWith('LONGUSER', $acct->user_access_token);
        $raw = DB::table('instagram_accounts')->where('id', $acct->id)->first();
        $this->assertStringNotContainsString('PAGETOKEN_PG1', $raw->access_token); $this->assertStringNotContainsString('LONGUSER', $raw->user_access_token); // encrypted at rest
        $this->assertEqualsWithDelta(60, now()->diffInDays($acct->token_expires_at, false), 1);
        $this->assertSame('active', $acct->status);
        $this->assertNotNull($acct->last_checked_at);
    }

    public function test_pick_several_accounts_and_admin_assigns_them_to_a_client(): void
    {
        $sid = $this->connectSession($this->asAdmin(), '/api/instagram');
        $res = $this->asAdmin()->postJson("/api/instagram/connect/$sid", ['ig_user_ids' => ['17841499990000001', '17841499990000003'], 'client_id' => $this->b->id])->assertOk()->json('saved');
        $this->assertCount(2, $res);
        $this->assertSame(2, InstagramAccount::where('client_id', $this->b->id)->where('ig_user_id', 'like', '178414999%')->count());
        // reassign by the admin
        $id = InstagramAccount::where('ig_user_id', '17841499990000001')->value('id');
        $this->asAdmin()->putJson("/api/instagram/accounts/$id", ['client_id' => $this->a->id])->assertOk()->assertJsonPath('client_id', $this->a->id);
        $this->asAdmin()->putJson("/api/instagram/accounts/$id", ['client_id' => null])->assertOk()->assertJsonPath('client_id', null);
        $this->asClient($this->a)->putJson("/api/client/instagram/accounts/$id", ['client_id' => $this->a->id])->assertStatus(405)->assertStatus(405);
    }

    public function test_callback_rejects_unknown_state_denied_login_and_foreign_sessions(): void
    {
        $this->assertSame(rtrim(config('app.url'), '/').'/', $this->get('/api/instagram/callback?code=X&state=nope')->assertRedirect()->headers->get('Location'));
        $url = $this->asClient($this->a)->postJson('/api/client/instagram/connect/start', ['return_to' => '/instagram'])->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $loc = $this->get('/api/instagram/callback?error=access_denied&error_description=User+cancelled&state='.$q['state'])->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/instagram?connect_error=', $loc); $this->assertStringContainsString('cancelled', urldecode($loc));
        $this->assertSame([], $this->graph('POST', '#/oauth/access_token#')); // nothing was exchanged

        $sid = $this->connectSession($this->asClient($this->a), '/api/client/instagram');
        $this->asClient($this->b)->getJson("/api/client/instagram/connect/$sid")->assertStatus(410);                                           // someone else's session
        $this->asClient($this->b)->postJson("/api/client/instagram/connect/$sid", ['ig_user_ids' => ['17841499990000001']])->assertStatus(422);
        $this->assertSame(0, InstagramAccount::where('ig_user_id', '17841499990000001')->count());
    }

    public function test_open_redirects_are_not_possible_through_return_to(): void
    {
        $url = $this->asClient($this->a)->postJson('/api/client/instagram/connect/start', ['return_to' => 'https://evil.example/x'])->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $loc = $this->get('/api/instagram/callback?error=access_denied&state='.$q['state'])->headers->get('Location');
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/instagram?', $loc);
    }

    public function test_a_client_cannot_take_an_account_that_belongs_to_another_client(): void
    {
        $this->account('17841499990000001', 'shop_ig', $this->b);
        $sid = $this->connectSession($this->asClient($this->a), '/api/client/instagram');
        $res = $this->asClient($this->a)->postJson("/api/client/instagram/connect/$sid", ['ig_user_ids' => ['17841499990000001', '17841499990000003']])->assertOk()->json();
        $this->assertSame(['cafe_ig'], array_column($res['saved'], 'username'));
        $this->assertSame('shop_ig', $res['skipped'][0]['username']);
        $this->assertSame($this->b->id, InstagramAccount::where('ig_user_id', '17841499990000001')->value('client_id'));
    }

    // ======================================================================
    // Token validity / refresh
    // ======================================================================
    public function test_revoked_token_flags_the_account_for_reconnect_and_it_recovers(): void
    {
        $this->rename = true;
        $this->debug = ['is_valid' => false, 'error' => ['message' => 'Session invalidated']];
        $this->artisan('instagram:check-tokens')->assertSuccessful();
        $a = $this->acctA->fresh();
        $this->assertSame('needs_reconnect', $a->status); $this->assertStringContainsString('Session invalidated', $a->status_reason);
        $this->debug = ['is_valid' => true];
        $this->artisan('instagram:check-tokens')->assertSuccessful();
        $this->assertSame('active', $this->acctA->fresh()->status);
        $this->assertSame('renamed_17841400000000001', $this->acctA->fresh()->username); // profile refreshed by the check
    }

    public function test_graph_oauth_error_190_marks_reconnect_but_transient_errors_do_not(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => ['instagram_basic', 'instagram_content_publish']]]), '*/17841400000000001*' => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400),
            '*/17841400000000002*' => Http::response(['error' => ['message' => 'Service unavailable', 'code' => 2, 'is_transient' => true]], 503)]);
        app(InstagramTokens::class)->checkAll();
        $this->assertSame('needs_reconnect', $this->acctA->fresh()->status);
        $this->assertSame('active', $this->acctB->fresh()->status);
    }

    public function test_missing_publish_permission_is_flagged(): void
    {
        $this->debug = ['is_valid' => true, 'scopes' => ['instagram_basic']];
        Http::fake(['*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => ['instagram_basic']]])]);
        app(InstagramTokens::class)->check($this->acctA);
        $this->assertSame('needs_reconnect', $this->acctA->fresh()->status);
        $this->assertStringContainsString('instagram_content_publish', $this->acctA->fresh()->status_reason);
    }

    public function test_long_lived_user_token_close_to_expiry_is_renewed_and_the_page_token_re_read(): void
    {
        $this->acctA->update(['token_expires_at' => now()->addDays(5)]);
        app(InstagramTokens::class)->check($this->acctA->fresh());
        $a = $this->acctA->fresh();
        $this->assertStringStartsWith('LONGUSER', $a->user_access_token);
        $this->assertSame('PAGETOKEN_RENEWED', $a->access_token);
        $this->assertGreaterThan(55, now()->diffInDays($a->token_expires_at, false));
        // not renewed while far from expiry
        $before = count($this->graph('POST', '#/oauth/access_token#'));
        app(InstagramTokens::class)->check($a->fresh());
        $this->assertCount($before, $this->graph('POST', '#/oauth/access_token#'));
    }

    // ======================================================================
    // Composer / media validation
    // ======================================================================
    public function test_upload_validates_type_size_ratio_and_duration(): void
    {
        $as = fn () => $this->asClient($this->a);
        $post = fn ($fmt, $f) => $as()->post('/api/client/instagram/media', ['format' => $fmt, 'file' => $f], ['Accept' => 'application/json']);
        $post('image', $this->img(1080, 1350))->assertCreated()->assertJsonPath('kind', 'image');
        $post('image', $this->img(1080, 1920))->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'aspect') || str_contains($m, 'Instagram accepts'));
        $post('image', $this->img(2000, 600))->assertStatus(422);                                          // 3.3:1 wider than 1.91
        $post('story', $this->img(1080, 1920))->assertCreated();                                           // stories take any ratio
        $post('image', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))->assertStatus(422);
        $post('image', $this->vid())->assertStatus(422);                                                   // video in a single-image post
        $post('reel', $this->img())->assertStatus(422);                                                    // image in a reel
        $post('reel', $this->vid('v2s.mp4'))->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'minimum is 3'));
        $r = $post('reel', $this->vid('v3s.mp4'))->assertCreated()->assertJsonPath('kind', 'video');
        $this->assertEqualsWithDelta(3.0, $r->json('duration'), 0.1); $this->assertSame(180, $r->json('width')); $this->assertSame(320, $r->json('height'));
        // 8 MB image limit
        $big = UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg');
        $post('image', $big)->assertStatus(422);
    }

    public function test_png_is_converted_to_jpeg_and_media_is_stored_publicly(): void
    {
        $r = $this->asClient($this->a)->post('/api/client/instagram/media', ['format' => 'image', 'file' => UploadedFile::fake()->image('x.png', 800, 800)], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('image', $r->json('kind')); $this->assertNotEmpty($r->json('warnings'));
        $m = InstagramMedia::find($r->json('id'));
        $this->assertSame('image/jpeg', $m->mime); $this->assertStringEndsWith('.jpg', $m->path);
        Storage::disk('public')->assertExists($m->path);
        $this->assertSame(config('app.url').'/storage/'.$m->path, $r->json('url'));
        $this->assertArrayNotHasKey('path', $r->json());
    }

    public function test_post_validation_caption_hashtags_and_carousel_size(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        $this->makePost($this->a, $this->acctA, 'image', [$m], ['caption' => str_repeat('a', 2201)])->assertStatus(422)->assertJsonPath('errors.post.0', fn ($x) => str_contains($x, '2200'));
        $this->makePost($this->a, $this->acctA, 'image', [$m], ['caption' => implode(' ', array_map(fn ($i) => "#tag$i", range(1, 31)))])->assertStatus(422)->assertJsonPath('errors.post.0', fn ($x) => str_contains($x, 'hashtags'));
        $this->makePost($this->a, $this->acctA, 'image', [$m], ['caption' => implode(' ', array_map(fn ($i) => "#tag$i", range(1, 30)))])->assertCreated();
        $this->makePost($this->a, $this->acctA, 'carousel', [$m])->assertStatus(422)->assertJsonPath('errors.post.0', fn ($x) => str_contains($x, 'at least 2'));
        $ids = array_map(fn () => $this->mediaId($this->a, 'carousel', $this->img()), range(1, 11));
        $this->makePost($this->a, $this->acctA, 'carousel', $ids)->assertStatus(422);
        $this->makePost($this->a, $this->acctA, 'carousel', array_slice($ids, 0, 10))->assertCreated();
        $this->assertSame(0, count($this->graph('POST', '#/media$#'))); // creating a post never calls Instagram
    }

    // ======================================================================
    // Publisher: container → publish
    // ======================================================================
    public function test_single_image_creates_a_container_then_publishes_and_stores_the_permalink(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img(1080, 1350));
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m], ['caption' => 'Fresh batch #soap'])->assertCreated()->json('posts.0.id');
        $this->assertSame('scheduled', InstagramPost::find($id)->status);
        $this->assertCount(0, $this->graph('POST', '#/media$#')); // nothing happens until the cron run

        $s = $this->run1();
        $this->assertSame(1, $s['posted']);
        $create = $this->graph('POST', '#/17841400000000001/media$#');
        $this->assertCount(1, $create);
        $this->assertStringStartsWith(config('app.url').'/storage/instagram/', $create[0][2]['image_url']);
        $this->assertSame('Fresh batch #soap', $create[0][2]['caption']);
        $this->assertSame('Bearer PAGETOKEN17841400000000001', $create[0][3]);
        $pub = $this->graph('POST', '#/media_publish$#');
        $this->assertCount(1, $pub); $this->assertSame('C1', $pub[0][2]['creation_id']);
        // order: create → status → publish
        $order = array_map(fn ($c) => $c[1], array_values(array_filter($this->calls, fn ($c) => preg_match('#/(media|media_publish|C1)$#', $c[1]))));
        $this->assertSame(['/17841400000000001/media', '/C1', '/17841400000000001/media_publish'], array_slice($order, -3));
        $p = InstagramPost::find($id);
        $this->assertSame('posted', $p->status); $this->assertSame('9000C1', $p->ig_media_id); $this->assertSame('https://www.instagram.com/p/9000C1/', $p->permalink); $this->assertNotNull($p->posted_at);
        $this->assertNull($p->error);
        // a second run does nothing (no double publish)
        $this->assertSame(0, $this->run1()['claimed']); $this->assertCount(1, $this->graph('POST', '#/media_publish$#'));
    }

    public function test_reel_video_is_polled_across_cron_runs_without_blocking_then_published(): void
    {
        $m = $this->mediaId($this->a, 'reel', $this->vid());
        $id = $this->makePost($this->a, $this->acctA, 'reel', [$m], ['caption' => 'Reel time', 'share_to_feed' => false])->assertCreated()->json('posts.0.id');

        $s1 = $this->run1();                                            // run 1: container created, status IN_PROGRESS → return immediately
        $this->assertSame(['claimed' => 1, 'posted' => 0, 'failed' => 0, 'waiting' => 1, 'deferred' => 0], $s1);
        $create = $this->graph('POST', '#/media$#')[0][2];
        $this->assertSame('REELS', $create['media_type']); $this->assertStringContainsString('/storage/instagram/', $create['video_url']); $this->assertSame('false', $create['share_to_feed']);
        $p = InstagramPost::find($id);
        $this->assertSame('publishing', $p->status); $this->assertSame('processing', $p->stage); $this->assertSame('C1', $p->container_id); $this->assertSame('IN_PROGRESS', $p->container_status);
        $this->assertCount(0, $this->graph('POST', '#/media_publish$#'));

        $this->assertSame(0, $this->run1()['claimed']);                 // the same minute: not due again (next_attempt_at)
        $this->travel(1)->minutes();
        $s2 = $this->run1();                                            // run 2: status FINISHED → publish, container NOT created again
        $this->assertSame(1, $s2['posted']);
        $this->assertCount(1, $this->graph('POST', '#/media$#'));
        $this->assertCount(1, $this->graph('GET', '#^/C1$#') ? $this->graph('POST', '#/media_publish$#') : []);
        $this->assertSame('posted', InstagramPost::find($id)->status);
        $this->assertCount(2, $this->graph('GET', '#^/C1$#')); // polled once per run
    }

    public function test_carousel_with_mixed_media_creates_children_then_the_parent(): void
    {
        $ids = [$this->mediaId($this->a, 'carousel', $this->img()), $this->mediaId($this->a, 'carousel', $this->vid()), $this->mediaId($this->a, 'carousel', $this->img(1080, 1350))];
        $pid = $this->makePost($this->a, $this->acctA, 'carousel', $ids, ['caption' => 'Swipe'])->assertCreated()->json('posts.0.id');

        $this->run1();                                                  // children created; the video child is still IN_PROGRESS → parent not created yet
        $creates = $this->graph('POST', '#/media$#');
        $this->assertCount(3, $creates);
        foreach ($creates as $c) $this->assertSame('true', $c[2]['is_carousel_item']);
        $this->assertArrayHasKey('image_url', $creates[0][2]); $this->assertSame('VIDEO', $creates[1][2]['media_type']); $this->assertArrayHasKey('video_url', $creates[1][2]);
        $this->assertArrayNotHasKey('caption', $creates[0][2]);         // the caption goes on the parent only
        $this->assertSame('publishing', InstagramPost::find($pid)->status);
        $this->assertCount(0, array_filter($this->calls, fn ($c) => ($c[2]['media_type'] ?? null) === 'CAROUSEL'));

        $this->travel(1)->minutes();
        $this->assertSame(1, $this->run1()['posted']);
        $creates = $this->graph('POST', '#/media$#');
        $this->assertCount(4, $creates);                                // + the parent
        $parent = $creates[3][2];
        $this->assertSame('CAROUSEL', $parent['media_type']); $this->assertSame('C1,C2,C3', $parent['children']); $this->assertSame('Swipe', $parent['caption']);
        $this->assertSame('C4', $this->graph('POST', '#/media_publish$#')[0][2]['creation_id']);
        $this->assertSame('posted', InstagramPost::find($pid)->status);
    }

    public function test_story_image_and_video_use_stories_media_type_and_no_caption(): void
    {
        $i = $this->mediaId($this->a, 'story', $this->img(1080, 1920)); $v = $this->mediaId($this->a, 'story', $this->vid());
        $this->makePost($this->a, $this->acctA, 'story', [$i], ['caption' => 'ignored'])->assertCreated()->assertJsonPath('posts.0.caption', null);
        $this->makePost($this->a, $this->acctA, 'story', [$v])->assertCreated();
        $this->run1(); $this->travel(1)->minutes(); $this->run1();
        $c = $this->graph('POST', '#/media$#');
        $this->assertSame('STORIES', $c[0][2]['media_type']); $this->assertArrayHasKey('image_url', $c[0][2]); $this->assertArrayNotHasKey('caption', $c[0][2]);
        $this->assertSame('STORIES', $c[1][2]['media_type']); $this->assertArrayHasKey('video_url', $c[1][2]);
        $this->assertCount(2, $this->graph('POST', '#/media_publish$#'));
        $this->assertSame(2, InstagramPost::where('status', 'posted')->count());
    }

    public function test_one_post_for_several_accounts_is_published_to_each(): void
    {
        $acc2 = $this->account('17841400000000009', 'alpha_two', $this->a);
        $m = $this->mediaId($this->a, 'image', $this->img());
        $r = $this->asClient($this->a)->postJson('/api/client/instagram/posts', ['format' => 'image', 'account_ids' => [$this->acctA->id, $acc2->id], 'media_ids' => [$m], 'mode' => 'now'])->assertCreated();
        $this->assertCount(2, $r->json('posts')); $this->assertSame($r->json('posts.0.batch_id'), $r->json('posts.1.batch_id'));
        $this->assertSame(2, $this->run1()['posted']);
        $this->assertSame(['17841400000000001', '17841400000000009'], array_map(fn ($c) => explode('/', $c[1])[1], $this->graph('POST', '#/media_publish$#')));
        $this->asClient($this->a)->postJson('/api/client/instagram/posts', ['format' => 'image', 'account_ids' => [$this->acctB->id], 'media_ids' => [$m], 'mode' => 'now'])->assertStatus(422); // not their account
    }

    // ======================================================================
    // Scheduling (IST in, UTC stored)
    // ======================================================================
    public function test_schedule_in_ist_is_stored_in_utc_and_not_published_early(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        $when = now('Asia/Kolkata')->addDays(2)->setTime(18, 30);
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m], ['mode' => 'schedule', 'scheduled_at' => $when->format('Y-m-d\TH:i')])->assertCreated()->json('posts.0.id');
        $raw = DB::table('instagram_posts')->where('id', $id)->value('scheduled_at');
        $this->assertSame($when->copy()->utc()->format('Y-m-d H:i:s'), $raw);                         // 18:30 IST = 13:00 UTC
        $this->assertStringEndsWith('13:00:00', $raw);
        $this->assertStringEndsWith('Z', json_encode(InstagramPost::find($id)->scheduled_at) === null ? '' : trim(json_encode(InstagramPost::find($id)->scheduled_at), '"'));
        $this->assertSame(0, $this->run1()['claimed']);
        $this->travelTo($when->copy()->subMinute());
        $this->assertSame(0, $this->run1()['claimed']);
        $this->travelTo($when->copy()->addMinute());
        $this->assertSame(1, $this->run1()['posted']);
        $this->asClient($this->a)->postJson('/api/client/instagram/posts', ['format' => 'image', 'account_ids' => [$this->acctA->id], 'media_ids' => [$m], 'mode' => 'schedule', 'scheduled_at' => now('Asia/Kolkata')->subHour()->format('Y-m-d\TH:i')])->assertStatus(422);
        $this->asClient($this->a)->postJson('/api/client/instagram/posts', ['format' => 'image', 'account_ids' => [$this->acctA->id], 'media_ids' => [$m], 'mode' => 'schedule'])->assertStatus(422);
    }

    public function test_an_explicit_utc_offset_is_respected(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m], ['mode' => 'schedule', 'scheduled_at' => now('UTC')->addDay()->setTime(7, 15)->format('Y-m-d\TH:i:s\Z')])->assertCreated()->json('posts.0.id');
        $this->assertStringEndsWith('07:15:00', DB::table('instagram_posts')->where('id', $id)->value('scheduled_at'));
    }

    // ======================================================================
    // Publishing limit
    // ======================================================================
    public function test_publisher_defers_when_the_daily_limit_is_exhausted_and_resumes_after(): void
    {
        $this->quota = ['usage' => 100, 'total' => 100];
        $m = $this->mediaId($this->a, 'image', $this->img());
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m])->assertCreated()->json('posts.0.id');
        $s = $this->run1();
        $this->assertSame(1, $s['deferred']);
        $this->assertCount(0, $this->graph('POST', '#/media$#'));       // nothing was sent to Instagram
        $this->assertGreaterThan(0, count($this->graph('GET', '#/content_publishing_limit$#')));
        $p = InstagramPost::find($id);
        $this->assertSame('scheduled', $p->status); $this->assertStringContainsString('limit', $p->deferred_reason); $this->assertStringContainsString('100/100', $p->deferred_reason);
        $this->assertNotNull($p->next_attempt_at);
        $this->assertSame(0, $this->run1()['claimed']);                 // not retried every minute
        $this->quota = ['usage' => 12, 'total' => 100];
        $this->travel(16)->minutes();
        $this->assertSame(1, $this->run1()['posted']);
        $this->assertNull(InstagramPost::find($id)->deferred_reason);
    }

    public function test_limit_is_rechecked_right_before_publishing(): void
    {
        $m = $this->mediaId($this->a, 'reel', $this->vid());
        $id = $this->makePost($this->a, $this->acctA, 'reel', [$m])->assertCreated()->json('posts.0.id');
        $this->run1();                                                   // container created (quota had room)
        $this->quota = ['usage' => 100, 'total' => 100];                 // others used it up while the video processed
        $this->travel(1)->minutes();
        $this->assertSame(1, $this->run1()['deferred']);
        $this->assertCount(0, $this->graph('POST', '#/media_publish$#'));
        $p = InstagramPost::find($id); $this->assertSame('C1', $p->container_id); $this->assertSame('ready', $p->stage);   // the finished container is kept
        $this->quota['usage'] = 1; $this->travel(16)->minutes();
        $this->assertSame(1, $this->run1()['posted']);
        $this->assertCount(1, $this->graph('POST', '#/media$#'));        // no second container
    }

    public function test_admin_usage_endpoint_reads_content_publishing_limit(): void
    {
        $this->quota = ['usage' => 42, 'total' => 100];
        $r = $this->asAdmin()->getJson('/api/instagram/usage?refresh=1')->assertOk();
        $this->assertSame(['alpha_ig', 'beta_ig'], array_column(array_column($r->json('accounts'), 'account'), 'username'));
        $this->assertSame(42, $r->json('accounts.0.usage')); $this->assertSame(100, $r->json('accounts.0.total'));
        $this->assertSame(2, $r->json('totals.accounts'));
        $this->assertSame('/17841400000000001/content_publishing_limit', $this->graph('GET', '#content_publishing_limit#')[0][1]);
        $this->assertSame('quota_usage,config', $this->graph('GET', '#content_publishing_limit#')[0][2]['fields']);
        $this->asClient($this->a)->getJson('/api/client/instagram/usage')->assertOk()->assertJsonCount(1, 'accounts');
    }

    public function test_usage_without_a_cached_or_refreshed_quota_falls_back_to_the_default_limit(): void
    {
        $r = $this->asAdmin()->getJson('/api/instagram/usage')->assertOk();   // never checked: no cached quota, no refresh
        $this->assertNull($r->json('accounts.0.usage'));
        $this->assertSame(100, $r->json('accounts.0.total'));
        $this->assertSame(100, $r->json('daily_limit'));
    }

    // ======================================================================
    // Failures, retry, cancel, edit
    // ======================================================================
    public function test_container_error_fails_the_post_with_metas_reason_and_logs_it(): void
    {
        $m = $this->mediaId($this->a, 'reel', $this->vid());
        $id = $this->makePost($this->a, $this->acctA, 'reel', [$m])->assertCreated()->json('posts.0.id');
        $this->run1();
        $this->statusSeq['C1'] = ['ERROR']; $this->travel(1)->minutes();
        $this->assertSame(1, $this->run1()['failed']);
        $p = InstagramPost::find($id);
        $this->assertSame('failed', $p->status); $this->assertStringContainsString('Video format not supported', $p->error); $this->assertSame('IG_CONTAINER_ERROR', $p->error_code);
        $this->assertNotNull($p->error_log_id);
        $log = ErrorLog::find($p->error_log_id);
        $this->assertNotNull($log); $this->assertStringContainsString("Instagram post #$id", $log->message);
        $this->assertCount(0, $this->graph('POST', '#/media_publish$#'));
    }

    public function test_graph_error_when_creating_a_container_fails_the_post_and_is_logged_as_meta(): void
    {
        $this->onCreate = fn () => Http::response(['error' => ['message' => 'Only photo or video can be accepted as media type.', 'code' => 9004, 'error_subcode' => 2207052, 'error_user_title' => 'Media download has failed', 'error_user_msg' => 'The media could not be fetched from the URI you provided.']], 400);
        $m = $this->mediaId($this->a, 'image', $this->img());
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m])->assertCreated()->json('posts.0.id');
        $this->assertSame(1, $this->run1()['failed']);
        $p = InstagramPost::find($id);
        $this->assertStringContainsString('could not be fetched', $p->error); $this->assertStringContainsString('public https URL', $p->error);
        $this->assertSame('META_9004', $p->error_code);
        $this->assertSame('meta', ErrorLog::find($p->error_log_id)->source);
        $this->assertSame('failed', $this->asClient($this->a)->getJson("/api/client/instagram/posts/$id")->assertOk()->json('status'));
    }

    public function test_transient_graph_errors_are_retried_later_then_give_up(): void
    {
        $this->publishError = ['error' => ['message' => 'Media ID is not available', 'code' => 9007, 'error_subcode' => 2207027, 'is_transient' => false]];
        $m = $this->mediaId($this->a, 'image', $this->img());
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m])->assertCreated()->json('posts.0.id');
        $this->run1();
        $p = InstagramPost::find($id);
        $this->assertSame('publishing', $p->status); $this->assertSame(1, $p->attempts); $this->assertStringContainsString('retrying', $p->deferred_reason);
        $this->assertSame(0, $this->run1()['claimed']);
        $this->publishError = null; $this->travel(3)->minutes();
        $this->assertSame(1, $this->run1()['posted']);
        $this->assertCount(1, $this->graph('POST', '#/media$#'));       // the container was reused
    }

    public function test_reconnect_needed_fails_the_post_without_calling_instagram(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m])->assertCreated()->json('posts.0.id');
        $this->acctA->markReconnect('Token expired');
        $this->assertSame(1, $this->run1()['failed']);
        $this->assertSame('IG_RECONNECT', InstagramPost::find($id)->error_code);
        $this->assertCount(0, $this->graph('POST', '#/media$#'));
        $this->asClient($this->a)->postJson("/api/client/instagram/posts/$id/retry")->assertStatus(422); // reconnect first
        $this->makePost($this->a, $this->acctA, 'image', [$m])->assertStatus(422);
    }

    public function test_retry_a_failed_post_publishes_it(): void
    {
        $this->onCreate = fn () => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400);
        $m = $this->mediaId($this->a, 'image', $this->img());
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m])->assertCreated()->json('posts.0.id');
        $this->run1(); $this->assertSame('failed', InstagramPost::find($id)->status);
        $this->onCreate = null;
        $r = $this->asClient($this->a)->postJson("/api/client/instagram/posts/$id/retry")->assertOk();
        $this->assertSame('scheduled', $r->json('status')); $this->assertNull($r->json('error')); $this->assertNull($r->json('error_code'));
        $this->assertSame(1, $this->run1()['posted']);
        $this->asClient($this->a)->postJson("/api/client/instagram/posts/$id/retry")->assertStatus(422);   // only failed posts
    }

    public function test_cancel_and_edit_before_the_publish_time(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        $m2 = $this->mediaId($this->a, 'image', $this->img(1080, 1350));
        $t = now('Asia/Kolkata')->addDay()->setTime(10, 0);
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m], ['mode' => 'schedule', 'scheduled_at' => $t->format('Y-m-d\TH:i')])->assertCreated()->json('posts.0.id');
        // edit: caption, media, time
        $t2 = $t->copy()->addHours(3);
        $r = $this->asClient($this->a)->putJson("/api/client/instagram/posts/$id", ['format' => 'image', 'media_ids' => [$m2], 'caption' => 'Edited', 'mode' => 'schedule', 'scheduled_at' => $t2->format('Y-m-d\TH:i')])->assertOk();
        $this->assertSame('Edited', $r->json('caption')); $this->assertSame($m2, $r->json('items.0.media.id'));
        $this->assertSame($t2->copy()->utc()->format('Y-m-d H:i:s'), DB::table('instagram_posts')->where('id', $id)->value('scheduled_at'));
        // cancel → draft, never published
        $this->asClient($this->a)->postJson("/api/client/instagram/posts/$id/cancel")->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('scheduled_at', null);
        $this->travel(3)->days(); $this->assertSame(0, $this->run1()['claimed']);
        $this->assertCount(0, $this->graph('POST', '#/media$#'));
        // a draft can be published now
        $this->asClient($this->a)->postJson("/api/client/instagram/posts/$id/publish-now")->assertOk()->assertJsonPath('status', 'scheduled');
        $this->assertSame(1, $this->run1()['posted']);
        // …but a posted one cannot be edited, cancelled or retried
        $this->asClient($this->a)->putJson("/api/client/instagram/posts/$id", ['format' => 'image', 'media_ids' => [$m], 'mode' => 'draft'])->assertStatus(422);
        $this->asClient($this->a)->postJson("/api/client/instagram/posts/$id/cancel")->assertStatus(422);
    }

    public function test_cancel_during_video_processing_discards_the_container_and_rejects_a_locked_post(): void
    {
        $m = $this->mediaId($this->a, 'reel', $this->vid());
        $id = $this->makePost($this->a, $this->acctA, 'reel', [$m])->assertCreated()->json('posts.0.id');
        $this->run1();
        $this->assertSame('C1', InstagramPost::find($id)->container_id);
        InstagramPost::where('id', $id)->update(['locked_until' => \App\Casts\UtcDateTime::now(120)]);
        $this->asClient($this->a)->postJson("/api/client/instagram/posts/$id/cancel")->assertStatus(422);       // a cron run is working on it right now
        InstagramPost::where('id', $id)->update(['locked_until' => null]);
        $this->asClient($this->a)->postJson("/api/client/instagram/posts/$id/cancel")->assertOk()->assertJsonPath('status', 'draft');
        $p = InstagramPost::find($id); $this->assertNull($p->container_id); $this->assertSame('new', $p->stage);
        $this->asClient($this->a)->deleteJson("/api/client/instagram/posts/$id")->assertNoContent();
        $this->assertNull(InstagramPost::find($id));
    }

    // ======================================================================
    // DB-locked batches + scheduler
    // ======================================================================
    public function test_a_post_reserved_by_another_run_is_skipped_and_stale_locks_expire(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        $id = $this->makePost($this->a, $this->acctA, 'image', [$m])->assertCreated()->json('posts.0.id');
        InstagramPost::where('id', $id)->update(['locked_until' => \App\Casts\UtcDateTime::now(120)]);
        $this->assertSame(0, $this->run1()['claimed']);
        $this->travel(5)->minutes();                                     // the crashed run's lock expired
        $this->assertSame(1, $this->run1()['posted']);
        $this->assertNull(InstagramPost::find($id)->locked_until);
    }

    public function test_batch_size_is_limited_and_oldest_first(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        for ($i = 0; $i < 3; $i++) { $this->makePost($this->a, $this->acctA, 'image', [$m])->assertCreated(); $this->travel(1)->seconds(); }
        $this->assertSame(2, app(InstagramPublisher::class)->runDue(2)['claimed']);
        $this->assertSame(['posted', 'posted', 'scheduled'], InstagramPost::orderBy('id')->pluck('status')->all());
    }

    public function test_publisher_is_scheduled_every_minute_without_overlapping(): void
    {
        $ev = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'instagram:publish'));
        $this->assertNotNull($ev);
        $this->assertSame('* * * * *', $ev->expression);
        $this->assertTrue($ev->withoutOverlapping);
        $this->assertNotNull(collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'instagram:check-tokens')));
    }

    public function test_publish_command_runs_the_publisher(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        $this->makePost($this->a, $this->acctA, 'image', [$m])->assertCreated();
        $this->artisan('instagram:publish')->assertSuccessful();
        $this->assertSame(1, InstagramPost::where('status', 'posted')->count());
    }

    // ======================================================================
    // Scoping / admin
    // ======================================================================
    public function test_clients_only_see_and_touch_their_own_accounts_posts_and_media(): void
    {
        $ma = $this->mediaId($this->a, 'image', $this->img()); $mb = $this->mediaId($this->b, 'image', $this->img());
        $pa = $this->makePost($this->a, $this->acctA, 'image', [$ma], ['mode' => 'draft'])->assertCreated()->json('posts.0.id');
        $pb = $this->makePost($this->b, $this->acctB, 'image', [$mb], ['mode' => 'draft'])->assertCreated()->json('posts.0.id');

        $this->assertSame(['alpha_ig'], array_column($this->asClient($this->a)->getJson('/api/client/instagram/accounts')->json(), 'username'));
        $this->assertSame([$pa], array_column($this->asClient($this->a)->getJson('/api/client/instagram/posts')->json('data'), 'id'));
        $this->asClient($this->a)->getJson("/api/client/instagram/posts/$pb")->assertNotFound();
        $this->asClient($this->a)->putJson("/api/client/instagram/posts/$pb", ['format' => 'image', 'media_ids' => [$ma], 'mode' => 'draft'])->assertNotFound();
        foreach (['cancel', 'retry', 'publish-now'] as $act) $this->asClient($this->a)->postJson("/api/client/instagram/posts/$pb/$act")->assertNotFound();
        $this->asClient($this->a)->deleteJson("/api/client/instagram/posts/$pb")->assertNotFound();
        $this->asClient($this->a)->deleteJson("/api/client/instagram/accounts/{$this->acctB->id}")->assertNotFound();
        $this->asClient($this->a)->postJson("/api/client/instagram/accounts/{$this->acctB->id}/check")->assertNotFound();
        $this->asClient($this->a)->deleteJson("/api/client/instagram/media/$mb")->assertNotFound();
        $this->makePost($this->a, $this->acctA, 'image', [$mb])->assertStatus(422);                       // someone else's media file
        $this->makePost($this->a, $this->acctB, 'image', [$ma])->assertStatus(422);                       // someone else's account
        $this->assertNotNull(InstagramPost::find($pb));

        // admin routes are closed to clients and to non-admin staff; clients' routes are closed to staff
        $this->asClient($this->a)->getJson('/api/instagram/accounts')->assertForbidden();
        $this->asClient($this->a)->getJson('/api/instagram/settings')->assertForbidden();
        $staff = User::create(['name' => 'Op', 'email' => 'op@t.l', 'password' => 'password123']);
        $this->app['auth']->forgetGuards();
        $this->withToken($staff->createToken('t')->plainTextToken)->getJson('/api/instagram/accounts')->assertForbidden();
        $this->asAdmin()->getJson('/api/client/instagram/accounts')->assertForbidden();
        $this->app['auth']->forgetGuards(); $this->flushHeaders();
        $this->getJson('/api/client/instagram/accounts')->assertUnauthorized();
    }

    public function test_admin_sees_every_account_and_post_with_client_names_and_filters(): void
    {
        $ma = $this->mediaId($this->a, 'image', $this->img()); $mb = $this->mediaId($this->b, 'image', $this->img());
        $this->makePost($this->a, $this->acctA, 'image', [$ma], ['mode' => 'draft'])->assertCreated();
        $this->makePost($this->b, $this->acctB, 'image', [$mb])->assertCreated();
        $this->account('17841400000000007', 'orphan_ig', null);
        $accts = $this->asAdmin()->getJson('/api/instagram/accounts')->assertOk()->json();
        $this->assertCount(3, $accts);
        $this->assertSame('Alpha', collect($accts)->firstWhere('username', 'alpha_ig')['client']['name']);
        $this->assertCount(1, $this->asAdmin()->getJson('/api/instagram/accounts?client_id=none')->json());
        $posts = $this->asAdmin()->getJson('/api/instagram/posts')->assertOk()->json('data');
        $this->assertCount(2, $posts);
        $this->assertSame(['Alpha', 'Beta'], collect($posts)->pluck('account.client_name')->sort()->values()->all());
        $this->assertCount(1, $this->asAdmin()->getJson('/api/instagram/posts?status=draft')->json('data'));
        $this->assertCount(1, $this->asAdmin()->getJson('/api/instagram/posts?client_id='.$this->b->id)->json('data'));
        $this->assertCount(1, $this->asAdmin()->getJson('/api/instagram/posts?account_id='.$this->acctA->id)->json('data'));
        $this->assertArrayNotHasKey('access_token', $accts[0]); $this->assertArrayNotHasKey('user_access_token', $accts[0]);
        // the encrypted tokens never leave the API
        $this->assertStringNotContainsString('PAGETOKEN', $this->asAdmin()->get('/api/instagram/accounts')->getContent());
    }

    public function test_calendar_range_filter_uses_the_scheduled_instant(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        $base = now('Asia/Kolkata')->addDays(3)->setTime(23, 30);                                         // 23:30 IST = 18:00 UTC same day
        $this->makePost($this->a, $this->acctA, 'image', [$m], ['mode' => 'schedule', 'scheduled_at' => $base->format('Y-m-d\TH:i')])->assertCreated();
        $day = $base->format('Y-m-d');
        $q = fn ($from, $to) => count($this->asClient($this->a)->getJson('/api/client/instagram/posts?from='.urlencode($from).'&to='.urlencode($to))->json('data'));
        $this->assertSame(1, $q("{$day}T00:00", "{$day}T23:59:59"));                                      // IST day contains it
        $this->assertSame(0, $q($base->copy()->addDay()->format('Y-m-d').'T00:00', $base->copy()->addDays(2)->format('Y-m-d').'T00:00'));
    }

    public function test_disconnecting_an_account_needs_confirmation_when_posts_are_scheduled(): void
    {
        $m = $this->mediaId($this->a, 'image', $this->img());
        $this->makePost($this->a, $this->acctA, 'image', [$m], ['mode' => 'schedule', 'scheduled_at' => now('Asia/Kolkata')->addDay()->format('Y-m-d\TH:i')])->assertCreated();
        $this->asClient($this->a)->deleteJson("/api/client/instagram/accounts/{$this->acctA->id}")->assertStatus(409)->assertJsonPath('pending', 1);
        $this->asClient($this->a)->deleteJson("/api/client/instagram/accounts/{$this->acctA->id}?force=1")->assertNoContent();
        $this->assertNull(InstagramAccount::find($this->acctA->id)); $this->assertSame(0, InstagramPost::count());
    }

    // ======================================================================
    // Settings
    // ======================================================================
    public function test_instagram_settings_fall_back_to_the_meta_app_and_never_expose_the_secret(): void
    {
        $r = $this->asAdmin()->getJson('/api/instagram/settings')->assertOk();
        $this->assertSame('APPID', $r->json('effective_app_id')); $this->assertSame('meta', $r->json('app_id_source')); $this->assertTrue($r->json('configured'));
        $this->assertSame(url('/api/instagram/callback'), $r->json('redirect_uri'));
        $r = $this->asAdmin()->putJson('/api/instagram/settings', ['app_id' => 'IG123', 'app_secret' => 'TOPSECRETVALUE', 'config_id' => 'CFG', 'redirect_uri' => 'https://portal.example/api/instagram/callback'])->assertOk();
        $this->assertSame('IG123', $r->json('effective_app_id')); $this->assertSame('instagram', $r->json('app_id_source')); $this->assertTrue($r->json('app_secret_set'));
        $this->assertStringNotContainsString('TOPSECRETVALUE', $r->getContent());
        $this->assertStringNotContainsString('TOPSECRETVALUE', DB::table('settings')->where('key', 'ig_app_secret')->value('value'));  // encrypted at rest
        $this->asAdmin()->putJson('/api/instagram/settings', ['app_id' => '', 'config_id' => '', 'redirect_uri' => ''])->assertOk()->assertJsonPath('effective_app_id', 'APPID');
        $this->asClient($this->a)->putJson('/api/instagram/settings', ['app_id' => 'x'])->assertForbidden();
    }

    public function test_cleanup_removes_published_and_abandoned_media_only(): void
    {
        $used = $this->mediaId($this->a, 'image', $this->img()); $pending = $this->mediaId($this->a, 'image', $this->img()); $orphan = $this->mediaId($this->a, 'image', $this->img());
        $this->makePost($this->a, $this->acctA, 'image', [$used])->assertCreated(); $this->run1();
        $this->makePost($this->a, $this->acctA, 'image', [$pending], ['mode' => 'draft'])->assertCreated();
        $this->travel(10)->days();
        $this->artisan('instagram:cleanup')->assertSuccessful();
        $this->assertNotNull(InstagramMedia::find($used)->purged_at);
        Storage::disk('public')->assertMissing(InstagramMedia::find($used)->path);
        $this->assertNull(InstagramMedia::find($orphan));
        $this->assertNull(InstagramMedia::find($pending)->purged_at);
        Storage::disk('public')->assertExists(InstagramMedia::find($pending)->path);
    }
}
