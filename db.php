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
const DB_SCHEMA_VERSION = 10;  // v10: LC-UP incidents/corrections/group map/debt snapshots, v9: Do'konPro monitoring, v2: multi-branch, v3: balance_ok, v4/v5: proof, v6: LC-UP monitoring, v7: whitelist log, charges, gaps, v8: student history (minus-payment edits)

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

        /* ---- v8: student "Tarix" rows (who edited which charge, when) used to identify "minus to'lov tahrirlandi" log rows ---- */
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_history (
            branch_id INTEGER NOT NULL, student_id TEXT NOT NULL, hkey TEXT NOT NULL,
            text TEXT, group_name TEXT, amount REAL, staff TEXT, at TEXT, first_seen TEXT,
            PRIMARY KEY (branch_id, student_id, hkey)
        )");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lchist_at ON lcup_history(branch_id, at)");
        db_add_column($c, 'lcup_student_ids', 'last_hist_check', 'TEXT');
        db_add_column($c, 'lcup_history', 'act_id', 'TEXT');       // the log row this history row was matched to (one history row identifies one log row)

        // suggested mapping (editable in Admin → LC-UP): Oxford LC (414) = Yangibozor Markaz, Oxford Bo'zqal'a = OXFORD Bo'zqal'a
        if ((int)$c->query("SELECT COUNT(*) FROM lcup_branch_map")->fetchColumn() === 0) {
            $m = $c->prepare("INSERT OR IGNORE INTO lcup_branch_map (branch_id, lcup_key, lcup_name) VALUES (?,?,?)");
            $yb = $c->query("SELECT id FROM branches WHERE name = 'Yangibozor Markaz'")->fetchColumn();
            $ox = $c->query("SELECT id FROM branches WHERE name LIKE 'OXFORD%' ORDER BY id LIMIT 1")->fetchColumn();
            if ($yb) $m->execute([$yb, '414', 'Oxford LC']);
            if ($ox) $m->execute([$ox, '', "Oxford Bo'zqal'a"]);
        }

        /* ---- v9: Do'konPro (shop) monitoring. The shop database itself is only ever READ (mode=ro); everything below lives in Argent's own DB. ---- */
        $c->exec("CREATE TABLE IF NOT EXISTS shop_branch_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL UNIQUE,      -- Argent branch
            shop_branch_id INTEGER,                 -- Do'konPro branches.id (empty = found by name)
            shop_name TEXT DEFAULT '',              -- Do'konPro branch name
            is_active INTEGER DEFAULT 1
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS shop_state (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)");
        $c->exec("CREATE TABLE IF NOT EXISTS shop_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            detected_at TEXT NOT NULL,              -- when Argent noticed it (local time)
            event_time TEXT,                        -- when it happened according to the shop (local time), NULL if unknown
            event_date TEXT,
            branch_id INTEGER, shop_branch_id INTEGER,
            type TEXT, staff TEXT, ref TEXT, ref_key TEXT,   -- ref = human reference (chek #12), ref_key = machine key (sale:77)
            amount REAL, details TEXT, before_json TEXT, after_json TEXT,
            severity TEXT DEFAULT 'info',           -- 'red' | 'yellow' | 'info'
            flags TEXT DEFAULT '',
            backfill INTEGER DEFAULT 0,
            dedupe_key TEXT NOT NULL UNIQUE,
            alerted_at TEXT,
            reviewed_at TEXT, reviewed_by INTEGER, review_note TEXT
        )");
        $c->exec("CREATE TRIGGER IF NOT EXISTS shop_events_nodelete BEFORE DELETE ON shop_events
            BEGIN SELECT RAISE(ABORT, 'shop_events: yozuvlarni o''chirib bo''lmaydi'); END");
        $c->exec("CREATE TRIGGER IF NOT EXISTS shop_events_noupdate BEFORE UPDATE ON shop_events
            WHEN OLD.id IS NOT NEW.id OR OLD.detected_at IS NOT NEW.detected_at OR OLD.event_time IS NOT NEW.event_time OR OLD.event_date IS NOT NEW.event_date
              OR OLD.branch_id IS NOT NEW.branch_id OR OLD.shop_branch_id IS NOT NEW.shop_branch_id OR OLD.type IS NOT NEW.type OR OLD.staff IS NOT NEW.staff
              OR OLD.ref IS NOT NEW.ref OR OLD.ref_key IS NOT NEW.ref_key OR OLD.amount IS NOT NEW.amount OR OLD.details IS NOT NEW.details
              OR OLD.before_json IS NOT NEW.before_json OR OLD.after_json IS NOT NEW.after_json OR OLD.severity IS NOT NEW.severity OR OLD.flags IS NOT NEW.flags
              OR OLD.backfill IS NOT NEW.backfill OR OLD.dedupe_key IS NOT NEW.dedupe_key
              OR (OLD.reviewed_at IS NOT NULL AND (NEW.reviewed_at IS NOT OLD.reviewed_at OR NEW.reviewed_by IS NOT OLD.reviewed_by OR NEW.review_note IS NOT OLD.review_note))
              OR (OLD.alerted_at IS NOT NULL AND NEW.alerted_at IS NOT OLD.alerted_at)
            BEGIN SELECT RAISE(ABORT, 'shop_events: yozuvlarni o''zgartirib bo''lmaydi'); END");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_shev_time ON shop_events(detected_at)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_shev_branch ON shop_events(branch_id, event_date)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_shev_unread ON shop_events(severity, reviewed_at, backfill)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_shev_ref ON shop_events(ref_key, type)");
        // snapshots of what the shop looked like at the last run (used to see what changed or disappeared; the shop keeps no audit log)
        $c->exec("CREATE TABLE IF NOT EXISTS shop_sales (
            sale_id INTEGER PRIMARY KEY, shop_branch_id INTEGER, receipt_no INTEGER, customer_id INTEGER, seller_id INTEGER,
            sale_date TEXT, sale_time TEXT, total REAL, discount REAL, paid REAL, returned_amount REAL, payment_type TEXT, status TEXT, note TEXT,
            items_hash TEXT, items_txt TEXT, dp_sum REAL DEFAULT 0, ret_sum REAL DEFAULT 0, first_seen TEXT, last_seen TEXT, baseline INTEGER DEFAULT 0, deleted_at TEXT
        )");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_shsale_branch ON shop_sales(shop_branch_id, receipt_no)");
        $c->exec("CREATE TABLE IF NOT EXISTS shop_products (
            product_id INTEGER PRIMARY KEY, shop_branch_id INTEGER, name TEXT, category_id INTEGER, sell_price REAL, buy_price REAL, stock REAL,
            deleted INTEGER DEFAULT 0, active INTEGER DEFAULT 1, last_seen TEXT
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS shop_users (
            user_id INTEGER PRIMARY KEY, name TEXT, username TEXT, role TEXT, active INTEGER, shop_branch_id INTEGER,
            blocked_at TEXT,                         -- when Argent first saw the account blocked (sales made before that are not flagged)
            first_seen TEXT, last_seen TEXT
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS shop_customers (
            customer_id INTEGER PRIMARY KEY, shop_branch_id INTEGER, name TEXT, phone TEXT, note TEXT, address TEXT, first_seen TEXT, baseline INTEGER DEFAULT 0
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS shop_purchases (
            purchase_id INTEGER PRIMARY KEY, shop_branch_id INTEGER, purchase_date TEXT, total REAL, paid REAL, items_hash TEXT, items_txt TEXT, first_seen TEXT, last_seen TEXT
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS shop_debtpay (
            dp_id INTEGER PRIMARY KEY, sale_id INTEGER, shop_branch_id INTEGER, amount REAL, method TEXT, payment_date TEXT, created_at TEXT, user_id INTEGER, first_seen TEXT
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS shop_transfers (          -- one row per \"chiqdi\" movement still waiting for its \"kirdi\" in the other branch
            move_id INTEGER PRIMARY KEY, from_branch INTEGER, ref_id INTEGER, product_id INTEGER, product_name TEXT, qty REAL, created_at TEXT, staff TEXT, state TEXT DEFAULT 'open'
        )");
        // inventory control (blind count): a check picks products, the count is typed in WITHOUT seeing the system quantity
        $c->exec("CREATE TABLE IF NOT EXISTS shop_inv_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NOT NULL, shop_branch_id INTEGER, created_at TEXT, created_by INTEGER,
            status TEXT DEFAULT 'open', completed_at TEXT, note TEXT
        )");
        $c->exec("CREATE TABLE IF NOT EXISTS shop_inv_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT, check_id INTEGER NOT NULL, product_id INTEGER, product_name TEXT, category TEXT, reason TEXT,
            counted_qty REAL, counted_at TEXT, sys_qty REAL, buy_price REAL, sell_price REAL, diff_qty REAL, diff_cost REAL, diff_sale REAL
        )");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_shinv_check ON shop_inv_items(check_id)");
        // suggested mapping (editable in Admin → Do'kon): Asosiy filial = Yangibozor Markaz, Oxford Bozqala = OXFORD Bo'zqal'a
        if ((int)$c->query("SELECT COUNT(*) FROM shop_branch_map")->fetchColumn() === 0) {
            $m = $c->prepare("INSERT OR IGNORE INTO shop_branch_map (branch_id, shop_name) VALUES (?,?)");
            $yb = $c->query("SELECT id FROM branches WHERE name = 'Yangibozor Markaz'")->fetchColumn();
            $ox = $c->query("SELECT id FROM branches WHERE name LIKE 'OXFORD%' ORDER BY id LIMIT 1")->fetchColumn();
            if ($yb) $m->execute([$yb, 'Asosiy filial']);
            if ($ox) $m->execute([$ox, 'Oxford Bozqala']);
        }

        /* ---- v10: LC-UP accuracy — one row per LC-UP log row, group -> branch map, incidents + corrections, debt snapshots, accuracy checks ---- */
        // lcup_acts: the LC-UP row id (wire:key "xf-row-NNNN") is the ONLY key. A center-wide user's row seen in both branch views is one row;
        // branch_id is derived from the group/student (NULL = "aniqlanmoqda"), read_branch / seen_in only say where it was read.
        if (!db_has_column($c, 'lcup_acts', 'seen_in')) {
            $c->exec("CREATE TABLE lcup_acts_v10 (
                act_id TEXT PRIMARY KEY, branch_id INTEGER, read_branch INTEGER, seen_in TEXT DEFAULT '',
                staff TEXT, act_time TEXT, act_text TEXT, type TEXT, student_id TEXT, student_name TEXT, group_id TEXT, group_name TEXT,
                amount REAL, old_amount REAL, pay_id TEXT, state TEXT DEFAULT 'open', cur_amount REAL, alert_amount REAL, first_seen TEXT, paired_at TEXT, backfill INTEGER DEFAULT 0
            )");
            $c->exec("INSERT OR IGNORE INTO lcup_acts_v10 (act_id, branch_id, read_branch, seen_in, staff, act_time, act_text, type, student_id, student_name, group_id, group_name, amount, pay_id, state, cur_amount, alert_amount, first_seen, paired_at, backfill)
                SELECT a.act_id, CASE WHEN (SELECT COUNT(DISTINCT b.branch_id) FROM lcup_acts b WHERE b.act_id = a.act_id) = 1 THEN a.branch_id ELSE NULL END, a.branch_id,
                       (SELECT group_concat(DISTINCT b.branch_id) FROM lcup_acts b WHERE b.act_id = a.act_id), a.staff, a.act_time, a.act_text, a.type, a.student_id, a.student_name, a.group_id, a.group_name, a.amount, a.pay_id, a.state, a.cur_amount, a.alert_amount, a.first_seen, a.paired_at, a.backfill
                FROM lcup_acts a ORDER BY a.first_seen, a.rowid");
            $c->exec("DROP TABLE lcup_acts");
            $c->exec("ALTER TABLE lcup_acts_v10 RENAME TO lcup_acts");
        }
        // one name per action type (the old pairs log_edit/log_delete are merged)
        $c->exec("UPDATE lcup_acts SET type = 'charge_edited' WHERE type = 'log_edit' AND act_text LIKE '%minus%'");
        $c->exec("UPDATE lcup_acts SET type = 'payment_edited' WHERE type = 'log_edit'");
        $c->exec("UPDATE lcup_acts SET type = 'payment_deleted' WHERE type = 'log_delete'");
        $c->exec("UPDATE lcup_acts SET type = 'balance_edited' WHERE type = 'payment_other' AND lower(act_text) LIKE '%balans%'");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcact_time2 ON lcup_acts(branch_id, act_time)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcact_state2 ON lcup_acts(state, type)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcact_student ON lcup_acts(student_id, act_time)");
        $c->exec("DROP INDEX IF EXISTS idx_lcact_time"); $c->exec("DROP INDEX IF EXISTS idx_lcact_state");
        // evidence columns: who CREATED the payment (HODIM column of lists/archive) is never the actor; time window of snapshot-only detections
        foreach (['creator' => 'TEXT', 'group_id' => 'TEXT', 'act_id' => 'TEXT', 'win_from' => 'TEXT', 'win_to' => 'TEXT'] as $col => $def) db_add_column($c, 'lcup_events', $col, $def);
        $c->exec("DROP TRIGGER IF EXISTS lcup_events_noupdate");
        $c->exec("CREATE TRIGGER lcup_events_noupdate BEFORE UPDATE ON lcup_events
            WHEN OLD.id IS NOT NEW.id OR OLD.detected_at IS NOT NEW.detected_at OR OLD.lcup_time IS NOT NEW.lcup_time OR OLD.event_date IS NOT NEW.event_date
              OR OLD.branch_id IS NOT NEW.branch_id OR OLD.source IS NOT NEW.source OR OLD.type IS NOT NEW.type OR OLD.staff IS NOT NEW.staff
              OR OLD.student_id IS NOT NEW.student_id OR OLD.student_name IS NOT NEW.student_name OR OLD.group_name IS NOT NEW.group_name
              OR OLD.amount IS NOT NEW.amount OR OLD.method IS NOT NEW.method OR OLD.before_json IS NOT NEW.before_json OR OLD.after_json IS NOT NEW.after_json
              OR OLD.details IS NOT NEW.details OR OLD.raw IS NOT NEW.raw OR OLD.severity IS NOT NEW.severity OR OLD.flags IS NOT NEW.flags
              OR OLD.backfill IS NOT NEW.backfill OR OLD.dedupe_key IS NOT NEW.dedupe_key
              OR OLD.creator IS NOT NEW.creator OR OLD.group_id IS NOT NEW.group_id OR OLD.act_id IS NOT NEW.act_id OR OLD.win_from IS NOT NEW.win_from OR OLD.win_to IS NOT NEW.win_to
              OR (OLD.reviewed_at IS NOT NULL AND (NEW.reviewed_at IS NOT OLD.reviewed_at OR NEW.reviewed_by IS NOT OLD.reviewed_by OR NEW.review_note IS NOT OLD.review_note))
              OR (OLD.alerted_at IS NOT NULL AND NEW.alerted_at IS NOT OLD.alerted_at)
            BEGIN SELECT RAISE(ABORT, 'lcup_events: yozuvlarni o''zgartirib bo''lmaydi'); END");
        // group id -> branch (from each branch's /admin/groups, refreshed nightly). Group ids are unique in LC-UP.
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_groups (group_id TEXT PRIMARY KEY, name TEXT, branch_id INTEGER NOT NULL, first_seen TEXT, last_seen TEXT)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcgrp_name ON lcup_groups(name)");
        // student "Tarix" rows: old -> new and the action kind
        foreach (['kind' => 'TEXT', 'old_amount' => 'REAL', 'new_amount' => 'REAL'] as $col => $def) db_add_column($c, 'lcup_history', $col, $def);
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lchist_student ON lcup_history(student_id, at)");
        // incidents: ONE row per real action, built from all evidence (log, archive, snapshot, Tarix). Derived data: rebuilt by tools/lcup_reprocess.php.
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, severity TEXT DEFAULT 'info', flags TEXT DEFAULT '',
            branch_id INTEGER, branch_src TEXT, student_id TEXT, student_name TEXT, group_id TEXT, group_name TEXT,
            amount REAL, old_amount REAL, new_amount REAL, method TEXT,
            actor TEXT, actor_src TEXT, creator TEXT, at TEXT, win_from TEXT, win_to TEXT,
            sources TEXT DEFAULT '', confidence TEXT DEFAULT 'estimated', leader INTEGER DEFAULT 0, details TEXT, related_id INTEGER,
            first_detected TEXT, updated_at TEXT, backfill INTEGER DEFAULT 0, hold_until TEXT,
            alerted_at TEXT, alerted_sig TEXT, corrected_at TEXT, correction TEXT, correction_reported INTEGER DEFAULT 0,
            tarix_checked_at TEXT, tarix_tries INTEGER DEFAULT 0,
            reviewed_at TEXT, reviewed_by INTEGER, review_note TEXT
        )");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcinc_at ON lcup_incidents(at)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcinc_student ON lcup_incidents(student_id, kind)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcinc_unread ON lcup_incidents(severity, reviewed_at, backfill)");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_incident_events (event_id INTEGER PRIMARY KEY, incident_id INTEGER NOT NULL)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcie_inc ON lcup_incident_events(incident_id)");
        // corrections: append-only, linked to the original (never changed) event
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_corrections (
            id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, event_id INTEGER NOT NULL, incident_id INTEGER,
            field TEXT NOT NULL, original TEXT, corrected TEXT, reason TEXT, UNIQUE (event_id, field, corrected)
        )");
        $c->exec("CREATE TRIGGER IF NOT EXISTS lcup_corrections_noupdate BEFORE UPDATE ON lcup_corrections BEGIN SELECT RAISE(ABORT, 'lcup_corrections: o''zgartirib bo''lmaydi'); END");
        $c->exec("CREATE TRIGGER IF NOT EXISTS lcup_corrections_nodelete BEFORE DELETE ON lcup_corrections BEGIN SELECT RAISE(ABORT, 'lcup_corrections: o''chirib bo''lmaydi'); END");
        // debtors: signed balance + comment + groups (state of the last sync); day snapshots are append-only
        if (!db_has_column($c, 'lcup_debts', 'balance')) {
            $c->exec("CREATE TABLE lcup_debts_v10 (branch_id INTEGER NOT NULL, d_key TEXT NOT NULL, student_id TEXT, student_name TEXT, group_name TEXT DEFAULT '', balance REAL, comment TEXT DEFAULT '', updated_at TEXT, changed_at TEXT, PRIMARY KEY (branch_id, d_key))");
            $c->exec("INSERT OR IGNORE INTO lcup_debts_v10 (branch_id, d_key, student_id, student_name, group_name, balance, updated_at, changed_at) SELECT branch_id, d_key, student_id, student_name, group_name, -ABS(debt), updated_at, updated_at FROM lcup_debts");
            $c->exec("DROP TABLE lcup_debts"); $c->exec("ALTER TABLE lcup_debts_v10 RENAME TO lcup_debts");
        }
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_debt_snaps (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NOT NULL, day TEXT NOT NULL, kind TEXT NOT NULL, taken_at TEXT NOT NULL, jami REAL, cnt INTEGER, rows_n INTEGER, UNIQUE (branch_id, day, kind))");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_debt_snap_rows (snap_id INTEGER NOT NULL, student_id TEXT, d_key TEXT NOT NULL, student_name TEXT, groups TEXT, balance REAL, comment TEXT, PRIMARY KEY (snap_id, d_key))");
        foreach (['lcup_debt_snaps', 'lcup_debt_snap_rows'] as $t) {
            $c->exec("CREATE TRIGGER IF NOT EXISTS {$t}_noupdate BEFORE UPDATE ON $t BEGIN SELECT RAISE(ABORT, '$t: o''zgartirib bo''lmaydi'); END");
            $c->exec("CREATE TRIGGER IF NOT EXISTS {$t}_nodelete BEFORE DELETE ON $t BEGIN SELECT RAISE(ABORT, '$t: o''chirib bo''lmaydi'); END");
        }
        // a debt that went down with nothing behind it yet (re-checked after a grace period)
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_debt_pending (branch_id INTEGER NOT NULL, d_key TEXT NOT NULL, student_id TEXT, student_name TEXT, group_name TEXT, prev_balance REAL, new_balance REAL, from_at TEXT, at TEXT, state TEXT DEFAULT 'open', PRIMARY KEY (branch_id, d_key, at))");
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_leavers (branch_id INTEGER NOT NULL, student_id TEXT NOT NULL, day TEXT NOT NULL, reason TEXT, checked_at TEXT, PRIMARY KEY (branch_id, student_id, day))");
        // accuracy check: 10 random incidents a day re-read from the student's Tarix
        $c->exec("CREATE TABLE IF NOT EXISTS lcup_accuracy (id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT NOT NULL, checked_at TEXT, incident_id INTEGER, actor_ok INTEGER, branch_ok INTEGER, amount_ok INTEGER, expected TEXT, found TEXT, note TEXT)");
        $c->exec("CREATE INDEX IF NOT EXISTS idx_lcacc_day ON lcup_accuracy(day)");
        // superseded switches: Livewire read calls and the Tarix component are always on now (the whitelist is always enforced)
        $c->exec("DELETE FROM settings WHERE key IN ('lcup.history_via_livewire', 'lcup.allow_switch_post', 'lcup.switch_url')");

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
