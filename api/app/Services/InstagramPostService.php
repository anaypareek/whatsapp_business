<?php
namespace App\Services;

use App\Models\Client;
use App\Models\InstagramAccount;
use App\Models\InstagramMedia;
use App\Models\InstagramPost;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Create / edit / schedule / cancel / retry / delete. Publishing itself is InstagramPublisher (cron). All times in = IST or ISO-with-zone, stored UTC. */
class InstagramPostService
{
    public const IST = 'Asia/Kolkata';
    public const MIN_LEAD_MINUTES = 0;

    public function __construct(protected InstagramPublisher $publisher) {}

    /**
     * @param array{format:string,account_ids:int[],media_ids:int[],caption?:?string,share_to_feed?:bool,mode:string,scheduled_at?:?string} $d  mode: draft | now | schedule
     * @param \Illuminate\Support\Collection $accounts already authorized by the caller
     * @return InstagramPost[] one post per selected account (same batch_id)
     */
    public function create(array $d, $accounts, $actor): array
    {
        $media = $this->mediaFor($d['media_ids'], $actor, $d['format']);
        $this->validate($d['format'], $media, $d['caption'] ?? null, $accounts);
        $when = $this->when($d['mode'], $d['scheduled_at'] ?? null);
        $batch = (string) Str::uuid();
        return DB::transaction(function () use ($d, $accounts, $actor, $media, $when, $batch) {
            $out = [];
            foreach ($accounts as $a) {
                $post = InstagramPost::create(['instagram_account_id' => $a->id, 'batch_id' => $batch, 'format' => $d['format'], 'caption' => $d['format'] === 'story' ? null : ($d['caption'] ?? null),
                    'share_to_feed' => (bool) ($d['share_to_feed'] ?? true), 'status' => $when['status'], 'scheduled_at' => $when['at'], 'stage' => 'new',
                    'created_by_type' => $actor instanceof Client ? 'client' : 'user', 'created_by_id' => $actor->id]);
                foreach ($media as $i => $m) $post->items()->create(['instagram_media_id' => $m->id, 'position' => $i]);
                $out[] = $post->load('items.media', 'account');
            }
            return $out;
        });
    }

    public function update(InstagramPost $post, array $d, $actor, ?InstagramAccount $account = null): InstagramPost
    {
        if (! $post->isEditable()) throw ValidationException::withMessages(['post' => $this->notEditableReason($post)]);
        $media = $this->mediaFor($d['media_ids'], $actor, $d['format'], $post);
        $this->validate($d['format'], $media, $d['caption'] ?? null, collect([$account ?? $post->account]));
        $when = $this->when($d['mode'], $d['scheduled_at'] ?? null);
        return DB::transaction(function () use ($post, $d, $media, $when, $account) {
            $post->forceFill(['instagram_account_id' => $account?->id ?? $post->instagram_account_id, 'format' => $d['format'], 'caption' => $d['format'] === 'story' ? null : ($d['caption'] ?? null),
                'share_to_feed' => (bool) ($d['share_to_feed'] ?? true), 'status' => $when['status'], 'scheduled_at' => $when['at']])->save();
            $post->items()->delete();
            foreach ($media as $i => $m) $post->items()->create(['instagram_media_id' => $m->id, 'position' => $i]);
            $this->clearRun($post, withError: true);
            return $post->load('items.media', 'account');
        });
    }

    /** scheduled/publishing (nothing live yet) → draft. */
    public function cancel(InstagramPost $post): InstagramPost
    {
        if ($post->ig_media_id || ! in_array($post->status, ['scheduled', 'publishing'], true)) throw ValidationException::withMessages(['post' => 'Only posts that have not been published yet can be cancelled.']);
        if ($post->isLocked()) throw ValidationException::withMessages(['post' => 'This post is being published right now. Try again in a minute.']);
        $post->forceFill(['status' => 'draft', 'scheduled_at' => null])->save();
        $this->clearRun($post);
        return $post->refresh();
    }

    /** failed → scheduled for now (resumes from the stored container when one is still valid; starts over when the failure was the container). */
    public function retry(InstagramPost $post): InstagramPost
    {
        if ($post->status !== 'failed') throw ValidationException::withMessages(['post' => 'Only failed posts can be retried.']);
        if (! $post->ig_media_id && $post->account && ! $post->account->isActive()) throw ValidationException::withMessages(['post' => 'Reconnect @'.$post->account->username.' first (Instagram → Accounts → Reconnect).']);
        $post->forceFill(['status' => 'scheduled', 'scheduled_at' => now(), 'attempts' => 0])->save();
        $this->clearRun($post, withError: true, keepContainers: (bool) $post->ig_media_id);
        return $post->refresh();
    }

    public function publishNow(InstagramPost $post): InstagramPost
    {
        if ($post->status !== 'draft' && ! ($post->status === 'scheduled' && $post->isEditable())) throw ValidationException::withMessages(['post' => 'This post cannot be published now.']);
        $post->forceFill(['status' => 'scheduled', 'scheduled_at' => now()])->save();
        $this->clearRun($post);
        return $post->refresh();
    }

    public function delete(InstagramPost $post): void
    {
        if ($post->status === 'publishing') throw ValidationException::withMessages(['post' => 'Cancel the post before deleting it.']);
        if ($post->isLocked()) throw ValidationException::withMessages(['post' => 'This post is being published right now. Try again in a minute.']);
        $post->delete(); // removes the record here only; a posted item stays on Instagram
    }

    // ---- helpers ----
    public function notEditableReason(InstagramPost $p): string
    {
        return match (true) { $p->status === 'posted' => 'This post is already on Instagram and can only be changed there.', $p->status === 'publishing' => 'This post is being published. Cancel it first, or wait for the result.',
            $p->isLocked() => 'This post is being published right now.', $p->status === 'scheduled' => 'The publish time has passed; the post is about to go out. Cancel it to edit.', default => 'This post cannot be edited.' };
    }

    protected function clearRun(InstagramPost $post, bool $withError = false, bool $keepContainers = false): void
    {
        $attrs = ['next_attempt_at' => null, 'deferred_reason' => null, 'locked_until' => null];
        if ($withError) $attrs += ['error' => null, 'error_code' => null, 'error_log_id' => null];
        $post->forceFill($attrs)->save();
        if ($keepContainers) { $post->forceFill(['stage' => 'published'])->save(); return; }
        $this->publisher->resetContainers($post);
    }

    /** Media must exist, belong to the actor's client scope, and be used in the given order. @return InstagramMedia[] */
    protected function mediaFor(array $ids, $actor, string $format, ?InstagramPost $post = null): array
    {
        $found = InstagramMedia::whereIn('id', $ids)->get()->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            $m = $found[$id] ?? throw ValidationException::withMessages(['media_ids' => 'A selected media file no longer exists. Upload it again.']);
            if ($actor instanceof Client && $m->client_id !== $actor->id) throw ValidationException::withMessages(['media_ids' => 'A selected media file was not found.']);
            if ($m->purged_at) throw ValidationException::withMessages(['media_ids' => ($m->original_name ?: 'A media file').' was removed from the server after posting. Upload it again.']);
            $out[] = $m;
        }
        return $out;
    }

    protected function validate(string $format, array $media, ?string $caption, $accounts): void
    {
        $errors = app(InstagramMediaService::class)->validatePost($format, $media, $caption);
        if ($accounts->isEmpty()) $errors[] = 'Choose at least one Instagram account.';
        if ($errors) throw ValidationException::withMessages(['post' => $errors]);
    }

    /** @return array{status:string,at:?Carbon} */
    public function when(string $mode, ?string $at): array
    {
        if ($mode === 'draft') return ['status' => 'draft', 'at' => $at ? $this->parse($at) : null];
        if ($mode === 'now') return ['status' => 'scheduled', 'at' => now()];
        $t = $at ? $this->parse($at) : throw ValidationException::withMessages(['scheduled_at' => 'Pick the date and time to publish.']);
        if ($t->lt(now()->addMinutes(self::MIN_LEAD_MINUTES)->subMinute())) throw ValidationException::withMessages(['scheduled_at' => 'The scheduled time is in the past (times are Indian Standard Time, IST).']);
        if ($t->gt(now()->addDays(75))) throw ValidationException::withMessages(['scheduled_at' => 'Instagram scheduling is limited to 75 days ahead.']);
        return ['status' => 'scheduled', 'at' => $t];
    }

    /** "2026-10-28T18:30" (no zone) = IST wall-clock; anything with Z / ±hh:mm is taken as given. */
    public function parse(string $s): Carbon
    {
        try { return preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($s)) ? Carbon::parse($s) : Carbon::parse($s, self::IST); }
        catch (\Throwable) { throw ValidationException::withMessages(['scheduled_at' => 'Invalid date/time.']); }
    }
}
