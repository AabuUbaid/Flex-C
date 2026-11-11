<?php
declare(strict_types=1);
/**
 * Flex-C 1.3.0: customer mobile numbers (for WhatsApp bills and offers), thank-you message and bill QR settings.
 * Additive and safe to run twice.
 */
return function (PDO $pdo): void {
    $hasCol = function (string $table, string $col) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    };
    $addCol = function (string $table, string $col, string $def) use ($pdo, $hasCol): void {
        if (!$hasCol($table, $col)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    };
    $addCol('sales', 'customer_phone', 'VARCHAR(20) NULL');
    $addCol('tenant_settings', 'thank_you', 'VARCHAR(255) NULL');
    $addCol('tenant_settings', 'qr_url', 'VARCHAR(255) NULL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        phone VARCHAR(20) NOT NULL,
        name VARCHAR(120) NULL,
        offers_ok TINYINT(1) NOT NULL DEFAULT 0,
        visits INT UNSIGNED NOT NULL DEFAULT 0,
        total_spent DECIMAL(14,2) NOT NULL DEFAULT 0,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        last_shop_id INT UNSIGNED NULL,
        UNIQUE KEY uq_customer (tenant_id, phone),
        KEY idx_customer_seen (tenant_id, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
