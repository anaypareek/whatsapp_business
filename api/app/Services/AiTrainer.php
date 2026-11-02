<?php
namespace App\Services;

use App\Models\AiExample;
use App\Models\AiKnowledgeFile;
use App\Models\AiOpenQuestion;
use App\Models\AiProductImage;
use App\Models\AiReplySetting;
use App\Models\AiTrainerMessage;
use App\Models\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Conversational trainer for one number. The admin types instructions / data and attaches files or images;
 * the trainer model replies and emits an <actions>[...]</actions> JSON block which we apply to the
 * existing corrections / knowledge / images tables. Separate system prompt from the customer AI.
 */
class AiTrainer
{
    public const CONTEXT = 10;
    public const MAX_TOKENS = 600;
    public const OPEN_LIMIT = 5;

    public static function systemPrompt(PhoneNumber $n): string
    {
        $biz = $n->name ?: $n->display_number;
        $counts = sprintf('%d rules, %d knowledge files/notes, %d product images',
            AiExample::where('phone_number_id', $n->id)->count(), AiKnowledgeFile::where('phone_number_id', $n->id)->count(), AiProductImage::where('phone_number_id', $n->id)->count());
        $open = static::open($n)->map(fn ($q) => "#{$q->id}: \"".mb_substr($q->question, 0, 200).'"')->implode("\n") ?: '(none)';
        $pending = static::pendingImages($n)->map(fn ($a) => "{$a['ref']} ({$a['filename']})")->implode(', ') ?: '(none)';
        return <<<P
You are the TRAINER assistant for the WhatsApp customer-service AI of "{$biz}". You talk to the business owner (admin), not to customers.
Your job: learn facts, prices, policies and answers from what the admin tells you, and save them so the customer AI can use them.
Be brief and friendly (1-3 short sentences). If something is unclear or missing (e.g. a price without unit, an image without a product name, an answer that is ambiguous), ask ONE short clarifying question and do NOT save it yet.
When you have clear information, save it by ending your reply with exactly one block:
<actions>[ ... ]</actions>
Allowed actions (JSON objects):
- {"type":"save_rule","question":"customer question","answer":"exact answer to give"}   (Q->A for a specific question)
- {"type":"save_knowledge","title":"short title","text":"facts in plain text"}           (general facts, prices, timings, policies)
- {"type":"save_image","ref":"img-…","name":"product name","tags":"comma, separated, tags"} (only for a pending image ref below)
- {"type":"resolve_question","id":123,"answer":"answer to give customers"}                 (when the admin answers an open question)
Never invent facts the admin did not give. Do not repeat already-saved actions. Omit the block if nothing should be saved.
Currently saved: {$counts}.
OPEN QUESTIONS (customers asked, the AI could not answer — bring these up when relevant and ask the admin for the answer):
{$open}
PENDING IMAGES (attached but not saved yet; need a product name and tags): {$pending}
P;
    }

    public static function open(PhoneNumber $n) { return AiOpenQuestion::where('phone_number_id', $n->id)->where('status', 'open')->latest('id')->limit(self::OPEN_LIMIT)->get(); }

    /** Image attachments in recent trainer messages that were not saved as product images yet. */
    public static function pendingImages(PhoneNumber $n)
    {
        return AiTrainerMessage::where('phone_number_id', $n->id)->where('role', 'user')->whereNotNull('attachments')->latest('id')->limit(20)->get()
            ->flatMap(fn ($m) => collect($m->attachments)->filter(fn ($a) => ($a['type'] ?? '') === 'image' && empty($a['saved']))->map(fn ($a) => $a + ['message_id' => $m->id]))->values();
    }

    /** Deterministic opener listing open questions (no AI call). */
    public static function greeting(PhoneNumber $n): ?string
    {
        $open = static::open($n);
        if ($open->isEmpty()) return null;
        $lines = $open->map(fn ($q) => '• '.mb_substr($q->question, 0, 160))->implode("\n");
        return "Customers asked me these and I couldn't answer 🙏\n{$lines}\nTell me the answers and I'll remember them.";
    }

    /** Store uploads: documents are ingested right away, images are kept pending until named. */
    public static function attach(PhoneNumber $n, array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            /** @var UploadedFile $f */
            $ext = strtolower($f->getClientOriginalExtension());
            $name = mb_substr($f->getClientOriginalName(), 0, 200);
            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $out[] = ['ref' => 'img-'.bin2hex(random_bytes(3)), 'type' => 'image', 'filename' => $name, 'path' => $f->store("ai-products/{$n->id}", 'public'), 'mime' => $f->getMimeType(), 'saved' => false];
                continue;
            }
            try {
                $file = AiKnowledge::ingest($n, $f);
                $out[] = ['ref' => 'file-'.$file->id, 'type' => 'file', 'filename' => $name, 'saved' => true, 'note' => "Knowledge file: {$name} ({$file->chunks} chunks)"];
            } catch (RuntimeException $e) {
                $out[] = ['ref' => 'file-x', 'type' => 'file', 'filename' => $name, 'saved' => false, 'note' => "Could not read {$name}: no text found"];
            }
        }
        return $out;
    }

    /** Text the model sees for a stored message (attachments summarised, low tokens). */
    protected static function contextText(AiTrainerMessage $m): string
    {
        $t = mb_substr($m->content, 0, 1500);
        foreach ((array) $m->attachments as $a) {
            $t .= $a['type'] === 'image' ? "\n[attached image {$a['ref']}: {$a['filename']}]" : "\n[attached file {$a['filename']}: ".($a['saved'] ? 'already saved to knowledge' : 'unreadable').']';
        }
        if ($m->role === 'assistant' && $m->saved) $t .= "\n(saved: ".implode('; ', $m->saved).')';
        return $t;
    }

    public function complete(PhoneNumber $n): ?string
    {
        $cfg = AiReplyService::config();
        if (! $cfg['key']) return null;
        $history = AiTrainerMessage::where('phone_number_id', $n->id)->latest('id')->limit(self::CONTEXT)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => static::contextText($m)])->values()->all();
        try {
            $res = Http::withToken($cfg['key'])->acceptJson()->timeout(AiReplyService::TIMEOUT)->connectTimeout(5)->post($cfg['base_url'].'/chat/completions', [
                'model' => $cfg['model'], 'max_tokens' => self::MAX_TOKENS, 'temperature' => 0.2,
                'messages' => array_merge([['role' => 'system', 'content' => static::systemPrompt($n)]], $history),
            ]);
            if (! $res->successful()) { Log::warning('AI trainer: provider HTTP '.$res->status()); return null; }
            return trim((string) ($res->json('choices.0.message.content') ?? ''));
        } catch (Throwable $e) {
            Log::warning('AI trainer: provider unreachable ('.class_basename($e).')');
            return null;
        }
    }

    /** Split model output into [visible text, actions[]]. Accepts <actions>…</actions> or a ```json fenced array. */
    public static function parse(string $text): array
    {
        $actions = [];
        if (preg_match('/<actions>(.*?)<\/actions>/is', $text, $m) || preg_match('/```(?:json|actions)?\s*(\[\s*\{.*?\}\s*\])\s*```/is', $text, $m)) {
            $j = json_decode(trim($m[1]), true);
            if (is_array($j)) $actions = array_is_list($j) ? $j : [$j];
            $text = str_replace($m[0], '', $text);
        }
        return [trim($text), array_values(array_filter($actions, 'is_array'))];
    }

    /** Apply actions scoped to the number. Returns human "Saved: …" notes. */
    public static function apply(PhoneNumber $n, array $actions): array
    {
        $notes = [];
        foreach (array_slice($actions, 0, 10) as $a) {
            $s = fn ($k, $max = 2000) => trim(mb_substr((string) ($a[$k] ?? ''), 0, $max));
            switch ($a['type'] ?? '') {
                case 'save_rule':
                    if ($s('question') === '' || $s('answer') === '') break;
                    $e = AiExample::updateOrCreate(['phone_number_id' => $n->id, 'question' => $s('question', 1000)], ['answer' => $s('answer')]);
                    $notes[] = 'Rule: “'.mb_strimwidth($e->question, 0, 60, '…').'”';
                    break;
                case 'save_knowledge':
                    if ($s('text', 20000) === '') break;
                    $f = AiKnowledge::ingestText($n, $s('title', 120) ?: 'Trainer note', $s('text', 20000));
                    $notes[] = 'Knowledge: '.$f->filename;
                    break;
                case 'save_image':
                    $p = static::pendingImages($n)->firstWhere('ref', $s('ref', 40));
                    if (! $p || $s('name', 150) === '') break;
                    $img = AiProductImage::create(['phone_number_id' => $n->id, 'name' => $s('name', 150), 'tags' => $s('tags', 500) ?: null, 'path' => $p['path'], 'mime_type' => $p['mime'] ?? 'image/jpeg']);
                    $msg = AiTrainerMessage::find($p['message_id']);
                    $msg->update(['attachments' => collect($msg->attachments)->map(fn ($x) => $x['ref'] === $p['ref'] ? ['saved' => true, 'image_id' => $img->id] + $x : $x)->all()]);
                    $notes[] = 'Image: '.$img->name.($img->tags ? " ({$img->tags})" : '');
                    break;
                case 'resolve_question':
                    $q = AiOpenQuestion::where('phone_number_id', $n->id)->where('status', 'open')->find((int) ($a['id'] ?? 0));
                    if (! $q || $s('answer') === '') break;
                    $q->update(['status' => 'resolved', 'answer' => $s('answer'), 'resolved_at' => now()]);
                    AiExample::updateOrCreate(['phone_number_id' => $n->id, 'question' => mb_substr($q->question, 0, 1000)], ['answer' => $s('answer')]);
                    $notes[] = 'Answered: “'.mb_strimwidth($q->question, 0, 60, '…').'”';
                    break;
            }
        }
        return $notes;
    }

    /** One trainer turn. Returns the stored assistant message, or null on provider error (user message is kept). */
    public function turn(PhoneNumber $n, string $text, array $files = []): array
    {
        $att = static::attach($n, $files);
        $user = AiTrainerMessage::create(['phone_number_id' => $n->id, 'role' => 'user', 'content' => $text !== '' ? $text : '(attached '.count($att).' file(s))', 'attachments' => $att ?: null]);
        $fileNotes = collect($att)->pluck('note')->filter()->values()->all();
        $raw = $this->complete($n);
        if ($raw === null) return [$user, null, $fileNotes];
        [$visible, $actions] = static::parse($raw);
        $notes = array_merge($fileNotes, static::apply($n, $actions));
        $bot = AiTrainerMessage::create(['phone_number_id' => $n->id, 'role' => 'assistant', 'content' => $visible !== '' ? $visible : ($notes ? 'Got it 👍' : '…'), 'saved' => $notes ?: null]);
        return [$user, $bot, $notes];
    }

    public static function forget(PhoneNumber $n): void
    {
        foreach (static::pendingImages($n) as $p) Storage::disk('public')->delete($p['path']);
        AiTrainerMessage::where('phone_number_id', $n->id)->delete();
    }
}
