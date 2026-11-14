<?php
/**
 * Inventory control ("Inventarizatsiya tekshiruvi"): Argent picks a few products per branch, a person counts them physically and types the numbers in
 * WITHOUT seeing the system quantity. Only after the count is saved is it compared with the Do'konPro stock of that moment.
 * Reads the shop read-only; the checks and results live in Argent's own tables (shop_inv_checks / shop_inv_items).
 */
require_once __DIR__ . '/shop_sync.php';

/** Picks the products of a check: every Kitob product + the best sellers always, the rest random. Returns [[product row, reason], ...]. */
function shop_inv_pick(int $shopBranch, int $want): array {
    $want = max(5, min(10, $want)); $cats = shop_categories(); $kid = array_keys(array_filter($cats, 'shop_is_kitob_cat'));
    $prod = shop_q("SELECT p.id, p.name, p.category_id, p.stock, p.buy_price, p.sell_price FROM products p WHERE p.branch_id = ? AND p.deleted = 0 AND p.active = 1", [$shopBranch]);
    $by = []; foreach ($prod as $p) $by[(int)$p['id']] = $p; $picked = [];
    foreach ($by as $id => $p) if (in_array((int)$p['category_id'], $kid, true)) $picked[$id] = 'Kitob';
    $top = shop_q("SELECT si.product_id, SUM(si.quantity) AS q FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.branch_id = ? AND s.sale_date >= ? GROUP BY si.product_id ORDER BY q DESC LIMIT 12", [$shopBranch, date('Y-m-d', strtotime('-' . (int)shop_num('inv_top_days') . ' days'))]);
    $n = 0; foreach ($top as $t) { $id = (int)$t['product_id']; if (!isset($by[$id]) || isset($picked[$id])) continue; $picked[$id] = "Eng ko'p sotiladigan"; if (++$n >= 3) break; }
    $want = max($want, min(14, count($picked)));
    $rest = array_values(array_diff(array_keys($by), array_keys($picked))); $k = count($rest);
    while (count($picked) < $want && $k > 0) { $i = random_int(0, $k - 1); $id = $rest[$i]; array_splice($rest, $i, 1); $k--; $picked[$id] = 'Tasodifiy'; }
    $out = []; foreach ($picked as $id => $why) $out[] = [$by[$id], $why];
    shuffle($out);                                                              // the order tells nothing about why a product was chosen
    return $out;
}
function shop_inv_start(int $argentBranch, int $userId): int {
    $map = shop_resolve_map(); $sb = $map[$argentBranch] ?? null; if (!$sb) throw new ShopError("Bu filial Do'konPro bilan moslanmagan.", 'config');
    $open = db()->prepare("SELECT id FROM shop_inv_checks WHERE branch_id = ? AND status = 'open' LIMIT 1"); $open->execute([$argentBranch]); if ($id = $open->fetchColumn()) return (int)$id;   // one open check per branch
    $cats = shop_categories(); $items = shop_inv_pick($sb, (int)shop_num('inv_count')); if (!$items) throw new ShopError("Bu filialda tekshiriladigan tovar topilmadi.", 'config');
    db()->beginTransaction();
    try {
        db()->prepare("INSERT INTO shop_inv_checks (branch_id, shop_branch_id, created_at, created_by, status) VALUES (?,?,?,?, 'open')")->execute([$argentBranch, $sb, shop_now(), $userId]); $id = (int)db()->lastInsertId();
        $ins = db()->prepare("INSERT INTO shop_inv_items (check_id, product_id, product_name, category, reason) VALUES (?,?,?,?,?)");
        foreach ($items as [$p, $why]) $ins->execute([$id, $p['id'], $p['name'], $cats[(int)$p['category_id']] ?? '', $why]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return $id;
}
/** Items of a check as the counting page may show them: NO system quantity for items that have not been counted yet. */
function shop_inv_items(int $checkId, bool $withSystem): array {
    $st = db()->prepare("SELECT * FROM shop_inv_items WHERE check_id = ? ORDER BY id"); $st->execute([$checkId]); $rows = $st->fetchAll();
    foreach ($rows as &$r) { $counted = $r['counted_qty'] !== null; if (!$counted || !$withSystem) foreach (['sys_qty', 'diff_qty', 'diff_cost', 'diff_sale', 'buy_price', 'sell_price'] as $f) $r[$f] = null; $r['counted'] = $counted; } unset($r);
    return $rows;
}
/** Saves the typed counts (item id => quantity). Compares with the shop's stock RIGHT NOW. Returns the number of items saved. */
function shop_inv_save(int $checkId, array $counts, int $userId): int {
    $c = db()->prepare("SELECT * FROM shop_inv_checks WHERE id = ? AND status = 'open'"); $c->execute([$checkId]); $chk = $c->fetch(); if (!$chk) return 0;
    $items = db()->prepare("SELECT * FROM shop_inv_items WHERE check_id = ? AND counted_qty IS NULL"); $items->execute([$checkId]); $n = 0; $now = shop_now();
    $upd = db()->prepare("UPDATE shop_inv_items SET counted_qty = ?, counted_at = ?, sys_qty = ?, buy_price = ?, sell_price = ?, diff_qty = ?, diff_cost = ?, diff_sale = ? WHERE id = ? AND counted_qty IS NULL");
    foreach ($items->fetchAll() as $it) {
        $raw = $counts[$it['id']] ?? null; if ($raw === null || trim((string)$raw) === '') continue;
        $q = str_replace([' ', ','], ['', '.'], (string)$raw); if (!is_numeric($q) || (float)$q < 0 || (float)$q > 1000000) continue; $q = (float)$q;
        $p = shop_one("SELECT stock, buy_price, sell_price FROM products WHERE id = ?", [$it['product_id']]); if (!$p) continue;
        $d = $q - (float)$p['stock']; $upd->execute([$q, $now, $p['stock'], $p['buy_price'], $p['sell_price'], $d, $d * (float)$p['buy_price'], $d * (float)$p['sell_price'], $it['id']]);
        if ($upd->rowCount()) $n++;
    }
    $left = (int)db()->query("SELECT COUNT(*) FROM shop_inv_items WHERE check_id = " . (int)$checkId . " AND counted_qty IS NULL")->fetchColumn();
    if ($n && $left === 0) {
        db()->prepare("UPDATE shop_inv_checks SET status = 'done', completed_at = ? WHERE id = ?")->execute([$now, $checkId]);
        $t = shop_inv_totals($checkId); $u = db()->prepare("SELECT username FROM users WHERE id = ?"); $u->execute([$userId]);
        if ($t['short_cost'] > 0 || $t['short_qty'] > 0) shop_event_add(['type' => 'inv_shortage', 'severity' => 'red', 'branch_id' => (int)$chk['branch_id'], 'shop_branch_id' => (int)$chk['shop_branch_id'], 'event_time' => $now, 'staff' => (string)$u->fetchColumn(), 'ref' => "tekshiruv #$checkId", 'ref_key' => "inv:$checkId", 'amount' => $t['short_cost'],
            'details' => "Inventarizatsiya tekshiruvi: " . $t['items'] . " tovardan " . $t['short_items'] . " tadda kamomad — " . shop_qty($t['short_qty']) . " dona, tannarx " . fmt($t['short_cost']) . ", sotuv narxi " . fmt($t['short_sale']), 'dedupe_key' => "inv|$checkId"]);
    }
    return $n;
}
function shop_inv_cancel(int $checkId): bool { $st = db()->prepare("UPDATE shop_inv_checks SET status = 'cancelled', completed_at = ? WHERE id = ? AND status = 'open'"); $st->execute([shop_now(), $checkId]); return $st->rowCount() > 0; }
/** Totals of a check: shortage (counted < system) and surplus, in pieces, at cost and at sale price. */
function shop_inv_totals(int $checkId): array {
    $t = ['items' => 0, 'short_items' => 0, 'short_qty' => 0.0, 'short_cost' => 0.0, 'short_sale' => 0.0, 'over_qty' => 0.0, 'over_cost' => 0.0, 'over_sale' => 0.0];
    $st = db()->prepare("SELECT diff_qty, diff_cost, diff_sale FROM shop_inv_items WHERE check_id = ? AND counted_qty IS NOT NULL"); $st->execute([$checkId]);
    foreach ($st->fetchAll() as $r) { $t['items']++; if ($r['diff_qty'] < -0.0005) { $t['short_items']++; $t['short_qty'] += -$r['diff_qty']; $t['short_cost'] += -$r['diff_cost']; $t['short_sale'] += -$r['diff_sale']; } elseif ($r['diff_qty'] > 0.0005) { $t['over_qty'] += $r['diff_qty']; $t['over_cost'] += $r['diff_cost']; $t['over_sale'] += $r['diff_sale']; } }
    return $t;
}
