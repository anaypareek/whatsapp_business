<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * API key for server-to-server calls. Only a sha256 hash is stored; the plain key is shown once.
 * client_id NULL = admin/global key (legacy keys; may use any number/template). Otherwise scoped to that client.
 */
class ApiKey extends Model
{
    public const PREFIX_LEN = 10;
    protected $fillable = ['client_id', 'name', 'prefix', 'key_hash', 'allowed_ips', 'allowed_origins', 'rate_limit_per_minute',
        'last_used_at', 'last_used_ip', 'revoked_at', 'created_by'];
    protected $hidden = ['key_hash'];
    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime', 'allowed_ips' => 'array', 'allowed_origins' => 'array',
        'rate_limit_per_minute' => 'integer', 'client_id' => 'integer'];
    protected $appends = ['active'];

    public function client() { return $this->belongsTo(Client::class); }
    public function getActiveAttribute(): bool { return $this->revoked_at === null; }

    /** @return array{0: ApiKey, 1: string} */
    public static function generate(string $name, ?int $userId = null, array $attrs = []): array
    {
        $plain = 'wab_'.Str::random(40);
        $key = static::create(['name' => $name, 'prefix' => substr($plain, 0, self::PREFIX_LEN), 'key_hash' => hash('sha256', $plain), 'created_by' => $userId] + $attrs);
        return [$key, $plain];
    }

    /** Prefix lookup + constant-time hash compare. Returns revoked keys too (caller decides). */
    public static function findByPlain(string $plain): ?self
    {
        if (strlen($plain) < self::PREFIX_LEN + 8 || strlen($plain) > 200) return null;
        $hash = hash('sha256', $plain);
        foreach (static::where('prefix', substr($plain, 0, self::PREFIX_LEN))->get() as $k) {
            if (hash_equals($k->key_hash, $hash)) return $k;
        }
        return null;
    }

    public function revoke(): void { if (! $this->revoked_at) $this->forceFill(['revoked_at' => now()])->save(); }

    public function ipAllowed(?string $ip): bool
    {
        $list = array_values(array_filter($this->allowed_ips ?? []));
        return ! $list || ($ip && IpUtils::checkIp($ip, $list));
    }

    /** Origin/Referer host must match an entry (exact host or *.domain). Missing header fails when a list is set. */
    public function originAllowed(?string $origin, ?string $referer): bool
    {
        $list = array_values(array_filter($this->allowed_origins ?? []));
        if (! $list) return true;
        $host = null;
        foreach ([$origin, $referer] as $u) {
            if ($u && ($h = parse_url($u, PHP_URL_HOST))) { $host = strtolower($h); break; }
        }
        if (! $host) return false;
        foreach ($list as $d) {
            $d = strtolower(trim($d));
            $d = parse_url(str_contains($d, '://') ? $d : 'https://'.$d, PHP_URL_HOST) ?: $d;
            if (str_starts_with($d, '*.')) { if (str_ends_with($host, substr($d, 1))) return true; }
            elseif ($host === $d) return true;
        }
        return false;
    }

    /** Scope helpers: global keys see everything. */
    public function canUsePhone(PhoneNumber $p): bool { return $this->client_id === null || (int) $p->client_id === $this->client_id; }
    public function canUseTemplate(MessageTemplate $t): bool { return $this->client_id === null || (int) $t->client_id === $this->client_id; }
}
