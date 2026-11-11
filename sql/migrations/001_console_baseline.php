<?php
declare(strict_types=1);
// Brings any Flex-C database up to the "Console" level: tenant columns plus the staff, release and audit tables.
// Replaces the old one-time upgrade_console.php. Safe on fresh installs and on installs that already ran it.
return function (PDO $pdo): void {
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'plan'          => "VARCHAR(30) NOT NULL DEFAULT 'standard'",
        'contact_name'  => 'VARCHAR(120) NULL',
        'contact_email' => 'VARCHAR(190) NULL',
        'contact_phone' => 'VARCHAR(40) NULL',
        'notes'         => 'VARCHAR(1000) NULL',
        'last_version'  => 'VARCHAR(20) NULL',
        'last_seen_at'  => 'DATETIME NULL',
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE tenants ADD COLUMN $col $def");
        }
    }
    foreach (sql_statements((string)file_get_contents(__DIR__ . '/../console.sql')) as $stmt) {
        $pdo->exec($stmt);
    }
};
