<?php
/**
 * Auth, roles, branch scope, CSRF, audit log, settings, backups.
 * Everything that decides "who may see/do what" lives here.
 *
 * Roles:   admin   – whole network, all branches, all settings
 *          manager – one branch: operations + reports for that branch
 *          staff   – cashier of one branch: operations for that branch only
 */
require_once __DIR__ . '/db.php';
date_default_timezone_set('Asia/Tashkent');

const ROLE_LABELS = ['admin' => 'Administrator', 'manager' => 'Menejer', 'staff' => 'Kassir'];
/** Validated categorical palette (slot order). Branch colour = index stored in branches.color */
const BRANCH_PALETTE = [
    ['#2a78d6', "Ko'k"], ['#eb6834', "To'q sariq"], ['#1baf7a', 'Zumrad'], ['#eda100', 'Sariq'],
    ['#e87ba4', 'Pushti'], ['#008300', 'Yashil'], ['#6250d6', 'Binafsha'], ['#e34948', 'Qizil'],
];
const SQL_METHOD = "CASE WHEN t.bank=1 THEN 'bank' WHEN t.click=1 THEN 'click' ELSE 'cash' END";

function db(): PDO { global $conn; return $conn; }

/* ------------------------------------------------------------------ session */
function app_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (is_dir($dir) && is_writable($dir)) {
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        $sp = $dir . '/sessions';
        if (!is_dir($sp)) @mkdir($sp, 0750, true);
        if (is_dir($sp) && is_writable($sp)) session_save_path($sp);
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    ini_set('session.gc_maxlifetime', '604800');
    ini_set('session.use_strict_mode', '1');
    session_name('ARGENTSID');
    session_set_cookie_params(['lifetime' => 604800, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
app_session_start();

/* ----------------------------------------------------------------- settings */
function settings_all(bool $reload = false): array {
    static $s = null;
    if ($s === null || $reload) {
        $s = [];
        foreach (db()->query("SELECT key, value FROM settings")->fetchAll() as $r) $s[$r['key']] = $r['value'];
    }
    return $s;
}
/** Branch override ("b<ID>.key") wins over the global value. */
function setting(string $key, $default = null, ?int $branch = null) {
    $s = settings_all();
    if ($branch && array_key_exists("b$branch.$key", $s)) return $s["b$branch.$key"];
    return array_key_exists($key, $s) ? $s[$key] : $default;
}
function setting_set(string $key, string $value, ?int $branch = null): void {
    $k = $branch ? "b$branch.$key" : $key;
    db()->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$k, $value]);
    settings_all(true);
}
function setting_clear(string $key, int $branch): void {
    db()->prepare("DELETE FROM settings WHERE key = ?")->execute(["b$branch.$key"]);
    settings_all(true);
}

/* ----------------------------------------------------------------- flash */
function flash_set(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function flash_get(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

/* ----------------------------------------------------------------- branches */
function branches_all(bool $reload = false): array {
    static $list = null;
    if ($list === null || $reload) {
        $list = [];
        foreach (db()->query("SELECT * FROM branches ORDER BY is_active DESC, sort_order, id")->fetchAll() as $b) $list[(int)$b['id']] = $b;
    }
    return $list;
}
function branches_active(): array { return array_filter(branches_all(), function ($b) { return (int)$b['is_active'] === 1; }); }
function branch_get(int $id): ?array { $all = branches_all(); return $all[$id] ?? null; }
function branch_color(?array $b): string { $i = $b ? (int)$b['color'] : 0; return BRANCH_PALETTE[$i % count(BRANCH_PALETTE)][0]; }
function branch_initials(string $name): string {
    $words = preg_split('/[\s\-]+/u', trim($name));
    $a = mb_substr($words[0] ?? '', 0, 1); $b = isset($words[1]) ? mb_substr($words[1], 0, 1) : mb_substr($words[0] ?? '', 1, 1);
    return strtoupper($a . $b) ?: '?';
}
function next_branch_color(): int {
    $used = array_map(function ($b) { return (int)$b['color']; }, branches_all());
    for ($i = 0; $i < count(BRANCH_PALETTE); $i++) if (!in_array($i, $used, true)) return $i;
    return count($used) % count(BRANCH_PALETTE);
}

/* --------------------------------------------------------------- passwords */
function pw_hash(string $p): string { return password_hash($p, PASSWORD_DEFAULT); }
function pw_is_hash(string $stored): bool { return strlen($stored) >= 50 && ($stored[0] === '$'); }
/** Accepts legacy plain-text passwords (old DB) and upgrades them to a hash on first successful login. */
function pw_verify(string $plain, string $stored): bool {
    if (pw_is_hash($stored)) return password_verify($plain, $stored);
    return hash_equals($stored, $plain);
}
function gen_password(int $len = 10): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = ''; for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

/* -------------------------------------------------------------- current user */
function auth_user(): ?array {
    static $user = null, $loaded = false;
    if ($loaded) return $user;
    $loaded = true;
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare("SELECT u.*, b.name AS branch_name, b.color AS branch_color, b.is_active AS branch_active
                         FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.id = ?");
    $st->execute([(int)$_SESSION['user_id']]);
    $u = $st->fetch();
    $bad = null;
    if (!$u) $bad = 'Hisob topilmadi';
    elseif ((int)$u['is_active'] !== 1) $bad = "Akkaunt faol emas. Administrator bilan bog'laning.";
    elseif ($u['role'] !== 'admin' && (empty($u['branch_id']) || (int)$u['branch_active'] !== 1)) $bad = "Filial faol emas yoki biriktirilmagan.";
    if ($bad) { unset($_SESSION['user_id'], $_SESSION['scope_branch']); $_SESSION['login_error'] = $bad; return null; }
    return $user = $u;
}
function is_admin(): bool { $u = auth_user(); return $u && $u['role'] === 'admin'; }
function is_manager(): bool { $u = auth_user(); return $u && $u['role'] === 'manager'; }
function can_view_reports(): bool { return is_admin() || is_manager(); }
function role_label(?string $r): string { return ROLE_LABELS[$r] ?? (string)$r; }

function require_login(): void { if (!auth_user()) { header('Location: index.php'); exit; } }
/** Admins and branch managers only (cashiers are sent back to the daily page). */
function require_manager(): void { require_login(); if (!is_admin() && !is_manager()) { flash_set('error', "Bu bo'lim kassirlar uchun yopiq."); header('Location: index.php'); exit; } }
function require_admin(): void { require_login(); if (!is_admin()) { flash_set('error', "Bu bo'lim faqat administrator uchun."); header('Location: index.php'); exit; } }
function require_reports(): void { require_login(); if (!can_view_reports()) { flash_set('error', "Hisobotlar kassirlar uchun yopiq."); header('Location: index.php'); exit; } }

/** Branch the user is working in. 0 = "all branches" (admin only). */
function scope_branch(): int {
    $u = auth_user();
    if (!$u) return 0;
    if ($u['role'] !== 'admin') return (int)$u['branch_id'];
    $b = (int)($_SESSION['scope_branch'] ?? 0);
    return ($b && branch_get($b)) ? $b : 0;
}
function scope_branch_row(): ?array { $b = scope_branch(); return $b ? branch_get($b) : null; }
/** Is the user allowed to touch data of this branch? */
function can_access_branch(int $bid): bool {
    $u = auth_user();
    if (!$u) return false;
    return $u['role'] === 'admin' ? (bool)branch_get($bid) : ((int)$u['branch_id'] === $bid);
}
/** Branch ids visible to the current user. */
function visible_branch_ids(): array {
    if (is_admin()) return array_keys(branches_all());
    $u = auth_user(); return $u ? [(int)$u['branch_id']] : [];
}

/* ---------------------------------------------------------- permissions */
function can_edit_tx(int $bid): bool {
    $u = auth_user(); if (!$u || !can_access_branch($bid)) return false;
    if ($u['role'] !== 'staff') return true;
    return setting('staff_can_edit', '1', $bid) !== '0';
}
function can_delete_tx(int $bid): bool {
    $u = auth_user(); if (!$u || !can_access_branch($bid)) return false;
    if ($u['role'] !== 'staff') return true;
    return setting('staff_can_delete', '0', $bid) === '1';
}
/** Load a transaction only if the current user may access its branch. */
function tx_authorized(int $id): ?array {
    $st = db()->prepare("SELECT t.*, c.name AS category_name FROM transactions t LEFT JOIN categories c ON c.id = t.category_id WHERE t.id = ?");
    $st->execute([$id]);
    $t = $st->fetch();
    return ($t && can_access_branch((int)$t['branch_id'])) ? $t : null;
}
function categories_for_branch(int $bid): array {
    if ($bid) { $st = db()->prepare("SELECT * FROM categories WHERE branch_id IS NULL OR branch_id = ? ORDER BY name"); $st->execute([$bid]); return $st->fetchAll(); }
    return db()->query("SELECT * FROM categories ORDER BY name")->fetchAll();
}

/* ----------------------------------------------------------------- CSRF */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">'; }
function csrf_valid(): bool {
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && hash_equals(csrf_token(), $t);
}

/* ---------------------------------------------------------------- audit log */
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function audit(string $action, ?string $entity = null, ?int $entityId = null, $details = null, ?int $branch = null): void {
    try {
        $u = auth_user();
        if (is_array($details)) $details = json_encode($details, JSON_UNESCAPED_UNICODE);
        db()->prepare("INSERT INTO audit_log (user_id, username, branch_id, action, entity, entity_id, details, ip) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$u['id'] ?? null, $u['username'] ?? ($_SESSION['login_user'] ?? null), $branch, $action, $entity, $entityId, $details, client_ip()]);
    } catch (Throwable $e) { error_log('audit: ' . $e->getMessage()); }
}

/* ------------------------------------------------------------ login + throttle */
function login_locked(string $username): bool {
    // 6 wrong passwords for one account from one IP, or 30 wrong attempts from one IP, within 10 minutes.
    // (Per account+IP, so someone elsewhere cannot lock the real admin out.)
    $st = db()->prepare("SELECT COUNT(*) AS ip_n, SUM(CASE WHEN lower(username) = lower(?) THEN 1 ELSE 0 END) AS user_n
                         FROM login_attempts WHERE ok = 0 AND ip = ? AND created_at > datetime('now', '-10 minutes')");
    $st->execute([$username, client_ip()]);
    $r = $st->fetch();
    return (int)$r['user_n'] >= 6 || (int)$r['ip_n'] >= 30;
}
/** @return string|null error message, null on success */
function do_login(string $username, string $password): ?string {
    $username = trim($username);
    if (login_locked($username)) return "Juda ko'p urinish. 10 daqiqadan so'ng qayta urinib ko'ring.";
    $st = db()->prepare("SELECT u.*, b.is_active AS branch_active FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE lower(u.username) = lower(?)");
    $st->execute([$username]);
    $u = $st->fetch();
    $ok = $u && pw_verify($password, (string)$u['password']);
    db()->prepare("INSERT INTO login_attempts (ip, username, ok) VALUES (?,?,?)")->execute([client_ip(), $username, $ok ? 1 : 0]);
    if (!$ok) return 'Login yoki parol xato';
    if ((int)$u['is_active'] !== 1) return "Akkaunt faol emas. Administrator bilan bog'laning.";
    if ($u['role'] !== 'admin' && (empty($u['branch_id']) || (int)$u['branch_active'] !== 1)) return "Sizga faol filial biriktirilmagan. Administrator bilan bog'laning.";
    if (!pw_is_hash((string)$u['password'])) {
        db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([pw_hash($password), $u['id']]);
    }
    db()->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$u['id']]);
    db()->exec("DELETE FROM login_attempts WHERE created_at < datetime('now', '-3 days')");
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    $_SESSION['scope_branch'] = 0;
    $GLOBALS['_auth_reset'] = true;
    audit('login', 'user', (int)$u['id'], null, $u['branch_id'] ? (int)$u['branch_id'] : null);
    if ($u['role'] === 'admin') {   // one automatic safety copy per day, taken when an admin signs in
        $dir = backup_dir();
        if ($dir && !glob($dir . '/backup_' . date('Ymd') . '_*_auto.sqlite')) db_backup('auto');
    }
    return null;
}

/* ----------------------------------------------------------------- balances */
/** Has the admin confirmed this branch's real cash balances? (otherwise running balances would be meaningless) */
function branch_tracked(int $id): bool { $b = branch_get($id); return $b && (int)$b['balance_ok'] === 1; }
/** Branch ids (of the given list, or all visible) whose cash balances are tracked. */
function tracked_ids(?array $ids = null): array {
    $ids = $ids ?? visible_branch_ids();
    return array_values(array_filter($ids, function ($i) { return branch_tracked((int)$i); }));
}
/** Tracking summary for a scope (0 = all visible branches). */
function tracking_info(int $branch = 0): array {
    $ids = $branch ? [$branch] : visible_branch_ids(); $t = tracked_ids($ids);
    return ['any' => count($t) > 0, 'all' => count($t) === count($ids) && count($ids) > 0, 'untracked' => count($ids) - count($t), 'tracked' => count($t)];
}
/** All-time net flow (income - expense) per method, WITHOUT the opening balance. */
function branch_net_flows(int $branch): array {
    $res = ['cash' => 0.0, 'click' => 0.0, 'bank' => 0.0];
    $st = db()->prepare("SELECT " . SQL_METHOD . " AS m, SUM(CASE WHEN t.cash_in = 1 THEN t.payment ELSE -t.payment END) AS s FROM transactions t WHERE t.branch_id = ? GROUP BY m");
    $st->execute([$branch]);
    foreach ($st->fetchAll() as $r) $res[$r['m']] = (float)$r['s'];
    return $res;
}
/**
 * Money on hand per method at the end of $upTo (all dates when null) = opening balance + income - expense.
 * Only branches with confirmed balances (balance_ok) are included; $branch = 0 sums every tracked, visible branch.
 */
function branch_balances(int $branch = 0, ?string $upTo = null, ?string $before = null): array {
    $res = ['cash' => 0.0, 'click' => 0.0, 'bank' => 0.0];
    $ids = tracked_ids($branch ? [$branch] : null);
    if (!$ids) return $res + ['total' => 0.0];
    foreach ($ids as $id) {
        $b = branch_get($id); if (!$b) continue;
        $res['cash'] += (float)$b['open_cash']; $res['click'] += (float)$b['open_click']; $res['bank'] += (float)$b['open_bank'];
    }
    $in = implode(',', array_map('intval', $ids));
    $w = "t.branch_id IN ($in)"; $p = [];
    if ($upTo !== null)  { $w .= " AND t.date <= ?"; $p[] = $upTo; }
    if ($before !== null) { $w .= " AND t.date < ?"; $p[] = $before; }
    $st = db()->prepare("SELECT " . SQL_METHOD . " AS m, SUM(CASE WHEN t.cash_in = 1 THEN t.payment ELSE -t.payment END) AS s FROM transactions t WHERE $w GROUP BY m");
    $st->execute($p);
    foreach ($st->fetchAll() as $r) $res[$r['m']] += (float)$r['s'];
    $res['total'] = $res['cash'] + $res['click'] + $res['bank'];
    return $res;
}

/* ------------------------------------------------------------------ backups */
function backup_dir(): ?string {
    $d = __DIR__ . '/data/backups';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return (is_dir($d) && is_writable($d)) ? $d : null;
}
/** Consistent copy of the database. Returns the file path or null. */
function db_backup(string $label = 'manual'): ?string {
    $d = backup_dir(); if (!$d) return null;
    $file = $d . '/backup_' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9_-]/i', '', $label) . '.sqlite';
    try { db()->exec("VACUUM INTO " . db()->quote($file)); }
    catch (Throwable $e) { if (!@copy(DB_FILE, $file)) return null; }
    $all = glob($d . '/backup_*.sqlite') ?: [];
    sort($all);
    foreach (array_slice($all, 0, max(0, count($all) - 30)) as $old) @unlink($old);
    return is_file($file) ? $file : null;
}
