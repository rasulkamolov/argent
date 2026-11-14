<?php
/**
 * Qarzdorlik o'zgarishi — why did a branch's total debt (Moliya → Qarzdorlar, "Jami") go up or down?
 *
 *  - Every debtors read (each sync) updates lcup_debts (current state: signed balance, comment, groups) and detects debts that went DOWN
 *    with nothing behind them (after a grace period: payments / Tarix rows may arrive a little later) -> incident 🔴 "Qarz sababsiz kamaydi".
 *  - Append-only day snapshots: 'start' (first read after 00:05) and 'end' (first read after 23:55).
 *  - The day report explains every student's balance change (end − start) with that student's log / Tarix rows of the day.
 * Amounts in the report are in DEBT terms (like the user reads "Jami"): a payment makes the debt go DOWN (−), a monthly charge UP (+).
 */
require_once __DIR__ . '/lcup_incidents.php';

const LCUP_DEBT_REASON = ['tolov' => "To'lov", 'tolov_ochirildi' => "To'lov o'chirildi", 'tolov_tahrir' => "To'lov tahrirlandi", 'minus_tahrir' => "Minus to'lov tahrirlandi", 'chegirma' => 'Chegirma',
    'muzlatish' => "Muzlatish / boshqa guruhga o'tkazish", 'balans' => 'Balans tahrirlandi', 'aktivlashtirish' => "Aktivlashtirish / guruhga qo'shish", 'oylik' => "Oylik to'lov hisoblandi",
    'chiqdi' => "Talaba ro'yxatdan chiqdi", 'yangi' => 'Yangi qarzdor', 'sababsiz' => '❓ Sababsiz'];

/* ============================================================== reading the debtors list */
/** Reads every page of the (unfiltered) debtors list. Returns ['rows' => [key => row], 'jami' => float|null, 'count' => int|null, 'complete' => bool]. */
function lcup_debtors_read(LcupClient $c, int $bid, string $url, float $deadline, int $max = 80): array {
    $rows = []; $first = null; $jami = null; $cnt = null; $complete = false;
    for ($pg = 1; $pg <= $max && microtime(true) < $deadline; $pg++) {
        $r = $pg === 1 ? $c->page($url) : $c->page($url, ['page' => $pg]);
        $a = lcup_parse_debtors($r['body']);
        if (!$a['structure']) { if ($pg === 1) throw new LcupError("Qarzdorlar jadvali tanilmadi (SAHIFA: $url). «Diagnostika» ni bosing.", 'layout'); break; }
        if ($pg === 1) { $jami = $a['jami']; $cnt = $a['count']; }
        $pi = lc_page_info(lc_dom($r['body'])); if ($pg > 1 && $pi['page'] !== $pg) break;
        $sig = md5(json_encode($a['rows'])); if ($sig === $first) break; if ($pg === 1) $first = $sig;
        foreach ($a['rows'] as $x) $rows[$x['key']] = $x;
        if (!$pi['has_next']) { $complete = true; break; }
    }
    if ($cnt !== null && count($rows) < $cnt) $complete = false;               // the footer says more students than we read
    return ['rows' => $rows, 'jami' => $jami, 'count' => $cnt, 'complete' => $complete];
}
/** Called by every sync of a branch (every debt_interval minutes). */
function lcup_check_debts(LcupClient $c, int $bid, array &$sum, float $deadline): void {
    $url = lcup_setting('debtors_url'); if ($url === '') return;
    $last = lcup_state_get("debt_last_$bid"); if ($last && time() - (int)$last < max(0, (int)lcup_num('debt_interval')) * 60 - 5) return;
    $rd = lcup_debtors_read($c, $bid, $url, $deadline); lcup_state_set("debt_last_$bid", time()); $new = $rd['rows'];
    $old = []; $q = db()->prepare("SELECT * FROM lcup_debts WHERE branch_id = ?"); $q->execute([$bid]); foreach ($q->fetchAll() as $o) $old[$o['d_key']] = $o;
    $base = !lcup_state_get("base_debt_$bid"); $now = lcup_now();
    if (!$rd['complete'] && !$base) { $sum['warnings'][] = (branch_get($bid)['name'] ?? $bid) . ": qarzdorlar ro'yxati to'liq o'qilmadi (" . count($new) . ($rd['count'] ? ' / ' . $rd['count'] : '') . ") — taqqoslash o'tkazib yuborildi."; return; }
    if (!$base && count($old) >= 10 && count($new) < 0.5 * count($old)) { $sum['warnings'][] = (branch_get($bid)['name'] ?? $bid) . ": qarzdorlar ro'yxati keskin qisqardi (" . count($old) . " → " . count($new) . ") — tekshiruv o'tkazib yuborildi."; return; }
    if (!$base) {
        $pend = db()->prepare("INSERT OR IGNORE INTO lcup_debt_pending (branch_id, d_key, student_id, student_name, group_name, prev_balance, new_balance, from_at, at) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($old as $k => $o) {
            $nb = isset($new[$k]) ? (float)$new[$k]['balance'] : null; $ob = (float)$o['balance'];
            if ($nb === null && $ob < -0.5) $pend->execute([$bid, $k, $o['student_id'], $o['student_name'], $o['group_name'], $ob, null, $o['changed_at'] ?: $o['updated_at'], $now]);      // left the list
            elseif ($nb !== null && $nb - $ob >= 1) $pend->execute([$bid, $k, $o['student_id'], $o['student_name'], $o['group_name'], $ob, $nb, $o['changed_at'] ?: $o['updated_at'], $now]);   // debt went down
        }
        // candidates for "minus to'lov tahrirlandi" rows (lcup_edits.php): whose balance moved
        $chg = json_decode((string)lcup_state_get("debt_changed_$bid", '[]'), true) ?: []; $cut = date('Y-m-d H:i:s', strtotime('-60 minutes')); $chg = array_values(array_filter($chg, function ($x) use ($cut) { return ($x['at'] ?? '') >= $cut; }));
        foreach ($new as $k => $x) { $ob = isset($old[$k]) ? (float)$old[$k]['balance'] : null; if (($ob === null || abs($ob - (float)$x['balance']) >= 1) && $x['student_id']) $chg[] = ['sid' => (string)$x['student_id'], 'name' => $x['student_name'], 'at' => $now]; }
        lcup_state_set("debt_changed_$bid", json_encode(array_slice($chg, -400)));
    }
    db()->prepare("DELETE FROM lcup_debts WHERE branch_id = ?")->execute([$bid]);
    $ins = db()->prepare("INSERT INTO lcup_debts (branch_id, d_key, student_id, student_name, group_name, balance, comment, updated_at, changed_at) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($new as $k => $x) { $o = $old[$k] ?? null; $ins->execute([$bid, $k, $x['student_id'], $x['student_name'], $x['group'] ?? '', $x['balance'], $x['comment'] ?? '', $now, ($o && abs((float)$o['balance'] - (float)$x['balance']) < 0.5) ? ($o['changed_at'] ?: $o['updated_at']) : $now]); }
    lcup_state_set("debt_jami_$bid", json_encode(['jami' => $rd['jami'], 'count' => $rd['count'], 'at' => $now]));
    // append-only day snapshots
    $day = date('Y-m-d'); $hm = date('H:i');
    if ($rd['complete']) foreach ([['start', '00:05'], ['end', '23:55']] as [$kind, $t]) {
        if ($hm < $t) continue;
        $ex = db()->prepare("SELECT 1 FROM lcup_debt_snaps WHERE branch_id = ? AND day = ? AND kind = ?"); $ex->execute([$bid, $day, $kind]); if ($ex->fetchColumn()) continue;
        db()->prepare("INSERT INTO lcup_debt_snaps (branch_id, day, kind, taken_at, jami, cnt, rows_n) VALUES (?,?,?,?,?,?,?)")->execute([$bid, $day, $kind, $now, $rd['jami'], $rd['count'], count($new)]); $sid = (int)db()->lastInsertId();
        $r = db()->prepare("INSERT INTO lcup_debt_snap_rows (snap_id, student_id, d_key, student_name, groups, balance, comment) VALUES (?,?,?,?,?,?,?)");
        foreach ($new as $k => $x) $r->execute([$sid, $x['student_id'], $k, $x['student_name'], $x['group'] ?? '', $x['balance'], $x['comment'] ?? '']);
    }
    lcup_state_set("base_debt_$bid", '1');
}

/* ============================================================== explaining a balance change */
/**
 * What moved this student's balance between $from and $to, from the log rows and the stored Tarix rows (deduplicated).
 * effect = change of the BALANCE (+ = debt down). null = the row has no amount (discount, activation): it can only absorb the residual.
 * @return array [['reason', 'effect' => float|null, 'label', 'at', 'actor', 'src']]
 */
function lcup_explain_balance(?string $sid, ?string $name, string $from, string $to): array {
    $items = []; $seen = [];
    $w = $sid ? "student_id = ?" : "student_name = ? COLLATE NOCASE"; $p = [$sid ?: $name, $from, $to];
    $q = db()->prepare("SELECT * FROM lcup_acts WHERE $w AND act_time BETWEEN ? AND ? ORDER BY act_time"); $q->execute($p);
    $hist = []; if ($sid) { $h = db()->prepare("SELECT * FROM lcup_history WHERE student_id = ? AND at BETWEEN ? AND ? ORDER BY at"); $h->execute([$sid, $from, $to]); $hist = $h->fetchAll(); }
    $oldOf = function ($at, $new) use ($sid, $hist) {
        foreach ($hist as $r) if (($r['kind'] ?? '') === 'payment_edited' && abs(strtotime($r['at']) - strtotime($at)) <= 90 && $r['new_amount'] !== null && abs((float)$r['new_amount'] - (float)$new) < 0.5 && $r['old_amount'] !== null) return (float)$r['old_amount'];
        if ($sid) { $i = db()->prepare("SELECT old_amount FROM lcup_incidents WHERE student_id = ? AND kind IN ('edited','moved') AND old_amount IS NOT NULL AND ABS(new_amount - ?) < 0.5 AND ABS(strftime('%s', at) - strftime('%s', ?)) <= 180 LIMIT 1"); $i->execute([$sid, $new, $at]); $v = $i->fetchColumn(); if ($v !== false && $v !== null) return (float)$v; }
        return null;
    };
    $add = function (string $kind, $amount, $old, string $at, ?string $actor, string $src, string $text) use (&$items, &$seen, $oldOf) {
        $k = $kind . '|' . substr($at, 0, 16) . '|' . ($amount === null ? '' : (int)round((float)$amount)); if (isset($seen[$k])) return; $seen[$k] = 1;
        $e = null; $r = null;
        switch ($kind) {
            case 'payment_created': $r = 'tolov'; $e = $amount !== null ? abs((float)$amount) : null; break;
            case 'payment_deleted': $r = 'tolov_ochirildi'; $e = $amount !== null ? -abs((float)$amount) : null; break;
            case 'payment_edited': $r = 'tolov_tahrir'; if ($old === null && $amount !== null) $old = $oldOf($at, $amount); $e = ($old !== null && $amount !== null) ? abs((float)$amount) - abs((float)$old) : null; break;
            case 'charge_edited': $r = 'minus_tahrir'; $e = ($old !== null && $amount !== null) ? (float)$amount - (float)$old : null; break;
            case 'balance_edited': $r = 'balans'; $e = ($old !== null && $amount !== null) ? (float)$amount - (float)$old : null; break;
            case 'freeze_charge': $r = 'muzlatish'; $e = $amount !== null ? (float)$amount : null; break;
            case 'discount': $r = 'chegirma'; break;
            case 'student_activated': case 'student_added': $r = 'aktivlashtirish'; break;
            default: return;
        }
        $items[] = ['reason' => $r, 'effect' => $e, 'label' => $text, 'at' => $at, 'actor' => $actor, 'src' => $src];
    };
    foreach ($q->fetchAll() as $a) {
        $old = $a['old_amount'] !== null ? (float)$a['old_amount'] : null; $amt = $a['amount'] !== null ? (float)$a['amount'] : null;
        if ($a['type'] === 'charge_edited' && $a['state'] === 'linked') { $amt = $a['cur_amount'] !== null ? (float)$a['cur_amount'] : $amt; $old = $a['alert_amount'] !== null ? (float)$a['alert_amount'] : $old; }
        $add((string)$a['type'], $amt, $old, (string)$a['act_time'], $a['staff'], 'jurnal', (string)$a['act_text']);
    }
    foreach ($hist as $r) $add((string)($r['kind'] ?: lcup_classify_event((string)$r['text'])[0]), $r['amount'], $r['old_amount'], (string)$r['at'], $r['staff'], 'Tarix', (string)$r['text']);
    return $items;
}
/** Residual (balance change not explained by rows with amounts) goes to an amount-less row of the same direction, the monthly charge (1st), or "Sababsiz". */
function lcup_assign_residual(array $items, float $delta, string $day, bool $joined, bool $left, ?string $leftReason): array {
    $known = 0.0; foreach ($items as $it) if ($it['effect'] !== null) $known += $it['effect'];
    $res = $delta - $known; $out = $items;
    // students who joined / left the list: the list only shows balances below zero, so rows may explain MORE than the visible change
    if (($joined || $left) && abs($res) >= 1 && (($delta < 0 && $known <= $delta) || ($delta > 0 && $known >= $delta))) $res = 0.0;
    if (abs($res) < 1) return [$out, 0.0];
    $absorb = function (string $reason) use (&$out, $res) { foreach ($out as &$it) if ($it['reason'] === $reason && $it['effect'] === null) { $it['effect'] = $res; $it['estimated'] = true; return true; } unset($it); return false; };
    if ($left && $leftReason !== null && $leftReason !== 'yopildi') { $out[] = ['reason' => 'chiqdi', 'effect' => $res, 'label' => $leftReason, 'at' => null, 'actor' => null, 'src' => '']; return [$out, 0.0]; }
    if ($res < 0 && $absorb('aktivlashtirish')) return [$out, 0.0];
    if ($res > 0 && $absorb('chegirma')) return [$out, 0.0];
    if ($absorb('tolov_tahrir') || $absorb('minus_tahrir') || $absorb('balans')) return [$out, 0.0];
    if ($res < 0 && substr($day, 8, 2) === '01') { $out[] = ['reason' => 'oylik', 'effect' => $res, 'label' => "LC-UP oylik to'lovni avtomatik hisobladi (oyning 1-kuni)", 'at' => null, 'actor' => 'Tizim', 'src' => '']; return [$out, 0.0]; }
    if ($res < 0 && $joined) { $out[] = ['reason' => 'yangi', 'effect' => $res, 'label' => "Qarzdorlar ro'yxatiga qo'shildi", 'at' => null, 'actor' => null, 'src' => '']; return [$out, 0.0]; }
    $out[] = ['reason' => 'sababsiz', 'effect' => $res, 'label' => "Hech bir jurnal / Tarix yozuvi bilan tushuntirilmadi", 'at' => null, 'actor' => null, 'src' => ''];
    return [$out, $res];
}

/* ============================================================== real-time: debt went down with nothing behind it */
/** Pending decreases older than the grace period: explained -> closed; otherwise Tarix is read once and, still unexplained, an incident. */
function lcup_debt_pending_eval(LcupClient $c, int $bid, array &$sum, float $deadline): void {
    $grace = max(5, (int)lcup_num('debt_grace_min')) * 60; $budget = 4;
    $q = db()->prepare("SELECT * FROM lcup_debt_pending WHERE branch_id = ? AND state IN ('open','tarix') AND at <= ? ORDER BY at LIMIT 30"); $q->execute([$bid, date('Y-m-d H:i:s', time() - $grace)]);
    foreach ($q->fetchAll() as $p) {
        if (microtime(true) > $deadline - 3) break;
        $from = date('Y-m-d H:i:s', strtotime((string)$p['from_at']) - 600); $to = lcup_now();     // rows up to now may explain it (they can arrive after the balance changed)
        $left = $p['new_balance'] === null; $delta = $left ? -(float)$p['prev_balance'] : (float)$p['new_balance'] - (float)$p['prev_balance'];
        $leftReason = null;
        if ($left) $leftReason = lcup_leaver_reason($c, $bid, $p['student_id'], (string)$p['student_name'], $budget);
        [$items, $res] = lcup_assign_residual(lcup_explain_balance($p['student_id'], $p['student_name'], $from, $to), $delta, substr((string)$p['at'], 0, 10), false, $left, $leftReason);
        $key = [$bid, $p['d_key'], $p['at']];
        if ($res < 1) { db()->prepare("UPDATE lcup_debt_pending SET state = 'explained' WHERE branch_id = ? AND d_key = ? AND at = ?")->execute($key); continue; }
        if ($p['state'] === 'open' && $p['student_id'] && $budget-- > 0) {                        // read the student's Tarix once, then decide at the next run
            try { lcup_fetch_history($c, $bid, (string)$p['student_id']); } catch (LcupError $e) { if (in_array($e->kind, ['login', 'network', 'ratelimit', 'config'], true)) throw $e; }
            db()->prepare("UPDATE lcup_debt_pending SET state = 'tarix' WHERE branch_id = ? AND d_key = ? AND at = ?")->execute($key); continue;
        }
        db()->prepare("UPDATE lcup_debt_pending SET state = 'alerted' WHERE branch_id = ? AND d_key = ? AND at = ?")->execute($key);
        lcup_event_add(['type' => 'debt_unexplained', 'branch_id' => $bid, 'source' => 'debtors', 'staff' => null, 'student_id' => $p['student_id'], 'student_name' => $p['student_name'], 'group_name' => $p['group_name'], 'amount' => $res,
            'before' => ['balance' => (float)$p['prev_balance']], 'after' => $left ? null : ['balance' => (float)$p['new_balance']], 'win_from' => $p['from_at'], 'win_to' => $p['at'],
            'details' => "Qarz " . fmt(-(float)$p['prev_balance']) . " → " . ($left ? "ro'yxatdan chiqdi" . ($leftReason ? " ($leftReason)" : '') : fmt(-(float)$p['new_balance'])) . ": " . fmt($res) . " so'm hech bir to'lov, tahrir yoki Tarix yozuvi bilan tushuntirilmadi",
            'dedupe_key' => "debtx|$bid|{$p['d_key']}|{$p['at']}"]);
    }
}
/** Why did a student leave the debtors list? Another branch's list, the student page (404 = deleted), otherwise "qarzi yopildi / arxivlangan". */
function lcup_leaver_reason(?LcupClient $c, int $bid, ?string $sid, string $name, int &$budget): ?string {
    if ($sid) {
        $q = db()->prepare("SELECT branch_id FROM lcup_debts WHERE student_id = ? AND branch_id <> ?"); $q->execute([$sid, $bid]); if (($ob = $q->fetchColumn())) return "boshqa filialga o'tdi (" . lcup_branch_name((int)$ob) . ")";
        $l = db()->prepare("SELECT reason FROM lcup_leavers WHERE branch_id = ? AND student_id = ? AND day = ?"); $l->execute([$bid, $sid, date('Y-m-d')]); if (($r = $l->fetchColumn())) return $r;
        if ($c && $budget-- > 0) {
            $reason = 'yopildi';
            try { $c->page('/admin/students/' . rawurlencode($sid)); } catch (LcupError $e) { if (in_array($e->kind, ['login', 'network', 'ratelimit', 'config'], true)) throw $e; $reason = "talaba o'chirilgan (sahifasi ochilmaydi)"; }
            db()->prepare("INSERT OR REPLACE INTO lcup_leavers (branch_id, student_id, day, reason, checked_at) VALUES (?,?,?,?,?)")->execute([$bid, $sid, date('Y-m-d'), $reason, lcup_now()]);
            return $reason;
        }
    }
    return null;
}

/* ============================================================== day report */
/** Start / end state of a branch for a day: append-only snapshots; for today without an 'end' snapshot the current state is used ("hozir"). */
function lcup_debt_states(int $bid, string $day): array {
    $s = db()->prepare("SELECT * FROM lcup_debt_snaps WHERE branch_id = ? AND day = ? ORDER BY CASE kind WHEN 'start' THEN 0 ELSE 1 END"); $s->execute([$bid, $day]); $snaps = []; foreach ($s->fetchAll() as $x) $snaps[$x['kind']] = $x;
    if (!isset($snaps['start'])) {   // the previous day's end is the best start when the morning read was missed
        $p = db()->prepare("SELECT * FROM lcup_debt_snaps WHERE branch_id = ? AND day < ? AND kind = 'end' ORDER BY day DESC LIMIT 1"); $p->execute([$bid, $day]); if (($x = $p->fetch())) $snaps['start'] = $x;
    }
    $rows = function ($snap) { $r = db()->prepare("SELECT * FROM lcup_debt_snap_rows WHERE snap_id = ?"); $r->execute([$snap['id']]); $o = []; foreach ($r->fetchAll() as $x) $o[$x['d_key']] = $x; return $o; };
    $start = isset($snaps['start']) ? ['at' => $snaps['start']['taken_at'], 'jami' => $snaps['start']['jami'], 'cnt' => $snaps['start']['cnt'], 'rows' => $rows($snaps['start']), 'label' => $snaps['start']['day'] === $day ? 'kun boshi' : 'oldingi kun oxiri'] : null;
    if (isset($snaps['end'])) $end = ['at' => $snaps['end']['taken_at'], 'jami' => $snaps['end']['jami'], 'cnt' => $snaps['end']['cnt'], 'rows' => $rows($snaps['end']), 'label' => 'kun oxiri'];
    elseif ($day === date('Y-m-d')) {
        $cur = db()->prepare("SELECT d_key, student_id, student_name, group_name AS groups, balance, comment, updated_at FROM lcup_debts WHERE branch_id = ?"); $cur->execute([$bid]); $o = []; $at = null; foreach ($cur->fetchAll() as $x) { $o[$x['d_key']] = $x; $at = max($at, $x['updated_at']); }
        $j = json_decode((string)lcup_state_get("debt_jami_$bid", ''), true) ?: [];
        $end = $o || $j ? ['at' => $j['at'] ?? $at, 'jami' => $j['jami'] ?? null, 'cnt' => $j['count'] ?? count($o), 'rows' => $o, 'label' => 'hozir'] : null;
    } else $end = null;
    return [$start, $end];
}
/**
 * Full explanation of one branch-day.
 * @return array ['start','end','change','by' => [reason => ['amount' (debt terms), 'n']], 'students' => [...], 'unexplained' => debt-terms sum, 'check' => diff between Jami change and the sum of students]
 */
function lcup_debt_day(int $bid, string $day): array {
    [$start, $end] = lcup_debt_states($bid, $day);
    $out = ['branch_id' => $bid, 'day' => $day, 'start' => $start ? ['at' => $start['at'], 'jami' => $start['jami'], 'cnt' => $start['cnt'], 'label' => $start['label']] : null,
            'end' => $end ? ['at' => $end['at'], 'jami' => $end['jami'], 'cnt' => $end['cnt'], 'label' => $end['label']] : null, 'students' => [], 'by' => [], 'unexplained' => 0.0, 'change' => null, 'check' => null];
    if (!$start || !$end) return $out;
    $keys = array_unique(array_merge(array_keys($start['rows']), array_keys($end['rows']))); $sumDelta = 0.0; $budget = 0;
    foreach ($keys as $k) {
        $s = $start['rows'][$k] ?? null; $e = $end['rows'][$k] ?? null; $sb = $s ? (float)$s['balance'] : 0.0; $eb = $e ? (float)$e['balance'] : 0.0; $delta = $eb - $sb;
        $sid = ($e['student_id'] ?? null) ?: ($s['student_id'] ?? null); $name = ($e['student_name'] ?? null) ?: ($s['student_name'] ?? '');
        $items = lcup_explain_balance($sid, $name, $start['at'], $end['at']);
        if (abs($delta) < 1 && !$items) continue;
        $sumDelta += $delta; $left = $s && !$e; $joined = !$s && $e;
        $why = $left ? lcup_leaver_reason(null, $bid, $sid, $name, $budget) : null;
        [$items, $res] = lcup_assign_residual($items, $delta, $day, $joined, $left, $why);
        $st = ['key' => $k, 'student_id' => $sid, 'name' => $name, 'groups' => ($e['groups'] ?? null) ?: ($s['groups'] ?? ''), 'start' => $s ? $sb : null, 'end' => $e ? $eb : null, 'delta_debt' => -$delta, 'items' => $items, 'unexplained_debt' => -$res, 'left' => $left, 'joined' => $joined];
        $out['students'][] = $st;
        foreach ($items as $it) { if ($it['effect'] === null) continue; $r = $it['reason']; if (!isset($out['by'][$r])) $out['by'][$r] = ['amount' => 0.0, 'n' => 0, 'ids' => []]; $out['by'][$r]['amount'] += -$it['effect']; $out['by'][$r]['ids'][$k] = 1; }
        $out['unexplained'] += -$res;
    }
    foreach ($out['by'] as &$b) { $b['n'] = count($b['ids']); unset($b['ids']); } unset($b);
    uasort($out['by'], function ($x, $y) { return abs($y['amount']) <=> abs($x['amount']); });
    usort($out['students'], function ($x, $y) { return [abs($y['unexplained_debt']) > 0.5, abs($y['delta_debt'])] <=> [abs($x['unexplained_debt']) > 0.5, abs($x['delta_debt'])]; });
    if ($start['jami'] !== null && $end['jami'] !== null) { $out['change'] = -((float)$end['jami'] - (float)$start['jami']); $out['check'] = $out['change'] - (-$sumDelta); }
    else $out['change'] = -$sumDelta;
    return $out;
}
/** "Bo'zqal'a: qarz −37 755 478 → −37 120 000 (−635 478): to'lovlar −900 000, oylik +0, ..., sababsiz 0" */
function lcup_debt_summary_line(int $bid, string $day): ?string {
    $r = lcup_debt_day($bid, $day); if (!$r['start'] || !$r['end']) return null;
    $parts = []; foreach ($r['by'] as $k => $b) if (abs($b['amount']) >= 1) $parts[] = mb_strtolower(LCUP_DEBT_REASON[$k] ?? $k) . ' ' . fmt_signed($b['amount']);
    if (!isset($r['by']['sababsiz'])) $parts[] = 'sababsiz 0';
    return lcup_branch_name($bid) . ": qarz " . ($r['start']['jami'] !== null ? fmt_signed($r['start']['jami']) : '?') . " → " . ($r['end']['jami'] !== null ? fmt_signed($r['end']['jami']) : '?') . " (" . fmt_signed((float)$r['change']) . ")" . ($parts ? ': ' . implode(', ', $parts) : '');
}
