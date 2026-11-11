<?php
declare(strict_types=1);
// Marks each customer's first owner as the PRIMARY owner: nobody but Adnys can disable, demote or reset that account.
return function (PDO $pdo): void {
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_primary', $have, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0');
    }
    $pdo->exec("UPDATE users u JOIN (SELECT tenant_id, MIN(id) AS id FROM users WHERE role = 'owner' GROUP BY tenant_id) f ON f.id = u.id SET u.is_primary = 1");
};
