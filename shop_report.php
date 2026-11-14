<?php
/** Do'konPro: lines for the 21:00 Telegram summary, weekly shrinkage ("kamomad") report and per-seller patterns. */
require_once __DIR__ . '/shop_sync.php';
require_once __DIR__ . '/lcup_report.php';
require_once __DIR__ . '/shop_inv.php';

/** Telegram lines for one day: reconciliation per branch + yellow items. */
function shop_daily_lines(string $date): array {
    if (!shop_configured() || shop_setting('enabled') !== '1') return [];
    $L = ["\n🛒 <b>Do'konPro ↔ Argent — " . date('d.m.Y', strtotime($date)) . "</b>"];
    try {
        $ids = array_keys(shop_resolve_map()); $res = shop_recon_day($date, $ids); $by = [];
        foreach ($res['rows'] as $r) $by[$r['branch_id']][] = $r;
        foreach ($ids as $bid) {
            $L[] = "🏢 <b>" . lcup_h(branch_get($bid)['name'] ?? ('#' . $bid)) . "</b>";
            if (empty($by[$bid])) { $L[] = "Bugun do'kon savdosi ham, Argent yozuvi ham yo'q."; continue; }
            foreach ($by[$bid] as $r) { [$em, $lbl] = SHOP_ST[$r['status']];
                $L[] = "$em " . lcup_h(shop_cat_label($r['cat'])) . ": " . lcup_h($r['status'] === 'ok' ? 'mos' : $lbl) . " — do'kon " . fmt($r['s_total']) . " · Argent " . fmt($r['a_total']) . ($r['status'] === 'ok' ? '' : ' (' . fmt_signed($r['diff']) . ')');
                if ($r['status'] !== 'ok') $L[] = '   ' . lcup_h(shop_recon_text($r)); }
        }
        $st = db()->prepare("SELECT * FROM shop_events WHERE event_date = ? AND severity = 'yellow' AND backfill = 0 ORDER BY id DESC LIMIT 12"); $st->execute([$date]); $ys = $st->fetchAll();
        $yn = (int)db()->query("SELECT COUNT(*) FROM shop_events WHERE event_date = " . db()->quote($date) . " AND severity = 'yellow' AND backfill = 0")->fetchColumn();
        $L[] = "🔴 Ko'rilmagan (do'kon): <b>" . shop_unread_count() . "</b>" . (lcup_app_url() ? "  " . lcup_h(lcup_app_url() . '/shop.php?status=unread') : '');
        if ($yn) { $L[] = "🟡 <b>Kun davomida ($yn ta, faqat hisobotda):</b>"; foreach ($ys as $e) $L[] = "• " . date('H:i', strtotime($e['event_time'] ?: $e['detected_at'])) . ' ' . lcup_h(shop_type_label($e['type']) . ($e['ref'] ? ' · ' . $e['ref'] : '') . ' — ' . mb_substr((string)$e['details'], 0, 80)); if ($yn > 12) $L[] = "… va yana " . ($yn - 12) . " ta"; }
        $m = shop_minutes_since_run(); if ($m !== null && $m >= 15) $L[] = "⚠️ Do'konPro monitoring $m daqiqadan beri ishlamagan — ma'lumotlar eskirgan bo'lishi mumkin.";
    } catch (Throwable $e) { $L[] = "⚠️ Do'konPro hisobotini tuzib bo'lmadi: " . lcup_h(lcup_redact($e->getMessage())); }
    return $L;
}

/* ----------------------------------------------------------------- weekly shrinkage */
/** Value of what left the shelves without a normal sale, per branch and per staff, from the event log. */
function shop_shrinkage(string $from, string $to): array {
    $st = db()->prepare("SELECT branch_id, type, flags, staff, amount, before_json FROM shop_events WHERE event_date BETWEEN ? AND ? AND backfill = 0 AND type IN ('stock_writeoff','stock_adjust','stock_inventory','return_new','sale_deleted')");
    $st->execute([$from, $to]); $blank = ['writeoff' => [0, 0.0], 'minus' => [0, 0.0], 'untracked' => [0, 0.0], 'returns' => [0, 0.0], 'deleted' => [0, 0.0]]; $out = ['branch' => [], 'staff' => [], 'total' => $blank];
    foreach ($st->fetchAll() as $e) {
        $fl = explode(',', (string)$e['flags']); $k = null;
        if ($e['type'] === 'stock_writeoff') $k = 'writeoff';
        elseif ($e['type'] === 'return_new') $k = 'returns';
        elseif ($e['type'] === 'sale_deleted') $k = 'deleted';
        elseif ($e['type'] === 'stock_adjust' && in_array('untracked', $fl, true)) $k = 'untracked';
        elseif (in_array($e['type'], ['stock_adjust', 'stock_inventory'], true) && in_array('minus', $fl, true)) $k = 'minus';
        if ($k === null) continue;
        $b = (int)$e['branch_id']; $s = (string)($e['staff'] ?: '—'); $v = (float)$e['amount'];
        foreach ([['branch', $b], ['staff', $b . '|' . $s]] as [$grp, $key]) { if (!isset($out[$grp][$key])) $out[$grp][$key] = $blank; $out[$grp][$key][$k][0]++; $out[$grp][$key][$k][1] += $v; }
        $out['total'][$k][0]++; $out['total'][$k][1] += $v;
    }
    return $out;
}
function shop_shrink_total(array $x): float { return $x['writeoff'][1] + $x['minus'][1] + $x['untracked'][1] + $x['returns'][1] + $x['deleted'][1]; }
const SHOP_SHRINK_LABEL = ['writeoff' => 'Hisobdan chiqarish', 'minus' => 'Minus tuzatish/inventarizatsiya', 'untracked' => "Harakatsiz qoldiq o'zgarishi", 'returns' => 'Qaytarishlar', 'deleted' => "O'chirilgan cheklar"];

/* ----------------------------------------------------------------- per-seller patterns */
/** Per shop branch: one row per seller with the numbers asked for + flags where someone is clearly off compared with the others. */
function shop_patterns(string $from, string $to): array {
    $map = shop_resolve_map(); $users = []; foreach (shop_users_all() as $u) $users[(int)$u['id']] = $u; $ctx = ['users' => $users]; $res = [];
    $lowR = shop_num('pat_low_ratio'); $hiR = shop_num('pat_high_ratio'); $minEv = (int)shop_num('pat_min_events');
    foreach ($map as $bid => $sb) {
        $rows = []; $branchDay = []; $shift = [];
        foreach (shop_q("SELECT sale_date, SUM(total) AS t FROM sales WHERE branch_id = ? AND sale_date BETWEEN ? AND ? GROUP BY sale_date", [$sb, $from, $to]) as $r) $branchDay[$r['sale_date']] = (float)$r['t'];
        foreach (shop_q("SELECT DISTINCT seller_id, sale_date FROM sales WHERE branch_id = ? AND sale_date BETWEEN ? AND ?", [$sb, $from, $to]) as $r) $shift[(int)$r['seller_id']][] = $r['sale_date'];
        $allAvg = $branchDay ? array_sum($branchDay) / count($branchDay) : 0;
        foreach (shop_q("SELECT seller_id, COUNT(*) AS n, SUM(total) AS rev, SUM(CASE WHEN payment_type = 'cash' THEN total ELSE 0 END) AS cash, SUM(CASE WHEN payment_type = 'debt' THEN 1 ELSE 0 END) AS debts,
                SUM(CASE WHEN payment_type = 'debt' THEN total ELSE 0 END) AS debt_sum FROM sales WHERE branch_id = ? AND sale_date BETWEEN ? AND ? GROUP BY seller_id", [$sb, $from, $to]) as $r) {
            $id = (int)$r['seller_id']; $days = $shift[$id] ?? []; $onShift = $days ? array_sum(array_map(function ($d) use ($branchDay) { return $branchDay[$d] ?? 0; }, $days)) / count($days) : 0;
            $ret = shop_q("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS a FROM sale_returns WHERE branch_id = ? AND user_id = ? AND return_date BETWEEN ? AND ?", [$sb, $id, $from, $to])[0];
            $del = db()->prepare("SELECT COUNT(*) FROM shop_sales WHERE shop_branch_id = ? AND seller_id = ? AND substr(deleted_at,1,10) BETWEEN ? AND ?"); $del->execute([$sb, $id, $from, $to]);
            $lab = shop_user_label($ctx, $id); $adj = db()->prepare("SELECT COUNT(*) FROM shop_events WHERE branch_id = ? AND staff = ? AND event_date BETWEEN ? AND ? AND backfill = 0 AND type IN ('stock_adjust','stock_writeoff','stock_inventory')"); $adj->execute([$bid, $lab, $from, $to]);
            $rows[$id] = ['id' => $id, 'name' => $lab, 'role' => $users[$id]['role'] ?? '', 'n' => (int)$r['n'], 'rev' => (float)$r['rev'], 'avg' => $r['n'] ? (float)$r['rev'] / $r['n'] : 0, 'cash_share' => $r['rev'] > 0 ? (float)$r['cash'] / $r['rev'] : 0,
                'returns' => (int)$ret['n'], 'return_sum' => (float)$ret['a'], 'deleted' => (int)$del->fetchColumn(), 'debts' => (int)$r['debts'], 'debt_sum' => (float)$r['debt_sum'], 'adjust' => (int)$adj->fetchColumn(),
                'shift_days' => count($days), 'per_day' => $days ? (float)$r['rev'] / count($days) : 0, 'branch_on_shift' => $onShift, 'shift_vs_avg' => $allAvg > 0 ? $onShift / $allAvg : null, 'flags' => []];
        }
        $cmp = array_filter($rows, function ($r) { return $r['role'] !== 'admin' && $r['n'] >= 5; });
        foreach ($cmp as $id => $r) {
            $oth = array_filter($cmp, function ($x) use ($id) { return $x['id'] !== $id; }); if (!$oth) continue;
            $mean = function ($f) use ($oth) { return array_sum(array_map($f, $oth)) / count($oth); };
            $opd = $mean(function ($x) { return $x['per_day']; }); $ocs = $mean(function ($x) { return $x['cash_share']; });
            $orr = $mean(function ($x) { return $x['returns'] / max(1, $x['n']); }); $odd = $mean(function ($x) { return $x['deleted'] / max(1, $x['n']); });
            if ($opd > 0 && $r['per_day'] < $lowR * $opd) $rows[$id]['flags'][] = "smena kuniga savdo boshqalardan ancha past (" . fmt($r['per_day']) . " vs " . fmt($opd) . ")";
            if ($ocs - $r['cash_share'] >= 0.15) $rows[$id]['flags'][] = "naqd ulushi past (" . round($r['cash_share'] * 100) . "% vs " . round($ocs * 100) . "%)";
            if ($r['returns'] >= $minEv && $r['returns'] / $r['n'] >= $hiR * max($orr, 0.005)) $rows[$id]['flags'][] = "qaytarishlar ancha ko'p (" . $r['returns'] . " ta)";
            if ($r['deleted'] >= $minEv && $r['deleted'] / $r['n'] >= $hiR * max($odd, 0.005)) $rows[$id]['flags'][] = "o'chirilgan cheklar ancha ko'p (" . $r['deleted'] . " ta)";
            if ($r['shift_vs_avg'] !== null && $r['shift_days'] >= 2 && $r['shift_vs_avg'] < $lowR) $rows[$id]['flags'][] = "uning smena kunlari filial o'rtachasidan past (" . round($r['shift_vs_avg'] * 100) . "%)";
        }
        usort($rows, function ($a, $b) { return $b['rev'] <=> $a['rev']; });
        $res[$bid] = ['branch_id' => $bid, 'shop_branch_id' => $sb, 'rows' => $rows, 'avg_day' => $allAvg, 'days' => count($branchDay)];
    }
    return $res;
}
function shop_week_range(?string $today = null): array {
    $today = $today ?: date('Y-m-d'); $mon = date('Y-m-d', strtotime('monday this week', strtotime($today)));
    return [date('Y-m-d', strtotime($mon . ' -7 days')), date('Y-m-d', strtotime($mon . ' -1 day'))];
}
/** Weekly Telegram text (Monday): shrinkage per branch and staff, inventory checks, seller patterns. */
function shop_weekly_lines(?string $today = null): array {
    if (!shop_configured() || shop_setting('enabled') !== '1') return [];
    [$from, $to] = shop_week_range($today); $L = ["🛒 <b>Do'konPro haftalik hisobot</b> — " . date('d.m', strtotime($from)) . '–' . date('d.m.Y', strtotime($to))];
    try {
        $sh = shop_shrinkage($from, $to); $pat = shop_patterns($from, $to);
        foreach (array_keys(shop_resolve_map()) as $bid) {
            $L[] = "\n🏢 <b>" . lcup_h(branch_get($bid)['name'] ?? '#' . $bid) . "</b>"; $b = $sh['branch'][$bid] ?? null;
            if ($b && shop_shrink_total($b) > 0) { $L[] = "📉 Kamomad jami: <b>" . fmt(shop_shrink_total($b)) . "</b> so'm"; foreach (SHOP_SHRINK_LABEL as $k => $l) if ($b[$k][0]) $L[] = "• " . $l . ": " . $b[$k][0] . " ta · " . fmt($b[$k][1]) . " so'm";
                foreach ($sh['staff'] as $key => $x) { [$kb, $nm] = explode('|', $key, 2); if ((int)$kb !== $bid || shop_shrink_total($x) <= 0) continue; $L[] = "👤 " . lcup_h($nm) . ": " . fmt(shop_shrink_total($x)) . " so'm"; } }
            else $L[] = "📉 Kamomad: hafta davomida hodisa qayd etilmadi.";
            $ic = db()->prepare("SELECT c.id, c.completed_at FROM shop_inv_checks c WHERE c.branch_id = ? AND c.status = 'done' AND substr(c.completed_at,1,10) BETWEEN ? AND ?"); $ic->execute([$bid, $from, $to]);
            foreach ($ic->fetchAll() as $c) { $t = shop_inv_totals((int)$c['id']); $L[] = "📋 Inventarizatsiya #{$c['id']} (" . date('d.m', strtotime($c['completed_at'])) . "): " . $t['items'] . " tovar, kamomad " . fmt($t['short_cost']) . " (tannarx) / " . fmt($t['short_sale']) . " (sotuv narxi)"; }
            $p = $pat[$bid] ?? null;
            if ($p && $p['rows']) { $L[] = "👥 <b>Sotuvchilar</b> (filial kuniga o'rtacha " . fmt($p['avg_day']) . "):";
                foreach ($p['rows'] as $r) { $L[] = ($r['flags'] ? '🔺 ' : '👤 ') . lcup_h($r['name']) . ": " . $r['n'] . " chek · o'rtacha " . fmt($r['avg']) . " · naqd " . round($r['cash_share'] * 100) . "% · qaytarish " . $r['returns'] . " · o'chirilgan " . $r['deleted'] . " · qarz " . $r['debts'] . " · tuzatish " . $r['adjust'];
                    foreach ($r['flags'] as $f) $L[] = "   ⚠️ " . lcup_h($f); } }
        }
    } catch (Throwable $e) { $L[] = "⚠️ Do'konPro haftalik hisobotini tuzib bo'lmadi: " . lcup_h(lcup_redact($e->getMessage())); }
    return $L;
}
