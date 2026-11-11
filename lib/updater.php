<?php
declare(strict_types=1);
/**
 * Signed-package updater. Pure file logic: no database, no web request, so it can be tested on its own.
 *
 * A release package is a .zip made by tools/make_release.php:
 *   manifest.json   product, version, min_from, notes, files{path: sha256}, delete[]
 *   manifest.sig    base64 Ed25519 signature of the exact bytes of manifest.json
 *   files/<path>    the new files
 * The server only accepts a package whose signature verifies against lib/update_pubkey.txt, so a stolen
 * Console login alone can never put new code on the server: the private key stays with Adnys, offline.
 */

const UPD_PRODUCT = 'Flex-C';
const UPD_MAX_ZIP = 60 * 1024 * 1024;
const UPD_MAX_UNPACKED = 120 * 1024 * 1024;
const UPD_MAX_FILES = 3000;
// These are never written, replaced or deleted by an update.
const UPD_PROTECTED = ['lib/config.php', 'lib/installed.lock', 'lib/update_pubkey.txt', 'install.php'];
const UPD_EXT = ['php', 'html', 'js', 'css', 'json', 'svg', 'webmanifest', 'sql', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'woff', 'woff2', 'map', 'md'];

final class UpdateError extends RuntimeException
{
}

function upd_path_ok(string $p): bool
{
    if ($p === '' || strlen($p) > 200 || !preg_match('/^[A-Za-z0-9._\-\/]+$/', $p)) {
        return false;
    }
    if (in_array($p, UPD_PROTECTED, true) || str_starts_with($p, 'lib/storage/') || str_starts_with($p, 'tools/')) {
        return false;
    }
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') {
            return false;
        }
        if ($seg[0] === '.' && $seg !== '.htaccess') {
            return false;
        }
    }
    $base = basename($p);
    return $base === '.htaccess' || in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), UPD_EXT, true);
}

/** Raw 32-byte public key from lib/update_pubkey.txt, or null when not set. */
function upd_pubkey(string $root): ?string
{
    $raw = @file_get_contents($root . '/lib/update_pubkey.txt');
    if ($raw === false) {
        return null;
    }
    $bin = base64_decode(trim($raw), true);
    return $bin !== false && strlen($bin) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $bin : null;
}

function upd_fingerprint(string $pub): string
{
    return strtoupper(substr(hash('sha256', $pub), 0, 16));
}

/**
 * Check a package completely before anything is written. Returns the manifest.
 * @throws UpdateError with a short code
 */
function upd_verify(string $zipPath, string $pub, string $currentVersion): array
{
    if (!is_file($zipPath) || filesize($zipPath) > UPD_MAX_ZIP) {
        throw new UpdateError('package_too_big');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new UpdateError('not_a_zip');
    }
    try {
        if ($zip->numFiles < 3 || $zip->numFiles > UPD_MAX_FILES + 4) {
            throw new UpdateError('bad_package');
        }
        $mj = $zip->getFromName('manifest.json');
        $sig = $zip->getFromName('manifest.sig');
        if ($mj === false || $sig === false || strlen($mj) > 2_000_000) {
            throw new UpdateError('bad_package');
        }
        $sigBin = base64_decode(trim($sig), true);
        if ($sigBin === false || strlen($sigBin) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sigBin, $mj, $pub)) {
            throw new UpdateError('bad_signature');
        }
        $m = json_decode($mj, true);
        if (!is_array($m) || ($m['product'] ?? '') !== UPD_PRODUCT || !preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', (string)($m['version'] ?? ''))
            || !is_array($m['files'] ?? null) || !$m['files'] || count($m['files']) > UPD_MAX_FILES) {
            throw new UpdateError('bad_manifest');
        }
        if (version_compare($m['version'], $currentVersion, '<=')) {
            throw new UpdateError('not_newer');
        }
        $minFrom = (string)($m['min_from'] ?? '0.0.0');
        if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $minFrom) || version_compare($currentVersion, $minFrom, '<')) {
            throw new UpdateError('needs_earlier_version');
        }
        foreach ($m['files'] as $path => $hash) {
            if (!is_string($path) || !upd_path_ok($path) || !is_string($hash) || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
                throw new UpdateError('bad_path');
            }
        }
        foreach ((array)($m['delete'] ?? []) as $d) {
            if (!is_string($d) || !upd_path_ok($d) || isset($m['files'][$d])) {
                throw new UpdateError('bad_path');
            }
        }
        // Every entry in the zip must be accounted for in the signed manifest, and must match its hash.
        $seen = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = (string)$st['name'];
            if ($name === 'manifest.json' || $name === 'manifest.sig' || str_ends_with($name, '/')) {
                continue;
            }
            if (!str_starts_with($name, 'files/')) {
                throw new UpdateError('unexpected_entry');
            }
            $rel = substr($name, 6);
            if (isset($seen[$rel]) || !isset($m['files'][$rel])) {
                throw new UpdateError('unexpected_entry');
            }
            $total += (int)$st['size'];
            if ($total > UPD_MAX_UNPACKED) {
                throw new UpdateError('package_too_big');
            }
            $data = $zip->getFromIndex($i);
            if ($data === false || !hash_equals($m['files'][$rel], hash('sha256', $data))) {
                throw new UpdateError('hash_mismatch');
            }
            $seen[$rel] = true;
        }
        if (count($seen) !== count($m['files'])) {
            throw new UpdateError('missing_files');
        }
        $vj = json_decode((string)$zip->getFromName('files/version.json'), true);
        if (!is_array($vj) || ($vj['version'] ?? '') !== $m['version']) {
            throw new UpdateError('version_file_mismatch');
        }
        return $m;
    } finally {
        $zip->close();
    }
}

function upd_write(string $dest, string $data): void
{
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new UpdateError('not_writable');
    }
    $tmp = $dest . '.flexc-tmp';
    if (@file_put_contents($tmp, $data) === false) {
        throw new UpdateError('not_writable');
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        throw new UpdateError('not_writable');
    }
}

/**
 * Back up what will change, then write the new files and remove deleted ones.
 * If anything fails half way, the backup is restored and the error is rethrown.
 * @return array{backup:string,changed:int}
 */
function upd_apply(string $root, string $zipPath, array $m, string $fromVersion, string $backupDir): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new UpdateError('not_a_zip');
    }
    $backup = '';
    try {
        $change = [];
        $created = [];
        foreach ($m['files'] as $path => $hash) {
            $dest = $root . '/' . $path;
            if (is_file($dest)) {
                if (hash_equals($hash, (string)hash_file('sha256', $dest))) {
                    continue;   // identical, nothing to do
                }
            } else {
                $created[] = $path;
            }
            $change[] = $path;
        }
        $remove = [];
        foreach ((array)($m['delete'] ?? []) as $d) {
            if (is_file($root . '/' . $d)) {
                $remove[] = $d;
            }
        }

        if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new UpdateError('not_writable');
        }
        $backup = $backupDir . '/' . $fromVersion . '_to_' . $m['version'] . '_' . date('YmdHis') . '.zip';
        $bz = new ZipArchive();
        if ($bz->open($backup, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UpdateError('not_writable');
        }
        foreach (array_merge($change, $remove) as $path) {
            if (is_file($root . '/' . $path)) {
                $bz->addFile($root . '/' . $path, 'files/' . $path);
            }
        }
        $bz->addFromString('created.json', json_encode($created));
        if (!$bz->close() || !is_file($backup)) {
            throw new UpdateError('backup_failed');
        }

        foreach ($change as $path) {
            $data = $zip->getFromName('files/' . $path);
            if ($data === false) {
                throw new UpdateError('missing_files');
            }
            upd_write($root . '/' . $path, $data);
        }
        foreach ($remove as $path) {
            @unlink($root . '/' . $path);
        }
        clearstatcache();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        return ['backup' => basename($backup), 'changed' => count($change) + count($remove)];
    } catch (Throwable $e) {
        if ($backup !== '' && is_file($backup)) {
            try {
                upd_restore($root, $backup);
            } catch (Throwable $ignore) {
                // nothing more we can do; the backup zip is still on disk for manual recovery
            }
        }
        throw $e;
    } finally {
        $zip->close();
    }
}

/** Put the files from a backup zip back, and remove files the update had newly created. */
function upd_restore(string $root, string $backupZip): int
{
    $zip = new ZipArchive();
    if ($zip->open($backupZip) !== true) {
        throw new UpdateError('backup_unreadable');
    }
    $n = 0;
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!str_starts_with($name, 'files/') || str_ends_with($name, '/')) {
                continue;
            }
            $rel = substr($name, 6);
            if (!upd_path_ok($rel)) {
                continue;
            }
            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                upd_write($root . '/' . $rel, $data);
                $n++;
            }
        }
        foreach ((array)json_decode((string)$zip->getFromName('created.json'), true) as $rel) {
            if (is_string($rel) && upd_path_ok($rel) && is_file($root . '/' . $rel)) {
                @unlink($root . '/' . $rel);
                $n++;
            }
        }
    } finally {
        $zip->close();
    }
    clearstatcache();
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    return $n;
}

function upd_prune_backups(string $backupDir, int $keep = 5): void
{
    $files = glob($backupDir . '/*.zip') ?: [];
    rsort($files);
    foreach (array_slice($files, $keep) as $f) {
        @unlink($f);
    }
}
