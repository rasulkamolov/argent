<?php
/**
 * MOCK Do'konPro database for the tests: the same table structure as the real shop, filled with SYNTHETIC data only
 * (made-up names "Test …", phones +99890000xxxx). No real customer, staff or sales data is used anywhere in the tests.
 * The tests write to THIS file only; the module itself opens the shop database read-only.
 */
class ShopMock {
    public $pdo; public $path;
    function __construct(string $path) {
        $this->path = $path; foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
        $this->pdo = new PDO('sqlite:' . $path); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = wal'); $this->schema(); $this->seed();
    }
    function x(string $sql, array $p = []) { $st = $this->pdo->prepare($sql); $st->execute($p); return $st; }
    function q(string $sql, array $p = []): array { return $this->x($sql, $p)->fetchAll(); }
    function v(string $sql, array $p = []) { $r = $this->x($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    function schema(): void {
        $this->pdo->exec("
        CREATE TABLE branches (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, code TEXT, address TEXT, phone TEXT, color TEXT NOT NULL DEFAULT '#6366f1', note TEXT, active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE COLLATE NOCASE, color TEXT NOT NULL DEFAULT '#6366f1', sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER REFERENCES branches(id) ON DELETE SET NULL, name TEXT NOT NULL, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'seller' CHECK (role IN ('admin','manager','seller')), phone TEXT, active INTEGER NOT NULL DEFAULT 1, last_login TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NOT NULL REFERENCES branches(id), name TEXT NOT NULL, phone TEXT, address TEXT, note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NOT NULL REFERENCES branches(id), category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL, name TEXT NOT NULL, barcode TEXT, unit TEXT NOT NULL DEFAULT 'dona', description TEXT, buy_price REAL NOT NULL DEFAULT 0, sell_price REAL NOT NULL DEFAULT 0, stock REAL NOT NULL DEFAULT 0, min_stock REAL NOT NULL DEFAULT 5, active INTEGER NOT NULL DEFAULT 1, deleted INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT);
        CREATE TABLE sales (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NOT NULL REFERENCES branches(id), receipt_no INTEGER NOT NULL DEFAULT 0, customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL, seller_id INTEGER REFERENCES users(id) ON DELETE SET NULL, sale_date TEXT NOT NULL, sale_time TEXT NOT NULL, subtotal REAL NOT NULL DEFAULT 0, discount REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0, paid REAL NOT NULL DEFAULT 0, initial_paid REAL NOT NULL DEFAULT 0, returned_amount REAL NOT NULL DEFAULT 0, payment_type TEXT NOT NULL DEFAULT 'cash', status TEXT NOT NULL DEFAULT 'paid', note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE sale_items (id INTEGER PRIMARY KEY AUTOINCREMENT, sale_id INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE, product_id INTEGER REFERENCES products(id) ON DELETE SET NULL, product_name TEXT, unit TEXT, quantity REAL NOT NULL, returned_qty REAL NOT NULL DEFAULT 0, sell_price REAL NOT NULL, buy_price REAL NOT NULL DEFAULT 0);
        CREATE TABLE sale_returns (id INTEGER PRIMARY KEY AUTOINCREMENT, sale_id INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE, branch_id INTEGER NOT NULL REFERENCES branches(id), user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, amount REAL NOT NULL DEFAULT 0, refund REAL NOT NULL DEFAULT 0, return_date TEXT NOT NULL, items TEXT, note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE debt_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, sale_id INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE, branch_id INTEGER NOT NULL REFERENCES branches(id), customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL, user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, amount REAL NOT NULL, method TEXT NOT NULL DEFAULT 'cash', payment_date TEXT NOT NULL, note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE stock_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NOT NULL REFERENCES branches(id), product_id INTEGER REFERENCES products(id) ON DELETE CASCADE, user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, type TEXT NOT NULL, qty_change REAL NOT NULL, qty_after REAL NOT NULL, ref_id INTEGER, note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE purchases (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NOT NULL REFERENCES branches(id), supplier_id INTEGER, user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, purchase_date TEXT NOT NULL, total REAL NOT NULL DEFAULT 0, paid REAL NOT NULL DEFAULT 0, initial_paid REAL NOT NULL DEFAULT 0, payment_type TEXT NOT NULL DEFAULT 'cash', status TEXT NOT NULL DEFAULT 'paid', note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE purchase_items (id INTEGER PRIMARY KEY AUTOINCREMENT, purchase_id INTEGER NOT NULL REFERENCES purchases(id) ON DELETE CASCADE, product_id INTEGER REFERENCES products(id) ON DELETE SET NULL, product_name TEXT, quantity REAL NOT NULL, buy_price REAL NOT NULL, sell_price REAL NOT NULL DEFAULT 0);
        CREATE TABLE expenses (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NOT NULL REFERENCES branches(id), user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, category TEXT NOT NULL, amount REAL NOT NULL, method TEXT NOT NULL DEFAULT 'cash', expense_date TEXT NOT NULL, note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE transfers (id INTEGER PRIMARY KEY AUTOINCREMENT, from_branch_id INTEGER NOT NULL REFERENCES branches(id), to_branch_id INTEGER NOT NULL REFERENCES branches(id), user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, total_cost REAL NOT NULL DEFAULT 0, note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);");
    }
    /** ids: branches 1 (Asosiy filial), 2 (Oxford Bozqala); users 1 admin, 2 manager, 3 sellerA, 4 sellerB, 5 blocked, 6 Oxford seller; products 1-3 (b1), 4-7 (b2: 5,6 Kitob). */
    function seed(): void {
        $x = [['branches', "(name) VALUES ('Asosiy filial'),('Oxford Bozqala')"], ['categories', "(name) VALUES ('Ichimliklar'),('Gazaklar'),('Kitob')"]];
        foreach ($x as [$t, $v]) $this->pdo->exec("INSERT INTO $t $v");
        $u = "(branch_id, name, username, password, role, phone, active) VALUES (NULL,'Test Admin','tadmin','x','admin','+998900000001',1),(1,'Test Menejer','tmanager','x','manager','+998900000002',1),(1,'Test Sotuvchi A','tsella','x','seller','+998900000003',1),(1,'Test Sotuvchi B','tsellb','x','seller','+998900000004',1),(1,'Test Bloklangan','tblock','x','seller','+998900000005',1),(2,'Test Oxford Sotuvchi','toxsell','x','seller','+998900000006',1)";
        $this->pdo->exec("INSERT INTO users $u");
        $p = "(branch_id, category_id, name, buy_price, sell_price, stock) VALUES (1,1,'Test Suv',2000,3000,50),(1,2,'Test Chips',5000,7000,30),(1,1,'Test Sok',4000,6000,20),(2,1,'Test Suv Ox',2000,3000,40),(2,3,'Test Kitob Elementary',50000,65000,10),(2,3,'Test Kitob Level 1',50000,65000,10),(2,2,'Test Chips Ox',5000,7000,25)";
        $this->pdo->exec("INSERT INTO products $p");
        $this->pdo->exec("INSERT INTO customers (branch_id, name, phone) VALUES (1,'Test Mijoz Bir','+998900001001'),(1,'Test Mijoz Telefonsiz',NULL),(1,'Test Sotuvchi A','+998900009999'),(1,'Test Mijoz Eski','+998900001002')");
        // history: a few ordinary sales so that "new customer" differs from "old customer", and prior purchases for the cost comparison
        foreach ([[1, 3, '-3 days'], [1, 4, '-3 days'], [1, 3, '-2 days']] as [$b, $s, $d]) $this->sale($b, $s, [[1, 2, 3000], [2, 1, 7000]], 'cash', ['date' => date('Y-m-d', strtotime($d)), 'time' => '10:15:00']);
        $this->sale(1, 3, [[2, 1, 7000]], 'debt', ['customer' => 4, 'initial' => 0, 'date' => date('Y-m-d', strtotime('-3 days')), 'time' => '11:00:00']);
        foreach ([2000, 2100, 1900] as $i => $c) $this->purchase(1, [[1, 20, $c]], ['date' => date('Y-m-d', strtotime('-' . (30 - $i) . ' days'))]);
    }
    function nextReceipt(int $b): int { return (int)$this->v("SELECT COALESCE(MAX(receipt_no),0)+1 FROM sales WHERE branch_id = ?", [$b]); }
    /** items = [[product_id, qty, price], ...]. opts: date,time,customer,initial,receipt,note,discount. Writes the stock movement + product stock like the real shop does. Returns the sale id. */
    function sale(int $b, int $seller, array $items, string $ptype = 'cash', array $o = []): int {
        $date = $o['date'] ?? date('Y-m-d'); $time = $o['time'] ?? date('H:i:s'); $sub = 0; foreach ($items as [$pid, $q, $pr]) $sub += $q * $pr; $disc = $o['discount'] ?? 0; $tot = $sub - $disc;
        $init = array_key_exists('initial', $o) ? $o['initial'] : ($ptype === 'debt' ? 0 : $tot); $paid = $ptype === 'debt' ? $init : $tot; $status = ($ptype === 'debt' && $paid < $tot) ? 'debt' : 'paid';
        $this->x("INSERT INTO sales (branch_id, receipt_no, customer_id, seller_id, sale_date, sale_time, subtotal, discount, total, paid, initial_paid, payment_type, status, note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$b, $o['receipt'] ?? $this->nextReceipt($b), $o['customer'] ?? null, $seller, $date, $time, $sub, $disc, $tot, $paid, $init, $ptype, $status, $o['note'] ?? null, $date . ' ' . $time]);
        $id = (int)$this->pdo->lastInsertId();
        foreach ($items as [$pid, $q, $pr]) {
            $p = $this->q("SELECT name, buy_price, stock FROM products WHERE id = ?", [$pid])[0];
            $this->x("INSERT INTO sale_items (sale_id, product_id, product_name, unit, quantity, sell_price, buy_price) VALUES (?,?,?,?,?,?,?)", [$id, $pid, $p['name'], 'dona', $q, $pr, $p['buy_price']]);
            $after = $p['stock'] - $q; $this->x("UPDATE products SET stock = ? WHERE id = ?", [$after, $pid]);
            $this->x("INSERT INTO stock_movements (branch_id, product_id, user_id, type, qty_change, qty_after, ref_id, note, created_at) VALUES (?,?,?,?,?,?,?,?,?)", [$b, $pid, $seller, 'sale', -$q, $after, $id, 'Sotuv', $date . ' ' . $time]);
        }
        return $id;
    }
    function deleteSale(int $id): void { $this->x("DELETE FROM sale_items WHERE sale_id = ?", [$id]); $this->x("DELETE FROM debt_payments WHERE sale_id = ?", [$id]); $this->x("DELETE FROM sales WHERE id = ?", [$id]); }
    /** A stock movement of any type name (the real names of "Tuzatish", "Hisobdan chiqarish" … are not known, the module must cope). Changes products.stock too unless $touch = false. */
    function move(int $b, int $pid, int $user, string $type, float $qty, ?string $note = null, ?int $ref = null, bool $touch = true, ?string $at = null): int {
        $after = (float)$this->v("SELECT stock FROM products WHERE id = ?", [$pid]) + $qty; if ($touch) $this->x("UPDATE products SET stock = ? WHERE id = ?", [$after, $pid]);
        $this->x("INSERT INTO stock_movements (branch_id, product_id, user_id, type, qty_change, qty_after, ref_id, note, created_at) VALUES (?,?,?,?,?,?,?,?,?)", [$b, $pid, $user, $type, $qty, $after, $ref, $note, $at ?? date('Y-m-d H:i:s')]);
        return (int)$this->pdo->lastInsertId();
    }
    function purchase(int $b, array $items, array $o = []): int {
        $tot = 0; foreach ($items as [$pid, $q, $c]) $tot += $q * $c; $date = $o['date'] ?? date('Y-m-d');
        $this->x("INSERT INTO purchases (branch_id, user_id, purchase_date, total, paid, created_at) VALUES (?,?,?,?,?,?)", [$b, $o['user'] ?? 2, $date, $tot, $tot, $date . ' 09:00:00']); $id = (int)$this->pdo->lastInsertId();
        foreach ($items as [$pid, $q, $c]) { $this->x("INSERT INTO purchase_items (purchase_id, product_id, product_name, quantity, buy_price) VALUES (?,?,?,?,?)", [$id, $pid, $this->v("SELECT name FROM products WHERE id=?", [$pid]), $q, $c]); }
        return $id;
    }
    function debtPay(int $saleId, float $amt, string $method = 'cash', ?string $date = null, int $user = 3): int {
        $b = (int)$this->v("SELECT branch_id FROM sales WHERE id = ?", [$saleId]); $date = $date ?? date('Y-m-d');
        $this->x("INSERT INTO debt_payments (sale_id, branch_id, customer_id, user_id, amount, method, payment_date, created_at) VALUES (?,?,?,?,?,?,?,?)", [$saleId, $b, $this->v("SELECT customer_id FROM sales WHERE id=?", [$saleId]), $user, $amt, $method, $date, $date . ' ' . date('H:i:s')]);
        $this->x("UPDATE sales SET paid = paid + ?, status = CASE WHEN paid + ? >= total THEN 'paid' ELSE status END WHERE id = ?", [$amt, $amt, $saleId]); return (int)$this->pdo->lastInsertId();
    }
    function ret(int $saleId, float $amount, float $refund, int $user, ?string $at = null): int {
        $s = $this->q("SELECT branch_id FROM sales WHERE id = ?", [$saleId])[0]; $at = $at ?? date('Y-m-d H:i:s');
        $this->x("INSERT INTO sale_returns (sale_id, branch_id, user_id, amount, refund, return_date, items, created_at) VALUES (?,?,?,?,?,?,?,?)", [$saleId, $s['branch_id'], $user, $amount, $refund, substr($at, 0, 10), 'test', $at]);
        $this->x("UPDATE sales SET returned_amount = returned_amount + ? WHERE id = ?", [$amount, $saleId]); return (int)$this->pdo->lastInsertId();
    }
}
