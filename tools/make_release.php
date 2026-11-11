<?php
declare(strict_types=1);
/**
 * Flex-C release builder. Run on YOUR computer (or over SSH), never needs a database.
 *
 *   php tools/make_release.php keygen [keyfile]
 *       Creates your signing key pair once. Prints the PUBLIC key (paste it in Console > Versions)
 *       and saves the PRIVATE key to keyfile (default adnys-release.key). Keep that file offline and backed up.
 *
 *   php tools/make_release.php build 1.2.0 [--key=adnys-release.key] [--notes="What changed"]
 *                                          [--min-from=1.0.0] [--delete=old/file.php,other.js] [--out=dir]
 *       Packs this project into flex-c-1.2.0.zip, signed with your key. Upload it in Console > Versions.
 *       The version number is stamped into version.json, assets/app.js, pos/index.html and pos/sw.js for you.
 *
 * Needs the PHP sodium and zip extensions (both are standard).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/updater.php';

if (!function_exists('sodium_crypto_sign_detached') || !class_exists('ZipArchive')) {
    fwrite(STDERR, "This tool needs the PHP sodium and zip extensions.\n");
    exit(1);
}

function out(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}

function die_with(string $s): never
{
    fwrite(STDERR, "Error: $s\n");
    exit(1);
}

function load_secret(string $file): string
{
    $raw = @file_get_contents($file);
    $bin = $raw === false ? false : base64_decode(trim($raw), true);
    if ($bin === false || strlen($bin) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        die_with("Cannot read a signing key from $file. Run keygen first.");
    }
    return $bin;
}

$argvList = $argv;
array_shift($argvList);
$cmd = $argvList[0] ?? '';

if ($cmd === 'keygen') {
    $file = $argvList[1] ?? 'adnys-release.key';
    if (file_exists($file)) {
        die_with("$file already exists. Refusing to overwrite your signing key.");
    }
    $kp = sodium_crypto_sign_keypair();
    file_put_contents($file, base64_encode(sodium_crypto_sign_secretkey($kp)) . "\n");
    @chmod($file, 0600);
    $pub = sodium_crypto_sign_publickey($kp);
    out("Private key saved to: $file   (KEEP THIS FILE SAFE AND OFFLINE. Anyone holding it can push code to your servers.)");
    out('');
    out('PUBLIC key (paste into Console > Versions > one-time setup):');
    out(base64_encode($pub));
    out('');
    out('Fingerprint (the Console shows the same): ' . upd_fingerprint($pub));
    exit(0);
}

if ($cmd !== 'build') {
    die_with("Usage:\n  php tools/make_release.php keygen [keyfile]\n  php tools/make_release.php build <version> [--key=file] [--notes=text] [--min-from=1.0.0] [--delete=a,b] [--out=dir]");
}

$version = $argvList[1] ?? '';
if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $version)) {
    die_with('Give a version like 1.2.0');
}
$opt = ['key' => 'adnys-release.key', 'notes' => '', 'min-from' => '1.0.0', 'delete' => '', 'out' => '.', 'src' => dirname(__DIR__)];
foreach (array_slice($argvList, 2) as $a) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } else {
        die_with("Unknown option: $a");
    }
}
if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $opt['min-from'])) {
    die_with('--min-from must look like 1.0.0');
}
$secret = load_secret($opt['key']);
$src = rtrim((string)realpath($opt['src']), '/');
if ($src === '' || !is_file($src . '/version.json')) {
    die_with('Source folder must contain version.json');
}

// Files stamped with the version being built (done in memory; the source tree is not touched).
$stamp = [
    'version.json' => fn(string $c) => json_encode(['product' => UPD_PRODUCT, 'version' => $version]) . "\n",
    'assets/app.js' => fn(string $c) => preg_replace("/const APP_VERSION = '[0-9.]+';/", "const APP_VERSION = '$version';", $c, 1) ?? $c,
    'pos/index.html' => fn(string $c) => preg_replace("/const APP_VERSION = '[0-9.]+';/", "const APP_VERSION = '$version';", $c, 1) ?? $c,
    'pos/sw.js' => fn(string $c) => preg_replace("/const V = 'flexc-pos-v[0-9.]+';/", "const V = 'flexc-pos-v$version';", $c, 1) ?? $c,
];

$files = [];
$skipped = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS));
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($src))), '/');
    if (str_starts_with($rel, '.git/') || str_ends_with($rel, '.zip') || str_ends_with($rel, '.key') || basename($rel) === '.DS_Store' || $rel === 'README.md') {
        continue;
    }
    if (!upd_path_ok($rel)) {
        $skipped[] = $rel;   // install.php, tools/, config, lock, storage: by design never shipped
        continue;
    }
    $data = (string)file_get_contents($f->getPathname());
    if (isset($stamp[$rel])) {
        $new = $stamp[$rel]($data);
        if ($new === $data && $rel !== 'version.json' && !str_contains($data, $version)) {
            fwrite(STDERR, "Warning: could not stamp version in $rel\n");
        }
        $data = $new;
    }
    $files[$rel] = $data;
}
require __DIR__ . '/fingerprint.php';
$files = fp_apply($files);   // ?v=hash on CSS/JS, service-worker fingerprints
ksort($files);
foreach (array_keys($stamp) as $must) {
    if (!isset($files[$must])) {
        die_with("$must is missing from the source tree");
    }
}

$delete = array_values(array_filter(array_map('trim', explode(',', $opt['delete']))));
$manifest = [
    'product' => UPD_PRODUCT,
    'version' => $version,
    'min_from' => $opt['min-from'],
    'notes' => $opt['notes'],
    'created' => date('c'),
    'files' => array_map(fn($d) => hash('sha256', $d), $files),
    'delete' => $delete,
];
$mj = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$sig = base64_encode(sodium_crypto_sign_detached($mj, $secret));

@mkdir($opt['out'], 0755, true);
$zipPath = rtrim($opt['out'], '/') . "/flex-c-$version.zip";
@unlink($zipPath);
$z = new ZipArchive();
if ($z->open($zipPath, ZipArchive::CREATE) !== true) {
    die_with("Cannot write $zipPath");
}
$z->addFromString('manifest.json', $mj);
$z->addFromString('manifest.sig', $sig);
foreach ($files as $rel => $data) {
    $z->addFromString('files/' . $rel, $data);
}
$z->close();

// Prove the package is acceptable before you upload it.
$pub = sodium_crypto_sign_publickey_from_secretkey($secret);
// Check it the way a server running the oldest allowed version (min-from) would.
$probe = version_compare($opt['min-from'], $version, '<') ? $opt['min-from'] : '0.0.0';
try {
    upd_verify($zipPath, $pub, $probe);
} catch (UpdateError $e) {
    @unlink($zipPath);
    die_with('Self-check failed: ' . $e->getMessage());
}
out("Built $zipPath");
out('  version      ' . $version . '   (min upgrade-from ' . $opt['min-from'] . ')');
out('  files        ' . count($files) . ', ' . round(filesize($zipPath) / 1024) . ' KB');
out('  signed with  key ' . upd_fingerprint($pub));
if ($skipped) {
    out('  not shipped  ' . implode(', ', array_slice($skipped, 0, 8)) . (count($skipped) > 8 ? ', ...' : ''));
}
out('Upload it in Console > Versions > Upload and install.');
