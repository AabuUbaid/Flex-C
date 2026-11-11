<?php
declare(strict_types=1);

/**
 * POS -> server sync. Idempotent on the sale UUID, so a register can safely retry.
 * Prices are VAT inclusive. VAT and totals are recomputed here from the item's VAT rate;
 * the register's own totals are never trusted.
 */
function h_sync_sales(): void
{
    $u = auth(['owner', 'manager', 'cashier']);
    $sales = body()['sales'] ?? [];
    if (!is_array($sales) || count($sales) > 50) {
        fail('bad_payload');
    }
    $results = [];
    foreach ($sales as $s) {
        $uuid = is_array($s) ? (string)($s['uuid'] ?? '') : '';
        try {
            if (!is_array($s)) {
                throw new ApiError('bad_sale');
            }
            $results[] = ['uuid' => $uuid] + post_sale($u, $s);
        } catch (ApiError $e) {
            rollback_if_open();
            $results[] = ['uuid' => $uuid, 'status' => 'error', 'error' => $e->getMessage()];
        } catch (PDOException $e) {
            rollback_if_open();
            $msg = $e->getMessage();
            if (($e->errorInfo[1] ?? 0) === 1062) {
                // Same UUID arrived twice at the same moment: treat as already stored.
                $status = str_contains($msg, 'uq_sales_uuid') ? 'duplicate' : 'error';
                $results[] = ['uuid' => $uuid, 'status' => $status] + ($status === 'error' ? ['error' => 'invoice_no_conflict'] : []);
            } else {
                error_log('sync_sales: ' . $msg);
                $results[] = ['uuid' => $uuid, 'status' => 'error', 'error' => 'db_error'];
            }
        }
    }
    json_out(['results' => $results]);
}

function rollback_if_open(): void
{
    try {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    } catch (Throwable $e) {
        // connection already gone; nothing to roll back
    }
}

function post_sale(array $u, array $s): array
{
    $pdo = db();
    $uuid = strtolower((string)($s['uuid'] ?? ''));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
        throw new ApiError('bad_uuid');
    }

    $st = $pdo->prepare('SELECT tenant_id FROM sales WHERE uuid = ?');
    $st->execute([$uuid]);
    $existing = $st->fetchColumn();
    if ($existing !== false) {
        if ((int)$existing !== $u['tenant_id']) {
            throw new ApiError('bad_uuid');
        }
        return ['status' => 'duplicate'];
    }

    $st = $pdo->prepare(
        'SELECT r.id, r.shop_id, s.company_id, s.name AS shop_name FROM registers r JOIN shops s ON s.id = r.shop_id
          WHERE r.id = ? AND r.tenant_id = ?'
    );
    $st->execute([(int)($s['register_id'] ?? 0), $u['tenant_id']]);
    $reg = $st->fetch();
    if (!$reg) {
        throw new ApiError('register_not_found');
    }
    assert_shop($u, (int)$reg['shop_id']);
    $shopId = (int)$reg['shop_id'];
    $companyId = (int)$reg['company_id'];

    $invoice = trim((string)($s['invoice_no'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9\-]{3,40}$/', $invoice)) {
        throw new ApiError('bad_invoice_no');
    }
    $seq = max(0, (int)($s['seq'] ?? 0));

    $soldAt = date('Y-m-d H:i:s');
    $raw = (string)($s['sold_at'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('Asia/Dubai'));
    if ($dt && $dt->format('Y-m-d H:i:s') === $raw && $dt->getTimestamp() <= time() + 86400) {
        $soldAt = $raw;
    }

    $lines = $s['lines'] ?? [];
    if (!is_array($lines) || !$lines || count($lines) > 200) {
        throw new ApiError('bad_lines');
    }
    $ids = [];
    foreach ($lines as $l) {
        $ids[] = (int)($l['item_id'] ?? 0);
    }
    $ids = array_values(array_unique($ids));
    $st = $pdo->prepare(
        'SELECT id, name, vat_rate, cost FROM items WHERE tenant_id = ? AND company_id = ? AND id IN (' . placeholders(count($ids)) . ')'
    );
    $st->execute(array_merge([$u['tenant_id'], $companyId], $ids));
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[(int)$r['id']] = $r;
    }

    $calc = [];
    $gross = $disc = $total = $vatTotal = $cogs = 0.0;
    foreach ($lines as $l) {
        $iid = (int)($l['item_id'] ?? 0);
        if (!isset($items[$iid])) {
            throw new ApiError('unknown_item');
        }
        $qty = round((float)($l['qty'] ?? 0), 3);
        $price = round((float)($l['unit_price'] ?? 0), 2);
        $d = round((float)($l['discount'] ?? 0), 2);
        if ($qty == 0.0 || abs($qty) > 100000 || $price < 0 || $price > 10000000) {
            throw new ApiError('bad_line');
        }
        $g = round($qty * $price, 2);
        // A discount reduces the line toward zero; it can never flip or exceed it.
        if (abs($d) > abs($g) + 0.001 || $d * $g < 0) {
            throw new ApiError('bad_discount');
        }
        $lt = round($g - $d, 2);
        $rate = (float)$items[$iid]['vat_rate'];
        $vat = round($lt * $rate / (100 + $rate), 2);
        $cost = (float)$items[$iid]['cost'];
        $calc[] = [$iid, $qty, $price, $d, $rate, $vat, $lt, $cost];
        $gross += $g;
        $disc += $d;
        $total += $lt;
        $vatTotal += $vat;
        $cogs += $qty * $cost;
    }
    $gross = round($gross, 2);
    $disc = round($disc, 2);
    $total = round($total, 2);
    $vatTotal = round($vatTotal, 2);
    $net = round($total - $vatTotal, 2);
    $cogs = round($cogs, 2);
    $type = $total < 0 ? 'return' : 'sale';

    // A return must be approved by a manager / accountant / owner. Online uses a one-time
    // token; an offline register uses a credential the manager already cached on that device.
    $authUserId = null;
    $authRef = null;
    if ($type === 'return') {
        $authToken = (string)($s['auth_token'] ?? '');
        $offlineUser = strtolower(trim((string)($s['authorised_by'] ?? '')));
        if ($authToken !== '') {
            $st = $pdo->prepare(
                "SELECT a.user_id FROM authorisations a JOIN users x ON x.id = a.user_id
                  WHERE a.tenant_id = ? AND a.purpose = 'return' AND a.token_hash = ?
                    AND a.used_at IS NULL AND a.expires_at > NOW()
                    AND x.role IN ('owner','manager','accountant') AND x.active = 1"
            );
            $st->execute([$u['tenant_id'], hash('sha256', $authToken)]);
            $authUserId = $st->fetchColumn();
            if (!$authUserId) {
                throw new ApiError('return_needs_authorisation');
            }
            $pdo->prepare('UPDATE authorisations SET used_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $authToken)]);
            $authRef = 'token';
        } elseif (!empty($s['auth_offline']) && $offlineUser !== '') {
            $st = $pdo->prepare(
                "SELECT id FROM users WHERE tenant_id = ? AND (LOWER(username) = ? OR LOWER(email) = ?)
                  AND role IN ('owner','manager','accountant') AND active = 1"
            );
            $st->execute([$u['tenant_id'], $offlineUser, $offlineUser]);
            $authUserId = $st->fetchColumn();
            if (!$authUserId) {
                throw new ApiError('return_needs_authorisation');
            }
            $authRef = 'offline';
        } else {
            throw new ApiError('return_needs_authorisation');
        }
        $authUserId = (int)$authUserId;
    }

    $pays = $s['payments'] ?? [];
    if (!is_array($pays) || count($pays) > 6) {
        throw new ApiError('bad_payments');
    }
    $paid = 0.0;
    $by = ['cash' => 0.0, 'card' => 0.0, 'bank' => 0.0, 'other' => 0.0];
    foreach ($pays as $p) {
        $m = (string)($p['method'] ?? '');
        if (!isset($by[$m])) {
            throw new ApiError('bad_payment_method');
        }
        $a = round((float)($p['amount'] ?? 0), 2);
        $by[$m] += $a;
        $paid += $a;
    }
    if (abs(round($paid, 2) - $total) > 0.01) {
        throw new ApiError('payment_mismatch');
    }

    $phone = clean_phone($s['customer_phone'] ?? null);
    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO sales (uuid, tenant_id, company_id, shop_id, register_id, user_id, authorised_by, auth_ref,
                            invoice_no, seq, sold_at, type, gross, discount, total, vat_total, net_total, cogs,
                            customer_name, customer_trn, customer_phone)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $uuid, $u['tenant_id'], $companyId, $shopId, $reg['id'], $u['id'], $authUserId, $authRef,
        $invoice, $seq, $soldAt, $type,
        $gross, $disc, $total, $vatTotal, $net, $cogs,
        str($s['customer_name'] ?? null, 120), str($s['customer_trn'] ?? null, 20), $phone,
    ]);
    $saleId = (int)$pdo->lastInsertId();

    $insLine = $pdo->prepare(
        'INSERT INTO sale_lines (sale_id, item_id, qty, unit_price, discount, vat_rate, vat_amount, line_total, unit_cost)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $insLedger = $pdo->prepare(
        "INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, ref_id, user_id, created_at)
         VALUES (?,?,?,?,?,'sale',?,?,?)"
    );
    foreach ($calc as [$iid, $qty, $price, $d, $rate, $vat, $lt, $cost]) {
        $insLine->execute([$saleId, $iid, $qty, $price, $d, $rate, $vat, $lt, $cost]);
        $insLedger->execute([$u['tenant_id'], $shopId, $iid, -$qty, $cost, $saleId, $u['id'], $soldAt]);
    }
    $insPay = $pdo->prepare('INSERT INTO payments (sale_id, method, amount) VALUES (?,?,?)');
    foreach ($by as $m => $a) {
        if (round($a, 2) != 0.0) {
            $insPay->execute([$saleId, $m, round($a, 2)]);
        }
    }

    // Low-stock alert for the owner / admin / purchase team (one open alert per item + shop).
    $threshold = (float)settings_for($u['tenant_id'])['low_stock_threshold'];
    $stockNow = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? AND item_id = ?');
    foreach (array_values(array_unique(array_map(fn($x) => $x[0], $calc))) as $iid) {
        $stockNow->execute([$u['tenant_id'], $shopId, $iid]);
        notify_low_stock(
            $pdo, $u['tenant_id'], $companyId, $shopId, $iid,
            (float)$stockNow->fetchColumn(), $threshold,
            (string)($items[$iid]['name'] ?? 'Item'), (string)$reg['shop_name']
        );
    }

    $pdo->prepare(
        'INSERT INTO daily_summary (tenant_id, company_id, shop_id, day, txn_count, sales_total, vat_total, net_total, cogs,
                                    cash_total, card_total, other_total)
         VALUES (?,?,?,?,1,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE txn_count = txn_count + 1,
            sales_total = sales_total + VALUES(sales_total), vat_total = vat_total + VALUES(vat_total),
            net_total = net_total + VALUES(net_total), cogs = cogs + VALUES(cogs),
            cash_total = cash_total + VALUES(cash_total), card_total = card_total + VALUES(card_total),
            other_total = other_total + VALUES(other_total)'
    )->execute([
        $u['tenant_id'], $companyId, $shopId, substr($soldAt, 0, 10),
        $total, $vatTotal, $net, $cogs, round($by['cash'], 2), round($by['card'], 2), round($by['other'] + $by['bank'], 2),
    ]);
    if ($phone !== null && $type === 'sale') {
        // Remember the customer for WhatsApp offers. Offers only go to people the cashier marked as agreeing.
        $pdo->prepare(
            'INSERT INTO customers (tenant_id, phone, name, offers_ok, visits, total_spent, first_seen, last_seen, last_shop_id)
             VALUES (?,?,?,?,1,?,?,?,?)
             ON DUPLICATE KEY UPDATE visits = visits + 1, total_spent = total_spent + VALUES(total_spent),
                last_seen = GREATEST(last_seen, VALUES(last_seen)), last_shop_id = VALUES(last_shop_id),
                name = COALESCE(VALUES(name), name), offers_ok = GREATEST(offers_ok, VALUES(offers_ok))'
        )->execute([$u['tenant_id'], $phone, str($s['customer_name'] ?? null, 120), !empty($s['offers_ok']) ? 1 : 0, $total, $soldAt, $soldAt, $shopId]);
    }
    $pdo->commit();

    if ($type === 'return') {
        audit($u, 'sale_return', "invoice $invoice total $total authorised user #$authUserId ($authRef)");
    }

    return ['status' => 'ok'];
}
