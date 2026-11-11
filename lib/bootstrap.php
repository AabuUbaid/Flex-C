<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

final class ApiError extends Exception
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

function fail(string $message, int $status = 400): never
{
    throw new ApiError($message, $status);
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 2_000_000) {
            fail('payload_too_large', 413);
        }
        $b = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($b)) {
            fail('bad_json');
        }
    }
    return $b;
}

/**
 * Authenticate via the X-Auth-Token header.
 * $roles: allowed roles (empty = any). $unrestricted: reject users locked to one shop.
 */
function auth(array $roles = [], bool $unrestricted = false): array
{
    $t = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if ($t === '') {
        fail('unauthorized', 401);
    }
    $st = db()->prepare(
        'SELECT u.id, u.tenant_id, u.name, u.role, u.shop_id
           FROM api_tokens k JOIN users u ON u.id = k.user_id JOIN tenants t ON t.id = u.tenant_id
          WHERE k.token_hash = ? AND k.expires_at > NOW() AND u.active = 1 AND t.active = 1'
    );
    $st->execute([hash('sha256', $t)]);
    $u = $st->fetch();
    if (!$u) {
        fail('unauthorized', 401);
    }
    $u['id'] = (int)$u['id'];
    $u['tenant_id'] = (int)$u['tenant_id'];
    $u['shop_id'] = $u['shop_id'] === null ? null : (int)$u['shop_id'];
    if ($u['role'] === 'owner') {
        $u['shop_id'] = null;   // an owner (or sub-owner) always works across all shops; the shop on the record only shapes the username
    }
    if ($roles && !in_array($u['role'], $roles, true)) {
        fail('forbidden', 403);
    }
    if ($unrestricted && $u['shop_id'] !== null) {
        fail('forbidden', 403);
    }
    return $u;
}

function assert_shop(array $u, int $shopId): array
{
    $st = db()->prepare('SELECT * FROM shops WHERE id = ? AND tenant_id = ?');
    $st->execute([$shopId, $u['tenant_id']]);
    $s = $st->fetch();
    if (!$s) {
        fail('shop_not_found', 404);
    }
    if ($u['shop_id'] !== null && $u['shop_id'] !== (int)$s['id']) {
        fail('forbidden', 403);
    }
    return $s;
}

function assert_company(array $u, int $companyId): array
{
    $st = db()->prepare('SELECT * FROM companies WHERE id = ? AND tenant_id = ?');
    $st->execute([$companyId, $u['tenant_id']]);
    $c = $st->fetch();
    if (!$c) {
        fail('company_not_found', 404);
    }
    return $c;
}

function audit(array $u, string $action, string $detail = ''): void
{
    db()->prepare('INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?,?,?,?,?)')
        ->execute([$u['tenant_id'], $u['id'], $action, mb_substr($detail, 0, 2000), substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)]);
}

function vdate(mixed $s, ?string $default = null): string
{
    $s = (string)$s;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $s;
    }
    if ($default !== null) {
        return $default;
    }
    fail('bad_date');
}

function num(mixed $v, float $min = -1e12, float $max = 1e12): float
{
    if (!is_numeric($v)) {
        fail('bad_number');
    }
    $f = (float)$v;
    if ($f < $min || $f > $max) {
        fail('number_out_of_range');
    }
    return $f;
}

function str(mixed $v, int $max, bool $required = false): ?string
{
    $s = trim((string)($v ?? ''));
    if ($s === '') {
        if ($required) {
            fail('missing_field');
        }
        return null;
    }
    if (mb_strlen($s) > $max) {
        fail('text_too_long');
    }
    return $s;
}

function placeholders(int $n): string
{
    return implode(',', array_fill(0, $n, '?'));
}

/* ---------- shared helpers (1.2.0) ---------- */

/** Letters/digits only, upper case. Used for shop codes and usernames. */
function alnum(string $s): string
{
    return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $s));
}

/**
 * Shop code = shop name + 001, 002 ... so branches of the same shop are recognisable.
 * Code is limited to 10 characters by the schema.
 */
function shop_code_from_name(PDO $pdo, int $tenantId, string $name): string
{
    $base = substr(alnum($name), 0, 7);
    if ($base === '') {
        $base = 'SHOP';
    }
    for ($i = 1; $i <= 999; $i++) {
        $code = $base . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        $st = $pdo->prepare('SELECT 1 FROM shops WHERE tenant_id = ? AND code = ?');
        $st->execute([$tenantId, $code]);
        if (!$st->fetchColumn()) {
            return $code;
        }
    }
    return 'SHOP' . random_int(100, 999);
}

/**
 * Username = shop code + first 3 letters of the person's name + a 6-digit number.
 * Example: SHOP001AHM000014. Login accepts this or the email address.
 */
function gen_username(PDO $pdo, int $tenantId, ?int $shopId, string $name): string
{
    $shopCode = 'SHOP';
    if ($shopId !== null) {
        $st = $pdo->prepare('SELECT code FROM shops WHERE id = ? AND tenant_id = ?');
        $st->execute([$shopId, $tenantId]);
        $shopCode = (string)($st->fetchColumn() ?: 'SHOP');
    }
    $base = substr(alnum($shopCode), 0, 8);
    if ($base === '') {
        $base = 'SHOP';
    }
    $nm = substr(alnum($name), 0, 3);
    if ($nm === '') {
        $nm = 'USR';
    }
    $st = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM users WHERE tenant_id = ?');
    $st->execute([$tenantId]);
    $i = (int)$st->fetchColumn() + 1;
    for ($try = 0; $try < 100000; $try++, $i++) {
        $un = $base . $nm . str_pad((string)$i, 6, '0', STR_PAD_LEFT);
        // Checked across ALL customers: sign-in looks a username up without knowing the customer.
        $st = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $st->execute([$un]);
        if (!$st->fetchColumn()) {
            return $un;
        }
    }
    return $base . $nm . bin2hex(random_bytes(3));
}

/** Tenant preferences with safe defaults (works even before migration 003 ran). */
function settings_for(int $tenantId): array
{
    $d = [
        'low_stock_threshold' => 10.0,
        'share_email' => null,
        'share_whatsapp' => null,
        'currency' => 'AED',
        'receipt_note' => null,
        'thank_you' => null,
        'qr_url' => null,
        'watermark_opacity' => 15,
        'qr_enabled' => 1,
    ];
    try {
        $st = db()->prepare('SELECT * FROM tenant_settings WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        $r = $st->fetch();
        if ($r) {
            $d['low_stock_threshold'] = (float)$r['low_stock_threshold'];
            $d['share_email'] = $r['share_email'];
            $d['share_whatsapp'] = $r['share_whatsapp'];
            $d['currency'] = $r['currency'] ?: 'AED';
            $d['receipt_note'] = $r['receipt_note'];
            $d['thank_you'] = $r['thank_you'] ?? null;
            $d['qr_url'] = $r['qr_url'] ?? null;
            $d['qr_enabled'] = isset($r['qr_enabled']) ? (int)$r['qr_enabled'] : 1;
            $d['watermark_opacity'] = isset($r['watermark_opacity']) ? (int)$r['watermark_opacity'] : 15;
        }
    } catch (Throwable $e) {
        // old database before the migration: keep defaults
    }
    return $d;
}

/** A logo is a small data: URL image. Returns null when the value is not acceptable. */
function clean_logo(mixed $v): ?string
{
    $s = trim((string)($v ?? ''));
    if ($s === '') {
        return null;
    }
    if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\r\n]+$#i', $s)) {
        fail('bad_logo');
    }
    if (strlen($s) > 400_000) {
        fail('logo_too_big');
    }
    return $s;
}

/**
 * Create or refresh a low-stock alert. One open alert per shop + item, so a busy day
 * does not flood the owner / purchase team with duplicates.
 */
function notify_low_stock(PDO $pdo, int $tenantId, int $companyId, int $shopId, int $itemId, float $qty, float $threshold, string $itemName, string $shopName): void
{
    // An item's own reorder level wins over the tenant default (10 unless changed in Settings).
    $st = $pdo->prepare('SELECT reorder_level FROM items WHERE id = ? AND tenant_id = ?');
    $st->execute([$itemId, $tenantId]);
    $reorder = (float)$st->fetchColumn();
    if ($reorder > 0) {
        $threshold = $reorder;
    }
    if ($qty > $threshold) {
        $pdo->prepare(
            "UPDATE notifications SET status = 'resolved'
              WHERE tenant_id = ? AND shop_id = ? AND item_id = ? AND type = 'low_stock' AND status IN ('new','read')"
        )->execute([$tenantId, $shopId, $itemId]);
        return;
    }
    $level = $qty <= 0 ? 'bad' : 'warn';
    $title = ($qty <= 0 ? 'Out of stock: ' : 'Low stock: ') . $itemName;
    $qtyTxt = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    $thTxt = rtrim(rtrim(number_format($threshold, 3, '.', ''), '0'), '.');
    $detail = $shopName . ' · ' . $qtyTxt . ' left (alert at ' . $thTxt . ')';
    $st = $pdo->prepare(
        "SELECT id FROM notifications
          WHERE tenant_id = ? AND shop_id = ? AND item_id = ? AND type = 'low_stock' AND status IN ('new','read')
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$tenantId, $shopId, $itemId]);
    $id = (int)$st->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE notifications SET qty = ?, level = ?, title = ?, detail = ?, status = 'new', created_at = NOW() WHERE id = ?")
            ->execute([$qty, $level, $title, $detail, $id]);
        return;
    }
    $pdo->prepare(
        "INSERT INTO notifications (tenant_id, company_id, shop_id, item_id, type, level, title, detail, qty)
         VALUES (?,?,?,?, 'low_stock', ?, ?, ?, ?)"
    )->execute([$tenantId, $companyId, $shopId, $itemId, $level, $title, $detail, $qty]);
}

/** Mobile number as digits with country code (UAE default). Returns null when it does not look like a phone number. */
function clean_phone(mixed $v): ?string
{
    $d = (string)preg_replace('/\D+/', '', (string)($v ?? ''));
    if ($d === '') {
        return null;
    }
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    } elseif (str_starts_with($d, '05') && strlen($d) === 10) {
        $d = '971' . substr($d, 1);
    } elseif (str_starts_with($d, '5') && strlen($d) === 9) {
        $d = '971' . $d;
    }
    return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null;
}
