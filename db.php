<?php
try {
    $db_file = __DIR__ . '/harvards_finance.sqlite';
    $conn = new PDO('sqlite:' . $db_file);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Foydalanuvchilar (Staff/Admin)
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        role TEXT, -- 'admin' or 'staff'
        is_active INTEGER DEFAULT 1
    )");

    // 2. Xodimlar (Payroll uchun)
    $conn->exec("CREATE TABLE IF NOT EXISTS employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        position TEXT,
        salary REAL
    )");

    // 3. Kategoriyalar
    $conn->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        type TEXT
    )");

    // 4. Tranzaksiyalar
    $conn->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        category_id INTEGER,
        employee_id INTEGER DEFAULT NULL,
        payment REAL,
        cash INTEGER DEFAULT 0,
        click INTEGER DEFAULT 0,
        bank INTEGER DEFAULT 0,
        cash_in INTEGER DEFAULT 0,
        cash_out INTEGER DEFAULT 0,
        comment TEXT,
        date TEXT,
        is_modified INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 5. Tarix
    $conn->exec("CREATE TABLE IF NOT EXISTS transaction_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER,
        user_id INTEGER,
        field_name TEXT,
        old_value TEXT,
        new_value TEXT,
        changed_at TEXT
    )");

    // 6. Sozlamalar
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");

    // Admin yaratish
    $check = $conn->query("SELECT count(*) FROM users WHERE username='admin'")->fetchColumn();
    if ($check == 0) {
        $conn->exec("INSERT INTO users (username, password, role, is_active) VALUES ('admin', 'admin0007', 'admin', 1)");
    }

    // Default Kategoriyalar
    $checkCat = $conn->query("SELECT count(*) FROM categories")->fetchColumn();
    if ($checkCat == 0) {
        $conn->exec("INSERT INTO categories (name, type) VALUES ('Oylik Maosh', 'expense')");
        $conn->exec("INSERT INTO categories (name, type) VALUES ('Ijara', 'expense')");
        $conn->exec("INSERT INTO categories (name, type) VALUES ('To''lov', 'income')");
    }

    // Bank ustunini tekshirish (eski bazalar uchun)
    $cols = $conn->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_ASSOC);
    $has_bank = false;
    foreach($cols as $col) { if($col['name'] == 'bank') $has_bank = true; }
    if(!$has_bank) { $conn->exec("ALTER TABLE transactions ADD COLUMN bank INTEGER DEFAULT 0"); }

} catch(PDOException $e) {
    die("Bazaga ulanishda xatolik: " . $e->getMessage());
}
?>