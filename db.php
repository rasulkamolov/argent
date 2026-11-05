<?php
/**
 * Database bootstrap + versioned migrations (SQLite).
 * Safe to run on every request: migrations only run once (PRAGMA user_version).
 * Works with old single-branch databases: existing data is moved into the
 * default branch "OXFORD Bo'zqal'a" automatically.
 */
require_once __DIR__ . '/compat.php';

if (!defined('DB_FILE')) {
    define('DB_FILE', getenv('ARGENT_DB') ?: (__DIR__ . '/harvards_finance.sqlite'));
}
if (!defined('DEFAULT_BRANCH_NAME')) define('DEFAULT_BRANCH_NAME', "OXFORD Bo'zqal'a");
const DB_SCHEMA_VERSION = 5;   // v2: multi-branch, v3: branches.balance_ok, v4: transactions.proof, v5: transactions.proof_name

function db_has_column(PDO $c, string $table, string $col): bool {
    foreach ($c->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (strcasecmp($r['name'], $col) === 0) return true;
    }
    return false;
}
function db_add_column(PDO $c, string $table, string $col, string $def): void {
    if (!db_has_column($c, $table, $col)) $c->exec("ALTER TABLE $table ADD COLUMN $col $def");
}

function db_migrate(PDO $c): void {
    /* ---- legacy (v1) tables: unchanged structure, created if missing ---- */
    $c->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        role TEXT,
        is_active INTEGER DEFAULT 1
    )");
    $c->exec("CREATE TABLE IF NOT EXISTS employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, position TEXT, salary REAL
    )");
    $c->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, type TEXT
    )");
    $c->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER, category_id INTEGER, employee_id INTEGER DEFAULT NULL,
        payment REAL, cash INTEGER DEFAULT 0, click INTEGER DEFAULT 0, bank INTEGER DEFAULT 0,
        cash_in INTEGER DEFAULT 0, cash_out INTEGER DEFAULT 0,
        comment TEXT, date TEXT, is_modified INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $c->exec("CREATE TABLE IF NOT EXISTS transaction_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER, user_id INTEGER, field_name TEXT,
        old_value TEXT, new_value TEXT, changed_at TEXT
    )");
    $c->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
    db_add_column($c, 'transactions', 'bank', 'INTEGER DEFAULT 0');

    $ver = (int)$c->query("PRAGMA user_version")->fetchColumn();
    if ($ver >= DB_SCHEMA_VERSION) return;

    /* ---- v2: multi-branch ---- */
    $c->exec("BEGIN IMMEDIATE");
    try {
        $c->exec("CREATE TABLE IF NOT EXISTS branches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            address TEXT DEFAULT '',
            phone TEXT DEFAULT '',
            note TEXT DEFAULT '',
            color INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            open_cash REAL DEFAULT 0,
            open_click REAL DEFAULT 0,
            open_bank REAL DEFAULT 0,
            balance_ok INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $defId = $c->query("SELECT id FROM branches ORDER BY id LIMIT 1")->fetchColumn();
        if (!$defId) {
            $c->prepare("INSERT INTO branches (name, color, sort_order) VALUES (?, 0, 1)")->execute([DEFAULT_BRANCH_NAME]);
            $defId = (int)$c->lastInsertId();
        }

        db_add_column($c, 'branches', 'balance_ok', 'INTEGER DEFAULT 0');   // 1 = admin confirmed real cash balances
        db_add_column($c, 'users', 'branch_id', 'INTEGER');
        db_add_column($c, 'users', 'full_name', "TEXT DEFAULT ''");
        db_add_column($c, 'users', 'phone', "TEXT DEFAULT ''");
        db_add_column($c, 'users', 'created_at', 'TEXT');
        db_add_column($c, 'users', 'last_login_at', 'TEXT');
        db_add_column($c, 'transactions', 'branch_id', 'INTEGER');
        db_add_column($c, 'transactions', 'ext_ref', 'TEXT');
        db_add_column($c, 'transactions', 'proof', 'TEXT');
        db_add_column($c, 'transactions', 'proof_name', 'TEXT');   // original file name (shown / used when downloading)      // relative path of the payment screenshot (data/proofs/...)
        db_add_column($c, 'employees', 'branch_id', 'INTEGER');
        db_add_column($c, 'employees', 'phone', "TEXT DEFAULT ''");
        db_add_column($c, 'categories', 'branch_id', 'INTEGER');   // NULL = shared by all branches

        // existing data belongs to the default branch
        $c->prepare("UPDATE transactions SET branch_id = ? WHERE branch_id IS NULL")->execute([$defId]);
        $c->prepare("UPDATE employees SET branch_id = ? WHERE branch_id IS NULL")->execute([$defId]);
        $c->prepare("UPDATE users SET branch_id = ? WHERE branch_id IS NULL AND role <> 'admin'")->execute([$defId]);
        $c->exec("UPDATE users SET created_at = CURRENT_TIMESTAMP WHERE created_at IS NULL");

        $c->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER, username TEXT, branch_id INTEGER,
            action TEXT NOT NULL, entity TEXT, entity_id INTEGER,
            details TEXT, ip TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS cash_counts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL, date TEXT NOT NULL, method TEXT DEFAULT 'cash',
            expected REAL DEFAULT 0, counted REAL DEFAULT 0, diff REAL DEFAULT 0,
            note TEXT DEFAULT '', user_id INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT, username TEXT, ok INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $c->exec("CREATE INDEX IF NOT EXISTS idx_tx_branch_date ON transactions(branch_id, date)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_tx_date ON transactions(date)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_tx_user ON transactions(user_id)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_tx_cat ON transactions(category_id)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_tx_emp ON transactions(employee_id)");
        $c->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tx_ext ON transactions(branch_id, ext_ref) WHERE ext_ref IS NOT NULL");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_hist_tx ON transaction_history(transaction_id)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_log(created_at)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_audit_branch ON audit_log(branch_id)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_counts_branch ON cash_counts(branch_id, date)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_login_time ON login_attempts(created_at)");

        // screenshots are only demanded for transactions dated from the day this feature went live
        $c->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('proof_since', ?)")->execute([date('Y-m-d')]);
        $c->exec("PRAGMA user_version = " . DB_SCHEMA_VERSION);
        $c->exec("COMMIT");
    } catch (Throwable $e) {
        try { $c->exec("ROLLBACK"); } catch (Throwable $e2) {}
        throw $e;
    }
}

function db_seed_defaults(PDO $c): void {
    // first install: default admin (password is hashed) + starter categories
    if ((int)$c->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() === 0) {
        $c->prepare("INSERT INTO users (username, password, role, is_active, created_at) VALUES ('admin', ?, 'admin', 1, CURRENT_TIMESTAMP)")
          ->execute([password_hash('admin0007', PASSWORD_DEFAULT)]);
    }
    if ((int)$c->query("SELECT COUNT(*) FROM categories")->fetchColumn() === 0) {
        $ins = $c->prepare("INSERT INTO categories (name, type) VALUES (?, ?)");
        foreach ([['Oylik Maosh', 'expense'], ['Ijara', 'expense'], ["To'lov", 'income']] as $r) $ins->execute($r);
    }
}

try {
    $db_file = DB_FILE;
    $conn = new PDO('sqlite:' . DB_FILE);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->exec("PRAGMA busy_timeout = 8000");
    db_migrate($conn);
    db_seed_defaults($conn);
} catch (Throwable $e) {
    error_log('DB error: ' . $e->getMessage());
    http_response_code(500);
    die("Bazaga ulanishda xatolik. Fayl ruxsatlarini tekshiring (harvards_finance.sqlite va uning papkasi yozish uchun ochiq bo'lishi kerak).");
}
