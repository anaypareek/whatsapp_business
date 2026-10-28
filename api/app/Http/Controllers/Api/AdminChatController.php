<?php
namespace App\Http\Controllers\Api;

use App\Models\PhoneNumber;
use App\Models\UserDeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Chat API for the WA Fineoutput Master app: same protocol as the client app (sync cursor, conversations, thread, read,
 * media), but across ALL numbers. Staff only. Replies go through the existing POST /api/messages/send.
 */
class AdminChatController extends ClientChatController
{
    protected function numberIds(Request $r): array
    {
        return Cache::remember('chat:pns:admin', 60, fn () => PhoneNumber::orderBy('id')->pluck('id')->all());
    }

    protected function scopeKey(Request $r): string { return 'admin'; }

    protected function numberList(Request $r)
    {
        return PhoneNumber::with('client:id,name,company')->orderBy('id')->get(['id', 'display_number', 'name', 'status', 'quality_rating', 'client_id']);
    }

    public function registerDevice(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|min:20|max:512', 'platform' => 'nullable|in:android,ios', 'app_version' => 'nullable|string|max:32']);
        $dev = UserDeviceToken::updateOrCreate(['token' => $d['token']], ['user_id' => $r->user()->id, 'platform' => $d['platform'] ?? 'android',
            'app_version' => $d['app_version'] ?? null, 'last_seen_at' => now()]);
        return response()->json(['ok' => true, 'id' => $dev->id], 201);
    }

    public function removeDevice(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|max:512']);
        return ['removed' => UserDeviceToken::where('user_id', $r->user()->id)->where('token', $d['token'])->delete()];
    }
}
