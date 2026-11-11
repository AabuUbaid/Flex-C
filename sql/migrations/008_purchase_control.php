<?php
declare(strict_types=1);
/** Flex-C 1.6.0: purchase control totals, short-delivery tracking and bill proof photos. */
return function (PDO $pdo): void {
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$has('purchases', 'declared_total')) {
        $pdo->exec('ALTER TABLE purchases ADD COLUMN declared_total DECIMAL(14,2) NULL, ADD COLUMN declared_qty DECIMAL(14,3) NULL, ADD COLUMN has_short TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!$has('purchase_lines', 'recv_qty')) {
        $pdo->exec('ALTER TABLE purchase_lines ADD COLUMN recv_qty DECIMAL(14,3) NULL');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_files (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        purchase_id BIGINT UNSIGNED NOT NULL,
        mime VARCHAR(40) NOT NULL,
        data MEDIUMTEXT NOT NULL,
        user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pf_purchase (purchase_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
