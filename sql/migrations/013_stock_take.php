<?php
declare(strict_types=1);
/**
 * Flex-C 1.10.0: team stock take.
 * - New role 'stocker': can only scan in an open stock take of their own shop.
 * - stock_takes: one count per shop at a time (open -> closed -> applied, or cancelled).
 * - stock_take_lines: one line per product (or per unknown barcode) per count. The UNIQUE key means
 *   two stockers scanning the same product at the same moment add to ONE line, never two.
 * - stock_take_scans: every scan, who and when. The client sends a unique id with each scan, so a scan
 *   re-sent after a network drop is never counted twice.
 */
return function (PDO $pdo): void {
    $col = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetchColumn();
    if ($col !== false && !str_contains((string)$col, "'stocker'")) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('owner','manager','accountant','cashier','purchase','stocker') NOT NULL DEFAULT 'cashier'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_takes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        company_id INT UNSIGNED NOT NULL,
        shop_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        rate_kind ENUM('price','cost') NOT NULL DEFAULT 'price',
        status ENUM('open','closed','applied','cancelled') NOT NULL DEFAULT 'open',
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_by INT UNSIGNED NULL,
        closed_at DATETIME NULL,
        applied_by INT UNSIGNED NULL,
        applied_at DATETIME NULL,
        result VARCHAR(500) NULL,
        KEY idx_st_shop (tenant_id, shop_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_take_lines (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        take_id INT UNSIGNED NOT NULL,
        tenant_id INT UNSIGNED NOT NULL,
        line_key VARCHAR(80) NOT NULL,
        code VARCHAR(64) NOT NULL,
        item_id INT UNSIGNED NULL,
        name VARCHAR(200) NOT NULL,
        rate DECIMAL(14,4) NOT NULL DEFAULT 0,
        qty DECIMAL(14,3) NOT NULL DEFAULT 0,
        first_user_id INT UNSIGNED NULL,
        first_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_user_id INT UNSIGNED NULL,
        last_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_stl (take_id, line_key),
        KEY idx_stl_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_take_scans (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        take_id INT UNSIGNED NOT NULL,
        line_id INT UNSIGNED NOT NULL,
        tenant_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        qty DECIMAL(14,3) NOT NULL,
        client_uuid CHAR(36) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sts_uuid (take_id, client_uuid),
        KEY idx_sts_line (line_id),
        KEY idx_sts_user (take_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
