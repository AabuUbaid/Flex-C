<?php
declare(strict_types=1);
// Database migrations. Standalone on purpose: the installer and the updater both load it.
// Files live in sql/migrations as NNN_name.sql or NNN_name.php and run once, in order.
// A .php migration returns a function that receives the PDO connection (for checks like "add column if missing").
// Rule for every migration: additive and safe to run twice (CREATE TABLE IF NOT EXISTS, check before ALTER).

/** Split SQL into statements. Files must not contain ';' inside string literals. */
function sql_statements(string $sql): array
{
    $sql = preg_replace('/--[^\r\n]*/', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== ''));
}

/** @return array<string,string> migration name => path, in order */
function migration_files(string $dir): array
{
    $out = [];
    foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $f) {
        if (preg_match('/^\d{3}_[a-z0-9_]+\.(sql|php)$/', basename($f))) {
            $out[basename($f)] = $f;
        }
    }
    ksort($out);
    return $out;
}

/** Run every pending migration. Returns the names applied. A failing migration is not recorded, so it is retried. */
function migrate(PDO $pdo, string $dir): array
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)');
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql && !(int)$pdo->query("SELECT GET_LOCK('flexc_migrate', 20)")->fetchColumn()) {
        throw new RuntimeException('migration_locked');
    }
    try {
        $done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = [];
        foreach (migration_files($dir) as $name => $path) {
            if (in_array($name, $done, true)) {
                continue;
            }
            if (str_ends_with($name, '.php')) {
                $fn = (static fn() => require $path)();
                if (!is_callable($fn)) {
                    throw new RuntimeException("migration $name must return a function");
                }
                $fn($pdo);
            } else {
                foreach (sql_statements((string)file_get_contents($path)) as $stmt) {
                    $pdo->exec($stmt);
                }
            }
            $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)')->execute([$name, date('Y-m-d H:i:s')]);
            $applied[] = $name;
        }
        return $applied;
    } finally {
        if ($mysql) {
            $pdo->query("SELECT RELEASE_LOCK('flexc_migrate')")->fetchColumn();
        }
    }
}

/**
 * Cheap per-request guard: only touches the database when the set of migration files changed
 * (for example right after a version was uploaded by hand). Never throws.
 */
function migrate_if_needed(PDO $pdo, string $dir, string $stateDir): void
{
    $sig = md5(implode('|', array_keys(migration_files($dir))));
    $ok = $stateDir . '/migrated.sig';
    $bad = $stateDir . '/migrate.failed';
    if (@file_get_contents($ok) === $sig) {
        return;
    }
    if (is_file($bad) && time() - (int)@filemtime($bad) < 60) {
        return;   // failed a moment ago; do not hammer the database
    }
    try {
        migrate($pdo, $dir);
        @mkdir($stateDir, 0755, true);
        @file_put_contents($ok, $sig);
        @unlink($bad);
    } catch (Throwable $e) {
        error_log('migrate: ' . $e->getMessage());
        @mkdir($stateDir, 0755, true);
        @file_put_contents($bad, $e->getMessage());
    }
}
