<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\AutoReplyRule;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PhoneNumber;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = now()->startOfDay();
        $out = fn () => Message::where('direction', 'out');
        $in = fn () => Message::where('direction', 'in');

        $from = now()->subDays(6)->startOfDay();
        $rows = Message::where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) d, direction, COUNT(*) c')->groupBy('d', 'direction')->get();
        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $series[] = [
                'date' => $d, 'label' => now()->subDays($i)->format('D'),
                'sent' => (int) ($rows->first(fn ($r) => $r->d == $d && $r->direction === 'out')->c ?? 0),
                'received' => (int) ($rows->first(fn ($r) => $r->d == $d && $r->direction === 'in')->c ?? 0),
            ];
        }

        $tpl = MessageTemplate::select('status', DB::raw('COUNT(*) c'))->groupBy('status')->pluck('c', 'status');
        $conversations = Message::select('phone_number_id', 'contact_wa_id')->distinct()->get()->count();

        $recentIds = Message::select(DB::raw('MAX(id) id'))->groupBy('phone_number_id', 'contact_wa_id')->orderByDesc('id')->limit(5)->pluck('id');
        $recent = Message::with('phoneNumber:id,name,display_number')->whereIn('id', $recentIds)->orderByDesc('id')->get()
            ->map(function ($m) {
                $name = Message::where('contact_wa_id', $m->contact_wa_id)->whereNotNull('contact_name')->latest('id')->value('contact_name');
                $unread = Message::where('phone_number_id', $m->phone_number_id)->where('contact_wa_id', $m->contact_wa_id)
                    ->where('direction', 'in')->whereNull('read_at')->count();
                return ['phone_number_id' => $m->phone_number_id, 'contact_wa_id' => $m->contact_wa_id, 'contact_name' => $name,
                    'number' => $m->phoneNumber?->name ?? $m->phoneNumber?->display_number, 'unread' => $unread, 'last_message' => $m];
            });

        return [
            'messages' => [
                'sent_today' => $out()->where('created_at', '>=', $today)->count(),
                'received_today' => $in()->where('created_at', '>=', $today)->count(),
                'failed_today' => $out()->where('status', 'failed')->where('created_at', '>=', $today)->count(),
                'total_sent' => $out()->count(),
                'total_received' => $in()->count(),
            ],
            'phone_numbers' => PhoneNumber::count(),
            'templates' => [
                'total' => (int) $tpl->sum(),
                'approved' => (int) ($tpl['APPROVED'] ?? 0), 'pending' => (int) ($tpl['PENDING'] ?? 0),
                'rejected' => (int) ($tpl['REJECTED'] ?? 0),
                'other' => (int) $tpl->except(['APPROVED', 'PENDING', 'REJECTED'])->sum(),
            ],
            'auto_replies_active' => AutoReplyRule::where('enabled', true)->count(),
            'conversations' => $conversations,
            'contacts' => Message::distinct('contact_wa_id')->count('contact_wa_id'),
            'api_keys' => ApiKey::count(),
            'series' => $series,
            'recent_conversations' => $recent,
        ];
    }
}
