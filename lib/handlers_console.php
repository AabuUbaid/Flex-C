<?php
declare(strict_types=1);
// Adnys Console handlers. Staff are separate from tenant users and use the X-Staff-Token header.

function cauth(bool $adminOnly = false): array
{
    $t = $_SERVER['HTTP_X_STAFF_TOKEN'] ?? '';
    if ($t === '') {
        fail('unauthorized', 401);
    }
    $st = db()->prepare('SELECT s.id, s.name, s.role FROM staff_tokens k JOIN staff s ON s.id = k.staff_id
                          WHERE k.token_hash = ? AND k.expires_at > NOW() AND s.active = 1');
    $st->execute([hash('sha256', $t)]);
    $s = $st->fetch();
    if (!$s) {
        fail('unauthorized', 401);
    }
    $s['id'] = (int)$s['id'];
    if ($adminOnly && $s['role'] !== 'admin') {
        fail('forbidden', 403);
    }
    return $s;
}

function caudit(array $s, string $action, string $detail = ''): void
{
    db()->prepare('INSERT INTO console_audit (staff_id, action, detail, ip) VALUES (?,?,?,?)')
        ->execute([$s['id'], $action, mb_substr($detail, 0, 2000), substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)]);
}

function gen_pass(): string
{
    return rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'AB'), '=');
}

function tenant_must(int $id): array
{
    $st = db()->prepare('SELECT * FROM tenants WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: fail('tenant_not_found', 404);
}

function v_email(mixed $v): string
{
    $e = strtolower(trim((string)$v));
    if (!filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 190) {
        fail('bad_email');
    }
    return $e;
}

/** Email is optional for tenant users: they sign in with the generated username. */
function v_email_opt(mixed $v): ?string
{
    $e = strtolower(trim((string)$v));
    return $e === '' ? null : v_email($e);
}

function v_trn(mixed $v): ?string
{
    $t = str($v, 20);
    if ($t !== null && !preg_match('/^\d{15}$/', $t)) {
        fail('bad_trn');
    }
    return $t;
}

function v_code(mixed $v): string
{
    $c = strtoupper(trim((string)$v));
    if (!preg_match('/^[A-Z0-9]{1,10}$/', $c)) {
        fail('bad_code');
    }
    return $c;
}

function v_trade(mixed $v): string
{
    $t = (string)($v ?: 'other');
    return in_array($t, ['clothes', 'mobile', 'grocery', 'stationery', 'other'], true) ? $t : fail('bad_trade');
}

function c_login(): void
{
    $b = body();
    $email = strtolower(trim((string)($b['email'] ?? '')));
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 10) {
        fail('too_many_attempts', 429);
    }
    $st = $pdo->prepare('SELECT * FROM staff WHERE email = ? AND active = 1');
    $st->execute([$email]);
    $s = $st->fetch();
    if (!$s || !password_verify((string)($b['password'] ?? ''), $s['password_hash'])) {
        $pdo->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
        fail('invalid_login', 401);
    }
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO staff_tokens (token_hash, staff_id, expires_at) VALUES (?, ?, NOW() + INTERVAL 12 HOUR)')
        ->execute([hash('sha256', $token), $s['id']]);
    $pdo->exec('DELETE FROM staff_tokens WHERE expires_at < NOW()');
    $me = ['id' => (int)$s['id'], 'name' => $s['name'], 'role' => $s['role']];
    caudit($me, 'login');
    json_out(['token' => $token, 'staff' => $me]);
}

function c_logout(): void
{
    $t = $_SERVER['HTTP_X_STAFF_TOKEN'] ?? '';
    if ($t !== '') {
        db()->prepare('DELETE FROM staff_tokens WHERE token_hash = ?')->execute([hash('sha256', $t)]);
    }
    json_out(['ok' => true]);
}

/** Home screen: every tenant with size and the version it last ran, plus release state. */
function c_overview(): void
{
    cauth();
    $tenants = db()->query(
        'SELECT t.id, t.name, t.active, t.plan, t.contact_name, t.contact_email, t.last_version, t.last_seen_at, t.created_at, t.update_mode, t.pushed_version, t.is_demo,
                (SELECT COUNT(*) FROM companies c WHERE c.tenant_id = t.id) AS companies,
                (SELECT COUNT(*) FROM shops s WHERE s.tenant_id = t.id) AS shops,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.active = 1) AS users
           FROM tenants t ORDER BY t.name'
    )->fetchAll();
    $rel = release_state();
    $outdated = 0;
    foreach ($tenants as &$t) {
        $target = $t['update_mode'] === 'auto' ? ($rel['latest']['version'] ?? null) : $t['pushed_version'];
        $t['waiting'] = $rel['latest'] && $t['update_mode'] === 'push' && $t['pushed_version'] && version_compare($t['pushed_version'], $rel['latest']['version'], '<');
        $t['outdated'] = $target && $t['last_version'] && version_compare($t['last_version'], $target, '<');
        $outdated += $t['outdated'] ? 1 : 0;
    }
    unset($t);
    json_out(['tenants' => $tenants, 'latest' => $rel['latest'], 'min_required' => $rel['min_required'], 'outdated' => $outdated]);
}

function c_tenant_get(): void
{
    cauth();
    $t = tenant_must((int)($_GET['id'] ?? 0));
    $pdo = db();
    $get = function (string $sql) use ($pdo, $t): array {
        $st = $pdo->prepare($sql);
        $st->execute([$t['id']]);
        return $st->fetchAll();
    };
    json_out([
        'tenant'    => $t,
        'companies' => $get('SELECT id, name, name_ar, trn, address, phone, footer, logo FROM companies WHERE tenant_id = ? ORDER BY name'),
        'shops'     => $get('SELECT id, company_id, code, group_code, name, trade, address, logo FROM shops WHERE tenant_id = ? ORDER BY name'),
        'registers' => $get('SELECT id, shop_id, code FROM registers WHERE tenant_id = ? ORDER BY code'),
        'users'     => $get('SELECT id, name, username, email, role, shop_id, active, is_primary FROM users WHERE tenant_id = ? ORDER BY is_primary DESC, role, name'),
    ]);
}

/** One call creates the customer: tenant + first company + first shop + register R1 + owner login. */
function c_tenant_create(): void
{
    $s = cauth();
    $b = body();
    $name = str($b['name'] ?? null, 150, true);
    $company = str($b['company_name'] ?? null, 150) ?? $name;
    $ownerEmail = v_email_opt($b['owner_email'] ?? null);
    $ownerName = str($b['owner_name'] ?? null, 120) ?? 'Owner';
    $shopName = str($b['shop_name'] ?? null, 120) ?? 'Main Shop';
    $trn = v_trn($b['trn'] ?? null);
    $trade = v_trade($b['trade'] ?? 'other');
    $pdo = db();

    if ($ownerEmail !== null) {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$ownerEmail]);
        if ($st->fetchColumn()) {
            fail('duplicate', 409);
        }
    }
    $pass = gen_pass();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO tenants (name, plan, contact_name, contact_email, contact_phone, notes, pushed_version) VALUES (?,?,?,?,?,?,?)')
        ->execute([$name, str($b['plan'] ?? 'standard', 30) ?? 'standard', $ownerName, $ownerEmail, str($b['contact_phone'] ?? null, 40), str($b['notes'] ?? null, 1000), release_state()['latest']['version'] ?? null]);
    $tid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO companies (tenant_id, name, name_ar, trn, address, phone) VALUES (?,?,?,?,?,?)')
        ->execute([$tid, $company, str($b['company_name_ar'] ?? null, 150), $trn, str($b['address'] ?? null, 255), str($b['contact_phone'] ?? null, 40)]);
    $cid = (int)$pdo->lastInsertId();
    // Shop code = shop name + 001, 002 ... so all branches of the same shop are recognisable.
    $requested = trim((string)($b['shop_code'] ?? ''));
    $code = $requested === '' ? shop_code_from_name($pdo, $tid, $shopName) : v_code($requested);
    $group = (string)preg_replace('/\d+$/', '', $code);
    $pdo->prepare('INSERT INTO shops (tenant_id, company_id, code, group_code, name, trade) VALUES (?,?,?,?,?,?)')
        ->execute([$tid, $cid, $code, $group === '' ? null : substr($group, 0, 10), $shopName, $trade]);
    $sid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO registers (tenant_id, shop_id, code) VALUES (?,?,?)')->execute([$tid, $sid, 'R1']);
    $pdo->prepare('INSERT INTO users (tenant_id, name, username, email, password_hash, role, shop_id, is_primary) VALUES (?,?,NULL,?,?,?,?,1)')
        ->execute([$tid, $ownerName, $ownerEmail, password_hash($pass, PASSWORD_DEFAULT), 'owner', $sid]);
    $uid = (int)$pdo->lastInsertId();
    $username = gen_username($pdo, $tid, $sid, $ownerName);
    $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$username, $uid]);
    $pdo->commit();
    caudit($s, 'tenant_create', "tenant #$tid $name owner $username shop $code");
    json_out(['tenant_id' => $tid, 'login' => $username, 'username' => $username, 'email' => $ownerEmail, 'password' => $pass, 'shop_code' => $code]);
}

function c_tenant_update(): void
{
    $s = cauth();
    $b = body();
    $t = tenant_must((int)($b['id'] ?? 0));
    if (array_key_exists('active', $b) && (int)(bool)$b['active'] !== (int)$t['active']) {
        cauth(true);   // only admins suspend or reactivate a customer
    }
    $set = [];
    $args = [];
    foreach (['name' => 150, 'plan' => 30, 'contact_name' => 120, 'contact_phone' => 40, 'notes' => 1000] as $f => $max) {
        if (array_key_exists($f, $b)) {
            $v = str($b[$f], $max, $f === 'name');
            $set[] = "$f = ?";
            $args[] = $v;
        }
    }
    if (array_key_exists('contact_email', $b)) {
        $set[] = 'contact_email = ?';
        $args[] = $b['contact_email'] === '' ? null : v_email($b['contact_email']);
    }
    if (array_key_exists('active', $b)) {
        $set[] = 'active = ?';
        $args[] = $b['active'] ? 1 : 0;
    }
    if ($set) {
        $args[] = $t['id'];
        db()->prepare('UPDATE tenants SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
        caudit($s, 'tenant_update', "tenant #{$t['id']} " . json_encode(array_diff_key($b, ['id' => 1])));
    }
    json_out(['ok' => true]);
}

function c_company_save(): void
{
    $s = cauth();
    $b = body();
    $t = tenant_must((int)($b['tenant_id'] ?? 0));
    $f = [str($b['name'] ?? null, 150, true), str($b['name_ar'] ?? null, 150), v_trn($b['trn'] ?? null),
          str($b['address'] ?? null, 255), str($b['phone'] ?? null, 40), str($b['footer'] ?? null, 255)];
    $logo = array_key_exists('logo', $b) ? clean_logo($b['logo']) : null;
    $pdo = db();
    if (!empty($b['id'])) {
        $pdo->prepare('UPDATE companies SET name=?, name_ar=?, trn=?, address=?, phone=?, footer=? WHERE id=? AND tenant_id=?')
            ->execute(array_merge($f, [(int)$b['id'], $t['id']]));
        if (array_key_exists('logo', $b)) {
            $pdo->prepare('UPDATE companies SET logo = ? WHERE id = ? AND tenant_id = ?')->execute([$logo, (int)$b['id'], $t['id']]);
        }
        caudit($s, 'company_update', "tenant #{$t['id']} company #{$b['id']}");
        json_out(['ok' => true, 'tenant_id' => (int)$t['id']]);
    }
    // New company. An optional first shop is created with register R1, because a company
    // with no shop cannot sell anything. The shop code is generated from its name if omitted.
    $shopName = str($b['shop_name'] ?? null, 120);
    $requestedCode = trim((string)($b['shop_code'] ?? ''));
    $makeShop = $requestedCode !== '' || $shopName !== null;
    $trade = v_trade($b['trade'] ?? 'other');
    $shopLogo = array_key_exists('shop_logo', $b) ? clean_logo($b['shop_logo']) : null;
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO companies (name, name_ar, trn, address, phone, footer, tenant_id, logo) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(array_merge($f, [$t['id'], $logo]));
    $cid = (int)$pdo->lastInsertId();
    $sid = null;
    $shopCode = null;
    if ($makeShop) {
        $shopName = $shopName ?? 'Main Shop';
        $shopCode = $requestedCode === '' ? shop_code_from_name($pdo, (int)$t['id'], $shopName) : v_code($requestedCode);
        $group = (string)preg_replace('/\d+$/', '', $shopCode);
        $pdo->prepare('INSERT INTO shops (tenant_id, company_id, code, group_code, name, trade, logo) VALUES (?,?,?,?,?,?,?)')
            ->execute([$t['id'], $cid, $shopCode, $group === '' ? null : substr($group, 0, 10), $shopName, $trade, $shopLogo]);
        $sid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO registers (tenant_id, shop_id, code) VALUES (?,?,?)')->execute([$t['id'], $sid, 'R1']);
    }
    $pdo->commit();
    caudit($s, 'company_create', "tenant #{$t['id']} {$f[0]}" . ($shopCode ? " shop $shopCode" : ''));
    json_out(['ok' => true, 'tenant_id' => (int)$t['id'], 'company_id' => $cid, 'shop_id' => $sid, 'shop_code' => $shopCode]);
}

/** New shop always gets register R1. */
function c_shop_save(): void
{
    $s = cauth(true);   // a new shop / branch is added by an Adnys admin only (it changes what the customer pays for)
    $b = body();
    $t = tenant_must((int)($b['tenant_id'] ?? 0));
    $pdo = db();
    $st = $pdo->prepare('SELECT 1 FROM companies WHERE id = ? AND tenant_id = ?');
    $st->execute([(int)($b['company_id'] ?? 0), $t['id']]);
    if (!$st->fetchColumn()) {
        fail('company_not_found', 404);
    }
    $pdo->beginTransaction();
    $shopName = str($b['name'] ?? null, 120, true);
    $requested = trim((string)($b['code'] ?? ''));
    $code = $requested === '' ? shop_code_from_name($pdo, (int)$t['id'], $shopName) : v_code($requested);
    $group = (string)preg_replace('/\d+$/', '', $code);
    $logo = clean_logo($b['logo'] ?? null);
    $pdo->prepare('INSERT INTO shops (tenant_id, company_id, code, group_code, name, trade, address, logo) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$t['id'], (int)$b['company_id'], $code, $group === '' ? null : substr($group, 0, 10), $shopName, v_trade($b['trade'] ?? 'other'), str($b['address'] ?? null, 255), $logo]);
    $sid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO registers (tenant_id, shop_id, code) VALUES (?,?,?)')->execute([$t['id'], $sid, 'R1']);
    $pdo->commit();
    caudit($s, 'shop_create', "tenant #{$t['id']} shop #$sid code $code");
    json_out(['ok' => true, 'shop_id' => $sid, 'shop_code' => $code]);
}

function c_register_save(): void
{
    $s = cauth();
    $b = body();
    $t = tenant_must((int)($b['tenant_id'] ?? 0));
    $st = db()->prepare('SELECT 1 FROM shops WHERE id = ? AND tenant_id = ?');
    $st->execute([(int)($b['shop_id'] ?? 0), $t['id']]);
    if (!$st->fetchColumn()) {
        fail('shop_not_found', 404);
    }
    db()->prepare('INSERT INTO registers (tenant_id, shop_id, code) VALUES (?,?,?)')->execute([$t['id'], (int)$b['shop_id'], v_code($b['code'] ?? '')]);
    caudit($s, 'register_create', "tenant #{$t['id']} shop #{$b['shop_id']}");
    json_out(['ok' => true]);
}

function c_user_save(): void
{
    $s = cauth();
    $b = body();
    $t = tenant_must((int)($b['tenant_id'] ?? 0));
    $role = (string)($b['role'] ?? '');
    if ($role === 'owner') {
        cauth(true);   // only an Adnys admin creates another owner login
    }
    if (!in_array($role, ['owner', 'manager', 'accountant', 'cashier', 'purchase', 'stocker'], true)) {
        fail('bad_role');
    }
    $shop = !empty($b['shop_id']) ? (int)$b['shop_id'] : null;
    if (($role === 'cashier' || $role === 'stocker') && $shop === null) {
        fail('cashier_needs_shop');
    }
    if ($shop !== null) {
        $st = db()->prepare('SELECT 1 FROM shops WHERE id = ? AND tenant_id = ?');
        $st->execute([$shop, $t['id']]);
        if (!$st->fetchColumn()) {
            fail('shop_not_found', 404);
        }
    }
    $email = v_email_opt($b['email'] ?? null);
    if ($email !== null) {
        $st = db()->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) {
            fail('duplicate', 409);
        }
    }
    $name = str($b['name'] ?? null, 120, true);
    $pass = (string)($b['password'] ?? '');
    if ($pass !== '' && strlen($pass) < 8) {
        fail('password_too_short');
    }
    if ($pass === '') {
        $pass = gen_pass();
    }
    $pdo = db();
    $pdo->prepare('INSERT INTO users (tenant_id, name, username, email, password_hash, role, shop_id) VALUES (?,?,NULL,?,?,?,?)')
        ->execute([$t['id'], $name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $shop]);
    $uid = (int)$pdo->lastInsertId();
    $username = gen_username($pdo, (int)$t['id'], $shop, $name);
    $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$username, $uid]);
    caudit($s, 'user_create', "tenant #{$t['id']} $username $role");
    json_out(['login' => $username, 'username' => $username, 'email' => $email, 'password' => $pass]);
}

/** reset = new password, disable / enable = block or allow sign-in. */
function c_user_action(): void
{
    $s = cauth();
    $b = body();
    $pdo = db();
    $st = $pdo->prepare('SELECT id, tenant_id, username, email, role FROM users WHERE id = ?');
    $st->execute([(int)($b['user_id'] ?? 0)]);
    $u = $st->fetch() ?: fail('user_not_found', 404);
    if ($u['role'] === 'owner') {
        cauth(true);   // owner passwords and owner access are an Adnys admin matter
    }
    $who = $u['username'] ?: $u['email'];
    $act = (string)($b['action'] ?? '');
    if ($act === 'reset') {
        $pass = gen_pass();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
        $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$u['id']]);
        caudit($s, 'user_reset', "tenant #{$u['tenant_id']} $who");
        json_out(['login' => $who, 'username' => $u['username'], 'email' => $u['email'], 'password' => $pass]);
    }
    if ($act === 'disable' || $act === 'enable') {
        $pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$act === 'enable' ? 1 : 0, $u['id']]);
        caudit($s, "user_$act", "tenant #{$u['tenant_id']} $who");
        json_out(['ok' => true]);
    }
    fail('bad_action');
}

function c_releases(): void
{
    cauth();
    $r = release_state();
    json_out(['releases' => $r['all'], 'latest' => $r['latest']['version'] ?? null, 'min_required' => $r['min_required']]);
}

/** Publish after uploading the new files. Open pages are told to reload; force_update locks out older pages. */
function c_release_publish(): void
{
    $s = cauth(true);
    $b = body();
    $v = (string)($b['version'] ?? '');
    if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $v)) {
        fail('bad_version');
    }
    db()->prepare('INSERT INTO app_releases (version, notes, force_update, published_by) VALUES (?,?,?,?)')
        ->execute([$v, str($b['notes'] ?? null, 4000), empty($b['force_update']) ? 0 : 1, $s['id']]);
    caudit($s, 'release_publish', $v . (empty($b['force_update']) ? '' : ' (forced)'));
    json_out(['ok' => true]);
}

function c_release_update(): void
{
    $s = cauth(true);
    $b = body();
    $set = [];
    $args = [];
    foreach (['force_update', 'retired'] as $f) {
        if (array_key_exists($f, $b)) {
            $set[] = "$f = ?";
            $args[] = $b[$f] ? 1 : 0;
        }
    }
    if (!$set) {
        fail('nothing_to_update');
    }
    $args[] = (int)($b['id'] ?? 0);
    db()->prepare('UPDATE app_releases SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    caudit($s, 'release_update', json_encode($b));
    json_out(['ok' => true]);
}

function c_staff_list(): void
{
    cauth(true);
    json_out(['staff' => db()->query('SELECT id, name, email, role, active, created_at FROM staff ORDER BY name')->fetchAll()]);
}

function c_staff_save(): void
{
    $s = cauth(true);
    $b = body();
    $role = ($b['role'] ?? '') === 'admin' ? 'admin' : 'support';
    $email = v_email($b['email'] ?? '');
    $pass = gen_pass();
    db()->prepare('INSERT INTO staff (name, email, password_hash, role) VALUES (?,?,?,?)')
        ->execute([str($b['name'] ?? null, 120, true), $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
    caudit($s, 'staff_create', "$email $role");
    json_out(['login' => $email, 'password' => $pass]);
}

function c_staff_action(): void
{
    $s = cauth(true);
    $b = body();
    $id = (int)($b['staff_id'] ?? 0);
    $act = (string)($b['action'] ?? '');
    $pdo = db();
    if ($id === $s['id'] && $act !== 'reset') {
        fail('cannot_change_self');
    }
    if ($act === 'reset') {
        $pass = gen_pass();
        $pdo->prepare('UPDATE staff SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        $pdo->prepare('DELETE FROM staff_tokens WHERE staff_id = ?')->execute([$id]);
        caudit($s, 'staff_reset', "staff #$id");
        json_out(['password' => $pass]);
    }
    if ($act === 'disable' || $act === 'enable') {
        $pdo->prepare('UPDATE staff SET active = ? WHERE id = ?')->execute([$act === 'enable' ? 1 : 0, $id]);
        caudit($s, "staff_$act", "staff #$id");
        json_out(['ok' => true]);
    }
    fail('bad_action');
}

function c_audit(): void
{
    cauth();
    json_out(['rows' => db()->query(
        'SELECT a.created_at, COALESCE(s.name, "-") AS staff, a.action, a.detail
           FROM console_audit a LEFT JOIN staff s ON s.id = a.staff_id ORDER BY a.id DESC LIMIT 150'
    )->fetchAll()]);
}

/* ======================= Upload next version (signed packages) ======================= */

function upd_root(): string
{
    return dirname(__DIR__);
}

function upd_state_dir(): string
{
    $d = upd_root() . '/lib/storage';
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
    if (!is_file($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    return $d;
}

function upd_installed_version(): string
{
    $j = @json_decode((string)@file_get_contents(upd_root() . '/version.json'), true);
    return is_array($j) ? (string)($j['version'] ?? '0.0.0') : '0.0.0';
}

/** "64M" -> bytes (ini_parse_quantity needs PHP 8.2, Hostinger may run 8.1). */
function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g':
            $n *= 1024;
            // no break
        case 'm':
            $n *= 1024;
            // no break
        case 'k':
            $n *= 1024;
    }
    return $n;
}

function upd_log(string $from, string $to, string $status, int $staffId, int $files, ?string $backup, array $migs, string $msg): int
{
    db()->prepare('INSERT INTO app_updates (from_version, to_version, status, staff_id, files_changed, backup_file, migrations, message) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$from, $to, $status, $staffId, $files, $backup, mb_substr(implode(', ', $migs), 0, 1000), mb_substr($msg, 0, 1000)]);
    return (int)db()->lastInsertId();
}

function c_update_status(): void
{
    cauth(true);
    $root = upd_root();
    $pub = function_exists('sodium_crypto_sign_verify_detached') ? upd_pubkey($root) : null;
    $hist = db()->query('SELECT u.id, u.from_version, u.to_version, u.status, u.files_changed, u.backup_file, u.migrations, u.message, u.created_at, s.name AS staff
                           FROM app_updates u LEFT JOIN staff s ON s.id = u.staff_id ORDER BY u.id DESC LIMIT 15')->fetchAll();
    $last = $hist && $hist[0]['status'] === 'applied' && $hist[0]['backup_file'] && is_file(upd_state_dir() . '/backups/' . basename((string)$hist[0]['backup_file'])) ? (int)$hist[0]['id'] : 0;
    json_out([
        'version' => upd_installed_version(),
        'key_set' => $pub !== null,
        'key_fingerprint' => $pub !== null ? upd_fingerprint($pub) : null,
        'has_sodium' => function_exists('sodium_crypto_sign_verify_detached'),
        'has_zip' => class_exists('ZipArchive'),
        'writable' => is_writable($root) && is_writable($root . '/lib') && is_writable($root . '/api'),
        'upload_limit' => min(ini_bytes((string)ini_get('upload_max_filesize')), ini_bytes((string)ini_get('post_max_size')) ?: PHP_INT_MAX),
        'history' => $hist,
        'rollback_id' => $last,
    ]);
}

/** One-time: store the public half of Adnys' release-signing key. Needs the database password as proof of server access. */
function c_update_key_set(): void
{
    $s = cauth(true);
    $b = body();
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        fail('no_sodium');
    }
    if (upd_pubkey(upd_root()) !== null || is_file(upd_root() . '/lib/update_pubkey.txt')) {
        fail('key_already_set', 409);
    }
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 10) {
        fail('too_many_attempts', 429);
    }
    if (!hash_equals((string)DB_PASS, (string)($b['db_password'] ?? ''))) {
        db()->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
        fail('wrong_db_password', 403);
    }
    $key = trim((string)($b['public_key'] ?? ''));
    $bin = base64_decode($key, true);
    if ($bin === false || strlen($bin) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        fail('bad_public_key');
    }
    if (@file_put_contents(upd_root() . '/lib/update_pubkey.txt', $key . "\n") === false) {
        fail('not_writable', 500);
    }
    @chmod(upd_root() . '/lib/update_pubkey.txt', 0640);
    caudit($s, 'update_key_set', 'fingerprint ' . upd_fingerprint($bin));
    json_out(['ok' => true, 'fingerprint' => upd_fingerprint($bin)]);
}

function c_update_upload(): void
{
    $s = cauth(true);
    $root = upd_root();
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        fail('no_sodium', 500);
    }
    if (!class_exists('ZipArchive')) {
        fail('no_zip', 500);
    }
    $pub = upd_pubkey($root);
    if ($pub === null) {
        fail('update_key_not_set', 409);
    }
    $state = upd_state_dir();
    $flag = $state . '/maintenance.flag';
    if (is_file($flag) && time() - (int)filemtime($flag) < 600) {
        fail('update_in_progress', 409);
    }
    $f = $_FILES['pkg'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $e = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        fail($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE ? 'file_too_big_for_server' : ($e === UPLOAD_ERR_NO_FILE ? 'no_file' : 'upload_failed'));
    }
    $incoming = $state . '/incoming-' . bin2hex(random_bytes(6)) . '.zip';
    if (!is_uploaded_file($f['tmp_name']) || !move_uploaded_file($f['tmp_name'], $incoming)) {
        fail('upload_failed');
    }

    $from = upd_installed_version();
    try {
        $m = upd_verify($incoming, $pub, $from);
    } catch (UpdateError $e) {
        @unlink($incoming);
        caudit($s, 'update_rejected', $e->getMessage());
        fail($e->getMessage(), 422);
    }

    $notes = trim((string)($_POST['notes'] ?? '')) ?: (string)($m['notes'] ?? '');
    $force = empty($_POST['force']) ? 0 : 1;
    $info = null;
    $migs = [];
    touch($flag);
    try {
        $info = upd_apply($root, $incoming, $m, $from, $state . '/backups');
        $migs = migrate(db(), $root . '/sql/migrations');
        if (upd_installed_version() !== $m['version']) {
            throw new UpdateError('version_file_mismatch');
        }
        db()->prepare('INSERT INTO app_releases (version, notes, force_update, published_by) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE notes = VALUES(notes), force_update = VALUES(force_update), retired = 0')
            ->execute([$m['version'], mb_substr($notes, 0, 4000), $force, $s['id']]);
    } catch (Throwable $e) {
        if ($info !== null) {
            try {
                upd_restore($root, $state . '/backups/' . $info['backup']);
            } catch (Throwable $ignore) {
                // backup zip stays on disk for manual recovery
            }
        }
        $msg = str_replace($root, '', $e->getMessage());
        try {
            upd_log($from, $m['version'], 'failed', $s['id'], 0, $info['backup'] ?? null, $migs, $msg);
            caudit($s, 'update_failed', $m['version'] . ': ' . $msg);
        } catch (Throwable $ignore) {
            // logging must not hide the real error
        }
        @unlink($flag);
        @unlink($incoming);
        error_log('update failed: ' . $e->getMessage());
        fail($e instanceof UpdateError ? $e->getMessage() : 'update_failed', 500);
    }
    @unlink($flag);
    @unlink($incoming);
    upd_prune_backups($state . '/backups', 5);
    @unlink($state . '/migrated.sig');   // forces a fresh check on the next request
    upd_log($from, $m['version'], 'applied', $s['id'], $info['changed'], $info['backup'], $migs, $notes);
    caudit($s, 'update_applied', $from . ' -> ' . $m['version'] . ($force ? ' (forced)' : ''));
    json_out(['ok' => true, 'from' => $from, 'version' => $m['version'], 'files' => $info['changed'], 'migrations' => $migs]);
}

/** Undo the most recent update (files only). Database migrations are additive and stay in place. */
function c_update_rollback(): void
{
    $s = cauth(true);
    $b = body();
    $root = upd_root();
    $row = db()->query("SELECT * FROM app_updates WHERE status = 'applied' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$row || (int)$row['id'] !== (int)($b['id'] ?? 0)) {
        fail('only_latest_can_be_rolled_back', 409);
    }
    $state = upd_state_dir();
    $backup = $state . '/backups/' . basename((string)$row['backup_file']);
    if (!$row['backup_file'] || !is_file($backup)) {
        fail('backup_missing', 409);
    }
    $flag = $state . '/maintenance.flag';
    touch($flag);
    try {
        upd_restore($root, $backup);
    } finally {
        @unlink($flag);
    }
    db()->prepare("UPDATE app_updates SET status = 'rolled_back' WHERE id = ?")->execute([$row['id']]);
    db()->prepare('UPDATE app_releases SET retired = 1 WHERE version = ?')->execute([$row['to_version']]);
    caudit($s, 'update_rolled_back', $row['to_version'] . ' -> ' . $row['from_version']);
    json_out(['ok' => true, 'version' => upd_installed_version()]);
}
