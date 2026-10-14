<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        $out = [];
        foreach (Setting::KEYS as $k) {
            $v = Setting::get($k);
            $out[$k] = in_array($k, Setting::SECRET) ? ($v ? '••••'.substr($v, -4) : '') : ($v ?? '');
            if (in_array($k, Setting::SECRET)) $out[$k.'_set'] = (bool) $v;
        }
        $out['webhook_url'] = rtrim(config('app.url'), '/').'/api/webhook/whatsapp';
        $out['graph_version'] = config('services.whatsapp.graph_version');
        return $out;
    }
    public function update(Request $r)
    {
        $d = $r->validate(collect(Setting::KEYS)->mapWithKeys(fn ($k) => [$k => 'nullable|string|max:2000'])->all());
        foreach ($d as $k => $v) {
            // secrets: blank or masked value means "keep existing"
            if (in_array($k, Setting::SECRET) && ($v === null || $v === '' || str_starts_with($v, '••••'))) continue;
            Setting::put($k, $v);
        }
        return $this->show();
    }
}
