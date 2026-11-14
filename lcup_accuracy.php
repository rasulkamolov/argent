<?php
/**
 * Aniqlik tekshiruvi — proof that the monitor names the right person, branch and amount.
 * Every day after accuracy_time: 10 random incidents of the last 7 days (with a student) are re-checked against a FRESH read of the student's Tarix:
 *   actor  = the Tarix row's person,   amount = the Tarix row's amount(s),   branch = the branch of the row's group (group map from /admin/groups).
 * Results are stored (lcup_accuracy) and shown on aniqlik.php; below 100% -> one Telegram alert. A mismatch is also fed back as Tarix evidence,
 * so the incident itself gets corrected ("tuzatildi").
 */
require_once __DIR__ . '/lcup_incidents.php';
require_once __DIR__ . '/lcup_edits.php';   // lcup_tarix_match / lcup_fetch_history

const LCUP_ACC_SAMPLE = 10;

/** Today's sample (picked once a day, same for every branch run). */
function lcup_accuracy_pick(string $day): array {
    $k = "acc_pick_$day"; $v = lcup_state_get($k);
    if ($v !== null) return json_decode($v, true) ?: [];
    $q = db()->prepare("SELECT id FROM lcup_incidents WHERE student_id IS NOT NULL AND kind IN ('created','edited','deleted','moved','moved_other','charge_edit') AND backfill = 0 AND at >= ? AND at <= ? AND first_detected <= ? ORDER BY RANDOM() LIMIT " . LCUP_ACC_SAMPLE);
    $q->execute([date('Y-m-d 00:00:00', strtotime($day . ' -7 days')), lcup_now(), date('Y-m-d H:i:s', time() - 900)]);   // settled incidents only (15 min after detection)
    $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    lcup_state_set($k, json_encode($ids)); return $ids;
}
/** Re-checks one incident against fresh Tarix rows. Returns the stored result row. */
function lcup_accuracy_check_one(array $i, array $rows, string $day): array {
    $parts = $i['kind'] === 'moved' ? ['deleted', 'edited'] : [$i['kind'] === 'moved_other' ? 'deleted' : $i['kind']];
    $found = []; $actorOk = true; $amountOk = true; $branchOk = true; $notes = [];
    foreach ($parts as $p) {
        $x = $i; $x['kind'] = $p; if ($i['kind'] === 'moved' && $p === 'edited') { $x['amount'] = $i['new_amount']; } if ($i['kind'] === 'moved' && $p === 'deleted') { $x['old_amount'] = null; $x['new_amount'] = null; }
        // exact row first (two actions of one student in the same minute); kind + time only as a fallback, so a WRONG amount is still found
        $m = lcup_tarix_match($x, $rows);
        $y = $x; $y['amount'] = null; $y['old_amount'] = null; $y['new_amount'] = null; if (!$m) $m = lcup_tarix_match($y, $rows);
        if (!$m) { if ($i['kind'] === 'moved' && $p === 'edited') { $y['kind'] = 'created'; $m = lcup_tarix_match($y, $rows); } }
        if (!$m) { $actorOk = $amountOk = $branchOk = false; $notes[] = lcup_kind_label($p) . ": Tarixda topilmadi"; continue; }
        $found[] = ['kind' => $m['kind'], 'staff' => $m['staff'], 'at' => $m['at'], 'group' => $m['group'], 'old' => $m['old_amount'] ?? null, 'new' => $m['amount']];
        if (lc_norm((string)$m['staff']) !== lc_norm((string)$i['actor'])) { $actorOk = false; $notes[] = "xodim: Argent «" . ($i['actor'] ?: "Noma'lum") . "», Tarix «" . $m['staff'] . "»"; }
        $wantNew = $p === 'deleted' ? $i['amount'] : ($i['kind'] === 'moved' ? $i['new_amount'] : ($i['new_amount'] ?? $i['amount']));
        if ($wantNew !== null && $m['amount'] !== null && abs(abs((float)$m['amount']) - abs((float)$wantNew)) >= 0.5) { $amountOk = false; $notes[] = "summa: Argent " . fmt($wantNew) . ", Tarix " . fmt($m['amount']); }
        if (($m['old_amount'] ?? null) !== null && $i['old_amount'] !== null && abs(abs((float)$m['old_amount']) - abs((float)$i['old_amount'])) >= 0.5) { $amountOk = false; $notes[] = "eski summa: Argent " . fmt($i['old_amount']) . ", Tarix " . fmt($m['old_amount']); }
        [$gb] = lcup_branch_for(null, $m['group'], null, null);
        if ($gb === null) { [$gb] = lcup_branch_for($i['group_id'], null, null, null); }
        if ($gb === null || (int)$gb !== (int)$i['branch_id']) { $branchOk = false; $notes[] = "filial: Argent «" . lcup_branch_name($i['branch_id'] !== null ? (int)$i['branch_id'] : null) . "», guruh bo'yicha «" . lcup_branch_name($gb) . "»"; }
        // feed the truth back: the incident gets corrected (and "tuzatildi" in the summary)
        if (!$actorOk || !$amountOk) lcup_tarix_attach((int)$i['id'], (string)$i['student_id'], $m, $i['student_name']);
    }
    $exp = ['actor' => $i['actor'], 'branch' => $i['branch_id'], 'amount' => $i['amount'], 'old' => $i['old_amount'], 'new' => $i['new_amount'], 'kind' => $i['kind']];
    db()->prepare("INSERT INTO lcup_accuracy (day, checked_at, incident_id, actor_ok, branch_ok, amount_ok, expected, found, note) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$day, lcup_now(), $i['id'], $actorOk ? 1 : 0, $branchOk ? 1 : 0, $amountOk ? 1 : 0, json_encode($exp, JSON_UNESCAPED_UNICODE), json_encode($found, JSON_UNESCAPED_UNICODE), implode('; ', $notes)]);
    return ['actor_ok' => $actorOk, 'branch_ok' => $branchOk, 'amount_ok' => $amountOk, 'note' => implode('; ', $notes)];
}
/** Runs inside each branch sync: checks today's picked incidents of THIS branch (student pages are read in the open branch). */
function lcup_accuracy_due(LcupClient $c, int $bid, array &$sum, float $deadline, bool $force = false): void {
    $day = date('Y-m-d'); if (!$force && date('H:i') < (string)lcup_setting('accuracy_time')) return;
    $ids = lcup_accuracy_pick($day); if (!$ids) return;
    $done = db()->prepare("SELECT incident_id FROM lcup_accuracy WHERE day = ?"); $done->execute([$day]); $done = array_map('intval', $done->fetchAll(PDO::FETCH_COLUMN));
    $budget = $force ? 20 : 4;
    foreach ($ids as $id) {
        if (in_array($id, $done, true) || microtime(true) > $deadline - 3 || $budget <= 0) continue;
        $i = lcup_inc_get($id);
        if (!$i) { db()->prepare("INSERT INTO lcup_accuracy (day, checked_at, incident_id, actor_ok, branch_ok, amount_ok, note) VALUES (?,?,?,1,1,1,?)")->execute([$day, lcup_now(), $id, "hodisa qayta ishlashda boshqasiga qo'shilgan"]); continue; }
        if ($i['branch_id'] !== null && (int)$i['branch_id'] !== $bid) continue;
        if ($i['branch_id'] === null && $bid !== (lcup_branch_ids()[0] ?? $bid)) continue;
        $budget--;
        try { $h = lcup_fetch_history($c, $bid, (string)$i['student_id']); } catch (LcupError $e) { if (in_array($e->kind, ['login', 'network', 'ratelimit', 'config'], true)) throw $e; $h = ['rows' => []]; }
        lcup_accuracy_check_one($i, $h['rows'], $day);
    }
    lcup_accuracy_report($day);
}
/** [checked, ok, details] of a day; sends ONE Telegram alert when the whole sample is checked and accuracy < 100%. */
function lcup_accuracy_day(string $day): array {
    $q = db()->prepare("SELECT a.*, i.student_name, i.kind, i.actor FROM lcup_accuracy a LEFT JOIN lcup_incidents i ON i.id = a.incident_id WHERE a.day = ? ORDER BY a.id"); $q->execute([$day]); $rows = $q->fetchAll();
    $ok = count(array_filter($rows, function ($r) { return $r['actor_ok'] && $r['branch_ok'] && $r['amount_ok']; }));
    return ['checked' => count($rows), 'ok' => $ok, 'rows' => $rows, 'actor' => count(array_filter(array_column($rows, 'actor_ok'))), 'branch' => count(array_filter(array_column($rows, 'branch_ok'))), 'amount' => count(array_filter(array_column($rows, 'amount_ok')))];
}
function lcup_accuracy_report(string $day): void {
    $want = count(lcup_accuracy_pick($day)); $d = lcup_accuracy_day($day);
    if (!$want || $d['checked'] < $want || lcup_state_get("acc_sent_$day")) return;
    lcup_state_set("acc_sent_$day", (string)$d['ok']);
    if ($d['ok'] < $d['checked'] && lcup_setting('alerts_on') === '1' && lcup_tg_ready())
        lcup_tg_send("⚠️ <b>LC-UP aniqlik tekshiruvi: {$d['ok']}/{$d['checked']}</b>\nXodim: {$d['actor']}/{$d['checked']} · filial: {$d['branch']}/{$d['checked']} · summa: {$d['amount']}/{$d['checked']}\nNotoʻgʻrilari Tarix boʻyicha tuzatildi." . (lcup_app_url() ? "\n🔗 " . lcup_h(lcup_app_url() . '/aniqlik.php?day=' . $day) : ''));
}
