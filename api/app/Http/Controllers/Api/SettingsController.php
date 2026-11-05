<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        \App\Services\AiUsageService::seedDefaults();
        $out = [];
        foreach (Setting::KEYS as $k) {
            $v = Setting::get($k);
            $out[$k] = in_array($k, Setting::SECRET) ? ($v ? '••••'.substr($v, -4) : '') : ($v ?? '');
            if (in_array($k, Setting::SECRET)) $out[$k.'_set'] = (bool) $v;
        }
        $out['webhook_url'] = rtrim(config('app.url'), '/').'/api/webhook/whatsapp';
        $out['ai_defaults'] = [
            'base_url' => \App\Services\AiReplyService::DEFAULT_BASE_URL,
            'model' => \App\Services\AiReplyService::DEFAULT_MODEL,
            'global_max_per_hour' => \App\Services\AiReplyService::DEFAULT_GLOBAL_PER_HOUR,
            'usd_inr_rate' => \App\Services\AiUsageService::DEFAULT_USD_INR,
            'model_prices' => \App\Services\AiUsageService::DEFAULT_PRICES,
        ];
        $out['ai_usd_inr_rate'] = $out['ai_usd_inr_rate'] !== '' ? $out['ai_usd_inr_rate'] : (string) \App\Services\AiUsageService::DEFAULT_USD_INR;
        if ($out['ai_model_prices'] === '') $out['ai_model_prices'] = json_encode(\App\Services\AiUsageService::DEFAULT_PRICES);
        $out['ai_model_prices_parsed'] = \App\Services\AiUsageService::prices();
        $out['graph_version'] = config('services.whatsapp.graph_version');
        return $out;
    }
    /** GET /waba-health (cached); POST /waba-health/refresh (Settings → Test connection). */
    public function health() { return response()->json(\App\Services\WabaHealth::get() ?? ['can_send_message' => null, 'entities' => [], 'blocked' => false, 'checked_at' => null]); }
    public function refreshHealth() { return \App\Services\WabaHealth::refresh(); }

    public function update(Request $r)
    {
        $d = $r->validate(collect(Setting::KEYS)->mapWithKeys(fn ($k) => [$k => 'nullable|string|max:'.($k === 'ai_model_prices' ? '8000' : '2000')])->all());
        foreach ($d as $k => $v) {
            // secrets: blank or masked value means "keep existing"
            if (in_array($k, Setting::SECRET) && ($v === null || $v === '' || str_starts_with($v, '••••'))) continue;
            Setting::put($k, $v);
        }
        if (array_intersect(array_keys($d), ['app_id', 'app_secret', 'access_token', 'waba_id']) && Setting::get('waba_id') && Setting::get('access_token')) \App\Services\WabaHealth::refreshQuietly();
        return $this->show();
    }
}
