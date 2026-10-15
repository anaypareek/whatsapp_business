<?php
namespace App\Services;

/**
 * Pure, side-effect-free flow interpreter. Works on a plain definition array
 * {id, trigger_type, keywords, nodes, edges} so the simulator can run unsaved drafts.
 * Returns "outputs" (what the bot would send) plus the node it is now waiting on.
 *
 * Node types: trigger (start, handle "next"), message ("next"), question (one handle per option: "opt-<id>"),
 * condition ("match" / "nomatch", evaluated on the contact's latest reply), webhook ("next").
 */
class FlowRunner
{
    public const MAX_HOPS = 30; // safety against cycles that never ask a question

    public static function norm(?string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $s)));
    }

    /** Case-insensitive keyword match: contains (whole word or phrase), exact, starts_with. */
    public static function keywordMatch(string $type, array $keywords, ?string $text): bool
    {
        $t = self::norm($text);
        if ($t === '') return false;
        foreach ($keywords as $k) {
            $k = self::norm($k);
            if ($k === '') continue;
            $hit = match ($type) {
                'exact' => $t === $k,
                'starts_with' => str_starts_with($t, $k),
                default => (bool) preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($k, '/').'($|[^\p{L}\p{N}])/u', $t),
            };
            if ($hit) return true;
        }
        return false;
    }

    public static function triggerMatches(array $f, ?string $text): bool
    {
        return self::keywordMatch($f['trigger_type'] ?? 'contains', $f['keywords'] ?? [], $text);
    }

    public static function node(array $f, ?string $id): ?array
    {
        if ($id === null) return null;
        foreach ($f['nodes'] ?? [] as $n) if (($n['id'] ?? null) === $id) return $n;
        return null;
    }

    public static function next(array $f, string $from, string $handle): ?string
    {
        foreach ($f['edges'] ?? [] as $e) {
            if (($e['source'] ?? null) === $from && ($e['sourceHandle'] ?? 'next') === $handle) return $e['target'] ?? null;
        }
        return null;
    }

    public static function start(array $f, ?string $input): array
    {
        $trigger = collect($f['nodes'] ?? [])->firstWhere('type', 'trigger');
        return self::run($f, $trigger ? self::next($f, $trigger['id'], 'next') : null, $input);
    }

    /** Reply to a waiting question. ['matched' => false] when the reply matches no option. */
    public static function reply(array $f, string $waitingNodeId, ?string $text, ?string $interactiveId): array
    {
        $n = self::node($f, $waitingNodeId);
        if (! $n || $n['type'] !== 'question') return ['matched' => false];
        $opt = self::matchOption($f, $n, $text, $interactiveId);
        if (! $opt) return ['matched' => false];
        return ['matched' => true, 'option' => $opt['id']] + self::run($f, self::next($f, $n['id'], 'opt-'.$opt['id']), $text ?? $opt['title']);
    }

    /** Interactive reply id first, then typed text: option number, title, the option's keywords, or the title inside the text. */
    public static function matchOption(array $f, array $n, ?string $text, ?string $interactiveId): ?array
    {
        $opts = $n['data']['options'] ?? [];
        if ($interactiveId) {
            foreach ($opts as $o) if ($interactiveId === self::optionId($f, $n, $o)) return $o;
        }
        $t = self::norm($text);
        if ($t === '') return null;
        if (ctype_digit($t) && isset($opts[(int) $t - 1])) return $opts[(int) $t - 1];
        foreach ($opts as $o) if (self::norm($o['title'] ?? '') === $t) return $o;
        foreach ($opts as $o) {
            $kw = array_filter(array_map('trim', explode(',', (string) ($o['keywords'] ?? ''))));
            if ($kw && self::keywordMatch('contains', $kw, $t)) return $o;
        }
        foreach ($opts as $o) if (self::keywordMatch('contains', [$o['title'] ?? ''], $t)) return $o;
        // a word from exactly one option title ("laundry" -> "Laundry soap")
        $hits = array_values(array_filter($opts, fn ($o) => mb_strlen($t) >= 3 && self::keywordMatch('contains', [$t], $o['title'] ?? '')));
        if (count($hits) === 1) return $hits[0];
        // a significant word (4+ letters) of exactly one option title appears in the text ("support - urgent" -> "Talk to support")
        $hits = array_values(array_filter($opts, fn ($o) => collect(preg_split('/[^\p{L}\p{N}]+/u', self::norm($o['title'] ?? '')))
            ->filter(fn ($w) => mb_strlen($w) >= 4)->contains(fn ($w) => self::keywordMatch('contains', [$w], $t))));
        return count($hits) === 1 ? $hits[0] : null;
    }

    public static function optionId(array $f, array $n, array $o): string
    {
        return 'f'.($f['id'] ?? 0).'.'.$n['id'].'.'.$o['id'];
    }

    public static function run(array $f, ?string $nodeId, ?string $input): array
    {
        $out = [];
        for ($hops = 0; $nodeId !== null && $hops < self::MAX_HOPS; $hops++) {
            $n = self::node($f, $nodeId);
            if (! $n) break;
            $d = $n['data'] ?? [];
            switch ($n['type']) {
                case 'message':
                    $out[] = ['kind' => 'message', 'node_id' => $n['id'], 'text' => $d['text'] ?? '', 'media_type' => $d['media_type'] ?? 'none',
                        'media_url' => ($d['media_type'] ?? 'none') !== 'none' ? ($d['media_url'] ?? null) : null, 'filename' => $d['filename'] ?? null];
                    $nodeId = self::next($f, $n['id'], 'next');
                    break;
                case 'question':
                    $out[] = self::questionOutput($f, $n);
                    return ['outputs' => $out, 'waiting' => $n['id'], 'completed' => false];
                case 'condition':
                    $ok = self::keywordMatch($d['match_type'] ?? 'contains', $d['keywords'] ?? [], $input);
                    $out[] = ['kind' => 'condition', 'node_id' => $n['id'], 'matched' => $ok];
                    $nodeId = self::next($f, $n['id'], $ok ? 'match' : 'nomatch');
                    break;
                case 'webhook':
                    $out[] = ['kind' => 'webhook', 'node_id' => $n['id'], 'url' => $d['url'] ?? null];
                    $nodeId = self::next($f, $n['id'], 'next');
                    break;
                default:
                    $nodeId = null;
            }
        }
        return ['outputs' => $out, 'waiting' => null, 'completed' => true];
    }

    public static function questionOutput(array $f, array $n): array
    {
        $d = $n['data'] ?? [];
        return ['kind' => 'question', 'node_id' => $n['id'], 'text' => $d['text'] ?? '', 'interactive' => self::interactive($f, $n),
            'options' => array_map(fn ($o) => ['id' => self::optionId($f, $n, $o), 'title' => $o['title']], $d['options'] ?? [])];
    }

    /** Meta interactive payload: reply buttons for <= 3 options, otherwise a list message (max 10 rows). */
    public static function interactive(array $f, array $n): array
    {
        $d = $n['data'] ?? [];
        $opts = $d['options'] ?? [];
        $body = ['text' => mb_substr((string) ($d['text'] ?? ''), 0, 1024)];
        $p = count($opts) <= 3
            ? ['type' => 'button', 'body' => $body, 'action' => ['buttons' => array_map(fn ($o) => ['type' => 'reply',
                'reply' => ['id' => self::optionId($f, $n, $o), 'title' => mb_substr($o['title'], 0, 20)]], $opts)]]
            : ['type' => 'list', 'body' => $body, 'action' => ['button' => mb_substr((string) (($d['list_button'] ?? '') ?: 'Choose an option'), 0, 20),
                'sections' => [['title' => mb_substr((string) (($d['section_title'] ?? '') ?: 'Options'), 0, 24),
                    'rows' => array_map(fn ($o) => ['id' => self::optionId($f, $n, $o), 'title' => mb_substr($o['title'], 0, 24)], array_slice($opts, 0, 10))]]]];
        if (! empty($d['footer'])) $p['footer'] = ['text' => mb_substr($d['footer'], 0, 60)];
        return $p;
    }

    /** Re-ask a question after an unrecognised reply. */
    public static function reask(array $f, string $nodeId): array
    {
        $n = self::node($f, $nodeId);
        $msg = ($n['data']['retry_text'] ?? '') ?: 'Sorry, I didn\'t get that. Please choose one of the options.';
        return ['outputs' => [['kind' => 'message', 'node_id' => $nodeId, 'text' => $msg, 'media_type' => 'none', 'media_url' => null, 'filename' => null], self::questionOutput($f, $n)],
            'waiting' => $nodeId, 'completed' => false];
    }
}
