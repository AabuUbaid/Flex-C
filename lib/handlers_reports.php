<?php
declare(strict_types=1);

/**
 * 1.2.0 reports, cash-up, settings, logos, users and notifications.
 * Every query is tenant-scoped from the token; shop-restricted users only see their shop.
 */

function register_must(array $u, int $registerId): array
{
    $st = db()->prepare(
        'SELECT r.id, r.code, r.shop_id, s.company_id, s.name AS shop_name, s.code AS shop_code,
                s.group_code, s.logo AS shop_logo, c.name AS company_name, c.name_ar AS company_name_ar,
                c.trn AS company_trn, c.address AS company_address, c.phone AS company_phone, c.logo AS company_logo
           FROM registers r
           JOIN shops s ON s.id = r.shop_id
           JOIN companies c ON c.id = s.company_id
          WHERE r.id = ? AND r.tenant_id = ?'
    );
    $st->execute([$registerId, $u['tenant_id']]);
    $reg = $st->fetch();
    if (!$reg) {
        fail('register_not_found', 404);
    }
    assert_shop($u, (int)$reg['shop_id']);
    return $reg;
}

/** Sales, VAT, cost and payment totals for one register on one day (returns are negative). */
function day_totals(int $tenantId, int $registerId, string $date): array
{
    $st = db()->prepare(
        "SELECT COUNT(*) AS txn_count,
                COALESCE(SUM(type = 'sale'), 0) AS sale_count,
                COALESCE(SUM(type = 'return'), 0) AS return_count,
                COALESCE(SUM(gross), 0) AS gross,
                COALESCE(SUM(discount), 0) AS discount,
                COALESCE(SUM(total), 0) AS net_sales,
                COALESCE(SUM(vat_total), 0) AS vat_total,
                COALESCE(SUM(net_total), 0) AS net_ex_vat,
                COALESCE(SUM(cogs), 0) AS cogs,
                COALESCE(SUM(IF(type = 'return', -total, 0)), 0) AS returns_total
           FROM sales
          WHERE tenant_id = ? AND register_id = ? AND DATE(sold_at) = ?"
    );
    $st->execute([$tenantId, $registerId, $date]);
    $r = $st->fetch() ?: [];

    $by = ['cash' => 0.0, 'card' => 0.0, 'bank' => 0.0, 'other' => 0.0];
    $st = db()->prepare(
        'SELECT p.method, COALESCE(SUM(p.amount), 0) AS total
           FROM payments p JOIN sales s ON s.id = p.sale_id
          WHERE s.tenant_id = ? AND s.register_id = ? AND DATE(s.sold_at) = ?
          GROUP BY p.method'
    );
    $st->execute([$tenantId, $registerId, $date]);
    foreach ($st->fetchAll() as $p) {
        $m = (string)$p['method'];
        if (isset($by[$m])) {
            $by[$m] = (float)$p['total'];
        }
    }

    $netSales = round((float)($r['net_sales'] ?? 0), 2);
    $vat = round((float)($r['vat_total'] ?? 0), 2);
    $netEx = round((float)($r['net_ex_vat'] ?? 0), 2);
    $cogs = round((float)($r['cogs'] ?? 0), 2);
    return [
        'txn_count' => (int)($r['txn_count'] ?? 0),
        'sale_count' => (int)($r['sale_count'] ?? 0),
        'return_count' => (int)($r['return_count'] ?? 0),
        'gross' => round((float)($r['gross'] ?? 0), 2),
        'discount' => round((float)($r['discount'] ?? 0), 2),
        'net_sales' => $netSales,
        'vat_total' => $vat,
        'net_ex_vat' => $netEx,
        'cogs' => $cogs,
        'profit' => round($netEx - $cogs, 2),
        'returns_total' => round((float)($r['returns_total'] ?? 0), 2),
        'cash_sales' => round($by['cash'], 2),
        'card_sales' => round($by['card'], 2),
        'bank_sales' => round($by['bank'], 2),
        'other_sales' => round($by['other'], 2),
    ];
}

function closing_for(int $tenantId, int $registerId, string $date): ?array
{
    $st = db()->prepare('SELECT * FROM daily_closings WHERE tenant_id = ? AND register_id = ? AND close_date = ?');
    $st->execute([$tenantId, $registerId, $date]);
    $r = $st->fetch();
    return $r ?: null;
}

function h_day_summary(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'cashier']);
    $reg = register_must($u, (int)($_GET['register_id'] ?? 0));
    $date = vdate($_GET['date'] ?? '', date('Y-m-d'));
    $sum = day_totals($u['tenant_id'], (int)$reg['id'], $date);
    $closing = closing_for($u['tenant_id'], (int)$reg['id'], $date);
    $opening = $closing ? (float)$closing['opening_float'] : 0.0;

    // The last cash-up before this day gives the cashier a sensible opening float.
    $st = db()->prepare(
        'SELECT counted_cash, close_date FROM daily_closings
          WHERE tenant_id = ? AND register_id = ? AND close_date < ? ORDER BY close_date DESC LIMIT 1'
    );
    $st->execute([$u['tenant_id'], (int)$reg['id'], $date]);
    $prev = $st->fetch() ?: null;

    $sum['opening_float'] = round($opening, 2);
    $sum['expected_cash'] = round($opening + $sum['cash_sales'], 2);
    $sum['counted_cash'] = $closing ? (float)$closing['counted_cash'] : null;
    $sum['difference'] = $closing ? (float)$closing['difference'] : null;
    $sum['notes'] = $closing['notes'] ?? null;
    $sum['closed_at'] = $closing['created_at'] ?? null;
    $sum['closed_by'] = $closing['closed_by'] ?? null;
    $sum['previous_counted'] = $prev ? (float)$prev['counted_cash'] : null;
    $sum['previous_date'] = $prev['close_date'] ?? null;

    json_out([
        'date' => $date,
        'register' => ['id' => (int)$reg['id'], 'code' => $reg['code']],
        'shop' => [
            'id' => (int)$reg['shop_id'], 'name' => $reg['shop_name'], 'code' => $reg['shop_code'],
            'group_code' => $reg['group_code'], 'logo' => $reg['shop_logo'],
        ],
        'company' => [
            'name' => $reg['company_name'], 'name_ar' => $reg['company_name_ar'], 'trn' => $reg['company_trn'],
            'address' => $reg['company_address'], 'phone' => $reg['company_phone'], 'logo' => $reg['company_logo'],
        ],
        'summary' => $sum,
        'settings' => settings_for($u['tenant_id']),
    ]);
}

/** Invoice-level detail for the Excel/CSV export and the A4 day report. */
function h_day_sales(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'cashier']);
    $reg = register_must($u, (int)($_GET['register_id'] ?? 0));
    $date = vdate($_GET['date'] ?? '', date('Y-m-d'));
    $st = db()->prepare(
        'SELECT s.id, s.invoice_no, s.sold_at, s.type, s.gross, s.discount, s.total, s.vat_total,
                s.net_total, s.cogs, s.customer_name, s.customer_trn,
                u.name AS cashier, au.name AS authorised_name, au.username AS authorised_username, s.auth_ref
           FROM sales s
           JOIN users u ON u.id = s.user_id
           LEFT JOIN users au ON au.id = s.authorised_by
          WHERE s.tenant_id = ? AND s.register_id = ? AND DATE(s.sold_at) = ?
          ORDER BY s.sold_at, s.id LIMIT 2000'
    );
    $st->execute([$u['tenant_id'], (int)$reg['id'], $date]);
    $sales = $st->fetchAll();

    $payments = [];
    if ($sales) {
        $ids = array_map(fn($s) => (int)$s['id'], $sales);
        $st = db()->prepare(
            'SELECT sale_id, method, amount FROM payments WHERE sale_id IN (' . placeholders(count($ids)) . ') ORDER BY id'
        );
        $st->execute($ids);
        foreach ($st->fetchAll() as $p) {
            $payments[(int)$p['sale_id']][] = ['method' => $p['method'], 'amount' => (float)$p['amount']];
        }
    }
    foreach ($sales as &$s) {
        $s['id'] = (int)$s['id'];
        $s['payments'] = $payments[$s['id']] ?? [];
    }
    unset($s);

    json_out(['date' => $date, 'register' => ['id' => (int)$reg['id'], 'code' => $reg['code']], 'sales' => $sales]);
}

/** Save (or re-save) the Z report. Totals are recalculated from the database, never trusted from the device. */
function h_close_day(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'cashier']);
    $b = body();
    $reg = register_must($u, (int)($b['register_id'] ?? 0));
    $date = vdate($b['close_date'] ?? '', date('Y-m-d'));
    $opening = round(num($b['opening_float'] ?? 0, -1e9, 1e9), 2);
    $counted = round(num($b['counted_cash'] ?? 0, -1e9, 1e9), 2);
    $notes = str($b['notes'] ?? null, 500);
    $sum = day_totals($u['tenant_id'], (int)$reg['id'], $date);
    $expected = round($opening + $sum['cash_sales'], 2);
    $difference = round($counted - $expected, 2);

    db()->prepare(
        'INSERT INTO daily_closings
            (tenant_id, company_id, shop_id, register_id, close_date, opening_float, cash_sales, card_sales,
             bank_sales, other_sales, returns_total, net_sales, vat_total, expected_cash, counted_cash, difference,
             txn_count, notes, closed_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            opening_float = VALUES(opening_float), cash_sales = VALUES(cash_sales), card_sales = VALUES(card_sales),
            bank_sales = VALUES(bank_sales), other_sales = VALUES(other_sales), returns_total = VALUES(returns_total),
            net_sales = VALUES(net_sales), vat_total = VALUES(vat_total), expected_cash = VALUES(expected_cash),
            counted_cash = VALUES(counted_cash), difference = VALUES(difference), txn_count = VALUES(txn_count),
            notes = VALUES(notes), closed_by = VALUES(closed_by), created_at = NOW()'
    )->execute([
        $u['tenant_id'], (int)$reg['company_id'], (int)$reg['shop_id'], (int)$reg['id'], $date, $opening,
        $sum['cash_sales'], $sum['card_sales'], $sum['bank_sales'], $sum['other_sales'], $sum['returns_total'],
        $sum['net_sales'], $sum['vat_total'], $expected, $counted, $difference, $sum['txn_count'], $notes, $u['id'],
    ]);
    audit($u, 'day_close', "register {$reg['code']} $date counted $counted expected $expected diff $difference");

    json_out([
        'ok' => true,
        'date' => $date,
        'summary' => array_merge($sum, [
            'opening_float' => $opening, 'expected_cash' => $expected,
            'counted_cash' => $counted, 'difference' => $difference, 'notes' => $notes,
        ]),
    ]);
}

function h_closings_list(): void
{
    $u = auth(['owner', 'manager', 'accountant']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-01'));
    $sql = 'SELECT c.id, c.close_date, c.opening_float, c.cash_sales, c.card_sales, c.bank_sales, c.other_sales,
                   c.returns_total, c.net_sales, c.vat_total, c.expected_cash, c.counted_cash, c.difference,
                   c.txn_count, c.notes, c.created_at, s.name AS shop, s.code AS shop_code,
                   r.code AS register, u.name AS closed_by_name
              FROM daily_closings c
              JOIN shops s ON s.id = c.shop_id
              JOIN registers r ON r.id = c.register_id
              LEFT JOIN users u ON u.id = c.closed_by
             WHERE c.tenant_id = ? AND c.company_id = ? AND c.close_date BETWEEN ? AND ?';
    $args = [$u['tenant_id'], $cid, $from, $to];
    if ($u['shop_id'] !== null) {
        $sql .= ' AND c.shop_id = ?';
        $args[] = $u['shop_id'];
    }
    $sql .= ' ORDER BY c.close_date DESC, c.id DESC LIMIT 500';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $tot = ['net_sales' => 0.0, 'cash_sales' => 0.0, 'card_sales' => 0.0, 'bank_sales' => 0.0,
            'other_sales' => 0.0, 'returns_total' => 0.0, 'difference' => 0.0];
    foreach ($rows as $r) {
        foreach ($tot as $k => $_) {
            $tot[$k] += (float)$r[$k];
        }
    }
    foreach ($tot as $k => $v) {
        $tot[$k] = round($v, 2);
    }
    json_out(['closings' => $rows, 'totals' => $tot, 'from' => $from, 'to' => $to]);
}

function h_returns_list(): void
{
    $u = auth(['owner', 'manager', 'accountant']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-01'));
    $sql = 'SELECT s.id, s.invoice_no, s.sold_at, s.total, s.vat_total, s.net_total, s.customer_name, s.customer_trn,
                   s.auth_ref, u.name AS cashier, u.username AS cashier_username,
                   au.name AS authorised_name, au.username AS authorised_username, au.role AS authorised_role,
                   sh.name AS shop, sh.code AS shop_code, r.code AS register
              FROM sales s
              JOIN users u ON u.id = s.user_id
              LEFT JOIN users au ON au.id = s.authorised_by
              JOIN shops sh ON sh.id = s.shop_id
              JOIN registers r ON r.id = s.register_id
             WHERE s.tenant_id = ? AND s.company_id = ? AND s.type = \'return\'
               AND s.sold_at >= ? AND s.sold_at < ?';
    $args = [$u['tenant_id'], $cid, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
    if ($u['shop_id'] !== null) {
        $sql .= ' AND s.shop_id = ?';
        $args[] = $u['shop_id'];
    }
    $sql .= ' ORDER BY s.sold_at DESC, s.id DESC LIMIT 500';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $total = round(array_sum(array_map(fn($r) => (float)$r['total'], $rows)), 2);
    json_out(['returns' => $rows, 'total' => $total, 'from' => $from, 'to' => $to]);
}

function h_stock_ledger(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $shopId = $u['shop_id'] !== null ? (int)$u['shop_id'] : (int)($_GET['shop_id'] ?? 0);
    $itemId = (int)($_GET['item_id'] ?? 0);
    $type = trim((string)($_GET['type'] ?? ''));
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-d', strtotime('-30 days')));

    $sql = 'SELECT l.id, l.created_at, l.qty, l.unit_cost, l.ref_type, l.ref_id, l.note,
                   i.sku, i.name AS item_name, sh.name AS shop, sh.code AS shop_code, u.name AS user_name
              FROM stock_ledger l
              JOIN items i ON i.id = l.item_id
              JOIN shops sh ON sh.id = l.shop_id
              LEFT JOIN users u ON u.id = l.user_id
             WHERE l.tenant_id = ? AND i.company_id = ?
               AND l.created_at >= ? AND l.created_at < ?';
    $args = [$u['tenant_id'], $cid, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
    if ($shopId > 0) {
        $sql .= ' AND l.shop_id = ?';
        $args[] = $shopId;
    }
    if ($itemId > 0) {
        $sql .= ' AND l.item_id = ?';
        $args[] = $itemId;
    }
    if ($type !== '' && in_array($type, ['sale', 'purchase', 'adjust', 'opening', 'transfer_in', 'transfer_out'], true)) {
        $sql .= ' AND l.ref_type = ?';
        $args[] = $type;
    }
    $sql .= ' ORDER BY l.id DESC LIMIT 2000';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $in = $out = 0.0;
    foreach ($rows as $r) {
        if ((float)$r['qty'] >= 0) {
            $in += (float)$r['qty'];
        } else {
            $out += -(float)$r['qty'];
        }
    }
    json_out(['rows' => $rows, 'in' => round($in, 3), 'out' => round($out, 3), 'from' => $from, 'to' => $to]);
}

function h_notifications_list(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $status = (string)($_GET['status'] ?? 'open');
    $sql = 'SELECT n.id, n.type, n.level, n.title, n.detail, n.qty, n.status, n.created_at,
                   sh.name AS shop, sh.code AS shop_code, i.sku, i.name AS item_name
              FROM notifications n
              LEFT JOIN shops sh ON sh.id = n.shop_id
              LEFT JOIN items i ON i.id = n.item_id
             WHERE n.tenant_id = ?';
    $args = [$u['tenant_id']];
    if ($u['shop_id'] !== null) {
        $sql .= ' AND n.shop_id = ?';
        $args[] = $u['shop_id'];
    }
    if ($status === 'open') {
        $sql .= " AND n.status IN ('new','read')";
    } elseif ($status === 'new') {
        $sql .= " AND n.status = 'new'";
    } elseif ($status === 'resolved') {
        $sql .= " AND n.status = 'resolved'";
    }
    $sql .= ' ORDER BY n.id DESC LIMIT 300';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    $st = db()->prepare(
        "SELECT COALESCE(SUM(status = 'new'), 0) AS new_count,
                COALESCE(SUM(status IN ('new','read')), 0) AS open_count,
                COALESCE(SUM(status IN ('new','read') AND type = 'low_stock'), 0) AS low_count
           FROM notifications WHERE tenant_id = ?"
    );
    $st->execute([$u['tenant_id']]);
    $counts = $st->fetch() ?: ['new_count' => 0, 'open_count' => 0, 'low_count' => 0];
    json_out(['notifications' => $rows, 'counts' => $counts]);
}

function h_notification_action(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $action = (string)($b['action'] ?? '');
    if (!in_array($action, ['read', 'resolve', 'reopen'], true)) {
        fail('bad_action');
    }
    $st = db()->prepare('SELECT id, shop_id FROM notifications WHERE id = ? AND tenant_id = ?');
    $st->execute([$id, $u['tenant_id']]);
    $n = $st->fetch();
    if (!$n) {
        fail('notification_not_found', 404);
    }
    if ($u['shop_id'] !== null && (int)$n['shop_id'] !== $u['shop_id']) {
        fail('forbidden', 403);
    }
    $status = $action === 'resolve' ? 'resolved' : ($action === 'reopen' ? 'new' : 'read');
    db()->prepare('UPDATE notifications SET status = ? WHERE id = ? AND tenant_id = ?')->execute([$status, $id, $u['tenant_id']]);
    audit($u, 'notification_' . $action, "notification $id");
    json_out(['ok' => true, 'status' => $status]);
}

function h_settings_get(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $pdo = db();
    $st = $pdo->prepare('SELECT id, name, logo FROM companies WHERE tenant_id = ? ORDER BY name');
    $st->execute([$u['tenant_id']]);
    $companies = $st->fetchAll();
    $st = $pdo->prepare(
        'SELECT id, company_id, code, group_code, name, trade, address, logo FROM shops WHERE tenant_id = ? ORDER BY name'
    );
    $st->execute([$u['tenant_id']]);
    $shops = $st->fetchAll();
    json_out([
        'settings' => settings_for($u['tenant_id']),
        'companies' => $companies,
        'shops' => $shops,
        'brand' => ['product' => BRAND_NAME, 'tagline' => BRAND_TAGLINE, 'vendor' => BRAND_VENDOR, 'version' => app_version()],
    ]);
}

function h_settings_save(): void
{
    $u = auth(['owner', 'manager']);
    $b = body();
    $threshold = round(num($b['low_stock_threshold'] ?? 10, 0, 1000000), 3);
    $email = str($b['share_email'] ?? null, 190);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('bad_email');
    }
    $wa = str($b['share_whatsapp'] ?? null, 40);
    if ($wa !== null) {
        $wa = (string)preg_replace('/[^0-9+]/', '', $wa);
    }
    $currency = str($b['currency'] ?? 'AED', 8) ?? 'AED';
    $currency = strtoupper((string)preg_replace('/[^A-Za-z]/', '', $currency));
    if ($currency === '') {
        $currency = 'AED';
    }
    $note = str($b['receipt_note'] ?? null, 255);
    $thanks = str($b['thank_you'] ?? null, 255);
    $qr = str($b['qr_url'] ?? null, 255);
    $wm = max(0, min(40, (int)($b['watermark_opacity'] ?? 15)));
    $qrOn = array_key_exists('qr_enabled', $b) && empty($b['qr_enabled']) ? 0 : 1;
    if ($qr !== null && !preg_match('#^https?://[^\s<>"]+$#i', $qr)) {
        fail('bad_qr_url');
    }
    db()->prepare(
        'INSERT INTO tenant_settings (tenant_id, low_stock_threshold, share_email, share_whatsapp, currency, receipt_note, thank_you, qr_url, watermark_opacity, qr_enabled)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE low_stock_threshold = VALUES(low_stock_threshold), share_email = VALUES(share_email),
            share_whatsapp = VALUES(share_whatsapp), currency = VALUES(currency), receipt_note = VALUES(receipt_note),
            thank_you = VALUES(thank_you), qr_url = VALUES(qr_url), watermark_opacity = VALUES(watermark_opacity), qr_enabled = VALUES(qr_enabled)'
    )->execute([$u['tenant_id'], $threshold, $email, $wa, $currency, $note, $thanks, $qr, $wm, $qrOn]);
    audit($u, 'settings_save', "low_stock_threshold $threshold currency $currency");
    json_out(['ok' => true, 'settings' => settings_for($u['tenant_id'])]);
}

/** Shop or company logo (small data: URL). Send an empty logo to remove it. */
function h_logo_save(): void
{
    $u = auth(['owner', 'manager', 'accountant']);
    $b = body();
    $target = (string)($b['target'] ?? 'shop');
    $id = (int)($b['id'] ?? 0);
    $logo = clean_logo($b['logo'] ?? null);
    if ($target === 'company') {
        $c = assert_company($u, $id);
        db()->prepare('UPDATE companies SET logo = ? WHERE id = ? AND tenant_id = ?')->execute([$logo, $id, $u['tenant_id']]);
        audit($u, 'company_logo', "company $id " . ($logo ? 'set' : 'cleared'));
        json_out(['ok' => true, 'logo' => $logo, 'company' => $c['name']]);
    }
    $s = assert_shop($u, $id);
    db()->prepare('UPDATE shops SET logo = ? WHERE id = ? AND tenant_id = ?')->execute([$logo, $id, $u['tenant_id']]);
    audit($u, 'shop_logo', "shop $id " . ($logo ? 'set' : 'cleared'));
    json_out(['ok' => true, 'logo' => $logo, 'shop' => $s['name']]);
}

/** What the signed-in user may do to a target account. Primary owner: only Adnys (Console) can disable, demote or reset it. */
function user_perms(array $me, array $t): array
{
    $isOwner = $me['role'] === 'owner';
    $mePrimary = !empty($me['is_primary']);
    $self = (int)$me['id'] === (int)$t['id'];
    $tOwner = $t['role'] === 'owner';
    $tPrimary = !empty($t['is_primary']);
    $edit = $reset = $toggle = false;
    if ($self) {
        $edit = true;                     // own name, email and password; never role, status or shop
    } elseif ($tPrimary) {
        // nobody else
    } elseif ($tOwner) {                  // sub-owner: only the primary owner manages them
        $edit = $reset = $toggle = $mePrimary;
    } elseif ($isOwner) {
        $edit = $reset = $toggle = true;
    } elseif ($me['role'] === 'manager') {
        $edit = $toggle = true;           // managers manage staff but cannot reset or set passwords
    }
    return ['can_edit' => $edit, 'can_reset' => $reset, 'can_toggle' => $toggle];
}

function me_full(array $u): array
{
    $st = db()->prepare('SELECT is_primary FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    return $u + ['is_primary' => (int)$st->fetchColumn()];
}

function h_users_list(): void
{
    $me = me_full(auth(['owner', 'manager']));
    $sql = 'SELECT u.id, u.name, u.username, u.email, u.role, u.shop_id, u.active, u.is_primary, s.code AS shop_code, s.name AS shop_name
              FROM users u LEFT JOIN shops s ON s.id = u.shop_id
             WHERE u.tenant_id = ?';
    $args = [$me['tenant_id']];
    if ($me['shop_id'] !== null) {
        $sql .= ' AND u.shop_id = ?';
        $args[] = $me['shop_id'];
    }
    $sql .= ' ORDER BY u.is_primary DESC, u.role, u.name';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r += user_perms($me, $r);
    }
    unset($r);
    json_out(['users' => $rows, 'roles' => ['owner', 'manager', 'accountant', 'cashier', 'purchase', 'stocker'], 'me' => ['id' => $me['id'], 'primary' => (int)$me['is_primary']]]);
}

function h_user_save(): void
{
    $u = me_full(auth(['owner', 'manager']));
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $name = str($b['name'] ?? null, 120, true);
    $role = (string)($b['role'] ?? 'cashier');
    if (!in_array($role, ['owner', 'manager', 'accountant', 'cashier', 'purchase', 'stocker'], true)) {
        fail('bad_role');
    }
    // Only the primary owner nominates sub-owners (an owner login with all controls).
    if ($id === 0 && $role === 'owner' && !$u['is_primary']) {
        fail('only_primary_nominates', 403);
    }
    $shopId = !empty($b['shop_id']) ? (int)$b['shop_id'] : null;
    if ($role === 'cashier' && $shopId === null) {
        fail('cashier_needs_shop');
    }
    if ($role === 'stocker' && $shopId === null) {
        fail('stocker_needs_shop');
    }
    if ($shopId !== null) {
        assert_shop($u, $shopId);
    }
    if ($u['shop_id'] !== null && $shopId !== $u['shop_id']) {
        fail('forbidden', 403);
    }
    $email = str($b['email'] ?? null, 190);
    if ($email !== null) {
        $email = strtolower($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('bad_email');
        }
    }
    $active = array_key_exists('active', $b) ? (!empty($b['active']) ? 1 : 0) : 1;
    $password = (string)($b['password'] ?? '');
    $pdo = db();

    if ($id > 0) {
        $st = $pdo->prepare('SELECT id, role, shop_id, username, email, active, is_primary FROM users WHERE id = ? AND tenant_id = ?');
        $st->execute([$id, $u['tenant_id']]);
        $old = $st->fetch();
        if (!$old) {
            fail('user_not_found', 404);
        }
        $p = user_perms($u, $old);
        if (!$p['can_edit']) {
            fail('forbidden', 403);
        }
        $self = $id === (int)$u['id'];
        if ($self) {                       // own account: keep role, shop and status as they are
            $role = $old['role'];
            $shopId = $old['shop_id'] === null ? null : (int)$old['shop_id'];
            $active = (int)$old['active'];
        } else {
            if (!$p['can_toggle'] && $active !== (int)$old['active']) {
                fail('forbidden', 403);
            }
            if ($old['role'] !== $role && ($old['role'] === 'owner' || $role === 'owner') && !$u['is_primary']) {
                fail('forbidden', 403);
            }
        }
        if ($password !== '' && !$self && !$p['can_reset']) {
            fail('only_owner_resets', 403);
        }
        if ($email !== null) {
            $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
            $st->execute([$email, $id]);
            if ($st->fetchColumn()) {
                fail('duplicate', 409);
            }
        }
        $set = 'name = ?, email = ?, role = ?, shop_id = ?, active = ?';
        $args = [$name, $email, $role, $shopId, $active];
        if ($password !== '') {
            if (strlen($password) < 8) {
                fail('password_too_short');
            }
            $set .= ', password_hash = ?';
            $args[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $args[] = $id;
        $args[] = $u['tenant_id'];
        $pdo->prepare("UPDATE users SET $set WHERE id = ? AND tenant_id = ?")->execute($args);
        if ($password !== '') {
            $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$id]);
        }
        audit($u, 'user_save', "user $id $name $role");
        json_out(['id' => $id, 'username' => $old['username'], 'email' => $email, 'password' => $password ?: null]);
    }

    if ($email !== null) {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) {
            fail('duplicate', 409);
        }
    }
    if ($password !== '' && strlen($password) < 8) {
        fail('password_too_short');
    }
    $plain = $password !== '' ? $password : gen_pass_plain();
    $pdo->prepare(
        'INSERT INTO users (tenant_id, name, username, email, password_hash, role, shop_id, active, is_primary)
         VALUES (?,?,NULL,?,?,?,?,?,0)'
    )->execute([$u['tenant_id'], $name, $email, password_hash($plain, PASSWORD_DEFAULT), $role, $shopId, $active]);
    $newId = (int)$pdo->lastInsertId();
    $username = gen_username($pdo, $u['tenant_id'], $shopId, $name);
    $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$username, $newId]);
    audit($u, 'user_save', "user $newId $name $role username $username");
    json_out(['id' => $newId, 'username' => $username, 'email' => $email, 'password' => $plain]);
}

function gen_pass_plain(): string
{
    return rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'AB'), '=');
}

function h_user_reset(): void
{
    $u = me_full(auth(['owner', 'manager']));
    $b = body();
    $id = (int)($b['user_id'] ?? 0);
    $st = db()->prepare('SELECT id, name, username, email, role, shop_id, is_primary FROM users WHERE id = ? AND tenant_id = ?');
    $st->execute([$id, $u['tenant_id']]);
    $target = $st->fetch();
    if (!$target) {
        fail('user_not_found', 404);
    }
    if ((int)$target['id'] === (int)$u['id']) {
        fail('reset_self', 403);        // change your own password with Edit; a forgotten owner password goes through Adnys
    }
    if (!user_perms($u, $target)['can_reset']) {
        fail($u['role'] === 'manager' ? 'only_owner_resets' : 'forbidden', 403);
    }
    if ($u['shop_id'] !== null && (int)$target['shop_id'] !== $u['shop_id']) {
        fail('forbidden', 403);
    }
    $plain = gen_pass_plain();
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($plain, PASSWORD_DEFAULT), $id]);
    db()->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$id]);
    audit($u, 'user_reset', "user $id");
    json_out(['login' => $target['username'] ?: $target['email'], 'username' => $target['username'], 'email' => $target['email'], 'password' => $plain]);
}

/** Public. A forgotten owner password e-mails Adnys, who reset it from the Console. Always answers the same. */
function h_forgot(): void
{
    $b = body();
    $login = strtolower(trim((string)($b['login'] ?? '')));
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 10) {
        fail('too_many_attempts', 429);
    }
    $pdo->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
    if ($login !== '') {
        $st = $pdo->prepare('SELECT u.id, u.name, u.username, u.email, u.role, t.name AS tenant FROM users u JOIN tenants t ON t.id = u.tenant_id
                              WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?) AND u.active = 1 LIMIT 1');
        $st->execute([$login, $login]);
        $r = $st->fetch();
        if ($r && $r['role'] === 'owner') {
            $body = "A Flex-C owner says they forgot their password.\n\nCustomer: {$r['tenant']}\nOwner: {$r['name']}\nUsername: {$r['username']}\nEmail: " . ($r['email'] ?: '-') .
                    "\nTime: " . date('Y-m-d H:i:s') . "\nIP: $ip\n\nVerify the person (call the shop), then reset it in Console > Customers > {$r['tenant']} > Users > Reset password.\n";
            $host = preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
            @mail('adnys.dsf@gmail.com,info@adnys.com', 'Flex-C owner password reset request: ' . $r['tenant'], $body,
                  "From: Flex-C <no-reply@$host>\r\nContent-Type: text/plain; charset=UTF-8");
            try {
                $pdo->prepare('INSERT INTO console_audit (staff_id, action, detail, ip) VALUES (NULL, ?, ?, ?)')
                    ->execute(['forgot_password', mb_substr("{$r['tenant']} / {$r['username']}", 0, 2000), $ip]);
            } catch (PDOException $e) { /* console tables missing: the e-mail already went out */ }
        }
    }
    json_out(['ok' => true]);
}

/** Identity/version/settings for any page that wants to show the customer's branding. */
function h_brand(): void
{
    $u = auth();
    json_out([
        'product' => BRAND_NAME,
        'tagline' => BRAND_TAGLINE,
        'vendor' => BRAND_VENDOR,
        'url' => BRAND_URL,
        'version' => app_version(),
        'settings' => settings_for($u['tenant_id']),
    ]);
}

/** Company sales report: per shop, per day, payment mix, VAT, profit and returns. */
function h_sales_report(): void
{
    $u = auth(['owner', 'manager', 'accountant', 'purchase']);
    $cid = (int)($_GET['company_id'] ?? 0);
    assert_company($u, $cid);
    $to = vdate($_GET['to'] ?? '', date('Y-m-d'));
    $from = vdate($_GET['from'] ?? '', date('Y-m-01'));
    $fromDt = $from . ' 00:00:00';
    $toDt = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    $pdo = db();

    $shopFilter = '';
    $shopArgs = [];
    if ($u['shop_id'] !== null) {
        $shopFilter = ' AND s.id = ?';
        $shopArgs[] = $u['shop_id'];
    }

    $st = $pdo->prepare(
        'SELECT s.id, s.name, s.code, s.group_code,
                COALESCE(SUM(d.txn_count), 0) AS txn_count,
                COALESCE(SUM(d.sales_total), 0) AS sales_total,
                COALESCE(SUM(d.vat_total), 0) AS vat_total,
                COALESCE(SUM(d.net_total), 0) AS net_total,
                COALESCE(SUM(d.cogs), 0) AS cogs,
                COALESCE(SUM(d.cash_total), 0) AS cash_total,
                COALESCE(SUM(d.card_total), 0) AS card_total,
                COALESCE(SUM(d.other_total), 0) AS other_total
           FROM shops s
           LEFT JOIN daily_summary d ON d.shop_id = s.id AND d.day BETWEEN ? AND ?
          WHERE s.tenant_id = ? AND s.company_id = ?' . $shopFilter . '
          GROUP BY s.id, s.name, s.code, s.group_code ORDER BY s.name'
    );
    $st->execute(array_merge([$from, $to, $u['tenant_id'], $cid], $shopArgs));
    $shops = $st->fetchAll();

    $st = $pdo->prepare(
        'SELECT shop_id, COALESCE(SUM(-total), 0) AS returns_total, COUNT(*) AS return_count
           FROM sales WHERE tenant_id = ? AND company_id = ? AND type = \'return\'
            AND sold_at >= ? AND sold_at < ? GROUP BY shop_id'
    );
    $st->execute([$u['tenant_id'], $cid, $fromDt, $toDt]);
    $returns = [];
    foreach ($st->fetchAll() as $r) {
        $returns[(int)$r['shop_id']] = $r;
    }

    $st = $pdo->prepare(
        'SELECT shop_id, COALESCE(SUM(amount), 0) AS expenses
           FROM expenses WHERE tenant_id = ? AND company_id = ? AND expense_date BETWEEN ? AND ?
          GROUP BY shop_id'
    );
    $st->execute([$u['tenant_id'], $cid, $from, $to]);
    $expenses = [];
    foreach ($st->fetchAll() as $r) {
        $expenses[$r['shop_id'] === null ? 0 : (int)$r['shop_id']] = (float)$r['expenses'];
    }

    $tot = ['txn_count' => 0, 'sales_total' => 0.0, 'vat_total' => 0.0, 'net_total' => 0.0, 'cogs' => 0.0,
            'profit' => 0.0, 'cash_total' => 0.0, 'card_total' => 0.0, 'other_total' => 0.0,
            'returns_total' => 0.0, 'return_count' => 0, 'expenses' => 0.0];
    $rows = [];
    foreach ($shops as $s) {
        $sid = (int)$s['id'];
        $ret = $returns[$sid] ?? ['returns_total' => 0, 'return_count' => 0];
        $exp = (float)($expenses[$sid] ?? 0);
        $row = [
            'shop_id' => $sid, 'shop' => $s['name'], 'code' => $s['code'], 'group_code' => $s['group_code'],
            'txn_count' => (int)$s['txn_count'], 'sales_total' => round((float)$s['sales_total'], 2),
            'vat_total' => round((float)$s['vat_total'], 2), 'net_total' => round((float)$s['net_total'], 2),
            'cogs' => round((float)$s['cogs'], 2), 'profit' => round((float)$s['net_total'] - (float)$s['cogs'], 2),
            'cash_total' => round((float)$s['cash_total'], 2), 'card_total' => round((float)$s['card_total'], 2),
            'other_total' => round((float)$s['other_total'], 2),
            'returns_total' => round((float)$ret['returns_total'], 2), 'return_count' => (int)$ret['return_count'],
            'expenses' => round($exp, 2),
        ];
        $rows[] = $row;
        foreach ($tot as $k => $_) {
            $tot[$k] += $row[$k];
        }
    }
    foreach ($tot as $k => $v) {
        $tot[$k] = is_int($v) ? $v : round($v, 2);
    }
    $tot['expenses'] = round($tot['expenses'] + (float)($expenses[0] ?? 0), 2);
    $tot['net_after_expenses'] = round($tot['profit'] - $tot['expenses'], 2);

    $st = $pdo->prepare(
        'SELECT day, SUM(txn_count) AS txn_count, SUM(sales_total) AS sales_total, SUM(vat_total) AS vat_total,
                SUM(net_total) AS net_total, SUM(cogs) AS cogs, SUM(cash_total) AS cash_total,
                SUM(card_total) AS card_total, SUM(other_total) AS other_total
           FROM daily_summary WHERE tenant_id = ? AND company_id = ? AND day BETWEEN ? AND ?
          GROUP BY day ORDER BY day'
    );
    $st->execute([$u['tenant_id'], $cid, $from, $to]);
    $days = $st->fetchAll();

    json_out(['shops' => $rows, 'totals' => $tot, 'days' => $days, 'from' => $from, 'to' => $to]);
}

/**
 * Profit summary by month (profit and loss): income, cost of goods, gross profit, expenses by category, net profit.
 * GET company_id, year (e.g. 2026), shop_id (0 = all shops). Money excludes VAT, as it is passed to the FTA.
 */
function h_profit_report(): void
{
    $u = auth(['owner', 'manager', 'accountant']);
    $cid = (int)($_GET['company_id'] ?? 0);
    $co = assert_company($u, $cid);
    $year = (int)($_GET['year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) {
        fail('bad_date');
    }
    $shopId = (int)($_GET['shop_id'] ?? 0);
    if ($u['shop_id'] !== null) {
        $shopId = $u['shop_id'];
    }
    $shopName = 'All shops';
    if ($shopId > 0) {
        $s = assert_shop($u, $shopId);
        $shopName = $s['name'] . ' (' . $s['code'] . ')';
    }
    $from = "$year-01-01";
    $to = ($year + 1) . '-01-01';
    $pdo = db();
    $sf = $shopId > 0 ? ' AND shop_id = ' . $shopId : '';
    $m = fn() => array_fill(1, 12, 0.0);
    $rows = ['sales' => $m(), 'sales_vat' => $m(), 'returns' => $m(), 'net_sales' => $m(), 'cogs' => $m(), 'bills' => $m(),
             'purchases' => $m(), 'purchase_returns' => $m(), 'expense_vat' => $m()];
    $st = $pdo->prepare("SELECT MONTH(sold_at) AS mo, type, COUNT(*) AS n, SUM(total) AS total, SUM(vat_total) AS vat, SUM(net_total) AS net, SUM(cogs) AS cogs
                           FROM sales WHERE tenant_id = ? AND company_id = ? AND sold_at >= ? AND sold_at < ?$sf GROUP BY MONTH(sold_at), type");
    $st->execute([$u['tenant_id'], $cid, $from, $to]);
    foreach ($st->fetchAll() as $r) {
        $mo = (int)$r['mo'];
        if ($r['type'] === 'sale') {
            $rows['sales'][$mo] += (float)$r['net'];          // sales without VAT
            $rows['sales_vat'][$mo] += (float)$r['vat'];
            $rows['bills'][$mo] += (int)$r['n'];
        } else {
            $rows['returns'][$mo] += (float)$r['net'];        // negative
            $rows['sales_vat'][$mo] += (float)$r['vat'];
        }
        $rows['net_sales'][$mo] += (float)$r['net'];
        $rows['cogs'][$mo] += (float)$r['cogs'];
    }
    $st = $pdo->prepare("SELECT MONTH(bill_date) AS mo, SUM(subtotal) AS amt FROM purchases WHERE tenant_id = ? AND company_id = ? AND bill_date >= ? AND bill_date < ?$sf GROUP BY MONTH(bill_date)");
    $st->execute([$u['tenant_id'], $cid, $from, $to]);
    foreach ($st->fetchAll() as $r) {
        $rows['purchases'][(int)$r['mo']] += (float)$r['amt'];
    }
    try {
        $st = $pdo->prepare("SELECT MONTH(return_date) AS mo, SUM(subtotal) AS amt FROM purchase_returns WHERE tenant_id = ? AND company_id = ? AND return_date >= ? AND return_date < ?$sf GROUP BY MONTH(return_date)");
        $st->execute([$u['tenant_id'], $cid, $from, $to]);
        foreach ($st->fetchAll() as $r) {
            $rows['purchase_returns'][(int)$r['mo']] -= (float)$r['amt'];
        }
    } catch (PDOException $e) { /* older database without supplier returns */ }
    // Expenses by category, without the VAT you can claim back. Shop filter: that shop's expenses only (company-wide ones show under All shops).
    $st = $pdo->prepare("SELECT MONTH(expense_date) AS mo, category, SUM(amount - vat_amount) AS amt, SUM(vat_amount) AS vat FROM expenses
                          WHERE tenant_id = ? AND company_id = ? AND expense_date >= ? AND expense_date < ?$sf GROUP BY MONTH(expense_date), category ORDER BY category");
    $st->execute([$u['tenant_id'], $cid, $from, $to]);
    $exp = [];
    foreach ($st->fetchAll() as $r) {
        $cat = trim((string)$r['category']) ?: 'Other';
        $exp[$cat] ??= $m();
        $exp[$cat][(int)$r['mo']] += (float)$r['amt'];
        $rows['expense_vat'][(int)$r['mo']] += (float)$r['vat'];
    }
    ksort($exp, SORT_NATURAL | SORT_FLAG_CASE);
    $totalExp = $m();
    foreach ($exp as $vals) {
        foreach ($vals as $mo => $v) {
            $totalExp[$mo] += $v;
        }
    }
    $gross = $net = $m();
    for ($mo = 1; $mo <= 12; $mo++) {
        $gross[$mo] = $rows['net_sales'][$mo] - $rows['cogs'][$mo];
        $net[$mo] = $gross[$mo] - $totalExp[$mo];
    }
    $r2 = function (array $a): array {
        $o = [];
        foreach ($a as $k => $v) {
            $o[] = round($v, 2);
        }
        $o[] = round(array_sum($a), 2);   // 13th = year total
        return $o;
    };
    $out = [];
    foreach ($rows as $k => $v) {
        $out[$k] = $r2($v);
    }
    $out['gross_profit'] = $r2($gross);
    $out['total_expenses'] = $r2($totalExp);
    $out['net_profit'] = $r2($net);
    $cats = [];
    foreach ($exp as $cat => $vals) {
        $cats[] = ['category' => $cat, 'values' => $r2($vals)];
    }
    json_out(['year' => $year, 'company' => $co['name'], 'shop' => $shopName, 'rows' => $out, 'expenses' => $cats, 'this_month' => (int)date('n'), 'this_year' => (int)date('Y')]);
}

/** Remove the 3 sample items the installer can add, once the shop has its real items. Used ones are hidden instead. */
function h_items_remove_samples(): void
{
    $u = items_editor();
    $b = body();
    $cid = (int)($b['company_id'] ?? 0);
    assert_company($u, $cid);
    $pdo = db();
    $st = $pdo->prepare("SELECT id, sku FROM items WHERE tenant_id = ? AND company_id = ? AND sku IN ('SAMPLE-1','SAMPLE-2','SAMPLE-3') AND name LIKE 'Sample item %'");
    $st->execute([$u['tenant_id'], $cid]);
    $removed = $hidden = 0;
    $pdo->beginTransaction();
    foreach ($st->fetchAll() as $it) {
        $used = 0;
        foreach (['SELECT COUNT(*) FROM sale_lines WHERE item_id = ?', 'SELECT COUNT(*) FROM purchase_lines WHERE item_id = ?'] as $q) {
            $c = $pdo->prepare($q);
            $c->execute([$it['id']]);
            $used += (int)$c->fetchColumn();
        }
        if ($used) {
            $pdo->prepare('UPDATE items SET active = 0 WHERE id = ?')->execute([$it['id']]);
            $hidden++;
            continue;
        }
        foreach (['DELETE FROM stock_ledger WHERE item_id = ?', 'DELETE FROM item_barcodes WHERE item_id = ?', 'DELETE FROM notifications WHERE item_id = ?', 'DELETE FROM items WHERE id = ?'] as $q) {
            $pdo->prepare($q)->execute([$it['id']]);
        }
        $removed++;
    }
    $pdo->commit();
    audit($u, 'items_remove_samples', "removed $removed hidden $hidden");
    json_out(['removed' => $removed, 'hidden' => $hidden]);
}
