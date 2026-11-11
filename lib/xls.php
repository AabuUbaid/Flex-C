<?php
declare(strict_types=1);
/*
 * Reader for old Excel .xls files (BIFF8 inside an OLE2 compound file), first worksheet only.
 * Many shop programs export stock lists in this format. Text, numbers, RK numbers, formulas (cached result)
 * and booleans are read; formatting is ignored. No external libraries.
 */

/** @return list<list<string>> */
function xls_rows(string $path): array
{
    $data = (string)file_get_contents($path);
    $book = xls_ole_stream($data, ['Workbook', 'Book']);
    if ($book === null) {
        fail('bad_file_type');
    }
    // 1. records
    $recs = [];
    $len = strlen($book);
    for ($p = 0; $p + 4 <= $len;) {
        ['t' => $t, 'l' => $l] = unpack('vt/vl', $book, $p);
        $recs[] = [$t, substr($book, $p + 4, $l), $p];
        $p += 4 + $l;
    }
    // 2. shared strings and the offset of the first worksheet
    $sst = [];
    $firstSheet = null;
    $n = count($recs);
    for ($i = 0; $i < $n; $i++) {
        [$t, $d] = $recs[$i];
        if ($t === 0x0085 && $firstSheet === null && strlen($d) >= 6) {        // BOUNDSHEET
            $info = unpack('Vpos/Cvis/Ctype', $d);
            if ($info['type'] === 0) {
                $firstSheet = $info['pos'];
            }
        } elseif ($t === 0x00FC) {                                             // SST (+ CONTINUE)
            $chunks = [substr($d, 8)];
            while ($i + 1 < $n && $recs[$i + 1][0] === 0x003C) {
                $chunks[] = $recs[++$i][1];
            }
            $total = unpack('V', $d, 4)[1];
            $sst = xls_sst($chunks, (int)$total);
        }
    }
    if ($firstSheet === null) {
        fail('bad_file_type');
    }
    // 3. cells of the first worksheet
    $cells = [];
    $maxRow = -1;
    $put = function (int $r, int $c, string $v) use (&$cells, &$maxRow): void {
        if ($r > 20001) {
            fail('too_many_rows');
        }
        $cells[$r][$c] = trim($v);
        $maxRow = max($maxRow, $r);
    };
    $num = fn(float $f): string => (string)(0 + round($f, 6));
    $start = false;
    $pendingFormula = null;
    foreach ($recs as [$t, $d, $pos]) {
        if (!$start) {
            if ($pos === $firstSheet) {
                $start = true;
            }
            continue;
        }
        if ($t === 0x000A) {                                                   // EOF of the sheet
            break;
        }
        switch ($t) {
            case 0x00FD:                                                       // LABELSST
                $r = unpack('vr/vc/vx/Vi', $d);
                $put($r['r'], $r['c'], $sst[$r['i']] ?? '');
                break;
            case 0x0203:                                                       // NUMBER
                $r = unpack('vr/vc/vx', $d);
                $put($r['r'], $r['c'], $num(unpack('e', $d, 6)[1]));
                break;
            case 0x027E:                                                       // RK
                $r = unpack('vr/vc/vx/Vk', $d);
                $put($r['r'], $r['c'], $num(xls_rk($r['k'])));
                break;
            case 0x00BD:                                                       // MULRK
                $r = unpack('vr/vc', $d);
                $cnt = intdiv(strlen($d) - 6, 6);
                for ($k = 0; $k < $cnt; $k++) {
                    $put($r['r'], $r['c'] + $k, $num(xls_rk(unpack('V', $d, 4 + $k * 6 + 2)[1])));
                }
                break;
            case 0x0204:                                                       // LABEL (rare in BIFF8)
                $r = unpack('vr/vc/vx/vn/Cf', $d);
                $put($r['r'], $r['c'], xls_chars(substr($d, 9), $r['n'], ($r['f'] & 1) === 1));
                break;
            case 0x0205:                                                       // BOOLERR
                $r = unpack('vr/vc/vx/Cv/Ce', $d);
                $put($r['r'], $r['c'], $r['e'] ? '' : ($r['v'] ? 'TRUE' : 'FALSE'));
                break;
            case 0x0006:                                                       // FORMULA: cached result
                $r = unpack('vr/vc/vx', $d);
                $res = substr($d, 6, 8);
                if (substr($res, 6, 2) === "\xFF\xFF") {
                    if (ord($res[0]) === 0) {
                        $pendingFormula = [$r['r'], $r['c']];                  // text follows in a STRING record
                    } elseif (ord($res[0]) === 1) {
                        $put($r['r'], $r['c'], ord($res[2]) ? 'TRUE' : 'FALSE');
                    }
                } else {
                    $put($r['r'], $r['c'], $num(unpack('e', $res)[1]));
                }
                break;
            case 0x0207:                                                       // STRING (formula text)
                if ($pendingFormula) {
                    $h = unpack('vn/Cf', $d);
                    $put($pendingFormula[0], $pendingFormula[1], xls_chars(substr($d, 3), $h['n'], ($h['f'] & 1) === 1));
                    $pendingFormula = null;
                }
                break;
        }
    }
    $rows = [];
    for ($r = 0; $r <= $maxRow; $r++) {
        $row = [];
        if (isset($cells[$r])) {
            $max = max(array_keys($cells[$r]));
            for ($c = 0; $c <= $max; $c++) {
                $row[] = $cells[$r][$c] ?? '';
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

function xls_rk(int $k): float
{
    if ($k & 2) {
        $v = (float)($k >> 2);
        if ($k & 0x80000000) {                // sign-extend the 30-bit integer
            $v = (float)(($k >> 2) - (1 << 30));
        }
    } else {
        $v = unpack('e', "\0\0\0\0" . pack('V', $k & 0xFFFFFFFC))[1];
    }
    return ($k & 1) ? $v / 100 : $v;
}

function xls_chars(string $s, int $n, bool $wide): string
{
    if ($wide) {
        return (string)mb_convert_encoding(substr($s, 0, $n * 2), 'UTF-8', 'UTF-16LE');
    }
    return (string)mb_convert_encoding(substr($s, 0, $n), 'UTF-8', 'ISO-8859-1');
}

/** Shared strings. A string may continue in the next CONTINUE record, which then starts with a new 8/16-bit flag byte. */
function xls_sst(array $chunks, int $total): array
{
    $out = [];
    $ci = 0;
    $p = 0;
    $cur = $chunks[0] ?? '';
    $next = function () use (&$ci, &$p, &$cur, $chunks): bool {
        if ($ci + 1 >= count($chunks)) {
            return false;
        }
        $cur = $chunks[++$ci];
        $p = 0;
        return true;
    };
    $bytes = function (int $k) use (&$p, &$cur, $next): string {
        $s = '';
        while ($k > 0) {
            if ($p >= strlen($cur) && !$next()) {
                break;
            }
            $take = min($k, strlen($cur) - $p);
            $s .= substr($cur, $p, $take);
            $p += $take;
            $k -= $take;
        }
        return $s;
    };
    for ($i = 0; $i < $total; $i++) {
        if ($p >= strlen($cur) && !$next()) {
            break;
        }
        $h = $bytes(3);
        if (strlen($h) < 3) {
            break;
        }
        $nch = unpack('v', $h)[1];
        $flags = ord($h[2]);
        $rich = ($flags & 8) ? unpack('v', $bytes(2))[1] : 0;
        $ext = ($flags & 4) ? unpack('V', $bytes(4))[1] : 0;
        $wide = ($flags & 1) === 1;
        $str = '';
        $left = $nch;
        while ($left > 0) {
            if ($p >= strlen($cur)) {
                if (!$next()) {
                    break;
                }
                $wide = (ord($cur[0]) & 1) === 1;   // continued text: new flag byte
                $p = 1;
            }
            $can = $wide ? intdiv(strlen($cur) - $p, 2) : strlen($cur) - $p;
            $take = min($left, $can);
            if ($take <= 0) {
                $p = strlen($cur);
                continue;
            }
            $raw = substr($cur, $p, $wide ? $take * 2 : $take);
            $p += strlen($raw);
            $str .= xls_chars($raw, $take, $wide);
            $left -= $take;
        }
        $bytes($rich * 4 + $ext);
        $out[] = $str;
    }
    return $out;
}

/** A named stream from an OLE2 compound file, or null. */
function xls_ole_stream(string $d, array $names): ?string
{
    if (strlen($d) < 512 || substr($d, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return null;
    }
    $h = unpack('vminor/vmajor/vorder/vsecShift/vminiShift', $d, 24);
    $ss = 1 << $h['secShift'];
    $mss = 1 << $h['miniShift'];
    $hh = unpack('VnFat/VdirStart/Vx/VminiCutoff/VminiFatStart/VnMiniFat/VdifStart/VnDif', $d, 44);
    $sec = fn(int $i): string => substr($d, 512 + $i * $ss, $ss);
    // DIFAT -> FAT
    $difat = array_values(unpack('V109', $d, 76));
    $ds = $hh['difStart'];
    for ($k = 0; $k < $hh['nDif'] && $ds < 0xFFFFFFFA; $k++) {
        $vals = array_values(unpack('V' . ($ss / 4), $sec($ds)));
        $ds = array_pop($vals);
        $difat = array_merge($difat, $vals);
    }
    $fat = [];
    foreach (array_slice($difat, 0, $hh['nFat']) as $fs) {
        if ($fs >= 0xFFFFFFFA) {
            continue;
        }
        foreach (unpack('V' . ($ss / 4), $sec($fs)) as $v) {
            $fat[] = $v;
        }
    }
    $chain = function (int $start, array $table, callable $read, int $limit) {
        $out = '';
        $seen = 0;
        for ($s = $start; $s < 0xFFFFFFFA && isset($table[$s]) && $seen++ < $limit; $s = $table[$s]) {
            $out .= $read($s);
        }
        return $out;
    };
    $dir = $chain($hh['dirStart'], $fat, $sec, 100000);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $e = substr($dir, $o, 128);
        $nl = unpack('v', $e, 64)[1];
        $name = $nl >= 2 ? (string)mb_convert_encoding(substr($e, 0, $nl - 2), 'UTF-8', 'UTF-16LE') : '';
        $x = unpack('Ctype', $e, 66) + unpack('Vstart/Vsize', $e, 116);
        $entries[$name] = $x;
    }
    $root = $entries['Root Entry'] ?? null;
    foreach ($names as $nm) {
        if (!isset($entries[$nm])) {
            continue;
        }
        $e = $entries[$nm];
        if ($e['size'] >= $hh['miniCutoff'] || !$root) {
            return substr($chain($e['start'], $fat, $sec, 1000000), 0, $e['size']);
        }
        $miniStream = $chain($root['start'], $fat, $sec, 1000000);
        $miniFat = [];
        foreach (str_split($chain($hh['miniFatStart'], $fat, $sec, 100000), 4) as $b) {
            if (strlen($b) === 4) {
                $miniFat[] = unpack('V', $b)[1];
            }
        }
        $read = fn(int $i): string => substr($miniStream, $i * $mss, $mss);
        return substr($chain($e['start'], $miniFat, $read, 1000000), 0, $e['size']);
    }
    return null;
}
