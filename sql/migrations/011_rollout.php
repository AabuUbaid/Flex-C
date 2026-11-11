<?php
declare(strict_types=1);
// Staged roll-out: each customer is either 'auto' (gets every release at once, used for the demo company)
// or 'push' (Adnys admin pushes a version to them from the Console). Existing customers start fully pushed.
return function (PDO $pdo): void {
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'")->fetchAll(PDO::FETCH_COLUMN);
    $add = ['update_mode' => "VARCHAR(8) NOT NULL DEFAULT 'push'", 'pushed_version' => 'VARCHAR(20) NULL', 'is_demo' => 'TINYINT(1) NOT NULL DEFAULT 0'];
    foreach ($add as $c => $def) {
        if (!in_array($c, $have, true)) {
            $pdo->exec("ALTER TABLE tenants ADD COLUMN $c $def");
        }
    }
    $v = null;
    if (!$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_releases'")->fetchColumn()) {
        return;
    }
    foreach ($pdo->query('SELECT version FROM app_releases WHERE retired = 0')->fetchAll(PDO::FETCH_COLUMN) as $x) {
        if ($v === null || version_compare($x, $v, '>')) {
            $v = $x;
        }
    }
    if ($v !== null) {
        $pdo->prepare('UPDATE tenants SET pushed_version = ? WHERE pushed_version IS NULL')->execute([$v]);
    }
};
