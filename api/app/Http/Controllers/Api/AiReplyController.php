<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiContactState;
use App\Models\AiReplySetting;
use App\Models\Client;
use App\Models\PhoneNumber;
use App\Services\AiReplyService;
use Illuminate\Http\Request;

/** Per-number AI reply settings. Admin only. Provider key lives in admin Settings only. */
class AiReplyController extends Controller
{
    protected function numbers(Request $r)
    {
        $u = $r->user();
        abort_if($u instanceof Client, 404); // admin-only feature
        return PhoneNumber::query();
    }

    public function index(Request $r)
    {
        $nums = $this->numbers($r)->orderBy('id')->get(['id', 'display_number', 'name']);
        $saved = AiReplySetting::whereIn('phone_number_id', $nums->pluck('id'))->get()->keyBy('phone_number_id');
        $handoffs = AiContactState::whereIn('phone_number_id', $nums->pluck('id'))->where('needs_human', true)->orderByDesc('handoff_at')
            ->get(['phone_number_id', 'contact_wa_id', 'handoff_at', 'reason'])->groupBy('phone_number_id');
        $cfg = AiReplyService::config();
        return ['provider' => ['configured' => (bool) $cfg['key'], 'model' => $cfg['model']],
            'defaults' => [
                'handoff_text' => AiReplyService::HANDOFF_TEXT,
                'handoff_text_hi' => AiReplyService::HANDOFF_TEXT_HI,
                'human_active_quiet_minutes' => 30,
            ],
            'numbers' => $nums->map(fn ($n) => ['phone_number' => $n, 'settings' => $saved[$n->id] ?? new AiReplySetting(['phone_number_id' => $n->id]), 'handoffs' => $handoffs[$n->id] ?? []])];
    }

    public function update(Request $r, int $phoneNumber)
    {
        $n = $this->numbers($r)->findOrFail($phoneNumber);
        $d = $r->validate([
            'enabled' => 'boolean', 'test_mode' => 'boolean', 'orders_lookup' => 'boolean',
            'allowlist' => 'nullable|array|max:200', 'allowlist.*' => 'string|max:32',
            'knowledge_base' => 'nullable|string|max:20000',
            'max_per_hour' => 'integer|min:1|max:60',
            'handoff_cooldown_hours' => 'integer|min:1|max:720',
            'handoff_text' => 'nullable|string|max:500',
            'handoff_text_hi' => 'nullable|string|max:500',
            'human_active_quiet_minutes' => 'integer|min:0|max:1440',
        ]);
        if (array_key_exists('allowlist', $d)) $d['allowlist'] = collect($d['allowlist'] ?? [])->map(fn ($x) => preg_replace('/\D/', '', $x))->filter(fn ($x) => strlen($x) >= 6)->unique()->values()->all();
        foreach (['handoff_text', 'handoff_text_hi'] as $k) {
            if (array_key_exists($k, $d)) $d[$k] = ($d[$k] === null || trim((string) $d[$k]) === '') ? null : trim((string) $d[$k]);
        }
        return AiReplySetting::updateOrCreate(['phone_number_id' => $n->id], $d);
    }

    /** Clear the "needs human" flag for a conversation (AI may reply again). */
    public function resolve(Request $r, int $phoneNumber)
    {
        $n = $this->numbers($r)->findOrFail($phoneNumber);
        $c = $r->validate(['contact' => 'required|string|max:32'])['contact'];
        AiContactState::where('phone_number_id', $n->id)->where('contact_wa_id', $c)->update(['needs_human' => false]);
        return ['ok' => true];
    }
}
