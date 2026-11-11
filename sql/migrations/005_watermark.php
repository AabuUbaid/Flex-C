<?php
declare(strict_types=1);
/** Flex-C 1.3.1: POS logo watermark opacity (percent, 0 = off). */
return function (PDO $pdo): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_settings' AND COLUMN_NAME = 'watermark_opacity'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE tenant_settings ADD COLUMN watermark_opacity TINYINT UNSIGNED NULL');
    }
};
