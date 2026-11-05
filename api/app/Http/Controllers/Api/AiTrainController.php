<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiExample;
use App\Models\AiKnowledgeFile;
use App\Models\AiProductImage;
use App\Models\AiReplySetting;
use App\Models\Client;
use App\Models\PhoneNumber;
use App\Services\AiKnowledge;
use App\Services\AiReplyService;
use App\Services\AiTrainer;
use App\Services\AiUsageService;
use App\Models\AiOpenQuestion;
use App\Models\AiTrainerMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** "Train AI" (admin: all numbers; client portal: own numbers only): playground, Q->A corrections, knowledge files, product images. Everything is scoped per number. */
class AiTrainController extends Controller
{
    protected function num(Request $r, int $id): PhoneNumber
    {
        // admins: any number; clients (/client/ai-train): only their own numbers, everything else is 404
        $u = $r->user();
        if ($u instanceof Client) { $n = $u->phoneNumbers()->find($id); abort_unless($n, 404, 'Number not found'); return $n; }
        return PhoneNumber::findOrFail($id);
    }

    public function show(Request $r, int $phoneNumber)
    {
        $n = $this->num($r, $phoneNumber);
        return [
            'phone_number' => $n->only(['id', 'display_number', 'name']),
            'examples' => AiExample::where('phone_number_id', $n->id)->latest('id')->get(),
            'files' => AiKnowledgeFile::where('phone_number_id', $n->id)->latest('id')->get(),
            'images' => AiProductImage::where('phone_number_id', $n->id)->latest('id')->get(),
        ];
    }

    /** Playground: admin-triggered AI call with the saved key; nothing is sent to WhatsApp and no order API is called. */
    public function chat(Request $r, int $phoneNumber, AiReplyService $ai)
    {
        $n = $this->num($r, $phoneNumber);
        $d = $r->validate(['messages' => 'required|array|min:1|max:20', 'messages.*.role' => 'required|in:user,assistant', 'messages.*.content' => 'required|string|max:2000']);
        if (! AiReplyService::config()['key']) return response()->json(['message' => 'Set the OpenAI API key in Settings → AI Replies first.'], 422);
        if ($why = app(AiUsageService::class)->skipReason($n)) return response()->json(['message' => 'AI credits exhausted. Ask your admin to top up.', 'reason' => $why], 402);
        $kb = AiReplySetting::where('phone_number_id', $n->id)->value('knowledge_base');
        $text = $ai->complete($n, $kb, $d['messages'], AiUsageService::FEATURE_OTHER);
        if ($text === null) return response()->json(['message' => 'AI provider error or timeout. Check the key / model in Settings.'], 502);
        $p = AiReplyService::parse($n, $text);
        $s = AiReplySetting::where('phone_number_id', $n->id)->first();
        $lastUser = collect($d['messages'])->where('role', 'user')->last()['content'] ?? '';
        return ['raw' => $text, 'reply' => $p['handoff'] ? $ai->handoffText($s, (string) $lastUser) : $p['text'], 'handoff' => $p['handoff'],
            'order_lookup' => $p['order'], 'image' => $p['image']];
    }

    /** Trainer chat: history (last 50), open questions, deterministic opener. */
    public function trainer(Request $r, int $phoneNumber)
    {
        $n = $this->num($r, $phoneNumber);
        return [
            'messages' => AiTrainerMessage::where('phone_number_id', $n->id)->latest('id')->limit(50)->get()->reverse()->values()
                ->map(fn ($m) => $m->only(['id', 'role', 'content', 'saved', 'created_at']) + ['attachments' => collect($m->attachments)->map(fn ($a) => array_intersect_key($a, array_flip(['ref', 'type', 'filename', 'saved'])) + (isset($a['path']) ? ['url' => rtrim(config('app.url'), '/').'/storage/'.$a['path']] : []))->all()]),
            'open_questions' => static::openList($n),
            'greeting' => AiTrainer::greeting($n),
        ];
    }

    public function trainerSend(Request $r, int $phoneNumber, AiTrainer $t)
    {
        $n = $this->num($r, $phoneNumber);
        $r->validate(['message' => 'nullable|string|max:4000', 'files' => 'nullable|array|max:5', 'files.*' => 'file|max:10240|extensions:pdf,txt,csv,jpg,jpeg,png']);
        $text = trim((string) $r->input('message'));
        $files = $r->file('files', []);
        if ($text === '' && ! $files) return response()->json(['message' => 'Type something or attach a file.', 'errors' => ['message' => ['Required']]], 422);
        if (! AiReplyService::config()['key']) return response()->json(['message' => 'Set the OpenAI API key in Settings → AI Replies first.'], 422);
        if ($why = app(AiUsageService::class)->skipReason($n)) return response()->json(['message' => 'AI credits exhausted. Ask your admin to top up.', 'reason' => $why], 402);
        [$user, $bot, $notes] = $t->turn($n, $text, $files);
        if (! $bot) return response()->json(['message' => 'AI provider error or timeout. Your message was kept; try again.', 'user' => $user, 'saved' => $notes], 502);
        return ['user' => $user, 'reply' => $bot, 'saved' => $notes, 'open_questions' => AiOpenQuestion::where('phone_number_id', $n->id)->where('status', 'open')->count()];
    }

    public function trainerClear(Request $r, int $phoneNumber)
    {
        AiTrainer::forget($this->num($r, $phoneNumber));
        return ['ok' => true];
    }

    protected static function openList(PhoneNumber $n)
    {
        return AiOpenQuestion::where('phone_number_id', $n->id)->where('status', 'open')->latest('id')->limit(50)
            ->get(['id', 'question', 'contact_wa_id', 'contact_name', 'created_at']);
    }

    /** Unanswered customer questions (from AI handoffs) for this number. */
    public function questions(Request $r, int $phoneNumber)
    {
        $n = $this->num($r, $phoneNumber);
        return ['open' => static::openList($n),
            'resolved' => AiOpenQuestion::where('phone_number_id', $n->id)->where('status', 'resolved')->latest('resolved_at')->limit(20)->get(['id', 'question', 'answer', 'contact_name', 'resolved_at'])];
    }

    /** Answer one: saves a rule (Q->A) for THIS number only and resolves the question. */
    public function answerQuestion(Request $r, int $phoneNumber, int $id)
    {
        $n = $this->num($r, $phoneNumber);
        $q = AiOpenQuestion::where('phone_number_id', $n->id)->where('status', 'open')->findOrFail($id);
        $a = $r->validate(['answer' => 'required|string|max:2000'])['answer'];
        $q->update(['status' => 'resolved', 'answer' => $a, 'resolved_at' => now()]);
        $rule = AiExample::updateOrCreate(['phone_number_id' => $n->id, 'question' => mb_substr($q->question, 0, 1000)], ['answer' => $a]);
        return ['question' => $q, 'rule' => $rule];
    }

    public function dismissQuestion(Request $r, int $phoneNumber, int $id)
    {
        $n = $this->num($r, $phoneNumber);
        AiOpenQuestion::where('phone_number_id', $n->id)->findOrFail($id)->update(['status' => 'dismissed', 'resolved_at' => now()]);
        return ['ok' => true];
    }

    public function storeExample(Request $r, int $phoneNumber)
    {
        $n = $this->num($r, $phoneNumber);
        $d = $r->validate(['question' => 'required|string|max:1000', 'answer' => 'required|string|max:2000']);
        return AiExample::create($d + ['phone_number_id' => $n->id]);
    }

    public function updateExample(Request $r, int $phoneNumber, int $id)
    {
        $n = $this->num($r, $phoneNumber);
        $e = AiExample::where('phone_number_id', $n->id)->findOrFail($id);
        $e->update($r->validate(['question' => 'sometimes|string|max:1000', 'answer' => 'sometimes|string|max:2000']));
        return $e;
    }

    public function destroyExample(Request $r, int $phoneNumber, int $id)
    {
        $n = $this->num($r, $phoneNumber);
        AiExample::where('phone_number_id', $n->id)->findOrFail($id)->delete();
        return ['ok' => true];
    }

    public function storeFile(Request $r, int $phoneNumber)
    {
        $n = $this->num($r, $phoneNumber);
        $r->validate(['file' => 'required|file|max:10240|extensions:pdf,txt,csv']);
        try { return AiKnowledge::ingest($n, $r->file('file')); }
        catch (RuntimeException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422); }
    }

    public function destroyFile(Request $r, int $phoneNumber, int $id)
    {
        $n = $this->num($r, $phoneNumber);
        AiKnowledgeFile::where('phone_number_id', $n->id)->findOrFail($id)->delete(); // chunks cascade
        return ['ok' => true];
    }

    public function storeImage(Request $r, int $phoneNumber)
    {
        $n = $this->num($r, $phoneNumber);
        $d = $r->validate(['image' => 'required|file|max:5120|mimetypes:image/jpeg,image/png', 'name' => 'required|string|max:150', 'tags' => 'nullable|string|max:500']);
        $path = $r->file('image')->store("ai-products/{$n->id}", 'public');
        return AiProductImage::create(['phone_number_id' => $n->id, 'name' => $d['name'], 'tags' => $d['tags'] ?? null, 'path' => $path, 'mime_type' => $r->file('image')->getMimeType()]);
    }

    public function destroyImage(Request $r, int $phoneNumber, int $id)
    {
        $n = $this->num($r, $phoneNumber);
        $img = AiProductImage::where('phone_number_id', $n->id)->findOrFail($id);
        Storage::disk('public')->delete($img->path);
        $img->delete();
        return ['ok' => true];
    }
}
