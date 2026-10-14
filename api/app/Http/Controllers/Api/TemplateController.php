<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\TemplateEdit;
use App\Services\TemplateBuilder;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Throwable;

class TemplateController extends Controller
{
    public function index(Request $r)
    {
        $q = MessageTemplate::query()->orderBy('name')->orderBy('language');
        foreach (['status', 'category', 'language'] as $f) if ($r->filled($f)) $q->where($f, $r->input($f));
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
        } catch (Throwable $e) { return response()->json(['message' => $e->getMessage()], 502); }
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
        catch (Throwable $e) { return response()->json(['message' => $e->getMessage()], 502); }
        TemplateEdit::create(['message_template_id' => $template->id, 'status_at_edit' => $template->status]);
        $template->update(['components' => $payload['components'], 'category' => $payload['category'] ?? $template->category,
            'status' => 'PENDING', 'rejected_reason' => null]); // edited templates go back to review
        return $template->fresh();
    }

    public function destroy(MessageTemplate $template, WhatsAppService $wa)
    {
        if ($template->meta_id) {
            try { $wa->deleteTemplate($template->name, $template->meta_id); }
            catch (Throwable $e) { return response()->json(['message' => $e->getMessage()], 502); }
        }
        $template->delete();
        return response()->noContent();
    }

    public function sync(WhatsAppService $wa)
    {
        try { $remote = $wa->listTemplates(); } catch (Throwable $e) { return response()->json(['message' => $e->getMessage()], 502); }
        $waba = Setting::get('waba_id');
        $ids = collect($remote)->map(fn ($t) => MessageTemplate::fromMeta($t, $waba)->id);
        // templates deleted on Meta's side
        $removed = MessageTemplate::whereNotNull('meta_id')->whereNotIn('id', $ids)->delete();
        return ['synced' => $ids->count(), 'removed' => $removed];
    }
}
