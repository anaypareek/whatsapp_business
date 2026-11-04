<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\TemplateButtonReply;
use App\Services\ButtonReplies;
use App\Services\GreetingTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** "Button replies": the follow-up sent per QUICK_REPLY button of an APPROVED template. Admin: all templates; client: own templates only. */
class ButtonReplyController extends Controller
{
    protected function client(Request $r): ?Client { return $r->user() instanceof Client ? $r->user() : null; }

    protected function scoped(Request $r) { return MessageTemplate::query()->when($this->client($r), fn ($q, $c) => $q->where('client_id', $c->id)); }

    protected function find(Request $r, int $id): MessageTemplate { return $this->scoped($r)->findOrFail($id); }

    public function index(Request $r)
    {
        $counts = TemplateButtonReply::where('enabled', true)->selectRaw('message_template_id, COUNT(*) c')->groupBy('message_template_id')->pluck('c', 'message_template_id');
        return $this->scoped($r)->where('status', 'APPROVED')->orderBy('name')->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'language' => $t->language, 'category' => $t->category, 'buttons' => ButtonReplies::quickReplies($t), 'configured' => (int) ($counts[$t->id] ?? 0)])
            ->filter(fn ($t) => count($t['buttons']) > 0)->values();
    }

    public function show(Request $r, int $template)
    {
        $t = $this->find($r, $template);
        $usable = $this->scoped($r)->where('status', 'APPROVED')->when($t->waba_id, fn ($q) => $q->where(fn ($w) => $w->where('waba_id', $t->waba_id)->orWhereNull('waba_id')))
            ->orderBy('name')->get()->map(fn ($x) => GreetingTemplates::describe($x))->values();
        return ['template' => ['id' => $t->id, 'name' => $t->name, 'language' => $t->language, 'body' => $t->component('BODY')['text'] ?? '', 'buttons' => ButtonReplies::quickReplies($t)],
            'replies' => TemplateButtonReply::where('message_template_id', $t->id)->orderBy('button_index')->get(), 'templates' => $usable];
    }

    public function update(Request $r, int $template)
    {
        $t = $this->find($r, $template);
        abort_unless($t->status === 'APPROVED', 422, 'Only APPROVED templates can have button replies.');
        $d = $r->validate([
            'replies' => 'present|array|max:10',
            'replies.*.button_index' => 'required|integer|min:0|max:9',
            'replies.*.reply_type' => 'required|in:'.implode(',', TemplateButtonReply::TYPES),
            'replies.*.text' => 'nullable|string|max:4096',
            'replies.*.reply_template_id' => 'nullable|integer',
            'replies.*.template_params' => 'nullable|array',
            'replies.*.media_type' => 'nullable|in:image,document,video',
            'replies.*.media_url' => 'nullable|string|max:1024',
            'replies.*.enabled' => 'boolean',
        ]);
        $buttons = collect(ButtonReplies::quickReplies($t))->keyBy('index');
        $rows = []; $errors = [];
        foreach ($d['replies'] as $i => $x) {
            $f = "replies.$i";
            $b = $buttons[$x['button_index']] ?? null;
            if (! $b) { $errors["$f.button_index"] = 'This template has no quick-reply button at that position.'; continue; }
            $row = ['button_index' => $b['index'], 'button_text' => mb_substr($b['text'], 0, 64), 'reply_type' => $x['reply_type'], 'enabled' => $x['enabled'] ?? true,
                'text' => null, 'reply_template_id' => null, 'template_params' => null, 'media_type' => null, 'media_url' => null];
            if ($x['reply_type'] === 'text') {
                if (trim((string) ($x['text'] ?? '')) === '') $errors["$f.text"] = "“{$b['text']}”: enter the reply text.";
                $row['text'] = $x['text'] ?? null;
            } elseif ($x['reply_type'] === 'media') {
                $type = $x['media_type'] ?? null; $url = (string) ($x['media_url'] ?? '');
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                $ok = ['image' => ['jpg', 'jpeg', 'png'], 'video' => ['mp4'], 'document' => ['pdf']];
                if (! $type) $errors["$f.media_type"] = "“{$b['text']}”: choose image, document or video.";
                elseif (! preg_match('#^https://\S+$#', $url)) $errors["$f.media_url"] = "“{$b['text']}”: enter a public https:// link to the {$type}.";
                elseif (! in_array($ext, $ok[$type], true)) $errors["$f.media_url"] = "“{$b['text']}”: a {$type} link must end in .".implode(' / .', $ok[$type]).'.';
                $row['media_type'] = $type; $row['media_url'] = $url; $row['text'] = $x['text'] ?? null;
            } else {
                $rt = $this->scoped($r)->find($x['reply_template_id'] ?? 0);
                if (! $rt) { $errors["$f.reply_template_id"] = "“{$b['text']}”: choose an approved template."; continue; }
                $err = GreetingTemplates::validate(new \App\Models\PhoneNumber(['waba_id' => $t->waba_id]), $this->client($r), 'reply', $rt->id, $x['template_params'] ?? []);
                foreach ($err as $msg) $errors["$f.reply_template_id"] = "“{$b['text']}”: {$msg}";
                $row['reply_template_id'] = $rt->id; $row['template_params'] = $x['template_params'] ?? [];
            }
            $rows[$b['index']] = $row;
        }
        if ($errors) return response()->json(['message' => reset($errors), 'errors' => array_map(fn ($e) => [$e], $errors)], 422);
        DB::transaction(function () use ($t, $rows) {
            TemplateButtonReply::where('message_template_id', $t->id)->whereNotIn('button_index', array_keys($rows) ?: [-1])->delete();
            foreach ($rows as $idx => $row) TemplateButtonReply::updateOrCreate(['message_template_id' => $t->id, 'button_index' => $idx], $row);
        });
        return $this->show($r, $t->id);
    }
}
