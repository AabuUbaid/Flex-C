<?php
declare(strict_types=1);
/** Flex-C 1.7.1: history of printed barcode labels. */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS label_prints (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        company_id INT UNSIGNED NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        code VARCHAR(80) NOT NULL,
        copies INT UNSIGNED NOT NULL,
        size_key VARCHAR(10) NOT NULL DEFAULT '',
        user_id INT UNSIGNED NULL,
        printed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lp_company (tenant_id, company_id, printed_at),
        KEY idx_lp_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
