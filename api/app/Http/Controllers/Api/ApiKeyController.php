<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\Client;
use Illuminate\Http\Request;

/** API keys: admins manage any client's (or global) keys at /api/api-keys; clients manage their own at /api/client/api-keys. */
class ApiKeyController extends Controller
{
    private function clientId(Request $r): ?int { return $r->user() instanceof Client ? $r->user()->id : null; }
    private function isClient(Request $r): bool { return $r->user() instanceof Client; }

    private function own(Request $r, ApiKey $k): ApiKey
    {
        abort_if($this->isClient($r) && $k->client_id !== $r->user()->id, 404);
        return $k;
    }

    public function index(Request $r)
    {
        $q = ApiKey::with('client:id,name,company')->orderByRaw('revoked_at is not null')->orderByDesc('id');
        if ($this->isClient($r)) $q->where('client_id', $r->user()->id);
        elseif ($r->filled('client_id')) $r->input('client_id') === 'global' ? $q->whereNull('client_id') : $q->where('client_id', (int) $r->input('client_id'));
        return $q->get();
    }

    private function rules(Request $r, bool $create): array
    {
        $rules = [
            'name' => ($create ? 'required' : 'sometimes').'|string|max:100',
            'allowed_ips' => 'nullable|array|max:50', 'allowed_ips.*' => 'string|max:64',
            'allowed_origins' => 'nullable|array|max:50', 'allowed_origins.*' => 'string|max:191',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:'.($this->isClient($r) ? 300 : 6000),
        ];
        if (! $this->isClient($r) && $create) $rules['client_id'] = 'nullable|integer|exists:clients,id';
        return $rules;
    }

    private function clean(array $d): array
    {
        foreach (['allowed_ips', 'allowed_origins'] as $f) {
            if (array_key_exists($f, $d)) $d[$f] = array_values(array_unique(array_filter(array_map('trim', $d[$f] ?? [])))) ?: null;
        }
        foreach ($d['allowed_ips'] ?? [] as $ip) {
            [$addr, $bits] = array_pad(explode('/', $ip, 2), 2, null);
            if (! filter_var($addr, FILTER_VALIDATE_IP) || ($bits !== null && (! ctype_digit($bits) || (int) $bits > (str_contains($addr, ':') ? 128 : 32)))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['allowed_ips' => "'{$ip}' is not a valid IP address or CIDR range."]);
            }
        }
        foreach ($d['allowed_origins'] ?? [] as $o) {
            $host = parse_url(str_contains($o, '://') ? $o : 'https://'.$o, PHP_URL_HOST);
            if (! $host || ! preg_match('/^(\*\.)?[a-z0-9.-]+$/i', $host)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['allowed_origins' => "'{$o}' is not a valid domain."]);
            }
        }
        if (array_key_exists('rate_limit_per_minute', $d) && ! $d['rate_limit_per_minute']) $d['rate_limit_per_minute'] = (int) config('services.api.rate_limit', 60);
        return $d;
    }

    public function store(Request $r)
    {
        $d = $this->clean($r->validate($this->rules($r, true)));
        $attrs = array_intersect_key($d, array_flip(['allowed_ips', 'allowed_origins', 'rate_limit_per_minute']));
        $attrs['client_id'] = $this->isClient($r) ? $r->user()->id : ($d['client_id'] ?? null);
        [$key, $plain] = ApiKey::generate($d['name'], $this->isClient($r) ? null : $r->user()->id, $attrs);
        return response()->json(['api_key' => $key->fresh()->load('client:id,name,company'), 'plain_key' => $plain,
            'note' => 'Copy now; it will not be shown again. Keep it on your server only.'], 201);
    }

    public function update(Request $r, ApiKey $apiKey)
    {
        $this->own($r, $apiKey);
        $apiKey->update($this->clean($r->validate($this->rules($r, false))));
        return $apiKey->fresh()->load('client:id,name,company');
    }

    /** Revoke (kept for the logs). */
    public function destroy(Request $r, ApiKey $apiKey)
    {
        $this->own($r, $apiKey)->revoke();
        return response()->noContent();
    }

    public function logs(Request $r)
    {
        $q = ApiRequestLog::with('apiKey:id,name,prefix', 'client:id,name')->orderByDesc('id');
        if ($this->isClient($r)) $q->where('client_id', $r->user()->id);
        elseif ($r->filled('client_id')) $q->where('client_id', (int) $r->input('client_id'));
        if ($r->filled('api_key_id')) $q->where('api_key_id', (int) $r->input('api_key_id'));
        if ($r->input('status') === 'ok') $q->where('status', '<', 400);
        elseif ($r->input('status') === 'error') $q->where('status', '>=', 400);
        return $q->paginate(min(100, max(5, (int) $r->input('per_page', 25))));
    }
}
