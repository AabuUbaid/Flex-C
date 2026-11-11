<?php
declare(strict_types=1);
/** Flex-C 1.12.4: an Undo points at the scan it takes back, so one scan can be undone once only. */
return function (PDO $pdo): void {
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_take_scans'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('undo_of', $have, true)) {
        $pdo->exec('ALTER TABLE stock_take_scans ADD COLUMN undo_of BIGINT UNSIGNED NULL, ADD UNIQUE KEY uq_sts_undo (take_id, undo_of)');
    }
};
