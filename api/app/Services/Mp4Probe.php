<?php
namespace App\Services;

/**
 * Minimal MP4/MOV (ISO-BMFF) header reader: duration, pixel size (rotation-aware), video codec and whether the moov atom precedes
 * mdat ("fast start", which Instagram wants). Pure PHP, so it works on shared hosting without ffprobe.
 */
class Mp4Probe
{
    /** @return array{duration:?float,width:?int,height:?int,codec:?string,faststart:?bool}|null null when the file is not a parsable MP4/MOV */
    public static function probe(string $file): ?array
    {
        $f = @fopen($file, 'rb');
        if (! $f) return null;
        try {
            $size = filesize($file); $pos = 0; $moov = null; $sawMdat = false; $faststart = null; $hasFtyp = false;
            while ($pos + 8 <= $size) {
                fseek($f, $pos);
                $h = fread($f, 16);
                if (strlen($h) < 8) break;
                $len = unpack('N', substr($h, 0, 4))[1]; $type = substr($h, 4, 4); $hdr = 8;
                if ($len === 1) { if (strlen($h) < 16) break; $len = unpack('J', substr($h, 8, 8))[1]; $hdr = 16; }
                elseif ($len === 0) $len = $size - $pos;
                if ($len < $hdr || $pos + $len > $size + 0) { if ($type !== 'mdat') break; $len = $size - $pos; }
                if ($type === 'ftyp') $hasFtyp = true;
                if ($type === 'mdat') $sawMdat = true;
                if ($type === 'moov') {
                    if ($len > 40 * 1024 * 1024) return null;
                    fseek($f, $pos + $hdr);
                    $moov = fread($f, $len - $hdr);
                    $faststart = ! $sawMdat;
                    break;
                }
                $pos += $len;
            }
            if (! $hasFtyp || $moov === null) return null;
            return self::parseMoov($moov) + ['faststart' => $faststart];
        } finally {
            fclose($f);
        }
    }

    /** Yields [type, payload] for the child boxes of a container payload. */
    protected static function boxes(string $data): array
    {
        $out = []; $o = 0; $n = strlen($data);
        while ($o + 8 <= $n) {
            $len = unpack('N', substr($data, $o, 4))[1]; $type = substr($data, $o + 4, 4);
            if ($len === 0) $len = $n - $o;
            if ($len < 8 || $o + $len > $n) break;
            $out[] = [$type, substr($data, $o + 8, $len - 8)];
            $o += $len;
        }
        return $out;
    }

    protected static function parseMoov(string $moov): array
    {
        $res = ['duration' => null, 'width' => null, 'height' => null, 'codec' => null];
        foreach (self::boxes($moov) as [$type, $p]) {
            if ($type === 'mvhd' && strlen($p) >= 20) {
                $v = ord($p[0]);
                if ($v === 1 && strlen($p) >= 32) { $ts = unpack('N', substr($p, 20, 4))[1]; $dur = unpack('J', substr($p, 24, 8))[1]; }
                else { $ts = unpack('N', substr($p, 12, 4))[1]; $dur = unpack('N', substr($p, 16, 4))[1]; }
                if ($ts > 0) $res['duration'] = round($dur / $ts, 2);
            }
            if ($type === 'trak') {
                $tr = self::boxes($p); $isVideo = false; $tkhd = null; $codec = null;
                foreach ($tr as [$t2, $p2]) {
                    if ($t2 === 'tkhd') $tkhd = $p2;
                    if ($t2 === 'mdia') foreach (self::boxes($p2) as [$t3, $p3]) {
                        if ($t3 === 'hdlr' && strlen($p3) >= 12 && substr($p3, 8, 4) === 'vide') $isVideo = true;
                        if ($t3 === 'minf') foreach (self::boxes($p3) as [$t4, $p4]) if ($t4 === 'stbl') foreach (self::boxes($p4) as [$t5, $p5]) if ($t5 === 'stsd' && strlen($p5) >= 16) $codec = substr($p5, 12, 4);
                    }
                }
                if (! $isVideo || $tkhd === null || $res['width'] !== null) continue;
                $v = ord($tkhd[0]); $base = $v === 1 ? 88 : 76; // matrix offset 40/48, width/height after matrix
                if (strlen($tkhd) < $base + 8) continue;
                $m = $v === 1 ? 48 : 40;
                $a = self::fixed(substr($tkhd, $m, 4)); $b = self::fixed(substr($tkhd, $m + 4, 4)); $c = self::fixed(substr($tkhd, $m + 12, 4)); $d = self::fixed(substr($tkhd, $m + 16, 4));
                $w = (int) round(unpack('N', substr($tkhd, $base, 4))[1] / 65536); $h = (int) round(unpack('N', substr($tkhd, $base + 4, 4))[1] / 65536);
                if (abs($a) < 0.01 && abs($d) < 0.01 && (abs($b) > 0.5 || abs($c) > 0.5)) [$w, $h] = [$h, $w]; // rotated 90/270°
                $res['width'] = $w; $res['height'] = $h; $res['codec'] = $codec;
            }
        }
        return $res;
    }

    protected static function fixed(string $b): float { return unpack('l', pack('l', unpack('N', $b)[1]))[1] / 65536; }
}
