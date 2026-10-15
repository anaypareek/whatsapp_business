<?php
namespace App\Services;

use App\Models\Flow;
use Illuminate\Validation\ValidationException;

/** Validates and normalises a flow graph (nodes + edges). Enforces the 10-step limit. */
class FlowValidator
{
    public static function rules(bool $partial = false): array
    {
        $s = $partial ? 'sometimes' : 'required';
        return [
            'name' => "$s|string|max:120",
            'trigger_type' => "$s|in:".implode(',', Flow::TRIGGER_TYPES),
            'keywords' => "$s|array|max:20",
            'keywords.*' => 'string|max:60',
            'nodes' => "$s|array|max:".(Flow::MAX_NODES + 1),
            'edges' => 'sometimes|array|max:200',
            'is_active' => 'sometimes|boolean',
        ];
    }

    /** @return array{0: array, 1: array} [nodes, edges] */
    public static function graph(array $nodes, array $edges): array
    {
        $err = [];
        $clean = []; $ids = [];
        $steps = 0; $triggers = 0;
        foreach (array_values($nodes) as $i => $n) {
            $id = (string) ($n['id'] ?? '');
            $type = $n['type'] ?? '';
            $d = is_array($n['data'] ?? null) ? $n['data'] : [];
            $label = "Step ".($i + 1);
            if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || isset($ids[$id])) { $err["nodes.$i.id"] = "$label has an invalid or duplicate id."; continue; }
            $ids[$id] = $type;
            if ($type === 'trigger') { $triggers++; $d = []; }
            elseif (! in_array($type, Flow::NODE_TYPES, true)) { $err["nodes.$i.type"] = "$label has an unknown type."; continue; }
            else $steps++;
            if ($type === 'message') {
                $mt = $d['media_type'] ?? 'none';
                if (! in_array($mt, ['none', 'image', 'document'], true)) $err["nodes.$i.media_type"] = "$label: media must be none, image or document.";
                if ($mt !== 'none' && ! filter_var($d['media_url'] ?? '', FILTER_VALIDATE_URL)) $err["nodes.$i.media_url"] = "$label: enter a valid media URL.";
                if (trim($d['text'] ?? '') === '' && $mt === 'none') $err["nodes.$i.text"] = "$label: message text is required.";
                if (mb_strlen($d['text'] ?? '') > 4096) $err["nodes.$i.text"] = "$label: message is longer than 4096 characters.";
                $d = ['text' => (string) ($d['text'] ?? ''), 'media_type' => $mt, 'media_url' => $mt !== 'none' ? ($d['media_url'] ?? null) : null, 'filename' => $d['filename'] ?? null];
            } elseif ($type === 'question') {
                $opts = array_values($d['options'] ?? []);
                if (trim($d['text'] ?? '') === '') $err["nodes.$i.text"] = "$label: question text is required.";
                if (mb_strlen($d['text'] ?? '') > 1024) $err["nodes.$i.text"] = "$label: question is longer than 1024 characters.";
                if (count($opts) < 1 || count($opts) > 10) $err["nodes.$i.options"] = "$label: a question needs 1 to 10 options.";
                $max = count($opts) <= 3 ? 20 : 24; $oids = [];
                foreach ($opts as $j => $o) {
                    $t = trim((string) ($o['title'] ?? ''));
                    $oid = (string) ($o['id'] ?? '');
                    if ($t === '' || mb_strlen($t) > $max) $err["nodes.$i.options.$j"] = "$label: option ".($j + 1)." needs a title of 1–$max characters".($max === 20 ? ' (button limit)' : ' (list limit)').'.';
                    if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $oid) || isset($oids['id:'.$oid]) || isset($oids['t:'.mb_strtolower($t)])) $err["nodes.$i.options.$j"] ??= "$label: option ".($j + 1)." is duplicated.";
                    $oids['id:'.$oid] = $oids['t:'.mb_strtolower($t)] = true;
                    $opts[$j] = ['id' => $oid, 'title' => $t, 'keywords' => mb_substr((string) ($o['keywords'] ?? ''), 0, 200)];
                }
                $d = ['text' => (string) ($d['text'] ?? ''), 'options' => $opts, 'footer' => mb_substr((string) ($d['footer'] ?? ''), 0, 60),
                    'list_button' => mb_substr((string) ($d['list_button'] ?? ''), 0, 20), 'retry_text' => mb_substr((string) ($d['retry_text'] ?? ''), 0, 500)];
            } elseif ($type === 'condition') {
                $kw = array_values(array_filter(array_map(fn ($k) => mb_substr(trim((string) $k), 0, 60), $d['keywords'] ?? [])));
                if (! $kw) $err["nodes.$i.keywords"] = "$label: add at least one keyword to the condition.";
                $mt = $d['match_type'] ?? 'contains';
                if (! in_array($mt, Flow::TRIGGER_TYPES, true)) $err["nodes.$i.match_type"] = "$label: invalid match type.";
                $d = ['keywords' => $kw, 'match_type' => $mt];
            } elseif ($type === 'webhook') {
                $url = (string) ($d['url'] ?? '');
                if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) $err["nodes.$i.url"] = "$label: enter a valid http(s) webhook URL.";
                $d = ['url' => $url];
            }
            $clean[] = ['id' => $id, 'type' => $type, 'position' => ['x' => (float) ($n['position']['x'] ?? 0), 'y' => (float) ($n['position']['y'] ?? 0)], 'data' => $d];
        }
        if ($steps > Flow::MAX_NODES) $err['nodes'] = 'A flow can have at most '.Flow::MAX_NODES.' steps.';
        if ($triggers !== 1) $err['nodes'] ??= 'A flow needs exactly one trigger.';

        $out = []; $seen = [];
        foreach (array_values($edges) as $k => $e) {
            $s = (string) ($e['source'] ?? ''); $t = (string) ($e['target'] ?? ''); $h = (string) (($e['sourceHandle'] ?? null) ?: 'next');
            if (! isset($ids[$s], $ids[$t]) || $ids[$t] === 'trigger' || $s === $t) continue; // drop dangling edges
            $valid = match ($ids[$s]) {
                'question' => str_starts_with($h, 'opt-') && in_array(substr($h, 4), array_column(collect($clean)->firstWhere('id', $s)['data']['options'] ?? [], 'id'), true),
                'condition' => in_array($h, ['match', 'nomatch'], true),
                default => $h === 'next',
            };
            if (! $valid) continue;
            $seen["$s|$h"] = ['id' => (string) (($e['id'] ?? '') ?: "e-$s-$h-$t"), 'source' => $s, 'sourceHandle' => $h, 'target' => $t]; // one edge per handle
        }
        $out = array_values($seen);
        if ($err) throw ValidationException::withMessages($err);
        return [$clean, $out];
    }
}
