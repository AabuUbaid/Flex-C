<?php
declare(strict_types=1);
/** Flex-C 1.12.3: fuller supplier record (contact, address, emirate) and TRN verification (who checked it on the FTA site, when, legal name). */
return function (PDO $pdo): void {
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers'")->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'contact_name' => 'VARCHAR(120) NULL', 'email' => 'VARCHAR(190) NULL', 'address' => 'VARCHAR(255) NULL', 'emirate' => 'VARCHAR(40) NULL',
        'po_box' => 'VARCHAR(20) NULL', 'notes' => 'VARCHAR(255) NULL', 'trn_legal_name' => 'VARCHAR(190) NULL',
        'trn_verified_at' => 'DATETIME NULL', 'trn_verified_by' => 'INT UNSIGNED NULL',
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN $col $def");
        }
    }
};
