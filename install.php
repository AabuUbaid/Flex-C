<?php
declare(strict_types=1);
/**
 * Flex-C (powered by Adnys): web installer.
 * Open https://your-domain/install.php, fill the form, press Install.
 * It creates the tables, your company, shop and owner login, writes lib/config.php,
 * locks itself and then deletes this file.
 * Run it right after uploading: until it finishes, anyone who finds the page could use it.
 */
session_start();
date_default_timezone_set('Asia/Dubai');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

const LOCK = __DIR__ . '/lib/installed.lock';
const CONFIG = __DIR__ . '/lib/config.php';

function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' | Flex-C</title><style>
        *{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#0f172a;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
        .w{max-width:640px;margin:0 auto;padding:20px}.c{background:#fff;border-radius:14px;padding:22px;margin-bottom:14px;box-shadow:0 1px 3px #0002}
        h1{margin:0}h2{margin:0 0 6px;font-size:17px}.by{color:#64748b;font-size:13px;margin-bottom:14px}
        label{display:block;font-size:13px;color:#475569;margin:12px 0 4px}input,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
        .g{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}@media(max-width:560px){.g{grid-template-columns:1fr}}
        button,a.b{display:inline-block;background:#0f766e;color:#fff;border:0;border-radius:8px;padding:12px 18px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
        a.s{background:#e2e8f0;color:#0f172a}.err{background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:8px;margin-bottom:12px}
        .ok{background:#dcfce7;color:#166534;padding:10px 12px;border-radius:8px;margin-bottom:12px}.warn{background:#fef3c7;color:#92400e;padding:10px 12px;border-radius:8px;margin-bottom:12px}
        small{color:#64748b}textarea{width:100%;height:220px;font:12px monospace}
        </style></head><body><div class="w"><div class="c"><h1>Flex-C</h1><div class="by">Your trade, a smart move · installer</div>' . $body . '<div style="text-align:center;color:#64748b;font-size:12px">Powered by <a href="https://adnys.com" style="color:#0f766e">Adnys</a> · adnys.com</div></div></div></body></html>';
    exit;
}

/* ---------- already installed? ---------- */
if (file_exists(LOCK)) {
    $gone = @unlink(__FILE__);
    page('Installed', '<div class="ok">Flex-C is already installed.</div><p>'
        . ($gone ? 'This installer file has now been removed.' : '<b>Delete install.php from the server (File Manager).</b>')
        . '</p><p><a class="b" href="dashboard/">Dashboard</a> <a class="b s" href="pos/">POS</a> <a class="b s" href="office/">Back office</a></p>');
}

/* ---------- requirements ---------- */
$problems = [];
if (PHP_VERSION_ID < 80100) {
    $problems[] = 'PHP 8.1 or newer is required (this server runs ' . PHP_VERSION . '). Change it in hPanel > PHP Configuration.';
}
foreach (['pdo_mysql', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        $problems[] = "PHP extension $ext is missing. Enable it in hPanel > PHP Configuration > Extensions.";
    }
}
if (!is_file(__DIR__ . '/sql/schema.sql')) {
    $problems[] = 'sql/schema.sql is missing. Upload the complete folder, including sql/.';
}
foreach (['lib/brand.php', 'lib/migrate.php', 'sql/migrations'] as $need) {
    if (!file_exists(__DIR__ . '/' . $need)) {
        $problems[] = "$need is missing. Upload the complete folder, including lib/ and sql/.";
    }
}
if ($problems) {
    page('Problem', '<div class="err">' . implode('<br>', array_map('h', $problems)) . '</div>');
}
require_once __DIR__ . '/lib/brand.php';
require_once __DIR__ . '/lib/migrate.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$v = [
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '',
    'company' => '', 'trn' => '', 'address' => '', 'phone' => '',
    'shop' => 'Main Shop', 'trade' => 'other',
    'owner_name' => '', 'owner_email' => '', 'staff_email' => '', 'samples' => '1',
];
$error = '';

/* ---------- install ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $pass1 = (string)($_POST['owner_pass'] ?? '');
    $pass2 = (string)($_POST['owner_pass2'] ?? '');
    $staffPass = (string)($_POST['staff_pass'] ?? '');
    $v['samples'] = isset($_POST['samples']) ? '1' : '';

    $pdo = null;
    $created = false;
    $before = [];
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('The page expired. Reload it and try again.');
        }
        if (!preg_match('/^[A-Za-z0-9.\-:_]{1,100}$/', $v['db_host'])) {
            throw new RuntimeException('Database host looks wrong. On Hostinger it is usually "localhost".');
        }
        if (!preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $v['db_name']) || $v['db_user'] === '') {
            throw new RuntimeException('Enter the database name and user exactly as shown in hPanel > Databases.');
        }
        if ($v['company'] === '' || mb_strlen($v['company']) > 150) {
            throw new RuntimeException('Enter your company name.');
        }
        if ($v['trn'] !== '' && !preg_match('/^\d{15}$/', $v['trn'])) {
            throw new RuntimeException('A UAE TRN has 15 digits. Leave it empty if you do not have it yet.');
        }
        if ($v['owner_name'] === '' || !filter_var($v['owner_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter the owner name and a valid email address.');
        }
        if (strlen($pass1) < 10) {
            throw new RuntimeException('The owner password needs at least 10 characters.');
        }
        if ($pass1 !== $pass2) {
            throw new RuntimeException('The two passwords do not match.');
        }
        if ($v['staff_email'] !== '' && (!filter_var($v['staff_email'], FILTER_VALIDATE_EMAIL) || strlen($staffPass) < 10)) {
            throw new RuntimeException('Console admin: enter a valid email and a password of at least 10 characters, or leave both empty.');
        }
        if (!in_array($v['trade'], ['clothes', 'mobile', 'grocery', 'stationery', 'other'], true)) {
            $v['trade'] = 'other';
        }

        try {
            $pdo = new PDO("mysql:host={$v['db_host']};dbname={$v['db_name']};charset=utf8mb4", $v['db_user'], $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Could not connect to the database. Check host, name, user and password. (' . $e->getMessage() . ')');
        }
        $pdo->exec("SET time_zone = '+04:00'");
        if ($pdo->query("SHOW TABLES LIKE 'tenants'")->fetchColumn()) {
            throw new RuntimeException('This database already contains Flex-C tables. Use an empty database, or delete the old tables first.');
        }

        // 1) tables (DDL cannot be rolled back in MySQL, so on failure we drop exactly the tables we added)
        $before = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $created = true;
        foreach (sql_statements((string)file_get_contents(__DIR__ . '/sql/schema.sql')) as $stmt) {
            $pdo->exec($stmt);
        }
        migrate($pdo, __DIR__ . '/sql/migrations');   // console tables, tenant columns, update history

        // 2) company, shop, register, owner
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO tenants (name) VALUES (?)')->execute([$v['company']]);
        $tid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO companies (tenant_id, name, trn, address, phone, footer) VALUES (?,?,?,?,?,?)')
            ->execute([$tid, $v['company'], $v['trn'] ?: null, $v['address'] ?: null, $v['phone'] ?: null, 'Thank you / شكراً لزيارتكم']);
        $cid = (int)$pdo->lastInsertId();
        $shopName = $v['shop'] ?: 'Main Shop';
        $grp = substr(str_pad(substr((string)preg_replace('/[^A-Z0-9]/', '', strtoupper($shopName)), 0, 3), 3, 'X'), 0, 3);
        $shopCode = $grp . '001';   // shop name letters + 001; further branches become 002, 003 ...
        $pdo->prepare('INSERT INTO shops (tenant_id, company_id, code, group_code, name, trade) VALUES (?,?,?,?,?,?)')
            ->execute([$tid, $cid, $shopCode, $grp, $shopName, $v['trade']]);
        $sid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO registers (tenant_id, shop_id, code) VALUES (?,?,?)')->execute([$tid, $sid, 'R1']);
        $nm3 = substr(str_pad(substr((string)preg_replace('/[^A-Z0-9]/', '', strtoupper($v['owner_name'])), 0, 3), 3, 'X'), 0, 3);
        $ownerUser = $shopCode . $nm3 . '000001';   // shop code + first 3 letters of the name + number
        $pdo->prepare('INSERT INTO users (tenant_id, name, username, email, password_hash, role, shop_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$tid, $v['owner_name'], $ownerUser, strtolower($v['owner_email']), password_hash($pass1, PASSWORD_DEFAULT), 'owner', $sid]);

        if ($v['staff_email'] !== '') {
            $pdo->prepare('INSERT INTO staff (name, email, password_hash, role) VALUES (?,?,?,?)')
                ->execute(['Adnys Admin', strtolower($v['staff_email']), password_hash($staffPass, PASSWORD_DEFAULT), 'admin']);
            $pdo->prepare('INSERT INTO app_releases (version, notes) VALUES (?,?)')->execute([app_version(), 'Installed']);
        }

        if ($v['samples'] === '1') {
            $mkItem = $pdo->prepare('INSERT INTO items (tenant_id, company_id, sku, name, name_ar, category, uom, price, cost, vat_rate, reorder_level) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $mkBc = $pdo->prepare('INSERT INTO item_barcodes (tenant_id, company_id, item_id, barcode) VALUES (?,?,?,?)');
            $mkStock = $pdo->prepare("INSERT INTO stock_ledger (tenant_id, shop_id, item_id, qty, unit_cost, ref_type, note) VALUES (?,?,?,?,?,'opening','sample opening stock')");
            foreach ([['SAMPLE-1', 'Sample item 1', 'صنف تجريبي ١', 10.00, 6.00, '6291000000011'],
                      ['SAMPLE-2', 'Sample item 2', 'صنف تجريبي ٢', 25.00, 15.00, '6291000000028'],
                      ['SAMPLE-3', 'Sample item 3', 'صنف تجريبي ٣', 99.00, 60.00, '6291000000035']] as [$sku, $name, $ar, $price, $cost, $bc]) {
                $mkItem->execute([$tid, $cid, $sku, $name, $ar, 'Sample', 'pc', $price, $cost, 5, 5]);
                $iid = (int)$pdo->lastInsertId();
                $mkBc->execute([$tid, $cid, $iid, $bc]);
                $mkStock->execute([$tid, $sid, $iid, 20, $cost]);
            }
        }
        $pdo->commit();

        // 3) config file
        $cfg = "<?php\n// Written by install.php on " . date('c') . "\n"
            . "define('DB_HOST', getenv('RP_DB_HOST') ?: " . var_export($v['db_host'], true) . ");\n"
            . "define('DB_NAME', getenv('RP_DB_NAME') ?: " . var_export($v['db_name'], true) . ");\n"
            . "define('DB_USER', getenv('RP_DB_USER') ?: " . var_export($v['db_user'], true) . ");\n"
            . "define('DB_PASS', getenv('RP_DB_PASS') ?: " . var_export($dbPass, true) . ");\n";
        $wrote = @file_put_contents(CONFIG, $cfg) !== false;
        @file_put_contents(LOCK, date('c'));
        @chmod(CONFIG, 0640);
        unset($_SESSION['csrf']);
        $gone = @unlink(__FILE__);

        $body = '<div class="ok"><b>Flex-C is installed.</b></div>';
        if (!$wrote) {
            $body .= '<div class="warn">The tables and your owner login are ready, but this server would not let the installer write <code>lib/config.php</code>. '
                . 'Open <code>lib/config.php</code> in File Manager, replace its whole content with the text below, and save.</div>'
                . '<textarea readonly onclick="this.select()">' . h($cfg) . '</textarea>';
        }
        $body .= '<p>Sign in with username <b>' . h($ownerUser ?? '') . '</b> (or the email <b>' . h(strtolower($v['owner_email'])) . '</b>) and the password you just chose.</p>'
            . '<p><a class="b" href="dashboard/">Owner dashboard</a> <a class="b s" href="office/">Back office</a> <a class="b s" href="pos/">POS counter</a>'
            . ($v['staff_email'] !== '' ? ' <a class="b s" href="console/">Adnys Console</a>' : '') . '</p>'
            . '<p><small>Start with Back office > Items to add your products and opening stock, then open the POS.</small></p>'
            . ($gone ? '<p><small>The installer file has been deleted automatically.</small></p>'
                     : '<div class="warn"><b>Delete install.php</b> from public_html now (File Manager). It could not delete itself.</div>');
        page('Installed', $body);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($created) {   // leave the database as it was so you can simply try again
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    foreach (array_diff($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), $before) as $t) {
                        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $t) . '`');
                    }
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                }
            } catch (Throwable $ignore) {
                // best effort
            }
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Install failed: ' . $e->getMessage();
    }
}

/* ---------- form ---------- */
$opt = fn(string $val, string $label) => '<option value="' . $val . '"' . ($v['trade'] === $val ? ' selected' : '') . '>' . $label . '</option>';
page('Install', ($error ? '<div class="err">' . h($error) . '</div>' : '')
    . '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'
    . '<h2>1. Database</h2><small>hPanel > Databases > MySQL Databases. Create an empty database and a user first.</small>'
    . '<div class="g"><div><label>Host</label><input name="db_host" value="' . h($v['db_host']) . '" required></div>'
    . '<div><label>Database name</label><input name="db_name" value="' . h($v['db_name']) . '" required></div>'
    . '<div><label>Database user</label><input name="db_user" value="' . h($v['db_user']) . '" required></div>'
    . '<div><label>Database password</label><input name="db_pass" type="password"></div></div>'
    . '<h2 style="margin-top:22px">2. Your business</h2>'
    . '<div class="g"><div><label>Company name *</label><input name="company" value="' . h($v['company']) . '" required></div>'
    . '<div><label>TRN (15 digits)</label><input name="trn" value="' . h($v['trn']) . '" inputmode="numeric" maxlength="15"></div>'
    . '<div><label>Address</label><input name="address" value="' . h($v['address']) . '"></div>'
    . '<div><label>Phone</label><input name="phone" value="' . h($v['phone']) . '"></div>'
    . '<div><label>First shop name</label><input name="shop" value="' . h($v['shop']) . '"></div>'
    . '<div><label>Shop type</label><select name="trade">' . $opt('clothes', 'Clothes') . $opt('mobile', 'Mobile &amp; accessories') . $opt('grocery', 'Grocery') . $opt('stationery', 'Stationery') . $opt('other', 'Other') . '</select></div></div>'
    . '<h2 style="margin-top:22px">3. Owner login</h2>'
    . '<div class="g"><div><label>Name *</label><input name="owner_name" value="' . h($v['owner_name']) . '" required></div>'
    . '<div><label>Email *</label><input name="owner_email" type="email" value="' . h($v['owner_email']) . '" required></div>'
    . '<div><label>Password (10+ characters) *</label><input name="owner_pass" type="password" minlength="10" required></div>'
    . '<div><label>Repeat password *</label><input name="owner_pass2" type="password" minlength="10" required></div></div>'
    . '<h2 style="margin-top:22px">4. Adnys Console admin <small>(optional)</small></h2><small>Staff login for /console/: customers, versions and uploads. Leave empty to skip.</small>'
    . '<div class="g"><div><label>Console admin email</label><input name="staff_email" type="email" value="' . h($v['staff_email']) . '"></div>'
    . '<div><label>Console password (10+ characters)</label><input name="staff_pass" type="password" minlength="10"></div></div>'
    . '<label style="display:flex;gap:8px;align-items:center;margin-top:16px"><input type="checkbox" name="samples" value="1" style="width:auto"' . ($v['samples'] === '1' ? ' checked' : '') . '> Add 3 sample items so I can test a sale</label>'
    . '<p style="margin-top:18px"><button type="submit">Install Flex-C</button></p></form>');
