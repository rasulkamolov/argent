<?php
/**
 * Database bootstrap + versioned migrations (SQLite).
 * Safe to run on every request: migrations only run once (PRAGMA user_version).
 * Works with old single-branch databases: existing data is moved into the
 * default branch "OXFORD Bo'zqal'a" automatically.
 */
require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/paths.php';

if (!defined('DB_FILE')) {
    define('DB_FILE', db_resolve_file());
}
if (!defined('DEFAULT_BRANCH_NAME')) define('DEFAULT_BRANCH_NAME', "OXFORD Bo'zqal'a");
const DB_SCHEMA_VERSION = 7;   // v2: multi-branch, v3: branches.balance_ok, v4: transactions.proof, v5: transactions.proof_name, v6: LC-UP monitoring, v7: LC-UP whitelist log, charges, gaps

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

        /* ---- v6: LC-UP monitoring (append-only event feed + snapshots) ---- */
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_branch_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL UNIQUE,      -- Argent branch
            lcup_key TEXT DEFAULT '',               -- value of the LC-UP branch dropdown (e.g. 414)
            lcup_name TEXT DEFAULT '',              -- LC-UP branch name (used to find the key when it is empty)
            is_active INTEGER DEFAULT 1
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            detected_at TEXT NOT NULL,              -- when Argent noticed it (local time)
            lcup_time TEXT,                         -- time shown by LC-UP (local time), NULL if unknown
            event_date TEXT,                        -- date used for filtering (LC-UP time, else detected)
            branch_id INTEGER, source TEXT, type TEXT,
            staff TEXT, student_id TEXT, student_name TEXT, group_name TEXT,
            amount REAL, method TEXT,
            before_json TEXT, after_json TEXT, details TEXT, raw TEXT,
            severity TEXT DEFAULT 'info',           -- 'red' | 'info'
            flags TEXT DEFAULT '',                  -- comma separated flag codes
            backfill INTEGER DEFAULT 0,             -- 1 = historical (no alert, not counted as unread)
            dedupe_key TEXT NOT NULL UNIQUE,
            alerted_at TEXT,
            reviewed_at TEXT, reviewed_by INTEGER, review_note TEXT
        )");
        // Append-only: nothing can be deleted; only the review mark (once) and the alert time (once) may be written.
        $c->exec("CREATE TRIGGER IF NOT EXISTS lcup_events_nodelete BEFORE DELETE ON lcup_events
            BEGIN SELECT RAISE(ABORT, 'lcup_events: yozuvlarni o''chirib bo''lmaydi'); END");
        $c->exec("CREATE TRIGGER IF NOT EXISTS lcup_events_noupdate BEFORE UPDATE ON lcup_events
            WHEN OLD.id IS NOT NEW.id OR OLD.detected_at IS NOT NEW.detected_at OR OLD.lcup_time IS NOT NEW.lcup_time OR OLD.event_date IS NOT NEW.event_date
              OR OLD.branch_id IS NOT NEW.branch_id OR OLD.source IS NOT NEW.source OR OLD.type IS NOT NEW.type OR OLD.staff IS NOT NEW.staff
              OR OLD.student_id IS NOT NEW.student_id OR OLD.student_name IS NOT NEW.student_name OR OLD.group_name IS NOT NEW.group_name
              OR OLD.amount IS NOT NEW.amount OR OLD.method IS NOT NEW.method OR OLD.before_json IS NOT NEW.before_json OR OLD.after_json IS NOT NEW.after_json
              OR OLD.details IS NOT NEW.details OR OLD.raw IS NOT NEW.raw OR OLD.severity IS NOT NEW.severity OR OLD.flags IS NOT NEW.flags
              OR OLD.backfill IS NOT NEW.backfill OR OLD.dedupe_key IS NOT NEW.dedupe_key
              OR (OLD.reviewed_at IS NOT NULL AND (NEW.reviewed_at IS NOT OLD.reviewed_at OR NEW.reviewed_by IS NOT OLD.reviewed_by OR NEW.review_note IS NOT OLD.review_note))
              OR (OLD.alerted_at IS NOT NULL AND NEW.alerted_at IS NOT OLD.alerted_at)
            BEGIN SELECT RAISE(ABORT, 'lcup_events: yozuvlarni o''zgartirib bo''lmaydi'); END");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcev_time ON lcup_events(detected_at)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcev_branch ON lcup_events(branch_id, event_date)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcev_unread ON lcup_events(severity, reviewed_at, backfill)");
        // activity-log mirror: every "to'lov yaratildi" etc. with the ORIGINAL amount shown by LC-UP at that moment
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_acts (
            branch_id INTEGER NOT NULL, act_id TEXT NOT NULL,           -- LC-UP activity id (xf-row-<id>)
            staff TEXT, act_time TEXT, act_text TEXT, type TEXT,
            student_id TEXT, student_name TEXT, group_id TEXT, group_name TEXT,
            amount REAL,                                                -- amount in the log = the ORIGINAL amount for a created payment
            pay_id TEXT, state TEXT DEFAULT 'open',                     -- open | paired | deleted | orphan
            cur_amount REAL, alert_amount REAL, first_seen TEXT, paired_at TEXT, backfill INTEGER DEFAULT 0,
            PRIMARY KEY (branch_id, act_id)
        )");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcact_time ON lcup_acts(branch_id, act_time)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcact_state ON lcup_acts(branch_id, state, type)");
        // snapshot of the live payment list (/admin/tolovlars), keyed by LC-UP payment id
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL, pay_id TEXT NOT NULL,
            student_id TEXT, student_name TEXT, group_name TEXT, teacher TEXT,
            amount REAL, pay_date TEXT,             -- pay_date = billing period shown by LC-UP (NOT the day money arrived)
            method TEXT, status TEXT, staff TEXT,
            created_at TEXT,                        -- creation timestamp when the list shows one
            first_seen TEXT, last_seen TEXT, version INTEGER DEFAULT 1,
            missing_since TEXT, miss_n INTEGER DEFAULT 0, deleted_at TEXT, delete_info TEXT, act_id TEXT, baseline INTEGER DEFAULT 0,
            UNIQUE (branch_id, pay_id)
        )");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcpay_name ON lcup_payments(branch_id, student_name)");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_archive (
            branch_id INTEGER NOT NULL, pay_id TEXT NOT NULL,
            student_name TEXT, amount REAL, pay_date TEXT, method TEXT, teacher TEXT, group_name TEXT,
            staff TEXT, deleted_at TEXT, reason TEXT, first_seen TEXT, baseline INTEGER DEFAULT 0,
            PRIMARY KEY (branch_id, pay_id)
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_discounts (
            branch_id INTEGER NOT NULL, d_key TEXT NOT NULL, student_id TEXT, student_name TEXT, group_name TEXT,
            value TEXT, num REAL, reason TEXT, staff TEXT, first_seen TEXT, last_seen TEXT,
            PRIMARY KEY (branch_id, d_key)
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_debts (
            branch_id INTEGER NOT NULL, d_key TEXT NOT NULL, student_id TEXT, student_name TEXT, debt REAL, updated_at TEXT,
            PRIMARY KEY (branch_id, d_key)
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_state (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, started_at TEXT, finished_at TEXT, ok INTEGER, trigger TEXT,
            requests INTEGER DEFAULT 0, new_events INTEGER DEFAULT 0, seconds REAL, message TEXT
        )");
        /* ---- v7: request log of everything sent to LC-UP, monthly-charge snapshot, outages ---- */
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_reqlog (
            id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT, method TEXT, path TEXT, purpose TEXT, detail TEXT, status INTEGER, ms INTEGER
        )");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcreq_at ON lcup_reqlog(at)");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_charges (
            branch_id INTEGER NOT NULL, student_id TEXT NOT NULL, ckey TEXT NOT NULL,
            amount REAL, pay_date TEXT, status TEXT, group_name TEXT, staff TEXT, created_at TEXT, first_seen TEXT, last_seen TEXT,
            PRIMARY KEY (branch_id, student_id, ckey)
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_student_ids (
            branch_id INTEGER NOT NULL, student_id TEXT NOT NULL, name TEXT DEFAULT '', last_check TEXT,
            PRIMARY KEY (branch_id, student_id)
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_gaps (
            id INTEGER PRIMARY KEY AUTOINCREMENT, from_at TEXT NOT NULL, to_at TEXT NOT NULL, filled INTEGER DEFAULT 0, note TEXT
        )");
        db_add_column($c, 'lcup_debts', 'group_name', "TEXT DEFAULT ''");

        // suggested mapping (editable in Admin → LC-UP): Oxford LC (414) = Yangibozor Markaz, Oxford Bo'zqal'a = OXFORD Bo'zqal'a
        if ((int)$c->query("SELECT COUNT(*) FROM lcup_branch_map")->fetchColumn() === 0) {
            $m = $c->prepare("INSERT OR IGNORE INTO lcup_branch_map (branch_id, lcup_key, lcup_name) VALUES (?,?,?)");
            $yb = $c->query("SELECT id FROM branches WHERE name = 'Yangibozor Markaz'")->fetchColumn();
            $ox = $c->query("SELECT id FROM branches WHERE name LIKE 'OXFORD%' ORDER BY id LIMIT 1")->fetchColumn();
            if ($yb) $m->execute([$yb, '414', 'Oxford LC']);
            if ($ox) $m->execute([$ox, '', "Oxford Bo'zqal'a"]);
        }

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
