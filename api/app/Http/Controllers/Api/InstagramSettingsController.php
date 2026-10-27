<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\InstagramGraph;
use Illuminate\Http\Request;

/** Admin → Settings → Instagram. The secret is stored encrypted by Setting and never returned. Empty app id/secret fall back to the WhatsApp Meta app. */
class InstagramSettingsController extends Controller
{
    public function show(): array
    {
        $own = fn ($k) => filled(Setting::get($k));
        return [
            'app_id' => Setting::get('ig_app_id') ?: '', 'app_secret_set' => $own('ig_app_secret'), 'config_id' => Setting::get('ig_config_id') ?: '',
            'redirect_uri_custom' => Setting::get('ig_redirect_uri') ?: '', 'redirect_uri' => InstagramGraph::redirectUri(),
            'default_redirect_uri' => rtrim(config('app.url'), '/').'/api/instagram/callback',
            'effective_app_id' => InstagramGraph::appId(), 'app_id_source' => $own('ig_app_id') ? 'instagram' : (filled(Setting::get('app_id')) ? 'meta' : null),
            'secret_source' => $own('ig_app_secret') ? 'instagram' : (filled(Setting::get('app_secret')) ? 'meta' : null),
            'configured' => InstagramGraph::configured(), 'scopes' => InstagramGraph::SCOPES, 'graph_version' => config('services.instagram.graph_version'),
            'media_base_url' => rtrim(config('services.instagram.media_base_url') ?: config('app.url'), '/'),
        ];
    }

    public function update(Request $r)
    {
        $d = $r->validate(['app_id' => 'nullable|string|max:64', 'app_secret' => 'nullable|string|max:128', 'config_id' => 'nullable|string|max:64', 'redirect_uri' => 'nullable|url|max:300']);
        foreach (['app_id' => 'ig_app_id', 'config_id' => 'ig_config_id', 'redirect_uri' => 'ig_redirect_uri'] as $in => $key) {
            if (array_key_exists($in, $d)) $d[$in] === null || $d[$in] === '' ? Setting::where('key', $key)->delete() : Setting::put($key, trim($d[$in]));
        }
        if (! empty($d['app_secret'])) Setting::put('ig_app_secret', trim($d['app_secret']));
        return $this->show();
    }
}
