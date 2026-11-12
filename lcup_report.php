<?php
/** Daily 21:00 summary and Monday weekly report (Telegram), and the "settings changed" record. Everything is computed from lcup_events. */
require_once __DIR__ . '/lcup_lib.php';
require_once __DIR__ . '/shop_lib.php';

const LCUP_CATS = ['created' => ['payment_created'], 'edited' => ['payment_changed', 'amount_changed', 'log_edit'], 'deleted' => ['payment_deleted', 'log_delete'], 'discount' => ['discount_new', 'discount_changed', 'discount_removed'], 'debt' => ['debt_reduced', 'charge_changed']];
const LCUP_CAT_LABEL = ['created' => 'yaratilgan', 'edited' => 'tahrirlangan', 'deleted' => "o'chirilgan", 'discount' => 'chegirma', 'debt' => 'qarz/oylik'];

function lcup_h(string $s): string { return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8'); }
/** [branch_id][staff][category] => count, for event_date in [from, to]. */
function lcup_counts(string $from, string $to): array {
    $st = db()->prepare("SELECT branch_id, COALESCE(NULLIF(staff,''), '—') AS s, type, COUNT(*) AS n FROM lcup_events WHERE event_date BETWEEN ? AND ? AND backfill = 0 GROUP BY branch_id, s, type");
    $st->execute([$from, $to]); $out = [];
    foreach ($st->fetchAll() as $r) foreach (LCUP_CATS as $cat => $types) if (in_array($r['type'], $types, true)) $out[(int)$r['branch_id']][$r['s']][$cat] = ($out[(int)$r['branch_id']][$r['s']][$cat] ?? 0) + (int)$r['n'];
    return $out;
}
function lcup_gaps_between(string $from, string $to): array {
    $st = db()->prepare("SELECT * FROM lcup_gaps WHERE substr(to_at,1,10) >= ? AND substr(from_at,1,10) <= ? ORDER BY id"); $st->execute([$from, $to]); return $st->fetchAll();
}
function lcup_gap_text(array $g): string { return date('d.m H:i', strtotime($g['from_at'])) . '–' . date('d.m H:i', strtotime($g['to_at'])) . ($g['filled'] ? ' (to\'ldirildi)' : ' (to\'ldirilmoqda)'); }

/** @return string[] Telegram messages (each ≤ ~3500 chars) */
function lcup_daily_report(string $date): array {
    $L = ["📊 <b>LC-UP kunlik xulosa — " . date('d.m.Y', strtotime($date)) . "</b>"]; $cnt = lcup_counts($date, $date);
    foreach (lcup_map_rows() as $m) {
        $bid = (int)$m['branch_id']; $t = lcup_reconcile($date, [$bid])['totals'];
        $L[] = "\n🏢 <b>" . lcup_h($m['branch_name']) . "</b>";
        $L[] = "LC-UP: " . fmt($t['lcup']) . " · Argent: " . fmt($t['argent']) . " · farq: " . fmt_signed($t['diff']) . ($t['problems'] ? " · ❗ " . $t['problems'] . " muammo" : " · ✅ mos");
        foreach (($cnt[$bid] ?? []) as $staff => $c) { $p = []; foreach (LCUP_CAT_LABEL as $k => $l) if (!empty($c[$k])) $p[] = $l . ' ' . $c[$k]; if ($p) $L[] = "👤 " . lcup_h($staff) . ": " . implode(' · ', $p); }
        if (empty($cnt[$bid])) $L[] = "Bugun LC-UP da hodisa qayd etilmadi.";
    }
    $unread = lcup_unread_count(); $L[] = "\n🔴 Ko'rilmagan: <b>$unread</b>" . (lcup_app_url() ? "  " . lcup_h(lcup_app_url() . '/lcup.php?status=unread') : '');
    $y = db()->prepare("SELECT lcup_time, detected_at, student_name, details, branch_id FROM lcup_events WHERE event_date = ? AND severity = 'yellow' AND backfill = 0 ORDER BY id DESC LIMIT 15"); $y->execute([$date]); $ys = $y->fetchAll();
    $yn = (int)db()->query("SELECT COUNT(*) FROM lcup_events WHERE event_date = " . db()->quote($date) . " AND severity = 'yellow' AND backfill = 0")->fetchColumn();
    if ($yn) { $L[] = "\n🟡 <b>Kun davomida ($yn ta, faqat hisobotda):</b>"; foreach ($ys as $e) $L[] = "• " . date('H:i', strtotime($e['lcup_time'] ?: $e['detected_at'])) . ' ' . lcup_h(trim(($e['student_name'] ?: '') . ' — ' . mb_substr((string)$e['details'], 0, 90), ' —')); if ($yn > 15) $L[] = "… va yana " . ($yn - 15) . " ta"; }
    $gs = lcup_gaps_between($date, $date); if ($gs) $L[] = "\n⏸ Uzilishlar: " . lcup_h(implode('; ', array_map('lcup_gap_text', $gs)));
    if (function_exists('shop_daily_lines')) foreach (shop_daily_lines($date) as $l) $L[] = $l;     // Do'konPro ↔ Argent
    return lcup_chunk($L);
}
/** Previous full Monday–Sunday week vs the week before it; sharp increases are flagged. */
function lcup_weekly_report(?string $today = null): array {
    $today = $today ?: date('Y-m-d'); $mon = date('Y-m-d', strtotime('monday this week', strtotime($today))); $curFrom = date('Y-m-d', strtotime($mon . ' -7 days')); $curTo = date('Y-m-d', strtotime($mon . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($curFrom . ' -7 days')); $prevTo = date('Y-m-d', strtotime($curFrom . ' -1 day'));
    $cur = lcup_counts($curFrom, $curTo); $prev = lcup_counts($prevFrom, $prevTo);
    $L = ["📈 <b>LC-UP haftalik hisobot</b> — " . date('d.m', strtotime($curFrom)) . '–' . date('d.m.Y', strtotime($curTo)) . " (o'tgan hafta: " . date('d.m', strtotime($prevFrom)) . '–' . date('d.m', strtotime($prevTo)) . ")"];
    foreach (lcup_map_rows() as $m) {
        $bid = (int)$m['branch_id']; $L[] = "\n🏢 <b>" . lcup_h($m['branch_name']) . "</b>";
        $staffs = array_unique(array_merge(array_keys($cur[$bid] ?? []), array_keys($prev[$bid] ?? []))); sort($staffs);
        foreach ($staffs as $s) {
            $p = []; $spike = false;
            foreach (LCUP_CAT_LABEL as $k => $l) {
                $c = (int)($cur[$bid][$s][$k] ?? 0); $o = (int)($prev[$bid][$s][$k] ?? 0); if (!$c && !$o) continue;
                $hot = $k !== 'created' && $c >= 3 && $c >= 2 * $o; if ($hot) $spike = true;
                $p[] = $l . ' ' . $c . ($o ? ' (' . ($c >= $o ? '+' : '−') . abs($c - $o) . ')' : ' (yangi)') . ($hot ? ' ⚠️' : '');
            }
            $L[] = ($spike ? '🔺 ' : '👤 ') . lcup_h($s) . ': ' . implode(' · ', $p);
        }
        if (!$staffs) $L[] = "Hafta davomida hodisa qayd etilmadi.";
    }
    $red = db()->prepare("SELECT COUNT(*) FROM lcup_events WHERE severity = 'red' AND backfill = 0 AND event_date BETWEEN ? AND ?"); $red->execute([$curFrom, $curTo]);
    $L[] = "\n🔴 Hafta davomida qizil hodisa: " . (int)$red->fetchColumn() . " · hozir ko'rilmagan: " . lcup_unread_count();
    $gs = lcup_gaps_between($curFrom, $curTo); if ($gs) $L[] = "⏸ Uzilishlar: " . lcup_h(implode('; ', array_map('lcup_gap_text', $gs)));
    $L[] = "\n⚠️ = shu hafta ≥3 ta va o'tgan haftadan kamida 2 baravar ko'p.";
    if (function_exists('shop_weekly_lines')) { $L[] = "\n"; foreach (shop_weekly_lines($today) as $l) $L[] = $l; }       // Do'konPro: kamomad + sotuvchilar
    return lcup_chunk($L);
}
function lcup_chunk(array $lines, int $max = 3400): array {
    $out = []; $cur = '';
    foreach ($lines as $l) { if ($cur !== '' && mb_strlen($cur) + mb_strlen($l) + 1 > $max) { $out[] = $cur; $cur = ''; } $cur .= ($cur === '' ? '' : "\n") . $l; }
    if ($cur !== '') $out[] = $cur; return $out;
}
function lcup_send_all(array $chunks, ?string &$err = null): bool { foreach ($chunks as $c) { if (!lcup_tg_send($c, $err)) return false; usleep(300000); } return true; }
/** Called at the end of every run: sends the 21:00 summary and the Monday report once. */
function lcup_reports_due(): void {
    if (lcup_setting('alerts_on') !== '1' || !lcup_tg_ready()) return;
    $now = date('H:i'); $d = date('Y-m-d');
    if ($now >= (string)lcup_setting('summary_time') && !lcup_state_get("daily_$d") && lcup_send_all(lcup_daily_report($d))) lcup_state_set("daily_$d", lcup_now());
    $wk = date('oW'); if (date('N') === '1' && $now >= (string)lcup_setting('weekly_time') && !lcup_state_get("weekly_$wk") && lcup_send_all(lcup_weekly_report())) lcup_state_set("weekly_$wk", lcup_now());
}

/** Journal + event + Telegram for a change of the monitoring settings. $fields = names only, never values. */
function lcup_settings_changed(array $fields, ?array $oldTg = null): void {
    if (!$fields) return; $u = auth_user(); $by = $u['username'] ?? 'noma\'lum';
    audit('lcup.config', 'lcup', null, ['fields' => array_values($fields)]);
    lcup_event_add(['type' => 'settings_changed', 'source' => 'system', 'staff' => $by, 'lcup_time' => lcup_now(), 'details' => "LC-UP monitoring sozlamalari o'zgartirildi — " . $by . ": " . implode(', ', $fields),
        'dedupe_key' => 'cfg|' . bin2hex(random_bytes(8))]);
    lcup_alerts_flush();                                                              // goes to the (new) Telegram settings right away
    [$nt, $nc] = lcup_tg();
    if ($oldTg && $oldTg[0] !== '' && ($oldTg[0] !== $nt || $oldTg[1] !== $nc))      // the OLD chat is told too: somebody who redirects alerts must not be able to do it silently
        lcup_tg_send("⚙️ <b>LC-UP monitoring sozlamalari o'zgartirildi</b>\nKim: " . lcup_h($by) . "\nMaydonlar: " . lcup_h(implode(', ', $fields)) . "\n⚠️ Telegram sozlamalari ham o'zgardi — ogohlantirishlar endi boshqa joyga ketishi mumkin.", $e, $oldTg);
}

require_once __DIR__ . '/shop_report.php';   // Do'konPro lines for the daily/weekly reports (circular include is fine: definitions only)
