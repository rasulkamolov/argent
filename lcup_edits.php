<?php
/**
 * Who is the student behind "Talaba uchun minus to'lov tahrirlandi"?  (the activity log shows neither student nor amount)
 *
 * Source: the student page /admin/students/{id}, tab "Tarix": every edit is written there with group, NEW amount (negative for a
 * minus payment), staff member and date-time. The page is read with GET; the tab itself is filled by ONE Livewire call to the component
 * students.history-livewire (whitelisted in lcup_policy.php, load/render only, switch: history_via_livewire).
 *
 * Flow per pending log row (staff S, time T, branch B):
 *   1. look in the history rows we already stored                                   (no request)
 *   2. candidates: students whose debt changed, students S worked with ±15 min, students with a payment event ±15 min, nearest in time first  (GET each, max 30)
 *   3. not found -> state "pending" (aniqlanmoqda); every run checks 20 more active students (debtors first) until found
 *   4. every night: history of all students is stored, pending rows are matched against it
 *   5. found: event with student, group, old -> new; reduced = RED, increased = yellow; still unknown after 24 h = RED
 */
require_once __DIR__ . '/lcup_client.php';
require_once __DIR__ . '/lcup_report.php';

function lcup_hkey(array $r): string {
    return sha1(implode('|', [lc_norm((string)$r['text']), lc_norm((string)$r['group']), $r['amount'] === null ? '' : (int)round($r['amount']), lc_norm((string)$r['staff']), substr((string)$r['at'], 0, 16)]));
}
function lcup_history_store(int $bid, string $sid, array $rows): int {
    $ins = db()->prepare("INSERT OR IGNORE INTO lcup_history (branch_id, student_id, hkey, text, group_name, amount, staff, at, first_seen) VALUES (?,?,?,?,?,?,?,?,?)"); $n = 0;
    foreach (array_slice($rows, 0, 300) as $r) {
        $k = lcup_hkey($r);
        $ins->execute([$bid, $sid, $k, $r['text'], $r['group'], $r['amount'], $r['staff'], $r['at'], lcup_now()]); $n += $ins->rowCount();
    }
    db()->prepare("INSERT OR IGNORE INTO lcup_student_ids (branch_id, student_id) VALUES (?,?)")->execute([$bid, $sid]);
    db()->prepare("UPDATE lcup_student_ids SET last_hist_check = ? WHERE branch_id = ? AND student_id = ?")->execute([lcup_now(), $bid, $sid]);
    return $n;
}
/** GET the student page and read its "Tarix" rows. Falls back to the history event ONLY when the admin switched that on. */
function lcup_fetch_history(LcupClient $c, int $bid, string $sid): array {
    $page = $c->view_page('/admin/students/' . rawurlencode($sid));
    $h = lcup_parse_student_history($page['html']); $via = 'get';
    if (!$h['rows'] && lcup_setting('history_via_livewire') === '1') {                  // the tab is filled by a POST to students.history-livewire (whitelisted: load/render only)
        $fr = $c->view_history($page, $sid);
        if ($fr) { $h = lcup_parse_student_history(implode("\n", $fr)); $h['structure'] = true; $via = 'livewire'; }   // the component answered: an empty history is a valid (empty) answer, not a layout problem
    }
    lcup_history_store($bid, $sid, $h['rows']);
    return $h + ['via' => $via];
}
/** The history row that belongs to a log row: minus edit, same staff, same minute (±1). A history row identifies ONE log row only. */
function lcup_match_edit(array $act, array $rows, ?int $bid = null, ?string $sid = null): ?array {
    foreach ($rows as $r) {
        if (!lcup_history_is_minus_edit($r)) continue;
        if (lc_norm((string)$r['staff']) !== lc_norm((string)$act['staff'])) continue;
        if (abs(strtotime($r['at']) - strtotime($act['act_time'])) > 70) continue;               // log time is minute-precise: ±1 minute
        if ($bid !== null && $sid !== null) { $q = db()->prepare("SELECT act_id FROM lcup_history WHERE branch_id = ? AND student_id = ? AND hkey = ?"); $q->execute([$bid, $sid, lcup_hkey($r)]); $used = $q->fetchColumn(); if ($used !== false && $used !== null && $used !== '' && $used !== $act['act_id']) continue; }
        return $r;
    }
    return null;
}
function lcup_stored_rows(int $bid, string $sid): array {
    $q = db()->prepare("SELECT text, group_name AS `group`, amount, staff, at FROM lcup_history WHERE branch_id = ? AND student_id = ? ORDER BY at"); $q->execute([$bid, $sid]); return $q->fetchAll();
}
/** Old amount: the saved charge snapshot of that student + group (a value different from the new one), else the previous edit row. */
function lcup_old_charge(int $bid, string $sid, ?string $group, float $new, string $at): ?float {
    $q = db()->prepare("SELECT amount, group_name FROM lcup_charges WHERE branch_id = ? AND student_id = ? AND amount < 0 ORDER BY pay_date DESC"); $q->execute([$bid, $sid]); $best = null;
    foreach ($q->fetchAll() as $o) { if ($group && $o['group_name'] && lc_norm($o['group_name']) !== lc_norm($group)) continue; if (abs((float)$o['amount'] - $new) < 0.5) continue; $best = (float)$o['amount']; break; }
    if ($best !== null) return $best;
    $h = db()->prepare("SELECT amount, group_name FROM lcup_history WHERE branch_id = ? AND student_id = ? AND amount < 0 AND text LIKE '%tahrirlandi%' AND at < ? ORDER BY at DESC LIMIT 6"); $h->execute([$bid, $sid, $at]);
    foreach ($h->fetchAll() as $o) { if ($group && $o['group_name'] && lc_norm($o['group_name']) !== lc_norm($group)) continue; if (abs((float)$o['amount'] - $new) < 0.5) continue; return (float)$o['amount']; }
    return null;
}
function lcup_student_name(int $bid, string $sid): string {
    foreach (["SELECT name FROM lcup_student_ids WHERE branch_id = ? AND student_id = ? AND name <> ''", "SELECT student_name FROM lcup_debts WHERE branch_id = ? AND student_id = ?", "SELECT student_name FROM lcup_acts WHERE branch_id = ? AND student_id = ? AND student_name IS NOT NULL ORDER BY act_time DESC"] as $sql) {
        $q = db()->prepare($sql); $q->execute([$bid, $sid]); if (($n = $q->fetchColumn())) return (string)$n; }
    return '';
}
/** The log row is identified: event (RED if the charge was reduced, yellow if increased) + a Telegram update. */
function lcup_resolve_edit(int $bid, array $act, string $sid, array $row): void {
    $new = (float)$row['amount']; $old = lcup_old_charge($bid, $sid, $row['group'], $new, (string)$row['at']); $name = lcup_student_name($bid, $sid);
    $bf = (bool)$act['backfill'];
    $id = lcup_event_add(['type' => 'charge_edit_resolved', 'branch_id' => $bid, 'source' => 'student_history', 'staff' => $act['staff'], 'student_id' => $sid, 'student_name' => $name, 'group_name' => $row['group'], 'amount' => $new,
        'lcup_time' => $act['act_time'], 'before' => $old !== null ? ['amount' => $old] : null, 'after' => ['amount' => $new], 'backfill' => $bf, 'is_charge' => true,
        'details' => "Minus to'lov tahrirlandi (jurnal #{$act['act_id']}): " . ($name ?: "#$sid") . ($row['group'] ? " · {$row['group']}" : '') . ' — ' . ($old !== null ? fmt($old) . ' → ' . fmt($new) : "yangi summa " . fmt($new) . " (eski summa snapshotda yo'q)") . " so'm · " . $act['staff'] . ' · ' . date('H:i', strtotime($act['act_time'])),
        'raw' => $row['raw'] ?? '', 'dedupe_key' => "chgres|$bid|{$act['act_id']}"]);
    db()->prepare("UPDATE lcup_history SET act_id = ? WHERE branch_id = ? AND student_id = ? AND hkey = ?")->execute([$act['act_id'], $bid, $sid, lcup_hkey(['text' => $row['text'] ?? "Talaba uchun to'lov tahrirlandi", 'group' => $row['group'], 'amount' => $row['amount'], 'staff' => $row['staff'], 'at' => $row['at']])]);
    db()->prepare("UPDATE lcup_acts SET state = 'linked', student_id = ?, student_name = ?, group_name = COALESCE(?, group_name), cur_amount = ?, alert_amount = ? WHERE branch_id = ? AND act_id = ?")->execute([$sid, $name, $row['group'], $new, $old, $bid, $act['act_id']]);
    if ($id && !$bf) {                                                                   // red ones go out through the normal alert flush; the others still get the "updated" message (the first alert was already sent)
        $sev = db()->prepare("SELECT severity FROM lcup_events WHERE id = ?"); $sev->execute([$id]);
        if ($sev->fetchColumn() !== 'red' && lcup_tg_ready() && lcup_setting('alerts_on') === '1') {
            $ev = db()->prepare("SELECT details FROM lcup_events WHERE id = ?"); $ev->execute([$id]);
            lcup_tg_send("ℹ️ <b>Aniqlandi: minus to'lov tahriri</b>\n" . lcup_h((string)$ev->fetchColumn()) . ($old !== null && abs($new) > abs($old) ? "\n🟡 Oylik to'lov oshirilgan" : ''));
        }
    }
}
/** Students worth looking at first for one pending log row, ranked by how close their activity is to the edit time T (a, b, c on ties). */
function lcup_edit_candidates(int $bid, array $act): array {
    $T = strtotime($act['act_time']); $lo = date('Y-m-d H:i:s', $T - 900); $hi = date('Y-m-d H:i:s', $T + 900); $best = [];
    $add = function (string $sid, int $score, int $src) use (&$best) { if (!isset($best[$sid]) || [$score, $src] < $best[$sid]) $best[$sid] = [$score, $src]; };
    foreach ((json_decode((string)lcup_state_get("debt_changed_$bid", '[]'), true) ?: []) as $d)
        if (!empty($d['sid']) && ($d['at'] ?? '') >= date('Y-m-d H:i:s', $T - 1800)) $add((string)$d['sid'], abs(strtotime($d['at']) - $T), 0);              // a) debt changed since the last look
    $q = db()->prepare("SELECT student_id, act_time FROM lcup_acts WHERE branch_id = ? AND staff = ? AND student_id IS NOT NULL AND act_time BETWEEN ? AND ? AND act_id <> ?"); $q->execute([$bid, $act['staff'], $lo, $hi, $act['act_id']]);
    foreach ($q->fetchAll() as $r) $add((string)$r['student_id'], abs(strtotime($r['act_time']) - $T), 1);                                                                        // b) students this staff member worked with around T
    $q = db()->prepare("SELECT student_id, COALESCE(lcup_time, detected_at) AS t FROM lcup_events WHERE branch_id = ? AND student_id IS NOT NULL AND type IN ('payment_created','payment_changed','amount_changed','payment_deleted') AND COALESCE(lcup_time, detected_at) BETWEEN ? AND ?");
    $q->execute([$bid, $lo, $hi]); foreach ($q->fetchAll() as $r) $add((string)$r['student_id'], abs(strtotime($r['t']) - $T), 2);                                          // c) a payment was created/changed around T
    uasort($best, function ($x, $y) { return $x <=> $y; });
    return array_map('strval', array_keys($best));
}
/** Active students of the branch (needed for the background search); the list is read page by page over several runs. */
function lcup_students_crawl(LcupClient $c, int $bid, array &$sum, int $maxPages = 3): void {
    $done = lcup_state_get("sl_done_$bid");
    if ($done === date('Y-m-d')) return;                                                // read once a day (new students appear)
    if ($done) { lcup_state_del("sl_done_$bid"); lcup_state_set("sl_page_$bid", 1); }
    for ($i = 0; $i < $maxPages; $i++) {
        $pg = max(1, (int)lcup_state_get("sl_page_$bid", 1)); $r = $pg <= 1 ? $c->page(lcup_setting('students_url')) : $c->page(lcup_setting('students_url'), ['page' => $pg]);
        $l = lcup_parse_students_list($r['body']); $pi = lc_page_info(lc_dom($r['body'])); if ($pg > 1 && $pi['page'] !== $pg) $l['students'] = [];
        foreach ($l['students'] as $x) { $q = db()->prepare("INSERT OR IGNORE INTO lcup_student_ids (branch_id, student_id, name) VALUES (?,?,?)"); $q->execute([$bid, $x['id'], $x['name']]); if ($x['name'] !== '') db()->prepare("UPDATE lcup_student_ids SET name = ? WHERE branch_id = ? AND student_id = ? AND name = ''")->execute([$x['name'], $bid, $x['id']]); }
        if (!$l['students'] || !$pi['has_next'] || $pg >= 400) { lcup_state_set("sl_done_$bid", date('Y-m-d')); return; }
        lcup_state_set("sl_page_$bid", $pg + 1);
    }
}

/** Runs after the debt check in every sync of the branch. */
function lcup_resolve_charge_edits(LcupClient $c, int $bid, array &$sum, float $deadline): void {
    $q = db()->prepare("SELECT * FROM lcup_acts WHERE branch_id = ? AND type = 'log_edit' AND act_text LIKE '%minus%' AND state IN ('pending', 'unresolved') ORDER BY act_time");
    $q->execute([$bid]); $pend = $q->fetchAll(); if (!$pend) return;
    $resolve = function (array $act, string $sid, array $row) use ($bid, &$pend) { lcup_resolve_edit($bid, $act, $sid, $row); $pend = array_values(array_filter($pend, function ($p) use ($act) { return $p['act_id'] !== $act['act_id']; })); };
    // 1) history rows we already have (stored by an earlier lookup or by last night's scan)
    foreach ($pend as $act) {
        $q2 = db()->prepare("SELECT DISTINCT student_id FROM lcup_history WHERE branch_id = ? AND amount < 0 AND text LIKE '%tahrirlandi%' AND at BETWEEN ? AND ?");
        $q2->execute([$bid, date('Y-m-d H:i:s', strtotime($act['act_time']) - 120), date('Y-m-d H:i:s', strtotime($act['act_time']) + 120)]);
        foreach ($q2->fetchAll(PDO::FETCH_COLUMN) as $sid) if (($m = lcup_match_edit($act, lcup_stored_rows($bid, (string)$sid), $bid, (string)$sid))) { $resolve($act, (string)$sid, $m); break; }
    }
    $failUntil = (int)lcup_state_get("hist_fail_until_$bid", 0); $fails = 0; $budget = 40; $memo = [];
    $fetch = function (string $sid) use ($c, $bid, &$sum, &$fails, $failUntil, &$memo) {
        if (isset($memo[$sid])) return $memo[$sid];                                      // one request per student per run, however many pending rows want it
        if (time() < $failUntil) return null;
        try { $h = lcup_fetch_history($c, $bid, $sid); }
        catch (LcupError $e) { if (in_array($e->kind, ['login', 'network', 'ratelimit', 'config'], true)) throw $e; return null; }      // another branch's student, deleted, ...
        if (!$h['structure']) { if (++$fails >= 3) { lcup_state_set("hist_fail_until_$bid", time() + 1800); $sum['warnings'][] = (branch_get($bid)['name'] ?? $bid) . ": talaba sahifasida «Tarix» qatorlari topilmadi (3 ta sahifa; GET va students.history-livewire). Sahifa tuzilishi kutilganidan farq qiladi — Diagnostika yuboring (30 daqiqa kutiladi)."; } return null; }
        $fails = 0; return $memo[$sid] = $h;
    };
    // 2) immediate candidates for rows not tried yet
    foreach (array_reverse($pend) as $act) {                                            // newest first: a backlog of old rows must not starve the one that just appeared
        if ($act['paired_at'] !== null || $budget <= 0 || microtime(true) > $deadline - 3) continue;
        $cands = array_slice(lcup_edit_candidates($bid, $act), 0, 30); $looked = false;
        foreach ($cands as $sid) {
            if (!isset($memo[$sid]) && $budget-- <= 0) break; if (microtime(true) > $deadline - 3) break;
            $h = $fetch($sid); if (!$h) { if (time() < (int)lcup_state_get("hist_fail_until_$bid", 0)) break; continue; }
            $looked = true;
            if (($m = lcup_match_edit($act, $h['rows'], $bid, (string)$sid))) { $resolve($act, $sid, $m); break; }
        }
        if ($looked || !$cands) db()->prepare("UPDATE lcup_acts SET paired_at = ? WHERE branch_id = ? AND act_id = ?")->execute([lcup_now(), $bid, $act['act_id']]);   // retried later when nothing could be read (history unavailable)
    }
    // 3) background: 20 more students per run (debtors first), only students not looked at since the newest pending row appeared
    if ($pend && microtime(true) < $deadline - 4 && time() >= (int)lcup_state_get("hist_fail_until_$bid", 0)) {
        $since = max(array_map(function ($p) { return $p['first_seen']; }, $pend)); $limit = max(1, (int)lcup_num('hist_batch')); $ids = [];
        try { lcup_students_crawl($c, $bid, $sum); } catch (LcupError $e) { if ($e->kind !== 'layout') throw $e; }    // the list of active students (3 pages per run until read)
        $d = db()->prepare("SELECT d.student_id FROM lcup_debts d LEFT JOIN lcup_student_ids s ON s.branch_id = d.branch_id AND s.student_id = d.student_id WHERE d.branch_id = ? AND d.student_id IS NOT NULL AND (s.last_hist_check IS NULL OR s.last_hist_check < ?) ORDER BY s.last_hist_check LIMIT ?");
        $d->execute([$bid, $since, $limit]); foreach ($d->fetchAll(PDO::FETCH_COLUMN) as $s) $ids[(string)$s] = 1;
        if (count($ids) < $limit) { $o = db()->prepare("SELECT student_id FROM lcup_student_ids WHERE branch_id = ? AND (last_hist_check IS NULL OR last_hist_check < ?) ORDER BY last_hist_check LIMIT ?"); $o->execute([$bid, $since, $limit - count($ids)]); foreach ($o->fetchAll(PDO::FETCH_COLUMN) as $s) $ids[(string)$s] = 1; }
        foreach (array_keys($ids) as $sid) {
            if (!$pend || microtime(true) > $deadline - 3) break;
            $h = $fetch((string)$sid); if (!$h) { if (time() < (int)lcup_state_get("hist_fail_until_$bid", 0)) break; continue; }
            foreach ($pend as $act) if (($m = lcup_match_edit($act, $h['rows'], $bid, (string)$sid))) { $resolve($act, (string)$sid, $m); }
        }
    }
    // 5) still unknown after 24 hours: RED
    foreach ($pend as $act) if ($act['state'] === 'pending' && !$act['backfill'] && strtotime($act['first_seen']) < time() - 86400) {
        db()->prepare("UPDATE lcup_acts SET state = 'unresolved' WHERE branch_id = ? AND act_id = ?")->execute([$bid, $act['act_id']]);
        lcup_event_add(['type' => 'charge_edit_unresolved', 'branch_id' => $bid, 'source' => 'student_history', 'staff' => $act['staff'], 'lcup_time' => $act['act_time'], 'is_charge' => true,
            'details' => "«Minus to'lov tahrirlandi» (jurnal #{$act['act_id']}, " . date('d.m H:i', strtotime($act['act_time'])) . ") 24 soatdan beri talabaga bog'lanmadi — tahrir qilingan talaba aniqlanmadi. Qo'lda tekshiring.", 'dedupe_key' => "chgunres|$bid|{$act['act_id']}"]);
    }
}
