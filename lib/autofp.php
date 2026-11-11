<?php
declare(strict_types=1);
/*
 * Automatic fingerprints. Every page loads assets/*.css|js as  file.css?v=<hash of the file>  and every app's sw.js
 * carries  const FP = '<hash of what that app loads>'. The release builder stamps them, but a file uploaded or edited
 * by hand (File Manager) would keep the old ?v=, and browsers would keep showing the old CSS / JS.
 * So on each API call (every page visit makes one) we compare a cheap signature of the files (size + time);
 * when something changed, the pages and service workers are re-stamped. Installed apps then see a new service
 * worker and reload by themselves. Never throws; if files are not writable it simply does nothing.
 */

function autofp_files(string $root): array
{
    $list = array_merge(
        glob($root . '/assets/*.css') ?: [], glob($root . '/assets/*.js') ?: [], glob($root . '/assets/icons/*-*.png') ?: [],
        glob($root . '/*.html') ?: [], glob($root . '/*/index.html') ?: [], glob($root . '/*/sw.js') ?: [], glob($root . '/*/manifest.webmanifest') ?: []
    );
    $out = [];
    foreach ($list as $f) {
        $rel = ltrim(substr($f, strlen($root)), '/');
        if (!str_starts_with($rel, 'lib/') && !str_starts_with($rel, 'tools/') && !str_starts_with($rel, 'sql/')) {
            $out[$rel] = $f;
        }
    }
    ksort($out);
    return $out;
}

function autofp_signature(array $files): string
{
    clearstatcache();
    $s = '';
    foreach ($files as $rel => $f) {
        $s .= $rel . ':' . @filesize($f) . ':' . @filemtime($f) . '|';
    }
    return md5($s);
}

/** Same rules as tools/fingerprint.php (kept here because tools/ is not shipped to servers). */
function autofp_apply(array $files): array
{
    $h = fn(string $d): string => substr(hash('sha256', $d), 0, 10);
    $assetHash = [];
    foreach ($files as $rel => $d) {
        if (preg_match('#^assets/[^/]+\.(css|js)$#', $rel)) {
            $assetHash[$rel] = $h($d);
        }
    }
    foreach ($files as $rel => $d) {
        if (str_ends_with($rel, '.html')) {
            $files[$rel] = preg_replace_callback(
                '#((?:href|src)=")((?:\.\./)?)(assets/[A-Za-z0-9_.-]+\.(?:css|js))(?:\?v=[0-9a-f]+)?"#',
                fn($m) => isset($assetHash[$m[3]]) ? $m[1] . $m[2] . $m[3] . '?v=' . $assetHash[$m[3]] . '"' : $m[0],
                $d
            ) ?? $d;
        }
    }
    foreach ($files as $rel => $d) {
        if (!preg_match('#^([a-z]+)/sw\.js$#', $rel, $m)) {
            continue;
        }
        $parts = [];
        foreach ($files as $r2 => $d2) {
            if (str_starts_with($r2, $m[1] . '/') && $r2 !== $rel) {
                $parts[] = $r2 . ':' . $d2;
            }
            if (preg_match('#^assets/(?:[^/]+\.(?:css|js)|icons/' . $m[1] . '-[^/]+)$#', $r2)) {
                $parts[] = $r2 . ':' . $d2;
            }
        }
        sort($parts);
        $fp = $h(implode("\n", $parts));
        $files[$rel] = preg_replace("/const FP = '[0-9a-f]*';/", "const FP = '$fp';", $d, 1) ?? $d;
    }
    return $files;
}

function autofp_if_needed(string $root, string $stateDir): void
{
    try {
        $paths = autofp_files($root);
        $sig = autofp_signature($paths);
        $state = $stateDir . '/fingerprint.sig';
        if (@file_get_contents($state) === $sig) {
            return;
        }
        @mkdir($stateDir, 0755, true);
        $lock = @fopen($stateDir . '/fingerprint.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            return;   // another request is stamping right now
        }
        $data = [];
        foreach ($paths as $rel => $f) {
            $data[$rel] = (string)@file_get_contents($f);
        }
        $new = autofp_apply($data);
        foreach ($new as $rel => $d) {
            if ($d !== $data[$rel] && is_writable($paths[$rel])) {
                $tmp = $paths[$rel] . '.fp-tmp';
                if (@file_put_contents($tmp, $d) !== false) {
                    @rename($tmp, $paths[$rel]);
                }
            }
        }
        @file_put_contents($state, autofp_signature(autofp_files($root)));
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (Throwable $e) {
        error_log('autofp: ' . $e->getMessage());
    }
}
