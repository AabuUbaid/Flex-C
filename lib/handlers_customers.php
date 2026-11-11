<?php
declare(strict_types=1);

/** Customers captured at the POS (mobile number for WhatsApp bills and offers). */
function h_customers_list(): void
{
    $u = auth(['owner', 'manager', 'accountant']);
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT c.id, c.phone, c.name, c.offers_ok, c.visits, c.total_spent, c.first_seen, c.last_seen, s.name AS shop
              FROM customers c LEFT JOIN shops s ON s.id = c.last_shop_id WHERE c.tenant_id = ?';
    $args = [$u['tenant_id']];
    if ($q !== '') {
        $sql .= ' AND (c.phone LIKE ? OR c.name LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        array_push($args, $like, $like);
    }
    if (($_GET['offers'] ?? '') === '1') {
        $sql .= ' AND c.offers_ok = 1';
    }
    $sql .= ' ORDER BY c.last_seen DESC LIMIT 500';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['offers_ok'] = (int)$r['offers_ok'];
        $r['visits'] = (int)$r['visits'];
        $r['total_spent'] = (float)$r['total_spent'];
    }
    unset($r);
    json_out(['customers' => $rows]);
}

function h_customer_update(): void
{
    $u = auth(['owner', 'manager', 'accountant']);
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $st = db()->prepare('SELECT id FROM customers WHERE id = ? AND tenant_id = ?');
    $st->execute([$id, $u['tenant_id']]);
    if (!$st->fetchColumn()) {
        fail('not_found', 404);
    }
    db()->prepare('UPDATE customers SET offers_ok = ?, name = ? WHERE id = ? AND tenant_id = ?')
        ->execute([!empty($b['offers_ok']) ? 1 : 0, str($b['name'] ?? null, 120), $id, $u['tenant_id']]);
    audit($u, 'customer_update', "customer $id offers " . (!empty($b['offers_ok']) ? 'on' : 'off'));
    json_out(['ok' => true]);
}
