<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\TemplateRequest;
use App\Services\TemplateBuilder;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Throwable;

/** Admin review of client template requests: view, edit, approve (creates on Meta) or reject. */
class TemplateRequestController extends Controller
{
    const WITH = ['client:id,name,company', 'template:id,name,language,status,rejected_reason,meta_id,category', 'reviewer:id,name'];

    /** Validate builder input and return [form, components]. Shared with the client portal. */
    public static function build(Request $r, ?int $ignoreId = null): array
    {
        $d = $r->validate(TemplateBuilder::rules());
        $components = TemplateBuilder::components($d);
        $dup = MessageTemplate::where('name', $d['name'])->where('language', $d['language'])->exists()
            || TemplateRequest::where('name', $d['name'])->where('language', $d['language'])->where('status', 'pending')
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($dup) abort(response()->json(['message' => 'A template or pending request with this name and language already exists.', 'errors' => ['name' => ['Duplicate name+language']]], 422));
        $form = collect($d)->only(['header', 'body', 'footer', 'buttons'])->all();
        return [$d, $form, $components];
    }

    public function index(Request $r)
    {
        $q = TemplateRequest::with(self::WITH)->latest('id');
        if ($r->filled('status')) $q->where('status', $r->status);
        if ($r->filled('q')) $q->where('name', 'like', "%{$r->q}%");
        return $q->get();
    }

    public function show(TemplateRequest $templateRequest) { return $templateRequest->load(self::WITH); }

    public function update(Request $r, TemplateRequest $templateRequest)
    {
        abort_unless($templateRequest->status === 'pending', 422, 'Only pending requests can be edited.');
        [$d, $form, $components] = self::build($r, $templateRequest->id);
        $templateRequest->update(['name' => $d['name'], 'language' => $d['language'], 'category' => $d['category'], 'form' => $form, 'components' => $components]);
        return $templateRequest->fresh()->load(self::WITH);
    }

    public function approve(Request $r, TemplateRequest $templateRequest, WhatsAppService $wa)
    {
        abort_unless($templateRequest->status === 'pending', 422, 'Only pending requests can be approved.');
        $t = $templateRequest;
        if (MessageTemplate::where('name', $t->name)->where('language', $t->language)->exists()) {
            return response()->json(['message' => 'A template with this name and language already exists. Edit the request name first.'], 422);
        }
        try {
            $res = $wa->createTemplate(['name' => $t->name, 'language' => $t->language, 'category' => $t->category, 'components' => $t->components]);
        } catch (Throwable $e) {
            $t->update(['meta_error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage(), 'request' => $t->fresh()->load(self::WITH)], 502);
        }
        $tpl = MessageTemplate::create([
            'client_id' => $t->client_id, 'meta_id' => $res['id'] ?? null, 'waba_id' => Setting::get('waba_id'),
            'name' => $t->name, 'language' => $t->language, 'category' => $res['category'] ?? $t->category,
            'status' => $res['status'] ?? 'PENDING', 'components' => $t->components,
        ]);
        $t->update(['status' => 'approved', 'message_template_id' => $tpl->id, 'meta_error' => null, 'rejection_reason' => null,
            'reviewed_by' => $r->user()->id, 'reviewed_at' => now()]);
        return $t->fresh()->load(self::WITH);
    }

    public function reject(Request $r, TemplateRequest $templateRequest)
    {
        abort_unless($templateRequest->status === 'pending', 422, 'Only pending requests can be rejected.');
        $d = $r->validate(['reason' => 'required|string|max:1000']);
        $templateRequest->update(['status' => 'rejected', 'rejection_reason' => $d['reason'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now()]);
        return $templateRequest->fresh()->load(self::WITH);
    }
}
