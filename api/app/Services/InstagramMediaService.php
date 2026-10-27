<?php
namespace App\Services;

use App\Casts\UtcDateTime;
use App\Models\Client;
use App\Models\InstagramMedia;
use App\Models\InstagramPost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Upload storage + validation against Meta's Content Publishing media specs
 * (https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media, "Image/Reel/Story Specifications").
 * Instagram downloads from a public URL, so files go to the public disk (served at /storage/…).
 */
class InstagramMediaService
{
    public const MB = 1048576;
    public const CAPTION_MAX = 2200;
    public const HASHTAG_MAX = 30;
    public const MENTION_MAX = 20;
    public const EPS = 0.006;

    /** Per format+kind limits. ratio = width/height range that Instagram accepts (null = any); rec = recommended ratio (warning only). */
    public const SPECS = [
        'image:image' => ['max_mb' => 8, 'ratio' => [0.8, 1.91], 'min_w' => 320],
        'carousel:image' => ['max_mb' => 8, 'ratio' => [0.8, 1.91], 'min_w' => 320],
        'story:image' => ['max_mb' => 8, 'ratio' => null, 'rec' => 9 / 16],
        'reel:video' => ['max_mb' => 300, 'min_s' => 3, 'max_s' => 900, 'max_w' => 1920, 'ratio' => [0.01, 10], 'rec' => 9 / 16],
        'story:video' => ['max_mb' => 100, 'min_s' => 3, 'max_s' => 60, 'max_w' => 1920, 'ratio' => [0.1, 10], 'rec' => 9 / 16],
        'carousel:video' => ['max_mb' => 100, 'min_s' => 3, 'max_s' => 60, 'max_w' => 1920, 'ratio' => [0.01, 10]],
    ];

    /** @return array{max_upload_mb:float, specs: array} what the composer shows next to the file picker */
    public static function limits(): array
    {
        return ['server_upload_limit_bytes' => self::uploadLimitBytes(), 'specs' => self::SPECS, 'caption_max' => self::CAPTION_MAX, 'hashtag_max' => self::HASHTAG_MAX,
            'mention_max' => self::MENTION_MAX, 'carousel_min' => 2, 'carousel_max' => 10];
    }

    public static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key)); if ($v === '') return PHP_INT_MAX;
        $n = (int) $v; return match (strtolower(substr($v, -1))) { 'g' => $n * 1024 * self::MB, 'm' => $n * self::MB, 'k' => $n * 1024, default => $n ?: PHP_INT_MAX };
    }
    public static function uploadLimitBytes(): int { return min(self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size')); }

    // ---- caption ----
    public static function captionStats(?string $c): array
    {
        $c = (string) $c;
        return ['chars' => mb_strlen($c), 'hashtags' => preg_match_all('/(?<![\p{L}\p{N}_&])#[\p{L}\p{N}_]+/u', $c), 'mentions' => preg_match_all('/(?<![\p{L}\p{N}_.])@[A-Za-z0-9._]+/u', $c)];
    }
    public static function captionErrors(?string $c): array
    {
        $s = self::captionStats($c); $e = [];
        if ($s['chars'] > self::CAPTION_MAX) $e[] = "Caption is {$s['chars']} characters; Instagram allows ".self::CAPTION_MAX.'.';
        if ($s['hashtags'] > self::HASHTAG_MAX) $e[] = "Caption has {$s['hashtags']} hashtags; Instagram allows ".self::HASHTAG_MAX.'.';
        if ($s['mentions'] > self::MENTION_MAX) $e[] = "Caption has {$s['mentions']} @mentions; Instagram allows ".self::MENTION_MAX.'.';
        return $e;
    }

    // ---- per-file rules for a format ----
    /** @return array{errors: string[], warnings: string[]} */
    public function check(InstagramMedia $m, string $format): array
    {
        $errors = []; $warnings = []; $name = $m->original_name ?: 'file';
        $spec = self::SPECS["$format:{$m->kind}"] ?? null;
        if (! $spec) {
            $errors[] = match (true) {
                $format === 'image' && $m->kind === 'video' => "$name is a video; a single-image post needs an image (use Reel or Story for video).",
                $format === 'reel' && $m->kind === 'image' => "$name is an image; a Reel needs a video.",
                default => "$name can't be used in a $format post.",
            };
            return compact('errors', 'warnings');
        }
        if ($m->size > $spec['max_mb'] * self::MB) $errors[] = sprintf('%s is %.1f MB; the limit for this %s is %d MB.', $name, $m->size / self::MB, $m->kind, $spec['max_mb']);
        $ratio = ($m->width && $m->height) ? $m->width / $m->height : null;
        if ($ratio && ! empty($spec['ratio']) && ($ratio < $spec['ratio'][0] - self::EPS || $ratio > $spec['ratio'][1] + self::EPS)) {
            $errors[] = sprintf('%s is %d×%d (aspect %.2f:1); Instagram accepts %s for this format.', $name, $m->width, $m->height, $ratio, self::ratioText($spec['ratio']));
        } elseif ($ratio && isset($spec['rec']) && abs($ratio - $spec['rec']) / $spec['rec'] > 0.05) {
            $warnings[] = "$name isn't 9:16, so Instagram will crop or letterbox it (9:16, e.g. 1080×1920, is recommended).";
        }
        if ($m->kind === 'image' && $m->width && ! empty($spec['min_w']) && $m->width < $spec['min_w']) $warnings[] = "$name is only {$m->width}px wide; Instagram scales it up to {$spec['min_w']}px.";
        if ($m->kind === 'video') {
            if ($m->duration !== null) {
                if ($m->duration < $spec['min_s'] - 0.05) $errors[] = sprintf('%s is %.1f s; the minimum is %d s.', $name, $m->duration, $spec['min_s']);
                if ($m->duration > $spec['max_s'] + 0.05) $errors[] = sprintf('%s is %s; the maximum for this format is %s.', $name, self::dur($m->duration), self::dur($spec['max_s']));
            } else $warnings[] = "Couldn't read the duration of $name; Instagram will validate it.";
            if ($m->width && $m->width > $spec['max_w']) $errors[] = "$name is {$m->width}px wide; the maximum is {$spec['max_w']}px.";
        }
        foreach ((array) $m->warnings as $w) if (! in_array($w, $warnings, true)) $warnings[] = $w;
        return compact('errors', 'warnings');
    }

    /** Whole-post rules: counts, kinds per format, every file's spec. @param InstagramMedia[] $media ordered */
    public function validatePost(string $format, array $media, ?string $caption): array
    {
        $errors = []; $n = count($media);
        $min = $format === 'carousel' ? 2 : 1; $max = $format === 'carousel' ? 10 : 1;
        if ($n < $min) $errors[] = $format === 'carousel' ? 'A carousel needs at least 2 items.' : 'Add the '.($format === 'reel' ? 'video' : ($format === 'story' ? 'image or video' : 'image')).'.';
        if ($n > $max) $errors[] = $format === 'carousel' ? 'A carousel can have at most 10 items.' : "A $format post has exactly one media file.";
        foreach ($media as $m) $errors = array_merge($errors, $this->check($m, $format)['errors']);
        if ($format !== 'story') $errors = array_merge($errors, self::captionErrors($caption)); // stories have no caption in the API
        return $errors;
    }

    // ---- upload ----
    /** Validate and store one uploaded file for the composer. Throws a 422 listing every problem. */
    public function store(UploadedFile $file, string $format, $actor): InstagramMedia
    {
        if (! $file->isValid()) throw ValidationException::withMessages(['file' => $file->getErrorMessage().' (server upload limit: '.round(self::uploadLimitBytes() / self::MB).' MB; ask the host to raise upload_max_filesize / post_max_size).']);
        $real = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($real) ?: '';
        $warnings = []; $w = $h = null; $dur = null;
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $kind = 'image';
            [$w, $h] = array_slice(@getimagesize($real) ?: [null, null], 0, 2);
            if (! $w) throw ValidationException::withMessages(['file' => 'This image could not be read.']);
            $path = 'instagram/'.now('UTC')->format('Ymd').'/'.Str::random(32).'.jpg';
            if ($mime === 'image/jpeg') { $data = file_get_contents($real); }
            else { // Instagram accepts JPEG only → convert PNG/WebP (alpha flattened on white)
                if (! function_exists('imagecreatefromstring')) throw ValidationException::withMessages(['file' => 'Instagram only accepts JPEG images (PNG/WebP conversion needs the PHP GD extension on this server).']);
                $src = @imagecreatefromstring(file_get_contents($real));
                if (! $src) throw ValidationException::withMessages(['file' => 'This image could not be read.']);
                $dst = imagecreatetruecolor($w, $h); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
                ob_start(); imagejpeg($dst, null, 92); $data = ob_get_clean();
                $warnings[] = ($file->getClientOriginalName() ?: 'Image').' was converted to JPEG (Instagram only accepts JPEG).';
                $mime = 'image/jpeg';
            }
        } elseif (in_array($mime, ['video/mp4', 'video/quicktime', 'video/x-m4v'], true)) {
            $kind = 'video';
            $p = Mp4Probe::probe($real);
            if (! $p) throw ValidationException::withMessages(['file' => 'This video could not be read. Use an MP4 or MOV (H.264 or HEVC video, AAC audio).']);
            [$w, $h, $dur] = [$p['width'], $p['height'], $p['duration']];
            if ($p['faststart'] === false) $warnings[] = 'The moov atom is at the end of this video; Instagram may reject it. Re-export with "fast start" (ffmpeg -movflags +faststart).';
            if ($p['codec'] && ! in_array($p['codec'], ['avc1', 'avc3', 'hvc1', 'hev1'], true)) $warnings[] = "Video codec '{$p['codec']}' may not be supported; use H.264 or HEVC.";
            $path = 'instagram/'.now('UTC')->format('Ymd').'/'.Str::random(32).($mime === 'video/quicktime' ? '.mov' : '.mp4');
            $data = null;
        } else {
            throw ValidationException::withMessages(['file' => 'Unsupported file type'.($mime ? " ($mime)" : '').'. Use JPEG/PNG/WebP images or MP4/MOV videos.']);
        }
        $m = new InstagramMedia(['kind' => $kind, 'path' => $path, 'mime' => $mime, 'original_name' => mb_substr($file->getClientOriginalName() ?: 'upload', 0, 200),
            'size' => $data !== null ? strlen($data) : filesize($real), 'width' => $w, 'height' => $h, 'duration' => $dur, 'warnings' => $warnings,
            'client_id' => $actor instanceof Client ? $actor->id : null, 'user_id' => $actor instanceof Client ? null : $actor->id]);
        $r = $this->check($m, $format);
        if ($r['errors']) throw ValidationException::withMessages(['file' => $r['errors']]);
        $disk = Storage::disk('public');
        if ($data !== null) $disk->put($path, $data); else $disk->putFileAs(dirname($path), $file, basename($path));
        $m->warnings = $r['warnings'];
        $m->save();
        return $m;
    }

    /** Files are only needed until Instagram has fetched them: delete those of posts published > $days ago, and uploads never attached to a post. */
    public function cleanup(int $days = 7): array
    {
        $files = 0;
        $done = InstagramMedia::whereNull('purged_at')->whereHas('items.post', fn ($q) => $q->where('status', 'posted')->where('posted_at', '<', UtcDateTime::now(-$days * 86400)))
            ->whereDoesntHave('items.post', fn ($q) => $q->where('status', '!=', 'posted'));
        $done->each(function (InstagramMedia $m) use (&$files) { $m->deleteFile(); $m->forceFill(['purged_at' => now()])->save(); $files++; });
        InstagramMedia::whereDoesntHave('items')->where('created_at', '<', now()->subDays(2))->each(function (InstagramMedia $m) use (&$files) { $m->deleteFile(); $m->delete(); $files++; });
        return compact('files');
    }

    public static function ratioText(array $r): string
    {
        return $r[0] == 0.8 ? '4:5 (0.8) to 1.91:1' : $r[0].':1 to '.$r[1].':1';
    }
    public static function dur(float $s): string { return $s >= 120 ? round($s / 60, 1).' min' : round($s, 1).' s'; }
}
