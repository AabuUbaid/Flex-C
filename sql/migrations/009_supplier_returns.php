<?php
declare(strict_types=1);
/** Flex-C 1.7.0: returns of goods to a supplier (debit notes). */
return function (PDO $pdo): void {
    $col = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_ledger' AND COLUMN_NAME = 'ref_type'")->fetchColumn();
    if (is_string($col) && stripos($col, "'purchase_return'") === false) {
        $pdo->exec("ALTER TABLE stock_ledger MODIFY ref_type ENUM('sale','purchase','adjust','opening','transfer_in','transfer_out','purchase_return') NOT NULL");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_returns (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        company_id INT UNSIGNED NOT NULL,
        shop_id INT UNSIGNED NOT NULL,
        supplier_id INT UNSIGNED NOT NULL,
        note_no VARCHAR(30) NOT NULL DEFAULT '',
        bill_ref VARCHAR(60) NULL,
        return_date DATE NOT NULL,
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
        vat_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        reason VARCHAR(255) NULL,
        user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pr_company (tenant_id, company_id, return_date),
        KEY idx_pr_supplier (supplier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_return_lines (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        return_id BIGINT UNSIGNED NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        qty DECIMAL(14,3) NOT NULL,
        unit_cost DECIMAL(14,4) NOT NULL,
        vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        KEY idx_prl_return (return_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
