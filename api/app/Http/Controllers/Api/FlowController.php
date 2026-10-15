<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Flow;
use App\Models\FlowSession;
use App\Models\PhoneNumber;
use App\Services\FlowRunner;
use App\Services\FlowValidator;
use Illuminate\Http\Request;

/**
 * Automation flows. Shared by the admin panel (/api/flows: every number) and the client portal
 * (/api/client/flows: only the client's own numbers).
 */
class FlowController extends Controller
{
    protected function client(Request $r): ?Client { return $r->user() instanceof Client ? $r->user() : null; }

    protected function numbers(Request $r)
    {
        $c = $this->client($r);
        return $c ? $c->phoneNumbers() : PhoneNumber::query();
    }

    protected function scoped(Request $r)
    {
        $q = Flow::query();
        if ($c = $this->client($r)) $q->whereIn('phone_number_id', $c->phoneNumbers()->pluck('id'));
        return $q;
    }

    protected function find(Request $r, $id): Flow
    {
        $f = $this->scoped($r)->find($id);
        abort_unless($f, 404, 'Flow not found');
        return $f;
    }

    protected function withStats($q)
    {
        return $q->with('phoneNumber:id,display_number,name,client_id', 'client:id,name,company')
            ->withCount(['sessions as active_sessions' => fn ($s) => $s->live()]);
    }

    public function index(Request $r)
    {
        $q = $this->withStats($this->scoped($r))->orderByDesc('id');
        if ($r->filled('phone_number_id')) $q->where('phone_number_id', $r->phone_number_id);
        return $q->get()->each(fn ($f) => $f->setAttribute('steps', collect($f->nodes)->where('type', '!=', 'trigger')->count()))->makeHidden(['nodes', 'edges']);
    }

    public function numberOptions(Request $r)
    {
        return $this->numbers($r)->with('client:id,name,company')->withCount('flows')->orderBy('id')->get(['phone_numbers.id', 'phone_numbers.display_number', 'phone_numbers.name', 'phone_numbers.client_id', 'phone_numbers.status']);
    }

    public function show(Request $r, $flow) { return $this->withStats($this->scoped($r))->findOrFail($this->find($r, $flow)->id); }

    public function store(Request $r)
    {
        $d = $r->validate(FlowValidator::rules() + ['phone_number_id' => 'required|integer']);
        $number = $this->numbers($r)->find($d['phone_number_id']);
        abort_unless($number, 404, 'Number not found');
        [$nodes, $edges] = FlowValidator::graph($d['nodes'], $d['edges'] ?? []);
        $this->checkActivation($d['is_active'] ?? false, $d['keywords'], $edges);
        $f = Flow::create(['phone_number_id' => $number->id, 'client_id' => $number->client_id, 'name' => $d['name'], 'is_active' => $d['is_active'] ?? false,
            'trigger_type' => $d['trigger_type'], 'keywords' => $this->kw($d['keywords']), 'nodes' => $nodes, 'edges' => $edges]);
        return response()->json($this->show($r, $f->id), 201);
    }

    public function update(Request $r, $flow)
    {
        $f = $this->find($r, $flow);
        $d = $r->validate(FlowValidator::rules(true));
        if (isset($d['nodes'])) [$d['nodes'], $d['edges']] = FlowValidator::graph($d['nodes'], $d['edges'] ?? []);
        if (isset($d['keywords'])) $d['keywords'] = $this->kw($d['keywords']);
        $this->checkActivation($d['is_active'] ?? $f->is_active, $d['keywords'] ?? $f->keywords, $d['edges'] ?? $f->edges);
        $f->update($d);
        return $this->show($r, $f->id);
    }

    public function destroy(Request $r, $flow) { $this->find($r, $flow)->delete(); return response()->noContent(); }

    public function duplicate(Request $r, $flow)
    {
        $f = $this->find($r, $flow);
        $copy = $f->replicate(['triggered_count', 'completed_count']);
        $copy->fill(['name' => mb_substr($f->name.' (copy)', 0, 120), 'is_active' => false, 'triggered_count' => 0, 'completed_count' => 0])->save();
        return response()->json($this->show($r, $copy->id), 201);
    }

    /** Sessions currently waiting on each flow, plus totals. */
    public function stats(Request $r, $flow)
    {
        $f = $this->find($r, $flow);
        return ['triggered' => $f->triggered_count, 'completed' => $f->completed_count,
            'active_sessions' => $f->sessions()->live()->count(),
            'expired' => $f->sessions()->where('status', 'expired')->count(),
            'recent_sessions' => $f->sessions()->latest('id')->limit(20)->get(['id', 'contact_wa_id', 'status', 'current_node_id', 'updated_at', 'expires_at'])];
    }

    /**
     * Test-flow simulator: runs an (unsaved) draft without sending anything.
     * Body: {flow: {id?, trigger_type, keywords, nodes, edges}, waiting: ?nodeId, text, interactive_id?}
     */
    public function simulate(Request $r)
    {
        $d = $r->validate(['flow' => 'required|array', 'flow.trigger_type' => 'required|in:'.implode(',', Flow::TRIGGER_TYPES), 'flow.keywords' => 'array',
            'flow.nodes' => 'required|array', 'flow.edges' => 'array', 'waiting' => 'nullable|string|max:64', 'text' => 'nullable|string|max:4096', 'interactive_id' => 'nullable|string|max:256']);
        [$nodes, $edges] = FlowValidator::graph($d['flow']['nodes'], $d['flow']['edges'] ?? []);
        $def = ['id' => (int) ($r->input('flow.id') ?? 0), 'trigger_type' => $d['flow']['trigger_type'], 'keywords' => $d['flow']['keywords'] ?? [], 'nodes' => $nodes, 'edges' => $edges];
        $text = $d['text'] ?? null; $iid = $d['interactive_id'] ?? null;
        if (! empty($d['waiting'])) {
            $res = FlowRunner::reply($def, $d['waiting'], $text, $iid);
            if ($res['matched']) return ['event' => 'reply'] + $res;
            if (FlowRunner::triggerMatches($def, $text)) return ['event' => 'restart', 'matched' => true] + FlowRunner::start($def, $text);
            return ['event' => 'reask', 'matched' => false] + FlowRunner::reask($def, $d['waiting']);
        }
        if (! FlowRunner::triggerMatches($def, $text)) return ['event' => 'no_trigger', 'outputs' => [], 'waiting' => null, 'completed' => false];
        return ['event' => 'start'] + FlowRunner::start($def, $text);
    }

    protected function kw(array $k): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), $k), fn ($x) => $x !== '')));
    }

    protected function checkActivation(bool $active, array $keywords, array $edges): void
    {
        if (! $active) return;
        if (! $this->kw($keywords)) abort(response()->json(['message' => 'Add at least one trigger keyword before activating.', 'errors' => ['keywords' => ['Required to activate']]], 422));
        if (! collect($edges)->contains(fn ($e) => $e['source'] === 'trigger')) abort(response()->json(['message' => 'Connect the trigger to a first step before activating.', 'errors' => ['edges' => ['Trigger not connected']]], 422));
    }
}
