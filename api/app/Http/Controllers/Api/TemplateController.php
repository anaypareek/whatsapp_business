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
        $d = $r->validate(TemplateBuilder::rules() + ['client_id' => 'nullable|exists:clients,id']);
        if (MessageTemplate::where('name', $d['name'])->where('language', $d['language'])->exists()) {
            return response()->json(['message' => 'A template with this name and language already exists.', 'errors' => ['name' => ['Duplicate name+language']]], 422);
        }
        $components = TemplateBuilder::components($d);
        try {
            $res = $wa->createTemplate(['name' => $d['name'], 'language' => $d['language'], 'category' => $d['category'], 'components' => $components]);
        } catch (Throwable $e) { return $this->fail($e); }
        $t = MessageTemplate::create([
            'client_id' => $d['client_id'] ?? null, 'meta_id' => $res['id'] ?? null, 'waba_id' => Setting::get('waba_id'), 'name' => $d['name'], 'language' => $d['language'],
            'category' => $res['category'] ?? $d['category'], 'status' => $res['status'] ?? 'PENDING', 'components' => $components,
        ]);
        return response()->json($t->load('client:id,name,company'), 201);
    }

    public function update(Request $r, MessageTemplate $template, WhatsAppService $wa)
    {
        $check = $template->edit_check;
        if (! $check['allowed']) return response()->json(['message' => $check['reason'], 'edit_check' => $check], 422);
        $d = $r->validate(TemplateBuilder::rules(true) + ['client_id' => 'nullable|exists:clients,id']);
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
            'status' => 'PENDING', 'rejected_reason' => null] // edited templates go back to review
            + ($r->has('client_id') ? ['client_id' => $d['client_id'] ?? null] : []));
        return $template->fresh()->load('client:id,name,company');
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

    /** POST /templates-assign {ids:[…], client_id|null}: bulk (un)assign existing templates. */
    public function bulkAssign(Request $r)
    {
        $d = $r->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer', 'client_id' => 'nullable|exists:clients,id']);
        $n = MessageTemplate::whereIn('id', $d['ids'])->update(['client_id' => $d['client_id'] ?? null]);
        return ['updated' => $n, 'templates' => MessageTemplate::with('client:id,name,company')->whereIn('id', $d['ids'])->get()];
    }

    public function sync(WhatsAppService $wa)
    {
        try { $remote = $wa->listTemplates(); } catch (Throwable $e) { return $this->fail($e); }
        \App\Services\WabaHealth::refreshQuietly();
        $waba = Setting::get('waba_id');
        [$samples, $own] = collect($remote)->partition(fn ($t) => MessageTemplate::isSample($t['name'] ?? ''));
        $ids = $own->map(fn ($t) => MessageTemplate::fromMeta($t, $waba)->id)->values();
        $purged = MessageTemplate::purgeSamples();
        // templates deleted on Meta's side
        $removed = MessageTemplate::whereNotNull('meta_id')->whereNotIn('id', $ids)->delete();
        return ['synced' => $ids->count(), 'removed' => $removed + $purged['deleted'], 'skipped_samples' => $samples->count()];
    }

    // ---- "Test" button: send the template with demo values ----

    /** GET /templates/{t}/test: the demo values and rendered preview (nothing is sent). */
    public function testPreview(Request $r, MessageTemplate $template)
    {
        $this->authorizeTest($r, $template);
        $p = TemplateBuilder::demoParams($template);
        $components = null; $error = null;
        try { $components = TemplateBuilder::sendComponents($template, $p['body'], $p['header'], $p['buttons']); }
        catch (\Illuminate\Validation\ValidationException $e) { $error = collect($e->errors())->flatten()->implode(' '); }
        return ['template' => $template->only(['id', 'name', 'language', 'category', 'status', 'header_format']), 'params' => $p,
            'preview' => TemplateBuilder::render($template, $p['body'], $p['header']), 'components' => $components, 'error' => $error,
            'can_send' => $template->status === 'APPROVED' && ! $error];
    }

    /** POST /templates/{t}/test {phone_number_id, to}: send the template with demo values from the chosen number. */
    public function testSend(Request $r, MessageTemplate $template, \App\Services\MessagingService $svc)
    {
        $this->authorizeTest($r, $template);
        $d = $r->validate(['phone_number_id' => 'required|integer|exists:phone_numbers,id', 'to' => ['required', 'string', 'max:24', 'regex:/^\+?[\d\s\-()]{8,22}$/']],
            ['to.regex' => 'Enter the recipient number with country code, e.g. +91 98765 43210.']);
        $to = preg_replace('/\D/', '', $d['to']);
        if (strlen($to) < 10 || strlen($to) > 15) return response()->json(['message' => 'Enter the recipient number with country code (10–15 digits), e.g. +91 98765 43210.', 'errors' => ['to' => ['Invalid number']]], 422);
        $from = \App\Models\PhoneNumber::findOrFail($d['phone_number_id']);
        $user = $r->user();
        if ($user instanceof \App\Models\Client && $from->client_id !== $user->id) abort(403, 'You can only send from your own numbers.');
        if ($template->status !== 'APPROVED') return response()->json(['message' => "Only APPROVED templates can be tested (this one is {$template->status})."], 422);
        $p = TemplateBuilder::demoParams($template);
        $msg = $svc->sendTemplate($from, $to, $template, $p['body'], $p['header'], $p['buttons'], 'test');
        if ($msg->status === 'failed') {
            $e = $svc->lastError ?? new \RuntimeException($msg->error ?: 'Send failed');
            return $this->fail($e, 502, ['sent' => false, 'message_id' => $msg->id, 'params' => $p]);
        }
        return ['sent' => true, 'wamid' => $msg->wamid, 'message_id' => $msg->id, 'to' => $to, 'from' => $from->display_number,
            'params' => $p, 'meta_response' => $msg->raw];
    }

    protected function authorizeTest(Request $r, MessageTemplate $template): void
    {
        $u = $r->user();
        if ($u instanceof \App\Models\Client) abort_unless($template->client_id === $u->id, 404);
    }
}
