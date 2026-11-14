<?php
/** Logic tests of the Do'konPro module: mock shop DB (synthetic data) + Telegram stub. Run: php tests/shop_test.php */
date_default_timezone_set('Asia/Tashkent');
$T = sys_get_temp_dir() . '/argent_shop_test'; exec('rm -rf ' . escapeshellarg($T)); mkdir("$T/private", 0700, true);
$port = 8191; $tgLog = "$T/tg.log"; file_put_contents($tgLog, '');
$stub = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/tg_stub.php'], [1 => ['file', "$T/stub.out", 'w'], 2 => ['file', "$T/stub.out", 'a']], $pipes, __DIR__, ['TG_LOG' => $tgLog] + $_ENV + ['PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($stub) { if (is_resource($stub)) { proc_terminate($stub); } });
usleep(700000);
// a FRESH Argent database (the shipped one holds real data and must never be used by tests)
putenv("ARGENT_DB=$T/argent.sqlite"); putenv("ARGENT_PRIVATE_DIR=$T/private"); define('ARGENT_NO_SESSION', 1);
require __DIR__ . '/shop_mock.php';
$shop = new ShopMock("$T/shop.db");
file_put_contents("$T/private/config.php", "<?php return ['SHOP_DB_PATH'=>'$T/shop.db','TELEGRAM_BOT_TOKEN'=>'123:TESTTOKEN','TELEGRAM_CHAT_ID'=>'555','TELEGRAM_API_BASE'=>'http://127.0.0.1:$port'];");
chmod("$T/private/config.php", 0600);
require __DIR__ . '/../shop_report.php';
db()->exec("INSERT INTO branches (name, color, sort_order) VALUES ('Yangibozor Markaz', 1, 2)");
db()->prepare("INSERT OR REPLACE INTO shop_branch_map (branch_id, shop_name, is_active) VALUES (?, 'Asosiy filial', 1)")->execute([(int)db()->query("SELECT id FROM branches WHERE name='Yangibozor Markaz'")->fetchColumn()]);

$ok = 0; $bad = 0;
function chk($n, $c, $x = '') { global $ok, $bad; echo ($c ? "  PASS " : "  FAIL ") . $n . ($c ? '' : "  [" . (is_string($x) ? $x : json_encode($x, JSON_UNESCAPED_UNICODE)) . "]") . "\n"; $c ? $ok++ : $bad++; }
function mark() { return (int)db()->query("SELECT COALESCE(MAX(id),0) FROM shop_events")->fetchColumn(); }
function evs(int $since, string $where = '1=1') { return db()->query("SELECT * FROM shop_events WHERE id > $since AND $where ORDER BY id")->fetchAll(); }
function ev1(int $since, string $type) { $r = evs($since, "type = " . db()->quote($type)); return $r ? $r[0] : null; }
function has_flag($e, $f) { return $e && in_array($f, explode(',', $e['flags']), true); }
function run() { $r = shop_run(['trigger' => 'test']); return $r; }
function tg() { $o = []; foreach (file(getenv('TG_LOG_PATH') ?: $GLOBALS['tgLog'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) $o[] = json_decode($l, true)['text']; return $o; }
$D = date('Y-m-d');
setting_set('shop.eod_time', '23:59'); setting_set('shop.recon_next_time', '23:59');   // keep the day-level reconciliation events out of the per-rule tests

echo "== schema, mapping, read-only\n";
chk("schema v9 + shop tables", (int)db()->query("PRAGMA user_version")->fetchColumn() >= 9 && db()->query("SELECT COUNT(*) FROM shop_events")->fetchColumn() !== false);
$mp = shop_resolve_map(); ksort($mp); chk("default branch map resolves to the shop branches by name", $mp == [(int)db()->query("SELECT id FROM branches WHERE name LIKE 'OXFORD%'")->fetchColumn() => 2, (int)db()->query("SELECT id FROM branches WHERE name='Yangibozor Markaz'")->fetchColumn() => 1], $mp);
$AB1 = (int)db()->query("SELECT id FROM branches WHERE name='Yangibozor Markaz'")->fetchColumn(); $AB2 = (int)db()->query("SELECT id FROM branches WHERE name LIKE 'OXFORD%'")->fetchColumn();
chk("schema check passes on the mock", shop_schema_problems() === [], shop_schema_problems());
chk("a write attempt on the shop connection is refused", shop_assert_readonly() === true);
$h0 = md5_file("$T/shop.db"); $s0 = filesize("$T/shop.db");

echo "== baseline run (history is silent)\n";
$r = run(); chk("first run ok, no events, no Telegram", $r['ok'] && $r['new_events'] === 0 && !tg(), [$r, tg()]);
chk("snapshot taken (sales/products/users)", (int)db()->query("SELECT COUNT(*) FROM shop_sales")->fetchColumn() >= 4 && (int)db()->query("SELECT COUNT(*) FROM shop_users")->fetchColumn() === 6);
$r = run(); chk("second run without changes: still silent", $r['ok'] && $r['new_events'] === 0, $r);
$wal = @filesize("$T/shop.db-wal"); clearstatcache();
chk("module never changed the shop database file", md5_file("$T/shop.db") === $h0 && filesize("$T/shop.db") === $s0);

echo "== new sales: red / yellow rules\n";
$m = mark(); $id = $shop->sale(1, 1, [[1, 1, 3000]]); run(); $e = ev1($m, 'sale_flagged');
chk("sale under the Administrator login = RED (admin_sale)", $e && $e['severity'] === 'red' && has_flag($e, 'admin_sale') && strpos($e['ref'], 'chek #') === 0 && strpos($e['staff'], 'Test Admin') !== false, $e);
chk("…and goes to Telegram at once", count(tg()) === 1 && strpos(tg()[0], 'Shubhali sotuv') !== false && strpos(tg()[0], 'Administrator') !== false, tg());
$m = mark(); $shop->sale(1, 3, [[1, 1, 3000]], 'cash', ['time' => '23:30:00']); run(); $e = ev1($m, 'sale_flagged');
chk("sale at 23:30 (outside 08:00–21:00) = RED night_sale", $e && $e['severity'] === 'red' && has_flag($e, 'night_sale'), $e);
$m = mark(); $shop->sale(1, 3, [[1, 1, 3000]], 'cash', ['time' => '12:00:00']); run(); chk("an ordinary sale makes no event", !evs($m));
// blocked user
$m = mark(); $shop->x("UPDATE users SET active = 0 WHERE id = 5"); run(); $e = ev1($m, 'user_blocked'); chk("blocking of a user is recorded by Argent (blocked_at)", $e && db()->query("SELECT blocked_at FROM shop_users WHERE user_id = 5")->fetchColumn() !== null, $e);
$m = mark(); $shop->sale(1, 5, [[1, 1, 3000]], 'cash', ['time' => date('H:i:s')]); run(); $e = ev1($m, 'sale_flagged'); chk("sale by the blocked user AFTER detection = RED", $e && has_flag($e, 'blocked_user'), $e);
db()->prepare("UPDATE shop_users SET blocked_at = '2099-01-01 00:00:00' WHERE user_id = 5")->execute();   // pretend the block was detected later than a sale that is already in the shop
$m = mark(); $shop->sale(1, 5, [[1, 1, 3000]], 'cash', ['time' => '12:06:00']); run(); chk("a sale made BEFORE the block was detected is not flagged as blocked", !ev1($m, 'sale_flagged'), evs($m));
db()->prepare("UPDATE shop_users SET blocked_at = ? WHERE user_id = 5")->execute([date('Y-m-d H:i:s', strtotime('-1 day'))]);
// debt rules
$m = mark(); $shop->sale(1, 3, [[2, 1, 7000]], 'debt', ['customer' => 2, 'initial' => 0, 'time' => '12:10:00']); run(); $e = ev1($m, 'sale_flagged');
chk("debt to a customer without a phone, first-time customer = RED", $e && $e['severity'] === 'red' && has_flag($e, 'debt_no_phone') && has_flag($e, 'debt_new_customer'), $e);
$m = mark(); $shop->sale(1, 3, [[2, 1, 7000]], 'debt', ['customer' => 3, 'initial' => 0, 'time' => '12:11:00']); run(); $e = ev1($m, 'sale_flagged');
chk("debt to a customer who is a staff member = RED (debt_staff)", $e && has_flag($e, 'debt_staff'), $e);
$m = mark(); $shop->sale(1, 3, [[2, 1, 7000]], 'debt', ['customer' => null, 'initial' => 0, 'time' => '12:12:00']); run(); $e = ev1($m, 'sale_flagged'); chk("debt without any customer = RED", $e && has_flag($e, 'debt_no_customer'), $e);
$m = mark(); $shop->sale(1, 3, [[1, 1, 3000]], 'debt', ['customer' => 4, 'initial' => 0, 'time' => '12:13:00']); run(); $e = ev1($m, 'sale_flagged');
chk("small debt to a known customer = YELLOW only (no Telegram)", $e && $e['severity'] === 'yellow' && has_flag($e, 'small_debt'), $e);
$m = mark(); $shop->sale(1, 3, [[1, 1, 2500]], 'cash', ['time' => '12:14:00']); run(); $e = ev1($m, 'sale_flagged'); chk("item sold below the product's normal price = RED", $e && has_flag($e, 'below_price') && $e['severity'] === 'red', $e);
$m = mark(); $shop->x("UPDATE products SET stock = 0 WHERE id = 3"); $shop->pdo->exec("INSERT INTO stock_movements (branch_id, product_id, user_id, type, qty_change, qty_after, note) VALUES (1,3,3,'manual_zero',-20,0,'test')");
run(); $m = mark(); $shop->sale(1, 3, [[3, 1, 6000]], 'cash', ['time' => '12:15:00']); run(); $e = ev1($m, 'sale_flagged'); chk("selling an item with 0 stock (stock goes negative) = YELLOW", $e && $e['severity'] === 'yellow' && has_flag($e, 'negative_stock'), $e);

echo "== deleted / changed sales, receipt gaps\n";
$m = mark(); $sid = $shop->sale(1, 3, [[1, 2, 3000], [2, 1, 7000]], 'cash', ['time' => '12:20:00']); run(); chk("(setup) the sale itself is quiet", !evs($m));
$m = mark(); $rc = $shop->v("SELECT receipt_no FROM sales WHERE id = ?", [$sid]); $shop->deleteSale($sid); run(); $e = ev1($m, 'sale_deleted');
chk("deleted sale = RED with receipt, amount, seller, items", $e && $e['severity'] === 'red' && $e['ref'] === "chek #$rc" && (float)$e['amount'] === 13000.0 && strpos($e['details'], 'Test Chips') !== false && strpos($e['staff'], 'Test Sotuvchi A') !== false, $e);
$m = mark(); run(); chk("a deleted sale is reported once", !evs($m));
$m = mark(); $a = $shop->sale(1, 3, [[1, 1, 3000]], 'cash', ['time' => '12:21:00']); $gapN = $shop->nextReceipt(1) + 1; $shop->sale(1, 3, [[1, 1, 3000]], 'cash', ['time' => '12:22:00', 'receipt' => $gapN]); run(); $e = ev1($m, 'receipt_gap');
chk("receipt-number gap (sale created and deleted between two runs) = RED", $e && $e['ref'] === 'chek #' . ($gapN - 1), $e);
$m = mark(); $sid = $shop->sale(1, 3, [[1, 1, 3000], [2, 1, 7000]], 'cash', ['time' => '12:23:00']); run();
$m = mark(); $shop->x("UPDATE sale_items SET quantity = 1, sell_price = 7000 WHERE sale_id = ? AND product_id = 2", [$sid]); $shop->x("UPDATE sale_items SET quantity = 3 WHERE sale_id = ? AND product_id = 1", [$sid]); $shop->x("UPDATE sales SET total = 16000 WHERE id = ?", [$sid]);
run(); $e = ev1($m, 'sale_changed'); chk("items + amount changed after creation = RED sale_changed", $e && $e['severity'] === 'red' && has_flag($e, 'items_changed') && has_flag($e, 'amount_changed') && strpos($e['details'], '→') !== false, $e);
$m = mark(); $shop->x("UPDATE sales SET payment_type = 'card' WHERE id = ?", [$sid]); run(); $e = ev1($m, 'sale_changed'); chk("payment type changed = RED", $e && has_flag($e, 'payment_changed'), $e);
$m = mark(); $shop->x("UPDATE sales SET note = 'izoh' WHERE id = ?", [$sid]); run(); $e = ev1($m, 'sale_changed'); chk("only the comment changed = YELLOW", $e && $e['severity'] === 'yellow', $e);
$m = mark(); $shop->x("UPDATE customers SET note = 'yangi izoh' WHERE id = 1"); run(); $e = ev1($m, 'customer_edited'); chk("customer comment edited = YELLOW", $e && $e['severity'] === 'yellow', $e);

echo "== returns and debts\n";
$sid = $shop->sale(1, 3, [[1, 2, 3000]], 'cash', ['date' => date('Y-m-d', strtotime('-1 day')), 'time' => '12:30:00']); run();
$m = mark(); $shop->ret($sid, 3000, 3000, 4); run(); $e = ev1($m, 'return_new');
chk("return by another person, long after the sale, cash refund = RED with all three flags", $e && $e['severity'] === 'red' && has_flag($e, 'late_return') && has_flag($e, 'other_user') && has_flag($e, 'cash_refund'), $e);
$m = mark(); $s2 = $shop->sale(1, 3, [[1, 1, 3000]], 'cash', ['time' => date('H:i:s')]); run(); $m = mark(); $shop->ret($s2, 3000, 0, 3); run(); $e = ev1($m, 'return_new');
chk("every return is RED, even a quick one by the seller (no refund flags)", $e && $e['severity'] === 'red' && !has_flag($e, 'late_return') && !has_flag($e, 'other_user') && !has_flag($e, 'cash_refund'), $e);
$m = mark(); $s3 = $shop->sale(1, 3, [[1, 1, 3000]], 'cash', ['time' => '12:32:00']); run(); $m = mark(); $shop->x("UPDATE sales SET returned_amount = 3000 WHERE id = ?", [$s3]); run(); chk("returned amount without a return record = RED return_mismatch", (bool)ev1($m, 'return_mismatch'));
$ds = $shop->sale(1, 3, [[2, 2, 7000]], 'debt', ['customer' => 4, 'initial' => 0, 'time' => '12:33:00']); run();
$m = mark(); $shop->x("UPDATE sales SET paid = 14000, status = 'paid' WHERE id = ?", [$ds]); run(); $e = ev1($m, 'debt_reduced'); chk("debt reduced with no recorded payment = RED", $e && $e['severity'] === 'red' && (float)$e['amount'] === 14000.0, $e);
$ds2 = $shop->sale(1, 3, [[2, 2, 7000]], 'debt', ['customer' => 4, 'initial' => 0, 'time' => '12:34:00']); run(); $m = mark(); $shop->debtPay($ds2, 5000); run(); chk("a debt reduced WITH a recorded payment is not flagged", !ev1($m, 'debt_reduced'), evs($m));
$m = mark(); $dpid = $shop->v("SELECT id FROM debt_payments WHERE sale_id = ?", [$ds2]); $shop->x("DELETE FROM debt_payments WHERE id = ?", [$dpid]); run(); $e = ev1($m, 'debt_payment_deleted'); chk("deleted debt payment = RED", $e && (float)$e['amount'] === 5000.0, $e);
$m = mark(); $shop->x("UPDATE sales SET status = 'paid' WHERE id = ?", [$ds2]); run(); $e = ev1($m, 'debt_reduced'); chk("debt status closed without being paid = RED (written off)", $e && strpos($e['details'], 'yopilgan') !== false, $e);

echo "== stock movements (names of the real types are not known: the module copes)\n";
$m = mark(); $shop->move(1, 1, 2, 'adjustment', -3, null); run(); $e = ev1($m, 'stock_adjust');
chk("manual adjustment (minus, no comment) = RED with product, qty, cost, sale value", $e && $e['severity'] === 'red' && has_flag($e, 'minus') && has_flag($e, 'no_comment') && strpos($e['details'], 'Test Suv') !== false && strpos($e['details'], '6 000') !== false && strpos($e['details'], '9 000') !== false && strpos($e['staff'], 'Test Menejer') !== false, $e);
$m = mark(); $shop->move(1, 1, 2, 'tuzatish', 2, 'sanashda xato'); run(); $e = ev1($m, 'stock_adjust'); chk("Uzbek type name 'tuzatish' recognised too; with a comment no 'no_comment' flag", $e && !has_flag($e, 'no_comment'), $e);
$m = mark(); $shop->move(1, 2, 2, 'write_off', -2, 'singan'); run(); $e = ev1($m, 'stock_writeoff'); chk("Hisobdan chiqarish = RED", $e && $e['severity'] === 'red' && $e['amount'] == 10000, $e);
$m = mark(); $shop->move(1, 2, 2, 'inventory', -1, null); run(); $e = ev1($m, 'stock_inventory'); chk("Inventarizatsiya with minus, no comment = RED", $e && $e['severity'] === 'red' && has_flag($e, 'minus') && has_flag($e, 'no_comment'), $e);
$m = mark(); $shop->move(1, 2, 2, 'inventory', 1, 'topildi'); run(); $e = ev1($m, 'stock_inventory'); chk("Inventarizatsiya with plus = YELLOW", $e && $e['severity'] === 'yellow', $e);
$m = mark(); $shop->move(1, 2, 2, 'mystery_kind', -1, 'x'); run(); $e = ev1($m, 'stock_other'); chk("unknown movement type = RED and the type name is shown", $e && has_flag($e, 'unknown_type') && strpos($e['details'], 'mystery_kind') !== false, $e);
$m = mark(); $shop->move(1, 1, 3, 'sale', -1, 'Sotuv', null); $mm = mark(); chk("normal movement types (sale/purchase/initial) are not flagged", !evs($m), evs($m)); run(); chk("(still none after the run)", !evs($m, "type LIKE 'stock_%'"));
$m = mark(); $shop->x("UPDATE products SET stock = stock - 4 WHERE id = 1"); run(); $e = ev1($m, 'stock_adjust'); chk("stock changed without any movement record = RED (untracked)", $e && has_flag($e, 'untracked') && $e['amount'] == 8000, $e);
$m = mark(); $pid = $shop->purchase(1, [[1, 5, 2000]]); $shop->move(1, 1, 2, 'purchase', 5, 'Kirim', $pid); run(); chk("a normal purchase (kirim) with its movement is quiet", !evs($m), evs($m));
$m = mark(); $shop->move(1, 1, 2, 'purchase_cancel', -5, 'bekor', $pid); run(); $e = ev1($m, 'purchase_cancelled'); chk("purchase_cancel movement = RED purchase_cancelled", (bool)$e);

echo "== prices, purchases, expenses, transfers\n";
$m = mark(); $shop->x("UPDATE products SET sell_price = 2000 WHERE id = 1"); run(); $e = ev1($m, 'price_down'); chk("sell price lowered = RED", $e && $e['severity'] === 'red' && $e['amount'] == 1000, $e);
$m = mark(); $shop->x("UPDATE products SET sell_price = 3000 WHERE id = 1"); run(); $e = ev1($m, 'price_restored'); chk("lowered and raised back within a day = RED price_restored", $e && $e['severity'] === 'red' && has_flag($e, 'rewrite'), evs($m));
$m = mark(); $shop->x("UPDATE products SET sell_price = 3500 WHERE id = 1"); run(); $e = ev1($m, 'price_up'); chk("price increase = YELLOW", $e && $e['severity'] === 'yellow', $e);
$m = mark(); $p1 = $shop->purchase(1, [[1, 10, 2000]]); $shop->move(1, 1, 2, 'purchase', 10, 'Kirim', $p1); run(); $m = mark();
$shop->x("UPDATE purchase_items SET quantity = 12 WHERE purchase_id = ?", [$p1]); $shop->x("UPDATE purchases SET total = 24000 WHERE id = ?", [$p1]); run(); $e = ev1($m, 'purchase_edited'); chk("kirim edited after creation = RED", $e && $e['severity'] === 'red' && strpos($e['details'], '→') !== false, evs($m));
$m = mark(); $shop->x("DELETE FROM purchase_items WHERE purchase_id = ?", [$p1]); $shop->x("DELETE FROM purchases WHERE id = ?", [$p1]); run(); $e = ev1($m, 'purchase_deleted'); chk("kirim deleted = RED", (bool)$e);
$m = mark(); $p2 = $shop->purchase(1, [[1, 10, 3500]]); $shop->move(1, 1, 2, 'purchase', 10, 'Kirim', $p2); run(); $e = ev1($m, 'cost_high'); chk("kirim with an unusually high cost (3 500 vs ≈ 2 000) = RED", $e && $e['severity'] === 'red' && strpos($e['details'], '3 500') !== false, $e);
$m = mark(); $shop->x("INSERT INTO expenses (branch_id, user_id, category, amount, method, expense_date, note) VALUES (1,3,'Transport',50000,'cash',?,NULL)", [$D]); run(); $e = ev1($m, 'expense_added'); chk("shop expense added = RED", $e && $e['amount'] == 50000 && has_flag($e, 'no_comment'), $e);
$m = mark(); $old = date('Y-m-d H:i:s', strtotime('-30 hours')); $shop->move(1, 1, 2, 'transfer_out', -2, 'Ox ga', 77, true, $old); run(); $e = ev1($m, 'transfer_unmatched'); chk("Ko'chirish 'chiqdi' without a 'kirdi' in the other branch within 24 h = RED", $e && $e['severity'] === 'red', evs($m));
$m = mark(); $shop->move(1, 3, 2, 'transfer_out', -1, 'Ox ga', 78, true, $old); $shop->move(2, 4, 6, 'transfer_in', 1, 'Mahsulot', 78, true, date('Y-m-d H:i:s', strtotime('-29 hours'))); $shop->x("UPDATE products SET name = 'Test Sok' WHERE id = 4"); run(); chk("a matching 'kirdi' in the other branch closes the transfer (no alert)", !ev1($m, 'transfer_unmatched'), evs($m));

echo "== append-only log, dedupe, review\n";
$row = evs(0)[0];
foreach (["UPDATE shop_events SET details = 'x' WHERE id = {$row['id']}", "DELETE FROM shop_events WHERE id = {$row['id']}", "UPDATE shop_events SET severity = 'info' WHERE id = {$row['id']}", "UPDATE shop_events SET amount = 1 WHERE id = {$row['id']}"] as $sql) { $err = ''; try { db()->exec($sql); } catch (Throwable $e) { $err = $e->getMessage(); } chk("tamper refused: " . substr($sql, 0, 40), $err !== '' , $err); }
$uid = (int)db()->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
chk("review marks once with a note", shop_review((int)$row['id'], $uid, 'ko\'rdim') === true && shop_review((int)$row['id'], $uid, 'boshqa') === false && db()->query("SELECT review_note FROM shop_events WHERE id = {$row['id']}")->fetchColumn() === "ko'rdim");
$err = ''; try { db()->exec("UPDATE shop_events SET review_note = 'o''zgartirildi' WHERE id = {$row['id']}"); } catch (Throwable $e) { $err = $e->getMessage(); } chk("a review cannot be rewritten", $err !== '');
$n1 = (int)db()->query("SELECT COUNT(*) FROM shop_events")->fetchColumn(); run(); run(); chk("repeated runs never duplicate events", (int)db()->query("SELECT COUNT(*) FROM shop_events")->fetchColumn() === $n1);
$sent = count(tg()); chk("every red event reached Telegram exactly once (≤10 per run, rest summarised)", $sent >= 20 && (int)db()->query("SELECT COUNT(*) FROM shop_events WHERE severity='red' AND alerted_at IS NULL AND backfill=0")->fetchColumn() === 0, [$sent, db()->query("SELECT COUNT(*) FROM shop_events WHERE severity='red' AND alerted_at IS NULL")->fetchColumn()]);
chk("yellow-only events (price up, customer edited) are not sent to Telegram", !array_filter(tg(), function ($t) { return strpos($t, 'Narx oshirilgan') !== false || strpos($t, "Mijoz ma'lumoti") !== false; }));
chk("Telegram alert text has the details (receipt, who, how much)", (bool)array_filter(tg(), function ($t) { return strpos($t, 'chek #') !== false && strpos($t, 'Test Sotuvchi A') !== false && strpos($t, 'so\'m') !== false; }));
chk("no secret in any Telegram text", !array_filter(tg(), function ($t) { return strpos($t, 'TESTTOKEN') !== false; }));

echo "== reconciliation (per branch/day/category)\n";
$tx = function (int $bid, string $cat, float $amt, string $method, string $date, string $comment = '', ?string $createdUtc = null) { $c = db()->prepare("SELECT id FROM categories WHERE name = ?"); $c->execute([$cat]); $cid = $c->fetchColumn(); if (!$cid) { db()->prepare("INSERT INTO categories (name, type) VALUES (?, 'income')")->execute([$cat]); $cid = db()->lastInsertId(); }
    db()->prepare("INSERT INTO transactions (user_id, branch_id, category_id, payment, cash, click, bank, cash_in, cash_out, comment, date, created_at) VALUES (?,?,?,?,?,?,?,1,0,?,?,?)")->execute([1, $bid, $cid, $amt, $method === 'cash' ? 1 : 0, $method === 'click' ? 1 : 0, $method === 'bank' ? 1 : 0, $comment, $date, $createdUtc ?? gmdate('Y-m-d H:i:s', strtotime($date . ' 12:00:00'))]); };
$day = function (int $n) { return date('Y-m-d', strtotime("-$n days")); };
$st = function (string $d, int $bid, string $cat) { foreach (shop_recon_day($d, [$bid])['rows'] as $r) if ($r['cat'] === $cat) return $r; return null; };
// d20: ok — cash 100 000 + card 50 000 in the shop; Argent Naqd 100 000, Click 30 000 + Bank 20 000
$d = $day(20); $shop->sale(1, 3, [[1, 20, 3000], [2, 5, 8000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $shop->sale(1, 3, [[2, 5, 10000]], 'card', ['date' => $d, 'time' => '11:00:00']); $shop->x("UPDATE sale_items SET sell_price = 3000 WHERE product_id = 1"); // keep prices equal to the catalogue
$shop->x("UPDATE sales SET total = 100000, subtotal = 100000, paid = 100000, initial_paid = 100000 WHERE date(sale_date) = ? AND payment_type = 'cash'", [$d]);
$tx($AB1, 'shop', 100000, 'cash', $d); $tx($AB1, 'shop', 30000, 'click', $d); $tx($AB1, 'shop', 20000, 'bank', $d);
$r = $st($d, $AB1, 'shop'); chk("cash ↔ Naqd and card ↔ Click+Bank COMBINED: match ✅", $r && $r['status'] === 'ok' && $r['s_cash'] == 100000 && $r['s_non'] == 50000 && $r['a_non'] == 50000, $r);
// d19: shop more than Argent
$d = $day(19); $s = $shop->sale(1, 3, [[2, 10, 7000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $tx($AB1, 'shop', 60000, 'cash', $d); $r = $st($d, $AB1, 'shop');
chk("shop 70 000 vs Argent 60 000 → 🔴 short, diff −10 000", $r && $r['status'] === 'short' && $r['diff'] == -10000, $r);
// d18: no Argent entry
$d = $day(18); $shop->sale(1, 3, [[2, 3, 7000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $r = $st($d, $AB1, 'shop'); chk("sales but no Argent entry → 🔴 missing", $r && $r['status'] === 'missing' && $r['a_total'] == 0, $r);
// d17: Argent more than shop
$d = $day(17); $tx($AB1, 'shop', 40000, 'cash', $d); $r = $st($d, $AB1, 'shop'); chk("Argent entry with no shop sale → 🟠 extra", $r && $r['status'] === 'extra', $r);
// d16: method swapped
$d = $day(16); $shop->sale(1, 3, [[2, 10, 8000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $tx($AB1, 'shop', 80000, 'click', $d); $r = $st($d, $AB1, 'shop'); chk("same total but typed as Click instead of Naqd → 🟡 method", $r && $r['status'] === 'method', $r);
// d15: entered on a later day (2 days later)
$d = $day(15); $shop->sale(1, 3, [[2, 5, 10000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $tx($AB1, 'shop', 50000, 'cash', $d, '', gmdate('Y-m-d H:i:s', strtotime($day(13) . ' 12:00:00'))); $r = $st($d, $AB1, 'shop');
chk("entered 2 days later → 🟡 late with the number of days", $r && $r['status'] === 'late' && $r['late_days'] === 2 && strpos(shop_recon_text($r), '2 kun kech') !== false, $r);
$d = $day(14); $shop->sale(1, 3, [[2, 5, 10000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $tx($AB1, 'shop', 50000, 'cash', $d, '', gmdate('Y-m-d H:i:s', strtotime($day(13) . ' 12:00:00'))); $r = $st($d, $AB1, 'shop'); chk("entered the next day (delay 1 day) is already 🟡", $r && $r['status'] === 'late' && $r['late_days'] === 1, $r);
// d12: category "To'lov" with the comment 'shop'
$d = $day(12); $shop->sale(1, 3, [[2, 4, 7000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $tx($AB1, "To'lov", 28000, 'cash', $d, 'shop kassa'); $r = $st($d, $AB1, 'shop');
chk("«To'lov» + 'shop' in the comment counts as shop AND is flagged 🟡 wrong category", $r && $r['status'] === 'wrongcat' && $r['a_total'] == 28000 && count($r['wrong']) === 1, $r);
$d = $day(11); $shop->sale(1, 3, [[2, 4, 7000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $tx($AB1, "To'lov", 28000, 'cash', $d, 'Kitob uchun'); $tx($AB1, "To'lov", 500000, 'cash', $d, 'https://oxfordlc.lc-up.com/admin/students/123 shop'); $tx($AB1, "To'lov", 90000, 'cash', $d, 'oylik to\'lov');
$r = $st($d, $AB1, 'shop'); $rk = $st($d, $AB1, 'kitob'); chk("«To'lov» with 'Kitob' in the comment goes to kitob; entries with an LC-UP student link or no keyword are NOT counted", $rk && $rk['a_total'] == 28000 && $rk['status'] === 'nokitob' && (!$r || $r['a_total'] == 0), [$r, $rk]);
chk("kitob entry in a branch with no Kitob products in the shop → 🔴 «Kitob sotuvi Do'konPro'da yo'q»", $rk && SHOP_ST[$rk['status']][1] === "Kitob sotuvi Do'konPro'da yo'q");
// d10: Oxford: a mixed basket (book + chips), kitob share separated
$d = $day(10); $shop->sale(2, 6, [[5, 1, 65000], [7, 1, 7000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $tx($AB2, 'kitob', 65000, 'cash', $d); $tx($AB2, 'shop', 7000, 'cash', $d);
$rk = $st($d, $AB2, 'kitob'); $rs = $st($d, $AB2, 'shop'); chk("mixed basket: Kitob share (65 000) and the rest (7 000) are reconciled separately → both ✅", $rk && $rs && $rk['status'] === 'ok' && $rs['status'] === 'ok' && $rk['s_cash'] == 65000 && $rs['s_cash'] == 7000, [$rk, $rs]);
// d9: cash debt collection counts, refund subtracts
$d = $day(9); $sd = $shop->sale(1, 3, [[2, 4, 7000]], 'debt', ['customer' => 4, 'initial' => 0, 'date' => $day(30), 'time' => '10:00:00']); $shop->debtPay($sd, 20000, 'cash', $d); $r = $st($d, $AB1, 'shop');
chk("cash debt collection counts as received money", $r && $r['s_cash'] == 20000 && $r['debt_cash'] == 20000, $r);
$d = $day(8); $s8 = $shop->sale(1, 3, [[2, 5, 7000]], 'cash', ['date' => $d, 'time' => '10:00:00']); $shop->ret($s8, 7000, 7000, 3, "$d 15:00:00"); $r = $st($d, $AB1, 'shop'); chk("cash refund is subtracted (35 000 − 7 000 = 28 000)", $r && $r['s_cash'] == 28000 && $r['refund'] == 7000, $r);
$d = $day(7); $shop->sale(1, 3, [[2, 1, 7000]], 'debt', ['customer' => 4, 'initial' => 0, 'date' => $d, 'time' => '10:00:00']); $r = $st($d, $AB1, 'shop'); chk("unpaid (credit) part of a sale is not expected in the till", $r === null || $r['s_total'] == 0, $r);
$tot = shop_recon_day($day(19), [$AB1, $AB2])['totals']; chk("day totals + problem counter", $tot['problems'] >= 1 && $tot['by']['short'] === 1, $tot);
$mo = shop_recon_month(date('Y-m'), [$AB1, $AB2]); chk("month view has one row per day up to today", count($mo) === (int)date('j'), count($mo));

echo "== reconciliation events (red ones go to Telegram)\n";
setting_set('shop.eod_time', '00:00'); setting_set('shop.recon_next_time', '00:00'); $m = mark(); $t0 = count(tg());
$sh = $shop->sale(1, 3, [[2, 10, 7000]], 'cash', ['time' => '12:40:00']); run(); $e = evs($m, "type = 'recon_gap' AND ref LIKE '%" . date('d.m.Y') . "%'");
chk("today after the end-of-day time with sales but no Argent entry → 🔴 recon_gap event", (bool)$e && $e[0]['severity'] === 'red' && $e[0]['branch_id'] == $AB1, $e);
chk("…and a Telegram alert", count(tg()) > $t0 && (bool)array_filter(array_slice(tg(), $t0), function ($t) { return strpos($t, "Do'kon ↔ Argent farqi") !== false; }));
$m = mark(); run(); chk("same situation again: no duplicate", !evs($m, "type = 'recon_gap' AND ref LIKE '%" . date('d.m.Y') . "%'"));
$m = mark(); $r0 = $st($D, $AB1, 'shop'); $tx($AB1, 'shop', $r0['s_cash'] - $r0['a_cash'], 'cash', $D); if ($r0['s_non'] - $r0['a_non'] > 0) $tx($AB1, 'shop', $r0['s_non'] - $r0['a_non'], 'click', $D); run(); $f = evs($m, "type = 'recon_gap' AND ref LIKE '%" . date('d.m.Y') . "%'"); chk("after the cashier enters the money the problem is gone (no new red)", !array_filter($f, function ($x) { return $x['severity'] === 'red'; }), $f);
$m = mark(); $tx($AB1, "To'lov", 5000, 'cash', $D, 'do\'kon'); run(); chk("mis-categorised entry gets its own 🟡 «Noto'g'ri kategoriya» event", (bool)evs($m, "type = 'recon_gap' AND flags LIKE '%wrong_category%' AND ref_key LIKE 'tx:%'"));
setting_set('shop.eod_time', '23:59'); setting_set('shop.recon_next_time', '23:59');

echo "== daily and weekly reports\n";
$L = shop_daily_lines($day(19)); $txt = implode("\n", $L); chk("21:00 summary has the shop block (status per branch/category, diff)", strpos($txt, "Do'konPro ↔ Argent") !== false && strpos($txt, '🔴') !== false && strpos($txt, '−10 000') !== false, $txt);
$tgm = lcup_daily_report($D); chk("lcup_daily_report() includes the shop block", strpos(implode("\n", $tgm), "Do'konPro ↔ Argent") !== false);
// patterns: seller A (cash only, no returns), seller B (card heavy, returns)
$wf = $day(6); $wt = $D; foreach ([[3, 'cash', 6], [3, 'cash', 5], [4, 'card', 6], [4, 'cash', 5], [4, 'card', 4]] as [$u, $pt, $n]) for ($i = 0; $i < 4; $i++) $shop->sale(1, $u, [[2, 1, 7000]], $pt, ['date' => $day($n), 'time' => '1' . $i . ':00:00']);
$bs = $shop->q("SELECT id FROM sales WHERE seller_id = 4 AND sale_date >= ? ORDER BY id LIMIT 4", [$day(6)]); foreach ($bs as $b) $shop->ret((int)$b['id'], 7000, 7000, 4, date('Y-m-d H:i:s'));
$pat = shop_patterns($wf, $wt); $rows = []; foreach ($pat[$AB1]['rows'] as $x) $rows[$x['id']] = $x;
chk("per-seller numbers: sales, average receipt, cash share, returns, debts", isset($rows[3], $rows[4]) && $rows[4]['returns'] >= 4 && $rows[3]['cash_share'] > $rows[4]['cash_share'] + 0.15 && $rows[3]['avg'] > 0 && array_key_exists('debts', $rows[3]) && array_key_exists('adjust', $rows[3]) && array_key_exists('deleted', $rows[3]), $rows);
chk("seller with many returns and low cash share is flagged, the other is not", isset($rows[4]) && count($rows[4]['flags']) >= 2 && !$rows[3]['flags'], [$rows[3]['flags'] ?? null, $rows[4]['flags'] ?? null]);
chk("shift-day comparison with the branch average is computed", $rows[3]['shift_vs_avg'] !== null && $rows[3]['shift_days'] >= 2, $rows[3]);
$sh = shop_shrinkage($day(30), $D); chk("shrinkage: write-offs, minus adjustments, returns, deleted sales per branch and per staff", $sh['total']['writeoff'][0] >= 1 && $sh['total']['minus'][0] >= 1 && $sh['total']['returns'][0] >= 1 && $sh['total']['deleted'][0] >= 1 && shop_shrink_total($sh['branch'][$AB1]) > 0 && count($sh['staff']) >= 2, $sh['total']);
$wk = implode("\n", shop_weekly_lines($day(0))); $wk2 = implode("\n", shop_weekly_lines(date('Y-m-d', strtotime('+7 days')))); chk("weekly Telegram text: kamomad + sotuvchilar (for the week that contains the data)", strpos($wk2, 'haftalik') !== false && (strpos($wk2, 'Kamomad') !== false || strpos($wk2, 'Sotuvchilar') !== false), $wk2);
chk("lcup_weekly_report() includes the shop part", strpos(implode("\n", lcup_weekly_report(date('Y-m-d', strtotime('+7 days')))), "Do'konPro haftalik") !== false);

echo "== inventory control (blind count)\n";
$cid = shop_inv_start($AB2, $uid); $items = shop_inv_items($cid, false); $names = array_column($items, 'product_name');
chk("5–10 products are picked; both Kitob products are always included", count($items) >= 4 && count($items) <= 10 && in_array('Test Kitob Elementary', $names, true) && in_array('Test Kitob Level 1', $names, true), $names);
chk("a second start returns the same open check (one open check per branch)", shop_inv_start($AB2, $uid) === $cid);
$blind = json_encode($items); chk("BLIND: no system quantity or price in the items shown before counting", !preg_match('/"sys_qty":\s*[0-9]/', $blind) && !preg_match('/"(buy|sell)_price":\s*[0-9]/', $blind), $blind);
$byName = []; foreach ($items as $i) $byName[$i['product_name']] = $i['id'];
$counts = []; foreach ($items as $i) $counts[$i['id']] = ''; $counts[$byName['Test Kitob Elementary']] = '8'; $n = shop_inv_save($cid, $counts, $uid); chk("partial save counts only typed items; check stays open", $n === 1 && db()->query("SELECT status FROM shop_inv_checks WHERE id = $cid")->fetchColumn() === 'open');
$it = shop_inv_items($cid, true); $k = array_values(array_filter($it, function ($x) { return $x['product_name'] === 'Test Kitob Elementary'; }))[0];
$sys = (float)$shop->v("SELECT stock FROM products WHERE id = 5"); $short = $sys - 8;
chk("after counting, the comparison is shown: system $sys vs counted 8 → −$short pcs, at cost and at sale price", $k['sys_qty'] == $sys && $k['diff_qty'] == -$short && $k['diff_cost'] == -$short * 50000 && $k['diff_sale'] == -$short * 65000, $k);
$unc = array_values(array_filter($it, function ($x) { return !$x['counted']; })); chk("items not counted yet still hide the system numbers", $unc && $unc[0]['sys_qty'] === null);
chk("a counted item cannot be re-counted", shop_inv_save($cid, [$byName['Test Kitob Elementary'] => '10'], $uid) === 0 && shop_inv_items($cid, true)[array_search($byName['Test Kitob Elementary'], array_column($it, 'id'))]['counted_qty'] == 8);
chk("negative / non-numeric input is ignored", shop_inv_save($cid, [$unc[0]['id'] => '-3'], $uid) === 0 && shop_inv_save($cid, [$unc[0]['id'] => 'abc'], $uid) === 0);
$m = mark(); $rest = []; foreach ($unc as $x) { $pid = (int)db()->query("SELECT product_id FROM shop_inv_items WHERE id = " . (int)$x['id'])->fetchColumn(); $rest[$x['id']] = (string)(float)$shop->v("SELECT stock FROM products WHERE id = ?", [$pid]); } $n = shop_inv_save($cid, $rest, $uid);
$tt = shop_inv_totals($cid); chk("all counted → check closes; totals: shortage in pcs / at cost / at sale price", db()->query("SELECT status FROM shop_inv_checks WHERE id = $cid")->fetchColumn() === 'done' && $tt['short_qty'] == $short && $tt['short_cost'] == $short * 50000 && $tt['short_sale'] == $short * 65000 && $tt['items'] === count($items), $tt);
$e = ev1($m, 'inv_shortage'); chk("shortage creates a RED event with qty and values", $e && $e['severity'] === 'red' && $e['amount'] == $short * 50000 && strpos($e['details'], fmt($short * 65000)) !== false, $e);
chk("history is kept (done checks stay in the table)", (int)db()->query("SELECT COUNT(*) FROM shop_inv_checks WHERE status = 'done'")->fetchColumn() === 1);
$cid2 = shop_inv_start($AB2, $uid); chk("cancel keeps the record", shop_inv_cancel($cid2) && db()->query("SELECT status FROM shop_inv_checks WHERE id = $cid2")->fetchColumn() === 'cancelled');
$cid3 = shop_inv_start($AB1, $uid); $its = shop_inv_items($cid3, false); $names1 = array_column($its, 'product_name'); chk("branch 1 (no Kitob products) picks top sellers + random, 3 products exist", count($its) === 3 && in_array('Test Suv', $names1, true), $names1);

echo "== health, failures, config\n";
chk("health: last success stored, no failures", shop_state_get('last_success') !== null && (int)shop_state_get('fail_count', 0) === 0);
file_put_contents("$T/private/config.php", "<?php return ['SHOP_DB_PATH'=>'$T/nope.db','TELEGRAM_BOT_TOKEN'=>'123:TESTTOKEN','TELEGRAM_CHAT_ID'=>'555','TELEGRAM_API_BASE'=>'http://127.0.0.1:$port'];"); private_config(true);
$r = shop_run(); chk("database missing: the cron run does not crash, it reports 'config'", $r['skipped'] === 'config' || !$r['ok'], $r);
file_put_contents("$T/private/config.php", "<?php return ['SHOP_DB_PATH'=>'$T/shop.db','TELEGRAM_BOT_TOKEN'=>'123:TESTTOKEN','TELEGRAM_CHAT_ID'=>'555','TELEGRAM_API_BASE'=>'http://127.0.0.1:$port'];"); private_config(true);
$shop->x("ALTER TABLE sale_returns RENAME TO sale_returns_old"); $r = run(); chk("schema drift (a table renamed) is reported clearly, no fatal error", !$r['ok'] && strpos(implode(' ', $r['errors']), 'sale_returns') !== false, $r); $shop->x("ALTER TABLE sale_returns_old RENAME TO sale_returns");
$r = run(); chk("recovers when the schema is back", $r['ok'], $r);
$mapBad = db()->prepare("UPDATE shop_branch_map SET shop_name = 'Mavjud emas filial', shop_branch_id = NULL WHERE branch_id = ?"); $mapBad->execute([$AB2]); $r = run(); chk("an unmatched branch name gives a warning, the other branch keeps working", $r['ok'] && shop_resolve_map() === [$AB1 => 1], shop_resolve_map());
$http = shop_http_exposure(); chk("exposure check answers (the mock DB is outside any web root → private/unknown)", in_array($http['state'], ['private', 'unknown'], true), $http);

echo "\n== $ok passed, $bad failed\n";
exit($bad ? 1 : 0);
