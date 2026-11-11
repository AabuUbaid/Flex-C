<?php
declare(strict_types=1);
/**
 * Flex-C 1.2.0 "POS Pro" migration.
 * Adds: usernames (login without email), shop/company logos, branch group codes,
 * return authorisation, low-stock notifications, daily cash closings and tenant settings.
 * Additive and safe to run twice.
 */
return function (PDO $pdo): void {
    $hasCol = function (string $table, string $col) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    };
    $hasIdx = function (string $table, string $idx) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $st->execute([$table, $idx]);
        return (int)$st->fetchColumn() > 0;
    };
    $addCol = function (string $table, string $col, string $def) use ($pdo, $hasCol): void {
        if (!$hasCol($table, $col)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    };

    // ---- users: username login (email becomes optional) ----
    $addCol('users', 'username', 'VARCHAR(40) NULL AFTER name');
    // A user may now be created without an email address and sign in with the generated username.
    $pdo->exec("ALTER TABLE users MODIFY email VARCHAR(190) NULL");
    // Purchase team role: receives low-stock / purchasing alerts.
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('owner','manager','accountant','cashier','purchase') NOT NULL DEFAULT 'cashier'");

    // Backfill usernames for existing users: SHOPCODE + first 3 letters of name + 6 digits.
    if ($hasCol('users', 'username')) {
        $rows = $pdo->query(
            'SELECT u.id, u.tenant_id, u.name, s.code AS shop_code
               FROM users u LEFT JOIN shops s ON s.id = u.shop_id
              WHERE u.username IS NULL OR u.username = \'\'
              ORDER BY u.id'
        )->fetchAll();
        if ($rows) {
            $used = [];
            $upd = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?');
            foreach ($rows as $r) {
                $base = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($r['shop_code'] ?? '')));
                if ($base === '') {
                    $base = 'SHOP';
                }
                $base = substr($base, 0, 8);
                $nm = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$r['name']));
                $nm = substr($nm === '' ? 'USR' : $nm, 0, 3);
                $tid = (int)$r['tenant_id'];
                $i = (int)$r['id'];
                do {
                    $un = $base . $nm . str_pad((string)$i, 6, '0', STR_PAD_LEFT);
                    $i += 100000;
                } while (isset($used[$tid][$un]));
                $used[$tid][$un] = true;
                $upd->execute([$un, (int)$r['id']]);
            }
        }
    }
    if (!$hasIdx('users', 'uq_user_username')) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_user_username (tenant_id, username)');
    }

    // ---- shops / companies: logo and branch group ----
    $addCol('shops', 'logo', 'MEDIUMTEXT NULL');
    $addCol('shops', 'group_code', 'VARCHAR(10) NULL');
    $addCol('companies', 'logo', 'MEDIUMTEXT NULL');
    if ($hasCol('shops', 'group_code')) {
        $rows = $pdo->query('SELECT id, code FROM shops WHERE group_code IS NULL OR group_code = ""')->fetchAll();
        if ($rows) {
            $upd = $pdo->prepare('UPDATE shops SET group_code = ? WHERE id = ?');
            foreach ($rows as $r) {
                $g = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$r['code']));
                $g = (string)preg_replace('/\d+$/', '', $g);
                $upd->execute([$g === '' ? null : substr($g, 0, 10), (int)$r['id']]);
            }
        }
    }

    // ---- sales: who authorised a return / void ----
    $addCol('sales', 'authorised_by', 'INT UNSIGNED NULL AFTER user_id');
    $addCol('sales', 'auth_ref', 'VARCHAR(40) NULL AFTER authorised_by');

    // ---- payments: allow bank transfers as well ----
    $pdo->exec("ALTER TABLE payments MODIFY method ENUM('cash','card','bank','other') NOT NULL");

    // ---- tenant settings ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_settings (
        tenant_id INT UNSIGNED NOT NULL PRIMARY KEY,
        low_stock_threshold DECIMAL(14,3) NOT NULL DEFAULT 10,
        share_email VARCHAR(190) NULL,
        share_whatsapp VARCHAR(40) NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'AED',
        receipt_note VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- one-time return / discount authorisations ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS authorisations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        purpose VARCHAR(30) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_auth_token (token_hash),
        KEY idx_auth_exp (expires_at),
        FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- low stock / purchasing notifications ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        company_id INT UNSIGNED NULL,
        shop_id INT UNSIGNED NULL,
        item_id INT UNSIGNED NULL,
        type VARCHAR(30) NOT NULL DEFAULT 'low_stock',
        level ENUM('info','warn','bad') NOT NULL DEFAULT 'warn',
        title VARCHAR(190) NOT NULL,
        detail VARCHAR(500) NULL,
        qty DECIMAL(14,3) NULL,
        status ENUM('new','read','resolved') NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_notif (tenant_id, status, created_at),
        KEY idx_notif_item (shop_id, item_id, type, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- daily cash closing (Z report) ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_closings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        company_id INT UNSIGNED NOT NULL,
        shop_id INT UNSIGNED NOT NULL,
        register_id INT UNSIGNED NOT NULL,
        close_date DATE NOT NULL,
        opening_float DECIMAL(14,2) NOT NULL DEFAULT 0,
        cash_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
        card_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
        bank_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
        other_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
        returns_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        net_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
        vat_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        expected_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
        counted_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
        difference DECIMAL(14,2) NOT NULL DEFAULT 0,
        txn_count INT NOT NULL DEFAULT 0,
        notes VARCHAR(500) NULL,
        closed_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_close (register_id, close_date),
        KEY idx_close_shop (shop_id, close_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
