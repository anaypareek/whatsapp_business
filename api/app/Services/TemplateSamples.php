<?php
namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Header media samples for templates. The file is stored privately first (both portals) and uploaded to Meta's
 * Resumable Upload API only when the template is actually submitted (admin create/edit, or admin approval of a client request).
 */
class TemplateSamples
{
    public const DISK = 'local';
    public const LIMITS = [ // format => [extensions, mimes, max KB, label]
        'IMAGE' => [['jpg', 'jpeg', 'png'], ['image/jpeg', 'image/png'], 5 * 1024, 'JPG or PNG up to 5 MB'],
        'VIDEO' => [['mp4'], ['video/mp4'], 16 * 1024, 'MP4 up to 16 MB'],
        'DOCUMENT' => [['pdf'], ['application/pdf'], 100 * 1024, 'PDF up to 100 MB'],
    ];

    /** Validate + store; owner scopes the token (e.g. "c12" for a client, "a" for admins). Returns metadata incl. the sample token. */
    public static function store(UploadedFile $f, string $format, string $owner): array
    {
        $format = strtoupper($format);
        [$exts, $mimes, $maxKb, $label] = self::LIMITS[$format] ?? throw ValidationException::withMessages(['format' => 'Header format must be IMAGE, VIDEO or DOCUMENT.']);
        $ext = strtolower($f->getClientOriginalExtension());
        $mime = $f->getMimeType();
        if (! in_array($ext, $exts, true) || ! in_array($mime, $mimes, true)) throw ValidationException::withMessages(['file' => "Wrong file type for a {$format} header: use {$label}."]);
        if ($f->getSize() > $maxKb * 1024) throw ValidationException::withMessages(['file' => "File too large for a {$format} header: use {$label}."]);
        $token = "{$owner}/".Str::random(24).".{$ext}";
        Storage::disk(self::DISK)->putFileAs('template-samples', $f, $token);
        return ['sample' => $token, 'filename' => mb_substr($f->getClientOriginalName(), 0, 200), 'size' => $f->getSize(), 'mime' => $mime, 'format' => $format];
    }

    public static function valid(?string $token, ?string $owner = null): bool
    {
        if (! $token || ! preg_match('#^(a|c\d+)/[A-Za-z0-9]{24}\.(jpe?g|png|mp4|pdf)$#', $token)) return false;
        if ($owner !== null && ! str_starts_with($token, "{$owner}/")) return false;
        return Storage::disk(self::DISK)->exists("template-samples/{$token}");
    }

    public static function path(string $token): string { return Storage::disk(self::DISK)->path("template-samples/{$token}"); }

    public static function mime(string $token): string
    {
        return match (strtolower(pathinfo($token, PATHINFO_EXTENSION))) { 'png' => 'image/png', 'mp4' => 'video/mp4', 'pdf' => 'application/pdf', default => 'image/jpeg' };
    }

    /** If the form carries a stored sample, upload it to Meta now and put the handle into the HEADER component. */
    public static function resolve(array $components, ?array $header, WhatsAppService $wa, ?string $filename = null): array
    {
        $token = $header['sample'] ?? null;
        if (! $token) return $components;
        if (! self::valid($token)) throw ValidationException::withMessages(['header.sample' => 'The header sample file is missing or expired. Upload it again.']);
        if (! \App\Models\Setting::get('app_id')) throw ValidationException::withMessages(['header.sample' => 'Meta App ID is not set in Settings, so the header sample cannot be uploaded to Meta. Add the App ID under Settings → Meta and try again.']);
        $h = $wa->uploadSample(self::path($token), self::mime($token), $header['sample_name'] ?? $filename ?? basename($token));
        foreach ($components as &$c) if (($c['type'] ?? '') === 'HEADER' && in_array($c['format'] ?? '', ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) $c['example'] = ['header_handle' => [$h]];
        return $components;
    }
}
