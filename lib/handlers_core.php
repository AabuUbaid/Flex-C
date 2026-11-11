<?php
declare(strict_types=1);

function org_for(array $u): array
{
    $pdo = db();
    $sql = 'SELECT id, company_id, code, name, trade, group_code, logo FROM shops WHERE tenant_id = ?';
    $args = [$u['tenant_id']];
    if ($u['shop_id'] !== null) {
        $sql .= ' AND id = ?';
        $args[] = $u['shop_id'];
    }
    $st = $pdo->prepare($sql . ' ORDER BY name');
    $st->execute($args);
    $shops = $st->fetchAll();

    $companyIds = array_values(array_unique(array_map(fn($s) => (int)$s['company_id'], $shops)));
    $companies = [];
    if ($companyIds) {
        $st = $pdo->prepare(
            'SELECT id, name, name_ar, trn, address, phone, footer, logo FROM companies
              WHERE tenant_id = ? AND id IN (' . placeholders(count($companyIds)) . ') ORDER BY name'
        );
        $st->execute(array_merge([$u['tenant_id']], $companyIds));
        $companies = $st->fetchAll();
    }

    $registers = [];
    if ($shops) {
        $ids = array_map(fn($s) => (int)$s['id'], $shops);
        $st = $pdo->prepare(
            'SELECT id, shop_id, code FROM registers WHERE tenant_id = ? AND shop_id IN (' . placeholders(count($ids)) . ') ORDER BY code'
        );
        $st->execute(array_merge([$u['tenant_id']], $ids));
        $registers = $st->fetchAll();
    }
    return ['companies' => $companies, 'shops' => $shops, 'registers' => $registers];
}

function h_login(): void
{
    $b = body();
    // Sign in with email OR the generated username (shop code + name + 6 digits).
    $login = strtolower(trim((string)($b['email'] ?? $b['username'] ?? '')));
    $pass = (string)($b['password'] ?? '');
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $pdo = db();

    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 10) {
        fail('too_many_attempts', 429);
    }

    $st = $pdo->prepare(
        'SELECT u.* FROM users u JOIN tenants t ON t.id = u.tenant_id
          WHERE (LOWER(u.email) = ? OR LOWER(u.username) = ?) AND u.active = 1 AND t.active = 1'
    );
    $st->execute([$login, $login]);
    $u = $st->fetch();
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        $pdo->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
        fail('invalid_login', 401);
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (token_hash, user_id, expires_at) VALUES (?, ?, NOW() + INTERVAL 30 DAY)')
        ->execute([hash('sha256', $token), $u['id']]);
    $pdo->exec('DELETE FROM api_tokens WHERE expires_at < NOW()');
    $pdo->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');

    $user = [
        'id' => (int)$u['id'], 'tenant_id' => (int)$u['tenant_id'], 'name' => $u['name'],
        'username' => $u['username'], 'role' => $u['role'],
        'shop_id' => $u['shop_id'] === null || $u['role'] === 'owner' ? null : (int)$u['shop_id'],   // owners see every shop
    ];
    json_out([
        'token' => $token, 'user' => $user, 'org' => org_for($user),
        'settings' => settings_for($user['tenant_id']),
        'brand' => BRAND_NAME . ' by ' . BRAND_VENDOR,
    ]);
}

function h_logout(): void
{
    $t = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if ($t !== '') {
        db()->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([hash('sha256', $t)]);
    }
    json_out(['ok' => true]);
}

function h_org(): void
{
    $u = auth();
    json_out(org_for($u));
}

/** Everything a register needs to sell offline. Cost prices are never sent. */
function h_master(): void
{
    $u = auth();
    if ($u['role'] === 'stocker') {
        fail('stocker_no_pos', 403);   // stockers only scan in Back office > Stock take
    }
    $pdo = db();
    $rid = (int)($_GET['register_id'] ?? 0);

    $st = $pdo->prepare(
        'SELECT r.id, r.code, r.shop_id, s.code AS shop_code, s.name AS shop_name, s.company_id,
                s.logo AS shop_logo, s.group_code
           FROM registers r JOIN shops s ON s.id = r.shop_id
          WHERE r.id = ? AND r.tenant_id = ?'
    );
    $st->execute([$rid, $u['tenant_id']]);
    $reg = $st->fetch();
    if (!$reg) {
        fail('register_not_found', 404);
    }
    assert_shop($u, (int)$reg['shop_id']);

    $st = $pdo->prepare('SELECT id, name, name_ar, trn, address, phone, footer, logo FROM companies WHERE id = ? AND tenant_id = ?');
    $st->execute([$reg['company_id'], $u['tenant_id']]);
    $company = $st->fetch();

    $st = $pdo->prepare(
        'SELECT i.id, i.sku, i.name, i.name_ar, i.category, i.uom, i.price, i.vat_rate, i.reorder_level,
                COALESCE(s.q, 0) AS stock
           FROM items i
           LEFT JOIN (SELECT item_id, SUM(qty) AS q FROM stock_ledger WHERE tenant_id = ? AND shop_id = ? GROUP BY item_id) s
                  ON s.item_id = i.id
          WHERE i.tenant_id = ? AND i.company_id = ? AND i.active = 1
          ORDER BY i.name'
    );
    $st->execute([$u['tenant_id'], $reg['shop_id'], $u['tenant_id'], $reg['company_id']]);
    $items = $st->fetchAll();

    $st = $pdo->prepare('SELECT item_id, barcode FROM item_barcodes WHERE tenant_id = ? AND company_id = ?');
    $st->execute([$u['tenant_id'], $reg['company_id']]);
    $bc = [];
    foreach ($st->fetchAll() as $r) {
        $bc[(int)$r['item_id']][] = $r['barcode'];
    }
    foreach ($items as &$i) {
        $i['id'] = (int)$i['id'];
        $i['price'] = (float)$i['price'];
        $i['vat_rate'] = (float)$i['vat_rate'];
        $i['reorder_level'] = (float)$i['reorder_level'];
        $i['stock'] = (float)$i['stock'];
        $i['barcodes'] = $bc[$i['id']] ?? [];
    }
    unset($i);

    $st = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) FROM sales WHERE register_id = ?');
    $st->execute([$rid]);

    $settings = settings_for($u['tenant_id']);
    json_out([
        'company' => $company,
        'shop' => [
            'id' => (int)$reg['shop_id'], 'code' => $reg['shop_code'], 'name' => $reg['shop_name'],
            'group_code' => $reg['group_code'], 'logo' => $reg['shop_logo'],
        ],
        'register' => ['id' => (int)$reg['id'], 'code' => $reg['code']],
        'items' => $items,
        'settings' => $settings,
        'last_seq' => (int)$st->fetchColumn(),
        'server_time' => date('Y-m-d H:i:s'),
    ]);
}

/** Latest, and minimum required, app version from the Console's release list. */
function release_state(): array
{
    $latest = null;
    $min = '0.0.0';
    $all = db()->query('SELECT id, version, notes, force_update, retired, published_at FROM app_releases ORDER BY id DESC')->fetchAll();
    foreach ($all as $r) {
        if ($r['retired']) {
            continue;
        }
        if (!$latest || version_compare($r['version'], $latest['version'], '>')) {
            $latest = $r;
        }
        if ($r['force_update'] && version_compare($r['version'], $min, '>')) {
            $min = $r['version'];
        }
    }
    return ['latest' => $latest, 'min_required' => $min, 'all' => $all];
}

/** Pages call this after sign-in with the version they are running (?v=). */
function h_version(): void
{
    $u = auth();
    $v = substr((string)preg_replace('/[^0-9.]/', '', (string)($_GET['v'] ?? '')), 0, 20);
    try {
        if ($v !== '') {
            db()->prepare('UPDATE tenants SET last_version = ?, last_seen_at = NOW()
                            WHERE id = ? AND (last_version IS NULL OR last_version <> ? OR last_seen_at < NOW() - INTERVAL 10 MINUTE)')
                ->execute([$v, $u['tenant_id'], $v]);
        }
        $s = release_state();
        // Staged roll-out: a customer in 'push' mode only hears about versions up to the one Adnys pushed to them.
        $t = db()->prepare('SELECT update_mode, pushed_version FROM tenants WHERE id = ?');
        $t->execute([$u['tenant_id']]);
        $t = $t->fetch();
        if ($t && $t['update_mode'] === 'push') {
            $cap = $t['pushed_version'];
            $latest = null;
            $min = '0.0.0';
            foreach ($s['all'] as $r) {
                if ($r['retired'] || $cap === null || version_compare($r['version'], $cap, '>')) {
                    continue;
                }
                if (!$latest || version_compare($r['version'], $latest['version'], '>')) {
                    $latest = $r;
                }
                if ($r['force_update'] && version_compare($r['version'], $min, '>')) {
                    $min = $r['version'];
                }
            }
            $s = ['latest' => $latest, 'min_required' => $min];
        }
    } catch (PDOException $e) {
        $s = ['latest' => null, 'min_required' => '0.0.0'];   // console upgrade not run yet
    }
    json_out(['latest' => $s['latest']['version'] ?? null, 'notes' => $s['latest']['notes'] ?? '', 'min_required' => $s['min_required']]);
}

/**
 * Approve a sensitive action (return, discount, void) with a manager/accountant/owner
 * username + password. Returns a short-lived single-use token. Every approval is audited.
 */
function h_authorize(): void
{
    $u = auth();
    if ($u['role'] === 'stocker') {
        fail('forbidden', 403);
    }
    $b = body();
    $login = strtolower(trim((string)($b['username'] ?? $b['email'] ?? '')));
    $pass = (string)($b['password'] ?? '');
    $purpose = (string)($b['purpose'] ?? 'return');
    if (!in_array($purpose, ['return', 'discount', 'void', 'closing', 'stock'], true)) {
        fail('bad_purpose');
    }
    if ($login === '' || $pass === '') {
        fail('missing_field');
    }
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 10) {
        fail('too_many_attempts', 429);
    }
    $st = db()->prepare(
        'SELECT id, name, username, email, role, password_hash FROM users
          WHERE tenant_id = ? AND (LOWER(username) = ? OR LOWER(email) = ?) AND active = 1'
    );
    $st->execute([$u['tenant_id'], $login, $login]);
    $a = $st->fetch();
    if (!$a || !password_verify($pass, $a['password_hash'])) {
        db()->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
        fail('invalid_authoriser', 403);
    }
    if (!in_array($a['role'], ['owner', 'manager', 'accountant'], true)) {
        fail('not_authorised_role', 403);
    }
    $token = bin2hex(random_bytes(24));
    db()->prepare(
        'INSERT INTO authorisations (tenant_id, user_id, purpose, token_hash, expires_at, ip)
         VALUES (?,?,?,?, NOW() + INTERVAL 10 MINUTE, ?)'
    )->execute([$u['tenant_id'], $a['id'], $purpose, hash('sha256', $token), $ip]);
    db()->exec('DELETE FROM authorisations WHERE expires_at < NOW() - INTERVAL 1 DAY');
    audit($u, 'authorise', "purpose $purpose by " . ($a['username'] ?: $a['email']));
    json_out([
        'token' => $token,
        'expires_in' => 600,
        'authorised_by' => [
            'id' => (int)$a['id'], 'name' => $a['name'], 'username' => $a['username'],
            'role' => $a['role'],
        ],
    ]);
}
