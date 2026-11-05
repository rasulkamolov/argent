<?php
/**
 * Single endpoint for every state-changing action.
 * Rules: POST only (except switch_branch), CSRF token required, and every action
 * re-checks on the server which branch the user may touch.
 */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/proofs.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function clean_number($str) {
    $str = str_replace([" ", "\xc2\xa0", "&nbsp;"], "", (string)$str);
    return floatval(preg_replace('/[^\d.]/', '', $str));
}
function pstr(string $k, string $d = ''): string { $v = $_POST[$k] ?? $d; return is_string($v) ? trim($v) : $d; }
function pint(string $k): int { return (int)($_POST[$k] ?? 0); }
/** Flash message + redirect. */
function done(string $to, string $msg = '', string $type = 'ok') {
    if ($msg !== '') flash_set($type, $msg);
    header("Location: $to");
    exit();
}
function safe_back(string $b, string $fallback = 'index.php'): string {
    return preg_match('/^[a-z0-9_]+\.php(\?[\w=&%.,\-]*)?(#[\w\-]*)?$/i', $b) ? $b : $fallback;
}
function need_admin(string $back): void { if (!is_admin()) done($back, "Bu amal faqat administrator uchun.", 'error'); }

/* ------------------------------------------------------------------ guards */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    done(!empty($_SERVER['HTTP_REFERER']) ? basename(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?: 'index.php') : 'index.php', "Yuborilgan fayl juda katta (serverda ruxsat etilganidan oshdi). Kichikroq rasm tanlang.", 'error');
}
$mutating = !in_array($action, ['switch_branch'], true);
if ($mutating && $_SERVER['REQUEST_METHOD'] !== 'POST') done('index.php', "Noto'g'ri so'rov usuli.", 'error');
if ($mutating && !csrf_valid()) done('index.php', "Sessiya muddati tugadi. Sahifani yangilab, qayta urinib ko'ring.", 'error');

/* ------------------------------------------------------------------- login */
if ($action === 'login') {
    $_SESSION['login_user'] = pstr('username');
    $err = do_login(pstr('username'), (string)($_POST['password'] ?? ''));
    if ($err) { $_SESSION['login_error'] = $err; done('index.php'); }
    unset($_SESSION['login_user'], $_SESSION['login_error']);
    done('index.php');
}
if ($action === 'logout') {
    if (auth_user()) audit('logout', 'user', (int)auth_user()['id']);
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    header('Location: index.php'); exit();
}

if (!auth_user()) { header('Location: index.php'); exit(); }
$me = auth_user();
$meId = (int)$me['id'];

/* ----------------------------------------------------------- branch switch */
if ($action === 'switch_branch') {
    if (!is_admin()) done('index.php');
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $_SESSION['scope_branch'] = ($id && branch_get($id)) ? $id : 0;
    header('Location: ' . safe_back((string)($_GET['back'] ?? $_POST['back'] ?? ''))); exit();
}

/* ------------------------------------------------------------- transactions */
if ($action === 'add_transaction') {
    $payment = clean_number($_POST['payment'] ?? '');
    $comment = pstr('comment');
    $method  = in_array(pstr('method'), ['cash', 'click', 'bank'], true) ? pstr('method') : 'cash';
    $type    = pstr('type') === 'expense' ? 'expense' : 'income';
    $catId   = pint('category_id');
    $bid     = is_admin() ? (pint('branch_id') ?: scope_branch()) : (int)$me['branch_id'];
    if (!$bid || !branch_get($bid) || !can_access_branch($bid)) done('index.php', "Filialni tanlang.", 'error');
    if (!branch_get($bid)['is_active']) done('index.php', "Bu filial arxivlangan.", 'error');
    if ($payment <= 0) done('index.php', "Summani kiriting", 'error');

    $date = date('Y-m-d');
    if ((is_admin() || is_manager()) && valid_date(pstr('date')) && pstr('date') <= $date) $date = pstr('date');

    if ($catId) {
        $st = db()->prepare("SELECT type FROM categories WHERE id = ? AND (branch_id IS NULL OR branch_id = ?)");
        $st->execute([$catId, $bid]); $ct = $st->fetchColumn();
        if (!$ct) $catId = 0; // category not available in this branch
    }
    $empId = pint('employee_id') ?: null;
    if ($empId) {
        $st = db()->prepare("SELECT name FROM employees WHERE id = ? AND branch_id = ?");
        $st->execute([$empId, $bid]); $empName = $st->fetchColumn();
        if ($empName) $comment = trim($comment . " (Oylik: $empName)"); else $empId = null;
    }
    $in = $type === 'income' ? 1 : 0;
    // payment screenshot (Click / Bank only)
    $proof = null;
    if ($method !== 'cash') {
        [$proof, $perr, $proofName] = proof_save($_FILES['proof'] ?? null, $bid);
        if ($perr) done('index.php', $perr, 'error');
        if (!$proof && proof_required($bid)) done('index.php', "Click/Bank uchun to'lov isbotini (skrinshot yoki PDF) biriktiring.", 'error');
    }
    db()->prepare("INSERT INTO transactions (branch_id, user_id, category_id, employee_id, payment, cash, click, bank, cash_in, cash_out, comment, date, proof, proof_name)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$bid, $meId, $catId ?: null, $empId, $payment, $method === 'cash' ? 1 : 0, $method === 'click' ? 1 : 0, $method === 'bank' ? 1 : 0, $in, 1 - $in, $comment, $date, $proof, $proof ? $proofName : null]);
    $id = (int)db()->lastInsertId();
    audit('tx.create', 'transaction', $id, ['amount' => $payment, 'type' => $type, 'method' => $method, 'date' => $date, 'proof' => (bool)$proof], $bid);
    done('index.php' . ($date !== date('Y-m-d') ? '?date=' . $date : ''), ($in ? "Kirim" : "Chiqim") . " saqlandi: " . ($in ? '+' : '−') . number_format($payment, 0, '.', ' '));
}

if ($action === 'attach_proof') {
    $t = tx_authorized(pint('id'));
    $back = $t ? 'index.php?date=' . urlencode($t['date']) : 'index.php';
    if (!$t || $t['cash'] && !$t['click'] && !$t['bank']) done($back, "Tranzaksiya topilmadi yoki naqd (skrinshot kerak emas).", 'error');
    $has = !empty($t['proof']);
    if ($has && !(is_admin() || is_manager())) done($back, "Isbot allaqachon biriktirilgan. Almashtirish faqat menejer/administratorga ruxsat.", 'error');
    [$new, $perr, $newName] = proof_save($_FILES['proof'] ?? null, (int)$t['branch_id']);
    if ($perr || !$new) done($back, $perr ?: "Faylni tanlang.", 'error');
    db()->prepare("UPDATE transactions SET proof = ?, proof_name = ? WHERE id = ?")->execute([$new, $newName, $t['id']]);
    db()->prepare("INSERT INTO transaction_history (transaction_id, user_id, field_name, old_value, new_value, changed_at) VALUES (?,?,?,?,?,datetime('now','localtime'))")
        ->execute([$t['id'], $meId, 'Isbot (fayl)', $has ? 'eski fayl' : "yo'q", $has ? 'almashtirildi' : 'biriktirildi']);
    if ($has) proof_delete($t['proof']);
    audit('tx.proof', 'transaction', (int)$t['id'], ['replaced' => $has], (int)$t['branch_id']);
    done($back, $has ? "Isbot almashtirildi" : "Isbot biriktirildi");
}
if ($action === 'remove_proof') {
    $t = tx_authorized(pint('id'));
    $back = $t ? 'index.php?date=' . urlencode($t['date']) : 'index.php';
    if (!$t || !(is_admin() || is_manager())) done($back, "Ruxsat yo'q.", 'error');
    if (!empty($t['proof'])) {
        db()->prepare("UPDATE transactions SET proof = NULL, proof_name = NULL WHERE id = ?")->execute([$t['id']]);
        db()->prepare("INSERT INTO transaction_history (transaction_id, user_id, field_name, old_value, new_value, changed_at) VALUES (?,?,?,?,?,datetime('now','localtime'))")->execute([$t['id'], $meId, 'Isbot (fayl)', 'bor', "o'chirildi"]);
        proof_delete($t['proof']);
        audit('tx.proof_remove', 'transaction', (int)$t['id'], null, (int)$t['branch_id']);
    }
    done($back, "Isbot o'chirildi");
}

if ($action === 'delete_transaction') {
    $t = tx_authorized(pint('id'));
    if (!$t) done('index.php', "Tranzaksiya topilmadi.", 'error');
    $back = 'index.php?date=' . urlencode($t['date']);
    if (!can_delete_tx((int)$t['branch_id'])) done($back, "O'chirish uchun ruxsat yo'q.", 'error');
    $snap = $t; unset($snap['category_name']);
    audit('tx.delete', 'transaction', (int)$t['id'], $snap, (int)$t['branch_id']);
    db()->prepare("DELETE FROM transactions WHERE id = ?")->execute([$t['id']]);
    done($back, "Tranzaksiya o'chirildi");
}

if ($action === 'restore_transaction') {
    need_admin('admin.php#journal');
    $st = db()->prepare("SELECT * FROM audit_log WHERE id = ? AND action = 'tx.delete'");
    $st->execute([pint('id')]); $log = $st->fetch();
    $snap = $log ? json_decode((string)$log['details'], true) : null;
    if (!is_array($snap) || empty($snap['branch_id'])) done('admin.php#journal', "Qaytarib bo'lmaydi.", 'error');
    $cols = ['id','branch_id','user_id','category_id','employee_id','payment','cash','click','bank','cash_in','cash_out','comment','date','is_modified','created_at','ext_ref','proof','proof_name'];
    $exists = db()->prepare("SELECT COUNT(*) FROM transactions WHERE id = ?"); $exists->execute([$snap['id'] ?? 0]);
    if ($exists->fetchColumn()) unset($snap['id']);
    $use = array_values(array_filter($cols, function ($c) use ($snap) { return array_key_exists($c, $snap); }));
    try {
        db()->prepare("INSERT INTO transactions (" . implode(',', $use) . ") VALUES (" . implode(',', array_fill(0, count($use), '?')) . ")")
            ->execute(array_map(function ($c) use ($snap) { return $snap[$c]; }, $use));
    } catch (Throwable $e) { done('admin.php#journal', "Qaytarishda xatolik: bunday yozuv allaqachon bor.", 'error'); }
    $newId = (int)db()->lastInsertId();
    db()->prepare("UPDATE audit_log SET action = 'tx.delete_restored' WHERE id = ?")->execute([$log['id']]);
    audit('tx.restore', 'transaction', $newId, ['from_log' => (int)$log['id'], 'amount' => $snap['payment']], (int)$snap['branch_id']);
    done('admin.php#journal', "Tranzaksiya qaytarildi");
}

/* -------------------------------------------------------------- cash count */
if ($action === 'add_cash_count') {
    $bid = is_admin() ? (pint('branch_id') ?: scope_branch()) : (int)$me['branch_id'];
    if (!$bid || !can_access_branch($bid)) done('kassa.php', "Filialni tanlang.", 'error');
    if (!branch_tracked($bid)) done('kassa.php', "Bu filialda kassa qoldig'i sozlanmagan (Admin → Filiallar).", 'error');
    $counted = clean_number($_POST['counted'] ?? '');
    $today = date('Y-m-d');
    $expected = branch_balances($bid, $today)['cash'];
    $diff = $counted - $expected;
    db()->prepare("INSERT INTO cash_counts (branch_id, date, method, expected, counted, diff, note, user_id) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$bid, $today, 'cash', $expected, $counted, $diff, pstr('note'), $meId]);
    audit('cash.count', 'branch', $bid, ['expected' => $expected, 'counted' => $counted, 'diff' => $diff], $bid);
    done('kassa.php', abs($diff) < 0.5 ? "Kassa to'g'ri: farq yo'q ✓" : ("Kassa farqi: " . fmt_signed($diff) . " so'm"), abs($diff) < 0.5 ? 'ok' : 'error');
}

/* ---------------------------------------------------------------- profile */
if ($action === 'change_password') {
    $cur = (string)($_POST['current_password'] ?? ''); $new = (string)($_POST['new_password'] ?? '');
    $st = db()->prepare("SELECT password FROM users WHERE id = ?"); $st->execute([$meId]);
    if (!pw_verify($cur, (string)$st->fetchColumn())) done('profile.php', "Hozirgi parol noto'g'ri", 'error');
    if (strlen($new) < 6) done('profile.php', "Yangi parol kamida 6 belgi bo'lsin", 'error');
    db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([pw_hash($new), $meId]);
    audit('user.password', 'user', $meId);
    done('profile.php', "Parol yangilandi");
}
if ($action === 'update_profile') {
    db()->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?")->execute([pstr('full_name'), pstr('phone'), $meId]);
    if (is_admin() && pstr('username') !== '' && pstr('username') !== $me['username']) {
        $dup = db()->prepare("SELECT COUNT(*) FROM users WHERE lower(username) = lower(?) AND id <> ?"); $dup->execute([pstr('username'), $meId]);
        if ($dup->fetchColumn()) done('profile.php', "Bu login band", 'error');
        db()->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([pstr('username'), $meId]);
    }
    done('profile.php', "Profil yangilandi");
}

/* ================================================================== ADMIN */
$A = 'admin.php';
if (is_admin()) {
    /* ---- branches ---- */
    if ($action === 'create_branch' || $action === 'update_branch') {
        $name = pstr('name');
        if ($name === '') done($A . '#branches', "Filial nomini kiriting", 'error');
        $track = !empty($_POST['track_balance']);
        $want = [clean_number($_POST['bal_cash'] ?? ''), clean_number($_POST['bal_click'] ?? ''), clean_number($_POST['bal_bank'] ?? '')];
        $color = max(0, min(7, pint('color')));
        if ($action === 'create_branch') {
            $dup = db()->prepare("SELECT COUNT(*) FROM branches WHERE lower(name) = lower(?)"); $dup->execute([$name]);
            if ($dup->fetchColumn()) done($A . '#branches', "Bunday nomli filial bor", 'error');
            $so = (int)db()->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM branches")->fetchColumn();
            db()->prepare("INSERT INTO branches (name, address, phone, note, color, open_cash, open_click, open_bank, balance_ok, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$name, pstr('address'), pstr('phone'), pstr('note'), $color, $track ? $want[0] : 0, $track ? $want[1] : 0, $track ? $want[2] : 0, $track ? 1 : 0, $so]);
            $id = (int)db()->lastInsertId();
            audit('branch.create', 'branch', $id, ['name' => $name], $id);
            done($A . '#branches', "Filial yaratildi: $name");
        }
        $id = pint('id'); if (!branch_get($id)) done($A . '#branches', "Filial topilmadi", 'error');
        db()->prepare("UPDATE branches SET name=?, address=?, phone=?, note=?, color=? WHERE id=?")->execute([$name, pstr('address'), pstr('phone'), pstr('note'), $color, $id]);
        if ($track) {   // calibrate: opening = what the admin says is there now − everything recorded so far
            $net = branch_net_flows($id);
            db()->prepare("UPDATE branches SET open_cash=?, open_click=?, open_bank=?, balance_ok=1 WHERE id=?")->execute([$want[0] - $net['cash'], $want[1] - $net['click'], $want[2] - $net['bank'], $id]);
        } else {
            db()->prepare("UPDATE branches SET balance_ok=0 WHERE id=?")->execute([$id]);
        }
        audit('branch.update', 'branch', $id, ['name' => $name, 'tracked' => $track, 'balance_now' => $track ? $want : null], $id);
        done($A . '#branches', "Filial yangilandi");
    }
    if ($action === 'toggle_branch') {
        $id = pint('id'); $b = branch_get($id); if (!$b) done($A . '#branches', "Filial topilmadi", 'error');
        $new = $b['is_active'] ? 0 : 1;
        db()->prepare("UPDATE branches SET is_active = ? WHERE id = ?")->execute([$new, $id]);
        if (!$new && (int)($_SESSION['scope_branch'] ?? 0) === $id) $_SESSION['scope_branch'] = 0;
        audit($new ? 'branch.activate' : 'branch.archive', 'branch', $id, ['name' => $b['name']], $id);
        done($A . '#branches', $new ? "Filial faollashtirildi" : "Filial arxivlandi (kassirlar kira olmaydi)");
    }
    if ($action === 'delete_branch') {
        $id = pint('id'); $b = branch_get($id); if (!$b) done($A . '#branches', "Filial topilmadi", 'error');
        if (mb_strtolower(pstr('confirm_name')) !== mb_strtolower($b['name'])) done($A . '#branches', "Tasdiqlash uchun filial nomini aynan yozing", 'error');
        $n = (int)db()->query("SELECT COUNT(*) FROM transactions WHERE branch_id = " . $id)->fetchColumn();
        $bk = db_backup('before_delete_branch');
        foreach (db()->query("SELECT proof FROM transactions WHERE branch_id = $id AND proof IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $pf) proof_delete($pf);
        db()->beginTransaction();
        db()->exec("DELETE FROM transaction_history WHERE transaction_id IN (SELECT id FROM transactions WHERE branch_id = $id)");
        db()->exec("DELETE FROM transactions WHERE branch_id = $id");
        db()->exec("DELETE FROM employees WHERE branch_id = $id");
        db()->exec("DELETE FROM categories WHERE branch_id = $id");
        db()->exec("DELETE FROM cash_counts WHERE branch_id = $id");
        db()->exec("DELETE FROM users WHERE branch_id = $id AND role <> 'admin'");
        db()->exec("DELETE FROM settings WHERE key LIKE 'b$id.%'");
        db()->exec("DELETE FROM branches WHERE id = $id");
        db()->commit();
        if ((int)($_SESSION['scope_branch'] ?? 0) === $id) $_SESSION['scope_branch'] = 0;
        audit('branch.delete', 'branch', $id, ['name' => $b['name'], 'transactions' => $n, 'backup' => $bk ? basename($bk) : null]);
        done($A . '#branches', "Filial va uning ma'lumotlari o'chirildi" . ($bk ? " (zaxira nusxa saqlandi)" : ''));
    }
    if ($action === 'set_branch_setting') {
        $id = pint('branch_id'); $key = pstr('key');
        if (!branch_get($id) || !in_array($key, ['staff_can_edit', 'staff_can_delete', 'require_proof'], true)) done($A . '#branches', "Noto'g'ri so'rov", 'error');
        if (pstr('value') === 'default') setting_clear($key, $id); else setting_set($key, pstr('value') === '1' ? '1' : '0', $id);
        audit('branch.setting', 'branch', $id, [$key => pstr('value')], $id);
        done($A . '#branches', "Filial ruxsati yangilandi");
    }

    /* ---- users ---- */
    if ($action === 'create_user' || $action === 'update_user') {
        $uname = pstr('username'); $role = in_array(pstr('role'), ['admin', 'manager', 'staff'], true) ? pstr('role') : 'staff';
        $bid = $role === 'admin' ? null : (pint('branch_id') ?: null);
        $pass = (string)($_POST['password'] ?? '');
        if ($uname === '') done($A . '#users', "Login kiriting", 'error');
        if ($role !== 'admin' && (!$bid || !branch_get($bid))) done($A . '#users', "Kassir/menejer uchun filialni tanlang", 'error');
        $uid = $action === 'update_user' ? pint('id') : 0;
        $dup = db()->prepare("SELECT COUNT(*) FROM users WHERE lower(username) = lower(?) AND id <> ?"); $dup->execute([$uname, $uid]);
        if ($dup->fetchColumn()) done($A . '#users', "Bu login band: $uname", 'error');
        if ($action === 'create_user') {
            if (strlen($pass) < 6) done($A . '#users', "Parol kamida 6 belgi bo'lsin", 'error');
            db()->prepare("INSERT INTO users (username, password, role, branch_id, full_name, phone, is_active, created_at) VALUES (?,?,?,?,?,?,1,CURRENT_TIMESTAMP)")
                ->execute([$uname, pw_hash($pass), $role, $bid, pstr('full_name'), pstr('phone')]);
            $nid = (int)db()->lastInsertId();
            audit('user.create', 'user', $nid, ['username' => $uname, 'role' => $role], $bid);
            done($A . '#users', "Foydalanuvchi qo'shildi: $uname");
        }
        $st = db()->prepare("SELECT * FROM users WHERE id = ?"); $st->execute([$uid]); $old = $st->fetch();
        if (!$old) done($A . '#users', "Foydalanuvchi topilmadi", 'error');
        if ($uid === $meId && $role !== 'admin') done($A . '#users', "O'zingizning rolingizni pasaytira olmaysiz", 'error');
        db()->prepare("UPDATE users SET username=?, role=?, branch_id=?, full_name=?, phone=? WHERE id=?")->execute([$uname, $role, $bid, pstr('full_name'), pstr('phone'), $uid]);
        if ($pass !== '') {
            if (strlen($pass) < 6) done($A . '#users', "Parol kamida 6 belgi bo'lsin", 'error');
            db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([pw_hash($pass), $uid]);
        }
        audit('user.update', 'user', $uid, ['username' => $uname, 'role' => $role, 'branch' => $bid, 'password_changed' => $pass !== ''], $bid);
        done($A . '#users', "Foydalanuvchi yangilandi");
    }
    if ($action === 'toggle_user') {
        $uid = pint('id'); if ($uid === $meId) done($A . '#users', "O'zingizni bloklay olmaysiz", 'error');
        $st = db()->prepare("SELECT * FROM users WHERE id = ?"); $st->execute([$uid]); $u = $st->fetch();
        if (!$u) done($A . '#users', "Topilmadi", 'error');
        $new = $u['is_active'] ? 0 : 1;
        db()->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$new, $uid]);
        audit($new ? 'user.activate' : 'user.block', 'user', $uid, ['username' => $u['username']], $u['branch_id'] ? (int)$u['branch_id'] : null);
        done($A . '#users', $new ? "Foydalanuvchi faollashtirildi" : "Foydalanuvchi bloklandi");
    }
    if ($action === 'delete_user') {
        $uid = pint('id'); if ($uid === $meId) done($A . '#users', "O'zingizni o'chira olmaysiz", 'error');
        $st = db()->prepare("SELECT * FROM users WHERE id = ?"); $st->execute([$uid]); $u = $st->fetch();
        if (!$u) done($A . '#users', "Topilmadi", 'error');
        if ($u['role'] === 'admin' && (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn() <= 1) done($A . '#users', "Oxirgi administratorni o'chirib bo'lmaydi", 'error');
        db()->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        audit('user.delete', 'user', $uid, ['username' => $u['username'], 'role' => $u['role']], $u['branch_id'] ? (int)$u['branch_id'] : null);
        done($A . '#users', "Foydalanuvchi o'chirildi (tranzaksiyalari saqlanadi)");
    }

    /* ---- employees ---- */
    if ($action === 'create_employee' || $action === 'update_employee') {
        $bid = pint('branch_id'); if (!branch_get($bid)) done($A . '#employees', "Filialni tanlang", 'error');
        if (pstr('name') === '') done($A . '#employees', "Ismni kiriting", 'error');
        if ($action === 'create_employee') {
            db()->prepare("INSERT INTO employees (name, position, salary, branch_id, phone) VALUES (?,?,?,?,?)")->execute([pstr('name'), pstr('position'), clean_number($_POST['salary'] ?? ''), $bid, pstr('phone')]);
            audit('employee.create', 'employee', (int)db()->lastInsertId(), ['name' => pstr('name')], $bid);
            done($A . '#employees', "Xodim qo'shildi");
        }
        db()->prepare("UPDATE employees SET name=?, position=?, salary=?, branch_id=?, phone=? WHERE id=?")->execute([pstr('name'), pstr('position'), clean_number($_POST['salary'] ?? ''), $bid, pstr('phone'), pint('id')]);
        audit('employee.update', 'employee', pint('id'), ['name' => pstr('name')], $bid);
        done($A . '#employees', "Xodim yangilandi");
    }
    if ($action === 'delete_employee') {
        db()->prepare("DELETE FROM employees WHERE id = ?")->execute([pint('id')]);
        audit('employee.delete', 'employee', pint('id'));
        done($A . '#employees', "Xodim o'chirildi");
    }

    /* ---- categories ---- */
    if ($action === 'add_category' || $action === 'update_category') {
        $name = pstr('name'); if ($name === '') done($A . '#categories', "Nomini kiriting", 'error');
        $type = pstr('type') === 'expense' ? 'expense' : 'income';
        $bid = pint('branch_id'); $bid = ($bid && branch_get($bid)) ? $bid : null;
        if ($action === 'add_category') {
            db()->prepare("INSERT INTO categories (name, type, branch_id) VALUES (?,?,?)")->execute([$name, $type, $bid]);
            audit('category.create', 'category', (int)db()->lastInsertId(), ['name' => $name, 'type' => $type], $bid);
            done($A . '#categories', "Kategoriya qo'shildi: $name");
        }
        db()->prepare("UPDATE categories SET name=?, type=?, branch_id=? WHERE id=?")->execute([$name, $type, $bid, pint('id')]);
        audit('category.update', 'category', pint('id'), ['name' => $name], $bid);
        done($A . '#categories', "Kategoriya yangilandi");
    }
    if ($action === 'delete_category') {
        db()->prepare("DELETE FROM categories WHERE id = ?")->execute([pint('id')]);
        audit('category.delete', 'category', pint('id'));
        done($A . '#categories', "Kategoriya o'chirildi");
    }

    /* ---- settings, backup, sample data ---- */
    if ($action === 'update_setting') {
        $key = pstr('key');
        if (in_array($key, ['staff_can_edit', 'staff_can_delete', 'require_proof'], true)) {
            setting_set($key, pstr('value') === '1' ? '1' : '0');
            audit('setting.update', 'setting', null, [$key => pstr('value')]);
            done($A . '#settings', "Ruxsat yangilandi");
        }
        done($A . '#settings');
    }
    if ($action === 'backup_now') {
        $f = db_backup('manual');
        audit('backup.create', 'database', null, ['file' => $f ? basename($f) : null]);
        done($A . '#settings', $f ? "Zaxira nusxa saqlandi: " . basename($f) : "Zaxira nusxa yaratib bo'lmadi (data/ papkasi yozishga ochiq emas)", $f ? 'ok' : 'error');
    }
    if ($action === 'load_sample_data') {
        $bid = scope_branch(); if (!$bid) done($A . '#settings', "Avval yuqoridan bitta filialni tanlang", 'error');
        $cats = categories_for_branch($bid);
        $inc = array_column(array_filter($cats, function ($c) { return $c['type'] === 'income'; }), 'id') ?: [null];
        $exp = array_column(array_filter($cats, function ($c) { return $c['type'] === 'expense'; }), 'id') ?: [null];
        $stmt = db()->prepare("INSERT INTO transactions (branch_id,user_id,category_id,payment,cash,click,bank,cash_in,cash_out,comment,date) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        db()->beginTransaction();
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            for ($j = rand(1, 3); $j > 0; $j--) { $m = rand(0, 2); $stmt->execute([$bid, $meId, $inc[array_rand($inc)], rand(5, 50) * 10000, (int)($m == 0), (int)($m == 1), (int)($m == 2), 1, 0, '[NAMUNA] Sinov kirim', $d]); }
            for ($j = rand(1, 2); $j > 0; $j--) { $m = rand(0, 2); $stmt->execute([$bid, $meId, $exp[array_rand($exp)], rand(2, 30) * 10000, (int)($m == 0), (int)($m == 1), (int)($m == 2), 0, 1, '[NAMUNA] Sinov chiqim', $d]); }
        }
        db()->commit();
        audit('sample.load', 'branch', $bid, null, $bid);
        done($A . '#settings', "Namunaviy ma'lumotlar yuklandi: " . branch_get($bid)['name']);
    }
    if ($action === 'delete_sample_data') {
        $n = db()->exec("DELETE FROM transactions WHERE comment LIKE '[NAMUNA]%'");
        audit('sample.delete', 'transaction', null, ['count' => $n]);
        done($A . '#settings', "Namunaviy ma'lumotlar o'chirildi ($n ta)");
    }
}

audit('denied', null, null, ['action' => $action]);
done('index.php', "Ruxsat etilmagan yoki noma'lum amal.", 'error');
