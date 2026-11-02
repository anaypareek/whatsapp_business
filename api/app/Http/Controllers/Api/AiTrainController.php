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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Admin-only "Train AI": playground, Q->A corrections, knowledge files, product images. Everything is scoped per number. */
class AiTrainController extends Controller
{
    protected function num(Request $r, int $id): PhoneNumber
    {
        abort_if($r->user() instanceof Client, 404);
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
        $kb = AiReplySetting::where('phone_number_id', $n->id)->value('knowledge_base');
        $text = $ai->complete($n, $kb, $d['messages']);
        if ($text === null) return response()->json(['message' => 'AI provider error or timeout. Check the key / model in Settings.'], 502);
        $p = AiReplyService::parse($n, $text);
        return ['raw' => $text, 'reply' => $p['handoff'] ? AiReplyService::HANDOFF_TEXT : $p['text'], 'handoff' => $p['handoff'],
            'order_lookup' => $p['order'], 'image' => $p['image']];
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
