<?php
declare(strict_types=1);
/** Flex-C 1.4.1: shop admin can switch the bill QR on or off. */
return function (PDO $pdo): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_settings' AND COLUMN_NAME = 'qr_enabled'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE tenant_settings ADD COLUMN qr_enabled TINYINT(1) NOT NULL DEFAULT 1');
    }
};
