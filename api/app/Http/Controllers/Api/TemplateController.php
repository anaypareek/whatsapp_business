<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\TemplateEdit;
use App\Services\ErrorLogger;
use App\Services\MetaApiException;
use App\Services\TemplateBuilder;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Throwable;

class TemplateController extends Controller
{
    public function index(Request $r)
    {
        $q = MessageTemplate::with('client:id,name,company')->orderBy('name')->orderBy('language');
        foreach (['status', 'category', 'language'] as $f) if ($r->filled($f)) $q->where($f, $r->input($f));
        if ($r->filled('client_id')) $r->client_id === 'none' ? $q->whereNull('client_id') : $q->where('client_id', $r->client_id);
        if ($r->filled('q')) $q->where('name', 'like', '%'.$r->q.'%');
        return $q->get();
    }

    public function show(MessageTemplate $template) { return $template->load(['events' => fn ($q) => $q->latest()->limit(20)]); }

    public function store(Request $r, WhatsAppService $wa)
    {
        $d = $r->validate(TemplateBuilder::rules());
        if (MessageTemplate::where('name', $d['name'])->where('language', $d['language'])->exists()) {
            return response()->json(['message' => 'A template with this name and language already exists.', 'errors' => ['name' => ['Duplicate name+language']]], 422);
        }
        $components = TemplateBuilder::components($d);
        try {
            $res = $wa->createTemplate(['name' => $d['name'], 'language' => $d['language'], 'category' => $d['category'], 'components' => $components]);
        } catch (Throwable $e) { return $this->fail($e); }
        $t = MessageTemplate::create([
            'meta_id' => $res['id'] ?? null, 'waba_id' => Setting::get('waba_id'), 'name' => $d['name'], 'language' => $d['language'],
            'category' => $res['category'] ?? $d['category'], 'status' => $res['status'] ?? 'PENDING', 'components' => $components,
        ]);
        return response()->json($t, 201);
    }

    public function update(Request $r, MessageTemplate $template, WhatsAppService $wa)
    {
        $check = $template->edit_check;
        if (! $check['allowed']) return response()->json(['message' => $check['reason'], 'edit_check' => $check], 422);
        $d = $r->validate(TemplateBuilder::rules(true));
        if (isset($d['name']) && $d['name'] !== $template->name || isset($d['language']) && $d['language'] !== $template->language) {
            return response()->json(['message' => 'Meta does not allow changing a template\'s name or language. Create a new template instead.'], 422);
        }
        $payload = ['components' => TemplateBuilder::components($d)];
        if (isset($d['category']) && $d['category'] !== $template->category) {
            if ($template->status === 'APPROVED') return response()->json(['message' => 'The category of an APPROVED template cannot be changed.'], 422);
            $payload['category'] = $d['category'];
        }
        try { $wa->editTemplate($template->meta_id, $payload); }
        catch (Throwable $e) { return $this->fail($e); }
        TemplateEdit::create(['message_template_id' => $template->id, 'status_at_edit' => $template->status]);
        $template->update(['components' => $payload['components'], 'category' => $payload['category'] ?? $template->category,
            'status' => 'PENDING', 'rejected_reason' => null]); // edited templates go back to review
        return $template->fresh();
    }

    /**
     * Delete on Meta (DELETE /{waba}/message_templates?name=..&hsm_id=..), then locally.
     * - template never created on Meta (no meta_id) or ?local_only=1: local delete only
     * - Meta says it doesn't exist any more: removed locally, 200 {deleted: "local"}
     * - any other Meta refusal: 502 {message (Meta's own reason), error_code, log_id, meta, can_remove_locally}
     */
    public function destroy(Request $r, MessageTemplate $template, WhatsAppService $wa)
    {
        if (! $template->meta_id || $r->boolean('local_only')) {
            $template->delete();
            return response()->json(['deleted' => 'local', 'message' => $template->meta_id ? 'Removed from this portal only. It still exists on Meta and will return on the next Sync from Meta.' : 'Template removed (it was never created on Meta).']);
        }
        try {
            $wa->deleteTemplate($template->name, $template->meta_id, $template->waba_id ?: null);
        } catch (MetaApiException $e) {
            if ($e->isNotFound()) {
                $template->delete();
                return response()->json(['deleted' => 'local', 'message' => 'This template no longer exists on Meta, so it was removed from the portal.', 'log_id' => ErrorLogger::alreadyLogged($e)]);
            }
            $hint = str_starts_with($template->name, 'sample_') ? " “{$template->name}” is one of Meta's built-in sample templates, which usually can't be deleted." : '';
            return $this->fail($e, 502, ['can_remove_locally' => true, 'hint' => trim($hint) ?: null]);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
        $template->delete();
        return response()->noContent();
    }

    /** Assign a template to a client (local only; Meta has no such concept). */
    public function assignClient(Request $r, MessageTemplate $template)
    {
        $d = $r->validate(['client_id' => 'nullable|exists:clients,id']);
        $template->update(['client_id' => $d['client_id'] ?? null]);
        return $template->load('client:id,name,company');
    }

    public function sync(WhatsAppService $wa)
    {
        try { $remote = $wa->listTemplates(); } catch (Throwable $e) { return $this->fail($e); }
        $waba = Setting::get('waba_id');
        $ids = collect($remote)->map(fn ($t) => MessageTemplate::fromMeta($t, $waba)->id);
        // templates deleted on Meta's side
        $removed = MessageTemplate::whereNotNull('meta_id')->whereNotIn('id', $ids)->delete();
        return ['synced' => $ids->count(), 'removed' => $removed];
    }
}
