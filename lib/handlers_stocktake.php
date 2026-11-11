<?php
declare(strict_types=1);
/*
 * Team stock take (1.10.0).
 * Owner / manager start a count for a shop and give stockers their own logins.
 * Every stocker scans with a phone: the first scan of a product in the count asks for the rate,
 * every next scan of the same product (by anyone) adds to the same line. Owner / manager watch it live,
 * correct lines, close it and apply it, which sets the shop's stock to the counted quantities.
 */

const TAKE_ADMIN = ['owner', 'manager'];
const TAKE_ANY = ['owner', 'manager', 'stocker'];
const TAKE_STREAK_MIN = 3;   // scanning the same product again within this many minutes = one pile, no question

/** Load a stock take the user may see. Stockers only see their own shop's counts. */
function take_for(array $u, int $id, bool $lock = false): array
{
    $st = db()->prepare('SELECT * FROM stock_takes WHERE id = ? AND tenant_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id, $u['tenant_id']]);
    $t = $st->fetch();
    if (!$t) {
        fail('take_not_found', 404);
    }
    if ($u['shop_id'] !== null && (int)$t['shop_id'] !== $u['shop_id']) {
        fail('forbidden', 403);
    }
    return $t;
}

/** Barcode / SKU as scanned. Letters, digits and . _ - / only, up to 64. */
function take_code(mixed $v): string
{
    $c = trim((string)($v ?? ''));
    if ($c === '') {
        fail('missing_field');
    }
    if (!preg_match('#^[A-Za-z0-9._\-/]{1,64}$#', $c)) {
        fail('bad_barcode');
    }
    return $c;
}

/** Find a product of the company by SKU or any of its barcodes. */
function take_find_item(int $tenantId, int $companyId, string $code): ?array
{
    $st = db()->prepare(
        'SELECT i.id, i.sku, i.name, i.price, i.cost, i.uom FROM items i
          WHERE i.tenant_id = ? AND i.company_id = ?
            AND (i.id IN (SELECT item_id FROM item_barcodes WHERE company_id = ? AND barcode = ?) OR i.sku = ?)
          ORDER BY i.active DESC, i.id LIMIT 1'
    );
    $st->execute([$tenantId, $companyId, $companyId, $code, $code]);
    $it = $st->fetch();
    if (!$it && ctype_digit($code)) {
        // The label says 16587 but the scanner reads 000000016587 (or the other way round): same barcode.
        $st = db()->prepare("SELECT i.id, i.sku, i.name, i.price, i.cost, i.uom FROM items i JOIN item_barcodes b ON b.item_id = i.id
                              WHERE i.tenant_id = ? AND b.company_id = ? AND b.barcode REGEXP '^[0-9]+$' AND TRIM(LEADING '0' FROM b.barcode) = ? ORDER BY i.active DESC, i.id LIMIT 1");
        $st->execute([$tenantId, $companyId, ltrim($code, '0')]);
        $it = $st->fetch();
    }
    return $it ?: null;
}

function take_line_out(array $l): array
{
    return [
        'id' => (int)$l['id'], 'code' => $l['code'], 'item_id' => $l['item_id'] === null ? null : (int)$l['item_id'],
        'name' => $l['name'], 'rate' => (float)$l['rate'], 'qty' => (float)$l['qty'],
    ];
}

/** GET count_list: open counts first. Stockers see only the open / closed counts of their shop. */
function h_count_list(): void
{
    $u = auth(TAKE_ANY);
    $sql = 'SELECT t.id, t.shop_id, t.name, t.rate_kind, t.status, t.created_at, t.closed_at, t.applied_at, t.result, s.name AS shop_name, s.code AS shop_code,
                   (SELECT COUNT(*) FROM stock_take_lines l WHERE l.take_id = t.id) AS n_lines,
                   (SELECT COALESCE(SUM(l.qty), 0) FROM stock_take_lines l WHERE l.take_id = t.id) AS units
              FROM stock_takes t JOIN shops s ON s.id = t.shop_id
             WHERE t.tenant_id = ?';
    $args = [$u['tenant_id']];
    $cid = (int)($_GET['company_id'] ?? 0);
    if ($cid > 0) {
        $sql .= ' AND t.company_id = ?';
        $args[] = $cid;
    }
    if ($u['shop_id'] !== null) {
        $sql .= ' AND t.shop_id = ?';
        $args[] = $u['shop_id'];
    }
    if ($u['role'] === 'stocker') {
        $sql .= " AND t.status IN ('open','closed')";
    }
    $st = db()->prepare($sql . " ORDER BY (t.status = 'open') DESC, t.id DESC LIMIT 100");
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['shop_id'] = (int)$r['shop_id'];
        $r['lines'] = (int)$r['n_lines'];
        unset($r['n_lines']);
        $r['units'] = (float)$r['units'];
    }
    unset($r);
    json_out(['takes' => $rows]);
}

/** POST count_start: shop_id, name, rate_kind ('price' = selling price VAT incl., 'cost' = buying cost without VAT). */
function h_count_start(): void
{
    $u = auth(TAKE_ADMIN);
    $b = body();
    $pdo = db();
    $pdo->beginTransaction();
    $shop = assert_shop($u, (int)($b['shop_id'] ?? 0));
    $pdo->prepare('SELECT id FROM shops WHERE id = ? FOR UPDATE')->execute([$shop['id']]);   // two managers pressing Start together
    $st = $pdo->prepare("SELECT id FROM stock_takes WHERE tenant_id = ? AND shop_id = ? AND status IN ('open','closed') LIMIT 1");
    $st->execute([$u['tenant_id'], $shop['id']]);
    if ($st->fetchColumn()) {
        fail('take_already_open', 409);
    }
    $name = str($b['name'] ?? null, 120) ?? ('Stock take ' . $shop['code'] . ' ' . date('Y-m-d'));
    $kind = ($b['rate_kind'] ?? 'price') === 'cost' ? 'cost' : 'price';
    $pdo->prepare('INSERT INTO stock_takes (tenant_id, company_id, shop_id, name, rate_kind, created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$u['tenant_id'], $shop['company_id'], $shop['id'], $name, $kind, $u['id']]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
    audit($u, 'take_start', "take $id shop {$shop['code']} rate $kind");
    json_out(['id' => $id]);
}

/**
 * GET count_get: take_id. Lines, and for owner/manager the system stock now, the difference and who scanned.
 * Stockers get the lines (name, rate, counted) and their own recent scans.
 */
function h_count_get(): void
{
    $u = auth(TAKE_ANY);
    $t = take_for($u, (int)($_GET['take_id'] ?? 0));
    $admin = in_array($u['role'], TAKE_ADMIN, true);
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT l.*, fu.name AS first_by, lu.name AS last_by FROM stock_take_lines l
           LEFT JOIN users fu ON fu.id = l.first_user_id LEFT JOIN users lu ON lu.id = l.last_user_id
          WHERE l.take_id = ? ORDER BY l.last_at DESC, l.id DESC LIMIT 20000'
    );
    $st->execute([$t['id']]);
    $lines = [];
    $sys = [];
    if ($admin) {
        $q = $pdo->prepare('SELECT item_id, SUM(qty) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? GROUP BY item_id');
        $q->execute([$u['tenant_id'], $t['shop_id']]);
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$iid, $qq]) {
            $sys[(int)$iid] = (float)$qq;
        }
        $q = $pdo->prepare('SELECT id, sku, price, cost FROM items WHERE tenant_id = ? AND company_id = ?');
        $q->execute([$u['tenant_id'], $t['company_id']]);
        $itm = [];
        foreach ($q->fetchAll() as $r) {
            $itm[(int)$r['id']] = $r;
        }
    }
    $split = [];
    $q = $pdo->prepare('SELECT s.line_id, u.name, SUM(s.qty) AS qty, MAX(s.recount) AS recount FROM stock_take_scans s JOIN users u ON u.id = s.user_id
                         WHERE s.take_id = ? GROUP BY s.line_id, u.id, u.name ORDER BY MIN(s.id)');
    $q->execute([$t['id']]);
    foreach ($q->fetchAll() as $r) {
        $split[(int)$r['line_id']][] = ['name' => $r['name'], 'qty' => (float)$r['qty'], 'recount' => (int)$r['recount']];
    }
    foreach ($st->fetchAll() as $l) {
        $o = take_line_out($l) + ['first_by' => $l['first_by'], 'last_by' => $l['last_by'], 'last_at' => $l['last_at']];
        $o['by'] = $split[(int)$l['id']] ?? [];
        $o['recount'] = (int)array_sum(array_column($o['by'], 'recount')) > 0;
        if ($admin) {
            $iid = $o['item_id'];
            $o['system'] = $iid ? ($sys[$iid] ?? 0.0) : 0.0;
            $o['diff'] = round($o['qty'] - $o['system'], 3);
            $o['sku'] = $iid && isset($itm[$iid]) ? $itm[$iid]['sku'] : null;
            $o['item_rate'] = $iid && isset($itm[$iid]) ? (float)$itm[$iid][$t['rate_kind'] === 'cost' ? 'cost' : 'price'] : null;
        }
        $lines[] = $o;
    }
    $people = [];
    if ($admin) {
        $q = $pdo->prepare(
            'SELECT u.id, u.name, u.username, COUNT(*) AS scans, SUM(s.qty) AS units, MAX(s.created_at) AS last_at
               FROM stock_take_scans s JOIN users u ON u.id = s.user_id WHERE s.take_id = ? GROUP BY u.id, u.name, u.username ORDER BY u.name'
        );
        $q->execute([$t['id']]);
        $people = $q->fetchAll();
        // Products of this shop with stock that nobody has scanned yet (helps find missed shelves).
        $q = $pdo->prepare(
            'SELECT i.id, i.sku, i.name, SUM(l.qty) AS qty FROM stock_ledger l JOIN items i ON i.id = l.item_id
              WHERE l.tenant_id = ? AND l.shop_id = ? AND i.id NOT IN (SELECT item_id FROM stock_take_lines WHERE take_id = ? AND item_id IS NOT NULL)
              GROUP BY i.id, i.sku, i.name HAVING ABS(SUM(l.qty)) > 0.0005 ORDER BY i.name LIMIT 5000'
        );
        $q->execute([$u['tenant_id'], $t['shop_id'], $t['id']]);
        $missing = $q->fetchAll();
    }
    $mine = [];
    $q = $pdo->prepare(
        'SELECT s.id, s.qty, s.created_at, s.undo_of, l.id AS line_id, l.name, l.code, l.qty AS line_qty,
                EXISTS(SELECT 1 FROM stock_take_scans x WHERE x.take_id = s.take_id AND x.undo_of = s.id) AS undone
           FROM stock_take_scans s JOIN stock_take_lines l ON l.id = s.line_id
          WHERE s.take_id = ? AND s.user_id = ? ORDER BY s.id DESC LIMIT 30'
    );
    $q->execute([$t['id'], $u['id']]);
    $mine = $q->fetchAll();
    $sh = $pdo->prepare('SELECT name, code FROM shops WHERE id = ?');
    $sh->execute([$t['shop_id']]);
    $shop = $sh->fetch();
    json_out([
        'take' => [
            'id' => (int)$t['id'], 'name' => $t['name'], 'status' => $t['status'], 'rate_kind' => $t['rate_kind'],
            'shop_id' => (int)$t['shop_id'], 'shop_name' => $shop['name'] ?? '', 'shop_code' => $shop['code'] ?? '',
            'created_at' => $t['created_at'], 'closed_at' => $t['closed_at'], 'applied_at' => $t['applied_at'], 'result' => $t['result'],
        ],
        'lines' => $lines, 'people' => $people, 'missing' => $missing ?? [], 'mine' => $mine,
    ]);
}

/**
 * POST count_scan: take_id, code, qty (default 1; negative = take back), uuid (unique per scan, re-sent on retry),
 * and rate (+ name for an unknown barcode) when the product is not in this count yet.
 * Answers {need_rate:true, ...} when the rate is still needed.
 */
function h_count_scan(): void
{
    $u = auth(TAKE_ANY);
    $b = body();
    $uuid = strtolower(trim((string)($b['uuid'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
        fail('bad_uuid');
    }
    $code = take_code($b['code'] ?? null);
    $qty = round(num($b['qty'] ?? 1, -100000, 100000), 3);
    if ($qty == 0.0) {
        fail('qty_zero');
    }
    $pdo = db();
    $pdo->beginTransaction();
    $t = take_for($u, (int)($b['take_id'] ?? 0), true);   // shared row lock order: take first, then line
    if ($t['status'] !== 'open') {
        fail('take_not_open', 409);
    }
    // Already received (the phone re-sent it after a network drop): answer with the line as it is now.
    $st = $pdo->prepare('SELECT l.*, s.id AS scan_id, s.undo_of FROM stock_take_scans s JOIN stock_take_lines l ON l.id = s.line_id WHERE s.take_id = ? AND s.client_uuid = ?');
    $st->execute([$t['id'], $uuid]);
    if ($l = $st->fetch()) {
        $pdo->commit();
        json_out(['ok' => true, 'repeat' => true, 'scan_id' => (int)$l['scan_id'], 'undone' => $l['undo_of'] === null ? null : (int)$l['undo_of'], 'line' => take_line_out($l)]);
    }
    // Undo: take back one exact scan (by its id), once. Works on the line that scan went to, whatever code it had.
    $undoOf = (int)($b['undo_scan_id'] ?? 0);
    if ($undoOf > 0) {
        $st = $pdo->prepare('SELECT * FROM stock_take_scans WHERE id = ? AND take_id = ?');
        $st->execute([$undoOf, $t['id']]);
        $sc = $st->fetch();
        if (!$sc || (float)$sc['qty'] <= 0 || ($u['role'] === 'stocker' && (int)$sc['user_id'] !== $u['id'])) {
            fail('undo_not_allowed', 409);
        }
        $st = $pdo->prepare('SELECT 1 FROM stock_take_scans WHERE take_id = ? AND undo_of = ?');
        $st->execute([$t['id'], $undoOf]);
        if ($st->fetchColumn()) {
            fail('already_undone', 409);
        }
        $st = $pdo->prepare('SELECT * FROM stock_take_lines WHERE id = ? FOR UPDATE');
        $st->execute([$sc['line_id']]);
        $line = $st->fetch();
        $back = (float)$sc['qty'];
        if (!$line || (float)$line['qty'] - $back < -0.0005) {
            fail('below_zero', 409);   // a manager already lowered this line
        }
        $pdo->prepare('UPDATE stock_take_lines SET qty = qty - ?, last_user_id = ?, last_at = NOW() WHERE id = ?')->execute([$back, $u['id'], $line['id']]);
        $pdo->prepare('INSERT INTO stock_take_scans (take_id, line_id, tenant_id, user_id, qty, client_uuid, undo_of) VALUES (?,?,?,?,?,?,?)')
            ->execute([$t['id'], $line['id'], $u['tenant_id'], $u['id'], -$back, $uuid, $undoOf]);
        $sid = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM stock_take_lines WHERE id = ?');
        $st->execute([$line['id']]);
        $l = $st->fetch();
        $pdo->commit();
        json_out(['ok' => true, 'undone' => $undoOf, 'scan_id' => $sid, 'line' => take_line_out($l)]);
    }
    $item = take_find_item($u['tenant_id'], (int)$t['company_id'], $code);
    // Unknown barcode that the stocker says is an existing product: remember the barcode on that product now,
    // so every next scan of it (by anyone) finds the product. If the product is already counted under another
    // code, the normal "already counted: more stock or same goods?" check below applies.
    $link = (int)($b['link_item_id'] ?? 0);
    if (!$item && $link > 0) {
        if (!preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $code)) {
            fail('bad_barcode');
        }
        $st = $pdo->prepare('SELECT id, sku, name, price, cost, uom FROM items WHERE id = ? AND tenant_id = ? AND company_id = ?');
        $st->execute([$link, $u['tenant_id'], $t['company_id']]);
        $item = $st->fetch();
        if (!$item) {
            fail('item_not_found', 404);
        }
        item_barcode_remember($pdo, $u['tenant_id'], (int)$t['company_id'], (int)$item['id'], $code);
        audit($u, 'take_barcode_link', "take {$t['id']} item {$item['id']} barcode $code");
    }
    $key = $item ? 'i:' . $item['id'] : 'c:' . strtoupper($code);
    $st = $pdo->prepare('SELECT * FROM stock_take_lines WHERE take_id = ? AND line_key = ? FOR UPDATE');
    $st->execute([$t['id'], $key]);
    $line = $st->fetch();
    $first = false;
    if (!$line) {
        if ($qty < 0) {
            fail('below_zero', 409);
        }
        if (!isset($b['rate']) || $b['rate'] === '' || $b['rate'] === null) {
            $pdo->commit();
            json_out([
                'need_rate' => true, 'code' => $code, 'rate_kind' => $t['rate_kind'],
                'item' => $item ? ['id' => (int)$item['id'], 'sku' => $item['sku'], 'name' => $item['name'],
                    'rate' => (float)($t['rate_kind'] === 'cost' ? $item['cost'] : $item['price'])] : null,
            ]);
        }
        $rate = round(num($b['rate'], 0, 1e7), 4);
        $name = $item ? (string)$item['name'] : (str($b['name'] ?? null, 200) ?? ('New item ' . $code));
        // Another stocker may have added this product a moment ago: then this scan simply adds to that line.
        $ins = $pdo->prepare(
            'INSERT INTO stock_take_lines (take_id, tenant_id, line_key, code, item_id, name, rate, qty, first_user_id, last_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE qty = qty + ?, last_user_id = ?, last_at = NOW(), id = LAST_INSERT_ID(id)'
        );
        $ins->execute([$t['id'], $u['tenant_id'], $key, $code, $item ? $item['id'] : null, $name, $rate, $qty, $u['id'], $u['id'], $qty, $u['id']]);
        $lineId = (int)$pdo->lastInsertId();
        $first = $ins->rowCount() === 1;
    } else {
        $lineId = (int)$line['id'];
        if ((float)$line['qty'] + $qty < -0.0005) {
            fail('below_zero', 409);
        }
        /*
         * Double-count guard. This product is already counted. If this person is not in the middle of scanning it
         * (no scan of it by them in the last few minutes) - another stocker counted it, or they come back to it later -
         * they must confirm it is MORE stock (another shelf / box), not the same goods counted again.
         */
        if ($qty > 0 && (float)$line['qty'] > 0.0005) {
            $q = $pdo->prepare('SELECT COUNT(*) FROM stock_take_scans WHERE line_id = ? AND user_id = ? AND qty > 0 AND created_at >= NOW() - INTERVAL ' . TAKE_STREAK_MIN . ' MINUTE');
            $q->execute([$lineId, $u['id']]);
            $streak = (int)$q->fetchColumn() > 0;
            if (!$streak && empty($b['confirm'])) {
                $q = $pdo->prepare('SELECT u.name, SUM(s.qty) AS qty, MAX(s.created_at) AS last_at FROM stock_take_scans s JOIN users u ON u.id = s.user_id
                                     WHERE s.line_id = ? GROUP BY u.id, u.name HAVING SUM(s.qty) <> 0 ORDER BY last_at DESC');
                $q->execute([$lineId]);
                $by = array_map(fn($r) => ['name' => $r['name'], 'qty' => (float)$r['qty'], 'at' => substr((string)$r['last_at'], 11, 5)], $q->fetchAll());
                $pdo->commit();
                json_out(['need_confirm' => true, 'code' => $code, 'line' => take_line_out($line), 'by' => $by]);
            }
            $recount = !$streak;
        }
        $pdo->prepare('UPDATE stock_take_lines SET qty = qty + ?, last_user_id = ?, last_at = NOW() WHERE id = ?')
            ->execute([$qty, $u['id'], $lineId]);
    }
    $pdo->prepare('INSERT INTO stock_take_scans (take_id, line_id, tenant_id, user_id, qty, client_uuid, recount) VALUES (?,?,?,?,?,?,?)')
        ->execute([$t['id'], $lineId, $u['tenant_id'], $u['id'], $qty, $uuid, empty($recount) ? 0 : 1]);
    $scanId = (int)$pdo->lastInsertId();
    $st = $pdo->prepare('SELECT * FROM stock_take_lines WHERE id = ?');
    $st->execute([$lineId]);
    $l = $st->fetch();
    $pdo->commit();
    json_out(['ok' => true, 'first' => $first, 'recount' => !empty($recount), 'scan_id' => $scanId, 'line' => take_line_out($l)]);
}

/** GET count_item_search: q. Products of the count's company for "this new barcode is an existing product". */
function h_count_item_search(): void
{
    $u = auth(TAKE_ANY);
    $t = take_for($u, (int)($_GET['take_id'] ?? 0));
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        json_out(['items' => []]);
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $like2 = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)preg_replace('/[^A-Za-z0-9._\-\/]+/', '-', $q)) . '%';
    $st = db()->prepare('SELECT id, sku, name, price, cost, uom FROM items WHERE tenant_id = ? AND company_id = ? AND active = 1 AND (name LIKE ? OR sku LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 20');
    $st->execute([$u['tenant_id'], $t['company_id'], $like, $like, $like2]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['rate'] = (float)($t['rate_kind'] === 'cost' ? $r['cost'] : $r['price']);
        unset($r['cost']);
    }
    unset($r);
    json_out(['items' => $rows]);
}

/** POST count_line: owner / manager correct a line (qty, rate, name) or delete it (delete: true). Logged. */
function h_count_line(): void
{
    $u = auth(TAKE_ADMIN);
    $b = body();
    $pdo = db();
    $pdo->beginTransaction();
    $t = take_for($u, (int)($b['take_id'] ?? 0), true);
    if (!in_array($t['status'], ['open', 'closed'], true)) {
        fail('take_not_open', 409);
    }
    $st = $pdo->prepare('SELECT * FROM stock_take_lines WHERE id = ? AND take_id = ? FOR UPDATE');
    $st->execute([(int)($b['line_id'] ?? 0), $t['id']]);
    $l = $st->fetch();
    if (!$l) {
        fail('line_not_found', 404);
    }
    if (!empty($b['delete'])) {
        $pdo->prepare('DELETE FROM stock_take_scans WHERE line_id = ?')->execute([$l['id']]);
        $pdo->prepare('DELETE FROM stock_take_lines WHERE id = ?')->execute([$l['id']]);
        $pdo->commit();
        audit($u, 'take_line_delete', "take {$t['id']} line {$l['id']} {$l['code']} qty {$l['qty']} rate {$l['rate']}");
        json_out(['ok' => true]);
    }
    $qty = round(num($b['qty'] ?? $l['qty'], 0, 1e7), 3);
    $rate = round(num($b['rate'] ?? $l['rate'], 0, 1e7), 4);
    $name = $l['item_id'] === null ? (str($b['name'] ?? null, 200) ?? $l['name']) : $l['name'];
    $delta = round($qty - (float)$l['qty'], 3);
    $pdo->prepare('UPDATE stock_take_lines SET qty = ?, rate = ?, name = ?, last_user_id = ?, last_at = NOW() WHERE id = ?')
        ->execute([$qty, $rate, $name, $u['id'], $l['id']]);
    if (abs($delta) > 0.0005) {   // keep "scans add up to the line" true, so per-person totals stay honest
        $pdo->prepare('INSERT INTO stock_take_scans (take_id, line_id, tenant_id, user_id, qty, client_uuid) VALUES (?,?,?,?,?,?)')
            ->execute([$t['id'], $l['id'], $u['tenant_id'], $u['id'], $delta, take_uuid()]);
    }
    $pdo->commit();
    audit($u, 'take_line_edit', "take {$t['id']} line {$l['id']} {$l['code']} qty {$l['qty']}->$qty rate {$l['rate']}->$rate");
    json_out(['ok' => true]);
}

function take_uuid(): string
{
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
}

/**
 * POST count_action: take_id, action:
 *   close   - stop scanning (stockers can no longer add); reopen - allow scanning again;
 *   cancel  - throw the count away (stock unchanged);
 *   apply   - set the shop's stock to the counted quantities, add unknown barcodes as new products,
 *             update each product's rate; zero_unscanned: also set products nobody scanned to 0.
 */
function h_count_action(): void
{
    $u = auth(TAKE_ADMIN);
    $b = body();
    $action = (string)($b['action'] ?? '');
    $pdo = db();
    $pdo->beginTransaction();
    $t = take_for($u, (int)($b['take_id'] ?? 0), true);
    $tid = (int)$t['id'];
    if ($action === 'close' || $action === 'reopen') {
        if ($t['status'] !== ($action === 'close' ? 'open' : 'closed')) {
            fail('take_not_open', 409);
        }
        $pdo->prepare('UPDATE stock_takes SET status = ?, closed_by = ?, closed_at = ? WHERE id = ?')
            ->execute([$action === 'close' ? 'closed' : 'open', $action === 'close' ? $u['id'] : null, $action === 'close' ? date('Y-m-d H:i:s') : null, $tid]);
        $pdo->commit();
        audit($u, 'take_' . $action, "take $tid");
        json_out(['ok' => true]);
    }
    if ($action === 'cancel') {
        if (!in_array($t['status'], ['open', 'closed'], true)) {
            fail('take_not_open', 409);
        }
        $pdo->prepare("UPDATE stock_takes SET status = 'cancelled', closed_by = ?, closed_at = NOW() WHERE id = ?")->execute([$u['id'], $tid]);
        $pdo->commit();
        audit($u, 'take_cancel', "take $tid");
        json_out(['ok' => true]);
    }
    if ($action !== 'apply') {
        fail('bad_action');
    }
    if ($t['status'] !== 'closed') {
        fail('take_close_first', 409);   // close first, so no stocker adds a scan while stock is being set
    }
    $shop = assert_shop($u, (int)$t['shop_id']);
    $cid = (int)$t['company_id'];
    $kind = $t['rate_kind'];
    $zero = !empty($b['zero_unscanned']);
    $st = $pdo->prepare('SELECT * FROM stock_take_lines WHERE take_id = ? ORDER BY id');
    $st->execute([$tid]);
    $lines = $st->fetchAll();
    if (!$lines) {
        fail('take_empty');
    }
    $insL = $pdo->prepare('INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, ref_id, note, user_id) VALUES (?,?,?,?,?,?,?,?,?)');
    $onHand = $pdo->prepare('SELECT COALESCE(SUM(qty),0), COUNT(*) FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? AND item_id = ?');
    $note = 'Stock take #' . $tid;
    $created = $changed = $rates = 0;
    $value = 0.0;
    $touched = [];
    foreach ($lines as $l) {
        $itemId = $l['item_id'] === null ? null : (int)$l['item_id'];
        if ($itemId === null) {
            // Someone may have registered the barcode while the count was running.
            $it = take_find_item($u['tenant_id'], $cid, $l['code']);
            if ($it) {
                $itemId = (int)$it['id'];
            } else {
                $itemId = take_create_item($pdo, $u['tenant_id'], $cid, $l['code'], $l['name'], (float)$l['rate'], $kind);
                $created++;
            }
            $pdo->prepare('UPDATE stock_take_lines SET item_id = ? WHERE id = ?')->execute([$itemId, $l['id']]);
        }
        if (isset($touched[$itemId])) {
            // two lines ended up on the same product (an unknown code registered meanwhile): add them together
            $touched[$itemId] += (float)$l['qty'];
        } else {
            $touched[$itemId] = (float)$l['qty'];
        }
        $rate = (float)$l['rate'];
        $value += $rate * (float)$l['qty'];
        if ($rate > 0) {
            $col = $kind === 'cost' ? 'cost' : 'price';
            $r = $pdo->prepare("UPDATE items SET $col = ? WHERE id = ? AND tenant_id = ? AND ABS($col - ?) > 0.00005");
            $r->execute([$kind === 'cost' ? round($rate, 4) : round($rate, 2), $itemId, $u['tenant_id'], $rate]);
            $rates += $r->rowCount();
        }
    }
    $costOf = $pdo->prepare('SELECT name, cost FROM items WHERE id = ?');
    $threshold = (float)settings_for($u['tenant_id'])['low_stock_threshold'];
    $setTo = function (int $itemId, float $counted) use ($pdo, $u, $shop, $tid, $note, $insL, $onHand, $costOf, $threshold, &$changed): void {
        $onHand->execute([$u['tenant_id'], $shop['id'], $itemId]);
        [$was, $had] = $onHand->fetch(PDO::FETCH_NUM);
        $delta = round($counted - (float)$was, 3);
        $costOf->execute([$itemId]);
        $it = $costOf->fetch();
        if (abs($delta) > 0.0005) {
            $insL->execute([$u['tenant_id'], $shop['id'], $itemId, $delta, $it['cost'], (int)$had > 0 ? 'adjust' : 'opening', $tid, $note, $u['id']]);
            $changed++;
        }
        notify_low_stock($pdo, $u['tenant_id'], (int)$shop['company_id'], (int)$shop['id'], $itemId, $counted, $threshold, (string)$it['name'], (string)$shop['name']);
    };
    foreach ($touched as $itemId => $counted) {
        $setTo((int)$itemId, round($counted, 3));
    }
    $zeroed = 0;
    if ($zero) {
        $q = $pdo->prepare('SELECT item_id FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? GROUP BY item_id HAVING ABS(SUM(qty)) > 0.0005');
        $q->execute([$u['tenant_id'], $shop['id']]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $iid) {
            if (!isset($touched[(int)$iid])) {
                $setTo((int)$iid, 0.0);
                $zeroed++;
            }
        }
    }
    $result = count($touched) . ' products counted, ' . $changed . ' stock changes, ' . $created . ' new products, ' . $rates . ' rates updated'
        . ($zero ? ', ' . $zeroed . ' not-counted products set to 0' : '') . ', value ' . number_format($value, 2, '.', '');
    $pdo->prepare("UPDATE stock_takes SET status = 'applied', applied_by = ?, applied_at = NOW(), result = ? WHERE id = ?")
        ->execute([$u['id'], $result, $tid]);
    $pdo->commit();
    audit($u, 'take_apply', "take $tid shop {$shop['code']}: $result");
    json_out(['ok' => true, 'result' => $result]);
}

/** New product from an unknown barcode. SKU = the barcode (made unique if that SKU is taken). */
function take_create_item(PDO $pdo, int $tenantId, int $cid, string $code, string $name, float $rate, string $kind): int
{
    $base = substr($code, 0, 36);
    if (!preg_match('#^[A-Za-z0-9._\-/]+$#', $base)) {
        $base = 'ST' . substr(md5($code), 0, 8);
    }
    $sku = $base;
    $chk = $pdo->prepare('SELECT 1 FROM items WHERE company_id = ? AND sku = ?');
    for ($i = 2; $i < 1000; $i++) {
        $chk->execute([$cid, $sku]);
        if (!$chk->fetchColumn()) {
            break;
        }
        $sku = $base . '-' . $i;
    }
    $price = $kind === 'price' ? round($rate, 2) : 0;
    $cost = $kind === 'cost' ? round($rate, 4) : 0;
    $pdo->prepare('INSERT INTO items (tenant_id, company_id, sku, name, uom, price, cost, vat_rate, reorder_level, active) VALUES (?,?,?,?,?,?,?,5,0,1)')
        ->execute([$tenantId, $cid, $sku, mb_substr($name, 0, 200), 'pc', $price, $cost]);
    $id = (int)$pdo->lastInsertId();
    if (preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $code)) {
        $pdo->prepare('INSERT IGNORE INTO item_barcodes (tenant_id, company_id, item_id, barcode) VALUES (?,?,?,?)')->execute([$tenantId, $cid, $id, $code]);
    }
    return $id;
}

/**
 * POST stockers_create: shop_id, names[] (one per stocker). Makes stocker logins tied to that shop
 * and returns each username + password once. A name already used by an active stocker of the shop is refused.
 */
function h_stockers_create(): void
{
    $u = auth(TAKE_ADMIN);
    $b = body();
    $shop = assert_shop($u, (int)($b['shop_id'] ?? 0));
    $names = [];
    foreach ((array)($b['names'] ?? []) as $n) {
        $n = trim((string)$n);
        if ($n === '') {
            continue;
        }
        if (mb_strlen($n) > 120) {
            fail('text_too_long');
        }
        $k = mb_strtolower($n);
        if (isset($names[$k])) {
            fail('duplicate_name:' . $n, 409);
        }
        $names[$k] = $n;
    }
    if (!$names) {
        fail('missing_field');
    }
    if (count($names) > 50) {
        fail('too_many_rows');
    }
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT LOWER(name) FROM users WHERE tenant_id = ? AND shop_id = ? AND role = 'stocker' AND active = 1");
    $st->execute([$u['tenant_id'], $shop['id']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $have) {
        if (isset($names[$have])) {
            fail('duplicate_name:' . $names[$have], 409);
        }
    }
    $out = [];
    foreach ($names as $n) {
        $plain = gen_pass_plain();
        $pdo->prepare("INSERT INTO users (tenant_id, name, username, email, password_hash, role, shop_id, active, is_primary) VALUES (?,?,NULL,NULL,?,'stocker',?,1,0)")
            ->execute([$u['tenant_id'], $n, password_hash($plain, PASSWORD_DEFAULT), $shop['id']]);
        $id = (int)$pdo->lastInsertId();
        $un = gen_username($pdo, $u['tenant_id'], (int)$shop['id'], $n);
        $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$un, $id]);
        $out[] = ['id' => $id, 'name' => $n, 'username' => $un, 'password' => $plain];
    }
    $pdo->commit();
    audit($u, 'stockers_create', "shop {$shop['code']}: " . implode(', ', array_map(fn($o) => $o['username'], $out)));
    json_out(['stockers' => $out, 'shop' => $shop['name']]);
}
