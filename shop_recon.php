<?php
/**
 * Daily reconciliation: what Do'konPro says was received (per branch, per category: Kitob / everything else, cash vs non-cash)
 * against what the staff typed into Argent (category shop / kitob, plus mis-categorised "To'lov" entries).
 *
 *  Do'konPro cash  = cash received at the till for sales (the paid-at-sale part) + cash debt collections − cash refunds
 *  Do'konPro non-cash = card / transfer received the same way. Argent: Naqd ↔ cash;  Click + Bank ↔ non-cash (compared COMBINED).
 * Day boundary is 00:00 (Tashkent). A payment is booked on the day of the sale / the debt payment / the refund.
 */
require_once __DIR__ . '/shop_lib.php';

const SHOP_ST = ['ok' => ['✅', 'Mos'], 'short' => ['🔴', "Do'konda ko'proq — Argentga to'liq kiritilmagan"], 'missing' => ['🔴', "Savdo bor, Argentga kiritilmagan"], 'nokitob' => ['🔴', "Kitob sotuvi Do'konPro'da yo'q"],
    'extra' => ['🟠', "Argentda ko'proq — sotuv Do'konPro'ga kiritilmagan"], 'method' => ['🟡', "To'lov usuli farq qiladi (naqd ↔ Click/Bank)"], 'late' => ['🟡', "Keyingi kunda kiritilgan"], 'wrongcat' => ['🟡', "Noto'g'ri kategoriya"]];
const SHOP_ST_ORDER = ['nokitob' => 0, 'missing' => 1, 'short' => 2, 'extra' => 3, 'method' => 4, 'late' => 5, 'wrongcat' => 6, 'ok' => 7];

function shop_is_kitob_cat(?string $name): bool { return $name !== null && shop_norm($name) === shop_norm((string)shop_setting('kitob_category')); }
function shop_is_cash(?string $m): bool { return shop_lc((string)$m) === 'cash' || shop_lc((string)$m) === 'naqd'; }

/** [sale_id => ['kitob' => w, 'shop' => w]] — share of every sale's value that belongs to the Kitob category (weights sum to 1). */
function shop_sale_shares(array $saleIds): array {
    $cats = shop_categories(); $out = [];
    foreach (array_chunk(array_values(array_unique($saleIds)), 400) as $ch) {
        $st = shop_q("SELECT si.sale_id, si.quantity * si.sell_price AS v, p.category_id FROM sale_items si LEFT JOIN products p ON p.id = si.product_id WHERE si.sale_id IN (" . shop_in($ch) . ")");
        $acc = [];
        foreach ($st as $r) { $k = shop_is_kitob_cat($cats[(int)$r['category_id']] ?? null) ? 'kitob' : 'shop'; $acc[(int)$r['sale_id']][$k] = ($acc[(int)$r['sale_id']][$k] ?? 0) + max(0, (float)$r['v']); }
        foreach ($acc as $sid => $a) { $t = ($a['kitob'] ?? 0) + ($a['shop'] ?? 0); $out[$sid] = $t > 0 ? ['kitob' => ($a['kitob'] ?? 0) / $t, 'shop' => ($a['shop'] ?? 0) / $t] : ['kitob' => 0, 'shop' => 1]; }
    }
    return $out;
}
/** What the shop booked for one shop branch on one day: ['shop' => ['cash','non','sales','debt','refund'], 'kitob' => [...], 'sales' => n, 'has_kitob' => bool, 'kitob_sales' => n]. */
function shop_day_expected(int $sb, string $date): array {
    $z = function () { return ['cash' => 0.0, 'non' => 0.0, 'sales' => 0, 'debt_cash' => 0.0, 'debt_non' => 0.0, 'refund' => 0.0]; };
    $o = ['shop' => $z(), 'kitob' => $z()];
    $sales = shop_q("SELECT id, payment_type, total, initial_paid FROM sales WHERE branch_id = ? AND sale_date = ?", [$sb, $date]);
    $dps = shop_q("SELECT sale_id, amount, method FROM debt_payments WHERE branch_id = ? AND payment_date = ?", [$sb, $date]);
    $rets = shop_q("SELECT r.sale_id, r.refund, s.payment_type FROM sale_returns r LEFT JOIN sales s ON s.id = r.sale_id WHERE r.branch_id = ? AND r.return_date = ?", [$sb, $date]);
    $ids = array_merge(array_column($sales, 'id'), array_column($dps, 'sale_id'), array_column($rets, 'sale_id')); $sh = $ids ? shop_sale_shares($ids) : [];
    $share = function ($sid) use ($sh) { return $sh[(int)$sid] ?? ['kitob' => 0, 'shop' => 1]; };
    foreach ($sales as $s) {
        $pt = shop_lc((string)$s['payment_type']); $got = (float)$s['initial_paid']; if ($pt !== 'debt' && $got <= 0) $got = (float)$s['total'];
        $sw = $share($s['id']);
        foreach (['kitob', 'shop'] as $k) { if ($sw[$k] <= 0) continue; $o[$k]['sales']++; $v = $got * $sw[$k]; if ($pt === 'cash' || $pt === 'debt') $o[$k]['cash'] += $v; else $o[$k]['non'] += $v; }
    }
    foreach ($dps as $d) { $sw = $share($d['sale_id']); foreach (['kitob', 'shop'] as $k) { $v = (float)$d['amount'] * $sw[$k]; if ($v <= 0) continue; if (shop_is_cash($d['method'])) { $o[$k]['cash'] += $v; $o[$k]['debt_cash'] += $v; } else { $o[$k]['non'] += $v; $o[$k]['debt_non'] += $v; } } }
    foreach ($rets as $r) { $sw = $share($r['sale_id']); foreach (['kitob', 'shop'] as $k) { $v = (float)$r['refund'] * $sw[$k]; if ($v <= 0) continue; $o[$k]['refund'] += $v; if (shop_lc((string)$r['payment_type']) === 'card') $o[$k]['non'] -= $v; else $o[$k]['cash'] -= $v; } }
    foreach (['kitob', 'shop'] as $k) foreach (['cash', 'non', 'debt_cash', 'debt_non', 'refund'] as $f) $o[$k][$f] = round($o[$k][$f], 2);
    $cats = shop_categories(); $kid = array_keys(array_filter($cats, 'shop_is_kitob_cat'));
    $o['has_kitob'] = $kid ? (int)shop_val("SELECT COUNT(*) FROM products WHERE branch_id = ? AND category_id IN (" . shop_in($kid) . ") AND deleted = 0", [$sb]) > 0 : false;
    return $o;
}
/** Argent income entries of one branch/day that count as shop/kitob. Each: tx row + cat ('shop'|'kitob') + wrong (mis-categorised) + late (days entered after the business day) + method bucket. */
function shop_argent_entries(int $bid, string $date): array {
    $st = db()->prepare("SELECT t.*, c.name AS cat_name, u.username AS author FROM transactions t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN users u ON u.id = t.user_id WHERE t.branch_id = ? AND t.date = ? AND t.cash_in = 1 ORDER BY t.id");
    $st->execute([$bid, $date]); $out = [];
    foreach ($st->fetchAll() as $t) {
        [$cat, $wrong] = shop_classify_tx($t); if ($cat === null) continue;
        $entered = $t['created_at'] ? date('Y-m-d', strtotime($t['created_at'] . ' UTC')) : $date;
        $t['cat'] = $cat; $t['wrong'] = $wrong; $t['late'] = max(0, (int)round((strtotime($entered) - strtotime($date)) / 86400)); $t['bucket'] = method_of($t) === 'cash' ? 'cash' : 'non'; $t['entered'] = $entered;
        $out[] = $t;
    }
    return $out;
}
/** Reconciliation of one day for the given Argent branch ids. Rows: one per branch and category (Kitob / shop). */
function shop_recon_day(string $date, array $branchIds): array {
    $map = shop_resolve_map(); $tol = max(0.5, shop_num('tolerance')); $rows = []; $lateMin = max(1, (int)shop_num('late_entry_days'));
    foreach ($branchIds as $bid) {
        $bid = (int)$bid; $sb = $map[$bid] ?? null; if (!$sb) continue;
        $exp = shop_day_expected($sb, $date); $ent = shop_argent_entries($bid, $date);
        foreach (['shop', 'kitob'] as $cat) {
            $e = $exp[$cat]; $a = ['cash' => 0.0, 'non' => 0.0, 'n' => 0]; $items = []; $late = []; $wrong = [];
            foreach ($ent as $t) { if ($t['cat'] !== $cat) continue; $a[$t['bucket']] += (float)$t['payment']; $a['n']++; $items[] = $t; if ($t['late'] >= $lateMin) $late[] = $t; if ($t['wrong']) $wrong[] = $t; }
            $sTot = $e['cash'] + $e['non']; $aTot = $a['cash'] + $a['non'];
            if ($sTot <= 0.5 && $aTot <= 0.5 && !$wrong && abs($e['refund']) < 0.5 && !$e['sales']) continue;
            $dc = $a['cash'] - $e['cash']; $dn = $a['non'] - $e['non']; $diff = $aTot - $sTot;
            if ($cat === 'kitob' && $aTot > 0.5 && !$exp['has_kitob']) $st = 'nokitob';
            elseif ($sTot > 0.5 && $aTot <= 0.5) $st = 'missing';
            elseif ($diff < -$tol) $st = 'short';
            elseif ($diff > $tol) $st = 'extra';
            elseif (abs($dc) > $tol || abs($dn) > $tol) $st = 'method';
            elseif ($late) $st = 'late';
            elseif ($wrong) $st = 'wrongcat';
            else $st = 'ok';
            $rows[] = ['key' => "$bid|$cat", 'branch_id' => $bid, 'shop_branch_id' => $sb, 'cat' => $cat, 'date' => $date, 'status' => $st,
                's_cash' => $e['cash'], 's_non' => $e['non'], 's_total' => $sTot, 'a_cash' => $a['cash'], 'a_non' => $a['non'], 'a_total' => $aTot, 'diff' => $diff, 'd_cash' => $dc, 'd_non' => $dn,
                'sales' => $e['sales'], 'debt_cash' => $e['debt_cash'], 'debt_non' => $e['debt_non'], 'refund' => $e['refund'], 'items' => $items, 'late' => $late, 'wrong' => $wrong,
                'late_days' => $late ? max(array_column($late, 'late')) : 0];
        }
    }
    usort($rows, function ($x, $y) { return [SHOP_ST_ORDER[$x['status']], $x['branch_id'], $x['cat']] <=> [SHOP_ST_ORDER[$y['status']], $y['branch_id'], $y['cat']]; });
    $tot = ['shop' => 0, 'argent' => 0, 'problems' => 0, 'by' => array_fill_keys(array_keys(SHOP_ST), 0)];
    foreach ($rows as $r) { $tot['shop'] += $r['s_total']; $tot['argent'] += $r['a_total']; $tot['by'][$r['status']]++; if (!in_array($r['status'], ['ok'], true)) $tot['problems']++; }
    $tot['diff'] = $tot['argent'] - $tot['shop'];
    return ['rows' => $rows, 'totals' => $tot];
}
function shop_recon_month(string $ym, array $branchIds): array {
    $days = []; $last = min(date('Y-m-t', strtotime($ym . '-01')), date('Y-m-d'));
    for ($d = $ym . '-01'; $d <= $last; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $r = shop_recon_day($d, $branchIds); $days[] = ['date' => $d] + $r['totals']; }
    return $days;
}
/** One-line explanation of a row ("Naqd: do'kon 100 000 · Argent 90 000 (−10 000) …"). */
function shop_recon_text(array $r): string {
    $p = ["Naqd: do'kon " . fmt($r['s_cash']) . " · Argent " . fmt($r['a_cash']) . ($r['d_cash'] >= 1 || $r['d_cash'] <= -1 ? ' (' . fmt_signed($r['d_cash']) . ')' : ''),
          "Click+Bank: do'kon " . fmt($r['s_non']) . " · Argent " . fmt($r['a_non']) . ($r['d_non'] >= 1 || $r['d_non'] <= -1 ? ' (' . fmt_signed($r['d_non']) . ')' : '')];
    if ($r['debt_cash'] > 0) $p[] = "shundan qarz yig'ilishi (naqd) " . fmt($r['debt_cash']);
    if ($r['refund'] > 0) $p[] = "naqd qaytarilgan −" . fmt($r['refund']);
    if ($r['late']) $p[] = "keyin kiritilgan: " . count($r['late']) . " ta (" . $r['late_days'] . " kun kech)";
    if ($r['wrong']) $p[] = "noto'g'ri kategoriya: " . count($r['wrong']) . " ta";
    return implode(' · ', $p);
}
function shop_cat_label(string $c): string { return $c === 'kitob' ? 'Kitob' : "Do'kon (kitobsiz)"; }

/**
 * Turns day-level problems into events (and Telegram alerts for the red ones). Called by every run:
 * today after the end-of-day time, and yesterday once the morning grace time has passed (staff often enter the previous day's money next morning).
 */
function shop_recon_events(array $ctx, array &$sum): void {
    $now = date('H:i'); $days = [];
    if ($now >= (string)shop_setting('eod_time')) $days[] = date('Y-m-d');
    if ($now >= (string)shop_setting('recon_next_time')) $days[] = date('Y-m-d', strtotime('-1 day'));
    if (!$days) return;
    $ids = array_keys(shop_resolve_map()); if (!$ids) return;
    foreach ($days as $d) {
        foreach (shop_recon_day($d, $ids)['rows'] as $r) {
            if ($r['status'] === 'ok') continue;
            $red = in_array($r['status'], ['short', 'missing', 'nokitob'], true);      // 🔴 go to Telegram; 🟠/🟡 only to the feed + daily summary
            $sig = md5(implode('|', [round($r['s_cash']), round($r['s_non']), round($r['a_cash']), round($r['a_non'])]));
            $flags = [$r['status'] === 'wrongcat' ? 'wrong_category' : ''];
            shop_event_add(['type' => 'recon_gap', 'severity' => $red ? 'red' : 'yellow', 'branch_id' => $r['branch_id'], 'shop_branch_id' => $r['shop_branch_id'], 'event_time' => $d . ' 23:59:00',
                'ref' => shop_cat_label($r['cat']) . ' · ' . date('d.m.Y', strtotime($d)), 'ref_key' => "recon:{$r['branch_id']}:{$r['cat']}:$d", 'amount' => abs($r['diff']),
                'details' => SHOP_ST[$r['status']][1] . '. ' . shop_recon_text($r), 'flags' => array_filter($flags), 'dedupe_key' => "recon|{$r['branch_id']}|{$r['cat']}|$d|{$r['status']}|$sig"]);
            foreach ($r['wrong'] as $t) shop_event_add(['type' => 'recon_gap', 'severity' => 'yellow', 'branch_id' => $r['branch_id'], 'shop_branch_id' => $r['shop_branch_id'], 'event_time' => $d . ' 23:59:00', 'staff' => $t['author'],
                'ref' => "Argent #" . $t['id'], 'ref_key' => 'tx:' . $t['id'], 'amount' => $t['payment'], 'details' => "«" . ($t['cat_name'] ?? '') . "» kategoriyasida, izohida do'kon/kitob so'zi bor: «" . mb_substr((string)$t['comment'], 0, 80) . "» — " . ($t['cat'] === 'kitob' ? 'kitob' : 'shop') . " kategoriyasiga o'tkazilishi kerak",
                'flags' => ['wrong_category'], 'dedupe_key' => 'wrongcat|' . $t['id']]);
        }
    }
}
