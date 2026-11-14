<?php
/**
 * INCIDENTS — one row per REAL action in LC-UP, built from every piece of evidence about it.
 *
 *   evidence (lcup_events, append-only)      ->  incident (lcup_incidents, derived, rebuilt by tools/lcup_reprocess.php)
 *   jurnal (activity log row, per staff)        actor  = ONLY from the log or the student's Tarix
 *   arxiv  (deleted-payments archive)           creator = the HODIM column of lists/archive (never shown as the person who edited/deleted)
 *   snapshot (payment list compared run-to-run) branch = from the group id (lcup_groups) / the student, never from the branch that was open while reading
 *   Tarix  (student history, old -> new)        one Telegram alert per incident; later corrections are reported as "tuzatildi"
 *
 * Corrections of what the original events said (actor, branch, duplicates, type) are written to lcup_corrections (append-only).
 */
require_once __DIR__ . '/lcup_lib.php';

const LCUP_KIND_LABEL = ['created' => "To'lov yaratildi", 'edited' => "To'lov tahrirlandi", 'deleted' => "To'lov o'chirildi", 'moved' => "To'lov ko'chirildi", 'moved_other' => "Boshqa talabaga ko'chirildi",
    'charge_edit' => "Oylik/minus to'lov tahriri", 'balance_edit' => 'Balans tahrirlandi', 'debt' => 'Qarz sababsiz kamaydi', 'discount' => 'Chegirma', 'cash_missing' => 'Naqd Argentga kiritilmagan',
    'freeze' => 'Muzlatishda pul yechildi', 'system' => 'Tizim', 'other' => 'Boshqa amal'];
/** The filter of the feed: the types that matter (instead of 25 raw event names). */
const LCUP_KIND_FILTER = ['created' => "Yaratildi", 'edited' => "Tahrirlandi", 'deleted' => "O'chirildi", 'moved' => "Ko'chirildi", 'charge_edit' => "Oylik/minus tahriri", 'discount' => 'Chegirma',
    'qarz' => 'Balans / qarz', 'cash_missing' => 'Naqd kiritilmagan', 'system' => 'Tizim'];
const LCUP_SRC_LABEL = ['jurnal' => 'jurnal', 'arxiv' => 'arxiv', 'snapshot' => 'snapshot', 'tarix' => 'Tarix', 'qarzdorlar' => 'qarzdorlar', 'chegirmalar' => 'chegirmalar', 'oylik' => "oylik to'lovlar", 'solishtirish' => 'solishtirish', 'tizim' => 'tizim'];
const LCUP_SRC_RANK = ['tarix' => 0, 'jurnal' => 1, 'arxiv' => 2, 'snapshot' => 3, 'qarzdorlar' => 4, 'chegirmalar' => 4, 'oylik' => 5, 'solishtirish' => 6, 'tizim' => 7];
const LCUP_ACTION_KINDS = ['created', 'edited', 'deleted', 'moved', 'moved_other', 'charge_edit', 'balance_edit'];

function lcup_kind_label(string $k): string { return LCUP_KIND_LABEL[$k] ?? $k; }
function lcup_branch_name(?int $bid): string { return $bid ? (branch_get($bid)['name'] ?? ('#' . $bid)) : 'aniqlanmoqda'; }
function lcup_ts(?string $t): ?int { if ($t === null || $t === '') return null; $x = strtotime($t); return $x === false ? null : $x; }
function lcup_dt(?int $t): ?string { return $t === null ? null : date('Y-m-d H:i:s', $t); }

/* ============================================================== branch: group id -> branch, else the student, else "aniqlanmoqda" */
/** @return array [branch_id|null, how] */
function lcup_branch_for(?string $groupId, ?string $groupName, ?string $sid, ?string $sname): array {
    if ($groupId) { $q = db()->prepare("SELECT branch_id FROM lcup_groups WHERE group_id = ?"); $q->execute([$groupId]); if (($b = $q->fetchColumn())) return [(int)$b, 'guruh']; }
    if ($groupName) {
        $n = lc_norm($groupName); $hit = [];
        foreach (db()->query("SELECT name, branch_id FROM lcup_groups")->fetchAll() as $g) if (lc_norm((string)$g['name']) === $n) $hit[(int)$g['branch_id']] = 1;
        if (count($hit) === 1) return [(int)array_key_first($hit), 'guruh nomi'];
    }
    if ($sid) {   // branch-scoped lists only (payments, debtors, students list): the log is NOT branch-scoped for center-wide users
        $q = db()->prepare("SELECT branch_id FROM lcup_payments WHERE student_id = ? UNION SELECT branch_id FROM lcup_debts WHERE student_id = ? UNION SELECT branch_id FROM lcup_student_ids WHERE student_id = ? AND name <> ''");
        $q->execute([$sid, $sid, $sid]); $b = array_values(array_unique(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN))));
        if (count($b) === 1) return [$b[0], 'talaba'];
    }
    if ($sname) {
        $q = db()->prepare("SELECT branch_id FROM lcup_debts WHERE student_name = ? COLLATE NOCASE UNION SELECT branch_id FROM lcup_payments WHERE student_name = ? COLLATE NOCASE UNION SELECT branch_id FROM lcup_student_ids WHERE name = ? COLLATE NOCASE");
        $q->execute([$sname, $sname, $sname]); $b = array_values(array_unique(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN))));
        if (count($b) === 1) return [$b[0], 'talaba nomi'];
    }
    return [null, null];
}
/** Student id from the name (rows like "Balans tahrirlandi" show the name without a link): debtors list first, then payments / students lists. Unique match only. */
function lcup_student_by_name(string $name): array {
    $q = db()->prepare("SELECT student_id, branch_id FROM lcup_debts WHERE student_name = ? COLLATE NOCASE AND student_id IS NOT NULL UNION SELECT student_id, branch_id FROM lcup_payments WHERE student_name = ? COLLATE NOCASE AND student_id IS NOT NULL UNION SELECT student_id, branch_id FROM lcup_student_ids WHERE name = ? COLLATE NOCASE");
    $q->execute([$name, $name, $name]); $ids = []; foreach ($q->fetchAll() as $r) $ids[(string)$r['student_id']] = (int)$r['branch_id'];
    return count($ids) === 1 ? [(string)array_key_first($ids), (int)reset($ids)] : [null, null];
}
/** Fills / refreshes the derived branch of log rows (after the group map changed). */
function lcup_acts_derive_branches(bool $all = false): int {
    $n = 0; $rows = db()->query("SELECT act_id, group_id, group_name, student_id, student_name, branch_id FROM lcup_acts" . ($all ? '' : " WHERE branch_id IS NULL"))->fetchAll();
    $u = db()->prepare("UPDATE lcup_acts SET branch_id = ? WHERE act_id = ?");
    foreach ($rows as $r) { [$b] = lcup_branch_for($r['group_id'], $r['group_name'], $r['student_id'], $r['student_name']); if ($b !== null && (int)$r['branch_id'] !== $b) { $u->execute([$b, $r['act_id']]); $n++; } elseif ($b === null && $all && $r['branch_id'] !== null && $r['student_id'] === null) { $u->execute([null, $r['act_id']]); $n++; } }
    return $n;
}

/* ============================================================== evidence -> detection */
/** Canonical incident kind of a stored event (old type names included). */
function lcup_ev_kind(array $e): string {
    $t = (string)$e['type']; $minus = stripos((string)$e['details'] . ' ' . (string)$e['raw'], 'minus') !== false;
    switch ($t) {
        case 'payment_created': return 'created';
        case 'payment_deleted': case 'log_delete': case 'payment_missing': return 'deleted';
        case 'payment_edited': case 'payment_changed': case 'amount_changed': return (float)$e['amount'] < 0 && $t === 'payment_changed' ? 'charge_edit' : 'edited';
        case 'log_edit': return $minus ? 'charge_edit' : 'edited';
        case 'charge_edited': case 'charge_edit_resolved': case 'charge_edit_unresolved': case 'charge_changed': return 'charge_edit';
        case 'balance_edited': return 'balance_edit';
        case 'debt_unexplained': case 'debt_reduced': return 'debt';
        case 'discount': case 'discount_new': case 'discount_changed': case 'discount_removed': return 'discount';
        case 'cash_missing': return 'cash_missing';
        case 'freeze_charge': return 'freeze';
        case 'settings_changed': case 'system': return 'system';
    }
    return 'other';
}
function lcup_ev_src(array $e): string {
    $s = (string)$e['source'];
    return ['worker_report' => 'jurnal', 'archive' => 'arxiv', 'snapshot' => 'snapshot', 'reconcile' => $e['type'] === 'cash_missing' ? 'solishtirish' : 'snapshot', 'student_history' => 'tarix', 'tarix' => 'tarix',
            'debtors' => 'qarzdorlar', 'discounts' => 'chegirmalar', 'charges' => 'oylik', 'system' => 'tizim', 'webhook' => 'tizim'][$s] ?? 'tizim';
}
/** Everything the incident builder needs from one event. Actor = only from the log / Tarix (or the person who GAVE a new discount). */
function lcup_detection(array $e): array {
    $kind = lcup_ev_kind($e); $src = lcup_ev_src($e);
    $b = json_decode((string)$e['before_json'], true) ?: []; $a = json_decode((string)$e['after_json'], true) ?: [];
    $actorSrc = in_array($src, ['jurnal', 'tarix'], true) || ($src === 'chegirmalar' && in_array($e['type'], ['discount_new'], true)) || ($src === 'chegirmalar' && $e['type'] === 'discount' && empty($b));
    $actor = $actorSrc && $e['staff'] !== null && $e['staff'] !== '' ? (string)$e['staff'] : null;
    $creator = $e['creator'] ?? null;
    if ($creator === null && !$actorSrc && $e['staff'] !== null && $e['staff'] !== '' && in_array($src, ['arxiv', 'snapshot'], true)) $creator = (string)$e['staff'];   // old rows: the HODIM column was stored as "staff"
    $amt = $e['amount'] !== null ? (float)$e['amount'] : null; $old = isset($b['amount']) ? (float)$b['amount'] : (isset($b['balance']) ? (float)$b['balance'] : null); $new = isset($a['amount']) ? (float)$a['amount'] : (isset($a['balance']) ? (float)$a['balance'] : null);
    if (in_array($kind, ['created', 'deleted'], true) && $amt !== null) $amt = abs($amt);
    if ($kind === 'edited') {
        if ($old === null && $b && !array_key_exists('amount', $b) && $amt !== null) $old = $amt;            // only method / date / group changed: same amount
        if ($new === null) $new = $amt; if ($new !== null) $new = abs($new); if ($old !== null) $old = abs($old);
    }
    if ($kind === 'charge_edit' && $new === null) $new = $amt;
    $t = lcup_ts($e['lcup_time']); $det = lcup_ts($e['detected_at']);
    if (!empty($e['win_from']) || !empty($e['win_to'])) { $from = lcup_ts($e['win_from']) ?? ($det - 36 * 3600); $to = lcup_ts($e['win_to']) ?? $det; $exact = false; }
    elseif ($t !== null && in_array($src, ['jurnal', 'tarix', 'arxiv'], true)) { $from = $t; $to = $t + 59; $exact = true; }
    elseif ($src === 'snapshot') { $from = $det - 36 * 3600; $to = $det + 60; $exact = false; }
    else { $from = $t ?? $det; $to = ($t ?? $det) + 59; $exact = $t !== null; }
    $hint = in_array($src, ['arxiv', 'snapshot', 'qarzdorlar', 'chegirmalar', 'oylik', 'solishtirish'], true) && $e['branch_id'] ? (int)$e['branch_id'] : null;   // these pages are branch-scoped
    $student = $e['student_id'] ?: null; $sname = $e['student_name'] ?: null;
    return ['id' => (int)$e['id'], 'kind' => $kind, 'src' => $src, 'type' => $e['type'], 'actor' => $actor, 'creator' => $creator, 'student_id' => $student, 'student_name' => $sname,
            'group_id' => $e['group_id'] ?? null, 'group_name' => $e['group_name'] ?: null, 'amount' => $amt, 'old' => $old, 'new' => $new, 'method' => $e['method'] ?? null,
            'from' => $from, 'to' => $to, 'exact' => $exact, 'time' => $t, 'detected' => $det, 'hint' => $hint, 'branch_id' => $e['branch_id'] !== null ? (int)$e['branch_id'] : null,
            'act_id' => $e['act_id'] ?? (preg_match('/^act\|(?:\d+\|)?(\d+)$/', (string)$e['dedupe_key'], $m) ? $m[1] : null), 'backfill' => (int)$e['backfill'], 'staff' => $e['staff'], 'flags' => (string)$e['flags'],
            'details' => (string)$e['details'], 'alerted_at' => $e['alerted_at'], 'reviewed_at' => $e['reviewed_at'], 'reviewed_by' => $e['reviewed_by'], 'review_note' => $e['review_note'], 'severity' => $e['severity']];
}

/* ============================================================== matching: is this evidence about the same action as the incident? */
const LCUP_MATCH_SLACK = 180;    // ±3 minutes
function lcup_same_student(array $x, array $y): ?bool {   // null = one side has no student
    if (!empty($x['student_id']) && !empty($y['student_id'])) return (string)$x['student_id'] === (string)$y['student_id'];
    if (!empty($x['student_name']) && !empty($y['student_name'])) return lc_norm($x['student_name']) === lc_norm($y['student_name']);
    return null;
}
function lcup_same_group(array $x, array $y): bool {
    if (!empty($x['group_id']) && !empty($y['group_id'])) return (string)$x['group_id'] === (string)$y['group_id'];
    if (!empty($x['group_name']) && !empty($y['group_name'])) return lc_norm($x['group_name']) === lc_norm($y['group_name']);
    return true;
}
function lcup_eq($a, $b): bool { return $a === null || $b === null || abs((float)$a - (float)$b) < 0.5; }
/** Score (lower = better) of attaching detection $d to incident $i, or null when they are different actions. */
function lcup_match_score(array $i, array $d, array $iSrcs): ?float {
    $ik = $i['kind']; $dk = $d['kind'];
    $parts = ['deleted' => ['deleted', 'moved', 'moved_other'], 'edited' => ['edited', 'moved'], 'created' => ['created', 'moved'], 'charge_edit' => ['charge_edit'], 'balance_edit' => ['balance_edit']];
    if (!isset($parts[$dk]) || !in_array($ik, $parts[$dk], true)) return null;
    // the SAME log row stored twice by the old version (once per branch view) is one action
    if ($d['src'] === 'jurnal' && $d['act_id'] && in_array('act:' . $d['act_id'], $iSrcs, true)) return -1.0;
    // one log row / archive row / Tarix row = one action: a second, different one of the same source and kind is never merged in
    if (in_array($d['src'], ['jurnal', 'arxiv', 'tarix'], true) && in_array($d['src'] . ':' . $dk, $iSrcs, true)) return null;
    $ss = lcup_same_student($i, $d);
    if ($ss === false) return null;
    if ($ss === null && !in_array($dk, ['charge_edit', 'balance_edit'], true)) return null;
    if (!lcup_same_group($i, $d) && $dk !== 'balance_edit') return null;
    if ($i['actor'] !== null && $d['actor'] !== null && lc_norm($i['actor']) !== lc_norm($d['actor'])) return null;
    if ($ss === null && ($i['actor'] === null || $d['actor'] === null || !$d['exact'])) return null;         // no student on one side: only an exact log/Tarix pair of the same person
    // amounts
    if ($dk === 'deleted' && !lcup_eq($i['amount'], $d['amount'])) return null;
    if ($dk === 'created' && !lcup_eq($ik === 'moved' ? $i['new_amount'] : $i['amount'], $d['amount'])) return null;
    if ($dk === 'edited' && (!lcup_eq($i['new_amount'], $d['new']) || !lcup_eq($i['old_amount'], $d['old']))) return null;
    if (in_array($dk, ['charge_edit', 'balance_edit'], true) && (!lcup_eq($i['new_amount'], $d['new']) || !lcup_eq($i['old_amount'], $d['old']))) return null;
    // time windows (±3 min around exact times)
    $if = lcup_ts($i['win_from']); $it = lcup_ts($i['win_to']);
    $lo = max($if - LCUP_MATCH_SLACK, $d['from'] - LCUP_MATCH_SLACK); $hi = min($it + LCUP_MATCH_SLACK, $d['to'] + LCUP_MATCH_SLACK);
    if ($lo > $hi) return null;
    $at = lcup_ts($i['at']); $dt = $d['exact'] ? $d['from'] : (int)(($d['from'] + $d['to']) / 2);
    return ($ss === null ? 1000 : 0) + abs(($at ?? $dt) - $dt) / 60;
}

/* ============================================================== attach evidence -> incident */
function lcup_inc_get(int $id): ?array { $q = db()->prepare("SELECT * FROM lcup_incidents WHERE id = ?"); $q->execute([$id]); return $q->fetch() ?: null; }
function lcup_inc_events(int $id): array { $q = db()->prepare("SELECT e.* FROM lcup_events e JOIN lcup_incident_events l ON l.event_id = e.id WHERE l.incident_id = ? ORDER BY e.id"); $q->execute([$id]); return $q->fetchAll(); }
function lcup_inc_srcs(int $id): array { $o = []; foreach (lcup_inc_events($id) as $e) { $d = lcup_detection($e); $o[] = $d['src']; $o[] = $d['src'] . ':' . $d['kind']; if ($d['act_id']) $o[] = 'act:' . $d['act_id']; } return array_values(array_unique($o)); }
/** Links event $eventId to the right incident (or a new one). $force = incident id chosen by the caller (e.g. a Tarix row looked up FOR that incident). */
function lcup_inc_attach(int $eventId, ?int $force = null, bool $quiet = false): ?int {
    $q = db()->prepare("SELECT * FROM lcup_events WHERE id = ?"); $q->execute([$eventId]); $e = $q->fetch(); if (!$e) return null;
    $q = db()->prepare("SELECT incident_id FROM lcup_incident_events WHERE event_id = ?"); $q->execute([$eventId]); if (($x = $q->fetchColumn())) return (int)$x;
    $d = lcup_detection($e); $target = $force;
    if ($target === null && in_array($d['kind'], ['created', 'edited', 'deleted', 'charge_edit', 'balance_edit'], true)) {
        $c = db()->prepare("SELECT * FROM lcup_incidents WHERE kind IN ('created','edited','deleted','moved','moved_other','charge_edit','balance_edit') AND win_to >= ? AND win_from <= ? ORDER BY id DESC LIMIT 200");
        $c->execute([lcup_dt($d['from'] - 2 * LCUP_MATCH_SLACK), lcup_dt($d['to'] + 2 * LCUP_MATCH_SLACK)]); $best = null; $bs = null;
        foreach ($c->fetchAll() as $i) { $sc = lcup_match_score($i, $d, lcup_inc_srcs((int)$i['id'])); if ($sc !== null && ($bs === null || $sc < $bs)) { $bs = $sc; $best = (int)$i['id']; } }
        $target = $best;
    }
    if ($target === null) {
        db()->prepare("INSERT INTO lcup_incidents (kind, first_detected, updated_at, win_from, win_to, backfill) VALUES (?,?,?,?,?,?)")->execute([$d['kind'], $e['detected_at'], lcup_now(), lcup_dt($d['from']), lcup_dt($d['to']), $d['backfill']]);
        $target = (int)db()->lastInsertId();
    }
    db()->prepare("INSERT OR REPLACE INTO lcup_incident_events (event_id, incident_id) VALUES (?,?)")->execute([$eventId, $target]);
    lcup_inc_recompute($target, $quiet);
    return $target;
}

/* ============================================================== derive an incident from its evidence */
function lcup_first(array $dets, string $f, array $order = null) {
    $order = $order ?: array_keys(LCUP_SRC_RANK);
    usort($dets, function ($x, $y) use ($order) { return [array_search($x['src'], $order, true), $x['id']] <=> [array_search($y['src'], $order, true), $y['id']]; });
    foreach ($dets as $d) if ($d[$f] !== null && $d[$f] !== '') return $d[$f];
    return null;
}
/** Recomputes every derived field of incident $id from its evidence. Writes corrections and "tuzatildi" when an alerted incident changes. */
function lcup_inc_recompute(int $id, bool $quiet = false): void {
    $i = lcup_inc_get($id); if (!$i) return; $evs = lcup_inc_events($id);
    if (!$evs) { db()->prepare("DELETE FROM lcup_incidents WHERE id = ?")->execute([$id]); return; }
    $dets = array_map('lcup_detection', $evs); $kinds = array_unique(array_column($dets, 'kind'));
    // kind: a deletion merged with an increase of the SAME student's other payment = moved
    $kind = $dets[0]['kind'];
    if (in_array('deleted', $kinds, true) && (in_array('edited', $kinds, true) || in_array('created', $kinds, true))) $kind = 'moved';
    elseif (count($kinds) === 1) $kind = $kinds[0];
    if ($kind === 'deleted' && $i['related_id']) $kind = 'moved_other';
    $del = array_values(array_filter($dets, function ($d) { return $d['kind'] === 'deleted'; })); $part = array_values(array_filter($dets, function ($d) { return in_array($d['kind'], ['edited', 'created', 'charge_edit', 'balance_edit'], true); }));
    $f = [];
    $f['actor'] = lcup_first($dets, 'actor', ['tarix', 'jurnal', 'chegirmalar']);
    $f['actor_src'] = null; foreach (['tarix', 'jurnal', 'chegirmalar'] as $s) foreach ($dets as $d) if ($d['src'] === $s && $d['actor'] !== null && $f['actor_src'] === null && lc_norm($d['actor']) === lc_norm((string)$f['actor'])) $f['actor_src'] = $s;
    $f['creator'] = lcup_first($dets, 'creator', ['arxiv', 'snapshot', 'jurnal', 'tarix']);
    $f['student_id'] = lcup_first($dets, 'student_id'); $f['student_name'] = lcup_first($dets, 'student_name', ['jurnal', 'tarix', 'arxiv', 'snapshot']);
    $f['group_id'] = lcup_first($dets, 'group_id'); $f['group_name'] = lcup_first($dets, 'group_name', ['jurnal', 'tarix', 'arxiv', 'snapshot']);
    $f['method'] = lcup_first($dets, 'method');
    if ($kind === 'moved') { $f['amount'] = lcup_first($del, 'amount'); $f['old_amount'] = $part ? lcup_first($part, 'old') : null; $f['new_amount'] = $part ? (lcup_first($part, 'new') ?? lcup_first($part, 'amount')) : null; }
    elseif (in_array($kind, ['edited', 'charge_edit', 'balance_edit'], true)) { $f['old_amount'] = lcup_first($dets, 'old'); $f['new_amount'] = lcup_first($dets, 'new'); $f['amount'] = $f['new_amount'] ?? lcup_first($dets, 'amount'); }
    else { $f['amount'] = lcup_first($dets, 'amount'); $f['old_amount'] = lcup_first($dets, 'old'); $f['new_amount'] = lcup_first($dets, 'new'); }
    // time: exact sources first; window = intersection of the windows
    $exact = array_values(array_filter($dets, function ($d) { return $d['exact']; }));
    usort($exact, function ($x, $y) { return LCUP_SRC_RANK[$x['src']] <=> LCUP_SRC_RANK[$y['src']]; });
    $from = max(array_column($dets, 'from')); $to = min(array_column($dets, 'to'));
    if ($from > $to) { $from = min(array_column($dets, 'from')); $to = max(array_column($dets, 'to')); }
    if ($exact) { $from = $exact[0]['from']; $to = $exact[0]['to']; }
    $f['at'] = lcup_dt($exact ? $exact[0]['from'] : $to); $f['win_from'] = lcup_dt($from); $f['win_to'] = lcup_dt($to);
    $srcs = array_values(array_unique(array_column($dets, 'src'))); usort($srcs, function ($x, $y) { return LCUP_SRC_RANK[$x] <=> LCUP_SRC_RANK[$y]; });
    $f['sources'] = implode(',', $srcs);
    // branch: group / student; branch-scoped pages (archive, payment list, debtors) as a hint when nothing else is known
    [$bid, $how] = lcup_branch_for($f['group_id'], $f['group_name'], $f['student_id'], $f['student_name']);
    if ($bid === null) { $h = array_values(array_unique(array_filter(array_column($dets, 'hint')))); if (count($h) === 1) { $bid = $h[0]; $how = "ro'yxat"; } }
    if ($bid === null && in_array($kind, ['system'], true)) { $how = null; }
    $f['branch_id'] = $bid; $f['branch_src'] = $how;
    $f['leader'] = lcup_is_leader($f['actor']) ? 1 : 0;
    $f['backfill'] = min(array_column($dets, 'backfill'));
    $f['first_detected'] = min(array_column($evs, 'detected_at'));
    $f['kind'] = $kind;
    $f['confidence'] = in_array($kind, ['cash_missing', 'system'], true) || $f['actor_src'] !== null ? 'confirmed' : 'estimated';
    [$f['severity'], $flags] = lcup_inc_severity($f, $dets, $i);
    $f['flags'] = implode(',', $flags);
    $f['details'] = lcup_inc_details($f, $dets);
    $needTarix = $f['student_id'] && in_array($kind, LCUP_ACTION_KINDS, true) && ($f['actor'] === null || (in_array($kind, ['edited', 'moved'], true) && $f['old_amount'] === null && in_array('edited', $kinds, true)));
    $f['hold_until'] = !$quiet && !$f['backfill'] && $needTarix && !$i['tarix_checked_at'] ? date('Y-m-d H:i:s', strtotime($f['first_detected']) + 600) : null;
    // corrected after the alert went out?
    $sig = lcup_inc_sig($f);
    if ($i['alerted_at'] && $i['alerted_sig'] && $i['alerted_sig'] !== $sig && !$quiet) {
        $f['corrected_at'] = lcup_now(); $f['correction'] = lcup_inc_diff_text(json_decode($i['alerted_sig'], true) ?: [], json_decode($sig, true)); $f['correction_reported'] = 0; $f['alerted_sig'] = $sig;
    }
    $f['updated_at'] = lcup_now();
    $set = []; $vals = []; foreach ($f as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; } $vals[] = $id;
    db()->prepare("UPDATE lcup_incidents SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
    lcup_inc_write_corrections($id, $f, $evs, $dets);
}
/** [severity, flags] of an incident. */
function lcup_inc_severity(array $f, array $dets, array $old): array {
    $k = $f['kind']; $fl = []; $sev = 'info';
    $t = $f['at'] ? substr($f['at'], 11, 5) : null;
    switch ($k) {
        case 'deleted': $sev = 'red'; $fl[] = 'deleted'; break;
        case 'moved':
            $fl[] = 'moved';
            $inc = $f['old_amount'] !== null && $f['new_amount'] !== null ? (float)$f['new_amount'] - (float)$f['old_amount'] : ($f['old_amount'] === null && $f['new_amount'] !== null ? (float)$f['new_amount'] : null);
            if ($inc !== null && $f['amount'] !== null && $inc < (float)$f['amount'] - 0.5) { $sev = 'red'; $fl[] = 'total_down'; } else $sev = 'yellow';
            break;
        case 'moved_other': $sev = 'red'; $fl[] = 'moved_other'; break;
        case 'created':
            if ($f['amount'] !== null && (float)$f['amount'] > 0 && (float)$f['amount'] < lcup_num('small_amount')) { $sev = 'red'; $fl[] = 'small_amount'; }
            if ($old['related_id']) { $fl[] = 'moved_in'; $sev = 'red'; }
            break;
        case 'edited':
            if ($old['related_id']) { $fl[] = 'moved_in'; $sev = 'red'; break; }
            if ($f['old_amount'] !== null && $f['new_amount'] !== null) {
                if ((float)$f['new_amount'] < (float)$f['old_amount'] - 0.5) { $sev = 'red'; $fl[] = 'amount_down'; if (abs((float)$f['new_amount']) < 0.5) $fl[] = 'amount_zero'; }
                elseif ((float)$f['new_amount'] > (float)$f['old_amount'] + 0.5) { $sev = 'yellow'; $fl[] = 'amount_up'; }
                else { $sev = 'yellow'; $fl[] = 'field_change'; }
                if ($sev === 'red' && $f['student_id']) {   // corrected within minutes of creation = a typo, not a manipulation
                    $c = db()->prepare("SELECT act_time FROM lcup_acts WHERE student_id = ? AND type = 'payment_created' AND ABS(amount - ?) < 0.5 AND act_time <= ? ORDER BY act_time DESC LIMIT 1"); $c->execute([$f['student_id'], $f['old_amount'], $f['at']]);
                    if (($ct = $c->fetchColumn()) && strtotime($f['at']) - strtotime($ct) <= (int)lcup_num('quick_fix_min') * 60) { $sev = 'yellow'; $fl[] = 'quick_fix'; }
                }
            } else { $sev = 'red'; $fl[] = 'no_details'; }
            break;
        case 'charge_edit':
            if ($f['old_amount'] !== null && $f['new_amount'] !== null) { if (abs((float)$f['new_amount']) < abs((float)$f['old_amount']) - 0.5) { $sev = 'red'; $fl[] = 'charge_reduced'; } elseif (abs((float)$f['new_amount']) > abs((float)$f['old_amount']) + 0.5) { $sev = 'yellow'; $fl[] = 'charge_increased'; } }
            elseif (!$f['student_id']) { if (in_array('charge_edit_unresolved', array_column($dets, 'type'), true)) { $sev = 'red'; $fl[] = 'charge_unresolved'; } else { $sev = 'info'; $fl[] = 'actor_unknown'; } }
            else { $sev = 'yellow'; $fl[] = 'charge_changed'; }
            break;
        case 'balance_edit':
            $d = $f['old_amount'] !== null && $f['new_amount'] !== null ? abs((float)$f['new_amount'] - (float)$f['old_amount']) : null;
            if ($d !== null && $d <= 10) { $sev = 'yellow'; $fl[] = 'rounding'; } else { $sev = 'red'; $fl[] = 'balance_big'; }
            break;
        case 'debt': $sev = 'red'; $fl[] = 'debt_unexplained'; if (strpos(implode(' ', array_column($dets, 'details')), "ro'yxatdan chiqdi") !== false) $fl[] = 'left_list'; break;
        case 'discount': $sev = strpos(implode(' ', array_column($dets, 'details')), 'ommaviy') !== false ? 'info' : 'red'; $fl[] = 'discount'; break;
        case 'cash_missing': $sev = 'red'; $fl[] = 'cash_missing'; break;
        case 'freeze': if ($f['amount'] !== null && abs((float)$f['amount']) >= 0.5) { $sev = 'yellow'; $fl[] = 'freeze_nonzero'; } break;
        case 'system': $sev = in_array('settings_changed', array_column($dets, 'type'), true) ? 'red' : 'info'; break;
    }
    if (in_array($k, ['created', 'edited', 'deleted', 'moved', 'moved_other', 'charge_edit', 'balance_edit'], true) && $t !== null && $f['actor'] !== null && lcup_outside_hours($t) && !lcup_is_leader($f['actor'])) { $fl[] = 'off_hours'; if ($sev !== 'red') $sev = 'red'; }
    if (in_array($k, LCUP_ACTION_KINDS, true) && $f['actor'] === null && $k !== 'charge_edit') $fl[] = 'actor_unknown';
    if (in_array('gap', array_merge(...array_map(function ($d) { return explode(',', $d['flags']); }, $dets)), true)) $fl[] = 'gap';
    if (!empty($f['leader']) && $sev !== 'info') { $sev = 'yellow'; $fl[] = 'leader'; }
    return [$sev, array_values(array_unique($fl))];
}
function lcup_money_txt($v): string { return $v === null ? '?' : fmt($v); }
function lcup_inc_details(array $f, array $dets): string {
    $k = $f['kind']; $p = [];
    if ($k === 'moved') $p[] = "To'lov " . lcup_money_txt($f['amount']) . " o'chirildi va shu talabaning boshqa to'loviga qo'shildi" . ($f['old_amount'] !== null ? ' (' . lcup_money_txt($f['old_amount']) . ' → ' . lcup_money_txt($f['new_amount']) . ')' : ($f['new_amount'] !== null ? ' (yangi to\'lov ' . lcup_money_txt($f['new_amount']) . ')' : ''));
    elseif ($k === 'moved_other') $p[] = "To'lov " . lcup_money_txt($f['amount']) . " o'chirildi va xuddi shu daqiqada boshqa talabaga qo'shildi";
    elseif (in_array($k, ['edited', 'charge_edit', 'balance_edit'], true)) $p[] = ($f['old_amount'] !== null ? lcup_money_txt($f['old_amount']) . ' → ' . lcup_money_txt($f['new_amount']) : 'yangi summa ' . lcup_money_txt($f['new_amount']) . ($k === 'edited' ? " (eski summa Tarixdan aniqlanmoqda)" : '')) . " so'm";
    elseif (in_array($k, ['created', 'deleted'], true)) $p[] = lcup_money_txt($f['amount']) . " so'm";
    if ($f['group_name']) $p[] = $f['group_name'];
    $pick = null; foreach ($dets as $d) if (in_array($d['kind'], ['debt', 'discount', 'cash_missing', 'system', 'other', 'freeze'], true) || ($k === 'charge_edit' && !$f['student_id'])) { $pick = $d['details']; break; }
    if ($pick) $p[] = $pick;
    return implode(' · ', $p);
}
/** What an alert said (only the fields that matter for that kind): a later change of these = "tuzatildi". */
function lcup_inc_sig(array $f): string {
    $r = function ($v) { return $v !== null ? round((float)$v) : null; }; $twoAmounts = in_array($f['kind'], ['edited', 'charge_edit', 'balance_edit', 'moved'], true);
    return json_encode(['kind' => $f['kind'], 'actor' => $f['actor'], 'branch' => $f['branch_id'], 'amount' => $r($f['amount']), 'old' => $twoAmounts ? $r($f['old_amount']) : null, 'new' => $twoAmounts ? $r($f['new_amount']) : null, 'severity' => $f['severity'], 'student' => $f['student_id']]);
}
function lcup_inc_diff_text(array $a, array $b): string {
    $o = []; $lab = ['kind' => 'Turi', 'actor' => 'Xodim', 'branch' => 'Filial', 'amount' => 'Summa', 'old' => 'Eski summa', 'new' => 'Yangi summa', 'severity' => 'Daraja', 'student' => 'Talaba'];
    foreach ($lab as $k => $l) { $x = $a[$k] ?? null; $y = $b[$k] ?? null; if ((string)$x === (string)$y) continue;
        $fmtv = function ($v) use ($k) { if ($v === null || $v === '') return $k === 'actor' ? "Noma'lum" : '—'; if ($k === 'branch') return lcup_branch_name((int)$v); if ($k === 'kind') return lcup_kind_label((string)$v); if (in_array($k, ['amount', 'old', 'new'], true)) return fmt($v); return (string)$v; };
        $o[] = $l . ': ' . $fmtv($x) . ' → ' . $fmtv($y); }
    return implode('; ', $o);
}
/** Append-only corrections of the ORIGINAL events (what the old feed showed) against the incident. Unique per (event, field, value). */
function lcup_inc_write_corrections(int $id, array $f, array $evs, array $dets): void {
    $ins = db()->prepare("INSERT OR IGNORE INTO lcup_corrections (created_at, event_id, incident_id, field, original, corrected, reason) VALUES (?,?,?,?,?,?,?)"); $now = lcup_now();
    $primary = null; foreach ($dets as $d) if ($primary === null || LCUP_SRC_RANK[$d['src']] < LCUP_SRC_RANK[$primary['src']]) $primary = $d;
    foreach ($dets as $j => $d) {
        $e = $evs[$j]; $actor = $f['actor'] ?? "Noma'lum";
        if ($e['staff'] !== null && $e['staff'] !== '' && in_array($d['kind'], LCUP_ACTION_KINDS, true) && lc_norm((string)$e['staff']) !== lc_norm((string)$f['actor'])) {
            $why = in_array($d['src'], ['arxiv', 'snapshot'], true) ? "«Hodim» ustuni — to'lovni YARATGAN xodim (o'chirgan/tahrirlagan emas)" : ($f['actor_src'] === 'tarix' ? "Talaba «Tarix» i bo'yicha" : "Jurnal bo'yicha");
            $ins->execute([$now, $e['id'], $id, 'actor', $e['staff'], $actor, $why]);
        }
        if ((int)$e['branch_id'] !== (int)$f['branch_id'] && in_array($d['kind'], array_merge(LCUP_ACTION_KINDS, ['debt', 'discount']), true))
            $ins->execute([$now, $e['id'], $id, 'branch', $e['branch_id'] ? lcup_branch_name((int)$e['branch_id']) : '—', lcup_branch_name($f['branch_id']), $f['branch_src'] ? "Guruh/talaba bo'yicha (" . $f['branch_src'] . ")" : "Talaba yo'q — filial aniqlanmoqda"]);
        if (count($dets) > 1 && $d['id'] !== $primary['id']) $ins->execute([$now, $e['id'], $id, 'duplicate', null, 'asosiy yozuv #' . $primary['id'], 'Bitta amal — manbalar: ' . $f['sources']]);
        if ($d['kind'] !== $f['kind'] && in_array($f['kind'], ['moved', 'moved_other'], true)) $ins->execute([$now, $e['id'], $id, 'kind', lcup_type_label((string)$e['type']), lcup_kind_label($f['kind']), "O'chirilgan to'lov puli boshqa to'lovga qo'shilgan"]);
    }
}
/** Corrections of the incident's original events that still apply (latest per event and field; matched by EVENT, so a rebuild keeps them). */
function lcup_inc_corrections(int $id): array {
    $i = lcup_inc_get($id); if (!$i) return [];
    $q = db()->prepare("SELECT c.*, e.type AS ev_type, e.source AS ev_source FROM lcup_corrections c JOIN lcup_events e ON e.id = c.event_id
        WHERE c.event_id IN (SELECT event_id FROM lcup_incident_events WHERE incident_id = ?) AND c.id = (SELECT MAX(c2.id) FROM lcup_corrections c2 WHERE c2.event_id = c.event_id AND c2.field = c.field) ORDER BY c.id");
    $q->execute([$id]); $n = (int)db()->query("SELECT COUNT(*) FROM lcup_incident_events WHERE incident_id = " . (int)$id)->fetchColumn();
    return array_values(array_filter($q->fetchAll(), function ($c) use ($i, $n) {
        if ($c['field'] === 'actor') return lc_norm((string)$c['corrected']) === lc_norm((string)($i['actor'] ?? "Noma'lum"));
        if ($c['field'] === 'branch') return $c['corrected'] === lcup_branch_name($i['branch_id'] !== null ? (int)$i['branch_id'] : null);
        if ($c['field'] === 'duplicate') return $n > 1;
        if ($c['field'] === 'kind') return $c['corrected'] === lcup_kind_label((string)$i['kind']);
        return true;
    }));
}
/* ============================================================== payment moved: a deletion + an increase at the same minute by the same person */
function lcup_link_moves(?string $since = null): int {
    $since = $since ?: date('Y-m-d H:i:s', strtotime('-3 days')); $n = 0;
    $q = db()->prepare("SELECT * FROM lcup_incidents WHERE kind = 'deleted' AND related_id IS NULL AND student_id IS NOT NULL AND amount IS NOT NULL AND at >= ? ORDER BY at"); $q->execute([$since]);
    foreach ($q->fetchAll() as $d) {
        $at = strtotime($d['at']); $c = db()->prepare("SELECT * FROM lcup_incidents WHERE id <> ? AND kind IN ('edited','created') AND related_id IS NULL AND at BETWEEN ? AND ?"); $c->execute([$d['id'], lcup_dt($at - LCUP_MATCH_SLACK), lcup_dt($at + LCUP_MATCH_SLACK)]);
        $same = null; $other = null;
        foreach ($c->fetchAll() as $p) {
            if ($d['actor'] !== null && $p['actor'] !== null && lc_norm($d['actor']) !== lc_norm($p['actor'])) continue;
            if ($d['actor'] === null && $p['actor'] === null) continue;
            $gain = $p['kind'] === 'created' ? (float)$p['amount'] : ($p['old_amount'] !== null && $p['new_amount'] !== null ? (float)$p['new_amount'] - (float)$p['old_amount'] : null);
            if ($gain === null || $gain < 0.5) continue;
            $ss = lcup_same_student($d, $p);
            if ($ss === true && $gain <= (float)$d['amount'] + 0.5) { if (!$same || abs($gain - $d['amount']) < abs(($same[1]) - $d['amount'])) $same = [$p, $gain]; }
            elseif ($ss === false && abs($gain - (float)$d['amount']) < 0.5) $other = $p;
        }
        if ($same) {   // same student: ONE incident "to'lov ko'chirildi"
            db()->prepare("UPDATE lcup_incident_events SET incident_id = ? WHERE incident_id = ?")->execute([$d['id'], $same[0]['id']]);
            if ($same[0]['reviewed_at'] && !$d['reviewed_at']) db()->prepare("UPDATE lcup_incidents SET reviewed_at = ?, reviewed_by = ?, review_note = ? WHERE id = ?")->execute([$same[0]['reviewed_at'], $same[0]['reviewed_by'], $same[0]['review_note'], $d['id']]);
            if ($same[0]['alerted_at'] && !$d['alerted_at']) db()->prepare("UPDATE lcup_incidents SET alerted_at = ?, alerted_sig = ? WHERE id = ?")->execute([$same[0]['alerted_at'], $same[0]['alerted_sig'], $d['id']]);
            db()->prepare("DELETE FROM lcup_incidents WHERE id = ?")->execute([$same[0]['id']]); lcup_inc_recompute((int)$d['id']); $n++;
        } elseif ($other) {   // money went to ANOTHER student
            db()->prepare("UPDATE lcup_incidents SET related_id = ? WHERE id = ?")->execute([$other['id'], $d['id']]); db()->prepare("UPDATE lcup_incidents SET related_id = ? WHERE id = ?")->execute([$d['id'], $other['id']]);
            lcup_inc_recompute((int)$d['id']); lcup_inc_recompute((int)$other['id']); $n++;
        }
    }
    return $n;
}

/* ============================================================== pipeline */
/** Attaches every event that is not part of an incident yet, links moved payments, alerts. Called at the end of each sync. */
function lcup_incidents_process(bool $quiet = false): array {
    $st = ['events' => 0, 'incidents' => 0];
    $q = db()->query("SELECT e.id FROM lcup_events e LEFT JOIN lcup_incident_events l ON l.event_id = e.id WHERE l.event_id IS NULL ORDER BY e.id");
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eid) { $before = (int)db()->query("SELECT COUNT(*) FROM lcup_incidents")->fetchColumn(); lcup_inc_attach((int)$eid, null, $quiet); $st['events']++; $st['incidents'] += (int)db()->query("SELECT COUNT(*) FROM lcup_incidents")->fetchColumn() - $before; }
    $st['moved'] = lcup_link_moves($quiet ? '2000-01-01' : null);
    if (!$quiet) lcup_leader_offhours();
    return $st;
}
/** One Telegram alert when a leader account is used outside the leader hours (per off-hours period). */
function lcup_leader_offhours(): void {
    $from = (string)lcup_setting('leader_from'); $to = (string)lcup_setting('leader_to');
    $q = db()->query("SELECT * FROM lcup_incidents WHERE leader = 1 AND backfill = 0 AND kind IN ('created','edited','deleted','moved','moved_other','charge_edit','balance_edit','discount') AND at >= '" . date('Y-m-d H:i:s', strtotime('-1 day')) . "' ORDER BY at");
    foreach ($q->fetchAll() as $i) {
        $t = substr((string)$i['at'], 11, 5); if ($t >= $from && $t < $to) continue;
        $period = $t < $from ? date('Y-m-d', strtotime(substr($i['at'], 0, 10) . ' -1 day')) : substr($i['at'], 0, 10);
        if (lcup_state_get("leader_off_$period")) continue;
        lcup_state_set("leader_off_$period", (string)$i['id']);
        if (lcup_setting('alerts_on') === '1' && lcup_tg_ready()) lcup_tg_send("⚠️ <b>Rahbar akkaunti ish vaqtidan tashqari ishlatildi</b>\n👤 " . lcup_h((string)$i['actor']) . " · 🕒 " . date('d.m H:i', strtotime($i['at'])) . " (ruxsat: {$from}–{$to})\n📝 " . lcup_h(lcup_kind_label($i['kind']) . ' — ' . ($i['student_name'] ?: '') . ' ' . mb_substr((string)$i['details'], 0, 120))
            . "\nAgar bu siz bo'lmasangiz — parolni almashtiring. Bu davr uchun boshqa xabar yuborilmaydi." . (lcup_app_url() ? "\n🔗 " . lcup_h(lcup_app_url() . '/lcup.php?leader=1') : ''));
    }
}
function lcup_h2(string $s): string { return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8'); }
function lcup_incident_html(array $i): string {
    $h = 'lcup_h2'; $fl = []; $labels = lcup_flag_labels();
    foreach (array_filter(explode(',', (string)$i['flags'])) as $f) if (!in_array($f, ['deleted'], true)) $fl[] = $labels[$f] ?? $f;
    $t = "🔴 <b>LC-UP: " . $h(lcup_kind_label($i['kind'])) . "</b>\n";
    $t .= "🏢 " . $h(lcup_branch_name($i['branch_id'] !== null ? (int)$i['branch_id'] : null)) . "\n";
    $t .= "👤 Kim: " . $h($i['actor'] ?: "Noma'lum (aniqlanmoqda)") . ($i['creator'] && lc_norm((string)$i['creator']) !== lc_norm((string)$i['actor']) ? " · Yaratgan: " . $h($i['creator']) : '') . "\n";
    if ($i['student_name'] || $i['student_id']) $t .= "🎓 " . $h($i['student_name'] ?: '—') . ($i['student_id'] ? ' (#' . $h($i['student_id']) . ')' : '') . "\n";
    if ($i['details']) $t .= "📝 " . $h($i['details']) . "\n";
    if ($i['at']) $t .= "🕒 " . date('d.m.Y H:i', strtotime($i['at'])) . "\n";
    $t .= "🔎 Manbalar: " . $h(implode(', ', array_map(function ($s) { return LCUP_SRC_LABEL[$s] ?? $s; }, array_filter(explode(',', (string)$i['sources']))))) . ' · ' . ($i['confidence'] === 'confirmed' ? '✅ Tasdiqlangan' : '⚠️ Taxminiy') . "\n";
    if ($fl) $t .= "⚠️ " . $h(implode(', ', $fl)) . "\n";
    if (lcup_app_url()) $t .= '🔗 ' . $h(lcup_app_url() . '/lcup.php?id=' . (int)$i['id']);
    return trim($t);
}
/** One message per red incident (never per source). Incidents still being looked up in Tarix wait up to 10 minutes. */
function lcup_incident_alerts_flush(int $max = 8): array {
    if (lcup_setting('alerts_on') !== '1' || !lcup_tg_ready()) return ['sent' => 0, 'failed' => 0, 'skipped' => true];
    $sent = 0; $fail = 0; $since = lcup_state_get('alerts_since', '2000-01-01 00:00:00'); $now = lcup_now();
    $st = db()->prepare("SELECT * FROM lcup_incidents WHERE severity = 'red' AND backfill = 0 AND alerted_at IS NULL AND first_detected >= ? AND (hold_until IS NULL OR hold_until <= ?) ORDER BY id LIMIT 60");
    $st->execute([$since, $now]); $rows = $st->fetchAll();
    $mark = db()->prepare("UPDATE lcup_incidents SET alerted_at = ?, alerted_sig = ? WHERE id = ? AND alerted_at IS NULL");
    $sigOf = function ($i) { return lcup_inc_sig(['kind' => $i['kind'], 'actor' => $i['actor'], 'branch_id' => $i['branch_id'], 'amount' => $i['amount'], 'old_amount' => $i['old_amount'], 'new_amount' => $i['new_amount'], 'severity' => $i['severity'], 'student_id' => $i['student_id']]); };
    foreach ($rows as $n => $i) {
        if ($n >= $max) {
            $rest = array_slice($rows, $n); $err = null;
            $ok = lcup_tg_send("🔴 <b>LC-UP: yana " . count($rest) . " ta shubhali hodisa</b>\nBatafsil: " . (lcup_app_url() ? lcup_app_url() . '/lcup.php?status=unread' : 'Argent → LC-UP nazorat'), $err);
            if ($ok) foreach ($rest as $r) $mark->execute([lcup_now(), $sigOf($r), $r['id']]); $ok ? $sent++ : $fail++; break;
        }
        $err = null;
        if (lcup_tg_send(lcup_incident_html($i), $err)) { $mark->execute([lcup_now(), $sigOf($i), $i['id']]); $sent++; usleep(250000); }
        else { $fail++; lcup_state_set('tg_last_error', $err); break; }
    }
    if ($sent && !$fail) lcup_state_del('tg_last_error');
    return ['sent' => $sent, 'failed' => $fail];
}

/* ============================================================== re-processing of everything stored (tools/lcup_reprocess.php, Admin → LC-UP button) */
/** Rebuilds all incidents from the append-only evidence with the current rules. Sends NO alerts; returns statistics. */
function lcup_reprocess_all(?callable $progress = null): array {
    @set_time_limit(0);
    $t0 = microtime(true); $before = (int)db()->query("SELECT COALESCE(MAX(id),0) FROM lcup_corrections")->fetchColumn();
    $oldInc = (int)db()->query("SELECT COUNT(*) FROM lcup_incidents")->fetchColumn();
    $acts = lcup_acts_derive_branches(true);
    // student id for "Balans tahrirlandi" rows (name only)
    foreach (db()->query("SELECT act_id, student_name FROM lcup_acts WHERE student_id IS NULL AND student_name IS NOT NULL")->fetchAll() as $a) { [$sid, $b] = lcup_student_by_name((string)$a['student_name']); if ($sid) db()->prepare("UPDATE lcup_acts SET student_id = ?, branch_id = COALESCE(branch_id, ?) WHERE act_id = ?")->execute([$sid, $b, $a['act_id']]); }
    db()->beginTransaction();
    try {
        db()->exec("DELETE FROM lcup_incident_events"); db()->exec("DELETE FROM lcup_incidents");
        $ids = db()->query("SELECT id FROM lcup_events ORDER BY id")->fetchAll(PDO::FETCH_COLUMN); $n = 0;
        foreach ($ids as $eid) { lcup_inc_attach((int)$eid, null, true); if ($progress && ++$n % 200 === 0) $progress($n, count($ids)); }
        $moved = lcup_link_moves('2000-01-01');
        // review marks + alerts carried over from the events: no Telegram for history
        foreach (db()->query("SELECT id FROM lcup_incidents")->fetchAll(PDO::FETCH_COLUMN) as $iid) {
            $ev = lcup_inc_events((int)$iid); $reds = array_values(array_filter($ev, function ($e) { return $e['severity'] === 'red' && !$e['backfill']; }));
            $rev = $reds && !array_filter($reds, function ($e) { return !$e['reviewed_at']; });
            $i = lcup_inc_get((int)$iid); $sig = lcup_inc_sig($i);
            $alerted = array_filter(array_column($ev, 'alerted_at')); $at = $alerted ? min($alerted) : lcup_now();
            db()->prepare("UPDATE lcup_incidents SET alerted_at = ?, alerted_sig = ?, hold_until = NULL" . ($rev ? ", reviewed_at = ?, reviewed_by = ?, review_note = ?" : '') . " WHERE id = ?")
                ->execute(array_merge([$at, $sig], $rev ? [max(array_column($reds, 'reviewed_at')), $reds[0]['reviewed_by'], implode(' / ', array_unique(array_filter(array_column($reds, 'review_note'))))] : [], [$iid]));
        }
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    $by = []; foreach (db()->query("SELECT field, COUNT(*) AS n FROM lcup_corrections WHERE id > $before GROUP BY field")->fetchAll() as $r) $by[$r['field']] = (int)$r['n'];
    $res = ['events' => count($ids), 'incidents' => (int)db()->query("SELECT COUNT(*) FROM lcup_incidents")->fetchColumn(), 'old_incidents' => $oldInc, 'moved' => $moved, 'acts_branch' => $acts,
            'corrections_new' => array_sum($by), 'by_field' => $by,
            'pending_minus' => (int)db()->query("SELECT COUNT(*) FROM lcup_acts WHERE type = 'charge_edited' AND state IN ('pending','unresolved')")->fetchColumn(), 'seconds' => round(microtime(true) - $t0, 1)];
    lcup_state_set('reprocess_last', json_encode(['at' => lcup_now()] + $res, JSON_UNESCAPED_UNICODE));
    if (!empty($res['corrections_new'])) lcup_state_set('reprocess_report_pending', '1');
    return $res;
}
