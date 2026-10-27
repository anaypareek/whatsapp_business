<?php
namespace App\Services;

use App\Casts\UtcDateTime;
use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Models\InstagramPostItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publishes due posts. Designed for shared hosting: no queue worker and no long-running process; `php artisan schedule:run`
 * (cron, every minute) calls instagram:publish, which calls runDue(). Nothing here ever sleeps or blocks on Instagram:
 *
 *   scheduled ──claim──▶ stage new        create item containers (POST /{ig}/media)         [quota checked first; deferred if exhausted]
 *                        stage processing poll GET /{container}?fields=status_code           [IN_PROGRESS → come back next minute]
 *                                         carousel: when all children FINISHED create the CAROUSEL parent container, poll it
 *                        stage ready      quota re-checked fresh, POST /{ig}/media_publish   → ig_media_id saved immediately
 *                        stage published  GET /{media}?fields=permalink                      → status posted
 *
 * The container ids are stored after every call, so a run that is interrupted at any point resumes where it stopped (and a post is
 * never published twice: ig_media_id is checked before media_publish). A batch is reserved in one transaction with SELECT … FOR UPDATE
 * (locked_until), on top of the scheduler's withoutOverlapping(), so two overlapping cron runs can't process the same post.
 */
class InstagramPublisher
{
    public const BATCH = 10;
    public const LOCK_SECONDS = 240;
    public const POLL_DELAY = 30;            // seconds between status polls (cron granularity is 1 minute anyway)
    public const MAX_PROCESSING_MINUTES = 30; // a container still IN_PROGRESS after this long is given up on
    public const MAX_ATTEMPTS = 5;
    public const QUOTA_DEFER_MINUTES = 15;
    public const CONTAINER_TTL_HOURS = 23;    // Meta expires containers after 24 h
    public const QUOTA_CACHE_SECONDS = 120;
    /** Graph error codes that mean "slow down / try again" rather than "this post is wrong". */
    public const TRANSIENT_CODES = [1, 2, 4, 17, 32, 341, 368, 613, 9007];

    public function __construct(protected InstagramGraph $graph) {}

    /** @return array{claimed:int,posted:int,failed:int,waiting:int,deferred:int} */
    public function runDue(int $limit = self::BATCH): array
    {
        $stats = ['claimed' => 0, 'posted' => 0, 'failed' => 0, 'waiting' => 0, 'deferred' => 0];
        foreach ($this->claimBatch($limit) as $id) {
            $stats['claimed']++;
            $post = InstagramPost::with(['account', 'items.media'])->find($id);
            if (! $post) continue;
            try {
                $this->advance($post);
            } catch (Throwable $e) {
                $this->handleError($post, $e);
            } finally {
                $post->refresh();
                if ($post->locked_until) $post->forceFill(['locked_until' => null])->save();
            }
            $key = match ($post->status) { 'posted' => 'posted', 'failed' => 'failed', default => $post->deferred_reason ? 'deferred' : 'waiting' };
            $stats[$key]++;
        }
        return $stats;
    }

    /** Reserve due posts in one transaction (row locks) by stamping locked_until; returns their ids. */
    protected function claimBatch(int $limit): array
    {
        return DB::transaction(function () use ($limit) {
            $now = UtcDateTime::now();
            $ids = InstagramPost::whereIn('status', ['scheduled', 'publishing'])
                ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
                ->where(fn ($q) => $q->where('status', 'publishing')->orWhere('scheduled_at', '<=', $now))
                ->orderBy('scheduled_at')->orderBy('id')->limit($limit)->lockForUpdate()->pluck('id')->all();
            if ($ids) InstagramPost::whereIn('id', $ids)->update(['locked_until' => UtcDateTime::now(self::LOCK_SECONDS)]);
            return $ids;
        });
    }

    protected function advance(InstagramPost $post): void
    {
        $a = $post->account;
        if (! $a) { $this->fail($post, 'The Instagram account was disconnected.', 'IG_ACCOUNT_GONE'); return; }
        if ($post->ig_media_id) { $post->stage = 'published'; } // published on Instagram earlier; only the bookkeeping is left
        elseif (! $a->isActive()) { $this->fail($post, 'This Instagram account needs to be reconnected'.($a->status_reason ? ': '.$a->status_reason : '.'), 'IG_RECONNECT'); return; }
        if ($post->stage !== 'new' && $post->stage !== 'published' && $post->container_created_at && $post->container_created_at->lt(now()->subHours(self::CONTAINER_TTL_HOURS))) {
            $this->resetContainers($post); // Meta containers expire after 24 h: start over
        }

        for ($guard = 0; $guard < 6; $guard++) {
            switch ($post->stage) {
                case 'new':
                    if ($this->deferIfQuotaExhausted($post, $a, fresh: false)) return;
                    $post->forceFill(['status' => 'publishing', 'deferred_reason' => null])->save();
                    $this->createItemContainers($post, $a);
                    $post->forceFill(['stage' => 'processing', 'container_created_at' => $post->container_created_at ?? now()])->save();
                    break;
                case 'processing':
                    if (! $this->pollContainers($post, $a)) return; // not finished yet (or failed); the next cron run resumes here
                    $post->forceFill(['stage' => 'ready', 'deferred_reason' => null])->save();
                    break;
                case 'ready':
                    if ($this->deferIfQuotaExhausted($post, $a, fresh: true)) return;
                    $mediaId = $this->graph->publish($a, $post->container_id);
                    $post->forceFill(['ig_media_id' => $mediaId, 'stage' => 'published', 'deferred_reason' => null])->save();
                    break;
                case 'published':
                    $this->finish($post, $a);
                    return;
                default:
                    $this->resetContainers($post);
            }
        }
    }

    // ---- stage: new ----
    protected function createItemContainers(InstagramPost $post, InstagramAccount $a): void
    {
        $carousel = $post->format === 'carousel';
        foreach ($post->items as $item) {
            if ($item->container_id) continue; // resume: this item's container was already created
            $m = $item->media; $video = $m->kind === 'video';
            $p = match ($post->format) {
                'image' => ['image_url' => $m->url, 'caption' => $post->caption],
                'reel' => ['media_type' => 'REELS', 'video_url' => $m->url, 'caption' => $post->caption, 'share_to_feed' => $post->share_to_feed],
                'story' => ['media_type' => 'STORIES'] + ($video ? ['video_url' => $m->url] : ['image_url' => $m->url]),
                'carousel' => ['is_carousel_item' => true] + ($video ? ['media_type' => 'VIDEO', 'video_url' => $m->url] : ['image_url' => $m->url]),
            };
            $item->forceFill(['container_id' => $this->graph->createContainer($a, array_filter($p, fn ($v) => $v !== null && $v !== '')), 'container_status' => 'IN_PROGRESS', 'container_error' => null])->save();
            if ($post->container_created_at === null) $post->forceFill(['container_created_at' => now()])->save();
        }
        if (! $carousel) $post->forceFill(['container_id' => $post->items->first()->container_id, 'container_status' => 'IN_PROGRESS'])->save();
    }

    // ---- stage: processing ----
    /** @return bool true when everything (incl. the carousel parent) is FINISHED. false = wait (or the post was failed). */
    protected function pollContainers(InstagramPost $post, InstagramAccount $a): bool
    {
        $waiting = false;
        foreach ($post->items as $item) {
            if ($item->container_status === 'FINISHED') continue;
            $s = $this->graph->containerStatus($a, $item->container_id);
            $item->forceFill(['container_status' => $s['status_code'], 'container_error' => $s['status_code'] === 'ERROR' || $s['status_code'] === 'EXPIRED' ? mb_substr((string) $s['status'], 0, 500) : null])->save();
            if (in_array($s['status_code'], ['ERROR', 'EXPIRED'], true)) {
                $this->fail($post, $this->containerFailure($post, $item, $s), 'IG_CONTAINER_'.$s['status_code'], context: ['container_id' => $item->container_id, 'status' => $s['status']]);
                return false;
            }
            if ($s['status_code'] !== 'FINISHED') $waiting = true;
        }
        if ($post->format !== 'carousel') {
            $post->container_status = $post->items->first()->container_status;
        } elseif (! $waiting) {
            if (! $post->container_id) {
                $post->container_id = $this->graph->createContainer($a, array_filter(['media_type' => 'CAROUSEL', 'children' => $post->items->pluck('container_id')->implode(','), 'caption' => $post->caption]));
                $post->container_status = 'IN_PROGRESS'; $post->save();
            }
            if ($post->container_status !== 'FINISHED') {
                $s = $this->graph->containerStatus($a, $post->container_id);
                $post->container_status = $s['status_code']; $post->save();
                if (in_array($s['status_code'], ['ERROR', 'EXPIRED'], true)) { $this->fail($post, 'Instagram could not assemble the carousel: '.($s['status'] ?: $s['status_code']), 'IG_CONTAINER_'.$s['status_code'], context: ['container_id' => $post->container_id]); return false; }
                if ($s['status_code'] !== 'FINISHED') $waiting = true;
            }
        }
        $post->save();
        if (! $waiting) return true;

        if ($post->container_created_at && $post->container_created_at->lt(now()->subMinutes(self::MAX_PROCESSING_MINUTES))) {
            $this->fail($post, 'Instagram is still processing the media after '.self::MAX_PROCESSING_MINUTES.' minutes. Try again later, or use a smaller/shorter file.', 'IG_PROCESSING_TIMEOUT');
            return false;
        }
        $post->forceFill(['next_attempt_at' => now()->addSeconds(self::POLL_DELAY), 'deferred_reason' => null])->save();
        return false;
    }

    protected function containerFailure(InstagramPost $post, InstagramPostItem $item, array $s): string
    {
        $what = $post->format === 'carousel' ? 'carousel item '.($item->position + 1) : 'the media';
        return 'Instagram could not process '.$what.' ('.$s['status_code'].')'.($s['status'] ? ': '.$s['status'] : '.');
    }

    // ---- stage: published ----
    protected function finish(InstagramPost $post, InstagramAccount $a): void
    {
        if (! $post->permalink) {
            try { $post->permalink = $this->graph->permalink($a, $post->ig_media_id); } catch (Throwable) { /* the post IS live; the link is a nicety, retried below */ }
        }
        $post->forceFill(['status' => 'posted', 'posted_at' => $post->posted_at ?? now(), 'deferred_reason' => null, 'next_attempt_at' => null, 'error' => null, 'error_code' => null])->save();
        $a->forceFill(['quota_usage' => ($a->quota_usage ?? 0) + 1])->save();
    }

    // ---- quota ----
    /** @return array{usage:int,total:int,source:string} */
    public function quota(InstagramAccount $a, bool $fresh): array
    {
        if (! $fresh && $a->quota_checked_at && $a->quota_checked_at->gt(now()->subSeconds(self::QUOTA_CACHE_SECONDS)) && $a->quota_usage !== null) {
            return ['usage' => $a->quota_usage, 'total' => $a->quota_total ?: config('services.instagram.daily_limit'), 'source' => 'cached'];
        }
        try {
            $q = $this->graph->publishingLimit($a);
            $a->forceFill(['quota_usage' => $q['usage'], 'quota_total' => $q['total'], 'quota_checked_at' => now()])->save();
            return $q + ['source' => 'meta'];
        } catch (Throwable) { // the limit endpoint itself failed (already in Error Logs): count our own posts of the last 24 h instead
            $used = InstagramPost::where('instagram_account_id', $a->id)->whereNotNull('ig_media_id')->where('posted_at', '>', UtcDateTime::now(-86400))->count();
            return ['usage' => $used, 'total' => $a->quota_total ?: config('services.instagram.daily_limit'), 'source' => 'local'];
        }
    }

    protected function deferIfQuotaExhausted(InstagramPost $post, InstagramAccount $a, bool $fresh): bool
    {
        $q = $this->quota($a, $fresh);
        if ($q['usage'] < $q['total']) return false;
        $post->forceFill(['deferred_reason' => "Instagram publishing limit reached for @{$a->username} ({$q['usage']}/{$q['total']} posts in the last 24 hours). It will be retried automatically.",
            'next_attempt_at' => now()->addMinutes(self::QUOTA_DEFER_MINUTES)])->save();
        return true;
    }

    // ---- errors ----
    protected function handleError(InstagramPost $post, Throwable $e): void
    {
        $post->refresh();
        if ($post->status === 'failed' || $post->status === 'posted') return;
        $post->attempts++;
        if ($e instanceof MetaApiException) {
            $code = $e->metaCode();
            if ($code === 190 || ($e->httpStatus === 401)) { $post->account?->markReconnect($e->getMessage()); $this->fail($post, 'The Instagram login is no longer valid. Reconnect the account, then retry. '.$e->getMessage(), 'IG_RECONNECT', $e); return; }
            $transient = $e->httpStatus >= 500 || ($e->error['is_transient'] ?? false) || in_array($code, self::TRANSIENT_CODES, true);
            if ($transient && $post->attempts < self::MAX_ATTEMPTS) {
                $rate = in_array($code, [4, 17, 32, 613], true);
                $this->retryLater($post, $e->getMessage(), $rate ? self::QUOTA_DEFER_MINUTES * 60 : min(60 * 2 ** $post->attempts, 1800));
                return;
            }
            $this->fail($post, $this->explain($e), $e->errorCode(), $e);
            return;
        }
        if ($e instanceof ConnectionException && $post->attempts < self::MAX_ATTEMPTS) { $this->retryLater($post, 'Could not reach Instagram: '.$e->getMessage(), min(60 * 2 ** $post->attempts, 1800)); return; }
        report($e);
        $this->fail($post, 'Unexpected error while publishing: '.$e->getMessage(), 'IG_PUBLISH_ERROR', $e);
    }

    protected function explain(MetaApiException $e): string
    {
        $m = $e->getMessage();
        if (in_array($e->metaSubcode(), [2207052, 2207003, 2207020], true) || $e->metaCode() === 9004) $m .= ' Instagram must be able to download the file from a public https URL: check APP_URL / IG_MEDIA_BASE_URL and that /storage is reachable from the internet.';
        if (in_array($e->metaCode(), [10, 200], true)) $m .= ' The account is missing a permission (instagram_content_publish) or the Facebook user lost access to the Page.';
        return $m;
    }

    protected function retryLater(InstagramPost $post, string $why, int $seconds): void
    {
        $post->forceFill(['next_attempt_at' => now()->addSeconds($seconds), 'deferred_reason' => 'Temporary problem, retrying ('.$post->attempts.'/'.self::MAX_ATTEMPTS.'): '.mb_substr($why, 0, 350)])->save();
    }

    public function fail(InstagramPost $post, string $message, string $code, ?Throwable $e = null, array $context = []): void
    {
        $metaLog = $e instanceof MetaApiException ? ErrorLogger::alreadyLogged($e) : null; // the Graph call itself was logged (source meta) by InstagramGraph
        $newLog = ErrorLogger::record(['level' => 'error', 'source' => 'app', 'message' => "Instagram post #{$post->id} (@".($post->account?->username ?? '?').") failed: $message", 'error_code' => $code,
            'context' => ['post_id' => $post->id, 'format' => $post->format, 'stage' => $post->stage, 'meta_log_id' => $metaLog, 'container_id' => $post->container_id] + $context]);
        $logId = $metaLog ?? $newLog;
        $post->forceFill(['status' => 'failed', 'error' => mb_substr($message, 0, 2000), 'error_code' => $code, 'error_log_id' => $logId, 'next_attempt_at' => null, 'deferred_reason' => null, 'locked_until' => null])->save();
    }

    public function resetContainers(InstagramPost $post): void
    {
        $post->items()->update(['container_id' => null, 'container_status' => null, 'container_error' => null]);
        $post->forceFill(['stage' => 'new', 'container_id' => null, 'container_status' => null, 'container_created_at' => null])->save();
        $post->load('items.media');
    }
}
