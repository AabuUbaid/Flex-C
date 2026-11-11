<?php
declare(strict_types=1);

/**
 * Integrity checks ("proof that stock and accounts tally") for one company and date range.
 * Every check returns how many records it looked at and the exceptions it found.
 */
function h_reconcile(): void
{
    $u = auth(['owner', 'manager', 'accountant']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-01'));
    $t = (int)$u['tenant_id'];
    $pdo = db();
    $q = function (string $sql, array $args) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    };
    $one = fn(string $sql, array $args) => (int)($q($sql, $args)[0]['n'] ?? 0);
    $checks = [];
    $add = function (string $key, string $title, string $how, int $checked, array $issues) use (&$checks): void {
        $checks[] = ['key' => $key, 'title' => $title, 'how' => $how, 'checked' => $checked, 'issues' => $issues];
    };

    // 1. Purchase bill total = sum of its lines = the total typed from the paper bill.
    $n = $one('SELECT COUNT(*) n FROM purchases WHERE tenant_id = ? AND company_id = ? AND bill_date BETWEEN ? AND ?', [$t, $cid, $from, $to]);
    $rows = $q(
        'SELECT p.id, p.bill_no, p.total, p.declared_total, COALESCE(SUM(l.line_total), 0) AS lines_total
           FROM purchases p LEFT JOIN purchase_lines l ON l.purchase_id = p.id
          WHERE p.tenant_id = ? AND p.company_id = ? AND p.bill_date BETWEEN ? AND ?
          GROUP BY p.id HAVING ABS(p.total - lines_total) > 0.02 OR (p.declared_total IS NOT NULL AND ABS(p.declared_total - p.total) > 0.05)',
        [$t, $cid, $from, $to]
    );
    $add('bill_totals', 'Purchase bill totals', 'Bill total = sum of lines = total typed from the supplier bill', $n,
        array_map(fn($r) => "Bill {$r['bill_no']}: total {$r['total']}, lines {$r['lines_total']}" . ($r['declared_total'] !== null ? ", supplier bill {$r['declared_total']}" : ''), $rows));

    // 2. Every purchase line is in the stock ledger with the received quantity.
    $n = $one('SELECT COUNT(*) n FROM purchase_lines l JOIN purchases p ON p.id = l.purchase_id WHERE p.tenant_id = ? AND p.company_id = ? AND p.bill_date BETWEEN ? AND ?', [$t, $cid, $from, $to]);
    $rows = $q(
        "SELECT p.bill_no, i.name, SUM(COALESCE(l.recv_qty, l.qty)) AS lq,
                (SELECT COALESCE(SUM(s.qty), 0) FROM stock_ledger s WHERE s.tenant_id = p.tenant_id AND s.ref_type = 'purchase' AND s.ref_id = p.id AND s.item_id = l.item_id) AS sq
           FROM purchases p JOIN purchase_lines l ON l.purchase_id = p.id JOIN items i ON i.id = l.item_id
          WHERE p.tenant_id = ? AND p.company_id = ? AND p.bill_date BETWEEN ? AND ?
          GROUP BY p.id, l.item_id HAVING ABS(lq - sq) > 0.0005",
        [$t, $cid, $from, $to]
    );
    $add('purchase_stock', 'Purchases reached the stock', 'Quantity received on each purchase line = quantity added to stock', $n,
        array_map(fn($r) => "Bill {$r['bill_no']}, {$r['name']}: received " . (float)$r['lq'] . ', stock added ' . (float)$r['sq'], $rows));

    // 3. Short / excess deliveries still on record.
    $rows = $q(
        'SELECT p.bill_no, i.name, l.qty, l.recv_qty FROM purchase_lines l JOIN purchases p ON p.id = l.purchase_id JOIN items i ON i.id = l.item_id
          WHERE p.tenant_id = ? AND p.company_id = ? AND p.bill_date BETWEEN ? AND ? AND l.recv_qty IS NOT NULL',
        [$t, $cid, $from, $to]
    );
    $add('delivery', 'Delivery differences', 'Billed quantity vs quantity actually received (claim these from the supplier)', $n,
        array_map(fn($r) => "Bill {$r['bill_no']}, {$r['name']}: billed " . (float)$r['qty'] . ', received ' . (float)$r['recv_qty'], $rows));

    // 4. Sales: bill total = sum of lines; payments cover the bill; stock reduced by exactly the quantity sold.
    $n = $one('SELECT COUNT(*) n FROM sales WHERE tenant_id = ? AND company_id = ? AND DATE(sold_at) BETWEEN ? AND ?', [$t, $cid, $from, $to]);
    $rows = $q(
        'SELECT s.invoice_no, s.total, COALESCE((SELECT SUM(line_total) FROM sale_lines x WHERE x.sale_id = s.id), 0) AS lt,
                COALESCE((SELECT SUM(amount) FROM payments y WHERE y.sale_id = s.id), 0) AS paid
           FROM sales s WHERE s.tenant_id = ? AND s.company_id = ? AND DATE(s.sold_at) BETWEEN ? AND ?
         HAVING ABS(s.total - lt) > 0.02 OR ABS(s.total - paid) > 0.02',
        [$t, $cid, $from, $to]
    );
    $add('sales_totals', 'Sales totals and payments', 'Bill total = sum of lines = cash + card received', $n,
        array_map(fn($r) => "Invoice {$r['invoice_no']}: total {$r['total']}, lines {$r['lt']}, paid {$r['paid']}", $rows));
    $rows = $q(
        "SELECT s.invoice_no, i.name, SUM(l.qty) AS lq,
                (SELECT COALESCE(-SUM(k.qty), 0) FROM stock_ledger k WHERE k.tenant_id = s.tenant_id AND k.ref_type = 'sale' AND k.ref_id = s.id AND k.item_id = l.item_id) AS kq
           FROM sales s JOIN sale_lines l ON l.sale_id = s.id JOIN items i ON i.id = l.item_id
          WHERE s.tenant_id = ? AND s.company_id = ? AND DATE(s.sold_at) BETWEEN ? AND ?
          GROUP BY s.id, l.item_id HAVING ABS(lq - kq) > 0.0005",
        [$t, $cid, $from, $to]
    );
    $add('sales_stock', 'Sales reached the stock', 'Quantity sold on each bill = quantity taken out of stock', $n,
        array_map(fn($r) => "Invoice {$r['invoice_no']}, {$r['name']}: sold " . (float)$r['lq'] . ', stock moved ' . (float)$r['kq'], $rows));

    // 5. Negative stock (selling goods that were never booked in).
    $n = $one('SELECT COUNT(DISTINCT l.shop_id, l.item_id) n FROM stock_ledger l JOIN shops s ON s.id = l.shop_id WHERE l.tenant_id = ? AND s.company_id = ?', [$t, $cid]);
    $rows = $q(
        'SELECT s.name AS shop, i.name, SUM(l.qty) AS q FROM stock_ledger l JOIN shops s ON s.id = l.shop_id JOIN items i ON i.id = l.item_id
          WHERE l.tenant_id = ? AND s.company_id = ? GROUP BY l.shop_id, l.item_id HAVING q < -0.0005',
        [$t, $cid]
    );
    $add('negative', 'Negative stock', 'Stock below zero means a purchase or opening stock was not entered', $n,
        array_map(fn($r) => "{$r['shop']}: {$r['name']} = " . (float)$r['q'], $rows));

    // 5b. Goods returned to suppliers left the stock.
    $n = $one('SELECT COUNT(*) n FROM purchase_return_lines l JOIN purchase_returns r ON r.id = l.return_id WHERE r.tenant_id = ? AND r.company_id = ? AND r.return_date BETWEEN ? AND ?', [$t, $cid, $from, $to]);
    $rows = $q(
        "SELECT r.note_no, i.name, SUM(l.qty) AS lq,
                (SELECT COALESCE(-SUM(k.qty), 0) FROM stock_ledger k WHERE k.tenant_id = r.tenant_id AND k.ref_type = 'purchase_return' AND k.ref_id = r.id AND k.item_id = l.item_id) AS kq
           FROM purchase_returns r JOIN purchase_return_lines l ON l.return_id = r.id JOIN items i ON i.id = l.item_id
          WHERE r.tenant_id = ? AND r.company_id = ? AND r.return_date BETWEEN ? AND ? GROUP BY r.id, l.item_id HAVING ABS(lq - kq) > 0.0005",
        [$t, $cid, $from, $to]
    );
    $add('supplier_returns', 'Supplier returns left the stock', 'Quantity on each debit note = quantity taken out of stock', $n,
        array_map(fn($r) => "{$r['note_no']}, {$r['name']}: returned " . (float)$r['lq'] . ', stock moved ' . (float)$r['kq'], $rows));

    // 6. Supplier accounts: paid never more than billed; balance = billed - paid.
    $n = $one('SELECT COUNT(*) n FROM suppliers WHERE tenant_id = ? AND company_id = ?', [$t, $cid]);
    $rows = $q(
        'SELECT s.name, COALESCE((SELECT SUM(total) FROM purchases p WHERE p.supplier_id = s.id), 0) AS billed,
                COALESCE((SELECT SUM(amount) FROM supplier_payments x WHERE x.supplier_id = s.id), 0) AS paid
           FROM suppliers s WHERE s.tenant_id = ? AND s.company_id = ? HAVING paid > billed + 0.01',
        [$t, $cid]
    );
    $add('suppliers', 'Supplier accounts', 'Payments never exceed bills (otherwise a bill is missing)', $n,
        array_map(fn($r) => "{$r['name']}: billed {$r['billed']}, paid {$r['paid']}", $rows));

    // 7. Stock added by hand (not from a purchase bill). Each one should have a reason; double entries show here.
    $rows = $q(
        "SELECT l.created_at, i.name, l.qty, l.unit_cost, l.note,
                (SELECT p.bill_no FROM purchases p JOIN purchase_lines pl ON pl.purchase_id = p.id
                  WHERE p.tenant_id = l.tenant_id AND p.shop_id = l.shop_id AND pl.item_id = l.item_id
                    AND p.created_at BETWEEN (l.created_at - INTERVAL 7 DAY) AND l.created_at ORDER BY p.id DESC LIMIT 1) AS bill
           FROM stock_ledger l JOIN items i ON i.id = l.item_id JOIN shops s ON s.id = l.shop_id
          WHERE l.tenant_id = ? AND s.company_id = ? AND l.ref_type = 'adjust' AND l.qty > 0 AND l.note LIKE 'POS stock in%' AND DATE(l.created_at) BETWEEN ? AND ?",
        [$t, $cid, $from, $to]
    );
    $add('manual', 'Stock added without a purchase bill', 'Best practice: stock comes only from purchase bills. Manual adds are listed so none is forgotten or doubled', count($rows),
        array_map(fn($r) => substr((string)$r['created_at'], 0, 16) . " {$r['name']} +" . (float)$r['qty'] . ($r['bill'] ? " (a bill {$r['bill']} for this item was entered in the 7 days before: possible double count)" : ' (no bill on record)'), $rows));

    $bad = 0;
    foreach ($checks as $c) {
        if (!in_array($c['key'], ['manual', 'delivery'], true)) {
            $bad += count($c['issues']);
        }
    }
    json_out(['from' => $from, 'to' => $to, 'checks' => $checks, 'errors' => $bad, 'run_at' => date('c')]);
}
