<?php
/**
 * Who is the student behind "Talaba uchun minus to'lov tahrirlandi"?  (the activity log shows neither student nor amount)
 *
 * Source: the student page /admin/students/{id}, tab "Tarix": every action is written there with group, OLD -> NEW amount (negative for a
 * minus payment), the person who did it and the date-time. The tab is filled by ONE Livewire call to the component students.history-livewire
 * (whitelisted in lcup_policy.php, load/render only — always the standard path).
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
    $ins = db()->prepare("INSERT OR IGNORE INTO lcup_history (branch_id, student_id, hkey, text, group_name, amount, staff, at, first_seen, kind, old_amount, new_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"); $n = 0;
    foreach (array_slice($rows, 0, 300) as $r) {
        $k = lcup_hkey($r);
        $ins->execute([$bid, $sid, $k, $r['text'], $r['group'], $r['amount'], $r['staff'], $r['at'], lcup_now(), $r['kind'] ?? lcup_classify_event((string)$r['text'])[0], $r['old_amount'] ?? null, $r['new_amount'] ?? $r['amount']]); $n += $ins->rowCount();
    }
    db()->prepare("INSERT OR IGNORE INTO lcup_student_ids (branch_id, student_id) VALUES (?,?)")->execute([$bid, $sid]);
    db()->prepare("UPDATE lcup_student_ids SET last_hist_check = ? WHERE branch_id = ? AND student_id = ?")->execute([lcup_now(), $bid, $sid]);
    return $n;
}
/** Student page (GET, for the Livewire components) + the "Tarix" tab through students.history-livewire. */
function lcup_fetch_history(LcupClient $c, int $bid, string $sid): array {
    $page = $c->view_page('/admin/students/' . rawurlencode($sid));
    $fr = $c->view_history($page, $sid);
    $h = $fr ? lcup_parse_student_history(implode("\n", $fr)) : ['structure' => false, 'strategy' => 'none', 'rows' => []];
    if ($fr) $h['structure'] = true;                                                    // the component answered: an empty history is a valid (empty) answer
    lcup_history_store($bid, $sid, $h['rows']);
    return $h + ['via' => 'livewire'];
}
/** The history row that belongs to a log row: minus edit, same staff, same minute (±1). A history row identifies ONE log row only. */
function lcup_match_edit(array $act, array $rows, ?int $bid = null, ?string $sid = null): ?array {
    foreach ($rows as $r) {
        if (!lcup_history_is_minus_edit($r)) continue;
        if (lc_norm((string)$r['staff']) !== lc_norm((string)$act['staff'])) continue;
        if (abs(strtotime($r['at']) - strtotime($act['act_time'])) > 70) continue;               // log time is minute-precise: ±1 minute
        if ($bid !== null && $sid !== null) { $q = db()->prepare("SELECT act_id FROM lcup_history WHERE student_id = ? AND hkey = ? AND act_id IS NOT NULL"); $q->execute([$sid, lcup_hkey($r)]); $used = $q->fetchColumn(); if ($used !== false && $used !== null && $used !== '' && $used !== $act['act_id']) continue; }
        return $r;
    }
    return null;
}
function lcup_stored_rows(int $bid, string $sid): array {
    $q = db()->prepare("SELECT text, kind, group_name AS `group`, amount, old_amount, new_amount, staff, at FROM lcup_history WHERE student_id = ? ORDER BY at"); $q->execute([$sid]); return $q->fetchAll();
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
/** The log row is identified: Tarix evidence (actor, group, OLD -> NEW) attached to the incident of that log row; the incident decides red/yellow and alerts. */
function lcup_resolve_edit(int $bid, array $act, string $sid, array $row): void {
    $new = (float)$row['amount']; $old = isset($row['old_amount']) && $row['old_amount'] !== null ? (float)$row['old_amount'] : lcup_old_charge($bid, $sid, $row['group'], $new, (string)$row['at']);
    $name = lcup_student_name($bid, $sid); $bf = (bool)$act['backfill'];
    [$b] = lcup_branch_for(null, $row['group'], $sid, $name);
    $id = lcup_event_add(['type' => 'charge_edited', 'branch_id' => $b, 'source' => 'tarix', 'staff' => $row['staff'] ?: $act['staff'], 'student_id' => $sid, 'student_name' => $name, 'group_name' => $row['group'], 'amount' => $new, 'act_id' => $act['act_id'],
        'lcup_time' => $row['at'], 'before' => $old !== null ? ['amount' => $old] : null, 'after' => ['amount' => $new], 'backfill' => $bf, 'is_charge' => true, 'info_only' => true,
        'details' => "Minus to'lov tahrirlandi (jurnal #{$act['act_id']}): " . ($name ?: "#$sid") . ($row['group'] ? " · {$row['group']}" : '') . ' — ' . ($old !== null ? fmt($old) . ' → ' . fmt($new) : "yangi summa " . fmt($new) . " (eski summa topilmadi)") . " so'm",
        'raw' => $row['raw'] ?? '', 'dedupe_key' => "chgres|{$act['act_id']}"]);
    db()->prepare("UPDATE lcup_history SET act_id = ? WHERE student_id = ? AND hkey = ?")->execute([$act['act_id'], $sid, lcup_hkey(['text' => $row['text'] ?? "Talaba uchun minus to'lov tahrirlandi", 'group' => $row['group'], 'amount' => $row['amount'], 'staff' => $row['staff'], 'at' => $row['at']])]);
    db()->prepare("UPDATE lcup_acts SET state = 'linked', student_id = ?, student_name = ?, group_name = COALESCE(?, group_name), cur_amount = ?, alert_amount = ?, old_amount = ?, branch_id = COALESCE(?, branch_id) WHERE act_id = ?")->execute([$sid, $name, $row['group'], $new, $old, $old, $b, $act['act_id']]);
    if ($id) { $inc = lcup_inc_of_act((string)$act['act_id']); lcup_inc_attach($id, $inc); }
}
/** Incident that holds the log row $actId (new rows: event.act_id, older rows: dedupe key act|<branch>|<id>). */
function lcup_inc_of_act(string $actId): ?int {
    $q = db()->prepare("SELECT e.id FROM lcup_events e WHERE (e.act_id = ? AND e.source = 'worker_report') OR e.dedupe_key = ? OR e.dedupe_key LIKE ? ORDER BY e.id LIMIT 1"); $q->execute([$actId, 'act|' . $actId, 'act|%|' . $actId]);
    $eid = $q->fetchColumn(); return $eid ? lcup_inc_attach((int)$eid) : null;      // attaches the log row first when this run has not processed it yet
}
/** Students worth looking at first for one pending log row, ranked by how close their activity is to the edit time T (a, b, c on ties). */
function lcup_edit_candidates(int $bid, array $act): array {
    $T = strtotime($act['act_time']); $lo = date('Y-m-d H:i:s', $T - 900); $hi = date('Y-m-d H:i:s', $T + 900); $best = [];
    $add = function (string $sid, int $score, int $src) use (&$best) { if (!isset($best[$sid]) || [$score, $src] < $best[$sid]) $best[$sid] = [$score, $src]; };
    foreach ((json_decode((string)lcup_state_get("debt_changed_$bid", '[]'), true) ?: []) as $d)
        if (!empty($d['sid']) && ($d['at'] ?? '') >= date('Y-m-d H:i:s', $T - 1800)) $add((string)$d['sid'], abs(strtotime($d['at']) - $T), 0);              // a) debt changed since the last look
    $q = db()->prepare("SELECT student_id, act_time FROM lcup_acts WHERE staff = ? AND student_id IS NOT NULL AND act_time BETWEEN ? AND ? AND act_id <> ?"); $q->execute([$act['staff'], $lo, $hi, $act['act_id']]);
    foreach ($q->fetchAll() as $r) $add((string)$r['student_id'], abs(strtotime($r['act_time']) - $T), 1);                                                                        // b) students this staff member worked with around T
    $q = db()->prepare("SELECT student_id, COALESCE(lcup_time, detected_at) AS t FROM lcup_events WHERE branch_id = ? AND student_id IS NOT NULL AND type IN ('payment_created','payment_edited','payment_changed','amount_changed','payment_deleted') AND COALESCE(lcup_time, detected_at) BETWEEN ? AND ?");
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
    // rows without a student have no branch yet ("aniqlanmoqda"): they are looked for in every branch view they were seen in
    $q = db()->prepare("SELECT * FROM lcup_acts WHERE type = 'charge_edited' AND state IN ('pending', 'unresolved') AND (branch_id = ? OR (branch_id IS NULL AND (',' || seen_in || ',') LIKE ?)) ORDER BY act_time");
    $q->execute([$bid, '%,' . $bid . ',%']); $pend = $q->fetchAll(); if (!$pend) return;
    $resolve = function (array $act, string $sid, array $row) use ($bid, &$pend) { lcup_resolve_edit($bid, $act, $sid, $row); $pend = array_values(array_filter($pend, function ($p) use ($act) { return $p['act_id'] !== $act['act_id']; })); };
    // 1) history rows we already have (stored by an earlier lookup or by last night's scan)
    foreach ($pend as $act) {
        $q2 = db()->prepare("SELECT DISTINCT student_id FROM lcup_history WHERE kind = 'charge_edited' AND at BETWEEN ? AND ?");
        $q2->execute([date('Y-m-d H:i:s', strtotime($act['act_time']) - 120), date('Y-m-d H:i:s', strtotime($act['act_time']) + 120)]);
        foreach ($q2->fetchAll(PDO::FETCH_COLUMN) as $sid) if (($m = lcup_match_edit($act, lcup_stored_rows($bid, (string)$sid), $bid, (string)$sid))) { $resolve($act, (string)$sid, $m); break; }
    }
    $failUntil = (int)lcup_state_get("hist_fail_until_$bid", 0); $fails = 0; $budget = 40; $memo = [];
    $fetch = function (string $sid) use ($c, $bid, &$sum, &$fails, $failUntil, &$memo) {
        if (isset($memo[$sid])) return $memo[$sid];                                      // one request per student per run, however many pending rows want it
        if (time() < $failUntil) return null;
        try { $h = lcup_fetch_history($c, $bid, $sid); }
        catch (LcupError $e) { if (in_array($e->kind, ['login', 'network', 'ratelimit', 'config'], true)) throw $e; return null; }      // another branch's student, deleted, ...
        if (!$h['structure']) { if (++$fails >= 3) { lcup_state_set("hist_fail_until_$bid", time() + 1800); $sum['warnings'][] = (branch_get($bid)['name'] ?? $bid) . ": talaba sahifasida «Tarix» qatorlari topilmadi (3 ta sahifa; students.history-livewire). Sahifa tuzilishi kutilganidan farq qiladi — Diagnostika yuboring (30 daqiqa kutiladi)."; } return null; }
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
        if ($looked || !$cands) db()->prepare("UPDATE lcup_acts SET paired_at = ? WHERE act_id = ?")->execute([lcup_now(), $act['act_id']]);   // retried later when nothing could be read (history unavailable)
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
        db()->prepare("UPDATE lcup_acts SET state = 'unresolved' WHERE act_id = ?")->execute([$act['act_id']]);
        $eid = lcup_event_add(['type' => 'charge_edit_unresolved', 'branch_id' => null, 'source' => 'worker_report', 'staff' => $act['staff'], 'lcup_time' => $act['act_time'], 'is_charge' => true, 'act_id' => $act['act_id'],
            'details' => "«Minus to'lov tahrirlandi» (jurnal #{$act['act_id']}, " . date('d.m H:i', strtotime($act['act_time'])) . ") 24 soatdan beri talabaga bog'lanmadi — tahrir qilingan talaba aniqlanmadi. Qo'lda tekshiring.", 'dedupe_key' => "chgunres|{$act['act_id']}"]);
        if ($eid) lcup_inc_attach($eid, lcup_inc_of_act((string)$act['act_id']));
    }
}


/* ============================================================== incidents confirmed from the student's Tarix */
/** The Tarix row that is the same action as incident $i (same kind, same amounts, time inside the incident window ±2 min). */
function lcup_tarix_match(array $i, array $rows): ?array {
    $want = ['deleted' => ['payment_deleted'], 'moved_other' => ['payment_deleted'], 'created' => ['payment_created'], 'edited' => ['payment_edited'], 'charge_edit' => ['charge_edited'], 'balance_edit' => ['balance_edited'], 'moved' => ['payment_deleted', 'payment_edited', 'payment_created']][$i['kind']] ?? null;
    if (!$want) return null; $lo = strtotime($i['win_from']) - 120; $hi = strtotime($i['win_to']) + 120; $best = null; $bs = null;
    foreach ($rows as $r) {
        $k = $r['kind'] ?? lcup_classify_event((string)$r['text'])[0]; if (!in_array($k, $want, true)) continue;
        $t = strtotime($r['at']); if ($t < $lo || $t > $hi) continue;
        if ($i['group_name'] && $r['group'] && lc_norm((string)$i['group_name']) !== lc_norm((string)$r['group'])) continue;
        $amt = $k === 'payment_deleted' ? $i['amount'] : ($i['kind'] === 'moved' ? $i['new_amount'] : ($i['new_amount'] ?? $i['amount']));
        if ($amt !== null && $r['amount'] !== null && abs(abs((float)$r['amount']) - abs((float)$amt)) >= 0.5) continue;
        if ($i['kind'] !== 'moved' && $i['old_amount'] !== null && ($r['old_amount'] ?? null) !== null && abs(abs((float)$r['old_amount']) - abs((float)$i['old_amount'])) >= 0.5) continue;
        $sc = abs($t - strtotime($i['at'] ?: $i['win_to'])); if ($bs === null || $sc < $bs) { $bs = $sc; $best = $r + ['kind' => $k]; }
    }
    return $best;
}
/** Stores a matched Tarix row as evidence of incident $incId (append-only event, source "tarix") and re-derives the incident. */
function lcup_tarix_attach(int $incId, string $sid, array $r, ?string $name = null): ?int {
    $type = $r['kind']; [$b] = lcup_branch_for(null, $r['group'], $sid, $name);
    $id = lcup_event_add(['type' => $type, 'source' => 'tarix', 'branch_id' => $b, 'staff' => $r['staff'], 'student_id' => $sid, 'student_name' => $name, 'group_name' => $r['group'], 'amount' => $r['amount'], 'lcup_time' => $r['at'],
        'before' => ($r['old_amount'] ?? null) !== null ? ['amount' => $r['old_amount']] : null, 'after' => $r['amount'] !== null ? ['amount' => $r['amount']] : null, 'info_only' => true,
        'details' => 'Tarix: ' . $r['text'] . ' · ' . ($r['group'] ?: '') . (($r['old_amount'] ?? null) !== null ? ' · ' . fmt($r['old_amount']) . ' → ' . fmt($r['amount']) : ($r['amount'] !== null ? ' · ' . fmt($r['amount']) : '')), 'raw' => $r['raw'] ?? '',
        'dedupe_key' => 'tarix|' . $sid . '|' . lcup_hkey($r)]);
    if ($id) lcup_inc_attach($id, $incId);
    return $id;
}
/**
 * Every run: incidents whose actor is unknown (snapshot-only detections) or whose old amount is missing (log "tahrirlandi" shows only the new amount)
 * are looked up in the student's Tarix (max tarix_per_run students per branch and run). Found -> actor / old amount filled in, ✅ Tasdiqlangan.
 */
function lcup_incident_tarix(LcupClient $c, int $bid, array &$sum, float $deadline): void {
    $budget = max(1, (int)lcup_num('tarix_per_run')); $since = date('Y-m-d H:i:s', strtotime('-4 days'));
    $q = db()->prepare("SELECT * FROM lcup_incidents WHERE student_id IS NOT NULL AND kind IN ('created','edited','deleted','moved','moved_other','charge_edit','balance_edit')
        AND (actor IS NULL OR (kind IN ('edited','moved') AND old_amount IS NULL AND sources NOT LIKE '%tarix%')) AND first_detected >= ? AND tarix_tries < 4
        AND (tarix_checked_at IS NULL OR tarix_checked_at < ?) AND (branch_id = ? OR branch_id IS NULL) ORDER BY backfill, id DESC LIMIT 60");
    $q->execute([$since, date('Y-m-d H:i:s', time() - 1800), $bid]); $memo = [];
    foreach ($q->fetchAll() as $i) {
        if (microtime(true) > $deadline - 3) break; $sid = (string)$i['student_id'];
        if (!isset($memo[$sid])) { if ($budget-- <= 0) break;
            try { $memo[$sid] = lcup_fetch_history($c, $bid, $sid); } catch (LcupError $e) { if (in_array($e->kind, ['login', 'network', 'ratelimit', 'config'], true)) throw $e; $memo[$sid] = null; } }
        db()->prepare("UPDATE lcup_incidents SET tarix_checked_at = ?, tarix_tries = tarix_tries + 1, hold_until = NULL WHERE id = ?")->execute([lcup_now(), $i['id']]);
        $h = $memo[$sid]; if (!$h || !$h['rows']) continue;
        if ($i['kind'] === 'moved') { foreach (['payment_deleted', 'payment_edited', 'payment_created'] as $part) { $x = $i; $x['kind'] = $part === 'payment_deleted' ? 'deleted' : ($part === 'payment_edited' ? 'edited' : 'created'); if ($x['kind'] !== 'deleted') { $x['amount'] = $i['new_amount']; } $m = lcup_tarix_match($x, $h['rows']); if ($m) lcup_tarix_attach((int)$i['id'], $sid, $m, $i['student_name']); } continue; }
        if (($m = lcup_tarix_match($i, $h['rows']))) lcup_tarix_attach((int)$i['id'], $sid, $m, $i['student_name']);
    }
}
