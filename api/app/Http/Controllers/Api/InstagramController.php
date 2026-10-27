<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\InstagramAccount;
use App\Models\InstagramMedia;
use App\Models\InstagramPost;
use App\Services\InstagramConnect;
use App\Services\InstagramGraph;
use App\Services\InstagramMediaService;
use App\Services\InstagramPostService;
use App\Services\InstagramPublisher;
use App\Services\InstagramTokens;
use App\Services\MetaApiException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Instagram module API. The same controller serves /api/client/instagram/* (a client: only their own accounts, posts and media)
 * and /api/instagram/* (admin: everything, can assign accounts to clients). Scoping is done in scopedAccounts()/scopedPosts().
 */
class InstagramController extends Controller
{
    protected function isClient(Request $r): bool { return $r->user() instanceof Client; }
    protected function scopedAccounts(Request $r) { return InstagramAccount::query()->when($this->isClient($r), fn ($q) => $q->where('client_id', $r->user()->id)); }
    protected function scopedPosts(Request $r) { return InstagramPost::query()->whereHas('account', fn ($q) => $q->when($this->isClient($r), fn ($q) => $q->where('client_id', $r->user()->id))); }
    protected function account(Request $r, $id): InstagramAccount { return $this->scopedAccounts($r)->findOrFail($id); }
    protected function post(Request $r, $id): InstagramPost { return $this->scopedPosts($r)->with('items.media', 'account.client')->findOrFail($id); }

    // ---- presentation ----
    protected function accountJson(InstagramAccount $a, bool $admin): array
    {
        $days = $a->token_expires_at ? (int) floor(now()->diffInHours($a->token_expires_at, false) / 24) : null;
        return ['id' => $a->id, 'username' => $a->username, 'name' => $a->name, 'profile_picture_url' => $a->profile_picture_url, 'ig_user_id' => $a->ig_user_id,
            'page_id' => $a->page_id, 'page_name' => $a->page_name, 'client_id' => $a->client_id, 'client' => $a->relationLoaded('client') && $a->client ? ['id' => $a->client->id, 'name' => $a->client->name, 'company' => $a->client->company] : null,
            'status' => $a->status, 'status_reason' => $a->status_reason, 'token_expires_at' => $a->token_expires_at, 'token_days_left' => $days,
            'token_expiring' => $days !== null && $days <= InstagramTokens::RENEW_DAYS, 'data_access_expires_at' => $a->data_access_expires_at, 'last_checked_at' => $a->last_checked_at,
            'quota' => ['usage' => $a->quota_usage, 'total' => $a->quota_total ?: (int) config('services.instagram.daily_limit'), 'checked_at' => $a->quota_checked_at],
            'counts' => isset($a->scheduled_count) ? ['scheduled' => (int) $a->scheduled_count, 'posted' => (int) $a->posted_count, 'failed' => (int) $a->failed_count] : null,
            'created_at' => $a->created_at] + ($admin ? ['scopes' => $a->scopes, 'connected_by_type' => $a->connected_by_type] : []);
    }

    protected function postJson(InstagramPost $p, bool $admin): array
    {
        $a = $p->account;
        return ['id' => $p->id, 'batch_id' => $p->batch_id, 'format' => $p->format, 'caption' => $p->caption, 'share_to_feed' => $p->share_to_feed, 'status' => $p->status,
            'scheduled_at' => $p->scheduled_at, 'posted_at' => $p->posted_at, 'permalink' => $p->permalink, 'ig_media_id' => $p->ig_media_id, 'stage' => $p->stage, 'container_status' => $p->container_status,
            'attempts' => $p->attempts, 'next_attempt_at' => $p->next_attempt_at, 'deferred_reason' => $p->deferred_reason, 'error' => $p->error, 'error_code' => $p->error_code,
            'error_log_id' => $admin ? $p->error_log_id : null, 'created_at' => $p->created_at,
            'account' => $a ? ['id' => $a->id, 'username' => $a->username, 'profile_picture_url' => $a->profile_picture_url, 'status' => $a->status, 'client_id' => $a->client_id,
                'client_name' => $admin ? $a->client?->name : null] : null,
            'items' => $p->items->map(fn ($i) => ['id' => $i->id, 'position' => $i->position, 'container_status' => $i->container_status, 'container_error' => $i->container_error,
                'media' => $i->media ? ['id' => $i->media->id, 'kind' => $i->media->kind, 'url' => $i->media->url, 'width' => $i->media->width, 'height' => $i->media->height,
                    'duration' => $i->media->duration, 'size' => $i->media->size, 'original_name' => $i->media->original_name, 'purged' => (bool) $i->media->purged_at] : null])->values(),
            'editable' => $p->isEditable(), 'can_cancel' => ! $p->ig_media_id && in_array($p->status, ['scheduled', 'publishing'], true) && ! $p->isLocked(),
            'can_retry' => $p->status === 'failed', 'can_publish_now' => $p->status === 'draft' || ($p->status === 'scheduled' && $p->isEditable()),
            'can_delete' => in_array($p->status, ['draft', 'scheduled', 'failed', 'posted'], true) && ! $p->isLocked()];
    }

    // ---- connect ----
    public function config(Request $r)
    {
        return ['configured' => InstagramGraph::configured(), 'redirect_uri' => InstagramGraph::redirectUri(), 'scopes' => InstagramGraph::SCOPES, 'limits' => InstagramMediaService::limits(),
            'daily_limit' => (int) config('services.instagram.daily_limit'), 'timezone' => InstagramPostService::IST];
    }

    public function connectStart(Request $r, InstagramConnect $c)
    {
        $d = $r->validate(['return_to' => 'nullable|string|max:300']);
        if (! InstagramGraph::configured()) return response()->json(['message' => $this->isClient($r) ? 'Instagram is not set up yet. Ask the administrator to add the Instagram app credentials.' : 'Add the Instagram App ID and secret under Settings → Instagram first.'], 422);
        return ['url' => $c->start($r->user(), $d['return_to'] ?? null)];
    }

    public function connectSession(Request $r, InstagramConnect $c, string $sid)
    {
        $s = $c->session($sid, $r->user());
        if (! $s) return response()->json(['message' => 'This connection has expired. Click "Connect Instagram" again.'], 410);
        return ['candidates' => $c->candidates($s), 'expires_at' => $s['expires_at']];
    }

    public function connectSave(Request $r, InstagramConnect $c, string $sid)
    {
        $d = $r->validate(['ig_user_ids' => 'required|array|min:1', 'ig_user_ids.*' => 'string|max:40', 'client_id' => 'nullable|integer|exists:clients,id']);
        $res = $c->save($sid, $r->user(), $d['ig_user_ids'], $this->isClient($r) ? null : ($d['client_id'] ?? null));
        $admin = ! $this->isClient($r);
        return ['saved' => collect($res['saved'])->map(fn ($a) => $this->accountJson($a->load('client'), $admin))->values(), 'skipped' => $res['skipped']];
    }

    // ---- accounts ----
    public function accounts(Request $r)
    {
        $q = $this->scopedAccounts($r)->with('client')->withCount([
            'posts as scheduled_count' => fn ($q) => $q->whereIn('status', ['scheduled', 'publishing']), 'posts as posted_count' => fn ($q) => $q->where('status', 'posted'), 'posts as failed_count' => fn ($q) => $q->where('status', 'failed')]);
        if ($s = $r->query('status')) $q->where('status', $s);
        if (! $this->isClient($r) && $r->filled('client_id')) $r->query('client_id') === 'none' ? $q->whereNull('client_id') : $q->where('client_id', (int) $r->query('client_id'));
        if ($s = trim((string) $r->query('q'))) $q->where(fn ($w) => $w->where('username', 'like', "%$s%")->orWhere('page_name', 'like', "%$s%"));
        $admin = ! $this->isClient($r);
        return $q->orderBy('username')->get()->map(fn ($a) => $this->accountJson($a, $admin))->values();
    }

    public function updateAccount(Request $r, $id)
    {
        $a = $this->account($r, $id); // admin only (route), still scoped
        $d = $r->validate(['client_id' => 'nullable|integer|exists:clients,id']);
        $a->update(['client_id' => $d['client_id'] ?? null]);
        return $this->accountJson($a->fresh('client'), true);
    }

    public function checkAccount(Request $r, $id, InstagramTokens $t, InstagramPublisher $pub)
    {
        $a = $t->check($this->account($r, $id));
        if ($a->isActive()) $pub->quota($a, fresh: true);
        return $this->accountJson($a->fresh('client'), ! $this->isClient($r));
    }

    public function disconnect(Request $r, $id)
    {
        $a = $this->account($r, $id);
        $pending = $a->posts()->whereIn('status', ['scheduled', 'publishing'])->count();
        if ($pending && ! $r->boolean('force')) return response()->json(['message' => "$pending scheduled post(s) for @{$a->username} will be deleted.", 'pending' => $pending, 'needs_force' => true], 409);
        $a->delete(); // posts cascade; the Page/IG account itself is untouched (revoke the app in Facebook settings to remove access fully)
        return response()->noContent();
    }

    // ---- usage (content_publishing_limit) ----
    public function usage(Request $r, InstagramPublisher $pub)
    {
        $accounts = $this->scopedAccounts($r)->with('client')->orderBy('username')->get();
        $total = (int) config('services.instagram.daily_limit');
        $rows = $accounts->map(function ($a) use ($r, $pub) {
            $q = $a->quota_usage !== null ? ['usage' => $a->quota_usage, 'total' => $a->quota_total ?: (int) config('services.instagram.daily_limit'), 'source' => 'cached'] : null;
            if ($r->boolean('refresh') && $a->isActive()) $q = $pub->quota($a, fresh: true);
            $a->refresh();
            return ['account' => ['id' => $a->id, 'username' => $a->username, 'profile_picture_url' => $a->profile_picture_url, 'status' => $a->status, 'client_id' => $a->client_id, 'client_name' => $a->client?->name],
                'usage' => $q['usage'] ?? null, 'total' => $q['total'] ?? ($a->quota_total ?: $total), 'checked_at' => $a->quota_checked_at, 'source' => $q['source'] ?? 'unknown',
                'posted_24h' => InstagramPost::where('instagram_account_id', $a->id)->where('posted_at', '>', \App\Casts\UtcDateTime::now(-86400))->count(),
                'deferred' => InstagramPost::where('instagram_account_id', $a->id)->whereNotNull('deferred_reason')->whereIn('status', ['scheduled', 'publishing'])->count()];
        })->values();
        $counts = $this->scopedPosts($r)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        return ['accounts' => $rows, 'totals' => ['accounts' => $accounts->count(), 'needs_reconnect' => $accounts->where('status', 'needs_reconnect')->count(), 'draft' => (int) ($counts['draft'] ?? 0),
            'scheduled' => (int) ($counts['scheduled'] ?? 0), 'publishing' => (int) ($counts['publishing'] ?? 0), 'posted' => (int) ($counts['posted'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0)],
            'daily_limit' => $total, 'note' => 'quota_usage = posts published in the last 24 hours (rolling), from Instagram\'s content_publishing_limit.'];
    }

    // ---- media ----
    public function upload(Request $r, InstagramMediaService $svc)
    {
        $d = $r->validate(['format' => 'required|in:'.implode(',', InstagramPost::FORMATS)]);
        if (! $r->hasFile('file')) {
            $max = round(InstagramMediaService::uploadLimitBytes() / InstagramMediaService::MB);
            throw ValidationException::withMessages(['file' => "No file received. The server accepts uploads up to $max MB (PHP upload_max_filesize / post_max_size)."]);
        }
        $m = $svc->store($r->file('file'), $d['format'], $r->user());
        return response()->json(['id' => $m->id, 'kind' => $m->kind, 'url' => $m->url, 'width' => $m->width, 'height' => $m->height, 'duration' => $m->duration, 'size' => $m->size,
            'original_name' => $m->original_name, 'warnings' => $m->warnings ?: []], 201);
    }

    public function deleteMedia(Request $r, $id)
    {
        $m = InstagramMedia::when($this->isClient($r), fn ($q) => $q->where('client_id', $r->user()->id))->findOrFail($id);
        if ($m->items()->exists()) return response()->json(['message' => 'This file is used by a post.'], 409);
        $m->deleteFile(); $m->delete();
        return response()->noContent();
    }

    // ---- posts ----
    public function posts(Request $r)
    {
        $q = $this->scopedPosts($r)->with('items.media', 'account.client');
        if ($s = $r->query('status')) $q->whereIn('status', explode(',', $s));
        if ($s = $r->query('format')) $q->where('format', $s);
        if ($s = $r->integer('account_id')) $q->where('instagram_account_id', $s);
        if (! $this->isClient($r) && $r->filled('client_id')) $q->whereHas('account', fn ($w) => $w->where('client_id', (int) $r->query('client_id')));
        if ($s = trim((string) $r->query('q'))) $q->where('caption', 'like', "%$s%");
        // calendar range on the scheduled instant (a posted item keeps the time it was scheduled for); from/to are ISO instants or IST wall-clock
        $utc = fn ($v) => app(InstagramPostService::class)->parse($v)->utc()->format('Y-m-d H:i:s');
        if ($r->filled('from')) $q->where('scheduled_at', '>=', $utc($r->query('from')));
        if ($r->filled('to')) $q->where('scheduled_at', '<', $utc($r->query('to')));
        $dir = $r->query('sort') === 'asc' ? 'asc' : 'desc';
        $page = $q->orderByRaw('COALESCE(scheduled_at, posted_at, created_at) '.$dir)->orderBy('id', $dir)->paginate(min(max($r->integer('per_page', 20), 1), 500));
        $admin = ! $this->isClient($r);
        $page->getCollection()->transform(fn ($p) => $this->postJson($p, $admin));
        return $page;
    }

    public function showPost(Request $r, $id) { return $this->postJson($this->post($r, $id), ! $this->isClient($r)); }

    protected function postRules(): array
    {
        return ['format' => 'required|in:'.implode(',', InstagramPost::FORMATS), 'media_ids' => 'required|array|min:1|max:10', 'media_ids.*' => 'integer', 'caption' => 'nullable|string|max:5000',
            'share_to_feed' => 'nullable|boolean', 'mode' => 'required|in:draft,now,schedule', 'scheduled_at' => 'nullable|string|max:40'];
    }

    public function storePost(Request $r, InstagramPostService $svc)
    {
        $d = $r->validate($this->postRules() + ['account_ids' => 'required|array|min:1|max:20', 'account_ids.*' => 'integer']);
        $accounts = $this->scopedAccounts($r)->whereIn('id', $d['account_ids'])->get();
        if ($accounts->count() !== count(array_unique($d['account_ids']))) throw ValidationException::withMessages(['account_ids' => 'An Instagram account was not found.']);
        $inactive = $accounts->first(fn ($a) => ! $a->isActive());
        if ($inactive && $d['mode'] !== 'draft') throw ValidationException::withMessages(['account_ids' => '@'.$inactive->username.' needs to be reconnected before it can publish.']);
        $posts = $svc->create($d, $accounts, $r->user());
        $admin = ! $this->isClient($r);
        return response()->json(['posts' => collect($posts)->map(fn ($p) => $this->postJson($p->load('account.client'), $admin))->values()], 201);
    }

    public function updatePost(Request $r, $id, InstagramPostService $svc)
    {
        $post = $this->post($r, $id);
        $d = $r->validate($this->postRules() + ['account_id' => 'nullable|integer']);
        $acct = isset($d['account_id']) ? $this->account($r, $d['account_id']) : null;
        if ($acct && $d['mode'] !== 'draft' && ! $acct->isActive()) throw ValidationException::withMessages(['account_id' => '@'.$acct->username.' needs to be reconnected before it can publish.']);
        return $this->postJson($svc->update($post, $d, $r->user(), $acct)->load('account.client'), ! $this->isClient($r));
    }

    public function cancelPost(Request $r, $id, InstagramPostService $svc) { return $this->postJson($svc->cancel($this->post($r, $id))->load('items.media', 'account.client'), ! $this->isClient($r)); }
    public function retryPost(Request $r, $id, InstagramPostService $svc) { return $this->postJson($svc->retry($this->post($r, $id))->load('items.media', 'account.client'), ! $this->isClient($r)); }
    public function publishNowPost(Request $r, $id, InstagramPostService $svc) { return $this->postJson($svc->publishNow($this->post($r, $id))->load('items.media', 'account.client'), ! $this->isClient($r)); }
    public function destroyPost(Request $r, $id, InstagramPostService $svc) { $svc->delete($this->post($r, $id)); return response()->noContent(); }

    /** Admin helper for the "assign to client" pickers etc. */
    public function clients() { return Client::orderBy('name')->get(['id', 'name', 'company']); }

    // ---- OAuth redirect target (public; the state in the cache identifies who is connecting) ----
    public function callback(Request $r, InstagramConnect $c)
    {
        [$path] = $c->callback($r->query('code'), $r->query('state'), $r->query('error'), $r->query('error_description') ?? $r->query('error_reason'));
        $base = rtrim(config('services.instagram.frontend_url') ?: config('app.url'), '/');
        return redirect()->away($base.$path);
    }
}
