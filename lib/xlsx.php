<?php
declare(strict_types=1);
// Minimal spreadsheet reader for stock import: .xlsx, old .xls (lib/xls.php) and .csv, first sheet. No external libraries.

/** @return list<list<string>> rows of text cells */
function sheet_rows(string $path, string $origName): array
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return xlsx_rows($path);
    }
    if ($ext === 'csv' || $ext === 'txt') {
        return csv_rows($path);
    }
    if ($ext === 'xls') {
        $head = (string)file_get_contents($path, false, null, 0, 8);
        if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            require_once __DIR__ . '/xls.php';
            return xls_rows($path);
        }
        return csv_rows($path);   // some programs save a CSV / tab file with an .xls name
    }
    fail('bad_file_type');
}

function csv_rows(string $path): array
{
    $raw = (string)file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $first = strtok($raw, "\n") ?: '';
    $delim = ',';
    foreach ([';', "\t"] as $d) {
        if (substr_count($first, $d) > substr_count($first, $delim)) {
            $delim = $d;
        }
    }
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($r === [null]) {
            continue;
        }
        $rows[] = array_map(fn($c) => trim((string)$c), $r);
        if (count($rows) > 20001) {
            fail('too_many_rows');
        }
    }
    fclose($fh);
    return $rows;
}

function xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        fail('no_zip');
    }
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        fail('bad_file_type');
    }
    $shared = [];
    if (($xml = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = @simplexml_load_string($xml);
        if ($sx) {
            foreach ($sx->si as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string)$si->t;
                } else {
                    foreach ($si->r as $r) {
                        $t .= (string)$r->t;
                    }
                }
                $shared[] = $t;
            }
        }
    }
    // first worksheet in workbook order
    $sheet = 'xl/worksheets/sheet1.xml';
    $wb = $z->getFromName('xl/workbook.xml');
    $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $w = @simplexml_load_string($wb);
        $r = @simplexml_load_string($rels);
        if ($w && $r && isset($w->sheets->sheet[0])) {
            $rid = (string)$w->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($r->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $t = (string)$rel['Target'];
                    $sheet = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', $t), '/');
                }
            }
        }
    }
    $xml = $z->getFromName($sheet);
    if ($xml === false) {
        fail('bad_file_type');
    }
    $sx = @simplexml_load_string($xml);
    if (!$sx) {
        fail('bad_file_type');
    }
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = 0;
            foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $col--;
            $type = (string)$c['t'];
            if ($type === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = (string)$c->is->t;
            } else {
                $v = (string)$c->v;
                if ($v !== '' && is_numeric($v) && $type !== 'str') {
                    // avoid 1.0000000000000002 style noise from Excel floats
                    $v = (string)(0 + round((float)$v, 6));
                }
            }
            while (count($cells) < $col) {
                $cells[] = '';
            }
            $cells[$col] = trim($v);
        }
        $rows[] = $cells;
        if (count($rows) > 20001) {
            fail('too_many_rows');
        }
    }
    return $rows;
}
