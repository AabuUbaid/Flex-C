<?php
declare(strict_types=1);
/** Flex-C 1.10.2: mark scans that a stocker confirmed as "more stock" on a product already counted (possible double count). */
return function (PDO $pdo): void {
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_take_scans'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('recount', $have, true)) {
        $pdo->exec('ALTER TABLE stock_take_scans ADD COLUMN recount TINYINT(1) NOT NULL DEFAULT 0');
    }
};
