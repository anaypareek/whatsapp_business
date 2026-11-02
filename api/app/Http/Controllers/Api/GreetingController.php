<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\GreetingGroup;
use App\Models\GreetingSetting;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Services\GreetingTemplates;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;

/** Greeting / off-hours auto-reply settings and contact groups. Admin: all numbers; client portal: only its own numbers. Disabled by default. */
class GreetingController extends Controller
{
    protected function numbers(Request $r)
    {
        $u = $r->user();
        return $u instanceof Client ? PhoneNumber::where('client_id', $u->id) : PhoneNumber::query();
    }

    protected function own(Request $r, int $id): PhoneNumber { return $this->numbers($r)->findOrFail($id); }

    protected function ownGroup(Request $r, int $id): GreetingGroup
    {
        $g = GreetingGroup::findOrFail($id);
        $this->own($r, $g->phone_number_id);
        return $g;
    }

    public function index(Request $r)
    {
        $nums = $this->numbers($r)->orderBy('id')->get(['id', 'display_number', 'name']);
        $saved = GreetingSetting::whereIn('phone_number_id', $nums->pluck('id'))->get()->keyBy('phone_number_id');
        $groups = GreetingGroup::with('members:id,greeting_group_id,contact_wa_id')->whereIn('phone_number_id', $nums->pluck('id'))
            ->orderByDesc('priority')->orderBy('id')->get()->groupBy('phone_number_id');
        return $nums->map(fn ($n) => [
            'phone_number' => $n, 'settings' => $saved[$n->id] ?? new GreetingSetting(['phone_number_id' => $n->id]),
            'groups' => $groups[$n->id] ?? [],
        ]);
    }

    protected const TPL_RULES = ['greeting_template_id' => 'nullable|integer', 'greeting_template_params' => 'nullable|array',
        'off_hours_template_id' => 'nullable|integer', 'off_hours_template_params' => 'nullable|array'];

    /** Check template slots (approved, same WABA, assigned to the client, variables mapped) and normalise params. */
    protected function checkSlots(Request $r, PhoneNumber $n, array &$d, $current = null): void
    {
        $client = $r->user() instanceof Client ? $r->user() : null;
        foreach (GreetingTemplates::SLOTS as $slot) {
            if (! array_key_exists("{$slot}_template_id", $d)) continue;
            $tid = $d["{$slot}_template_id"] ?: null;
            $d["{$slot}_template_id"] = $tid;
            if (! $tid) { $d["{$slot}_template_params"] = null; continue; }
            if ($current && (int) $current->{"{$slot}_template_id"} === (int) $tid && ! array_key_exists("{$slot}_template_params", $d)) $d["{$slot}_template_params"] = $current->{"{$slot}_template_params"};
            if ($e = GreetingTemplates::validate($n, $client, $slot, $tid, $d["{$slot}_template_params"] ?? null)) throw ValidationException::withMessages($e);
        }
    }

    /** APPROVED templates usable by this number (same WABA; client: only its assigned templates), with mapping info. */
    public function templates(Request $r, int $phoneNumber)
    {
        $n = $this->own($r, $phoneNumber);
        return GreetingTemplates::usable($n, $r->user() instanceof Client ? $r->user() : null)->get()->map(fn ($t) => GreetingTemplates::describe($t));
    }

    public function update(Request $r, int $phoneNumber)
    {
        $n = $this->own($r, $phoneNumber);
        $d = $r->validate([
            'enabled' => 'boolean', 'mode' => 'in:every,new_only', 'greeting_text' => 'nullable|string|max:4096',
            'image_url' => 'nullable|url|max:1024', 'cooldown_hours' => 'integer|min:1|max:8760',
            'hours_enabled' => 'boolean', 'hours_start' => 'nullable|date_format:H:i', 'hours_end' => 'nullable|date_format:H:i',
            'hours_days' => 'nullable|array', 'hours_days.*' => 'integer|between:1,7', 'off_hours_text' => 'nullable|string|max:4096',
        ] + self::TPL_RULES);
        $cur = GreetingSetting::where('phone_number_id', $n->id)->first();
        $this->checkSlots($r, $n, $d, $cur);
        $hasText = ! blank(array_key_exists('greeting_text', $d) ? $d['greeting_text'] : $cur?->greeting_text);
        $hasTpl = array_key_exists('greeting_template_id', $d) ? (bool) $d['greeting_template_id'] : (bool) $cur?->greeting_template_id;
        if (($d['enabled'] ?? false) && ! $hasText && ! $hasTpl) {
            return response()->json(['message' => 'Enter a greeting text or choose a template before enabling.', 'errors' => ['greeting_text' => ['Required when enabled']]], 422);
        }
        if (($d['hours_enabled'] ?? false) && (empty($d['hours_start']) || empty($d['hours_end']))) {
            return response()->json(['message' => 'Set start and end time for business hours.', 'errors' => ['hours_start' => ['Required']]], 422);
        }
        return GreetingSetting::updateOrCreate(['phone_number_id' => $n->id], $d);
    }

    protected function groupRules(bool $partial): array
    {
        return ['name' => ($partial ? 'sometimes' : 'required').'|string|max:100', 'greeting_text' => 'nullable|string|max:4096',
            'off_hours_text' => 'nullable|string|max:4096', 'priority' => 'integer|min:0|max:1000',
            'members' => 'sometimes|array|max:5000', 'members.*' => 'string|max:32'] + self::TPL_RULES;
    }

    protected function syncMembers(GreetingGroup $g, ?array $members): void
    {
        if ($members === null) return;
        $nums = collect($members)->map(fn ($m) => preg_replace('/\D/', '', $m))->filter(fn ($m) => strlen($m) >= 6)->unique()->values();
        $g->members()->whereNotIn('contact_wa_id', $nums)->delete();
        foreach ($nums as $m) $g->members()->firstOrCreate(['contact_wa_id' => $m]);
    }

    public function storeGroup(Request $r, int $phoneNumber)
    {
        $n = $this->own($r, $phoneNumber);
        $d = $r->validate($this->groupRules(false));
        $this->checkSlots($r, $n, $d);
        $g = GreetingGroup::create(['phone_number_id' => $n->id] + collect($d)->except('members')->all());
        $this->syncMembers($g, $d['members'] ?? []);
        return response()->json($g->load('members:id,greeting_group_id,contact_wa_id'), 201);
    }

    public function updateGroup(Request $r, int $group)
    {
        $g = $this->ownGroup($r, $group);
        $d = $r->validate($this->groupRules(true));
        $this->checkSlots($r, PhoneNumber::find($g->phone_number_id), $d, $g);
        $g->update(collect($d)->except('members')->all());
        $this->syncMembers($g, $d['members'] ?? null);
        return $g->load('members:id,greeting_group_id,contact_wa_id');
    }

    public function destroyGroup(Request $r, int $group)
    {
        $this->ownGroup($r, $group)->delete();
        return response()->noContent();
    }

    /** Existing contacts of a number, to pick group members from. */
    public function contacts(Request $r, int $phoneNumber)
    {
        $n = $this->own($r, $phoneNumber);
        return Message::where('phone_number_id', $n->id)->selectRaw('contact_wa_id, MAX(contact_name) as contact_name')
            ->groupBy('contact_wa_id')->orderBy('contact_wa_id')->limit(1000)->get();
    }
}
