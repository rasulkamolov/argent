<?php
/**
 * Do'konPro (shop) database adapter — the ONLY file that knows the shop's table and column names.
 * STRICTLY READ-ONLY: the SQLite file is opened with SQLITE_OPEN_READONLY (+ PRAGMA query_only), so no write is possible
 * even by mistake. Every other shop_*.php file works with the normalised arrays returned here.
 * Times: sales carry a local sale_date + sale_time (Asia/Tashkent). Other tables use created_at (local time in current rows).
 */
require_once __DIR__ . '/layout.php';   // fmt() etc. (definitions only)

class ShopError extends RuntimeException { public $kind; function __construct(string $m, string $kind = 'error') { parent::__construct($m); $this->kind = $kind; } }

/** Where bookshop.db is looked for: SHOP_DB_PATH from argent_private/config.php, otherwise the usual places next to Argent. */
function shop_db_candidates(): array {
    $c = []; $cfg = trim((string)cfg('SHOP_DB_PATH', '')); if ($cfg !== '') $c[] = $cfg;
    $root = dirname(__DIR__);
    foreach (['/shop/db/bookshop.db', '/shop/bookshop.db', '/shop/data/bookshop.db', '/shop_private/bookshop.db', '/shop_private/db/bookshop.db'] as $rel) $c[] = $root . $rel;
    return array_values(array_unique($c));
}
function shop_db_path(): ?string {
    foreach (shop_db_candidates() as $p) if (is_file($p)) return $p;
    return null;
}
function shop_configured(): bool { return shop_db_path() !== null; }

/** Read-only PDO to the shop database. Cached per request. */
function shop_pdo(): PDO {
    static $pdo = null; if ($pdo) return $pdo;
    $path = shop_db_path();
    if (!$path) throw new ShopError("Do'konPro bazasi topilmadi (SHOP_DB_PATH). Admin → Do'kon → sozlamalarda yo'lni kiriting.", 'config');
    if (!is_readable($path)) throw new ShopError("Do'konPro bazasini o'qib bo'lmaydi (huquq): $path", 'config');
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) $opt[PDO::SQLITE_ATTR_OPEN_FLAGS] = 1;      // SQLITE_OPEN_READONLY
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, $opt);
        $pdo->exec('PRAGMA busy_timeout = 5000'); $pdo->exec('PRAGMA query_only = 1');       // second lock: even a bug cannot write
        $pdo->query('SELECT 1 FROM sqlite_master LIMIT 1')->fetchAll();
    } catch (Throwable $e) { $pdo = null; throw new ShopError("Do'konPro bazasiga ulanib bo'lmadi: " . $e->getMessage(), 'network'); }
    return $pdo;
}
/** Proof for the settings page: a write attempt must fail. */
function shop_assert_readonly(): bool {
    try { shop_pdo()->exec('CREATE TABLE __argent_probe (x)'); shop_pdo()->exec('DROP TABLE __argent_probe'); return false; } catch (Throwable $e) { return true; }
}
function shop_q(string $sql, array $p = []): array { $st = shop_pdo()->prepare($sql); $st->execute($p); return $st->fetchAll(); }
function shop_one(string $sql, array $p = []) { $r = shop_q($sql, $p); return $r ? $r[0] : null; }
function shop_val(string $sql, array $p = []) { $r = shop_one($sql, $p); return $r ? array_values($r)[0] : null; }
function shop_in(array $ids): string { return $ids ? implode(',', array_map('intval', $ids)) : '0'; }

/** Tables/columns the module relies on. Returns human-readable problems (empty = OK). */
function shop_schema_problems(): array {
    $need = ['branches' => ['id', 'name'], 'users' => ['id', 'name', 'role', 'active', 'branch_id', 'phone'], 'customers' => ['id', 'branch_id', 'name', 'phone'],
        'categories' => ['id', 'name'], 'products' => ['id', 'branch_id', 'category_id', 'name', 'buy_price', 'sell_price', 'stock', 'deleted'],
        'sales' => ['id', 'branch_id', 'receipt_no', 'customer_id', 'seller_id', 'sale_date', 'sale_time', 'total', 'discount', 'paid', 'initial_paid', 'returned_amount', 'payment_type', 'status', 'created_at'],
        'sale_items' => ['id', 'sale_id', 'product_id', 'product_name', 'quantity', 'returned_qty', 'sell_price', 'buy_price'],
        'sale_returns' => ['id', 'sale_id', 'branch_id', 'user_id', 'amount', 'refund', 'created_at'],
        'debt_payments' => ['id', 'sale_id', 'branch_id', 'user_id', 'amount', 'method', 'payment_date', 'created_at'],
        'stock_movements' => ['id', 'branch_id', 'product_id', 'user_id', 'type', 'qty_change', 'qty_after', 'ref_id', 'note', 'created_at'],
        'purchases' => ['id', 'branch_id', 'total', 'paid', 'purchase_date', 'created_at'], 'purchase_items' => ['id', 'purchase_id', 'product_id', 'product_name', 'quantity', 'buy_price'],
        'expenses' => ['id', 'branch_id', 'user_id', 'category', 'amount', 'method', 'expense_date', 'created_at'],
        'transfers' => ['id', 'from_branch_id', 'to_branch_id', 'user_id', 'created_at']];
    $out = [];
    try {
        foreach ($need as $t => $cols) {
            $have = array_map(function ($r) { return strtolower($r['name']); }, shop_q("PRAGMA table_info($t)"));
            if (!$have) { $out[] = "jadval yo'q: $t"; continue; }
            foreach ($cols as $c) if (!in_array($c, $have, true)) $out[] = "ustun yo'q: $t.$c";
        }
    } catch (Throwable $e) { $out[] = $e->getMessage(); }
    return $out;
}

/* ------------------------------------------------------------------ normalised readers */
function shop_branches(): array { return shop_q("SELECT id, name FROM branches ORDER BY id"); }
function shop_users_all(): array { return shop_q("SELECT id, name, username, role, active, branch_id, phone FROM users"); }
function shop_categories(): array { $o = []; foreach (shop_q("SELECT id, name FROM categories") as $r) $o[(int)$r['id']] = $r['name']; return $o; }

/** Local time string of a sale ('Y-m-d H:i:s'). The shop's created_at of older sales is UTC, sale_date+sale_time is always local. */
function shop_sale_ts(array $s): string { $t = (string)($s['sale_time'] ?? ''); if (strlen($t) === 5) $t .= ':00'; return $s['sale_date'] . ' ' . ($t !== '' ? $t : '00:00:00'); }

/** Sales of the last $days days (and of every day when $days = 0) with their items hash, debt payments and returns. */
function shop_sales(int $days = 0, ?int $branch = null): array {
    $w = []; $p = [];
    if ($days > 0) { $w[] = 's.sale_date >= ?'; $p[] = date('Y-m-d', strtotime("-$days days")); }
    if ($branch !== null) { $w[] = 's.branch_id = ?'; $p[] = $branch; }
    $rows = shop_q("SELECT s.id, s.branch_id, s.receipt_no, s.customer_id, s.seller_id, s.sale_date, s.sale_time, s.total, s.discount, s.paid, s.initial_paid, s.returned_amount, s.payment_type, s.status, s.note, s.created_at,
            COALESCE((SELECT SUM(d.amount) FROM debt_payments d WHERE d.sale_id = s.id), 0) AS dp_sum, COALESCE((SELECT SUM(r.amount) FROM sale_returns r WHERE r.sale_id = s.id), 0) AS ret_sum
        FROM sales s" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY s.id", $p);
    $items = shop_sale_items(array_map(function ($r) { return (int)$r['id']; }, $rows));
    foreach ($rows as &$r) { $it = $items[(int)$r['id']] ?? []; $r['items'] = $it; $r['items_hash'] = shop_items_hash($it); $r['items_txt'] = shop_items_text($it); $r['ts'] = shop_sale_ts($r); } unset($r);
    return $rows;
}
function shop_sale_items(array $saleIds): array {
    $out = [];
    foreach (array_chunk($saleIds, 500) as $ch) foreach (shop_q("SELECT sale_id, product_id, product_name, quantity, returned_qty, sell_price, buy_price FROM sale_items WHERE sale_id IN (" . shop_in($ch) . ") ORDER BY id") as $r) $out[(int)$r['sale_id']][] = $r;
    return $out;
}
function shop_items_hash(array $items): string {
    $x = []; foreach ($items as $i) $x[] = (int)$i['product_id'] . ':' . rtrim(rtrim(number_format((float)$i['quantity'], 3, '.', ''), '0'), '.') . ':' . round((float)$i['sell_price']);
    sort($x); return md5(implode('|', $x));
}
function shop_items_text(array $items): string {
    $x = []; foreach ($items as $i) $x[] = trim((string)$i['product_name']) . ' ×' . rtrim(rtrim(number_format((float)$i['quantity'], 2, '.', ''), '0'), '.') . ' @' . fmt($i['sell_price']);
    return mb_substr(implode('; ', $x), 0, 900);
}
function shop_products_all(): array { return shop_q("SELECT id, branch_id, category_id, name, sell_price, buy_price, stock, deleted, active FROM products"); }
function shop_customers_all(): array { return shop_q("SELECT id, branch_id, name, phone, note, address FROM customers"); }
function shop_debt_payments(int $afterId = 0, ?string $fromDate = null): array {
    $w = ['id > ?']; $p = [$afterId]; if ($fromDate) { $w[] = 'payment_date >= ?'; $p[] = $fromDate; }
    return shop_q("SELECT id, sale_id, branch_id, customer_id, user_id, amount, method, payment_date, created_at FROM debt_payments WHERE " . implode(' AND ', $w) . " ORDER BY id", $p);
}
function shop_returns(int $afterId = 0): array { return shop_q("SELECT id, sale_id, branch_id, user_id, amount, refund, return_date, items, note, created_at FROM sale_returns WHERE id > ? ORDER BY id", [$afterId]); }
function shop_movements(int $afterId = 0): array { return shop_q("SELECT id, branch_id, product_id, user_id, type, qty_change, qty_after, ref_id, note, created_at FROM stock_movements WHERE id > ? ORDER BY id", [$afterId]); }
function shop_expenses(int $afterId = 0): array { return shop_q("SELECT id, branch_id, user_id, category, amount, method, expense_date, note, created_at FROM expenses WHERE id > ? ORDER BY id", [$afterId]); }
function shop_purchases_recent(int $days = 120): array {
    $rows = shop_q("SELECT id, branch_id, supplier_id, user_id, purchase_date, total, paid, created_at FROM purchases WHERE purchase_date >= ? ORDER BY id", [date('Y-m-d', strtotime("-$days days"))]);
    $items = []; foreach (array_chunk(array_map(function ($r) { return (int)$r['id']; }, $rows), 500) as $ch) foreach (shop_q("SELECT purchase_id, product_id, product_name, quantity, buy_price FROM purchase_items WHERE purchase_id IN (" . shop_in($ch) . ") ORDER BY id") as $r) $items[(int)$r['purchase_id']][] = $r;
    foreach ($rows as &$r) { $it = $items[(int)$r['id']] ?? []; $x = []; $t = [];
        foreach ($it as $i) { $x[] = (int)$i['product_id'] . ':' . round((float)$i['quantity'], 3) . ':' . round((float)$i['buy_price']); $t[] = trim((string)$i['product_name']) . ' ×' . round((float)$i['quantity'], 2) . ' @' . fmt($i['buy_price']); }
        sort($x); $r['items'] = $it; $r['items_hash'] = md5(implode('|', $x)); $r['items_txt'] = mb_substr(implode('; ', $t), 0, 900); } unset($r);
    return $rows;
}
/** Lowest stock level reached by the sale (negative = it was sold with 0 stock). */
function shop_sale_min_after(int $saleId) { return shop_val("SELECT MIN(qty_after) FROM stock_movements WHERE type = 'sale' AND ref_id = ?", [$saleId]); }
function shop_sale_one(int $id): ?array { return shop_one("SELECT id, branch_id, receipt_no, customer_id, seller_id, sale_date, sale_time, total, payment_type, status FROM sales WHERE id = ?", [$id]); }
function shop_existing_sale_ids(array $ids): array { $o = []; foreach (array_chunk($ids, 500) as $ch) foreach (shop_q("SELECT id FROM sales WHERE id IN (" . shop_in($ch) . ")") as $r) $o[(int)$r['id']] = true; return $o; }
function shop_existing_ids(string $table, array $ids): array { $o = []; foreach (array_chunk($ids, 500) as $ch) foreach (shop_q("SELECT id FROM $table WHERE id IN (" . shop_in($ch) . ")") as $r) $o[(int)$r['id']] = true; return $o; }
function shop_prev_unit_costs(int $branch, int $productId, int $beforePurchaseId, int $limit = 6): array {
    return array_map(function ($r) { return (float)$r['buy_price']; }, shop_q("SELECT pi.buy_price FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id WHERE p.branch_id = ? AND pi.product_id = ? AND p.id < ? AND pi.buy_price > 0 ORDER BY p.id DESC LIMIT " . (int)$limit, [$branch, $productId, $beforePurchaseId]));
}
