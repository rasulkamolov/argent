<?php
session_start();
include 'db.php';
date_default_timezone_set('Asia/Tashkent');

$action = $_POST['action'] ?? $_GET['action'];
$date = date('Y-m-d');

// --- BUG FIX: Robust Number Cleaner ---
// Removes spaces, non-breaking spaces, and any non-numeric characters except dot
function clean_number($str) {
    // Replace standard space and non-breaking space (ASCII 160 / 0xA0)
    $str = str_replace([" ", "\xc2\xa0", "&nbsp;"], "", $str); 
    // Keep only numbers and dots
    return floatval(preg_replace('/[^\d.]/', '', $str));
}

// --- KIRISH (LOGIN) ---
if ($action == 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['password'] === $password) {
        if ($user['is_active'] == 0) {
            header("Location: index.php?error=Akkaunt faol emas");
            exit();
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header("Location: index.php");
    } else {
        header("Location: index.php?error=Login yoki parol xato");
    }
    exit();
}

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
$current_user_id = $_SESSION['user_id'];

// 1. TRANZAKSIYA QO'SHISH
if ($action == 'add_transaction') {
    $payment = clean_number($_POST['payment']); // Fixed cleaning
    $cat_id = $_POST['category_id'];
    $comment = $_POST['comment'];
    $method = $_POST['method']; 
    $type = $_POST['type']; 
    
    $emp_id = !empty($_POST['employee_id']) ? $_POST['employee_id'] : NULL;
    if ($emp_id) {
        $empName = $conn->query("SELECT name FROM employees WHERE id=$emp_id")->fetchColumn();
        $comment = $comment . " (Oylik: $empName)";
    }

    $cash = ($method == 'cash') ? 1 : 0;
    $click = ($method == 'click') ? 1 : 0;
    $bank = ($method == 'bank') ? 1 : 0;
    $cash_in = ($type == 'income') ? 1 : 0;
    $cash_out = ($type == 'expense') ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO transactions (user_id, category_id, employee_id, payment, cash, click, bank, cash_in, cash_out, comment, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$current_user_id, $cat_id, $emp_id, $payment, $cash, $click, $bank, $cash_in, $cash_out, $comment, $date]);
    
    header("Location: index.php");
}

// 2. STAFF BOSHQARUVI (Admin)
if ($_SESSION['role'] == 'admin') {
    if ($action == 'create_staff') {
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, is_active) VALUES (?, ?, 'staff', 1)");
        $stmt->execute([$_POST['username'], $_POST['password']]);
        header("Location: admin.php"); exit();
    }
    if ($action == 'update_staff') {
        $stmt = $conn->prepare("UPDATE users SET username = ?, password = ? WHERE id = ? AND role != 'admin'");
        $stmt->execute([$_POST['username'], $_POST['password'], $_POST['id']]);
        header("Location: admin.php"); exit();
    }
    if ($action == 'delete_staff') {
        $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")->execute([$_GET['id']]);
        header("Location: admin.php"); exit();
    }
    if ($action == 'toggle_staff') {
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$_GET['status'], $_GET['id']]);
        header("Location: admin.php"); exit();
    }
}

// 3. XODIMLAR BOSHQARUVI (Payroll)
if ($_SESSION['role'] == 'admin') {
    if ($action == 'create_employee') {
        $stmt = $conn->prepare("INSERT INTO employees (name, position, salary) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['position'], clean_number($_POST['salary'])]);
        header("Location: admin.php");
    }
    if ($action == 'update_employee') {
        $stmt = $conn->prepare("UPDATE employees SET name = ?, position = ?, salary = ? WHERE id = ?");
        $stmt->execute([$_POST['name'], $_POST['position'], clean_number($_POST['salary']), $_POST['id']]);
        header("Location: admin.php");
    }
    if ($action == 'delete_employee') {
        $conn->prepare("DELETE FROM employees WHERE id = ?")->execute([$_GET['id']]);
        header("Location: admin.php");
    }
}

// 4. ADMIN: SOZLAMALAR
if ($action == 'update_setting' && $_SESSION['role'] == 'admin') {
    $stmt = $conn->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute([$_POST['key'], $_POST['value']]);
    header("Location: admin.php"); exit();
}

// 4b. ADMIN: Login/Parol o'zgartirish
if ($action == 'change_admin_credentials' && $_SESSION['role'] == 'admin') {
    $new_user = trim($_POST['new_username'] ?? '');
    $new_pass = trim($_POST['new_password'] ?? '');
    $cur_pass = trim($_POST['current_password'] ?? '');
    // Verify current password
    $check = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $check->execute([$_SESSION['user_id']]);
    $cur = $check->fetchColumn();
    if ($cur !== $cur_pass) {
        header("Location: admin.php?cred_error=Hozirgi+parol+noto%27g%27ri"); exit();
    }
    $updates = [];
    $params  = [];
    if ($new_user !== '') { $updates[] = "username = ?"; $params[] = $new_user; }
    if ($new_pass !== '') { $updates[] = "password = ?"; $params[] = $new_pass; }
    if (!empty($updates)) {
        $params[] = $_SESSION['user_id'];
        $conn->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
        if ($new_user !== '') $_SESSION['username'] = $new_user;
    }
    header("Location: admin.php?cred_ok=1"); exit();
}

// 5. KATEGORIYALAR
if ($action == 'add_category' && $_SESSION['role'] == 'admin') {
    $stmt = $conn->prepare("INSERT INTO categories (name, type) VALUES (?, ?)");
    $stmt->execute([$_POST['name'], $_POST['type']]);
    header("Location: admin.php");
}
if ($action == 'delete_category' && $_SESSION['role'] == 'admin') {
    $conn->prepare("DELETE FROM categories WHERE id = ?")->execute([$_GET['id']]);
    header("Location: admin.php");
}

// 6. TRANZAKSIYA O'CHIRISH
if ($action == 'delete_transaction') {
    $allowed = false;
    if ($_SESSION['role'] == 'admin') {
        $allowed = true;
    } elseif ($_SESSION['role'] == 'staff') {
        $setting = $conn->query("SELECT value FROM settings WHERE key='staff_can_delete'")->fetchColumn();
        $allowed = ($setting === '1');
    }
    if ($allowed) {
        $conn->prepare("DELETE FROM transactions WHERE id = ?")->execute([$_GET['id']]);
    }
    header("Location: index.php");
    exit();
}

// 7. NAMUNAVIY MA'LUMOTLAR (Sample Data)
if ($action == 'load_sample_data' && $_SESSION['role'] == 'admin') {
    // Mark sample transactions with comment prefix
    $cats = $conn->query("SELECT id, type FROM categories")->fetchAll(PDO::FETCH_ASSOC);
    $income_cats = array_filter($cats, fn($c) => $c['type'] === 'income');
    $expense_cats = array_filter($cats, fn($c) => $c['type'] === 'expense');
    $income_cat_ids = array_column($income_cats, 'id');
    $expense_cat_ids = array_column($expense_cats, 'id');
    if (empty($income_cat_ids)) $income_cat_ids = [null];
    if (empty($expense_cat_ids)) $expense_cat_ids = [null];

    $uid = $_SESSION['user_id'];
    $samples = [];
    // Generate 30 days of sample data
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        // 1-3 incomes per day
        $n = rand(1, 3);
        for ($j = 0; $j < $n; $j++) {
            $cat = $income_cat_ids[array_rand($income_cat_ids)];
            $amt = rand(5, 50) * 10000;
            $m = rand(0,2); $cash=0;$click=0;$bank=0;
            if($m==0) $cash=1; elseif($m==1) $click=1; else $bank=1;
            $samples[] = [$uid,$cat,null,$amt,$cash,$click,$bank,1,0,'[NAMUNA] Sinov kirim',$d];
        }
        // 1-2 expenses per day
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
    foreach ($samples as $s) { $stmt->execute($s); }
    header("Location: admin.php?sample_ok=1"); exit();
}

if ($action == 'delete_sample_data' && $_SESSION['role'] == 'admin') {
    $conn->exec("DELETE FROM transactions WHERE comment LIKE '[NAMUNA]%'");
    header("Location: admin.php?sample_del=1"); exit();
}
?>