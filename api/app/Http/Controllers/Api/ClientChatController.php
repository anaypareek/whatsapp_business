<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Services\FcmService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Chat API for the mobile app (client users only, scoped to the client's own numbers).
 * Load design: see docs/MOBILE.md. The hot path is sync() with an unchanged cursor: one cached lookup, no joins.
 */
class ClientChatController extends Controller
{
    public const VERSION_TTL = 5;     // seconds the per-client change cursor is cached
    public const MAX_DELTA = 200;     // more changes than this => client does a full reload

    protected function numberIds(Request $r): array
    {
        $c = $r->user();
        return Cache::remember("chat:pns:{$c->id}", 60, fn () => $c->phoneNumbers()->orderBy('id')->pluck('id')->all());
    }

    /** Cache key part for this caller's chat cursor (client id; "admin" in AdminChatController). */
    protected function scopeKey(Request $r): string { return (string) $r->user()->id; }

    /** Numbers listed with the conversations. */
    protected function numberList(Request $r)
    {
        return $r->user()->phoneNumbers()->orderBy('id')->get(['id', 'display_number', 'name', 'status', 'quality_rating']);
    }

    /** Fingerprint of the number set (0…999999): changes when a number is assigned/unassigned, forcing a full reload. */
    public static function idsHash(array $ids): int { return crc32(implode(',', $ids)) % 1000000; }

    /**
     * "maxId.maxUpdatedUnix.rowsInThatSecond" over the client's messages; index-only (messages_pn_updated_index + PK), cached a
     * few seconds. The 3rd part catches several updates within the same second (updated_at has 1s precision).
     */
    public static function version(int|string $key, array $ids): string
    {
        if (! $ids) return '0.0.0';
        return Cache::remember("chat:ver:$key", self::VERSION_TTL, function () use ($ids) {
            $row = DB::table('messages')->whereIn('phone_number_id', $ids)->selectRaw('MAX(id) mid, MAX(updated_at) mup')->first();
            $n = $row->mup ? DB::table('messages')->whereIn('phone_number_id', $ids)->where('updated_at', $row->mup)->count() : 0;
            // 3rd part = idsHash*1000 + rows-in-that-second (capped): a newly assigned number changes the cursor even when
            // its (older) messages don't move MAX(id)/MAX(updated_at), and sync() answers with reset → full reload incl. history.
            return (int) $row->mid.'.'.($row->mup ? Carbon::parse($row->mup)->timestamp : 0).'.'.(self::idsHash($ids) * 1000 + min($n, 999));
        });
    }

    public static function bust(int|string $key): void { Cache::forget("chat:ver:$key"); }

    /** A number changed owner (or was created/deleted): drop the cached number lists and cursors of everyone involved. */
    public static function forgetNumberScopes(array $clientIds, ?int $phoneNumberId = null): void
    {
        foreach (array_unique(array_filter($clientIds)) as $c) { Cache::forget("chat:pns:$c"); self::bust($c); }
        Cache::forget('chat:pns:admin'); self::bust('admin');
        if ($phoneNumberId) Cache::forget("chat:pnclient:$phoneNumberId");
    }

    /** GET /client/chat/sync?cursor=… (or ?since=…) → {changed:false} (or 304 with If-None-Match) | deltas. */
    public function sync(Request $r)
    {
        $ids = $this->numberIds($r);
        $cur = static::version($this->scopeKey($r), $ids);
        $etag = '"c'.$cur.'"';
        $given = (string) ($r->query('cursor') ?? $r->query('since', ''));
        if ($given === $cur || $r->header('If-None-Match') === $etag) {
            return response()->json(['changed' => false, 'cursor' => $cur], $r->header('If-None-Match') === $etag ? 304 : 200)->setEtag(trim($etag, '"'));
        }
        if (! preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?$/', $given, $m) || (isset($m[3]) && intdiv((int) $m[3], 1000) !== self::idsHash($ids))) return response()->json(['changed' => true, 'reset' => true, 'cursor' => $cur])->setEtag(trim($etag, '"'));
        [$sinceId, $sinceTs] = [(int) $m[1], (int) $m[2]];
        $msgs = Message::whereIn('phone_number_id', $ids)
            ->where(fn ($q) => $q->where('id', '>', $sinceId)->orWhere('updated_at', '>=', Carbon::createFromTimestamp($sinceTs)))
            ->orderBy('id')->limit(self::MAX_DELTA + 1)->get();
        if ($msgs->count() > self::MAX_DELTA) return response()->json(['changed' => true, 'reset' => true, 'cursor' => $cur])->setEtag(trim($etag, '"'));
        $pairs = $msgs->map(fn ($x) => [$x->phone_number_id, $x->contact_wa_id])->unique(fn ($p) => $p[0].'|'.$p[1])->values();
        return response()->json(['changed' => true, 'reset' => false, 'cursor' => $cur, 'messages' => $msgs,
            'conversations' => $this->summaries($ids, $pairs), 'unread_total' => $this->unreadTotal($ids)])->setEtag(trim($etag, '"'));
    }

    /** GET /client/chat/conversations[?phone_number_id=] → [{phone_number_id, contact_wa_id, contact_name, unread, last_message}] + cursor. */
    public function conversations(Request $r)
    {
        $ids = $this->numberIds($r);
        $cur = static::version($this->scopeKey($r), $ids);
        if ($r->filled('phone_number_id')) $ids = array_values(array_intersect($ids, [(int) $r->phone_number_id]));
        return ['cursor' => $cur, 'conversations' => $this->summaries($ids, null), 'unread_total' => $this->unreadTotal($ids),
            'numbers' => $this->numberList($r)];
    }

    /** GET /client/chat/thread?phone_number_id&contact[&before_id][&limit=50] → newest page (ascending), marks inbound as read. */
    public function thread(Request $r)
    {
        $d = $r->validate(['phone_number_id' => 'required|integer', 'contact' => 'required|string|max:32', 'before_id' => 'nullable|integer', 'limit' => 'nullable|integer|min:1|max:100']);
        abort_unless(in_array((int) $d['phone_number_id'], $this->numberIds($r), true), 404, 'Conversation not found');
        $q = Message::where('phone_number_id', $d['phone_number_id'])->where('contact_wa_id', $d['contact']);
        $limit = (int) ($d['limit'] ?? 50);
        $page = (clone $q)->when($d['before_id'] ?? null, fn ($x, $b) => $x->where('id', '<', $b))->orderByDesc('id')->limit($limit + 1)->get();
        $more = $page->count() > $limit;
        if (empty($d['before_id'])) $this->markRead($r, (int) $d['phone_number_id'], $d['contact']);
        return ['messages' => $page->take($limit)->reverse()->values(), 'has_more' => $more, 'cursor' => static::version($this->scopeKey($r), $this->numberIds($r))];
    }

    /** POST /client/chat/read {phone_number_id, contact} — while a thread is open and new messages arrive. */
    public function read(Request $r)
    {
        $d = $r->validate(['phone_number_id' => 'required|integer', 'contact' => 'required|string|max:32']);
        abort_unless(in_array((int) $d['phone_number_id'], $this->numberIds($r), true), 404, 'Conversation not found');
        return ['marked' => $this->markRead($r, (int) $d['phone_number_id'], $d['contact'])];
    }

    /** GET /client/chat/media/{message}: the file of a message (incoming media is fetched from Meta once and kept). */
    public function media(Request $r, Message $message, WhatsAppService $wa)
    {
        abort_unless(in_array((int) $message->phone_number_id, $this->numberIds($r), true), 404);
        $url = (string) $message->media_url;
        if ($url !== '' && preg_match('#^https?://#', $url)) return redirect()->away($url);
        if ($url !== '' && str_starts_with($url, '/storage/') && Storage::disk('public')->exists($p = substr($url, 9))) {
            return Storage::disk('public')->response($p, $message->filename, ['Cache-Control' => 'private, max-age=86400']);
        }
        abort_unless($message->media_id, 404, 'This message has no media.');
        try { $f = $wa->downloadMedia($message->media_id); }
        catch (Throwable $e) { return $this->fail($e); }
        $mime = strtok((string) ($f['mime_type'] ?: 'application/octet-stream'), ';');
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/aac' => 'aac', 'audio/amr' => 'amr',
            'application/pdf' => 'pdf'][$mime] ?? (pathinfo((string) $message->filename, PATHINFO_EXTENSION) ?: 'bin');
        $path = 'media-in/'.$message->id.'-'.substr(sha1($message->media_id), 0, 10).'.'.$ext;
        Storage::disk('public')->put($path, $f['body']);
        $message->forceFill(['media_url' => '/storage/'.$path, 'mime_type' => $message->mime_type ?: $mime])->saveQuietly();
        return Storage::disk('public')->response($path, $message->filename, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=86400']);
    }

    // ---- FCM devices ----
    public function registerDevice(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|min:20|max:512', 'platform' => 'nullable|in:android,ios', 'app_version' => 'nullable|string|max:32']);
        $dev = DeviceToken::updateOrCreate(['token' => $d['token']], ['client_id' => $r->user()->id, 'platform' => $d['platform'] ?? 'android',
            'app_version' => $d['app_version'] ?? null, 'last_seen_at' => now()]);
        return response()->json(['ok' => true, 'id' => $dev->id], 201);
    }

    public function removeDevice(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|max:512']);
        return ['removed' => DeviceToken::where('client_id', $r->user()->id)->where('token', $d['token'])->delete()];
    }

    /** GET /api/mobile/config (public): runtime Firebase options so push works without rebuilding the APK. */
    public function mobileConfig(Request $r, FcmService $fcm)
    {
        // ?package= lets the Master app (com.fineoutput.wabusiness.admin) get ITS Firebase app id; default = client app
        $pkg = $r->query('package') === FcmService::PACKAGE_ADMIN ? FcmService::PACKAGE_ADMIN : FcmService::PACKAGE;
        $opt = $fcm->clientOptions($pkg);
        $push = ['enabled' => (bool) $opt, 'server_ready' => $fcm->enabled(), 'options' => $opt];
        if (! $opt && $fcm->clientOptions()) $push['reason'] = 'The uploaded google-services.json has no Android app '.$pkg.'. Add it in the Firebase console and re-upload the file.';
        return response()->json(['app' => $pkg === FcmService::PACKAGE ? 'WA Business Fineoutput' : 'WA Fineoutput Master', 'package_name' => $pkg,
            'push' => $push])->header('Cache-Control', 'public, max-age=300');
    }

    // ---- helpers ----
    protected function markRead(Request $r, int $pn, string $contact): int
    {
        $n = Message::where('phone_number_id', $pn)->where('contact_wa_id', $contact)->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);
        if ($n) {
            self::bust($this->scopeKey($r)); self::bust('admin');
            $owner = (int) PhoneNumber::whereKey($pn)->value('client_id');
            if ($owner) self::bust($owner);
        }
        return $n;
    }

    protected function unreadTotal(array $ids): int
    {
        return $ids ? Message::whereIn('phone_number_id', $ids)->where('direction', 'in')->whereNull('read_at')->count() : 0;
    }

    /** Conversation rows for all of $ids, or only for the given [pn, contact] pairs. */
    protected function summaries(array $ids, $pairs)
    {
        if (! $ids || ($pairs !== null && $pairs->isEmpty())) return [];
        $q = Message::whereIn('phone_number_id', $ids);
        if ($pairs !== null) $q->where(function ($w) use ($pairs) { foreach ($pairs as [$pn, $c]) $w->orWhere(fn ($x) => $x->where('phone_number_id', $pn)->where('contact_wa_id', $c)); });
        $rows = $q->select('phone_number_id', 'contact_wa_id', DB::raw('MAX(id) as last_id'), DB::raw("SUM(CASE WHEN direction='in' AND read_at IS NULL THEN 1 ELSE 0 END) as unread"))
            ->groupBy('phone_number_id', 'contact_wa_id')->get();
        $last = Message::whereIn('id', $rows->pluck('last_id'))->get()->keyBy('id');
        $names = Message::whereIn('phone_number_id', $ids)->whereIn('contact_wa_id', $rows->pluck('contact_wa_id')->unique())->whereNotNull('contact_name')
            ->orderByDesc('id')->get(['contact_wa_id', 'contact_name'])->unique('contact_wa_id')->pluck('contact_name', 'contact_wa_id');
        return $rows->map(fn ($x) => ['phone_number_id' => $x->phone_number_id, 'contact_wa_id' => $x->contact_wa_id, 'contact_name' => $names[$x->contact_wa_id] ?? null,
            'unread' => (int) $x->unread, 'last_message' => $last[$x->last_id] ?? null])->sortByDesc(fn ($c) => $c['last_id'] ?? $c['last_message']?->id)->values()->all();
    }
}
