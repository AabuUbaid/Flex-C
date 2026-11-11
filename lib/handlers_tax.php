<?php
declare(strict_types=1);

/** UAE VAT return figures for one company and period (output VAT, input VAT, net payable). */
function h_vat_return(): void
{
    $u = auth(['owner', 'manager', 'accountant']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-01'));
    $t = (int)$u['tenant_id'];
    $pdo = db();
    $q = function (string $sql, array $a) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($a);
        return $st->fetchAll();
    };
    $args = [$t, $cid, $from, $to];
    // Sales: sale_lines hold VAT-inclusive totals; returns are stored as negative lines, so they net off.
    $sales = $q(
        "SELECT s.type, l.vat_rate, SUM(l.line_total - l.vat_amount) AS net, SUM(l.vat_amount) AS vat, COUNT(DISTINCT s.id) AS docs
           FROM sales s JOIN sale_lines l ON l.sale_id = s.id
          WHERE s.tenant_id = ? AND s.company_id = ? AND DATE(s.sold_at) BETWEEN ? AND ? GROUP BY s.type, l.vat_rate",
        $args
    );
    $purch = $q(
        'SELECT l.vat_rate, SUM(l.line_total - l.vat_amount) AS net, SUM(l.vat_amount) AS vat, COUNT(DISTINCT p.id) AS docs
           FROM purchases p JOIN purchase_lines l ON l.purchase_id = p.id
          WHERE p.tenant_id = ? AND p.company_id = ? AND p.bill_date BETWEEN ? AND ? GROUP BY l.vat_rate',
        $args
    );
    $pret = $q(
        'SELECT l.vat_rate, SUM(l.line_total - l.vat_amount) AS net, SUM(l.vat_amount) AS vat, COUNT(DISTINCT r.id) AS docs
           FROM purchase_returns r JOIN purchase_return_lines l ON l.return_id = r.id
          WHERE r.tenant_id = ? AND r.company_id = ? AND r.return_date BETWEEN ? AND ? GROUP BY l.vat_rate',
        $args
    );
    $exp = $q(
        'SELECT COUNT(*) AS docs, COALESCE(SUM(amount - vat_amount), 0) AS net, COALESCE(SUM(vat_amount), 0) AS vat
           FROM expenses WHERE tenant_id = ? AND company_id = ? AND expense_date BETWEEN ? AND ? AND vat_amount > 0',
        $args
    )[0];
    $r2 = fn($v) => round((float)$v, 2);
    $std = ['net' => 0.0, 'vat' => 0.0, 'docs' => 0];
    $ret = ['net' => 0.0, 'vat' => 0.0, 'docs' => 0];
    $zero = ['net' => 0.0, 'vat' => 0.0, 'docs' => 0];
    foreach ($sales as $r) {
        $isStd = (float)$r['vat_rate'] > 0;
        $bucket = !$isStd ? 'zero' : ($r['type'] === 'return' ? 'ret' : 'std');
        $$bucket['net'] += (float)$r['net'];
        $$bucket['vat'] += (float)$r['vat'];
        $$bucket['docs'] += (int)$r['docs'];
    }
    $pStd = ['net' => 0.0, 'vat' => 0.0, 'docs' => 0];
    $pZero = ['net' => 0.0, 'vat' => 0.0, 'docs' => 0];
    foreach ($purch as $r) {
        $k = (float)$r['vat_rate'] > 0 ? 'pStd' : 'pZero';
        $$k['net'] += (float)$r['net'];
        $$k['vat'] += (float)$r['vat'];
        $$k['docs'] += (int)$r['docs'];
    }
    $pr = ['net' => 0.0, 'vat' => 0.0, 'docs' => 0];
    foreach ($pret as $r) {
        if ((float)$r['vat_rate'] > 0) {
            $pr['net'] += (float)$r['net'];
            $pr['vat'] += (float)$r['vat'];
            $pr['docs'] += (int)$r['docs'];
        }
    }
    // Sales returns are already negative in sale_lines, so output VAT = standard sales + (negative) returns.
    $outNet = $std['net'] + $ret['net'];
    $outVat = $std['vat'] + $ret['vat'];
    $inNet = $pStd['net'] - $pr['net'] + (float)$exp['net'];
    $inVat = $pStd['vat'] - $pr['vat'] + (float)$exp['vat'];
    $round = fn(array $a) => ['net' => round($a['net'], 2), 'vat' => round($a['vat'], 2), 'docs' => $a['docs']];
    json_out([
        'from' => $from, 'to' => $to,
        'output' => [
            'standard' => $round($std), 'returns' => $round($ret), 'zero_exempt' => $round($zero),
            'total_net' => round($outNet, 2), 'total_vat' => round($outVat, 2),
        ],
        'input' => [
            'purchases' => $round($pStd), 'purchases_zero_exempt' => $round($pZero), 'supplier_returns' => $round($pr),
            'expenses' => ['net' => $r2($exp['net']), 'vat' => $r2($exp['vat']), 'docs' => (int)$exp['docs']],
            'total_net' => round($inNet, 2), 'total_vat' => round($inVat, 2),
        ],
        'net_payable' => round($outVat - $inVat, 2),
    ]);
}

/** Return goods to a supplier: reduces stock and what is owed (a debit note). */
function h_preturn_save(): void
{
    $u = auth(['owner', 'manager', 'accountant'], true);
    $b = body();
    $cid = (int)($b['company_id'] ?? 0);
    assert_company($u, $cid);
    $shop = assert_shop($u, (int)($b['shop_id'] ?? 0));
    if ((int)$shop['company_id'] !== $cid) {
        fail('shop_company_mismatch');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? AND tenant_id = ? AND company_id = ?');
    $st->execute([(int)($b['supplier_id'] ?? 0), $u['tenant_id'], $cid]);
    $supplierId = (int)$st->fetchColumn();
    if (!$supplierId) {
        fail('supplier_not_found', 404);
    }
    $lines = $b['lines'] ?? [];
    if (!is_array($lines) || !$lines || count($lines) > 200) {
        fail('bad_lines');
    }
    $date = vdate($b['return_date'] ?? '', date('Y-m-d'));
    $ids = array_values(array_unique(array_map(fn($l) => (int)($l['item_id'] ?? 0), $lines)));
    $st = $pdo->prepare('SELECT id, name, vat_rate, cost FROM items WHERE tenant_id = ? AND company_id = ? AND id IN (' . placeholders(count($ids)) . ')');
    $st->execute(array_merge([$u['tenant_id'], $cid], $ids));
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[(int)$r['id']] = $r;
    }
    $calc = [];
    $want = [];
    $sub = $vatSum = $total = 0.0;
    foreach ($lines as $l) {
        $iid = (int)($l['item_id'] ?? 0);
        if (!isset($items[$iid])) {
            fail('unknown_item');
        }
        $qty = round(num($l['qty'] ?? 0, 0.001, 1000000), 3);
        $cost = round(num($l['unit_cost'] ?? 0, 0, 10000000), 4);
        $rate = isset($l['vat_rate']) && $l['vat_rate'] !== '' ? round(num($l['vat_rate'], 0, 100), 2) : (float)$items[$iid]['vat_rate'];
        $net = round($qty * $cost, 2);
        $vat = round($net * $rate / 100, 2);
        $calc[] = [$iid, $qty, $cost, $rate, $vat, round($net + $vat, 2)];
        $want[$iid] = ($want[$iid] ?? 0) + $qty;
        $sub += $net;
        $vatSum += $vat;
        $total += $net + $vat;
    }
    $sub = round($sub, 2);
    $vatSum = round($vatSum, 2);
    $total = round($total, 2);
    $pdo->beginTransaction();
    $onHand = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? AND item_id = ? FOR UPDATE');
    foreach ($want as $iid => $q) {
        $onHand->execute([$u['tenant_id'], $shop['id'], $iid]);
        if ($q > (float)$onHand->fetchColumn() + 0.0005) {
            $pdo->rollBack();
            fail('not_enough_stock:' . $items[$iid]['name']);
        }
    }
    $pdo->prepare(
        'INSERT INTO purchase_returns (tenant_id, company_id, shop_id, supplier_id, bill_ref, return_date, subtotal, vat_total, total, reason, user_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$u['tenant_id'], $cid, $shop['id'], $supplierId, str($b['bill_ref'] ?? null, 60, false), $date, $sub, $vatSum, $total, str($b['reason'] ?? null, 255, false), $u['id']]);
    $rid = (int)$pdo->lastInsertId();
    $noteNo = 'DN-' . str_pad((string)$rid, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE purchase_returns SET note_no = ? WHERE id = ?')->execute([$noteNo, $rid]);
    $insL = $pdo->prepare('INSERT INTO purchase_return_lines (return_id, item_id, qty, unit_cost, vat_rate, vat_amount, line_total) VALUES (?,?,?,?,?,?,?)');
    $insS = $pdo->prepare("INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, ref_id, note, user_id) VALUES (?,?,?,?,?,'purchase_return',?,?,?)");
    foreach ($calc as [$iid, $qty, $cost, $rate, $vat, $lt]) {
        $insL->execute([$rid, $iid, $qty, $cost, $rate, $vat, $lt]);
        $insS->execute([$u['tenant_id'], $shop['id'], $iid, -$qty, $cost, $rid, "Returned to supplier ($noteNo)", $u['id']]);
    }
    $pdo->commit();
    foreach (array_keys($want) as $iid) {
        $now = stock_on_hand($u['tenant_id'], (int)$shop['id'], $iid);
        notify_low_stock($pdo, $u['tenant_id'], $cid, (int)$shop['id'], $iid, $now, (float)settings_for($u['tenant_id'])['low_stock_threshold'], (string)$items[$iid]['name'], (string)$shop['name']);
    }
    audit($u, 'purchase_return', "$noteNo total $total");
    json_out(['id' => $rid, 'note_no' => $noteNo, 'total' => $total]);
}

function h_preturns_list(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-d', strtotime('-90 days')));
    $st = db()->prepare(
        'SELECT r.id, r.note_no, r.return_date, r.bill_ref, r.subtotal, r.vat_total, r.total, r.reason, s.name AS supplier, s.trn AS supplier_trn, sh.name AS shop, u.name AS by_name
           FROM purchase_returns r JOIN suppliers s ON s.id = r.supplier_id JOIN shops sh ON sh.id = r.shop_id LEFT JOIN users u ON u.id = r.user_id
          WHERE r.tenant_id = ? AND r.company_id = ? AND r.return_date BETWEEN ? AND ? ORDER BY r.id DESC LIMIT 500'
    );
    $st->execute([$u['tenant_id'], $cid, $from, $to]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $l = db()->prepare('SELECT i.sku, i.name, l.qty, l.unit_cost, l.vat_rate, l.vat_amount, l.line_total FROM purchase_return_lines l JOIN items i ON i.id = l.item_id WHERE l.return_id = ?');
        $l->execute([$r['id']]);
        $r['lines'] = $l->fetchAll();
    }
    unset($r);
    json_out(['returns' => $rows]);
}

/** Plain rows for CSV / backup. what = items|sales|purchases|stock|suppliers|expenses|customers|supplier_returns */
function h_export(): void
{
    $u = auth(['owner', 'accountant']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-d', strtotime('-365 days')));
    $t = (int)$u['tenant_id'];
    $what = (string)($_GET['what'] ?? '');
    $sql = [
        'items' => ['SELECT i.sku, i.name, i.name_ar, i.category, i.uom, i.price, i.cost, i.vat_rate, i.reorder_level, i.active,
                           (SELECT GROUP_CONCAT(b.barcode SEPARATOR " ") FROM item_barcodes b WHERE b.item_id = i.id) AS barcodes
                      FROM items i WHERE i.tenant_id = ? AND i.company_id = ? ORDER BY i.sku', [$t, $cid]],
        'sales' => ['SELECT s.invoice_no, s.sold_at, s.type, sh.name AS shop, u.name AS cashier, i.sku, i.name AS item, l.qty, l.unit_price, l.discount, l.vat_rate, l.vat_amount, l.line_total, s.customer_name, s.customer_trn
                      FROM sales s JOIN sale_lines l ON l.sale_id = s.id JOIN items i ON i.id = l.item_id JOIN shops sh ON sh.id = s.shop_id LEFT JOIN users u ON u.id = s.user_id
                     WHERE s.tenant_id = ? AND s.company_id = ? AND DATE(s.sold_at) BETWEEN ? AND ? ORDER BY s.id LIMIT 200000', [$t, $cid, $from, $to]],
        'purchases' => ['SELECT p.bill_no, p.bill_date, su.name AS supplier, sh.name AS shop, i.sku, i.name AS item, l.qty AS billed_qty, COALESCE(l.recv_qty, l.qty) AS received_qty, l.unit_cost, l.vat_rate, l.vat_amount, l.line_total, p.total AS bill_total
                      FROM purchases p JOIN purchase_lines l ON l.purchase_id = p.id JOIN items i ON i.id = l.item_id JOIN suppliers su ON su.id = p.supplier_id JOIN shops sh ON sh.id = p.shop_id
                     WHERE p.tenant_id = ? AND p.company_id = ? AND p.bill_date BETWEEN ? AND ? ORDER BY p.id LIMIT 200000', [$t, $cid, $from, $to]],
        'supplier_returns' => ['SELECT r.note_no, r.return_date, su.name AS supplier, r.bill_ref, i.sku, i.name AS item, l.qty, l.unit_cost, l.vat_rate, l.vat_amount, l.line_total, r.reason
                      FROM purchase_returns r JOIN purchase_return_lines l ON l.return_id = r.id JOIN items i ON i.id = l.item_id JOIN suppliers su ON su.id = r.supplier_id
                     WHERE r.tenant_id = ? AND r.company_id = ? AND r.return_date BETWEEN ? AND ? ORDER BY r.id', [$t, $cid, $from, $to]],
        'stock' => ['SELECT sh.name AS shop, i.sku, i.name AS item, COALESCE(SUM(l.qty), 0) AS on_hand, i.cost, i.price
                      FROM items i JOIN shops sh ON sh.company_id = i.company_id LEFT JOIN stock_ledger l ON l.item_id = i.id AND l.shop_id = sh.id
                     WHERE i.tenant_id = ? AND i.company_id = ? GROUP BY sh.id, i.id ORDER BY sh.name, i.sku', [$t, $cid]],
        'suppliers' => ['SELECT s.name, s.phone, s.trn, s.opening_balance,
                           COALESCE((SELECT SUM(total) FROM purchases p WHERE p.supplier_id = s.id), 0) AS billed,
                           COALESCE((SELECT SUM(total) FROM purchase_returns r WHERE r.supplier_id = s.id), 0) AS returned,
                           COALESCE((SELECT SUM(amount) FROM supplier_payments x WHERE x.supplier_id = s.id), 0) AS paid
                      FROM suppliers s WHERE s.tenant_id = ? AND s.company_id = ? ORDER BY s.name', [$t, $cid]],
        'expenses' => ['SELECT e.expense_date, sh.name AS shop, e.category, e.amount, e.vat_amount, e.note FROM expenses e LEFT JOIN shops sh ON sh.id = e.shop_id
                     WHERE e.tenant_id = ? AND e.company_id = ? AND e.expense_date BETWEEN ? AND ? ORDER BY e.id', [$t, $cid, $from, $to]],
        'customers' => ['SELECT phone, name, visits, total_spent, offers_ok, last_seen FROM customers WHERE tenant_id = ? ORDER BY total_spent DESC', [$t]],
    ];
    if (!isset($sql[$what])) {
        fail('bad_request');
    }
    $st = db()->prepare($sql[$what][0]);
    $st->execute($sql[$what][1]);
    audit($u, 'export', $what);
    json_out(['what' => $what, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

/** Records that labels were printed (who, what barcode, how many). */
function h_labels_log(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase'], true);
    $b = body();
    $cid = (int)($b['company_id'] ?? 0);
    assert_company($u, $cid);
    $rows = $b['items'] ?? [];
    if (!is_array($rows) || !$rows || count($rows) > 500) {
        fail('bad_lines');
    }
    $size = preg_replace('/[^a-z0-9]/', '', strtolower((string)($b['size'] ?? ''))) ?: '';
    $chk = db()->prepare('SELECT id FROM items WHERE id = ? AND tenant_id = ? AND company_id = ?');
    $ins = db()->prepare('INSERT INTO label_prints (tenant_id, company_id, item_id, code, copies, size_key, user_id) VALUES (?,?,?,?,?,?,?)');
    $n = 0;
    foreach ($rows as $r) {
        $chk->execute([(int)($r['item_id'] ?? 0), $u['tenant_id'], $cid]);
        if (!$chk->fetchColumn()) {
            continue;
        }
        $ins->execute([$u['tenant_id'], $cid, (int)$r['item_id'], mb_substr((string)($r['code'] ?? ''), 0, 80), max(1, min(500, (int)($r['copies'] ?? 1))), mb_substr($size, 0, 10), $u['id']]);
        $n++;
    }
    audit($u, 'labels_print', "$n item(s)");
    json_out(['ok' => true, 'logged' => $n]);
}

function h_labels_history(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-d', strtotime('-180 days')));
    $st = db()->prepare(
        'SELECT p.id, p.printed_at, p.code, p.copies, p.size_key, i.sku, i.name, u.name AS by_name
           FROM label_prints p JOIN items i ON i.id = p.item_id LEFT JOIN users u ON u.id = p.user_id
          WHERE p.tenant_id = ? AND p.company_id = ? AND DATE(p.printed_at) BETWEEN ? AND ? ORDER BY p.id DESC LIMIT 1000'
    );
    $st->execute([$u['tenant_id'], $cid, $from, $to]);
    json_out(['prints' => $st->fetchAll()]);
}
