<?php
/**
 * Do'konPro change monitoring. Run by cron.php every minute (after the LC-UP run).
 * Read-only on the shop; the shop keeps no audit log and deletes rows physically, so every run compares the shop with a SNAPSHOT kept in Argent
 * and writes what changed / disappeared as append-only events (shop_events). The first run only takes the snapshot (no alerts for history).
 */
require_once __DIR__ . '/shop_recon.php';

const SHOP_ROLE_LABEL = ['admin' => 'Administrator', 'manager' => 'Filial menejeri', 'seller' => 'Sotuvchi'];
const SHOP_RED_FLAGS = ['items_changed', 'amount_changed', 'payment_changed', 'seller_changed', 'discount_changed', 'time_changed', 'admin_sale', 'blocked_user', 'night_sale', 'debt_no_phone', 'debt_new_customer', 'debt_staff', 'debt_no_customer', 'below_price',
    'late_return', 'other_user', 'cash_refund', 'minus', 'rewrite', 'unknown_type', 'untracked', 'no_comment', 'writeoff'];

function shop_lock() {
    $d = private_dir(); $f = ($d && is_dir($d) && is_writable($d)) ? $d . '/shop.lock' : sys_get_temp_dir() . '/argent_shop.lock';
    $h = @fopen($f, 'c'); if (!$h) return null; if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); return null; } return $h;
}
function shop_user_label(array $ctx, $uid): string {
    $u = $ctx['users'][(int)$uid] ?? null; if (!$u) return $uid ? "#$uid" : '—';
    return trim($u['name']) . ' (' . (SHOP_ROLE_LABEL[$u['role']] ?? $u['role']) . ')';
}
function shop_ev(array $ctx, int $sb, array $e): ?int { $e['shop_branch_id'] = $sb; $e['branch_id'] = $ctx['map'][$sb] ?? null; return shop_event_add($e); }
function shop_ref(array $s): string { return 'chek #' . $s['receipt_no']; }
function shop_qty($n): string { return rtrim(rtrim(number_format((float)$n, 3, '.', ' '), '0'), '.'); }
function shop_digits9(?string $p): string { $d = preg_replace('/\D+/', '', (string)$p); return strlen($d) >= 7 ? substr($d, -9) : ''; }
function shop_sev(array $flags, array $yellowOnly = []): string { $red = array_diff($flags, $yellowOnly); return array_intersect($red, SHOP_RED_FLAGS) ? 'red' : 'yellow'; }

/** Runs one named step. The first time a step runs it only records the baseline ($base = true: no events for history). */
function shop_step(string $name, array &$ctx, array &$sum, callable $f): void {
    $base = !shop_state_get("base_$name");
    try { $f($ctx, $base, $sum); shop_state_set("base_$name", shop_state_get("base_$name") ?: shop_now()); }
    catch (Throwable $e) { $sum['warnings'][] = "$name: " . lcup_redact($e->getMessage()); shop_log("step $name " . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); }
}

function shop_run(array $o = []): array {
    $t0 = microtime(true); $sum = ['ok' => true, 'busy' => false, 'skipped' => null, 'errors' => [], 'warnings' => [], 'new_events' => 0];
    if (shop_setting('enabled') !== '1') { $sum['skipped'] = 'off'; return $sum; }
    if (!shop_configured()) { $sum['skipped'] = 'config'; shop_state_set('last_run', shop_now()); shop_state_set('last_error', "Do'konPro bazasi sozlanmagan (SHOP_DB_PATH)"); return $sum; }
    @set_time_limit(90); @ignore_user_abort(true);
    $lock = shop_lock(); if (!$lock) { $sum['busy'] = true; return $sum; }
    $before = (int)db()->query("SELECT COALESCE(MAX(id),0) FROM shop_events")->fetchColumn();
    $inTx = false;
    try {
        $bad = shop_schema_problems(); if ($bad) throw new ShopError("Do'konPro bazasi tuzilishi kutilganidan farq qiladi: " . implode('; ', array_slice($bad, 0, 6)), 'schema');
        if (!shop_state_get('first_run')) { shop_state_set('first_run', shop_now()); shop_state_set('alerts_since', shop_now()); }
        shop_pdo()->exec('BEGIN'); $inTx = true;                       // one consistent read snapshot for the whole run (a sale and its stock movement are seen together)
        $map = shop_map_inverse(); if (!$map) $sum['warnings'][] = "Filial moslash bo'sh yoki do'kondagi filial nomi topilmadi (Admin → Do'kon).";
        $users = []; foreach (shop_users_all() as $u) $users[(int)$u['id']] = $u; $prod = []; foreach (shop_products_all() as $p) $prod[(int)$p['id']] = $p; $cust = []; foreach (shop_customers_all() as $c) $cust[(int)$c['id']] = $c;
        $ctx = ['map' => $map, 'users' => $users, 'prod' => $prod, 'cust' => $cust, 'cats' => shop_categories(), 'blocked' => [], 'mov_delta' => [], 'cancelled' => []];
        shop_step('users', $ctx, $sum, 'shop_scan_users');
        shop_step('customers', $ctx, $sum, 'shop_scan_customers');
        shop_step('movements', $ctx, $sum, 'shop_scan_movements');
        shop_step('sales', $ctx, $sum, 'shop_scan_sales');
        shop_step('returns', $ctx, $sum, 'shop_scan_returns');
        shop_step('debtpay', $ctx, $sum, 'shop_scan_debtpay');
        shop_step('products', $ctx, $sum, 'shop_scan_products');
        shop_step('purchases', $ctx, $sum, 'shop_scan_purchases');
        shop_step('expenses', $ctx, $sum, 'shop_scan_expenses');
        try { shop_recon_events($ctx, $sum); } catch (Throwable $e) { $sum['warnings'][] = 'recon: ' . lcup_redact($e->getMessage()); shop_log('recon ' . $e->getMessage()); }
        shop_pdo()->exec('COMMIT'); $inTx = false;
    } catch (ShopError $e) { $sum['ok'] = false; $sum['errors'][] = $e->getMessage(); }
    catch (Throwable $e) { $sum['ok'] = false; $sum['errors'][] = 'Ichki xatolik (' . get_class($e) . '): ' . lcup_redact($e->getMessage()); shop_log(get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); }
    if ($inTx) { try { shop_pdo()->exec('COMMIT'); } catch (Throwable $e) {} }
    $sum['new_events'] = (int)db()->query("SELECT COUNT(*) FROM shop_events WHERE id > $before")->fetchColumn();
    try { shop_alerts_flush(); } catch (Throwable $e) { shop_log('alerts: ' . $e->getMessage()); }
    shop_state_set('last_warnings', json_encode(array_slice($sum['warnings'], 0, 12), JSON_UNESCAPED_UNICODE)); shop_state_set('last_seconds', round(microtime(true) - $t0, 2));
    shop_health_update($sum['ok'], implode(' | ', $sum['errors']));
    flock($lock, LOCK_UN); fclose($lock);
    return $sum;
}

/* ============================================================== users: blocked status is recorded by Argent itself */
function shop_scan_users(array &$ctx, bool $base, array &$sum): void {
    $snap = []; foreach (db()->query("SELECT * FROM shop_users")->fetchAll() as $r) $snap[(int)$r['user_id']] = $r; $now = shop_now();
    $ins = db()->prepare("INSERT INTO shop_users (user_id, name, username, role, active, shop_branch_id, blocked_at, first_seen, last_seen) VALUES (?,?,?,?,?,?,?,?,?)");
    $upd = db()->prepare("UPDATE shop_users SET name=?, username=?, role=?, active=?, shop_branch_id=?, blocked_at=?, last_seen=? WHERE user_id=?");
    foreach ($ctx['users'] as $id => $u) {
        $o = $snap[$id] ?? null; $act = (int)$u['active']; $sb = $u['branch_id'] !== null ? (int)$u['branch_id'] : null;
        if (!$o) {
            $ins->execute([$id, $u['name'], $u['username'], $u['role'], $act, $sb, $act ? null : $now, $now, $now]);
            if (!$base) shop_ev($ctx, (int)($sb ?: 0), ['type' => 'user_new', 'severity' => $u['role'] === 'admin' ? 'red' : 'yellow', 'event_time' => $now, 'staff' => shop_user_label($ctx, $id), 'details' => 'Do\'konPro da yangi xodim paydo bo\'ldi', 'dedupe_key' => "unew|$id"]);
            $ctx['blocked'][$id] = $act ? null : $now; continue;
        }
        $blocked = $o['blocked_at'];
        if ((int)$o['active'] !== $act) {
            if (!$act) { $blocked = $now; shop_ev($ctx, (int)($sb ?: 0), ['type' => 'user_blocked', 'severity' => 'info', 'event_time' => $now, 'staff' => shop_user_label($ctx, $id), 'details' => "Xodim bloklandi. Shundan keyingi sotuvlari qizil belgilanadi.", 'dedupe_key' => "ublk|$id|" . date('YmdHis')]); }
            else { $blocked = null; shop_ev($ctx, (int)($sb ?: 0), ['type' => 'user_unblocked', 'severity' => 'yellow', 'event_time' => $now, 'staff' => shop_user_label($ctx, $id), 'details' => "Xodim blokdan chiqarildi", 'dedupe_key' => "uunb|$id|" . date('YmdHis')]); }
        }
        if ($o['role'] !== $u['role']) shop_ev($ctx, (int)($sb ?: 0), ['type' => 'user_changed', 'severity' => 'red', 'event_time' => $now, 'staff' => shop_user_label($ctx, $id), 'details' => "Rol: " . (SHOP_ROLE_LABEL[$o['role']] ?? $o['role']) . ' → ' . (SHOP_ROLE_LABEL[$u['role']] ?? $u['role']), 'dedupe_key' => "urole|$id|{$o['role']}|{$u['role']}|" . date('YmdHis')]);
        $upd->execute([$u['name'], $u['username'], $u['role'], $act, $sb, $blocked, $now, $id]); $ctx['blocked'][$id] = $blocked;
    }
}

/* ============================================================== customers */
function shop_scan_customers(array &$ctx, bool $base, array &$sum): void {
    $snap = []; foreach (db()->query("SELECT * FROM shop_customers")->fetchAll() as $r) $snap[(int)$r['customer_id']] = $r; $now = shop_now();
    $ins = db()->prepare("INSERT INTO shop_customers (customer_id, shop_branch_id, name, phone, note, address, first_seen, baseline) VALUES (?,?,?,?,?,?,?,?)");
    $upd = db()->prepare("UPDATE shop_customers SET name=?, phone=?, note=?, address=? WHERE customer_id=?");
    foreach ($ctx['cust'] as $id => $c) {
        $o = $snap[$id] ?? null;
        if (!$o) { $ins->execute([$id, $c['branch_id'], $c['name'], $c['phone'], $c['note'], $c['address'], $now, $base ? 1 : 0]); continue; }
        $chg = []; foreach (['name' => 'Ism', 'phone' => 'Telefon', 'note' => 'Izoh', 'address' => 'Manzil'] as $f => $l) if ((string)$o[$f] !== (string)$c[$f]) $chg[$f] = $l . ': «' . mb_substr((string)$o[$f], 0, 40) . '» → «' . mb_substr((string)$c[$f], 0, 40) . '»';
        if ($chg) {
            if (!$base) shop_ev($ctx, (int)$c['branch_id'], ['type' => 'customer_edited', 'severity' => 'yellow', 'event_time' => $now, 'ref' => 'Mijoz #' . $id, 'details' => implode('; ', $chg), 'ref_key' => "cust:$id", 'dedupe_key' => "cedit|$id|" . md5(json_encode($chg))]);
            $upd->execute([$c['name'], $c['phone'], $c['note'], $c['address'], $id]);
        }
    }
}

/* ============================================================== stock movements (Ombor → Harakatlar tarixi) */
/** normal | purchase_cancel | transfer_out | transfer_in | adjust | writeoff | inventory | other — by the movement's stored type name. */
function shop_move_kind(string $type, float $qty): string {
    $t = shop_lc($type);
    if (in_array($t, ['sale', 'purchase', 'initial', 'return', 'sale_return', 'sale_returned'], true)) return 'normal';
    if ($t === 'purchase_cancel') return 'purchase_cancel';
    if (preg_match('/transfer|ko.?chir/u', $t)) return $qty < 0 ? 'transfer_out' : 'transfer_in';
    if (preg_match('/invent|stocktak|count|sanoq/u', $t)) return 'inventory';
    if (preg_match('/write|waste|spoil|damage|loss|hisobdan|chiqar/u', $t)) return 'writeoff';
    if (preg_match('/adjust|tuzat|correct|manual/u', $t)) return 'adjust';
    return 'other';
}
function shop_scan_movements(array &$ctx, bool $base, array &$sum): void {
    $last = (int)shop_state_get('last_move_id', 0); $rows = shop_movements($last); $now = shop_now();
    foreach ($rows as $m) {
        $pid = (int)$m['product_id']; $q = (float)$m['qty_change']; $ctx['mov_delta'][$pid] = ($ctx['mov_delta'][$pid] ?? 0) + $q; $last = max($last, (int)$m['id']);
        $kind = shop_move_kind((string)$m['type'], $q); $p = $ctx['prod'][$pid] ?? null; $sb = (int)$m['branch_id']; $name = $p ? $p['name'] : "tovar #$pid";
        if ($kind === 'purchase_cancel') $ctx['cancelled'][(int)$m['ref_id']] = true;
        if ($base || $kind === 'normal') continue;
        $cost = abs($q) * ($p ? (float)$p['buy_price'] : 0); $sale = abs($q) * ($p ? (float)$p['sell_price'] : 0);
        $noc = trim((string)$m['note']) === ''; $t = $m['created_at'] ?: $now;
        $line = "Tovar: $name · " . ($q > 0 ? '+' : '−') . shop_qty(abs($q)) . " (qoldiq " . shop_qty($m['qty_after']) . ") · tannarx " . fmt($cost) . " · sotuv qiymati " . fmt($sale) . ' · izoh: ' . ($noc ? "YO'Q" : mb_substr((string)$m['note'], 0, 120));
        $base_ev = ['event_time' => $t, 'staff' => shop_user_label($ctx, $m['user_id']), 'amount' => $cost, 'ref' => "ombor #{$m['id']}", 'ref_key' => "move:{$m['id']}", 'dedupe_key' => 'mov|' . $m['id'], 'flags' => $noc ? ['no_comment'] : [], 'before' => ['type' => $m['type'], 'qty' => $q]];
        if ($kind === 'purchase_cancel') shop_ev($ctx, $sb, ['type' => 'purchase_cancelled', 'severity' => 'red'] + ['details' => "Kirim bekor qilindi (kirim #" . (int)$m['ref_id'] . "). " . $line] + $base_ev);
        elseif ($kind === 'adjust') shop_ev($ctx, $sb, ['type' => 'stock_adjust', 'severity' => 'red', 'flags' => array_merge($q < 0 ? ['minus'] : [], $noc ? ['no_comment'] : []), 'details' => "Qo'lda tuzatish. $line"] + $base_ev);
        elseif ($kind === 'writeoff') shop_ev($ctx, $sb, ['type' => 'stock_writeoff', 'severity' => 'red', 'flags' => array_merge(['writeoff'], $noc ? ['no_comment'] : []), 'details' => "Hisobdan chiqarish. $line"] + $base_ev);
        elseif ($kind === 'inventory') shop_ev($ctx, $sb, ['type' => 'stock_inventory', 'severity' => $q < 0 ? 'red' : 'yellow', 'flags' => array_merge($q < 0 ? ['minus'] : [], $noc ? ['no_comment'] : []), 'details' => "Inventarizatsiya. $line"] + $base_ev);
        elseif ($kind === 'other') shop_ev($ctx, $sb, ['type' => 'stock_other', 'severity' => 'red', 'flags' => ['unknown_type'], 'details' => "Noma'lum harakat turi «{$m['type']}». $line"] + $base_ev);
        elseif ($kind === 'transfer_out') { db()->prepare("INSERT OR IGNORE INTO shop_transfers (move_id, from_branch, ref_id, product_id, product_name, qty, created_at, staff) VALUES (?,?,?,?,?,?,?,?)")->execute([$m['id'], $sb, $m['ref_id'], $pid, $name, abs($q), $t, shop_user_label($ctx, $m['user_id'])]); }
    }
    if ($rows) shop_state_set('last_move_id', $last);
    if (!$base) shop_check_transfers($ctx);
}
/** A "chiqdi" movement must be followed by a "kirdi" in another branch within transfer_wait_h hours. */
function shop_check_transfers(array $ctx): void {
    $wait = max(1, (int)shop_num('transfer_wait_h')) * 3600; $now = time();
    foreach (db()->query("SELECT * FROM shop_transfers WHERE state = 'open'")->fetchAll() as $o) {
        $cands = shop_q("SELECT id, branch_id, product_id, type, qty_change, ref_id, created_at FROM stock_movements WHERE id > ? AND branch_id <> ? AND qty_change > 0 ORDER BY id LIMIT 400", [$o['move_id'], $o['from_branch']]); $hit = false;
        foreach ($cands as $c) {
            if (shop_move_kind((string)$c['type'], (float)$c['qty_change']) !== 'transfer_in') continue;
            if ($o['ref_id'] && $c['ref_id']) { if ((int)$o['ref_id'] === (int)$c['ref_id']) { $hit = true; break; } continue; }
            $pn = shop_val("SELECT name FROM products WHERE id = ?", [$c['product_id']]);
            if ($pn !== null && shop_norm((string)$pn) === shop_norm((string)$o['product_name']) && abs((float)$c['qty_change'] - (float)$o['qty']) < 0.0005) { $hit = true; break; }
        }
        if ($hit) { db()->prepare("UPDATE shop_transfers SET state = 'done' WHERE move_id = ?")->execute([$o['move_id']]); continue; }
        if ($now - strtotime($o['created_at']) >= $wait) {
            shop_ev($ctx, (int)$o['from_branch'], ['type' => 'transfer_unmatched', 'severity' => 'red', 'event_time' => $o['created_at'], 'staff' => $o['staff'], 'ref' => "ko'chirish (ombor #{$o['move_id']})", 'ref_key' => 'move:' . $o['move_id'],
                'details' => "«{$o['product_name']}» " . shop_qty($o['qty']) . " dona chiqdi, " . (int)shop_num('transfer_wait_h') . " soat ichida boshqa filialda «kirdi» yozuvi topilmadi", 'dedupe_key' => 'xfer|' . $o['move_id']]);
            db()->prepare("UPDATE shop_transfers SET state = 'alerted' WHERE move_id = ?")->execute([$o['move_id']]);
        }
    }
}

/* ============================================================== sales */
function shop_sale_flags(array $ctx, array $s): array {
    $red = []; $yel = []; $sb = (int)$s['branch_id']; $sid = (int)$s['seller_id']; $u = $ctx['users'][$sid] ?? null;
    if ($u && $u['role'] === 'admin') $red[] = 'admin_sale';
    $bl = $ctx['blocked'][$sid] ?? null; if ($bl && $s['ts'] >= $bl) $red[] = 'blocked_user';
    $t = substr((string)$s['sale_time'], 0, 5); if ($t < (string)shop_setting('work_from') || $t >= (string)shop_setting('work_to')) $red[] = 'night_sale';
    $why = [];
    if (shop_lc((string)$s['payment_type']) === 'debt') {
        $cid = (int)$s['customer_id']; $c = $cid ? ($ctx['cust'][$cid] ?? null) : null;
        if (!$c) { $red[] = 'debt_no_customer'; }
        else {
            if (shop_digits9($c['phone']) === '') $red[] = 'debt_no_phone';
            if ((int)shop_val("SELECT COUNT(*) FROM sales WHERE customer_id = ? AND id < ?", [$cid, $s['id']]) === 0) $red[] = 'debt_new_customer';
            $nm = shop_norm((string)$c['name']); $ph = shop_digits9($c['phone']);
            foreach ($ctx['users'] as $uu) if (($nm !== '' && $nm === shop_norm((string)$uu['name'])) || ($ph !== '' && $ph === shop_digits9($uu['phone'] ?? ''))) { $red[] = 'debt_staff'; $why[] = 'xodim: ' . $uu['name']; break; }
        }
        $debt = (float)$s['total'] - (float)($s['initial_paid'] ?? 0); if ($debt > 0 && $debt < shop_num('small_debt')) $yel[] = 'small_debt';
    }
    $low = [];
    foreach ($s['items'] as $i) { $p = $ctx['prod'][(int)$i['product_id']] ?? null; if ($p && (float)$i['sell_price'] < (float)$p['sell_price'] - 0.5) $low[] = trim($i['product_name']) . ' ' . fmt($i['sell_price']) . ' < ' . fmt($p['sell_price']); }
    if ($low) { $red[] = 'below_price'; $why[] = implode(', ', array_slice($low, 0, 3)); }
    $min = shop_sale_min_after((int)$s['id']); if ($min !== null && (float)$min < 0) $yel[] = 'negative_stock';
    return [$red, $yel, $why];
}
function shop_sale_new_event(array $ctx, array $s): void {
    [$red, $yel, $why] = shop_sale_flags($ctx, $s); if (!$red && !$yel) return;
    $cust = $s['customer_id'] ? ($ctx['cust'][(int)$s['customer_id']]['name'] ?? ('#' . $s['customer_id'])) : '—';
    shop_ev($ctx, (int)$s['branch_id'], ['type' => 'sale_flagged', 'severity' => $red ? 'red' : 'yellow', 'flags' => array_merge($red, $yel), 'event_time' => $s['ts'], 'staff' => shop_user_label($ctx, $s['seller_id']), 'ref' => shop_ref($s), 'ref_key' => 'sale:' . $s['id'], 'amount' => $s['total'],
        'details' => mb_substr(trim(strtoupper($s['payment_type']) . ' · mijoz: ' . $cust . ' · ' . $s['items_txt'] . ($why ? ' · ' . implode('; ', $why) : '')), 0, 600), 'dedupe_key' => 'snew|' . $s['id']]);
}
function shop_scan_sales(array &$ctx, bool $base, array &$sum): void {
    $days = max(7, (int)shop_num('window_days')); $from = date('Y-m-d', strtotime("-$days days")); $now = shop_now();
    $live = shop_sales($days); $liveIds = []; $snap = [];
    $q = db()->prepare("SELECT * FROM shop_sales WHERE sale_date >= ?"); $q->execute([$from]); foreach ($q->fetchAll() as $r) $snap[(int)$r['sale_id']] = $r;
    $put = db()->prepare("INSERT OR REPLACE INTO shop_sales (sale_id, shop_branch_id, receipt_no, customer_id, seller_id, sale_date, sale_time, total, discount, paid, returned_amount, payment_type, status, note, items_hash, items_txt, dp_sum, ret_sum, first_seen, last_seen, baseline, deleted_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)");
    $store = function (array $s, ?array $o) use ($put, $now, $base) { $put->execute([$s['id'], $s['branch_id'], $s['receipt_no'], $s['customer_id'], $s['seller_id'], $s['sale_date'], $s['sale_time'], $s['total'], $s['discount'], $s['paid'], $s['returned_amount'], $s['payment_type'], $s['status'], $s['note'],
        $s['items_hash'], $s['items_txt'], $s['dp_sum'], $s['ret_sum'], $o['first_seen'] ?? $now, $now, $o ? $o['baseline'] : ($base ? 1 : 0)]); };
    $maxBy = [];
    foreach ($live as $s) {
        $id = (int)$s['id']; $liveIds[$id] = true; $sb = (int)$s['branch_id']; $maxBy[$sb] = max($maxBy[$sb] ?? 0, (int)$s['receipt_no']); $o = $snap[$id] ?? null;
        if (!$o) { if (!$base) shop_sale_new_event($ctx, $s); $store($s, null); continue; }
        if (!$base) shop_sale_changes($ctx, $s, $o);
        $store($s, $o);
    }
    $missing = array_values(array_filter(array_keys($snap), function ($id) use ($liveIds, $snap) { return empty($liveIds[$id]) && empty($snap[$id]['deleted_at']); }));
    if ($missing) {
        $exists = shop_existing_sale_ids($missing);
        foreach ($missing as $id) { if (isset($exists[$id])) continue; $o = $snap[$id];
            if (!$base) {
                $age = max(0, (int)round((time() - strtotime($o['sale_date'] . ' ' . $o['sale_time'])) / 60));
                shop_ev($ctx, (int)$o['shop_branch_id'], ['type' => 'sale_deleted', 'severity' => 'red', 'event_time' => $now, 'staff' => shop_user_label($ctx, $o['seller_id']), 'ref' => 'chek #' . $o['receipt_no'], 'ref_key' => 'sale:' . $id, 'amount' => $o['total'],
                    'details' => "Chek do'kondan o'chirildi (sotuv " . substr($o['sale_time'], 0, 5) . ", " . $o['sale_date'] . "; " . ($age < 120 ? "$age daq." : round($age / 60, 1) . ' soat') . " o'tgach). " . strtoupper((string)$o['payment_type']) . ' · ' . $o['items_txt'] . " · kim o'chirgani do'konda saqlanmaydi, sotuvchi ko'rsatilgan",
                    'before' => ['total' => $o['total'], 'items' => $o['items_txt']], 'dedupe_key' => 'sdel|' . $id]);
            }
            db()->prepare("UPDATE shop_sales SET deleted_at = ? WHERE sale_id = ?")->execute([$now, $id]);
        }
    }
    shop_receipt_gaps($ctx, $base, $maxBy);
}
function shop_sale_changes(array $ctx, array $s, array $o): void {
    $chg = []; $fl = []; $yel = []; $after = []; $before = [];
    if ($o['items_hash'] !== $s['items_hash']) { $fl[] = 'items_changed'; $chg[] = 'Tovarlar: ' . ($o['items_txt'] ?: '—') . ' → ' . ($s['items_txt'] ?: '—'); $before['items'] = $o['items_txt']; $after['items'] = $s['items_txt']; }
    if (abs((float)$o['total'] - (float)$s['total']) > 0.5) { $fl[] = 'amount_changed'; $chg[] = 'Summa: ' . fmt($o['total']) . ' → ' . fmt($s['total']); $before['total'] = $o['total']; $after['total'] = $s['total']; }
    if (shop_lc((string)$o['payment_type']) !== shop_lc((string)$s['payment_type'])) { $fl[] = 'payment_changed'; $chg[] = "To'lov turi: " . $o['payment_type'] . ' → ' . $s['payment_type']; $before['payment'] = $o['payment_type']; $after['payment'] = $s['payment_type']; }
    if ((int)$o['seller_id'] !== (int)$s['seller_id']) { $fl[] = 'seller_changed'; $chg[] = 'Sotuvchi: ' . shop_user_label($ctx, $o['seller_id']) . ' → ' . shop_user_label($ctx, $s['seller_id']); $after['seller'] = $s['seller_id']; }
    if (abs((float)$o['discount'] - (float)$s['discount']) > 0.5) { $fl[] = 'discount_changed'; $chg[] = 'Chegirma: ' . fmt($o['discount']) . ' → ' . fmt($s['discount']); $after['discount'] = $s['discount']; }
    if ($o['sale_date'] !== $s['sale_date'] || substr($o['sale_time'], 0, 5) !== substr($s['sale_time'], 0, 5)) { $fl[] = 'time_changed'; $chg[] = 'Vaqt: ' . $o['sale_date'] . ' ' . substr($o['sale_time'], 0, 5) . ' → ' . $s['sale_date'] . ' ' . substr($s['sale_time'], 0, 5); $after['time'] = $s['sale_date'] . ' ' . $s['sale_time']; }
    if ((int)$o['customer_id'] !== (int)$s['customer_id']) { $yel[] = 'customer_changed'; $chg[] = 'Mijoz: #' . ($o['customer_id'] ?: '—') . ' → #' . ($s['customer_id'] ?: '—'); $after['customer'] = $s['customer_id']; }
    if ((string)$o['note'] !== (string)$s['note']) { $yel[] = 'note_changed'; $chg[] = 'Izoh: «' . mb_substr((string)$o['note'], 0, 40) . '» → «' . mb_substr((string)$s['note'], 0, 40) . '»'; $after['note'] = $s['note']; }
    $age = max(0, (int)round((time() - strtotime($s['ts'])) / 60));
    if ($chg) shop_ev($ctx, (int)$s['branch_id'], ['type' => 'sale_changed', 'severity' => $fl ? 'red' : 'yellow', 'flags' => array_merge($fl, $yel, $age > 60 ? ['late_edit'] : []), 'event_time' => shop_now(), 'staff' => shop_user_label($ctx, $s['seller_id']), 'ref' => shop_ref($s), 'ref_key' => 'sale:' . $s['id'], 'amount' => $s['total'],
        'details' => implode('; ', $chg) . ' (chek yaratilgach ' . ($age < 120 ? "$age daq." : round($age / 60, 1) . ' soat') . ' o\'tib tahrirlangan)', 'before' => $before, 'after' => $after, 'dedupe_key' => 'sch|' . $s['id'] . '|' . md5(json_encode($after))]);
    // debt reduced without a recorded payment / written off
    $dPaid = (float)$s['paid'] - (float)$o['paid']; $dRec = (float)$s['dp_sum'] - (float)$o['dp_sum'];
    if ($dPaid > 0.5 && $dPaid - $dRec > 0.5) shop_ev($ctx, (int)$s['branch_id'], ['type' => 'debt_reduced', 'severity' => 'red', 'flags' => [], 'event_time' => shop_now(), 'staff' => shop_user_label($ctx, $s['seller_id']), 'ref' => shop_ref($s), 'ref_key' => 'sale:' . $s['id'], 'amount' => $dPaid - $dRec,
        'details' => "Qarz " . fmt($dPaid) . " ga kamaygan, lekin qarz to'lovi yozuvi faqat " . fmt($dRec) . " (farq " . fmt($dPaid - $dRec) . ")", 'dedupe_key' => 'dred|' . $s['id'] . '|' . round($s['paid'])]);
    elseif (shop_lc((string)$o['status']) === 'debt' && shop_lc((string)$s['status']) !== 'debt' && (float)$s['paid'] + 0.5 < (float)$s['total'] && $dPaid <= 0.5) shop_ev($ctx, (int)$s['branch_id'], ['type' => 'debt_reduced', 'severity' => 'red', 'flags' => [], 'event_time' => shop_now(), 'staff' => shop_user_label($ctx, $s['seller_id']), 'ref' => shop_ref($s), 'ref_key' => 'sale:' . $s['id'],
        'amount' => (float)$s['total'] - (float)$s['paid'], 'details' => "Qarz holati yopilgan, lekin to'lanmagan: jami " . fmt($s['total']) . ", to'langan " . fmt($s['paid']) . " (qarz hisobdan chiqarilgan bo'lishi mumkin)", 'dedupe_key' => 'dwo|' . $s['id']]);
    if (abs((float)$s['returned_amount'] - (float)$s['ret_sum']) > 0.5 && abs((float)$s['returned_amount'] - (float)$o['returned_amount']) > 0.5) shop_ev($ctx, (int)$s['branch_id'], ['type' => 'return_mismatch', 'severity' => 'red', 'event_time' => shop_now(), 'staff' => shop_user_label($ctx, $s['seller_id']), 'ref' => shop_ref($s), 'ref_key' => 'sale:' . $s['id'], 'amount' => $s['returned_amount'],
        'details' => "Chekda qaytarilgan summa " . fmt($s['returned_amount']) . ", lekin qaytarish yozuvlari jami " . fmt($s['ret_sum']), 'dedupe_key' => 'rmis|' . $s['id'] . '|' . round($s['returned_amount'])]);
}
/** A receipt number that exists neither in the shop nor in our snapshot (a sale created and deleted between two runs). Only numbers above the baseline are considered. */
function shop_receipt_gaps(array $ctx, bool $base, array $maxBy): void {
    foreach ($maxBy as $sb => $max) {
        $key = "rcpt_base_$sb"; if ($base || shop_state_get($key) === null) { shop_state_set($key, $max); continue; }
        $b = (int)shop_state_get($key); if ($max <= $b) continue;
        $have = []; foreach (shop_q("SELECT receipt_no FROM sales WHERE branch_id = ? AND receipt_no > ?", [$sb, $b]) as $r) $have[(int)$r['receipt_no']] = true;
        $st = db()->prepare("SELECT receipt_no FROM shop_sales WHERE shop_branch_id = ? AND receipt_no > ?"); $st->execute([$sb, $b]); foreach ($st->fetchAll() as $r) $have[(int)$r['receipt_no']] = true;
        for ($n = $b + 1; $n < $max; $n++) if (empty($have[$n])) shop_ev($ctx, $sb, ['type' => 'receipt_gap', 'severity' => 'red', 'event_time' => shop_now(), 'ref' => "chek #$n", 'ref_key' => "receipt:$sb:$n", 'flags' => [],
            'details' => "Chek raqami #$n do'konda yo'q (#" . ($n - 1) . " va #" . ($n + 1) . " orasida). Chek yaratilib, bir daqiqa ichida o'chirilgan bo'lishi mumkin.", 'dedupe_key' => "gap|$sb|$n"]);
        shop_state_set($key, $max);
    }
}

/* ============================================================== returns */
function shop_scan_returns(array &$ctx, bool $base, array &$sum): void {
    $last = (int)shop_state_get('last_return_id', 0); $rows = shop_returns($last); if (!$rows) return;
    foreach ($rows as $r) {
        $last = max($last, (int)$r['id']); if ($base) continue;
        $s = shop_sale_one((int)$r['sale_id']); $ts = $s ? shop_sale_ts($s) : null; $when = $r['created_at'] ?: shop_now();
        $mins = $ts ? (int)round((strtotime($when) - strtotime($ts)) / 60) : null; $fl = [];
        if ($mins !== null && $mins > (int)shop_num('return_late_min')) $fl[] = 'late_return';
        if ($s && (int)$r['user_id'] !== (int)$s['seller_id']) $fl[] = 'other_user';
        if ((float)$r['refund'] > 0 && (!$s || shop_lc((string)$s['payment_type']) !== 'card')) $fl[] = 'cash_refund';
        $sb = (int)$r['branch_id'];
        shop_ev($ctx, $sb, ['type' => 'return_new', 'severity' => 'red', 'flags' => $fl, 'event_time' => $when, 'staff' => shop_user_label($ctx, $r['user_id']), 'ref' => $s ? shop_ref($s) : 'sotuv #' . $r['sale_id'], 'ref_key' => 'sale:' . $r['sale_id'], 'amount' => $r['amount'],
            'details' => "Qaytarish summasi " . fmt($r['amount']) . ", qaytarilgan pul " . fmt($r['refund']) . ($s ? ' · sotuvchi: ' . shop_user_label($ctx, $s['seller_id']) . ' · sotuvdan ' . ($mins < 120 ? "$mins daq." : round($mins / 60, 1) . ' soat') . ' keyin' : '') . ($r['note'] ? ' · izoh: ' . mb_substr($r['note'], 0, 100) : '') . ($r['items'] ? ' · ' . mb_substr((string)$r['items'], 0, 160) : ''), 'dedupe_key' => 'ret|' . $r['id']]);
    }
    shop_state_set('last_return_id', $last);
}

/* ============================================================== debt payments */
function shop_scan_debtpay(array &$ctx, bool $base, array &$sum): void {
    $days = max(7, (int)shop_num('window_days')); $from = date('Y-m-d', strtotime("-$days days")); $now = shop_now();
    $live = []; foreach (shop_debt_payments(0, $from) as $r) $live[(int)$r['id']] = $r;
    $q = db()->prepare("SELECT * FROM shop_debtpay WHERE payment_date >= ?"); $q->execute([$from]); $snap = []; foreach ($q->fetchAll() as $r) $snap[(int)$r['dp_id']] = $r;
    $put = db()->prepare("INSERT OR REPLACE INTO shop_debtpay (dp_id, sale_id, shop_branch_id, amount, method, payment_date, created_at, user_id, first_seen) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($live as $id => $r) {
        $o = $snap[$id] ?? null;
        if ($o && !$base && (abs((float)$o['amount'] - (float)$r['amount']) > 0.5 || shop_lc((string)$o['method']) !== shop_lc((string)$r['method'])))
            shop_ev($ctx, (int)$r['branch_id'], ['type' => 'debt_payment_changed', 'severity' => 'red', 'event_time' => $now, 'staff' => shop_user_label($ctx, $r['user_id']), 'ref' => 'qarz to\'lovi #' . $id, 'ref_key' => 'dp:' . $id, 'amount' => $r['amount'],
                'details' => "Summa " . fmt($o['amount']) . ' → ' . fmt($r['amount']) . ', usul ' . $o['method'] . ' → ' . $r['method'], 'dedupe_key' => 'dpchg|' . $id . '|' . round($r['amount']) . '|' . $r['method']]);
        $put->execute([$id, $r['sale_id'], $r['branch_id'], $r['amount'], $r['method'], $r['payment_date'], $r['created_at'], $r['user_id'], $o['first_seen'] ?? $now]);
    }
    $gone = array_values(array_filter(array_keys($snap), function ($id) use ($live) { return !isset($live[$id]); }));
    if ($gone && !$base) { $ex = shop_existing_ids('debt_payments', $gone);
        foreach ($gone as $id) { if (isset($ex[$id])) continue; $o = $snap[$id];
            shop_ev($ctx, (int)$o['shop_branch_id'], ['type' => 'debt_payment_deleted', 'severity' => 'red', 'event_time' => $now, 'staff' => shop_user_label($ctx, $o['user_id']), 'ref' => 'qarz to\'lovi #' . $id, 'ref_key' => 'dp:' . $id, 'amount' => $o['amount'],
                'details' => "Qarz to'lovi o'chirildi: " . fmt($o['amount']) . " (" . $o['method'] . "), sana " . $o['payment_date'] . ", chek #" . ((int)(db()->query("SELECT receipt_no FROM shop_sales WHERE sale_id = " . (int)$o['sale_id'])->fetchColumn())), 'dedupe_key' => 'dpdel|' . $id]);
            db()->prepare("DELETE FROM shop_debtpay WHERE dp_id = ?")->execute([$id]); } }
}

/* ============================================================== products: prices, deletion, stock changes without a movement */
function shop_scan_products(array &$ctx, bool $base, array &$sum): void {
    $snap = []; foreach (db()->query("SELECT * FROM shop_products")->fetchAll() as $r) $snap[(int)$r['product_id']] = $r; $now = shop_now();
    $put = db()->prepare("INSERT OR REPLACE INTO shop_products (product_id, shop_branch_id, name, category_id, sell_price, buy_price, stock, deleted, active, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($ctx['prod'] as $id => $p) {
        $o = $snap[$id] ?? null; $sb = (int)$p['branch_id'];
        if ($o && !$base) {
            $old = (float)$o['sell_price']; $new = (float)$p['sell_price'];
            if (abs($old - $new) > 0.5) {
                $key = 'product:' . $id; $ev = ['ref' => $p['name'], 'ref_key' => $key, 'event_time' => $now, 'amount' => abs($new - $old), 'before' => ['sell_price' => $old], 'after' => ['sell_price' => $new]];
                if ($new < $old) shop_ev($ctx, $sb, $ev + ['type' => 'price_down', 'severity' => 'red', 'details' => "Sotuv narxi tushirildi: " . fmt($old) . ' → ' . fmt($new) . " (" . round(($old - $new) / max($old, 1) * 100) . "%). Kim o'zgartirgani do'konda saqlanmaydi.", 'dedupe_key' => "pdn|$id|" . round($old) . '|' . round($new) . '|' . date('YmdHi')]);
                else {
                    $st = db()->prepare("SELECT id, before_json FROM shop_events WHERE ref_key = ? AND type = 'price_down' AND detected_at >= ? ORDER BY id DESC LIMIT 1"); $st->execute([$key, date('Y-m-d H:i:s', strtotime('-24 hours'))]); $pd = $st->fetch();
                    $was = $pd ? (float)(json_decode((string)$pd['before_json'], true)['sell_price'] ?? 0) : 0;
                    if ($pd && abs($was - $new) <= 0.5) shop_ev($ctx, $sb, $ev + ['type' => 'price_restored', 'severity' => 'red', 'flags' => ['rewrite'], 'details' => "Narx 24 soat ichida tushirilib, qaytarib oshirilgan: " . fmt($old) . ' → ' . fmt($new) . " (sotuvlar orasida past narxda sotilgan bo'lishi mumkin)", 'dedupe_key' => "prs|$id|" . date('YmdHi')]);
                    else shop_ev($ctx, $sb, $ev + ['type' => 'price_up', 'severity' => 'yellow', 'details' => "Sotuv narxi oshirildi: " . fmt($old) . ' → ' . fmt($new), 'dedupe_key' => "pup|$id|" . round($old) . '|' . round($new) . '|' . date('YmdHi')]);
                }
            }
            $dStock = (float)$p['stock'] - (float)$o['stock']; $dMove = $ctx['mov_delta'][$id] ?? 0;
            if (abs($dStock - $dMove) > 0.0005) shop_ev($ctx, $sb, ['type' => 'stock_adjust', 'severity' => 'red', 'flags' => ['untracked'], 'event_time' => $now, 'ref' => $p['name'], 'ref_key' => 'product:' . $id, 'amount' => abs($dStock - $dMove) * (float)$p['buy_price'],
                'details' => "Tovar qoldig'i " . shop_qty($o['stock']) . ' → ' . shop_qty($p['stock']) . ", lekin ombor harakatlarida izi yo'q (harakatlar bo'yicha kutilgan o'zgarish " . shop_qty($dMove) . "). Tannarx " . fmt(abs($dStock - $dMove) * (float)$p['buy_price']), 'dedupe_key' => "pst|$id|" . round($p['stock'], 3) . '|' . date('YmdHi')]);
            if ((int)$p['deleted'] === 1 && (int)$o['deleted'] === 0) shop_ev($ctx, $sb, ['type' => 'product_deleted', 'severity' => (float)$p['stock'] > 0 ? 'red' : 'yellow', 'event_time' => $now, 'ref' => $p['name'], 'ref_key' => 'product:' . $id, 'amount' => (float)$p['stock'] * (float)$p['buy_price'],
                'details' => "Tovar o'chirildi, qoldig'i " . shop_qty($p['stock']) . " (tannarx " . fmt((float)$p['stock'] * (float)$p['buy_price']) . ")", 'dedupe_key' => "pdel|$id"]);
        }
        $put->execute([$id, $sb, $p['name'], $p['category_id'], $p['sell_price'], $p['buy_price'], $p['stock'], $p['deleted'], $p['active'], $now]);
    }
    if (!$base) foreach ($snap as $id => $o) if (!isset($ctx['prod'][$id]) && (int)$o['deleted'] === 0) {
        shop_ev($ctx, (int)$o['shop_branch_id'], ['type' => 'product_deleted', 'severity' => (float)$o['stock'] > 0 ? 'red' : 'yellow', 'event_time' => $now, 'ref' => $o['name'], 'ref_key' => 'product:' . $id, 'amount' => (float)$o['stock'] * (float)$o['buy_price'],
            'details' => "Tovar bazadan butunlay o'chirildi, oxirgi qoldiq " . shop_qty($o['stock']), 'dedupe_key' => "pdel|$id"]);
        db()->prepare("UPDATE shop_products SET deleted = 1 WHERE product_id = ?")->execute([$id]);
    }
}

/* ============================================================== purchases (Kirim) */
function shop_scan_purchases(array &$ctx, bool $base, array &$sum): void {
    $live = shop_purchases_recent(120); $liveIds = []; $now = shop_now();
    $snap = []; foreach (db()->query("SELECT * FROM shop_purchases")->fetchAll() as $r) $snap[(int)$r['purchase_id']] = $r;
    $put = db()->prepare("INSERT OR REPLACE INTO shop_purchases (purchase_id, shop_branch_id, purchase_date, total, paid, items_hash, items_txt, first_seen, last_seen) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($live as $p) {
        $id = (int)$p['id']; $liveIds[$id] = true; $o = $snap[$id] ?? null; $sb = (int)$p['branch_id']; $who = shop_user_label($ctx, $p['user_id']);
        if (!$o && !$base) {
            $pct = shop_num('cost_jump_pct'); $hi = [];
            foreach ($p['items'] as $i) { if (!$i['product_id'] || (float)$i['buy_price'] <= 0) continue; $prev = shop_prev_unit_costs($sb, (int)$i['product_id'], $id); if (count($prev) < 2) continue; $avg = array_sum($prev) / count($prev);
                if ((float)$i['buy_price'] > $avg * (1 + $pct / 100)) $hi[] = trim($i['product_name']) . ': ' . fmt($i['buy_price']) . " (odatda ≈ " . fmt($avg) . ", +" . round(((float)$i['buy_price'] / $avg - 1) * 100) . "%)"; }
            if ($hi) shop_ev($ctx, $sb, ['type' => 'cost_high', 'severity' => 'red', 'event_time' => ($p['created_at'] ?: $now), 'staff' => $who, 'ref' => "kirim #$id", 'ref_key' => "purchase:$id", 'amount' => $p['total'], 'details' => implode('; ', array_slice($hi, 0, 5)), 'dedupe_key' => "chigh|$id"]);
        }
        if ($o && !$base && ($o['items_hash'] !== $p['items_hash'] || abs((float)$o['total'] - (float)$p['total']) > 0.5)) {
            $after = ['total' => $p['total'], 'items' => $p['items_txt']];
            shop_ev($ctx, $sb, ['type' => 'purchase_edited', 'severity' => 'red', 'event_time' => $now, 'staff' => $who, 'ref' => "kirim #$id", 'ref_key' => "purchase:$id", 'amount' => $p['total'], 'before' => ['total' => $o['total'], 'items' => $o['items_txt']], 'after' => $after,
                'details' => "Kirim yaratilgach o'zgartirilgan. Summa " . fmt($o['total']) . ' → ' . fmt($p['total']) . ($o['items_hash'] !== $p['items_hash'] ? ". Tovarlar: " . ($o['items_txt'] ?: '—') . ' → ' . ($p['items_txt'] ?: '—') : ''), 'dedupe_key' => "ped|$id|" . md5(json_encode($after))]);
        }
        $put->execute([$id, $sb, $p['purchase_date'], $p['total'], $p['paid'], $p['items_hash'], $p['items_txt'], $o['first_seen'] ?? $now, $now]);
    }
    if (!$base) { $gone = array_values(array_filter(array_keys($snap), function ($id) use ($liveIds) { return empty($liveIds[$id]); }));
        $ex = $gone ? shop_existing_ids('purchases', $gone) : [];
        foreach ($gone as $id) { if (isset($ex[$id])) continue; $o = $snap[$id]; if ($o['purchase_date'] < date('Y-m-d', strtotime('-118 days'))) continue;
            if (empty($ctx['cancelled'][$id])) shop_ev($ctx, (int)$o['shop_branch_id'], ['type' => 'purchase_deleted', 'severity' => 'red', 'event_time' => $now, 'ref' => "kirim #$id", 'ref_key' => "purchase:$id", 'amount' => $o['total'], 'details' => "Kirim o'chirildi: " . fmt($o['total']) . ' · ' . $o['items_txt'] . ' (sana ' . $o['purchase_date'] . ')', 'dedupe_key' => "pdl|$id"]);
            db()->prepare("DELETE FROM shop_purchases WHERE purchase_id = ?")->execute([$id]); } }
}

/* ============================================================== expenses */
function shop_scan_expenses(array &$ctx, bool $base, array &$sum): void {
    $last = (int)shop_state_get('last_expense_id', 0); $rows = shop_expenses($last);
    foreach ($rows as $r) { $last = max($last, (int)$r['id']); if ($base) continue;
        shop_ev($ctx, (int)$r['branch_id'], ['type' => 'expense_added', 'severity' => 'red', 'event_time' => $r['created_at'] ?: shop_now(), 'staff' => shop_user_label($ctx, $r['user_id']), 'ref' => 'xarajat #' . $r['id'], 'ref_key' => 'expense:' . $r['id'], 'amount' => $r['amount'],
            'details' => $r['category'] . ' · ' . $r['method'] . ' · sana ' . $r['expense_date'] . ($r['note'] ? ' · ' . mb_substr($r['note'], 0, 120) : " · izoh yo'q"), 'flags' => trim((string)$r['note']) === '' ? ['no_comment'] : [], 'dedupe_key' => 'exp|' . $r['id']]); }
    if ($rows) shop_state_set('last_expense_id', $last);
}
