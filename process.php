<?php
session_start();
include 'db.php';
date_default_timezone_set('Asia/Tashkent');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$date = date('Y-m-d');

// Removes spaces, non-breaking spaces and any non-numeric characters except dot
function clean_number($str) {
    $str = str_replace([" ", "\xc2\xa0", "&nbsp;"], "", (string)$str);
    return floatval(preg_replace('/[^\d.]/', '', $str));
}

/** Set a flash message (shown as a toast on the next page) and redirect. */
function done(string $to, string $msg = '', string $type = 'ok') {
    if ($msg !== '') $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header("Location: $to");
    exit();
}

// --- KIRISH (LOGIN) ---
if ($action == 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['password'] === $password) {
        if ($user['is_active'] == 0) {
            $_SESSION['login_error'] = "Akkaunt faol emas. Administrator bilan bog'laning.";
            $_SESSION['login_user'] = $username;
            header("Location: index.php"); exit();
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header("Location: index.php");
    } else {
        $_SESSION['login_error'] = "Login yoki parol xato";
        $_SESSION['login_user'] = $username;
        header("Location: index.php");
    }
    exit();
}

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] ?? '') === 'admin';

// 1. TRANZAKSIYA QO'SHISH
if ($action == 'add_transaction') {
    $payment = clean_number($_POST['payment'] ?? '');
    $cat_id = (int)($_POST['category_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $method = $_POST['method'] ?? 'cash';
    $type = $_POST['type'] ?? 'income';

    if ($payment <= 0) done('index.php', "Summani kiriting", 'error');

    $emp_id = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : NULL;
    if ($emp_id) {
        $q = $conn->prepare("SELECT name FROM employees WHERE id = ?");
        $q->execute([$emp_id]);
        $empName = $q->fetchColumn();
        if ($empName) $comment = trim($comment . " (Oylik: $empName)");
        else $emp_id = NULL;
    }

    $cash = ($method == 'cash') ? 1 : 0;
    $click = ($method == 'click') ? 1 : 0;
    $bank = ($method == 'bank') ? 1 : 0;
    $cash_in = ($type == 'income') ? 1 : 0;
    $cash_out = ($type == 'expense') ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO transactions (user_id, category_id, employee_id, payment, cash, click, bank, cash_in, cash_out, comment, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$current_user_id, $cat_id ?: null, $emp_id, $payment, $cash, $click, $bank, $cash_in, $cash_out, $comment, $date]);

    done('index.php', ($cash_in ? "Kirim" : "Chiqim") . " saqlandi: " . ($cash_in ? '+' : '−') . number_format($payment, 0, '.', ' '));
}

// 2. STAFF BOSHQARUVI (Admin)
if ($is_admin) {
    if ($action == 'create_staff') {
        $u = trim($_POST['username'] ?? ''); $p = $_POST['password'] ?? '';
        if ($u === '' || $p === '') done('admin.php#staff', "Login va parolni kiriting", 'error');
        try {
            $conn->prepare("INSERT INTO users (username, password, role, is_active) VALUES (?, ?, 'staff', 1)")->execute([$u, $p]);
        } catch (PDOException $ex) {
            done('admin.php#staff', "Bu login band: $u", 'error');
        }
        done('admin.php#staff', "Yangi staff qo'shildi: $u");
    }
    if ($action == 'update_staff') {
        $u = trim($_POST['username'] ?? ''); $p = $_POST['password'] ?? '';
        if ($u === '') done('admin.php#staff', "Login bo'sh bo'lmasligi kerak", 'error');
        try {
            if ($p !== '') {
                $conn->prepare("UPDATE users SET username = ?, password = ? WHERE id = ? AND role != 'admin'")->execute([$u, $p, $_POST['id']]);
            } else { // parol bo'sh bo'lsa o'zgarmaydi
                $conn->prepare("UPDATE users SET username = ? WHERE id = ? AND role != 'admin'")->execute([$u, $_POST['id']]);
            }
        } catch (PDOException $ex) {
            done('admin.php#staff', "Bu login band: $u", 'error');
        }
        done('admin.php#staff', "Staff ma'lumotlari yangilandi");
    }
    if ($action == 'delete_staff') {
        $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")->execute([$_REQUEST['id'] ?? 0]);
        done('admin.php#staff', "Staff o'chirildi");
    }
    if ($action == 'toggle_staff') {
        $st = ($_REQUEST['status'] ?? '1') === '0' ? 0 : 1;
        $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role != 'admin'")->execute([$st, $_REQUEST['id'] ?? 0]);
        done('admin.php#staff', $st ? "Staff faollashtirildi" : "Staff bloklandi");
    }

    // 3. XODIMLAR BOSHQARUVI (Payroll)
    if ($action == 'create_employee') {
        $conn->prepare("INSERT INTO employees (name, position, salary) VALUES (?, ?, ?)")
             ->execute([trim($_POST['name'] ?? ''), trim($_POST['position'] ?? ''), clean_number($_POST['salary'] ?? '')]);
        done('admin.php#employees', "Yangi xodim qo'shildi");
    }
    if ($action == 'update_employee') {
        $conn->prepare("UPDATE employees SET name = ?, position = ?, salary = ? WHERE id = ?")
             ->execute([trim($_POST['name'] ?? ''), trim($_POST['position'] ?? ''), clean_number($_POST['salary'] ?? ''), $_POST['id'] ?? 0]);
        done('admin.php#employees', "Xodim ma'lumotlari yangilandi");
    }
    if ($action == 'delete_employee') {
        $conn->prepare("DELETE FROM employees WHERE id = ?")->execute([$_REQUEST['id'] ?? 0]);
        done('admin.php#employees', "Xodim o'chirildi");
    }

    // 4. SOZLAMALAR
    if ($action == 'update_setting') {
        $key = $_POST['key'] ?? '';
        if (in_array($key, ['staff_can_edit', 'staff_can_delete'], true)) {
            $conn->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$key, ($_POST['value'] ?? '0') === '1' ? '1' : '0']);
            done('admin.php#settings', "Ruxsat yangilandi");
        }
        done('admin.php#settings');
    }

    // 4b. Login/Parol o'zgartirish
    if ($action == 'change_admin_credentials') {
        $new_user = trim($_POST['new_username'] ?? '');
        $new_pass = trim($_POST['new_password'] ?? '');
        $cur_pass = trim($_POST['current_password'] ?? '');
        $check = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $check->execute([$_SESSION['user_id']]);
        if ($check->fetchColumn() !== $cur_pass) done('admin.php#settings', "Hozirgi parol noto'g'ri", 'error');
        $updates = []; $params = [];
        if ($new_user !== '') { $updates[] = "username = ?"; $params[] = $new_user; }
        if ($new_pass !== '') { $updates[] = "password = ?"; $params[] = $new_pass; }
        if (!empty($updates)) {
            $params[] = $_SESSION['user_id'];
            try {
                $conn->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            } catch (PDOException $ex) {
                done('admin.php#settings', "Bu login band", 'error');
            }
            if ($new_user !== '') $_SESSION['username'] = $new_user;
        }
        done('admin.php#settings', "Login yoki parol muvaffaqiyatli yangilandi");
    }

    // 5. KATEGORIYALAR
    if ($action == 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') done('admin.php#categories', "Nomini kiriting", 'error');
        $conn->prepare("INSERT INTO categories (name, type) VALUES (?, ?)")->execute([$name, ($_POST['type'] ?? '') === 'expense' ? 'expense' : 'income']);
        done('admin.php#categories', "Kategoriya qo'shildi: $name");
    }
    if ($action == 'delete_category') {
        $conn->prepare("DELETE FROM categories WHERE id = ?")->execute([$_REQUEST['id'] ?? 0]);
        done('admin.php#categories', "Kategoriya o'chirildi");
    }
}

// 6. TRANZAKSIYA O'CHIRISH
if ($action == 'delete_transaction') {
    $allowed = false;
    if ($is_admin) {
        $allowed = true;
    } elseif (($_SESSION['role'] ?? '') == 'staff') {
        $setting = $conn->query("SELECT value FROM settings WHERE key='staff_can_delete'")->fetchColumn();
        $allowed = ($setting === '1');
    }
    $back = 'index.php';
    if ($allowed) {
        $id = $_REQUEST['id'] ?? 0;
        $d = $conn->prepare("SELECT date FROM transactions WHERE id = ?"); $d->execute([$id]);
        $tdate = $d->fetchColumn();
        if ($tdate) $back = 'index.php?date=' . urlencode($tdate);
        $conn->prepare("DELETE FROM transactions WHERE id = ?")->execute([$id]);
        done($back, "Tranzaksiya o'chirildi");
    }
    done($back, "O'chirish uchun ruxsat yo'q", 'error');
}

// 7. NAMUNAVIY MA'LUMOTLAR (Sample Data)
if ($action == 'load_sample_data' && $is_admin) {
    $cats = $conn->query("SELECT id, type FROM categories")->fetchAll(PDO::FETCH_ASSOC);
    $income_cat_ids = array_column(array_filter($cats, fn($c) => $c['type'] === 'income'), 'id');
    $expense_cat_ids = array_column(array_filter($cats, fn($c) => $c['type'] === 'expense'), 'id');
    if (empty($income_cat_ids)) $income_cat_ids = [null];
    if (empty($expense_cat_ids)) $expense_cat_ids = [null];

    $uid = $_SESSION['user_id'];
    $samples = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $n = rand(1, 3);
        for ($j = 0; $j < $n; $j++) {
            $cat = $income_cat_ids[array_rand($income_cat_ids)];
            $amt = rand(5, 50) * 10000;
            $m = rand(0,2); $cash=0;$click=0;$bank=0;
            if($m==0) $cash=1; elseif($m==1) $click=1; else $bank=1;
            $samples[] = [$uid,$cat,null,$amt,$cash,$click,$bank,1,0,'[NAMUNA] Sinov kirim',$d];
        }
        $n2 = rand(1, 2);
        for ($j = 0; $j < $n2; $j++) {
            $cat = $expense_cat_ids[array_rand($expense_cat_ids)];
            $amt = rand(2, 30) * 10000;
            $m = rand(0,2); $cash=0;$click=0;$bank=0;
            if($m==0) $cash=1; elseif($m==1) $click=1; else $bank=1;
            $samples[] = [$uid,$cat,null,$amt,$cash,$click,$bank,0,1,'[NAMUNA] Sinov chiqim',$d];
        }
    }
    $stmt = $conn->prepare("INSERT INTO transactions (user_id,category_id,employee_id,payment,cash,click,bank,cash_in,cash_out,comment,date) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $conn->beginTransaction();
    foreach ($samples as $s) { $stmt->execute($s); }
    $conn->commit();
    done('admin.php#settings', "Namunaviy ma'lumotlar yuklandi (30 kunlik)");
}

if ($action == 'delete_sample_data' && $is_admin) {
    $conn->exec("DELETE FROM transactions WHERE comment LIKE '[NAMUNA]%'");
    done('admin.php#settings', "Barcha namunaviy ma'lumotlar o'chirildi");
}

// Noma'lum yoki ruxsat etilmagan amal
header("Location: index.php");
exit();
