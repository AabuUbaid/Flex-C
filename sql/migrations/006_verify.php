<?php
declare(strict_types=1);
/** Flex-C 1.4.0: public verify links. Purchases get a secret token (sales use their own UUID). */
return function (PDO $pdo): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'verify_token'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE purchases ADD COLUMN verify_token CHAR(24) NULL');
    }
    $up = $pdo->prepare('UPDATE purchases SET verify_token = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id FROM purchases WHERE verify_token IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $up->execute([bin2hex(random_bytes(12)), $id]);
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND INDEX_NAME = 'uq_purchase_verify'");
    $st->execute();
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE purchases ADD UNIQUE KEY uq_purchase_verify (verify_token)');
    }
};
