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
    if (!is_admin() && !is_manager()) done('index.php', "Bu amal kassirlar uchun yopiq.", 'error');
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

    /* ---- Do'konPro monitoring (the shop database is only ever read) ---- */
    if ($action === 'shop_review') {
        require_once __DIR__ . '/shop_lib.php'; $ids = array_filter(array_map('intval', explode(',', pstr('ids')))); $n = 0;
        foreach (array_slice($ids, 0, 100) as $id) if (shop_review($id, $meId, pstr('note'))) $n++;
        audit('shop.review', 'shop', null, ['count' => $n]);
        done(safe_back(pstr('back'), 'shop.php'), $n ? "$n ta hodisa «ko'rildi» deb belgilandi" : "Belgilanadigan hodisa topilmadi (allaqachon ko'rilgan)", $n ? 'ok' : 'error');
    }
    if ($action === 'shop_config_save') {
        require_once __DIR__ . '/shop_lib.php'; $back = $A . '#shop'; $path = trim((string)($_POST['shop_db_path'] ?? ''));
        if ($path !== '') {
            if ($path[0] !== '/' && !preg_match('~^[A-Za-z]:[\\\\/]~', $path)) done($back, "Yo'l to'liq (absolyut) bo'lishi kerak, masalan /home/u…/domains/…/public_html/shop/db/bookshop.db", 'error');
            if (!is_file($path)) done($back, "Bunday fayl topilmadi: $path", 'error');
            if (!is_readable($path)) done($back, "Fayl o'qishga ochiq emas (huquq): $path", 'error');
            if (!preg_match('/\.(db|sqlite|sqlite3)$/i', $path)) done($back, "Fayl kengaytmasi .db / .sqlite bo'lishi kerak", 'error');
        }
        [$ok, $msg] = private_config_write(['SHOP_DB_PATH' => $path]); if (!$ok) done($back, $msg, 'error');
        audit('shop.config', 'shop', null, ['field' => 'SHOP_DB_PATH']);
        shop_event_add(['type' => 'system', 'severity' => 'info', 'staff' => $me['username'] ?? '', 'details' => "Do'konPro baza yo'li o'zgartirildi (SHOP_DB_PATH)", 'dedupe_key' => 'cfg|' . bin2hex(random_bytes(8))]);
        done($back, $path === '' ? "Yo'l o'chirildi (standart joylar qidiriladi)" : "Baza yo'li saqlandi");
    }
    if ($action === 'shop_save_map') {
        require_once __DIR__ . '/shop_lib.php';
        foreach ((array)($_POST['name'] ?? []) as $bid => $nm) {
            $id = (int)$bid; if (!branch_get($id)) continue; $nm = mb_substr(trim((string)$nm), 0, 120); $on = !empty($_POST['on'][$bid]) ? 1 : 0;
            if ($nm === '') { db()->prepare("DELETE FROM shop_branch_map WHERE branch_id = ?")->execute([$id]); continue; }
            db()->prepare("INSERT INTO shop_branch_map (branch_id, shop_name, shop_branch_id, is_active) VALUES (?,?,NULL,?) ON CONFLICT(branch_id) DO UPDATE SET shop_name = excluded.shop_name, shop_branch_id = NULL, is_active = excluded.is_active")->execute([$id, $nm, $on]);
        }
        audit('shop.map', 'shop'); done($A . '#shop', "Filial moslash saqlandi");
    }
    if ($action === 'shop_save_settings') {
        require_once __DIR__ . '/shop_lib.php';
        $num = function ($k, $min, $max) { return (string)max($min, min($max, (int)preg_replace('/\D+/', '', pstr($k)))); };
        $dec = function ($k, $min, $max) { return (string)max($min, min($max, (float)str_replace(',', '.', pstr($k)))); };
        $tm = function ($k, $d) { return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', pstr($k)) ? pstr($k) : $d; };
        $txt = function ($k, $d) { $v = mb_substr(trim(pstr($k)), 0, 200); return $v !== '' ? $v : $d; };
        $def = shop_defaults();
        foreach (['kitob_category' => $txt('kitob_category', $def['kitob_category']), 'argent_shop_cat' => $txt('argent_shop_cat', $def['argent_shop_cat']), 'argent_kitob_cat' => $txt('argent_kitob_cat', $def['argent_kitob_cat']), 'argent_pay_cat' => $txt('argent_pay_cat', $def['argent_pay_cat']), 'keywords' => $txt('keywords', $def['keywords']),
                 'work_from' => $tm('work_from', '08:00'), 'work_to' => $tm('work_to', '21:00'), 'eod_time' => $tm('eod_time', '21:00'), 'recon_next_time' => $tm('recon_next_time', '10:00'),
                 'return_late_min' => $num('return_late_min', 1, 1440), 'small_debt' => $num('small_debt', 0, 100000000), 'cost_jump_pct' => $num('cost_jump_pct', 1, 1000), 'transfer_wait_h' => $num('transfer_wait_h', 1, 168), 'window_days' => $num('window_days', 14, 365),
                 'inv_count' => $num('inv_count', 5, 10), 'inv_top_days' => $num('inv_top_days', 7, 365), 'tolerance' => $num('tolerance', 0, 100000), 'late_entry_days' => $num('late_entry_days', 1, 30),
                 'pat_low_ratio' => $dec('pat_low_ratio', 0.1, 1), 'pat_high_ratio' => $dec('pat_high_ratio', 1, 20), 'pat_min_events' => $num('pat_min_events', 1, 100),
                 'enabled' => !empty($_POST['enabled']) ? '1' : '0', 'alerts_on' => !empty($_POST['alerts_on']) ? '1' : '0'] as $k => $val) setting_set('shop.' . $k, $val);
        audit('shop.settings', 'shop'); shop_event_add(['type' => 'system', 'severity' => 'info', 'staff' => $me['username'] ?? '', 'details' => "Do'kon monitoring qoidalari/chegaralari o'zgartirildi" . (empty($_POST['alerts_on']) ? " (Telegram ogohlantirish O'CHIRILDI)" : ''), 'dedupe_key' => 'cfg|' . bin2hex(random_bytes(8))]);
        done($A . '#shop', "Do'kon sozlamalari saqlandi");
    }
    if ($action === 'shop_run_now') {
        require_once __DIR__ . '/shop_report.php'; @set_time_limit(120); session_write_close(); $r = shop_run(['trigger' => 'manual']); app_session_start(); audit('shop.run', 'shop', null, ['ok' => $r['ok']]);
        done($A . '#shop', !empty($r['busy']) ? "Boshqa tekshiruv ishlayapti" : ($r['skipped'] ? "Tekshiruv o'tkazilmadi: " . ($r['skipped'] === 'config' ? "baza sozlanmagan" : "o'chirilgan") : ($r['ok'] ? "Tekshiruv tayyor: {$r['new_events']} yangi hodisa" . ($r['warnings'] ? ' · ogohlantirish: ' . implode(' | ', array_slice($r['warnings'], 0, 3)) : '') : "Xato: " . implode(' | ', $r['errors']))), (!empty($r['busy']) || $r['skipped'] || $r['ok']) ? 'ok' : 'error');
    }
    if ($action === 'shop_http_check') {
        require_once __DIR__ . '/shop_lib.php'; $r = shop_http_exposure(); audit('shop.http_check', 'shop', null, ['result' => $r['state']]);
        done($A . '#shop', $r['msg'], $r['state'] === 'open' ? 'error' : 'ok');
    }
    if ($action === 'shop_inv_start') {
        require_once __DIR__ . '/shop_inv.php';
        try { $id = shop_inv_start(pint('branch'), $meId); audit('shop.inv_start', 'shop', $id, null, pint('branch')); done('inventory_check.php#check' . $id, "Tekshiruv #$id tayyor: tovarlarni sanab, sonini kiriting"); }
        catch (Throwable $e) { done('inventory_check.php', lcup_redact($e->getMessage()), 'error'); }
    }
    if ($action === 'shop_inv_save') {
        require_once __DIR__ . '/shop_inv.php';
        try { $n = shop_inv_save(pint('check'), (array)($_POST['count'] ?? []), $meId); audit('shop.inv_save', 'shop', pint('check'), ['items' => $n]);
            $chk = db()->prepare("SELECT status FROM shop_inv_checks WHERE id = ?"); $chk->execute([pint('check')]); $done = $chk->fetchColumn() === 'done';
            done($done ? 'inventory_check.php?check=' . pint('check') . '#h' . pint('check') : 'inventory_check.php#check' . pint('check'), $n ? "$n ta tovar saqlandi va tizim bilan solishtirildi" . ($done ? ". Tekshiruv yakunlandi." : '') : "Hech narsa saqlanmadi: sonlarni kiriting (0 yoki undan katta raqam)", $n ? 'ok' : 'error'); }
        catch (Throwable $e) { done('inventory_check.php', lcup_redact($e->getMessage()), 'error'); }
    }
    if ($action === 'shop_inv_cancel') {
        require_once __DIR__ . '/shop_inv.php'; $ok = shop_inv_cancel(pint('check')); audit('shop.inv_cancel', 'shop', pint('check'));
        done('inventory_check.php', $ok ? "Tekshiruv bekor qilindi" : "Bekor qilib bo'lmadi", $ok ? 'ok' : 'error');
    }

    /* ---- LC-UP monitoring ---- */
    if ($action === 'lcup_review') {
        $ids = array_filter(array_map('intval', explode(',', pstr('ids')))); $n = 0; require_once __DIR__ . '/lcup_lib.php';
        foreach (array_slice($ids, 0, 100) as $id) if (lcup_review($id, $meId, pstr('note'))) $n++;
        audit('lcup.review', 'lcup', null, ['count' => $n]);
        done(safe_back(pstr('back'), 'lcup.php'), $n ? "$n ta hodisa «ko'rildi» deb belgilandi" : "Belgilanadigan hodisa topilmadi (allaqachon ko'rilgan)", $n ? 'ok' : 'error');
    }
    if ($action === 'lcup_save_settings') {
        require_once __DIR__ . '/lcup_lib.php'; $num = function ($k, $min, $max) { return (string)max($min, min($max, (int)preg_replace('/\D+/', '', pstr($k)))); };
        $tm = function ($k, $d) { return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', pstr($k)) ? pstr($k) : $d; };
        foreach (['summary_time' => $tm('summary_time', '21:00'), 'weekly_time' => $tm('weekly_time', '09:00'), 'quick_fix_min' => $num('quick_fix_min', 0, 120), 'small_amount' => $num('small_amount', 0, 100000000), 'late_minutes' => $num('late_minutes', 1, 10080), 'hot_pages' => $num('hot_pages', 1, 20), 'warm_pages' => $num('warm_pages', 0, 20), 'delay_ms' => $num('delay_ms', 0, 5000), 'night_pages_per_run' => $num('night_pages_per_run', 1, 200),
                 'work_from' => $tm('work_from', '08:00'), 'work_to' => $tm('work_to', '21:00'), 'eod_time' => $tm('eod_time', '21:00'), 'night_time' => $tm('night_time', '03:00'),
                 'allow_switch_post' => !empty($_POST['allow_switch_post']) ? '1' : '0', 'history_via_livewire' => !empty($_POST['history_via_livewire']) ? '1' : '0', 'alerts_on' => !empty($_POST['alerts_on']) ? '1' : '0'] as $k => $val) setting_set('lcup.' . $k, $val);
        foreach (['worker_url', 'archive_url', 'payments_url', 'discount_url', 'debtors_url'] as $k) { $u = pstr($k); if ($u === '' || preg_match('~^/[A-Za-z0-9_\-/.]*$~', $u)) setting_set('lcup.' . $k, $u); }
        require_once __DIR__ . '/lcup_report.php'; lcup_settings_changed(['qoidalar/chegaralar' . (empty($_POST['alerts_on']) ? ' (Telegram ogohlantirish O\'CHIRILDI)' : '')]);
        done($A . '#lcup', "LC-UP sozlamalari saqlandi");
    }
    if ($action === 'lcup_save_map') {
        $keys = (array)($_POST['key'] ?? []); $names = (array)($_POST['name'] ?? []); $on = (array)($_POST['on'] ?? []);
        foreach (branches_all() as $b) {
            $id = (int)$b['id']; $k = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($keys[$id] ?? '')); $nm = mb_substr(trim((string)($names[$id] ?? '')), 0, 80); $act = isset($on[$id]) ? 1 : 0;
            if ($k === '' && $nm === '') { db()->prepare("DELETE FROM lcup_branch_map WHERE branch_id = ?")->execute([$id]); continue; }
            db()->prepare("INSERT INTO lcup_branch_map (branch_id, lcup_key, lcup_name, is_active) VALUES (?,?,?,?) ON CONFLICT(branch_id) DO UPDATE SET lcup_key = excluded.lcup_key, lcup_name = excluded.lcup_name, is_active = excluded.is_active")->execute([$id, $k, $nm, $act]);
        }
        audit('lcup.map', 'lcup'); done($A . '#lcup', "Filial moslash saqlandi");
    }
    if ($action === 'lcup_config_save') {
        require_once __DIR__ . '/lcup_report.php'; $back = $A . '#settings';
        $in = function ($k) { return trim((string)($_POST[$k] ?? '')); };
        $cur = private_config(true); $oldTg = lcup_tg(); $ch = []; $names = [];
        $set = function (string $key, string $label, string $val) use (&$ch, &$names, $cur) { if ($val !== '' && ($cur[$key] ?? '') !== $val) { $ch[$key] = $val; $names[] = $label; } };
        if (($v = $in('lc_phone')) !== '') { if (!preg_match('/^\+?[0-9 ()\-]{9,20}$/', $v)) done($back, "Telefon raqam formati noto'g'ri", 'error'); $set('LCUP_PHONE', 'LCUP_PHONE', $v); }
        if (($v = (string)($_POST['lc_password'] ?? '')) !== '') { if (mb_strlen($v) > 200) done($back, "Parol juda uzun", 'error'); $set('LCUP_PASSWORD', 'LCUP_PASSWORD', $v); }
        if (($v = $in('tg_token')) !== '') { if (!preg_match('/^[0-9]{5,}:[A-Za-z0-9_\-]{20,}$/', $v)) done($back, "Bot token formati noto'g'ri (123456:ABC…)", 'error'); $set('TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_TOKEN', $v); }
        if (($v = $in('tg_chat')) !== '') { if (!preg_match('/^(-?[0-9]{5,20}|@[A-Za-z0-9_]{5,})$/', $v)) done($back, "Chat ID formati noto'g'ri (masalan 123456789 yoki -100…)", 'error'); $set('TELEGRAM_CHAT_ID', 'TELEGRAM_CHAT_ID', $v); }
        if (($v = $in('cron_token')) !== '') { if (!preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $v)) done($back, "CRON_TOKEN kamida 16 ta harf/raqam bo'lsin (A–Z, 0–9, _ -)", 'error'); $set('CRON_TOKEN', 'CRON_TOKEN', $v); }
        if (!empty($_POST['webhook_clear'])) { if (($cur['LCUP_WEBHOOK_SECRET'] ?? '') !== '') { $ch['LCUP_WEBHOOK_SECRET'] = ''; $names[] = 'LCUP_WEBHOOK_SECRET (o\'chirildi)'; } }
        elseif (($v = $in('webhook_secret')) !== '') { if (mb_strlen($v) < 16 || mb_strlen($v) > 200) done($back, "Webhook siri kamida 16 belgi bo'lsin", 'error'); $set('LCUP_WEBHOOK_SECRET', 'LCUP_WEBHOOK_SECRET', $v); }
        $mon = mb_substr($in('monitor_name'), 0, 80); if ($mon !== (string)setting('lcup.monitor_name', '') && isset($_POST['monitor_name'])) { setting_set('lcup.monitor_name', $mon); $names[] = 'monitor_name'; }
        if (!$ch && !$names) done($back, "O'zgarish yo'q (bo'sh qoldirilgan maydonlar eski qiymatni saqlaydi)");
        if ($ch) { [$ok, $msg] = private_config_write($ch); if (!$ok) done($back, $msg, 'error'); private_set(['telegram_token' => null, 'telegram_chat' => null]); }
        if (isset($ch['TELEGRAM_BOT_TOKEN']) || isset($ch['TELEGRAM_CHAT_ID'])) lcup_state_set('alerts_since', lcup_now());
        lcup_settings_changed($names, $oldTg);
        done($back, "LC-UP monitoring sozlamalari saqlandi (" . count($names) . " ta maydon). Jurnalga yozildi va Telegram'ga xabar yuborildi.");
    }
    if ($action === 'lcup_test_telegram') {
        require_once __DIR__ . '/lcup_lib.php'; $err = null;
        $ok = lcup_tg_send("✅ <b>Argent: Telegram ulangan</b>\nTest xabar — LC-UP ogohlantirishlari shu yerga keladi. (" . date('d.m.Y H:i') . ")", $err);
        audit('lcup.telegram_test', 'lcup'); done(safe_back(pstr('back'), $A . '#settings'), $ok ? "Test xabar yuborildi" : "Telegram xatosi: $err", $ok ? 'ok' : 'error');
    }
    if ($action === 'lcup_send_report') {
        require_once __DIR__ . '/lcup_report.php'; $err = null; $kind = pstr('kind') === 'weekly' ? 'weekly' : 'daily';
        $ok = lcup_tg_ready() && lcup_send_all($kind === 'weekly' ? lcup_weekly_report() : lcup_daily_report(date('Y-m-d')), $err);
        audit('lcup.report_now', 'lcup', null, ['kind' => $kind]); done($A . '#lcup', $ok ? ($kind === 'weekly' ? 'Haftalik hisobot yuborildi' : 'Kunlik xulosa yuborildi') : ("Yuborib bo'lmadi: " . ($err ?: 'Telegram sozlanmagan')), $ok ? 'ok' : 'error');
    }
    if ($action === 'lcup_selftest' || $action === 'lcup_run_now' || $action === 'lcup_diagnostics') {
        require_once __DIR__ . '/lcup_diag.php'; @set_time_limit(150); session_write_close();
        if ($action === 'lcup_selftest') { $r = lcup_selftest(); app_session_start(); $_SESSION['lcup_selftest'] = $r; audit('lcup.selftest', 'lcup'); done(safe_back(pstr('back'), $A . '#lcup')); }
        if ($action === 'lcup_run_now') { $r = lcup_run(['trigger' => 'manual']); app_session_start(); audit('lcup.run', 'lcup', null, ['ok' => $r['ok']]);
            done($A . '#lcup', !empty($r['busy']) ? "Boshqa sinxronizatsiya ishlayapti" : ($r['ok'] ? "Sinxronizatsiya tayyor: {$r['requests']} so'rov, {$r['new_events']} yangi hodisa" : "Xato: " . implode(' | ', $r['errors'])), !empty($r['busy']) || $r['ok'] ? 'ok' : 'error'); }
        $d = lcup_diagnostics(); app_session_start(); audit('lcup.diagnostics', 'lcup', null, ['files' => count($d['files'])]);
        done($d['dir'] ? 'lcup.php?diag=' . $d['dir'] . '#diag' : $A . '#lcup', $d['dir'] ? 'Diagnostika tayyor' : implode(' ', $d['lines']), $d['dir'] ? 'ok' : 'error');
    }
    if ($action === 'move_to_private') {
        $bk = db_backup('before_move'); [$ok, $msg] = security_move_to_private();
        if (session_status() === PHP_SESSION_ACTIVE) { flash_set($ok ? 'ok' : 'error', $msg); }
        header('Location: ' . $A . '#lcup'); exit();
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
