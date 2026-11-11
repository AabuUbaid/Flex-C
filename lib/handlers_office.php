<?php
declare(strict_types=1);

const OFFICE = ['owner', 'manager', 'accountant'];

// ---------- Items ----------

/** Items are shared by every shop of the company: the owner always edits them, a manager only when not locked to one shop. */
function items_editor(bool $purchaseToo = false): array
{
    $u = auth($purchaseToo ? ['owner', 'manager', 'purchase'] : ['owner', 'manager']);
    if ($u['role'] !== 'owner' && $u['shop_id'] !== null) {
        fail('forbidden', 403);
    }
    return $u;
}

function h_items_list(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $q = trim((string)($_GET['q'] ?? ''));

    $sql = 'SELECT id, sku, name, name_ar, category, uom, price, cost, vat_rate, reorder_level, active
              FROM items WHERE tenant_id = ? AND company_id = ?';
    $args = [$u['tenant_id'], $cid];
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        // "KU RTA" also finds code KU-RTA (codes with spaces are stored with dashes)
        $like2 = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)preg_replace('/[^A-Za-z0-9._\-\/]+/', '-', $q)) . '%';
        $sql .= ' AND (name LIKE ? OR sku LIKE ? OR sku LIKE ? OR id IN (SELECT item_id FROM item_barcodes WHERE company_id = ? AND barcode LIKE ?))';
        array_push($args, $like, $like, $like2, $cid, $like);
    }
    $st = db()->prepare($sql . ' ORDER BY name LIMIT 20000');   // shops import 1000+ items from their old program
    $st->execute($args);
    $items = $st->fetchAll();

    $st = db()->prepare('SELECT item_id, barcode FROM item_barcodes WHERE tenant_id = ? AND company_id = ?');
    $st->execute([$u['tenant_id'], $cid]);
    $bc = [];
    foreach ($st->fetchAll() as $r) {
        $bc[(int)$r['item_id']][] = $r['barcode'];
    }
    foreach ($items as &$i) {
        $i['id'] = (int)$i['id'];
        $i['barcodes'] = $bc[$i['id']] ?? [];
    }
    unset($i);
    json_out(['items' => $items]);
}

function h_items_save(): void
{
    $b = body();
    // The purchase team may add a NEW item while entering a supplier bill; editing existing items stays with owner / manager.
    $u = items_editor((int)($b['id'] ?? 0) === 0);
    $cid = (int)($b['company_id'] ?? 0);
    assert_company($u, $cid);

    $sku = str($b['sku'] ?? null, 40, true);
    if (!preg_match('/^[A-Za-z0-9._\-\/]+$/', $sku)) {
        fail('bad_sku');
    }
    $name = str($b['name'] ?? null, 200, true);
    $nameAr = str($b['name_ar'] ?? null, 200);
    $category = str($b['category'] ?? null, 80);
    $uom = str($b['uom'] ?? 'pc', 10) ?? 'pc';
    $price = round(num($b['price'] ?? 0, 0, 10000000), 2);
    $cost = round(num($b['cost'] ?? 0, 0, 10000000), 4);
    $vat = round(num($b['vat_rate'] ?? 5, 0, 100), 2);
    $reorder = round(num($b['reorder_level'] ?? 0, 0, 10000000), 3);
    $active = empty($b['active']) && array_key_exists('active', $b) ? 0 : 1;

    $barcodes = [];
    foreach ((array)($b['barcodes'] ?? []) as $bc) {
        $bc = trim((string)$bc);
        if ($bc === '') {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $bc)) {
            fail('bad_barcode');
        }
        $barcodes[$bc] = true;
    }
    $barcodes = array_keys($barcodes);
    if (count($barcodes) > 10) {
        fail('too_many_barcodes');
    }

    $pdo = db();
    $id = (int)($b['id'] ?? 0);
    $pdo->beginTransaction();
    if ($id > 0) {
        $st = $pdo->prepare('SELECT id FROM items WHERE id = ? AND tenant_id = ? AND company_id = ?');
        $st->execute([$id, $u['tenant_id'], $cid]);
        if (!$st->fetchColumn()) {
            fail('item_not_found', 404);
        }
        $pdo->prepare(
            'UPDATE items SET sku=?, name=?, name_ar=?, category=?, uom=?, price=?, cost=?, vat_rate=?, reorder_level=?, active=?
              WHERE id = ? AND tenant_id = ?'
        )->execute([$sku, $name, $nameAr, $category, $uom, $price, $cost, $vat, $reorder, $active, $id, $u['tenant_id']]);
    } else {
        $pdo->prepare(
            'INSERT INTO items (tenant_id, company_id, sku, name, name_ar, category, uom, price, cost, vat_rate, reorder_level, active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$u['tenant_id'], $cid, $sku, $name, $nameAr, $category, $uom, $price, $cost, $vat, $reorder, $active]);
        $id = (int)$pdo->lastInsertId();
    }
    $pdo->prepare('DELETE FROM item_barcodes WHERE item_id = ?')->execute([$id]);
    $ins = $pdo->prepare('INSERT INTO item_barcodes (tenant_id, company_id, item_id, barcode) VALUES (?,?,?,?)');
    foreach ($barcodes as $bc) {
        $ins->execute([$u['tenant_id'], $cid, $id, $bc]);
    }
    $pdo->commit();
    audit($u, 'item_save', "item $id $sku");
    json_out(['id' => $id]);
}

// ---------- Stock ----------

function h_stock_levels(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $shopId = (int)($_GET['shop_id'] ?? 0);
    if ($u['shop_id'] !== null) {
        $shopId = $u['shop_id'];
    }
    $sql = 'SELECT i.id AS item_id, i.sku, i.name, i.reorder_level, i.cost, s.id AS shop_id, s.name AS shop_name,
                   COALESCE(SUM(l.qty), 0) AS qty
              FROM items i
              JOIN shops s ON s.company_id = i.company_id AND s.tenant_id = i.tenant_id
              LEFT JOIN stock_ledger l ON l.item_id = i.id AND l.shop_id = s.id
             WHERE i.tenant_id = ? AND i.company_id = ? AND i.active = 1';
    $args = [$u['tenant_id'], $cid];
    if ($shopId > 0) {
        $sql .= ' AND s.id = ?';
        $args[] = $shopId;
    }
    $sql .= ' GROUP BY i.id, i.sku, i.name, i.reorder_level, i.cost, s.id, s.name ORDER BY i.name, s.name LIMIT 5000';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $default = (float)settings_for($u['tenant_id'])['low_stock_threshold'];
    foreach ($rows as &$r) {
        $r['qty'] = (float)$r['qty'];
        // An item's own reorder level wins; otherwise the tenant default (10 by default).
        $thr = (float)$r['reorder_level'] > 0 ? (float)$r['reorder_level'] : $default;
        $r['threshold'] = $thr;
        $r['low'] = $r['qty'] <= $thr;
    }
    unset($r);
    json_out(['rows' => $rows, 'default_threshold' => $default]);
}

function item_in_company(array $u, int $itemId, int $companyId): array
{
    $st = db()->prepare('SELECT id, name, cost FROM items WHERE id = ? AND tenant_id = ? AND company_id = ?');
    $st->execute([$itemId, $u['tenant_id'], $companyId]);
    $it = $st->fetch();
    if (!$it) {
        fail('item_not_found', 404);
    }
    return $it;
}

function stock_on_hand(int $tenantId, int $shopId, int $itemId): float
{
    $st = db()->prepare('SELECT COALESCE(SUM(qty), 0) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? AND item_id = ?');
    $st->execute([$tenantId, $shopId, $itemId]);
    return (float)$st->fetchColumn();
}

function h_stock_adjust(): void
{
    $u = auth(['owner', 'manager']);
    $b = body();
    $shop = assert_shop($u, (int)($b['shop_id'] ?? 0));
    $item = item_in_company($u, (int)($b['item_id'] ?? 0), (int)$shop['company_id']);
    $qty = round(num($b['qty'] ?? 0, -1000000, 1000000), 3);
    if ($qty == 0.0) {
        fail('qty_zero');
    }
    $type = ($b['type'] ?? 'adjust') === 'opening' ? 'opening' : 'adjust';
    $note = str($b['note'] ?? null, 255);
    db()->prepare(
        'INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, note, user_id) VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$u['tenant_id'], $shop['id'], $item['id'], $qty, $item['cost'], $type, $note, $u['id']]);
    $now = stock_on_hand($u['tenant_id'], (int)$shop['id'], (int)$item['id']);
    notify_low_stock(
        db(), $u['tenant_id'], (int)$shop['company_id'], (int)$shop['id'], (int)$item['id'],
        $now, (float)settings_for($u['tenant_id'])['low_stock_threshold'],
        (string)$item['name'], (string)$shop['name']
    );
    audit($u, 'stock_' . $type, "shop {$shop['id']} item {$item['id']} qty $qty");
    json_out(['ok' => true, 'stock' => $now]);
}

function h_stock_transfer(): void
{
    $u = auth(['owner', 'manager']);
    $b = body();
    $from = assert_shop($u, (int)($b['from_shop_id'] ?? 0));
    $to = assert_shop($u, (int)($b['to_shop_id'] ?? 0));
    if ($from['id'] === $to['id'] || $from['company_id'] !== $to['company_id']) {
        fail('bad_transfer');
    }
    $item = item_in_company($u, (int)($b['item_id'] ?? 0), (int)$from['company_id']);
    $qty = round(num($b['qty'] ?? 0, 0.001, 1000000), 3);
    $pdo = db();
    $pdo->beginTransaction();
    if (stock_on_hand($u['tenant_id'], (int)$from['id'], (int)$item['id']) < $qty) {
        fail('insufficient_stock');
    }
    $ins = $pdo->prepare(
        'INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, note, user_id) VALUES (?,?,?,?,?,?,?,?)'
    );
    $ins->execute([$u['tenant_id'], $from['id'], $item['id'], -$qty, $item['cost'], 'transfer_out', 'to ' . $to['name'], $u['id']]);
    $ins->execute([$u['tenant_id'], $to['id'], $item['id'], $qty, $item['cost'], 'transfer_in', 'from ' . $from['name'], $u['id']]);
    $pdo->commit();
    audit($u, 'stock_transfer', "item {$item['id']} qty $qty {$from['id']}->{$to['id']}");
    json_out(['ok' => true]);
}

// ---------- Suppliers & purchases ----------

function h_suppliers_list(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase'], true);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $st = db()->prepare(
        'SELECT s.id, s.name, s.phone, s.trn, s.opening_balance, s.contact_name, s.email, s.address, s.emirate, s.po_box, s.notes,
                s.trn_legal_name, s.trn_verified_at, (SELECT name FROM users vu WHERE vu.id = s.trn_verified_by) AS trn_verified_by_name,
                COALESCE((SELECT SUM(total) FROM purchases p WHERE p.supplier_id = s.id), 0) AS billed,
                COALESCE((SELECT SUM(total) FROM purchase_returns r WHERE r.supplier_id = s.id), 0) AS returned,
                COALESCE((SELECT SUM(amount) FROM supplier_payments x WHERE x.supplier_id = s.id), 0) AS paid
           FROM suppliers s WHERE s.tenant_id = ? AND s.company_id = ? ORDER BY s.name'
    );
    $st->execute([$u['tenant_id'], $cid]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['balance'] = round((float)$r['opening_balance'] + (float)$r['billed'] - (float)$r['returned'] - (float)$r['paid'], 2);
    }
    unset($r);
    json_out(['suppliers' => $rows]);
}

function h_suppliers_save(): void
{
    $u = auth(OFFICE, true);
    $b = body();
    $cid = (int)($b['company_id'] ?? 0);
    assert_company($u, $cid);
    $name = str($b['name'] ?? null, 150, true);
    $phone = str($b['phone'] ?? null, 40);
    // UAE TRN: 15 digits starting with 100 (spaces and dashes typed by people are removed).
    $trn = preg_replace('/[\s\-]+/', '', (string)($b['trn'] ?? ''));
    $trn = $trn === '' ? null : $trn;
    if ($trn !== null && !preg_match('/^100\d{12}$/', $trn)) {
        fail('bad_trn');
    }
    $email = str($b['email'] ?? null, 190);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('bad_email');
    }
    $emirate = str($b['emirate'] ?? null, 40);
    $f = [
        'contact_name' => str($b['contact_name'] ?? null, 120), 'email' => $email ? strtolower($email) : null,
        'address' => str($b['address'] ?? null, 255), 'emirate' => $emirate, 'po_box' => str($b['po_box'] ?? null, 20),
        'notes' => str($b['notes'] ?? null, 255),
    ];
    $open = round(num($b['opening_balance'] ?? 0, -1e9, 1e9), 2);
    $id = (int)($b['id'] ?? 0);
    $pdo = db();
    // One supplier per TRN and per name: avoids paying the same supplier under two records.
    $q = $pdo->prepare('SELECT name FROM suppliers WHERE tenant_id = ? AND company_id = ? AND id <> ? AND (LOWER(name) = LOWER(?) OR (trn IS NOT NULL AND trn = ?)) LIMIT 1');
    $q->execute([$u['tenant_id'], $cid, $id, $name, $trn ?? '-']);
    if ($dup = $q->fetchColumn()) {
        fail('supplier_exists:' . $dup, 409);
    }
    $old = null;
    if ($id > 0) {
        $q = $pdo->prepare('SELECT * FROM suppliers WHERE id = ? AND tenant_id = ? AND company_id = ?');
        $q->execute([$id, $u['tenant_id'], $cid]);
        $old = $q->fetch() ?: fail('supplier_not_found', 404);
    }
    // TRN check on the FTA site (EmaraTax) has a CAPTCHA, so a person does it; we record who, when and the legal name shown.
    $verified = !empty($b['trn_verified']) && $trn !== null;
    $legal = $verified ? str($b['trn_legal_name'] ?? null, 190) : null;
    $keepVerify = $old && $verified && $old['trn'] === $trn && $old['trn_verified_at'] !== null && ($old['trn_legal_name'] ?? null) === $legal;
    $vAt = $verified ? ($keepVerify ? $old['trn_verified_at'] : date('Y-m-d H:i:s')) : null;
    $vBy = $verified ? ($keepVerify ? $old['trn_verified_by'] : $u['id']) : null;
    $cols = ['name' => $name, 'phone' => $phone, 'trn' => $trn, 'opening_balance' => $open] + $f + ['trn_legal_name' => $legal, 'trn_verified_at' => $vAt, 'trn_verified_by' => $vBy];
    if ($id > 0) {
        $pdo->prepare('UPDATE suppliers SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ' WHERE id = ? AND tenant_id = ? AND company_id = ?')
            ->execute(array_merge(array_values($cols), [$id, $u['tenant_id'], $cid]));
    } else {
        $pdo->prepare('INSERT INTO suppliers (tenant_id, company_id, ' . implode(', ', array_keys($cols)) . ') VALUES (?,?,' . placeholders(count($cols)) . ')')
            ->execute(array_merge([$u['tenant_id'], $cid], array_values($cols)));
        $id = (int)$pdo->lastInsertId();
    }
    audit($u, 'supplier_save', "supplier $id $name trn " . ($trn ?? '-') . ($verified && !$keepVerify ? ' verified as ' . ($legal ?? '') : ''));
    json_out(['id' => $id]);
}

/** Goods receipt + supplier bill in one step: raises stock, updates average cost, books the payable. */
function h_purchase_save(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase'], true);
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
    $billNo = str($b['bill_no'] ?? null, 40, true);
    $billDate = vdate($b['bill_date'] ?? '', date('Y-m-d'));
    $lines = $b['lines'] ?? [];
    if (!is_array($lines) || !$lines || count($lines) > 200) {
        fail('bad_lines');
    }

    $ids = array_values(array_unique(array_map(fn($l) => (int)($l['item_id'] ?? 0), $lines)));
    $st = $pdo->prepare(
        'SELECT id, vat_rate, cost FROM items WHERE tenant_id = ? AND company_id = ? AND id IN (' . placeholders(count($ids)) . ')'
    );
    $st->execute(array_merge([$u['tenant_id'], $cid], $ids));
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[(int)$r['id']] = $r;
    }

    $dup = $pdo->prepare('SELECT id FROM purchases WHERE tenant_id = ? AND supplier_id = ? AND bill_no = ?');
    $dup->execute([$u['tenant_id'], $supplierId, $billNo]);
    if ($dup->fetchColumn()) {
        fail('duplicate_bill', 409);
    }
    $calc = [];
    $sumQty = 0.0;
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
        $recvIn = $l['recv_qty'] ?? '';
        $recv = ($recvIn === '' || $recvIn === null) ? $qty : round(num($recvIn, 0, 1000000), 3);
        $calc[] = [$iid, $qty, $cost, $rate, $vat, round($net + $vat, 2), $recv];
        $sumQty += $qty;
        $sub += $net;
        $vatSum += $vat;
        $total += $net + $vat;
    }
    $sub = round($sub, 2);
    $vatSum = round($vatSum, 2);
    $total = round($total, 2);
    $paidNow = round(num($b['paid_now'] ?? 0, 0, 1e9), 2);
    if ($paidNow > $total + 0.01) {
        fail('paid_more_than_total');
    }
    // Control totals typed from the supplier's paper bill must agree with the lines entered.
    $declTotal = (isset($b['declared_total']) && $b['declared_total'] !== '') ? round(num($b['declared_total'], 0, 1e9), 2) : null;
    $declQty = (isset($b['declared_qty']) && $b['declared_qty'] !== '') ? round(num($b['declared_qty'], 0, 1e9), 3) : null;
    if ($declTotal !== null && abs($declTotal - $total) > 0.05) {
        fail('bill_total_mismatch:' . number_format($total, 2, '.', ''));
    }
    if ($declQty !== null && abs($declQty - $sumQty) > 0.0005) {
        fail('bill_qty_mismatch:' . rtrim(rtrim(number_format($sumQty, 3, '.', ''), '0'), '.'));
    }
    $short = false;
    foreach ($calc as $c) {
        if (abs($c[6] - $c[1]) > 0.0005) {
            $short = true;
        }
    }

    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO purchases (tenant_id, company_id, shop_id, supplier_id, bill_no, bill_date, subtotal, vat_total, total, user_id, verify_token, declared_total, declared_qty, has_short)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$u['tenant_id'], $cid, $shop['id'], $supplierId, $billNo, $billDate, $sub, $vatSum, $total, $u['id'], bin2hex(random_bytes(12)), $declTotal, $declQty, $short ? 1 : 0]);
    $pid = (int)$pdo->lastInsertId();

    $insLine = $pdo->prepare(
        'INSERT INTO purchase_lines (purchase_id, item_id, qty, unit_cost, vat_rate, vat_amount, line_total, recv_qty) VALUES (?,?,?,?,?,?,?,?)'
    );
    $insLedger = $pdo->prepare(
        "INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, ref_id, user_id) VALUES (?,?,?,?,?,'purchase',?,?)"
    );
    $setCost = $pdo->prepare('UPDATE items SET cost = ? WHERE id = ? AND tenant_id = ?');
    $onHand = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM stock_ledger WHERE tenant_id = ? AND item_id = ?');
    $curCost = $pdo->prepare('SELECT cost FROM items WHERE id = ? AND tenant_id = ?');

    $nameOf = $pdo->prepare('SELECT name FROM items WHERE id = ? AND tenant_id = ?');
    $lastRate = $pdo->prepare('SELECT l.unit_cost FROM purchase_lines l JOIN purchases p ON p.id = l.purchase_id WHERE p.tenant_id = ? AND l.item_id = ? AND p.id <> ? ORDER BY p.id DESC LIMIT 1');
    $rateChanges = [];
    $insNote = $pdo->prepare("INSERT INTO notifications (tenant_id, company_id, shop_id, item_id, type, level, title, detail, qty) VALUES (?,?,?,?,'delivery_diff','bad',?,?,?)");
    foreach ($calc as [$iid, $billed, $cost, $rate, $vat, $lt, $qty]) {
        $lastRate->execute([$u['tenant_id'], $iid, $pid]);
        $lr = $lastRate->fetchColumn();
        if ($lr !== false && abs((float)$lr - $cost) > 0.005) {
            $nameOf->execute([$iid, $u['tenant_id']]);
            $rateChanges[] = (string)$nameOf->fetchColumn() . ': ' . (float)$lr . ' -> ' . (float)$cost;
        }
        $insLine->execute([$pid, $iid, $billed, $cost, $rate, $vat, $lt, abs($qty - $billed) > 0.0005 ? $qty : null]);
        if (abs($qty - $billed) > 0.0005) {
            $nameOf->execute([$iid, $u['tenant_id']]);
            $diff = round($qty - $billed, 3);
            $insNote->execute([$u['tenant_id'], $cid, $shop['id'], $iid, ($diff < 0 ? 'Short delivery: ' : 'Excess delivery: ') . (string)$nameOf->fetchColumn(),
                "Bill $billNo · billed " . (float)$billed . ", received " . (float)$qty . ' (' . ($diff > 0 ? '+' : '') . (float)$diff . '). Stock updated with the received quantity; supplier bill stays at the billed amount.', $diff]);
        }
        // Weighted average cost over total stock in all shops of the tenant, before this receipt.
        $onHand->execute([$u['tenant_id'], $iid]);
        $oldQty = (float)$onHand->fetchColumn();
        $curCost->execute([$iid, $u['tenant_id']]);
        $oldCost = (float)$curCost->fetchColumn();
        $newCost = $oldQty > 0 ? ($oldQty * $oldCost + $qty * $cost) / ($oldQty + $qty) : $cost;
        $setCost->execute([round($newCost, 4), $iid, $u['tenant_id']]);
        $insLedger->execute([$u['tenant_id'], $shop['id'], $iid, $qty, $cost, $pid, $u['id']]);
    }
    // A receipt can clear a low-stock alert; notify_low_stock resolves it when above the level.
    $threshold = (float)settings_for($u['tenant_id'])['low_stock_threshold'];
    $stockNow = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? AND item_id = ?');
    $nameSt = $pdo->prepare('SELECT name FROM items WHERE id = ? AND tenant_id = ?');
    foreach (array_values(array_unique(array_map(fn($x) => $x[0], $calc))) as $iid) {   // stock level after receipt
        $stockNow->execute([$u['tenant_id'], $shop['id'], $iid]);
        $nameSt->execute([$iid, $u['tenant_id']]);
        notify_low_stock(
            $pdo, $u['tenant_id'], $cid, (int)$shop['id'], $iid,
            (float)$stockNow->fetchColumn(), $threshold,
            (string)($nameSt->fetchColumn() ?: 'Item'), (string)$shop['name']
        );
    }
    if ($paidNow > 0) {
        $method = in_array($b['method'] ?? 'cash', ['cash', 'card', 'bank', 'cheque'], true) ? $b['method'] : 'cash';
        $pdo->prepare(
            'INSERT INTO supplier_payments (tenant_id, company_id, supplier_id, purchase_id, amount, method, paid_at, note, user_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$u['tenant_id'], $cid, $supplierId, $pid, $paidNow, $method, $billDate, 'paid at purchase', $u['id']]);
    }
    $pdo->commit();
    audit($u, 'purchase_save', "purchase $pid bill $billNo total $total");
    json_out(['id' => $pid, 'total' => $total, 'rate_changes' => $rateChanges]);
}

function h_purchases_list(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase'], true);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-d', strtotime('-30 days')));
    $st = db()->prepare(
        'SELECT p.id, p.bill_no, p.bill_date, p.subtotal, p.vat_total, p.total, p.has_short, (p.declared_total IS NOT NULL) AS verified,
                (SELECT COUNT(*) FROM purchase_files f WHERE f.purchase_id = p.id) AS photos, s.name AS supplier, sh.name AS shop,
                COALESCE((SELECT SUM(amount) FROM supplier_payments x WHERE x.purchase_id = p.id), 0) AS paid
           FROM purchases p
           JOIN suppliers s ON s.id = p.supplier_id
           JOIN shops sh ON sh.id = p.shop_id
          WHERE p.tenant_id = ? AND p.company_id = ? AND p.bill_date BETWEEN ? AND ?
          ORDER BY p.bill_date DESC, p.id DESC LIMIT 500'
    );
    $st->execute([$u['tenant_id'], $cid, $from, $to]);
    json_out(['purchases' => $st->fetchAll(), 'from' => $from, 'to' => $to]);
}

/** Full purchase bill for the A4 PDF / print view. */
function h_purchase_get(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase'], true);
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare(
        'SELECT p.*, s.name AS supplier_name, s.phone AS supplier_phone, s.trn AS supplier_trn,
                sh.name AS shop_name, sh.code AS shop_code, sh.logo AS shop_logo,
                c.name AS company_name, c.name_ar AS company_name_ar, c.trn AS company_trn,
                c.address AS company_address, c.phone AS company_phone, c.logo AS company_logo
           FROM purchases p
           JOIN suppliers s ON s.id = p.supplier_id
           JOIN shops sh ON sh.id = p.shop_id
           JOIN companies c ON c.id = p.company_id
          WHERE p.id = ? AND p.tenant_id = ?'
    );
    $st->execute([$id, $u['tenant_id']]);
    $p = $st->fetch();
    if (!$p) {
        fail('purchase_not_found', 404);
    }
    assert_shop($u, (int)$p['shop_id']);
    $st = db()->prepare(
        'SELECT l.*, i.name, i.sku, i.name_ar
           FROM purchase_lines l JOIN items i ON i.id = l.item_id
          WHERE l.purchase_id = ? ORDER BY l.id'
    );
    $st->execute([$id]);
    $p['lines'] = $st->fetchAll();
    $st = db()->prepare('SELECT u.name FROM users u WHERE u.id = ?');
    $st->execute([(int)$p['user_id']]);
    $p['entered_by'] = (string)$st->fetchColumn();
    $st = db()->prepare("SELECT l.id, l.item_id, l.qty, l.created_at FROM stock_ledger l WHERE l.tenant_id = ? AND l.ref_type = 'purchase' AND l.ref_id = ? ORDER BY l.id");
    $st->execute([$u['tenant_id'], $id]);
    $p['ledger'] = $st->fetchAll();
    $st = db()->prepare('SELECT id, mime, created_at FROM purchase_files WHERE purchase_id = ? AND tenant_id = ? ORDER BY id');
    $st->execute([$id, $u['tenant_id']]);
    $p['files'] = $st->fetchAll();
    json_out(['purchase' => $p]);
}

function h_supplier_payment_save(): void
{
    $u = auth(OFFICE, true);
    $b = body();
    $cid = (int)($b['company_id'] ?? 0);
    assert_company($u, $cid);
    $st = db()->prepare('SELECT id FROM suppliers WHERE id = ? AND tenant_id = ? AND company_id = ?');
    $st->execute([(int)($b['supplier_id'] ?? 0), $u['tenant_id'], $cid]);
    $sid = (int)$st->fetchColumn();
    if (!$sid) {
        fail('supplier_not_found', 404);
    }
    $purchaseId = null;
    if (!empty($b['purchase_id'])) {
        $st = db()->prepare('SELECT id FROM purchases WHERE id = ? AND tenant_id = ? AND supplier_id = ?');
        $st->execute([(int)$b['purchase_id'], $u['tenant_id'], $sid]);
        $purchaseId = $st->fetchColumn() ?: fail('purchase_not_found', 404);
    }
    $amount = round(num($b['amount'] ?? 0, 0.01, 1e9), 2);
    $method = in_array($b['method'] ?? 'cash', ['cash', 'card', 'bank', 'cheque'], true) ? $b['method'] : 'cash';
    db()->prepare(
        'INSERT INTO supplier_payments (tenant_id, company_id, supplier_id, purchase_id, amount, method, paid_at, note, user_id)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$u['tenant_id'], $cid, $sid, $purchaseId, $amount, $method, vdate($b['paid_at'] ?? '', date('Y-m-d')), str($b['note'] ?? null, 255), $u['id']]);
    audit($u, 'supplier_payment', "supplier $sid amount $amount");
    json_out(['ok' => true]);
}

// ---------- Expenses ----------

function h_expense_save(): void
{
    $u = auth(OFFICE, true);
    $b = body();
    $cid = (int)($b['company_id'] ?? 0);
    assert_company($u, $cid);
    $shopId = null;
    if (!empty($b['shop_id'])) {
        $shop = assert_shop($u, (int)$b['shop_id']);
        if ((int)$shop['company_id'] !== $cid) {
            fail('shop_company_mismatch');
        }
        $shopId = (int)$shop['id'];
    }
    $amount = round(num($b['amount'] ?? 0, 0.01, 1e9), 2);
    $vat = round(num($b['vat_amount'] ?? 0, 0, 1e9), 2);
    if ($vat > $amount) {
        fail('vat_more_than_amount');
    }
    $cat = str($b['category'] ?? null, 60, true);
    $date = vdate($b['expense_date'] ?? '', date('Y-m-d'));
    $note = str($b['note'] ?? null, 255);
    $id = (int)($b['id'] ?? 0);
    if ($id > 0) {
        // Only the owner (or a sub-owner) may change an entry. The old values go into the audit log.
        if ($u['role'] !== 'owner') {
            fail('forbidden', 403);
        }
        $st = db()->prepare('SELECT category, amount, vat_amount, expense_date, note, shop_id FROM expenses WHERE id = ? AND tenant_id = ? AND company_id = ?');
        $st->execute([$id, $u['tenant_id'], $cid]);
        $old = $st->fetch();
        if (!$old) {
            fail('expense_not_found', 404);
        }
        db()->prepare('UPDATE expenses SET shop_id=?, category=?, amount=?, vat_amount=?, expense_date=?, note=? WHERE id = ? AND tenant_id = ?')
            ->execute([$shopId, $cat, $amount, $vat, $date, $note, $id, $u['tenant_id']]);
        audit($u, 'expense_edit', "expense $id was " . json_encode($old) . " now $cat $amount vat $vat $date");
        json_out(['ok' => true]);
    }
    db()->prepare(
        'INSERT INTO expenses (tenant_id, company_id, shop_id, category, amount, vat_amount, expense_date, note, user_id)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$u['tenant_id'], $cid, $shopId, $cat, $amount, $vat, $date, $note, $u['id']]);
    audit($u, 'expense_save', "company $cid amount $amount");
    json_out(['ok' => true]);
}

function h_expense_delete(): void
{
    $u = auth(['owner'], true);
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $st = db()->prepare('SELECT company_id, category, amount, vat_amount, expense_date, note FROM expenses WHERE id = ? AND tenant_id = ?');
    $st->execute([$id, $u['tenant_id']]);
    $old = $st->fetch();
    if (!$old) {
        fail('expense_not_found', 404);
    }
    assert_company($u, (int)$old['company_id']);
    db()->prepare('DELETE FROM expenses WHERE id = ? AND tenant_id = ?')->execute([$id, $u['tenant_id']]);
    audit($u, 'expense_delete', "expense $id " . json_encode($old));
    json_out(['ok' => true]);
}

function h_expenses_list(): void
{
    $u = auth(OFFICE, true);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-01'));
    $st = db()->prepare(
        'SELECT e.id, e.expense_date, e.category, e.amount, e.vat_amount, e.note, e.shop_id, s.name AS shop
           FROM expenses e LEFT JOIN shops s ON s.id = e.shop_id
          WHERE e.tenant_id = ? AND e.company_id = ? AND e.expense_date BETWEEN ? AND ?
          ORDER BY e.expense_date DESC, e.id DESC LIMIT 500'
    );
    $st->execute([$u['tenant_id'], $cid, $from, $to]);
    $rows = $st->fetchAll();
    $total = round(array_sum(array_map(fn($r) => (float)$r['amount'], $rows)), 2);
    json_out(['expenses' => $rows, 'total' => $total, 'from' => $from, 'to' => $to]);
}

/**
 * Stock received at the counter. Owner, manager and accountant can add directly; a cashier needs a
 * manager/accountant/owner authorisation token (purpose "stock"). Positive quantities only; every add is audited.
 */
function h_pos_stock_add(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'cashier']);
    $b = body();
    $shop = assert_shop($u, (int)($b['shop_id'] ?? 0));
    $item = item_in_company($u, (int)($b['item_id'] ?? 0), (int)$shop['company_id']);
    $qty = round(num($b['qty'] ?? 0, 0.001, 100000), 3);
    $by = $u['id'];
    // One entry per delivery: if a purchase bill for this item was entered at this shop in the last 7 days,
    // the bill already added the stock. Ask before adding it a second time.
    $bl = db()->prepare(
        'SELECT p.bill_no, COALESCE(l.recv_qty, l.qty) AS q, l.unit_cost FROM purchases p JOIN purchase_lines l ON l.purchase_id = p.id
          WHERE p.tenant_id = ? AND p.shop_id = ? AND l.item_id = ? AND p.created_at >= (NOW() - INTERVAL 7 DAY) ORDER BY p.id DESC LIMIT 1'
    );
    $bl->execute([$u['tenant_id'], $shop['id'], $item['id']]);
    $bill = $bl->fetch();
    if ($bill && empty($b['confirm_billed'])) {
        fail('stock_already_billed:' . str_replace(':', '', (string)$bill['bill_no']) . ':' . (float)$bill['q'] . ':' . (float)$bill['unit_cost']);
    }
    $rate = (isset($b['unit_cost']) && $b['unit_cost'] !== '') ? round(num($b['unit_cost'], 0, 1e7), 4) : null;
    if ($u['role'] === 'cashier') {
        $tok = (string)($b['auth_token'] ?? '');
        $st = db()->prepare(
            "SELECT a.id, a.user_id FROM authorisations a JOIN users x ON x.id = a.user_id
              WHERE a.token_hash = ? AND a.tenant_id = ? AND a.purpose = 'stock' AND a.used_at IS NULL
                AND a.expires_at > NOW() AND x.role IN ('owner','manager','accountant') AND x.active = 1"
        );
        $st->execute([hash('sha256', $tok), $u['tenant_id']]);
        $a = $st->fetch();
        if (!$a) {
            fail('stock_needs_authorisation', 403);
        }
        db()->prepare('UPDATE authorisations SET used_at = NOW() WHERE id = ?')->execute([$a['id']]);
        $by = (int)$a['user_id'];
    }
    $note = str($b['note'] ?? null, 200);
    db()->prepare(
        'INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, note, user_id) VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$u['tenant_id'], $shop['id'], $item['id'], $qty, $rate ?? $item['cost'], 'adjust', 'POS stock in' . ($bill ? ' (bill ' . $bill['bill_no'] . ' already on record)' : ' (no bill)') . ($note ? ': ' . $note : ''), $by]);
    if ($rate !== null) {
        $lr = db()->prepare('SELECT l.unit_cost FROM purchase_lines l JOIN purchases p ON p.id = l.purchase_id WHERE p.tenant_id = ? AND l.item_id = ? ORDER BY p.id DESC LIMIT 1');
        $lr->execute([$u['tenant_id'], $item['id']]);
        $last = $lr->fetchColumn();
        if ($last !== false && abs((float)$last - $rate) > 0.005) {
            db()->prepare("INSERT INTO notifications (tenant_id, company_id, shop_id, item_id, type, level, title, detail, qty) VALUES (?,?,?,?,'rate_diff','warn',?,?,?)")
                ->execute([$u['tenant_id'], $shop['company_id'], $shop['id'], $item['id'], 'Buy rate differs: ' . $item['name'], 'Stock added at ' . $rate . ' but the last purchase bill rate was ' . (float)$last . '.', $qty]);
        }
    }
    $now = stock_on_hand($u['tenant_id'], (int)$shop['id'], (int)$item['id']);
    audit($u, 'pos_stock_add', "shop {$shop['id']} item {$item['id']} qty $qty approved by user $by");
    json_out(['ok' => true, 'stock' => $now]);
}

/** Attach a photo (JPEG/PNG data URL) of the supplier bill / delivery note to a purchase. */
function h_purchase_attach(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase'], true);
    $b = body();
    $pid = (int)($b['purchase_id'] ?? 0);
    $st = db()->prepare('SELECT shop_id FROM purchases WHERE id = ? AND tenant_id = ?');
    $st->execute([$pid, $u['tenant_id']]);
    $shopId = $st->fetchColumn();
    if ($shopId === false) {
        fail('purchase_not_found', 404);
    }
    assert_shop($u, (int)$shopId);
    $img = (string)($b['image'] ?? '');
    if (!preg_match('#^data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)$#', $img, $m) || strlen($img) > 2200000) {
        fail('bad_image');
    }
    $raw = base64_decode($m[2], true);
    $mime = $raw !== false ? (new finfo(FILEINFO_MIME_TYPE))->buffer($raw) : '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        fail('bad_image');
    }
    $n = db()->prepare('SELECT COUNT(*) FROM purchase_files WHERE purchase_id = ?');
    $n->execute([$pid]);
    if ((int)$n->fetchColumn() >= 5) {
        fail('too_many_photos');
    }
    db()->prepare('INSERT INTO purchase_files (tenant_id, purchase_id, mime, data, user_id) VALUES (?,?,?,?,?)')->execute([$u['tenant_id'], $pid, $mime, $m[2], $u['id']]);
    audit($u, 'purchase_attach', "purchase $pid photo");
    json_out(['ok' => true]);
}

/** Returns one attached photo as a data URL (login required). */
function h_purchase_file(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase'], true);
    $st = db()->prepare('SELECT f.mime, f.data, p.shop_id FROM purchase_files f JOIN purchases p ON p.id = f.purchase_id WHERE f.id = ? AND f.tenant_id = ?');
    $st->execute([(int)($_GET['id'] ?? 0), $u['tenant_id']]);
    $r = $st->fetch();
    if (!$r) {
        fail('not_found', 404);
    }
    assert_shop($u, (int)$r['shop_id']);
    json_out(['image' => 'data:' . $r['mime'] . ';base64,' . $r['data']]);
}

/**
 * Opening stock / stock count from an Excel (.xlsx) or CSV file for one shop.
 * Multipart POST: file, company_id, shop_id, dry=1 (preview, nothing saved). Any error row stops the whole import.
 * Columns (any order, header names are forgiving): code, name, barcode, category, unit, cost, price, vat, qty, reorder, name_ar.
 * For every row the shop's stock is set to qty: the difference is written to the stock record
 * ("opening" the first time an item has stock at that shop, "adjust" afterwards).
 */
function h_stock_import(): void
{
    require_once __DIR__ . '/xlsx.php';
    $u = auth(['owner', 'manager']);
    $cid = (int)($_POST['company_id'] ?? 0);
    assert_company($u, $cid);
    $shop = assert_shop($u, (int)($_POST['shop_id'] ?? 0));
    if ((int)$shop['company_id'] !== $cid) {
        fail('shop_not_found', 404);
    }
    $dry = !empty($_POST['dry']);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        fail(($_FILES['file']['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'file_too_big_for_server' : 'no_file');
    }
    $rows = sheet_rows($_FILES['file']['tmp_name'], (string)$_FILES['file']['name']);
    if (count($rows) < 2) {
        fail('empty_file');
    }
    $alias = [
        'sku' => ['code', 'sku', 'itemcode', 'item code', 'item_code', 'product code', 'productcode'],
        'name' => ['name', 'item', 'itemname', 'item name', 'description', 'product', 'product name'],
        'barcode' => ['barcode', 'bar code', 'ean', 'upc'],
        'category' => ['category', 'group', 'type'],
        'uom' => ['unit', 'uom', 'unit of measure', 'units'],
        'cost' => ['cost', 'cost price', 'buy', 'buying price', 'purchase price', 'buy rate', 'rate'],
        'price' => ['price', 'selling price', 'sell', 'sale price', 'sell price', 'mrp', 'retail price', 's.price 1', 's.price1', 's.price', 's price', 'sale price 1', 'selling price 1', 'sales price', 'retail'],
        'vat' => ['vat', 'vat%', 'vat rate', 'vat_rate', 'tax'],
        'qty' => ['qty', 'quantity', 'stock', 'opening', 'opening stock', 'opening qty', 'on hand', 'count'],
        'reorder' => ['reorder', 'reorder level', 'min stock', 'minimum stock', 'min'],
        'name_ar' => ['name_ar', 'arabic', 'arabic name', 'name arabic'],
    ];
    $map = [];
    foreach ($rows[0] as $i => $h) {
        $h = strtolower(trim((string)$h));
        foreach ($alias as $key => $names) {
            if (!isset($map[$key]) && in_array($h, $names, true)) {
                $map[$key] = $i;
            }
        }
    }
    if (!isset($map['sku']) && !isset($map['barcode'])) {
        fail('import_no_code_column');
    }
    // Items only: load codes, names, units, cost and price; stock is left as it is (stockers count it later).
    $itemsOnly = !empty($_POST['items_only']) || !isset($map['qty']);
    if ($itemsOnly) {
        unset($map['qty']);
    }
    $cell = fn(array $r, string $k): string => isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';
    $pdo = db();
    $find = $pdo->prepare('SELECT id, name, cost FROM items WHERE company_id = ? AND sku = ?');
    $bcOwner = $pdo->prepare('SELECT item_id FROM item_barcodes WHERE company_id = ? AND barcode = ?');
    $stockNow = $pdo->prepare('SELECT COALESCE(SUM(qty),0), COUNT(*) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? AND item_id = ?');
    $errors = [];
    $warnings = [];   // fixed automatically; shown in the check, never stop the import
    $renamed = [];
    $noPrice = [];
    $plan = [];
    $seen = [];
    $seenRaw = [];
    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $line = $i + 1;
        if (!array_filter($r, fn($c) => trim((string)$c) !== '')) {
            continue;
        }
        $sku = $cell($r, 'sku');
        $bc = $cell($r, 'barcode');
        if ($sku === '') {
            $sku = $bc;
        }
        if (preg_match('/^(grand\s*)?total:?$/i', $sku) && $cell($r, 'name') === '') {
            continue;   // the totals line at the bottom of exported stock lists
        }
        if ($sku === '') {
            $errors[] = "Row $line: code is missing.";
            continue;
        }
        $raw = $sku;
        if (isset($seenRaw[strtoupper($raw)])) {
            $errors[] = "Row $line: code $raw appears twice (first on row {$seenRaw[strtoupper($raw)]}).";
            continue;
        }
        $seenRaw[strtoupper($raw)] = $line;
        if (!preg_match('/^[A-Za-z0-9._\-\/]{1,40}$/', $sku)) {
            // Codes from other programs often have spaces or symbols: keep them readable, e.g. "KU RTA" -> "KU-RTA".
            $sku = substr(trim((string)preg_replace('/-+/', '-', (string)preg_replace('/[^A-Za-z0-9._\-\/]+/', '-', $sku)), '-'), 0, 40) ?: 'ITEM' . $line;
            $base = $sku;
            for ($k = 2; isset($seen[$sku]); $k++) {
                $sku = substr($base, 0, 36) . '-' . $k;
            }
            $renamed[] = "\"$raw\" → $sku";
        }
        if (isset($seen[$sku])) {
            $errors[] = "Row $line: code $sku appears twice (first on row {$seen[$sku]}).";
            continue;
        }
        $seen[$sku] = $line;
        $nums = [];
        $bad = false;
        $q = str_replace([',', ' '], '', $cell($r, 'qty'));
        if ($itemsOnly) {
            $q = '';
        } elseif (is_numeric($q) && (float)$q < 0) {
            $warnings[] = "Row $line: $raw has stock $q (sold more than recorded): imported as 0.";
            $r[$map['qty']] = '0';
        } elseif (is_numeric($q) && (float)$q > 1000000) {
            $warnings[] = "Row $line: $raw has stock $q, which cannot be right: row skipped. Count it and add it later.";
            continue;
        }
        foreach (['cost' => 1e7, 'price' => 1e7, 'vat' => 100, 'qty' => 1e7, 'reorder' => 1e7] as $k => $max) {
            $t = str_replace([',', ' '], '', $cell($r, $k));
            if ($t === '') {
                $nums[$k] = null;
            } elseif (!is_numeric($t) || (float)$t < 0 || (float)$t > $max) {
                $errors[] = "Row $line: $k \"{$cell($r, $k)}\" is not a valid number.";
                $bad = true;
            } else {
                $nums[$k] = (float)$t;
            }
        }
        if ($bad) {
            continue;
        }
        if ($nums['qty'] === null && !$itemsOnly) {
            $errors[] = "Row $line: quantity is empty (use 0 for no stock).";
            continue;
        }
        if ($bc !== '' && !preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $bc)) {
            $errors[] = "Row $line: barcode has characters that are not allowed.";
            continue;
        }
        $find->execute([$cid, $sku]);
        $ex = $find->fetch();
        $name = $cell($r, 'name');
        if (!$ex) {
            if ($name === '') {
                $name = $raw;
                $warnings[] = "Row $line: no name, the code $raw is used as the name.";
            }
            if (($nums['price'] ?? 0) <= 0) {
                $nums['price'] = 0.0;
                $noPrice[] = $name;
            }
        }
        if ($bc !== '') {
            $bcOwner->execute([$cid, $bc]);
            $o = $bcOwner->fetchColumn();
            if ($o && (!$ex || (int)$o !== (int)$ex['id'])) {
                $errors[] = "Row $line: barcode $bc already belongs to another item.";
                continue;
            }
        }
        $cur = 0.0;
        $had = 0;
        if ($ex) {
            $stockNow->execute([$u['tenant_id'], $shop['id'], $ex['id']]);
            [$cur, $had] = array_map('floatval', $stockNow->fetch(PDO::FETCH_NUM));
        }
        $plan[] = ['line' => $line, 'sku' => $sku, 'bc' => $bc, 'name' => $name, 'ex' => $ex, 'n' => $nums, 'cur' => $cur, 'had' => $had,
                   'cat' => $cell($r, 'category'), 'uom' => $cell($r, 'uom'), 'ar' => $cell($r, 'name_ar')];
    }
    if ($renamed) {
        array_unshift($warnings, count($renamed) . ' code(s) had spaces or symbols and were adjusted, e.g. ' . implode(', ', array_slice($renamed, 0, 4)) . '. The name is unchanged; search works on both.');
    }
    if ($noPrice) {
        $warnings[] = count($noPrice) . ' new item(s) have no selling price yet (0), e.g. ' . implode(', ', array_slice($noPrice, 0, 3)) . '. Stockers enter it at the first scan, or set it in Items.';
    }
    $newItems = count(array_filter($plan, fn($p) => !$p['ex']));
    $changes = $itemsOnly ? 0 : count(array_filter($plan, fn($p) => abs($p['n']['qty'] - $p['cur']) > 0.0005));
    $summary = ['items_only' => $itemsOnly, 'rows' => count($plan), 'new_items' => $newItems, 'existing_items' => count($plan) - $newItems, 'stock_changes' => $changes,
                'errors' => array_slice($errors, 0, 100), 'error_count' => count($errors),
                'warnings' => array_slice($warnings, 0, 300), 'warning_count' => count($warnings),
                'preview' => array_map(fn($p) => ['line' => $p['line'], 'code' => $p['sku'], 'name' => $p['name'] ?: ($p['ex']['name'] ?? ''), 'is_new' => !$p['ex'], 'was' => $p['cur'], 'qty' => $p['n']['qty']], array_slice($plan, 0, 15))];
    if ($errors || $dry) {
        if ($errors && !$dry) {
            json_out(['error' => 'import_errors'] + $summary, 422);
        }
        json_out($summary + ['dry' => true]);
    }
    $pdo->beginTransaction();
    $insI = $pdo->prepare('INSERT INTO items (tenant_id, company_id, sku, name, name_ar, category, uom, price, cost, vat_rate, reorder_level, active) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)');
    $insB = $pdo->prepare('INSERT IGNORE INTO item_barcodes (tenant_id, company_id, item_id, barcode) VALUES (?,?,?,?)');
    $insL = $pdo->prepare("INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, note, user_id) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($plan as $p) {
        $n = $p['n'];
        if ($p['ex']) {
            $id = (int)$p['ex']['id'];
            $set = [];
            $args = [];
            foreach (['name' => $p['name'] ?: null, 'name_ar' => $p['ar'] ?: null, 'category' => $p['cat'] ?: null, 'uom' => $p['uom'] ?: null,
                      'price' => $n['price'], 'cost' => $n['cost'], 'vat_rate' => $n['vat'], 'reorder_level' => $n['reorder']] as $col => $val) {
                if ($val !== null) {
                    $set[] = "$col = ?";
                    $args[] = $val;
                }
            }
            if ($set) {
                $args[] = $id;
                $pdo->prepare('UPDATE items SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
            }
            $cost = $n['cost'] ?? (float)$p['ex']['cost'];
        } else {
            $insI->execute([$u['tenant_id'], $cid, $p['sku'], $p['name'], $p['ar'] ?: null, $p['cat'] ?: null, $p['uom'] ?: 'pc', $n['price'], $n['cost'] ?? 0, $n['vat'] ?? 5, $n['reorder'] ?? 0]);
            $id = (int)$pdo->lastInsertId();
            $cost = $n['cost'] ?? 0.0;
        }
        if ($p['bc'] !== '') {
            $insB->execute([$u['tenant_id'], $cid, $id, $p['bc']]);
        }
        $delta = $itemsOnly ? 0.0 : round($n['qty'] - $p['cur'], 3);
        if (abs($delta) > 0.0005) {
            $insL->execute([$u['tenant_id'], $shop['id'], $id, $delta, $cost, $p['had'] > 0 ? 'adjust' : 'opening', 'Excel import', $u['id']]);
        }
    }
    $pdo->commit();
    audit($u, 'stock_import', ($itemsOnly ? 'items only, ' : '') . "shop {$shop['code']} rows " . count($plan) . " new $newItems stock changes $changes");
    json_out($summary + ['imported' => true]);
}

/** Scan-count helper: find an item by code or barcode and say how many are in the shop now. */
function h_stock_lookup(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $shop = assert_shop($u, (int)($_GET['shop_id'] ?? 0));
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') {
        fail('missing_field');
    }
    $st = db()->prepare(
        'SELECT i.id, i.sku, i.name, i.price, i.cost, i.uom FROM items i
          WHERE i.tenant_id = ? AND i.company_id = ? AND (i.sku = ? OR i.id IN (SELECT item_id FROM item_barcodes WHERE company_id = ? AND barcode = ?)) LIMIT 1'
    );
    $st->execute([$u['tenant_id'], $shop['company_id'], $code, $shop['company_id'], $code]);
    $it = $st->fetch();
    if (!$it && ctype_digit($code)) {
        $it = take_find_item($u['tenant_id'], (int)$shop['company_id'], $code);   // 000000016587 = 16587
    }
    if (!$it) {
        json_out(['found' => false, 'code' => $code]);
    }
    $q = db()->prepare('SELECT COALESCE(SUM(qty),0) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? AND item_id = ?');
    $q->execute([$u['tenant_id'], $shop['id'], $it['id']]);
    json_out(['found' => true, 'item' => $it, 'stock' => (float)$q->fetchColumn()]);
}

/**
 * Current-stock entry by scanning (phone or scanner). POST: shop_id, qty, mode ('set' = this is the total counted, 'add' = add to what is there),
 * and either item_id, or new item details (barcode, name, price, cost, vat_rate, uom, category) to create it first.
 */
function h_stock_count(): void
{
    $u = auth(['owner', 'manager']);
    $b = body();
    $shop = assert_shop($u, (int)($b['shop_id'] ?? 0));
    $cid = (int)$shop['company_id'];
    $qty = round(num($b['qty'] ?? null, 0, 1e7), 3);
    $mode = ($b['mode'] ?? 'set') === 'add' ? 'add' : 'set';
    $pdo = db();
    $created = false;
    $pdo->beginTransaction();
    $itemId = (int)($b['item_id'] ?? 0);
    if ($itemId > 0) {
        $it = item_in_company($u, $itemId, $cid);
    } else {
        $bc = trim((string)($b['barcode'] ?? ''));
        $sku = trim((string)($b['sku'] ?? '')) ?: $bc;
        if (!preg_match('/^[A-Za-z0-9._\-\/]{1,40}$/', $sku)) {
            fail('bad_sku');
        }
        if ($bc !== '' && !preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $bc)) {
            fail('bad_barcode');
        }
        $name = str($b['name'] ?? null, 200, true);
        $price = round(num($b['price'] ?? 0, 0.01, 1e7), 2);
        $cost = round(num($b['cost'] ?? 0, 0, 1e7), 4);
        $vat = round(num($b['vat_rate'] ?? 5, 0, 100), 2);
        $pdo->prepare('INSERT INTO items (tenant_id, company_id, sku, name, category, uom, price, cost, vat_rate, reorder_level, active) VALUES (?,?,?,?,?,?,?,?,?,0,1)')
            ->execute([$u['tenant_id'], $cid, $sku, $name, str($b['category'] ?? null, 80), str($b['uom'] ?? 'pc', 10) ?? 'pc', $price, $cost, $vat]);
        $itemId = (int)$pdo->lastInsertId();
        if ($bc !== '') {
            $pdo->prepare('INSERT INTO item_barcodes (tenant_id, company_id, item_id, barcode) VALUES (?,?,?,?)')->execute([$u['tenant_id'], $cid, $itemId, $bc]);
        }
        $it = ['id' => $itemId, 'name' => $name, 'cost' => $cost];
        $created = true;
    }
    $q = $pdo->prepare('SELECT COALESCE(SUM(qty),0), COUNT(*) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? AND item_id = ?');
    $q->execute([$u['tenant_id'], $shop['id'], $itemId]);
    [$was, $had] = array_map('floatval', $q->fetch(PDO::FETCH_NUM));
    $delta = $mode === 'add' ? $qty : round($qty - $was, 3);
    if (abs($delta) > 0.0005) {
        $pdo->prepare('INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, note, user_id) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$u['tenant_id'], $shop['id'], $itemId, $delta, $it['cost'], $had > 0 ? 'adjust' : 'opening', 'Scan count (' . $mode . ')', $u['id']]);
    }
    $pdo->commit();
    audit($u, 'stock_count', "shop {$shop['code']} item $itemId $mode $qty was $was" . ($created ? ' (new item)' : ''));
    json_out(['ok' => true, 'item_id' => $itemId, 'name' => $it['name'], 'was' => $was, 'now' => round($was + $delta, 3), 'created' => $created]);
}

/**
 * Remember a barcode for an existing item (Item master scan: "this new barcode is item X").
 * POST company_id, item_id, barcode. A barcode can belong to one item only.
 */
function h_item_barcode_link(): void
{
    $u = items_editor(true);   // purchase team: "this new barcode on the bill is our item X"
    $b = body();
    $cid = (int)($b['company_id'] ?? 0);
    assert_company($u, $cid);
    $it = item_in_company($u, (int)($b['item_id'] ?? 0), $cid);
    $bc = trim((string)($b['barcode'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $bc)) {
        fail('bad_barcode');
    }
    item_barcode_remember(db(), $u['tenant_id'], $cid, (int)$it['id'], $bc);
    audit($u, 'item_barcode_link', "item {$it['id']} barcode $bc");
    json_out(['ok' => true, 'item_id' => (int)$it['id'], 'barcode' => $bc]);
}

/** Shared by Item master and Stock take: add a barcode to an item, refusing one that belongs to another item. */
function item_barcode_remember(PDO $pdo, int $tenantId, int $cid, int $itemId, string $bc): void
{
    $st = $pdo->prepare('SELECT b.item_id, i.name FROM item_barcodes b JOIN items i ON i.id = b.item_id WHERE b.company_id = ? AND b.barcode = ?');
    $st->execute([$cid, $bc]);
    $o = $st->fetch();
    if ($o) {
        if ((int)$o['item_id'] === $itemId) {
            return;
        }
        fail('barcode_taken:' . $o['name'], 409);
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM item_barcodes WHERE item_id = ?');
    $st->execute([$itemId]);
    if ((int)$st->fetchColumn() >= 10) {
        fail('too_many_barcodes');
    }
    $pdo->prepare('INSERT INTO item_barcodes (tenant_id, company_id, item_id, barcode) VALUES (?,?,?,?)')->execute([$tenantId, $cid, $itemId, $bc]);
}

/**
 * Item master scan list -> stock, in one go. POST shop_id, mode ('add' | 'set'), lines [{item_id, qty}].
 * add = put the counted quantity on top of the stock; set = the stock becomes the counted quantity.
 */
function h_stock_count_batch(): void
{
    $u = auth(['owner', 'manager']);
    $b = body();
    $shop = assert_shop($u, (int)($b['shop_id'] ?? 0));
    $cid = (int)$shop['company_id'];
    $mode = ($b['mode'] ?? 'add') === 'set' ? 'set' : 'add';
    $lines = (array)($b['lines'] ?? []);
    if (!$lines || count($lines) > 5000) {
        fail('bad_lines');
    }
    $pdo = db();
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT COALESCE(SUM(qty),0), COUNT(*) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? AND item_id = ?');
    $ins = $pdo->prepare('INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, note, user_id) VALUES (?,?,?,?,?,?,?,?)');
    $thr = (float)settings_for($u['tenant_id'])['low_stock_threshold'];
    $out = [];
    $seen = [];
    foreach ($lines as $l) {
        $iid = (int)($l['item_id'] ?? 0);
        if (isset($seen[$iid])) {
            fail('bad_lines');   // one line per item
        }
        $seen[$iid] = true;
        $it = item_in_company($u, $iid, $cid);
        $qty = round(num($l['qty'] ?? null, 0, 1e7), 3);
        $q->execute([$u['tenant_id'], $shop['id'], $iid]);
        [$was, $had] = array_map('floatval', $q->fetch(PDO::FETCH_NUM));
        $delta = $mode === 'add' ? $qty : round($qty - $was, 3);
        if (abs($delta) > 0.0005) {
            $ins->execute([$u['tenant_id'], $shop['id'], $iid, $delta, $it['cost'], $had > 0 ? 'adjust' : 'opening', 'Item master scan (' . $mode . ')', $u['id']]);
        }
        $now = round($was + $delta, 3);
        notify_low_stock($pdo, $u['tenant_id'], $cid, (int)$shop['id'], $iid, $now, $thr, (string)$it['name'], (string)$shop['name']);
        $out[] = ['item_id' => $iid, 'name' => $it['name'], 'was' => $was, 'now' => $now];
    }
    $pdo->commit();
    audit($u, 'stock_count_batch', "shop {$shop['code']} $mode " . count($out) . ' items');
    json_out(['ok' => true, 'mode' => $mode, 'lines' => $out]);
}

/**
 * Stock report (like the stock list of most shop programs): code, description, qty, unit, cost, selling price, values.
 * GET company_id, shop_id (0 = all shops together), show = all | instock | zero | negative, q = search.
 */
function h_stock_report(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $shopId = (int)($_GET['shop_id'] ?? 0);
    if ($u['shop_id'] !== null) {
        $shopId = $u['shop_id'];
    }
    $shop = null;
    if ($shopId > 0) {
        $shop = assert_shop($u, $shopId);
    }
    $sql = 'SELECT i.id, i.sku, i.name, i.category, i.uom, i.cost, i.price, i.active,
                   (SELECT MIN(barcode) FROM item_barcodes b WHERE b.item_id = i.id) AS barcode,
                   COALESCE((SELECT SUM(l.qty) FROM stock_ledger l WHERE l.tenant_id = i.tenant_id AND l.item_id = i.id' . ($shopId > 0 ? ' AND l.shop_id = ?' : ' AND l.shop_id IN (SELECT id FROM shops WHERE company_id = i.company_id)') . '), 0) AS qty
              FROM items i WHERE i.tenant_id = ? AND i.company_id = ?';
    $args = $shopId > 0 ? [$shopId, $u['tenant_id'], $cid] : [$u['tenant_id'], $cid];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $sql .= ' AND (i.name LIKE ? OR i.sku LIKE ? OR i.category LIKE ? OR i.id IN (SELECT item_id FROM item_barcodes WHERE company_id = ? AND barcode LIKE ?))';
        array_push($args, $like, $like, $like, $cid, $like);
    }
    $st = db()->prepare($sql . ' ORDER BY i.name, i.sku LIMIT 20000');
    $st->execute($args);
    $show = (string)($_GET['show'] ?? 'all');
    $rows = [];
    $tot = ['items' => 0, 'qty' => 0.0, 'value_cost' => 0.0, 'value_price' => 0.0, 'negative' => 0, 'zero' => 0];
    foreach ($st->fetchAll() as $r) {
        $qty = round((float)$r['qty'], 3);
        if (($show === 'instock' && $qty <= 0) || ($show === 'zero' && abs($qty) > 0.0005) || ($show === 'negative' && $qty >= 0) || ($show === 'all' && !(int)$r['active'] && abs($qty) < 0.0005)) {
            continue;
        }
        $vc = round($qty * (float)$r['cost'], 2);
        $vp = round($qty * (float)$r['price'], 2);
        $rows[] = ['id' => (int)$r['id'], 'sku' => $r['sku'], 'name' => $r['name'], 'category' => $r['category'], 'barcode' => $r['barcode'], 'uom' => $r['uom'],
                   'qty' => $qty, 'cost' => (float)$r['cost'], 'price' => (float)$r['price'], 'value_cost' => $vc, 'value_price' => $vp];
        $tot['items']++;
        $tot['qty'] += $qty;
        $tot['value_cost'] += $vc;
        $tot['value_price'] += $vp;
        $tot['negative'] += $qty < 0 ? 1 : 0;
        $tot['zero'] += abs($qty) < 0.0005 ? 1 : 0;
    }
    $tot['qty'] = round($tot['qty'], 3);
    $tot['value_cost'] = round($tot['value_cost'], 2);
    $tot['value_price'] = round($tot['value_price'], 2);
    $co = assert_company($u, $cid);
    json_out(['rows' => $rows, 'totals' => $tot, 'company' => $co['name'], 'shop' => $shop ? $shop['name'] . ' (' . $shop['code'] . ')' : 'All shops', 'at' => date('Y-m-d H:i')]);
}

/** Company details (name, Arabic name, TRN, address, phone, bill footer). Owner only; TRN goes on every tax invoice. */
function h_company_update(): void
{
    $u = auth(['owner']);
    $b = body();
    $c = assert_company($u, (int)($b['id'] ?? 0));
    $trn = str($b['trn'] ?? null, 20);
    if ($trn !== null && !preg_match('/^\d{15}$/', $trn)) {
        fail('bad_trn');
    }
    db()->prepare('UPDATE companies SET name = ?, name_ar = ?, trn = ?, address = ?, phone = ?, footer = ? WHERE id = ? AND tenant_id = ?')->execute([
        str($b['name'] ?? null, 150, true), str($b['name_ar'] ?? null, 150), $trn, str($b['address'] ?? null, 255),
        str($b['phone'] ?? null, 40), str($b['footer'] ?? null, 255), $c['id'], $u['tenant_id'],
    ]);
    audit($u, 'company_update', "company {$c['id']} name {$c['name']} -> " . ($b['name'] ?? '') . ' trn ' . ($c['trn'] ?? '-') . ' -> ' . ($trn ?? '-'));
    json_out(['ok' => true]);
}

/**
 * Owner only. Delete an item that was never on a bill. An item that is on sales, purchases or supplier returns cannot be
 * deleted (old bills and VAT need it): it is hidden instead (Active off). POST company_id, item_id, hide_if_used.
 */
function h_item_delete(): void
{
    $u = auth(['owner']);
    $b = body();
    $cid = (int)($b['company_id'] ?? 0);
    assert_company($u, $cid);
    $it = item_in_company($u, (int)($b['item_id'] ?? 0), $cid);
    $id = (int)$it['id'];
    $pdo = db();
    $used = 0;
    foreach (['SELECT COUNT(*) FROM sale_lines WHERE item_id = ?', 'SELECT COUNT(*) FROM purchase_lines WHERE item_id = ?', 'SELECT COUNT(*) FROM purchase_return_lines WHERE item_id = ?'] as $q) {
        try {
            $st = $pdo->prepare($q);
            $st->execute([$id]);
            $used += (int)$st->fetchColumn();
        } catch (PDOException $e) { /* table not there on an old database */ }
    }
    if ($used > 0) {
        if (empty($b['hide_if_used'])) {
            fail('item_used:' . $used, 409);
        }
        $pdo->prepare('UPDATE items SET active = 0 WHERE id = ? AND tenant_id = ?')->execute([$id, $u['tenant_id']]);
        audit($u, 'item_hide', "item $id {$it['name']} (on $used bill line(s))");
        json_out(['ok' => true, 'hidden' => true, 'used' => $used]);
    }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM stock_take_lines l JOIN stock_takes t ON t.id = l.take_id WHERE l.item_id = ? AND t.status IN ('open','closed')");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            fail('item_in_stock_take', 409);
        }
    } catch (PDOException $e) { /* no stock takes yet */ }
    $st = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM stock_ledger WHERE tenant_id = ? AND item_id = ?');
    $st->execute([$u['tenant_id'], $id]);
    $stock = (float)$st->fetchColumn();
    $pdo->beginTransaction();
    foreach (['DELETE FROM stock_ledger WHERE item_id = ?', 'DELETE FROM item_barcodes WHERE item_id = ?', 'DELETE FROM notifications WHERE item_id = ?',
              'DELETE FROM label_prints WHERE item_id = ?', 'DELETE FROM items WHERE id = ?'] as $q) {
        try {
            $pdo->prepare($q)->execute([$id]);
        } catch (PDOException $e) {
            if (!str_contains($q, 'label_prints')) {
                throw $e;
            }
        }
    }
    $pdo->commit();
    audit($u, 'item_delete', "item $id {$it['name']} stock was $stock");
    json_out(['ok' => true, 'deleted' => true, 'stock_was' => $stock]);
}
