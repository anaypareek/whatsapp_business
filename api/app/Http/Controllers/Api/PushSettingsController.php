<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DeviceToken;
use App\Models\Setting;
use App\Services\FcmService;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Admin → Settings → Push Notifications (Firebase). The service-account key is stored encrypted and never returned. */
class PushSettingsController extends Controller
{
    public function show(FcmService $fcm)
    {
        $sa = $fcm->credentials(); $opt = $fcm->clientOptions(); $adm = $fcm->clientOptions(FcmService::PACKAGE_ADMIN);
        return [
            'package_name' => FcmService::PACKAGE,
            'apps' => collect([FcmService::PACKAGE => 'WA Business Fineoutput (clients)', FcmService::PACKAGE_ADMIN => 'WA Fineoutput Master (staff)'])
                ->map(fn ($label, $pkg) => ['package_name' => $pkg, 'label' => $label, 'configured' => (bool) $fcm->clientOptions($pkg)])->values(),
            'staff_devices' => \App\Models\UserDeviceToken::count(),
            'admin_app_missing' => (bool) $opt && ! $adm,
            'client_config' => $opt ? ['uploaded' => true, 'project_id' => $opt['projectId'], 'app_id' => $opt['appId'], 'sender_id' => $opt['messagingSenderId']] : ['uploaded' => false],
            'service_account' => $sa ? ['uploaded' => true, 'project_id' => $sa['project_id'] ?? null, 'client_email' => $sa['client_email'],
                'source' => config('services.fcm.credentials_path') && is_readable((string) config('services.fcm.credentials_path')) ? 'env' : 'upload'] : ['uploaded' => false],
            'project_mismatch' => $sa && $opt && ($sa['project_id'] ?? null) !== $opt['projectId'],
            'enabled' => (bool) ($sa && $opt),
            'devices' => DeviceToken::count(),
            'clients_with_devices' => DeviceToken::distinct('client_id')->count('client_id'),
        ];
    }

    public function uploadClientConfig(Request $r, FcmService $fcm)
    {
        $r->validate(['file' => 'required|file|max:64']);
        try { $all = FcmService::parseGoogleServicesAll((string) file_get_contents($r->file('file')->getRealPath())); }
        catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422); }
        // a file that only has one of the apps replaces only that app's options
        foreach ($all as $pkg => $opt) Setting::put(FcmService::APPS[$pkg], json_encode($opt));
        return $this->show($fcm);
    }

    public function uploadServiceAccount(Request $r, FcmService $fcm)
    {
        $r->validate(['file' => 'required|file|max:64']);
        try { $sa = FcmService::parseServiceAccount((string) file_get_contents($r->file('file')->getRealPath())); }
        catch (InvalidArgumentException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422); }
        Setting::put('fcm_service_account', json_encode($sa));
        \Illuminate\Support\Facades\Cache::forget('fcm:access_token:'.md5($sa['client_email']));
        return $this->show($fcm);
    }

    public function destroy(Request $r, FcmService $fcm)
    {
        $what = $r->validate(['what' => 'required|in:client_config,service_account'])['what'];
        Setting::whereIn('key', $what === 'client_config' ? array_values(FcmService::APPS) : ['fcm_service_account'])->delete();
        return $this->show($fcm);
    }

    public function test(Request $r, FcmService $fcm)
    {
        $d = $r->validate(['client_id' => 'required_without:staff|nullable|exists:clients,id', 'staff' => 'nullable|boolean']);
        if (! $fcm->enabled()) return response()->json(['message' => 'Upload both google-services.json and the service-account key first.'], 422);
        if (! empty($d['staff'])) {
            $n = \App\Models\UserDeviceToken::count();
            if (! $n) return response()->json(['message' => 'No Master app devices yet. Install WA Fineoutput Master 1.0.2+, sign in and allow notifications'.($fcm->clientOptions(FcmService::PACKAGE_ADMIN) ? '.' : ' (and upload a google-services.json that includes '.FcmService::PACKAGE_ADMIN.').')], 422);
            $res = $fcm->sendToStaff('Test notification', 'Push notifications are working for the Master app.', ['type' => 'test'], 'test');
            return $res + ['devices' => $n, 'message' => "Sent to {$res['sent']} of $n Master app device(s)".($res['removed'] ? ", removed {$res['removed']} expired token(s)" : '').'.'];
        }
        $n = DeviceToken::where('client_id', $d['client_id'])->count();
        if (! $n) return response()->json(['message' => 'This client has no devices yet. Log in to the mobile app as this client first.'], 422);
        $res = $fcm->sendToClient((int) $d['client_id'], 'Test notification', 'Push notifications are working for '.Client::find($d['client_id'])->name.'.', ['type' => 'test'], 'test');
        return $res + ['devices' => $n, 'message' => "Sent to {$res['sent']} of $n device(s)".($res['removed'] ? ", removed {$res['removed']} expired token(s)" : '').'.'];
    }
}
