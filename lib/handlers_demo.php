<?php
declare(strict_types=1);
// Console: ABC demo company, clear items and stock for a fresh Excel start, roll-out control.

/** Creates tenant "ABC Demo": company ABC Trading, two shops, three logins, sample items with opening stock. */
function demo_seed(PDO $pdo): array
{
    $rel = release_state();
    $latest = $rel['latest']['version'] ?? null;
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO tenants (name, plan, contact_name, notes, update_mode, pushed_version, is_demo) VALUES (?,?,?,?, 'auto', ?, 1)")
        ->execute(['ABC Demo', 'demo', 'Adnys', 'Demo company for training. Gets every new version automatically.', $latest]);
    $tid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO companies (tenant_id, name, name_ar, trn, address, phone, footer) VALUES (?,?,?,?,?,?,?)')
        ->execute([$tid, 'ABC Trading LLC', 'شركة إيه بي سي للتجارة ذ.م.م', '100000000000003', 'Demo address, Dubai, UAE', '+971 4 000 0000', 'Thank you / شكراً لزيارتكم']);
    $cid = (int)$pdo->lastInsertId();
    $shops = [];
    foreach ([['ABC001', 'ABC Grocery', 'grocery'], ['ABC002', 'ABC Mobiles', 'mobile']] as [$code, $name, $trade]) {
        $pdo->prepare('INSERT INTO shops (tenant_id, company_id, code, group_code, name, trade) VALUES (?,?,?,?,?,?)')->execute([$tid, $cid, $code, 'ABC', $name, $trade]);
        $sid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO registers (tenant_id, shop_id, code) VALUES (?,?,?)')->execute([$tid, $sid, 'R1']);
        $shops[$code] = $sid;
    }
    $creds = [];
    foreach ([['Demo Owner', 'owner', null], ['Demo Manager', 'manager', null], ['Demo Cashier', 'cashier', $shops['ABC001']]] as [$nm, $role, $shopId]) {
        $pass = gen_pass();
        $pdo->prepare('INSERT INTO users (tenant_id, name, username, email, password_hash, role, shop_id, is_primary) VALUES (?,?,NULL,NULL,?,?,?,?)')
            ->execute([$tid, $nm, password_hash($pass, PASSWORD_DEFAULT), $role, $shopId, $role === 'owner' ? 1 : 0]);
        $uid = (int)$pdo->lastInsertId();
        $un = gen_username($pdo, $tid, $shops['ABC001'], $nm);
        $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$un, $uid]);
        $creds[] = ['role' => $role, 'name' => $nm, 'username' => $un, 'password' => $pass];
    }
    $pdo->prepare('INSERT INTO suppliers (tenant_id, company_id, name, phone, trn, opening_balance) VALUES (?,?,?,?,?,0)')->execute([$tid, $cid, 'Al Noor Wholesale (demo)', '+971 50 000 0001', null]);
    $pdo->prepare('INSERT INTO suppliers (tenant_id, company_id, name, phone, trn, opening_balance) VALUES (?,?,?,?,?,0)')->execute([$tid, $cid, 'Gulf Gadgets Trading (demo)', '+971 50 000 0002', null]);
    $items = [   // sku, name, arabic, category, uom, cost, price(VAT incl), barcode, qty, shop
        ['RICE5', 'Basmati Rice 5kg', 'أرز بسمتي 5 كجم', 'Grocery', 'pc', 22.00, 31.50, '6290000000011', 40, 'ABC001'],
        ['SUGAR1', 'Sugar 1kg', 'سكر 1 كجم', 'Grocery', 'pc', 3.20, 4.75, '6290000000028', 80, 'ABC001'],
        ['OIL15', 'Sunflower Oil 1.5L', 'زيت دوار الشمس 1.5 لتر', 'Grocery', 'pc', 9.50, 13.95, '6290000000035', 36, 'ABC001'],
        ['MILK1', 'Fresh Milk 1L', 'حليب طازج 1 لتر', 'Dairy', 'pc', 4.10, 5.95, '6290000000042', 60, 'ABC001'],
        ['WATER5', 'Drinking Water 5L', 'مياه شرب 5 لتر', 'Drinks', 'pc', 3.00, 4.50, '6290000000059', 50, 'ABC001'],
        ['TEA100', 'Black Tea 100 bags', 'شاي أسود 100 كيس', 'Grocery', 'pc', 8.00, 11.95, '6290000000066', 25, 'ABC001'],
        ['EGGS30', 'Eggs tray 30', 'بيض 30 حبة', 'Dairy', 'pc', 11.50, 16.50, '6290000000073', 20, 'ABC001'],
        ['BREAD', 'Arabic Bread pack', 'خبز عربي', 'Bakery', 'pc', 1.40, 2.00, '6290000000080', 100, 'ABC001'],
        ['CHRG20', 'USB-C Charger 20W', 'شاحن 20 واط', 'Accessories', 'pc', 18.00, 39.00, '6290000000097', 30, 'ABC002'],
        ['CABLE1', 'USB-C Cable 1m', 'كابل 1 متر', 'Accessories', 'pc', 4.00, 12.00, '6290000000103', 60, 'ABC002'],
        ['CASE14', 'Phone Case 14 Pro', 'غطاء هاتف', 'Accessories', 'pc', 6.00, 19.00, '6290000000110', 25, 'ABC002'],
        ['EARBD', 'Wireless Earbuds', 'سماعات لاسلكية', 'Audio', 'pc', 45.00, 89.00, '6290000000127', 12, 'ABC002'],
    ];
    $insI = $pdo->prepare('INSERT INTO items (tenant_id, company_id, sku, name, name_ar, category, uom, price, cost, vat_rate, reorder_level) VALUES (?,?,?,?,?,?,?,?,?,5,5)');
    $insB = $pdo->prepare('INSERT INTO item_barcodes (tenant_id, company_id, item_id, barcode) VALUES (?,?,?,?)');
    $insL = $pdo->prepare("INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, note) VALUES (?,?,?,?,?,'opening','Demo opening stock')");
    foreach ($items as [$sku, $name, $ar, $cat, $uom, $cost, $price, $bc, $qty, $shop]) {
        $insI->execute([$tid, $cid, $sku, $name, $ar, $cat, $uom, $price, $cost]);
        $iid = (int)$pdo->lastInsertId();
        $insB->execute([$tid, $cid, $iid, $bc]);
        $insL->execute([$tid, $shops[$shop], $iid, $qty, $cost]);
    }
    $pdo->commit();
    return ['tenant_id' => $tid, 'logins' => $creds, 'shops' => array_keys($shops)];
}

/** Admin only. Creates the ABC demo company (does not touch any other customer). */
function c_demo_create(): void
{
    $s = cauth(true);
    $pdo = db();
    if ($pdo->query('SELECT 1 FROM tenants WHERE is_demo = 1 LIMIT 1')->fetchColumn()) {
        fail('demo_exists', 409);
    }
    $demo = demo_seed($pdo);
    caudit($s, 'demo_create', "demo tenant #{$demo['tenant_id']}");
    json_out(['ok' => true] + $demo);
}

/**
 * Admin only. Keeps each customer's company, profile, shops, users and settings but removes ITEMS and STOCK,
 * so the shop can load its real stock once from Excel. Bills that point at items (sales, purchases, returns)
 * must go too, so they are only removed when include_history is set; without it, a customer that has bills is refused.
 */
function c_clear_stock(): void
{
    $s = cauth(true);
    $b = body();
    if (trim((string)($b['confirm'] ?? '')) !== 'CLEAR ITEMS AND STOCK') {
        fail('confirm_text_wrong');
    }
    $st = db()->prepare('SELECT password_hash FROM staff WHERE id = ?');
    $st->execute([$s['id']]);
    if (!password_verify((string)($b['password'] ?? ''), (string)$st->fetchColumn())) {
        fail('wrong_password', 403);
    }
    $pdo = db();
    $ids = !empty($b['all']) ? array_map('intval', $pdo->query('SELECT id FROM tenants WHERE is_demo = 0')->fetchAll(PDO::FETCH_COLUMN))
                             : [(int)tenant_must((int)($b['tenant_id'] ?? 0))['id']];
    $hist = !empty($b['include_history']);
    $masters = !empty($b['include_masters']) && $hist;   // also suppliers, expenses, customers: only company, shops, users, settings stay
    $cnt = fn(string $sql, int $t) => (function () use ($pdo, $sql, $t) { $q = $pdo->prepare($sql); $q->execute([$t]); return (int)$q->fetchColumn(); })();
    if (!$hist) {
        foreach ($ids as $t) {
            if ($cnt('SELECT COUNT(*) FROM sales WHERE tenant_id = ?', $t) || $cnt('SELECT COUNT(*) FROM purchases WHERE tenant_id = ?', $t)
                || $cnt('SELECT COUNT(*) FROM purchase_returns WHERE tenant_id = ?', $t)) {
                fail('has_history');
            }
        }
    }
    $done = [];
    $pdo->beginTransaction();
    try {
        foreach ($ids as $t) {
            $n = ['items' => $cnt('SELECT COUNT(*) FROM items WHERE tenant_id = ?', $t), 'stock_rows' => $cnt('SELECT COUNT(*) FROM stock_ledger WHERE tenant_id = ?', $t)];
            $q = function (string $sql) use ($pdo, $t): void { $pdo->prepare($sql)->execute([$t]); };
            if ($hist) {
                $q('DELETE l FROM sale_lines l JOIN sales s ON s.id = l.sale_id WHERE s.tenant_id = ?');
                $q('DELETE p FROM payments p JOIN sales s ON s.id = p.sale_id WHERE s.tenant_id = ?');
                $q('DELETE FROM sales WHERE tenant_id = ?');
                $q('DELETE l FROM purchase_lines l JOIN purchases p ON p.id = l.purchase_id WHERE p.tenant_id = ?');
                $q('DELETE FROM purchase_files WHERE tenant_id = ?');
                $q('DELETE FROM supplier_payments WHERE tenant_id = ?');
                $q('DELETE FROM purchases WHERE tenant_id = ?');
                $q('DELETE l FROM purchase_return_lines l JOIN purchase_returns r ON r.id = l.return_id WHERE r.tenant_id = ?');
                $q('DELETE FROM purchase_returns WHERE tenant_id = ?');
                $q('DELETE FROM daily_summary WHERE tenant_id = ?');
                $q('DELETE FROM daily_closings WHERE tenant_id = ?');
                $q('DELETE FROM authorisations WHERE tenant_id = ?');
            }
            if ($masters) {
                $q('DELETE FROM suppliers WHERE tenant_id = ?');
                $q('DELETE FROM expenses WHERE tenant_id = ?');
                try { $q('DELETE FROM customers WHERE tenant_id = ?'); } catch (PDOException $e) { /* no customers table yet */ }
            }
            try {   // stock takes point at items, so they go with them (1.10+)
                $q('DELETE FROM stock_take_scans WHERE tenant_id = ?');
                $q('DELETE FROM stock_take_lines WHERE tenant_id = ?');
                $q('DELETE FROM stock_takes WHERE tenant_id = ?');
            } catch (PDOException $e) { /* tables not created yet */ }
            $q('DELETE FROM notifications WHERE tenant_id = ?');
            $q('DELETE FROM label_prints WHERE tenant_id = ?');
            $q('DELETE FROM stock_ledger WHERE tenant_id = ?');
            $q('DELETE FROM item_barcodes WHERE tenant_id = ?');
            $q('DELETE FROM items WHERE tenant_id = ?');
            $done[] = ['tenant_id' => $t] + $n;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    caudit($s, 'clear_stock', json_encode(['tenants' => $ids, 'history' => $hist, 'suppliers_expenses_customers' => $masters]));
    json_out(['ok' => true, 'cleared' => $done]);
}

/** Admin only. Push a version to one customer (or all) and/or set auto/push mode. */
function c_rollout(): void
{
    $s = cauth(true);
    $b = body();
    $rel = release_state();
    $latest = $rel['latest']['version'] ?? null;
    $action = (string)($b['action'] ?? '');
    $pdo = db();
    if ($action === 'push') {
        if (!$latest) {
            fail('no_release');
        }
        if (!empty($b['all'])) {
            $pdo->prepare("UPDATE tenants SET pushed_version = ? WHERE update_mode = 'push'")->execute([$latest]);
            caudit($s, 'rollout_push_all', $latest);
        } else {
            $t = tenant_must((int)($b['tenant_id'] ?? 0));
            $pdo->prepare('UPDATE tenants SET pushed_version = ? WHERE id = ?')->execute([$latest, $t['id']]);
            caudit($s, 'rollout_push', "tenant #{$t['id']} $latest");
        }
    } elseif ($action === 'mode') {
        $t = tenant_must((int)($b['tenant_id'] ?? 0));
        $mode = ($b['mode'] ?? '') === 'auto' ? 'auto' : 'push';
        $pdo->prepare('UPDATE tenants SET update_mode = ? WHERE id = ?')->execute([$mode, $t['id']]);
        caudit($s, 'rollout_mode', "tenant #{$t['id']} $mode");
    } else {
        fail('bad_action');
    }
    json_out(['ok' => true]);
}
