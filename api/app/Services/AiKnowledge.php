<?php
namespace App\Services;

use App\Models\AiExample;
use App\Models\AiKnowledgeChunk;
use App\Models\AiKnowledgeFile;
use App\Models\AiProductImage;
use App\Models\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Per-number AI training data: Q->A corrections, knowledge file chunks, product images. Simple keyword-overlap retrieval (no embeddings). */
class AiKnowledge
{
    public const CHUNK_CHARS = 700;
    public const TOP_EXAMPLES = 3;
    public const TOP_CHUNKS = 3;
    public const TOP_IMAGES = 6;
    public const MAX_FILE_CHARS = 300000;
    protected const STOP = ['the', 'and', 'for', 'you', 'your', 'are', 'what', 'how', 'can', 'have', 'has', 'with', 'this', 'that', 'there', 'please', 'want',
        'kya', 'hai', 'hain', 'aap', 'mujhe', 'ka', 'ki', 'ke', 'me', 'do', 'is', 'of', 'to', 'in', 'a', 'an', 'it', 'i', 'my', 'me', 'pls', 'send', 'show'];

    /** Lowercase word set (≥2 chars, numbers kept, stop words dropped; naive plural strip). */
    public static function words(?string $t): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower((string) $t), $m);
        $out = [];
        foreach ($m[0] as $w) {
            if (mb_strlen($w) < 2 || in_array($w, self::STOP, true)) continue;
            if (mb_strlen($w) > 3 && str_ends_with($w, 's')) $w = mb_substr($w, 0, -1);
            $out[$w] = true;
        }
        return array_keys($out);
    }

    public static function score(array $q, ?string $text): int { return $q ? count(array_intersect($q, self::words($text))) : 0; }

    /** Top-N rows by keyword overlap with the query (score > 0 only). */
    protected static function top($rows, string $query, callable $text, int $n)
    {
        $q = self::words($query);
        return collect($rows)->map(fn ($r) => [$r, self::score($q, $text($r))])->filter(fn ($x) => $x[1] > 0)
            ->sortByDesc(fn ($x) => $x[1])->take($n)->map(fn ($x) => $x[0])->values();
    }

    public static function examples(PhoneNumber $p, string $q) { return self::top(AiExample::where('phone_number_id', $p->id)->latest('id')->limit(500)->get(), $q, fn ($e) => $e->question, self::TOP_EXAMPLES); }
    public static function chunks(PhoneNumber $p, string $q) { return self::top(AiKnowledgeChunk::where('phone_number_id', $p->id)->limit(3000)->get(['id', 'content']), $q, fn ($c) => $c->content, self::TOP_CHUNKS); }

    public static function images(PhoneNumber $p, string $q)
    {
        $all = AiProductImage::where('phone_number_id', $p->id)->orderBy('id')->limit(500)->get();
        return $all->count() <= self::TOP_IMAGES ? $all : self::top($all, $q, fn ($i) => $i->name.' '.$i->tags, self::TOP_IMAGES);
    }

    /** Extract text from PDF / TXT / CSV. */
    public static function extract(UploadedFile $f): array
    {
        $ext = strtolower($f->getClientOriginalExtension());
        $path = $f->getRealPath();
        if ($ext === 'pdf') {
            $text = '';
            try { $text = (new \Smalot\PdfParser\Parser())->parseFile($path)->getText(); } catch (Throwable) {}
            if (trim($text) === '' && is_executable('/usr/bin/pdftotext')) $text = (string) shell_exec('/usr/bin/pdftotext -q -layout '.escapeshellarg($path).' - 2>/dev/null');
            return ['pdf', $text];
        }
        $raw = (string) file_get_contents($path);
        if (! mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        if ($ext === 'csv') {
            $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $raw), fn ($l) => trim($l) !== ''));
            $head = $lines ? str_getcsv(array_shift($lines)) : [];
            $rows = array_map(function ($l) use ($head) {
                $c = str_getcsv($l);
                return implode(', ', array_map(fn ($v, $i) => trim(($head[$i] ?? 'col'.($i + 1)).': '.trim((string) $v)), $c, array_keys($c)));
            }, $lines);
            return ['csv', implode("\n", $rows)];
        }
        return ['txt', $raw];
    }

    /** Split into ~CHUNK_CHARS chunks on line boundaries (CSV: one row never split). */
    public static function chunk(string $text): array
    {
        $text = mb_substr(trim(preg_replace("/[ \t]+/", ' ', $text)), 0, self::MAX_FILE_CHARS);
        $out = []; $cur = '';
        foreach (preg_split('/\n+/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            while (mb_strlen($line) > self::CHUNK_CHARS) { $out[] = mb_substr($line, 0, self::CHUNK_CHARS); $line = mb_substr($line, self::CHUNK_CHARS); }
            if ($cur !== '' && mb_strlen($cur) + mb_strlen($line) + 1 > self::CHUNK_CHARS) { $out[] = $cur; $cur = ''; }
            $cur = $cur === '' ? $line : $cur."\n".$line;
        }
        if ($cur !== '') $out[] = $cur;
        return $out;
    }

    public static function ingest(PhoneNumber $p, UploadedFile $f): AiKnowledgeFile
    {
        [$kind, $text] = self::extract($f);
        return self::ingestText($p, $f->getClientOriginalName(), $text, $kind);
    }

    /** Store text as a knowledge "file" (also used for trainer notes, kind 'note'). */
    public static function ingestText(PhoneNumber $p, string $name, string $text, string $kind = 'note'): AiKnowledgeFile
    {
        $chunks = self::chunk($text);
        if (! $chunks) throw new RuntimeException('No text could be extracted from this file.');
        return DB::transaction(function () use ($p, $name, $kind, $chunks) {
            $file = AiKnowledgeFile::create(['phone_number_id' => $p->id, 'filename' => mb_substr($name, 0, 255), 'kind' => $kind,
                'chunks' => count($chunks), 'chars' => array_sum(array_map('mb_strlen', $chunks))]);
            foreach ($chunks as $c) AiKnowledgeChunk::create(['phone_number_id' => $p->id, 'ai_knowledge_file_id' => $file->id, 'content' => $c]);
            return $file;
        });
    }
}
