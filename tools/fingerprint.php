<?php
/**
 * Fingerprints the front-end so browsers always pick up the newest CSS/JS:
 *   - every  assets/*.css|js  reference in an .html file gets  ?v=<hash of that file>
 *   - every  <app>/sw.js  gets  const FP = '<hash of everything that app loads>'  (a changed hash makes the
 *     service worker byte-different, so installed apps update themselves).
 * Used by make_release.php automatically. Standalone: php tools/fingerprint.php <folder>   (rewrites in place)
 */
declare(strict_types=1);

/** @param array<string,string> $files rel path => contents ; returns the stamped map */
function fp_apply(array $files): array
{
    $h = fn(string $d): string => substr(hash('sha256', $d), 0, 10);
    $assetHash = [];
    foreach ($files as $rel => $d) {
        if (preg_match('#^assets/[^/]+\.(css|js)$#', $rel)) {
            $assetHash[$rel] = $h($d);
        }
    }
    foreach ($files as $rel => $d) {
        if (!str_ends_with($rel, '.html')) {
            continue;
        }
        $files[$rel] = preg_replace_callback(
            '#((?:href|src)=")((?:\.\./)?)(assets/[A-Za-z0-9_.-]+\.(?:css|js))(?:\?v=[0-9a-f]+)?"#',
            fn($m) => isset($assetHash[$m[3]]) ? $m[1] . $m[2] . $m[3] . '?v=' . $assetHash[$m[3]] . '"' : $m[0],
            $d
        ) ?? $d;
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

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $dir = rtrim((string)realpath($argv[1] ?? ''), '/');
    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, "Usage: php tools/fingerprint.php <folder>\n");
        exit(1);
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = ltrim(substr($f->getPathname(), strlen($dir)), '/');
        if (preg_match('#\.(html|css|js|png|webmanifest)$#', $rel) && !str_starts_with($rel, 'tools/') && !str_starts_with($rel, 'lib/')) {
            $files[$rel] = (string)file_get_contents($f->getPathname());
        }
    }
    $new = fp_apply($files);
    $n = 0;
    foreach ($new as $rel => $d) {
        if ($d !== $files[$rel]) {
            file_put_contents($dir . '/' . $rel, $d);
            $n++;
        }
    }
    echo "Fingerprinted $n file(s).\n";
}
