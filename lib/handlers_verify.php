<?php
declare(strict_types=1);

/**
 * Public invoice check, opened by scanning the QR code on a bill. No login.
 * Sales are found by their random UUID, purchases by a secret token. Customer details, cashier names
 * and cost prices of sales are never returned.
 */
function h_verify(): void
{
    header('Cache-Control: no-store');
    $pdo = db();
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $s = (string)($_GET['s'] ?? '');
    $p = (string)($_GET['p'] ?? '');
    if (preg_match('/^[0-9a-f\-]{36}$/i', $s)) {
        $st = $pdo->prepare(
            'SELECT s.id, s.invoice_no, s.sold_at, s.type, s.total, s.vat_total, s.net_total,
                    sh.name AS shop_name, sh.code AS shop_code,
                    c.name AS company_name, c.name_ar AS company_name_ar, c.trn AS company_trn, c.logo AS company_logo
               FROM sales s JOIN shops sh ON sh.id = s.shop_id JOIN companies c ON c.id = s.company_id
              WHERE s.uuid = ?'
        );
        $st->execute([strtolower($s)]);
        $r = $st->fetch();
        if (!$r) {
            json_out(['found' => false, 'reason' => 'not_synced'], 404);
        }
        $st = $pdo->prepare(
            'SELECT i.name, l.qty, l.unit_price, l.discount, l.vat_rate, l.vat_amount, l.line_total
               FROM sale_lines l JOIN items i ON i.id = l.item_id WHERE l.sale_id = ? ORDER BY l.id'
        );
        $st->execute([$r['id']]);
        $lines = $st->fetchAll();
        $rates = [];
        foreach ($lines as $l) {
            $k = (string)(float)$l['vat_rate'];
            $rates[$k] = $rates[$k] ?? ['rate' => (float)$l['vat_rate'], 'vat' => 0.0, 'gross' => 0.0];
            $rates[$k]['vat'] += (float)$l['vat_amount'];
            $rates[$k]['gross'] += (float)$l['line_total'];
        }
        $by = [];
        foreach ($rates as $x) {
            $by[] = ['rate' => $x['rate'], 'vat' => round($x['vat'], 2), 'net' => round($x['gross'] - $x['vat'], 2)];
        }
        unset($r['id']);
        json_out(['found' => true, 'kind' => 'sale', 'doc' => $r + ['lines' => $lines, 'vat_by_rate' => $by]]);
    }
    if (preg_match('/^[0-9a-f]{24}$/', $p)) {
        $st = $pdo->prepare(
            'SELECT p.id, p.bill_no, p.bill_date, p.subtotal, p.vat_total, p.total,
                    s.name AS supplier_name, s.trn AS supplier_trn, sh.name AS shop_name,
                    c.name AS company_name, c.trn AS company_trn, c.logo AS company_logo
               FROM purchases p JOIN suppliers s ON s.id = p.supplier_id JOIN shops sh ON sh.id = p.shop_id JOIN companies c ON c.id = p.company_id
              WHERE p.verify_token = ?'
        );
        $st->execute([$p]);
        $r = $st->fetch();
        if (!$r) {
            json_out(['found' => false, 'reason' => 'unknown'], 404);
        }
        $st = $pdo->prepare('SELECT i.name, l.qty, l.unit_cost, l.vat_rate, l.vat_amount, l.line_total FROM purchase_lines l JOIN items i ON i.id = l.item_id WHERE l.purchase_id = ? ORDER BY l.id');
        $st->execute([$r['id']]);
        unset($r['id']);
        json_out(['found' => true, 'kind' => 'purchase', 'doc' => $r + ['lines' => $st->fetchAll()]]);
    }
    json_out(['found' => false, 'reason' => 'bad_link'], 400);
}
