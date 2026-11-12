<?php
/**
 * LC-UP sync engine. One call = one cron run (about a minute apart). Everything here only READS LC-UP.
 *
 * Sources (all verified against saved real pages unless marked "unverified"):
 *   /admin/worker_reports   activity log of the selected staff member: "to'lov yaratildi" + ORIGINAL amount, student, group, time
 *   /admin/archive/tolovlars deleted payments with payment id, deleted time, staff and reason
 *   /admin/tolovlars        live payment list = CURRENT amount per payment id (unverified: page not seen yet)
 *   /admin/discount, /admin/qarzdorlar   discounts and debtors (unverified)
 *
 * Coverage:  every run   hot pages (newest billing dates) + a few warm pages (rotating through the last 40 days)
 *            nightly     slices of a full walk over ALL payment pages + the whole archive (default from 03:00)
 */
require_once __DIR__ . '/lcup_client.php';
require_once __DIR__ . '/lcup_report.php';

function lcup_lock() {
    $d = private_dir(); $dir = ($d && is_dir($d) && is_writable($d)) ? $d : data_dir_ready('lcup');
    if (!$dir) return null;
    $f = @fopen($dir . '/lcup.lock', 'c'); if (!$f) return null;
    if (!flock($f, LOCK_EX | LOCK_NB)) { fclose($f); return null; }
    return $f;
}
function lcup_note_ignored(string $text): void {
    $j = json_decode((string)lcup_state_get('ignored_texts', '{}'), true) ?: [];
    $j[$text] = ($j[$text] ?? 0) + 1; if (count($j) > 40) { arsort($j); $j = array_slice($j, 0, 40, true); }
    lcup_state_set('ignored_texts', json_encode($j, JSON_UNESCAPED_UNICODE));
}
function lcup_amount_text($a): string { return $a === null ? '—' : fmt($a) . " so'm"; }

/* ============================================================== run */
function lcup_run(array $o = []): array {
    $t0 = microtime(true); $trigger = $o['trigger'] ?? 'cron';
    @set_time_limit(max(60, (int)lcup_num('budget_s') + 40)); @ignore_user_abort(true);
    $lock = lcup_lock(); if (!$lock) return ['ok' => true, 'busy' => true, 'message' => 'Boshqa sinxronizatsiya ishlayapti'];
    $sum = ['ok' => true, 'errors' => [], 'warnings' => [], 'new_events' => 0, 'requests' => 0, 'branches' => []];
    $deadline = $t0 + max(10, lcup_num('budget_s'));
    db()->prepare("INSERT INTO lcup_runs (started_at, trigger) VALUES (?,?)")->execute([lcup_now(), $trigger]); $rid = (int)db()->lastInsertId();
    $before = (int)db()->query("SELECT COALESCE(MAX(id),0) FROM lcup_events")->fetchColumn();
    $c = null;
    // outage: the last good run is older than the threshold -> remember the gap and read the full 7-day log once to fill it
    $gap = null; $lastOk = lcup_state_get('last_success'); $open = db()->query("SELECT * FROM lcup_gaps WHERE filled = 0 ORDER BY id DESC LIMIT 1")->fetch();
    if ($open) $gap = ['id' => (int)$open['id'], 'from' => $open['from_at'], 'to' => $open['to_at']];
    elseif ($lastOk && time() - strtotime($lastOk) > (int)lcup_num('catchup_gap_min') * 60) { db()->prepare("INSERT INTO lcup_gaps (from_at, to_at) VALUES (?,?)")->execute([$lastOk, lcup_now()]); $gap = ['id' => (int)db()->lastInsertId(), 'from' => $lastOk, 'to' => lcup_now()]; }
    $sum['gap'] = $gap;
    try {
        $map = lcup_map_rows(); if (!$map) throw new LcupError("Filial moslash bo'sh (Admin → LC-UP).", 'config');
        $curKey = (string)lcup_state_get('current_branch_key', ''); usort($map, function ($a, $b) use ($curKey) { return ((string)$b['lcup_key'] === $curKey && $curKey !== '') <=> ((string)$a['lcup_key'] === $curKey && $curKey !== ''); });   // start with the branch that is already open
        if (!lcup_state_get('first_run')) { lcup_state_set('first_run', lcup_now()); lcup_state_set('alerts_since', lcup_now()); }
        $c = LcupClient::fromConfig();
        foreach ($map as $m) {
            try { lcup_sync_branch($c, $m, $sum, $deadline); $sum['branches'][$m['branch_name']] = 'ok'; $kk = (string)(db()->query("SELECT lcup_key FROM lcup_branch_map WHERE branch_id = " . (int)$m['branch_id'])->fetchColumn()); if ($kk !== '') lcup_state_set('current_branch_key', $kk); }
            catch (LcupError $e) { $sum['ok'] = false; $sum['errors'][] = $m['branch_name'] . ': ' . $e->getMessage(); $sum['branches'][$m['branch_name']] = $e->kind; if (in_array($e->kind, ['ratelimit', 'login', 'config', 'network'], true)) break; }
            catch (Throwable $e) { $sum['ok'] = false; $sum['errors'][] = $m['branch_name'] . ': ichki xatolik (' . get_class($e) . ') ' . lcup_redact($e->getMessage()); lcup_log('branch ' . $m['branch_name'] . ' ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); }
        }
        if ($sum['ok']) { try { lcup_eod_check($map, $sum); } catch (Throwable $e) { $sum['warnings'][] = 'Kun yakuni tekshiruvi: ' . lcup_redact($e->getMessage()); } }
    } catch (LcupError $e) { $sum['ok'] = false; $sum['errors'][] = $e->getMessage(); }
    catch (Throwable $e) { $sum['ok'] = false; $sum['errors'][] = 'Ichki xatolik (' . get_class($e) . '): ' . lcup_redact($e->getMessage()); lcup_log(get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); }
    $sum['requests'] = $c ? $c->requests : 0;
    $sum['new_events'] = (int)db()->query("SELECT COUNT(*) FROM lcup_events WHERE id > $before")->fetchColumn();
    if ($gap && $sum['ok'] && !empty($sum['branches'])) {
        db()->prepare("UPDATE lcup_gaps SET filled = 1, note = ? WHERE id = ?")->execute([$sum['new_events'] . " yangi hodisa topildi", $gap['id']]);
        lcup_tg_send("🔁 <b>LC-UP monitoring tiklandi</b>\nUzilish: " . date('d.m H:i', strtotime($gap['from'])) . '–' . date('d.m H:i', strtotime($gap['to'])) . "\nO'sha davrdagi amallar 7 kunlik jurnaldan to'ldirildi: " . $sum['new_events'] . " ta hodisa.");
    }
    try { lcup_alerts_flush(); } catch (Throwable $e) { lcup_log('alerts: ' . $e->getMessage()); }
    try { lcup_reports_due(); } catch (Throwable $e) { lcup_log('reports: ' . $e->getMessage()); }
    lcup_state_set('last_warnings', json_encode(array_slice($sum['warnings'], 0, 12), JSON_UNESCAPED_UNICODE));
    lcup_health_update($sum['ok'], implode(' | ', $sum['errors']));
    db()->prepare("UPDATE lcup_runs SET finished_at=?, ok=?, requests=?, new_events=?, seconds=?, message=? WHERE id=?")
        ->execute([lcup_now(), $sum['ok'] ? 1 : 0, $sum['requests'], $sum['new_events'], round(microtime(true) - $t0, 2), mb_substr(implode(' | ', array_merge($sum['errors'], $sum['warnings'])), 0, 500), $rid]);
    db()->exec("DELETE FROM lcup_runs WHERE id < (SELECT MAX(id) - 1500 FROM lcup_runs)");
    flock($lock, LOCK_UN); fclose($lock);
    return $sum;
}

/** Sources whose pages have not been seen yet fail "softly": shown in the UI, Telegram after 30 failed runs in a row. */
function lcup_source(string $name, int $bid, bool $soft, array &$sum, callable $f): void {
    $key = 'softfail_' . md5($name) . "_$bid";
    try { $f(); if ($soft) { if ((int)lcup_state_get($key, 0) >= 30 && lcup_state_get($key . '_a')) lcup_tg_send("✅ LC-UP: «$name» yana ishlayapti."); lcup_state_del($key); lcup_state_del($key . '_a'); } }
    catch (LcupError $e) {
        if (in_array($e->kind, ['login', 'network', 'ratelimit', 'config', 'branch'], true)) throw $e;
        $msg = "$name: " . $e->getMessage();
        if (!$soft) { $sum['ok'] = false; $sum['errors'][] = $msg; return; }
        $n = (int)lcup_state_get($key, 0) + 1; lcup_state_set($key, $n); $sum['warnings'][] = $msg;
        if ($n === 30 && !lcup_state_get($key . '_a')) { lcup_state_set($key . '_a', '1'); lcup_tg_send("⚠️ <b>LC-UP: «" . htmlspecialchars($name, ENT_NOQUOTES) . "» 30 marta ketma-ket o'qilmadi</b>\n" . htmlspecialchars(lcup_redact($e->getMessage()), ENT_NOQUOTES)); }
    }
}

function lcup_sync_branch(LcupClient $c, array $m, array &$sum, float $deadline): void {
    $bid = (int)$m['branch_id'];
    $html = $c->switch_branch((string)$m['lcup_key'], (string)$m['lcup_name'], lcup_setting('worker_url'));
    if ($c->resolved_key && $c->resolved_key !== (string)$m['lcup_key']) db()->prepare("UPDATE lcup_branch_map SET lcup_key = ? WHERE branch_id = ?")->execute([$c->resolved_key, $bid]);
    lcup_source('Xodimlar faoliyati', $bid, false, $sum, function () use ($c, $bid, $html, &$sum) { lcup_sync_activity($c, $bid, $html, $sum); });
    lcup_source("O'chirilgan to'lovlar", $bid, false, $sum, function () use ($c, $bid, &$sum, $deadline) { lcup_sync_archive($c, $bid, $sum, $deadline, 2); });
    lcup_source("To'lovlar ro'yxati", $bid, true, $sum, function () use ($c, $bid, &$sum, $deadline) { lcup_sync_list($c, $bid, $sum, $deadline); });
    lcup_pair_pending($bid, $sum); lcup_check_amounts($bid, $sum);
    lcup_source("To'lovlar ro'yxati (yo'qolganlar)", $bid, true, $sum, function () use ($c, $bid, &$sum, $deadline) { lcup_resolve_missing($c, $bid, $sum, $deadline); });
    lcup_source('Qarzdorlar', $bid, true, $sum, function () use ($c, $bid, &$sum, $deadline) { lcup_check_debts($c, $bid, $sum, $deadline); });
    lcup_source('Chegirmalar', $bid, true, $sum, function () use ($c, $bid, &$sum, $deadline) { lcup_check_discounts($c, $bid, $sum, $deadline); });
}

/* ============================================================== activity log (every staff member) */
/** Stores new activity rows of one staff member and turns them into events. Returns [new rows, rows that were already known]. */
function lcup_acts_ingest(int $bid, array $rows, ?string $staff, bool $base, ?array $gap): array {
    $ins = db()->prepare("INSERT OR IGNORE INTO lcup_acts (branch_id, act_id, staff, act_time, act_text, type, student_id, student_name, group_id, group_name, amount, first_seen, backfill) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $new = 0; $known = 0;
    foreach (array_reverse($rows) as $r) {                                   // oldest first
        [$type, $rel] = lcup_classify_event($r['text']);
        if (!$rel) { lcup_note_ignored($r['text']); continue; }
        $ins->execute([$bid, $r['act_id'], $staff, $r['time'], $r['text'], $type, $r['student_id'], $r['student_name'], $r['group_id'], $r['group_name'], $r['amount'], lcup_now(), $base ? 1 : 0]);
        if (!$ins->rowCount()) { $known++; continue; }
        $new++;
        $charge = (bool)preg_match('/minus/iu', $r['text']);
        $inGap = $gap && $r['time'] && $r['time'] >= $gap['from'] && $r['time'] <= $gap['to'];
        $ev = ['type' => $type, 'branch_id' => $bid, 'source' => 'worker_report', 'staff' => $staff, 'student_id' => $r['student_id'], 'student_name' => $r['student_name'], 'group_name' => $r['group_name'],
               'amount' => $r['amount'], 'lcup_time' => $r['time'], 'raw' => $r['raw'], 'backfill' => $base, 'is_charge' => $charge, 'flags' => $inGap ? ['gap'] : [], 'dedupe_key' => "act|$bid|{$r['act_id']}"];
        if ($type === 'payment_created') $ev['details'] = "To'lov yaratildi: " . lcup_amount_text($r['amount']) . ($r['group_name'] ? " · " . $r['group_name'] : '');
        elseif ($type === 'log_edit') $ev['details'] = $charge ? "Minus to'lov (hisob/qarz) tahrirlandi — LC-UP jurnali talaba va summani ko'rsatmaydi" : "To'lov tahrirlandi — LC-UP jurnali tafsilotni ko'rsatmaydi";
        else $ev['details'] = $r['text'] . ($r['amount'] !== null ? ' (' . lcup_amount_text($r['amount']) . ')' : '');
        if ($inGap) $ev['details'] .= ' · uzilish davrida aniqlandi';
        lcup_event_add($ev);
    }
    return [$new, $known];
}
/** Reads "yana ko'rsatish" until the batch contains rows we already know (new events are always on top), or everything when $all. */
function lcup_acts_more(LcupClient $c, array &$page, array $parsed, array $frags, bool $all, int $maxLoads = 8): array {
    $rows = $parsed['rows']; $p = $parsed; $comp = $parsed['more_comp'] ?? null;
    for ($i = 0; $i < $maxLoads; $i++) {
        $comp = $p['more_comp'] ?? $comp;                                      // a returned fragment may lack the wire:id of its root: keep the component we already used
        if (!$comp || empty($p['more_left'])) break;
        if (!$all) { $any = false; foreach ($rows as $r) { $q = db()->prepare("SELECT 1 FROM lcup_acts WHERE act_id = ? LIMIT 1"); $q->execute([$r['act_id']]); if ($q->fetchColumn()) { $any = true; break; } } if ($any) break; }
        $fr = $c->view_run($page, $comp, [LcupClient::call('loadMore')]);
        $p2 = lcup_parse_worker_reports(implode("\n", $fr), date('Y-m-d')); if (count($p2['rows']) <= count($rows)) break;
        $rows = $p2['rows']; $p = $p2;
    }
    return $rows;
}
function lcup_sync_activity(LcupClient $c, int $bid, string $html, array &$sum): void {
    $p0 = lcup_parse_worker_reports($html, date('Y-m-d'));
    if (!$p0['structure']) throw new LcupError("Sahifa tuzilishi tanilmadi (.xf-row topilmadi). «Diagnostika» ni bosing.", 'layout');
    $base = !lcup_state_get("base_act_$bid"); $gap = $sum['gap'] ?? null; $all = $base || $gap;
    $monitor = lc_norm((string)lcup_setting('monitor_name')); $isMon = function (?string $n) use ($monitor) { return $monitor !== '' && lc_norm((string)$n) === $monitor; };
    $viewOk = lcup_setting('allow_switch_post') === '1';
    $page = ['url' => $c->url(lcup_setting('worker_url')), 'html' => $html, 'comps' => []]; foreach (lcup_lw_components($html) as $x) $page['comps'][$x['id']] = $x;
    $c->adopt_csrf($html);
    $shown = []; foreach ($p0['rows'] as $r) $shown[$r['date']] = ($shown[$r['date']] ?? 0) + 1;
    $readStaff = [];                                                          // staff whose details we could read this run
    // 1) the staff member LC-UP opens by default
    $rows0 = $p0['rows']; $curPage = $p0;
    if ($viewOk && $p0['more_comp']) {
        if ($gap && $p0['log_comp']) { try { $c->view_run($page, $p0['log_comp'], [LcupClient::call('setPreset', ['7'])]); } catch (LcupError $e) { if ($e->kind === 'config') throw $e; } }
        $rows0 = lcup_acts_more($c, $page, $p0, [], $all);
    }
    if (!$isMon($p0['staff'])) { lcup_acts_ingest($bid, $rows0, $p0['staff'], $base, $gap); $readStaff[lc_norm((string)$p0['staff'])] = array_column($rows0, 'act_id'); }
    // 2) every other staff member, through the whitelisted Livewire "view" calls
    foreach ($p0['staff_list'] as $s) {
        if (!empty($s['selected']) || $s['id'] === null) continue;
        $k = "staffcnt_{$bid}_{$s['id']}"; $prev = lcup_state_get($k);
        if ($isMon($s['name'])) { lcup_state_set($k, (int)$s['count']); continue; }
        if (!$viewOk || !$p0['staff_comp']) {                                  // Livewire read calls switched off: only the counters are visible
            if ($prev !== null && !$base && (int)$s['count'] > (int)$prev)
                lcup_event_add(['type' => 'staff_activity', 'branch_id' => $bid, 'source' => 'worker_report', 'staff' => $s['name'], 'info_only' => true, 'details' => "«{$s['name']}» amallari soni " . (int)$prev . " → " . (int)$s['count'] . " (tafsilot ko'rinmaydi: Livewire o'qish amallari o'chiq)", 'dedupe_key' => "staffcnt|$bid|{$s['id']}|{$s['count']}|" . date('YmdH')]);
            lcup_state_set($k, (int)$s['count']); continue;
        }
        if ($prev !== null && (int)$s['count'] === (int)$prev && !$all && !$base) continue;   // nothing new for this person (saves requests)
        $fr = $c->view_run($page, $p0['staff_comp'], [LcupClient::call('openHistory', [(string)$s['id']])]);
        $p = lcup_parse_worker_reports(implode("\n", $fr), date('Y-m-d'));
        if (!$p['rows'] && $p0['log_comp']) { $fr2 = $c->view_run($page, $p0['log_comp'], [LcupClient::call('$refresh')]); $p = lcup_parse_worker_reports(implode("\n", array_merge($fr, $fr2)), date('Y-m-d')); }
        $lc = $p['log_comp'] ?: $p0['log_comp'];
        if ($gap && $lc) { try { $c->view_run($page, $lc, [LcupClient::call('setPreset', ['7'])]); } catch (LcupError $e) { if ($e->kind === 'config') throw $e; } }
        // never attribute rows to the wrong person: the header must match, and the rows must not be the previous person's rows again
        if ($p['staff'] !== null && lc_norm($p['staff']) !== lc_norm($s['name'])) { $sum['warnings'][] = "Xodim almashmadi: «{$s['name']}» kutilgan, LC-UP «{$p['staff']}» ni ko'rsatdi."; continue; }
        $ids = array_column($p['rows'], 'act_id');
        foreach ($readStaff as $other => $oids) if ($ids && $oids && !array_diff($ids, $oids)) { $sum['warnings'][] = "«{$s['name']}» uchun LC-UP oldingi xodim yozuvlarini qaytardi — o'tkazib yuborildi."; continue 2; }
        $rows = $p['rows']; if ($p['more_comp']) $rows = lcup_acts_more($c, $page, $p, $fr, $all);
        lcup_acts_ingest($bid, $rows, $s['name'], $base, $gap); $readStaff[lc_norm($s['name'])] = array_column($rows, 'act_id');
        lcup_state_set($k, (int)$s['count']);
    }
    foreach ($p0['day_totals'] as $d => $tot) if ($tot > ($shown[$d] ?? 0) && !$viewOk) $sum['warnings'][] = (branch_get($bid)['name'] ?? $bid) . ": jurnal $d kuni $tot ta amaldan faqat " . ($shown[$d] ?? 0) . " tasini ko'rsatdi.";
    lcup_state_set("base_act_$bid", '1');
}

/* ============================================================== paged fetching (GET ?page=N, verified by the paginator) */
function lcup_fetch_page(LcupClient $c, int $bid, string $url, int $page, string $kind, array &$sum): ?array {
    $r = $page <= 1 ? $c->page($url) : $c->page($url, ['page' => $page]);
    $a = $kind === 'archive' ? lcup_parse_archive($r['body']) : lcup_parse_payments($r['body']);
    if (!$a['structure']) {
        if ($page > 1) return null;                                  // beyond the last page
        $hdr = $a['headers'] ? ' Topilgan sarlavhalar: ' . implode(', ', $a['headers']) : '';
        throw new LcupError(($kind === 'archive' ? "Arxiv" : "To'lovlar") . " jadvali tanilmadi (kutilgan sarlavhalar: SANA, FISH, NARX, TO'LOV USULI, GURUH, HODIM" . ($kind === 'archive' ? ", O'CHIRILGAN SANA" : '') . ").$hdr «Diagnostika» ni bosing.", 'layout');
    }
    if ($page > 1 && ($a['page']['page'] ?? 1) !== $page) { $w = (branch_get($bid)['name'] ?? $bid) . ": sahifalash GET (?page=$page) orqali ishlamadi ($kind) — faqat birinchi sahifalar o'qiladi."; if (!in_array($w, $sum['warnings'], true)) $sum['warnings'][] = $w; lcup_state_set("pageget_bad_$bid", '1'); return null; }
    return $a;
}

/* ============================================================== archive (deleted payments) */
function lcup_sync_archive(LcupClient $c, int $bid, array &$sum, float $deadline, int $maxPages): void {
    $url = lcup_setting('archive_url'); $base = !lcup_state_get("base_arch_$bid");
    for ($pg = 1; $pg <= $maxPages && microtime(true) < $deadline; $pg++) {
        $a = lcup_fetch_page($c, $bid, $url, $pg, 'archive', $sum); if (!$a) break;
        $new = lcup_archive_rows($bid, $a['rows'], $base);
        if (!$a['page']['has_next'] || $new < count($a['rows']) || $base) break;       // reached rows we already know
    }
    lcup_state_set("base_arch_$bid", '1');
}
/** Archive rows have no usable payment id on the real site: identify them by student + amount + month + group + staff + deleted time. */
function lcup_archive_key(array $r): string {
    return !empty($r['pay_id']) ? (string)$r['pay_id'] : 'a:' . substr(sha1(implode('|', [lc_norm((string)$r['student_name']), (int)round((float)$r['amount']), $r['date'], $r['method'], lc_norm((string)$r['group']), lc_norm((string)$r['staff']), $r['deleted_at']])), 0, 20);
}
/** The snapshot payment an archive row belongs to: by id when both sides have one, otherwise by the attribute tuple (a payment that went missing is preferred). */
function lcup_match_payment(int $bid, array $r, ?string $key): ?array {
    if (!empty($r['pay_id'])) { $q = db()->prepare("SELECT * FROM lcup_payments WHERE branch_id = ? AND pay_id = ?"); $q->execute([$bid, $r['pay_id']]); if ($row = $q->fetch()) return $row; }
    $q = db()->prepare("SELECT * FROM lcup_payments WHERE branch_id = ? AND deleted_at IS NULL AND student_name = ? COLLATE NOCASE AND ABS(amount - ?) < 0.5 AND pay_date IS ?");
    $q->execute([$bid, $r['student_name'], $r['amount'], $r['date']]); $best = null; $bs = -1;
    foreach ($q->fetchAll() as $p) {
        if (lc_norm((string)$p['group_name']) !== lc_norm((string)$r['group'])) continue;
        if ($p['method'] && $r['method'] && $p['method'] !== $r['method']) continue;
        $score = ($p['missing_since'] ? 4 : 0) + (lc_norm((string)$p['staff']) === lc_norm((string)$r['staff']) ? 2 : 0) + ($p['miss_n'] > 0 ? 1 : 0);
        if ($score > $bs) { $bs = $score; $best = $p; }
    }
    return $best;
}
/** Stores archive rows (idempotent); a row seen for the first time is a deletion event. Returns the number of NEW rows. */
function lcup_archive_rows(int $bid, array $rows, bool $baseline): int {
    $ins = db()->prepare("INSERT OR IGNORE INTO lcup_archive (branch_id, pay_id, student_name, amount, pay_date, method, teacher, group_name, staff, deleted_at, reason, first_seen, baseline) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $new = 0;
    foreach ($rows as $r) {
        $key = lcup_archive_key($r); $old = !empty($r['deleted_at']) && strtotime($r['deleted_at']) < time() - 2 * 86400;
        $ins->execute([$bid, $key, $r['student_name'], $r['amount'], $r['date'], $r['method'], $r['teacher'], $r['group'], $r['staff'], $r['deleted_at'], $r['reason'], lcup_now(), ($baseline || $old) ? 1 : 0]);
        if (!$ins->rowCount()) continue;
        $new++;
        $pay = lcup_match_payment($bid, $r, $key);
        if ($pay && !$pay['deleted_at']) db()->prepare("UPDATE lcup_payments SET deleted_at = ?, delete_info = ?, miss_n = 0 WHERE id = ?")->execute([$r['deleted_at'] ?: lcup_now(), $r['reason'] ?: 'arxiv', $pay['id']]);
        // the activity-log record of this payment (paired earlier, or found now by student + group + amount)
        $act = db()->prepare("SELECT * FROM lcup_acts WHERE branch_id = ? AND type = 'payment_created' AND (pay_id = ? OR (pay_id IS NULL AND state = 'open' AND ABS(amount - ?) < 0.5)) ORDER BY pay_id IS NULL, act_time DESC LIMIT 20");
        $act->execute([$bid, $pay['pay_id'] ?? $key, $r['amount']]); $actRow = null;
        foreach ($act->fetchAll() as $a) { if (($pay && $a['pay_id'] === $pay['pay_id']) || (lc_norm((string)$a['student_name']) === lc_norm((string)$r['student_name']) && lc_norm((string)$a['group_name']) === lc_norm((string)$r['group']))) { $actRow = $a; break; } }
        if ($actRow) db()->prepare("UPDATE lcup_acts SET state = 'deleted', pay_id = COALESCE(pay_id, ?) WHERE branch_id = ? AND act_id = ?")->execute([$pay['pay_id'] ?? $key, $bid, $actRow['act_id']]);
        $created = $actRow['act_time'] ?? ($pay['created_at'] ?? null);
        lcup_event_add(['type' => 'payment_deleted', 'branch_id' => $bid, 'source' => 'archive', 'staff' => $r['staff'] ?: null, 'student_id' => $actRow['student_id'] ?? ($pay['student_id'] ?? null),
            'student_name' => $r['student_name'], 'group_name' => $r['group'], 'amount' => $r['amount'], 'method' => $r['method'], 'lcup_time' => $r['deleted_at'], 'created_at' => $created, 'reason' => $r['reason'],
            'backfill' => $baseline || $old, 'details' => "O'chirilgan to'lov (" . ($r['date'] ?: '?') . " oyi uchun)" . ($created ? ', yaratilgan ' . date('d.m H:i', strtotime($created)) : '') . '. Sabab: ' . ($r['reason'] !== '' ? $r['reason'] : "ko'rsatilmagan") . ($pay ? '' : " · snapshotda mos to'lov topilmadi"),
            'raw' => $r['raw'], 'dedupe_key' => "arch|$bid|$key"]);
    }
    return $new;
}

/* ============================================================== live payment list */
function lcup_sync_list(LcupClient $c, int $bid, array &$sum, float $deadline): void {
    $url = lcup_setting('payments_url'); if ($url === '') return;
    $base = !lcup_state_get("base_list_$bid"); $hot = max(1, (int)lcup_num('hot_pages')); $seen = []; $minDate = null; $complete = true;
    $ord = []; $maxBefore = (float)lcup_state_get("maxpid_$bid", 0); $hotNext = false;
    for ($pg = 1; $pg <= $hot && microtime(true) < $deadline; $pg++) {
        $a = lcup_fetch_page($c, $bid, $url, $pg, 'payments', $sum); if (!$a) { $complete = false; break; }
        foreach ($a['rows'] as $r) { $id = lcup_pay_upsert($bid, $r, 'hot', $base, $ord, $maxBefore); $seen[$id] = 1; if ($r['date'] && ($minDate === null || $r['date'] < $minDate)) $minDate = $r['date']; }
        $hotNext = (bool)$a['page']['has_next']; if (!$hotNext) break;
    }
    if ($complete && $minDate !== null) lcup_mark_missing($bid, $seen, $minDate);
    // warm: rotate through the rest of the last N days, a few pages per run
    $cutoff = date('Y-m-d', strtotime('-' . ((int)lcup_num('window_days') + 31) . ' days'));
    $cur = max($hot + 1, (int)lcup_state_get("warm_cur_$bid", $hot + 1));
    for ($i = 0; $hotNext && $i < (int)lcup_num('warm_pages') && microtime(true) < $deadline - 3; $i++) {
        $a = lcup_fetch_page($c, $bid, $url, $cur, 'payments', $sum);
        if (!$a || !$a['rows']) { $cur = $hot + 1; break; }
        $mx = null; foreach ($a['rows'] as $r) { lcup_pay_upsert($bid, $r, 'warm', true, $ord); if ($r['date'] && ($mx === null || $r['date'] > $mx)) $mx = $r['date']; }
        if (!$a['page']['has_next'] || ($mx !== null && $mx < $cutoff)) { $cur = $hot + 1; break; }
        $cur++;
    }
    lcup_state_set("warm_cur_$bid", $cur);
    lcup_nightly_slice($c, $bid, $sum, $deadline);
    $mx = db()->prepare("SELECT MAX(CAST(pay_id AS INTEGER)) FROM lcup_payments WHERE branch_id = ? AND pay_id GLOB '[0-9]*'"); $mx->execute([$bid]);
    lcup_state_set("maxpid_$bid", max($maxBefore, (float)$mx->fetchColumn()));
    lcup_state_set("base_list_$bid", '1');
}

/** Insert / compare one list row. Returns the payment id used as key. $region: hot | warm | night. */
function lcup_pay_upsert(int $bid, array $r, string $region, bool $baseline, array &$ord, float $newAbove = INF): string {
    $weak = empty($r['pay_id']);
    if ($weak) { $b = lc_norm((string)$r['student_name']) . '|' . lc_norm((string)$r['group']) . '|' . $r['date'] . '|' . $r['method']; $ord[$b] = ($ord[$b] ?? 0) + 1; $id = 'h:' . substr(sha1($b . '|' . $ord[$b]), 0, 16); }
    else $id = (string)$r['pay_id'];
    $now = lcup_now();
    $st = db()->prepare("SELECT * FROM lcup_payments WHERE branch_id = ? AND pay_id = ?"); $st->execute([$bid, $id]); $old = $st->fetch();
    if (!$old) {
        // LC-UP payment ids only grow: a row is a NEW payment only if its id is above everything seen before (not merely because it moved onto a hot page)
        $isNew = !$baseline && $region === 'hot' && !$weak && ctype_digit($id) && (float)$id > $newAbove;
        db()->prepare("INSERT INTO lcup_payments (branch_id, pay_id, student_id, student_name, group_name, teacher, amount, pay_date, method, status, staff, created_at, first_seen, last_seen, baseline) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$bid, $id, $r['student_id'], $r['student_name'], $r['group'], $r['teacher'], $r['amount'], $r['date'], $r['method'], $r['status'], $r['staff'], $r['created_at'], $now, $now, $isNew ? 0 : 1]);
        $pid = (int)db()->lastInsertId();
        if ($isNew) {
            $paired = lcup_pair_one($bid, $pid);
            if (!$paired) lcup_event_add(['type' => 'payment_created', 'branch_id' => $bid, 'source' => 'snapshot', 'staff' => $r['staff'] ?: null, 'student_id' => $r['student_id'], 'student_name' => $r['student_name'], 'group_name' => $r['group'],
                'amount' => $r['amount'], 'method' => $r['method'], 'lcup_time' => $r['created_at'], 'raw' => $r['raw'], 'details' => "To'lov ro'yxatda paydo bo'ldi (jurnalda topilmadi): " . lcup_amount_text($r['amount']) . ($r['group'] ? ' · ' . $r['group'] : ''),
                'dedupe_key' => "snapc|$bid|$id"]);
        } else lcup_pair_one($bid, $pid);                            // an old row found late: still link it to its log record (original-amount check)
        return $id;
    }
    $before = ['amount' => (float)$old['amount'], 'pay_date' => $old['pay_date'], 'method' => $old['method'], 'group_name' => $old['group_name'], 'status' => $old['status']];
    $after = ['amount' => (float)$r['amount'], 'pay_date' => $r['date'], 'method' => $r['method'], 'group_name' => $r['group'], 'status' => $r['status']];
    $chg = [];
    foreach ($after as $k => $v) { if ($k === 'amount' ? abs((float)$before[$k] - (float)$v) >= 0.5 : (string)$before[$k] !== (string)$v) $chg[$k] = $v; }
    $upd = db()->prepare("UPDATE lcup_payments SET last_seen = ?, miss_n = 0, missing_since = NULL, staff = COALESCE(NULLIF(?, ''), staff), teacher = ?, created_at = COALESCE(created_at, ?) WHERE id = ?");
    $upd->execute([$now, $r['staff'], $r['teacher'], $r['created_at'], $old['id']]);
    if (!$chg || $old['deleted_at']) return $id;
    $b = array_intersect_key($before, $chg); $a = array_intersect_key($after, $chg);
    db()->prepare("UPDATE lcup_payments SET amount = ?, pay_date = ?, method = ?, group_name = ?, status = ?, version = version + 1 WHERE id = ?")->execute([$r['amount'], $r['date'], $r['method'], $r['group'], $r['status'], $old['id']]);
    $actT = null; if ($old['act_id']) { $q = db()->prepare("SELECT act_time FROM lcup_acts WHERE branch_id = ? AND act_id = ?"); $q->execute([$bid, $old['act_id']]); $actT = $q->fetchColumn() ?: null; }
    if ($old['act_id'] && array_keys($chg) === ['amount']) return $id;       // lcup_check_amounts() reports it as "original -> current" (one alert, not two)
    $oldRow = ($old['baseline'] && !$old['created_at'] && !$actT);              // existed before we started watching: by definition not a fresh edit
    lcup_event_add(['type' => 'payment_changed', 'branch_id' => $bid, 'source' => 'snapshot', 'staff' => $r['staff'] ?: $old['staff'], 'student_id' => $old['student_id'], 'student_name' => $r['student_name'], 'group_name' => $r['group'],
        'amount' => $r['amount'], 'method' => $r['method'], 'before' => $b, 'after' => $a, 'created_at' => $actT ?: ($old['created_at'] ?: ($old['baseline'] ? null : $old['first_seen'])), 'is_charge' => (float)$old['amount'] < 0,
        'details' => lcup_change_text($b, $a) . ($region === 'night' ? ' (tungi tekshiruv)' : ''), 'raw' => $r['raw'], 'flags' => array_merge($weak ? ['weak'] : [], $oldRow ? ['late_edit'] : []), 'dedupe_key' => "snapu|$bid|$id|v" . ((int)$old['version'] + 1)]);
    return $id;
}
function lcup_mark_missing(int $bid, array $seen, string $minDate): void {
    $st = db()->prepare("SELECT pay_id FROM lcup_payments WHERE branch_id = ? AND deleted_at IS NULL AND pay_date > ?"); $st->execute([$bid, $minDate]);
    $u = db()->prepare("UPDATE lcup_payments SET miss_n = miss_n + 1, missing_since = COALESCE(missing_since, ?) WHERE branch_id = ? AND pay_id = ?");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) if (!isset($seen[$pid])) $u->execute([lcup_now(), $bid, $pid]);
}
/** Payments that stayed away from several scans: look for them in the archive; otherwise report them as vanished without a reason. */
function lcup_resolve_missing(LcupClient $c, int $bid, array &$sum, float $deadline): void {
    $q = db()->prepare("SELECT * FROM lcup_payments WHERE branch_id = ? AND deleted_at IS NULL AND miss_n >= 3");
    $q->execute([$bid]); $rows = $q->fetchAll(); if (!$rows) return;
    lcup_sync_archive($c, $bid, $sum, $deadline, 6);                 // goes deeper than the usual 2 pages
    $q->execute([$bid]);
    foreach ($q->fetchAll() as $p) {
        db()->prepare("UPDATE lcup_payments SET deleted_at = ?, delete_info = 'arxivda topilmadi' WHERE id = ?")->execute([lcup_now(), $p['id']]);
        db()->prepare("UPDATE lcup_acts SET state = 'deleted' WHERE branch_id = ? AND pay_id = ?")->execute([$bid, $p['pay_id']]);
        lcup_event_add(['type' => 'payment_deleted', 'branch_id' => $bid, 'source' => 'snapshot', 'staff' => $p['staff'] ?: null, 'student_id' => $p['student_id'], 'student_name' => $p['student_name'], 'group_name' => $p['group_name'],
            'amount' => $p['amount'], 'method' => $p['method'], 'created_at' => $p['created_at'] ?: $p['first_seen'], 'reason' => '', 'flags' => ['weak'],
            'details' => "To'lov ro'yxatdan yo'qoldi va o'chirilganlar arxivida topilmadi (" . ($p['pay_date'] ?: '?') . " oyi uchun)", 'dedupe_key' => "miss|$bid|{$p['pay_id']}"]);
    }
}

/* ============================================================== pairing log "yaratildi" <-> list row, and the ORIGINAL-amount check */
function lcup_pair_apply(int $bid, array $act, array $pay): void {
    db()->prepare("UPDATE lcup_acts SET pay_id = ?, state = 'paired', paired_at = ?, cur_amount = ? WHERE branch_id = ? AND act_id = ?")->execute([$pay['pay_id'], lcup_now(), $pay['amount'], $bid, $act['act_id']]);
    db()->prepare("UPDATE lcup_payments SET act_id = ?, student_id = COALESCE(student_id, ?) WHERE id = ?")->execute([$act['act_id'], $act['student_id'], $pay['id']]);
}
/** Pair one list row with an unpaired log record of the same student + group (exact amount first). Times use OUR clock (first_seen) so LC-UP's clock/timezone never matters. */
function lcup_pair_one(int $bid, int $pid): bool {
    $st = db()->prepare("SELECT * FROM lcup_payments WHERE id = ?"); $st->execute([$pid]); $p = $st->fetch(); if (!$p || $p['act_id']) return false;
    $a = db()->prepare("SELECT * FROM lcup_acts WHERE branch_id = ? AND type = 'payment_created' AND pay_id IS NULL AND state = 'open' AND amount IS NOT NULL AND student_name = ? COLLATE NOCASE");
    $a->execute([$bid, $p['student_name']]); $best = null; $bs = null;
    foreach ($a->fetchAll() as $act) {
        if (lc_norm((string)$act['group_name']) !== lc_norm((string)$p['group_name'])) continue;
        $dt = abs(strtotime($p['first_seen']) - strtotime($act['first_seen']));
        if (!$p['baseline'] && $dt > 6 * 3600) continue;                         // a fresh row can only belong to a log record we saw around the same time
        $exact = abs((float)$act['amount'] - (float)$p['amount']) < 0.5; $score = ($exact ? 0 : 1000000000) + $dt;
        if ($bs === null || $score < $bs) { $bs = $score; $best = $act; }
    }
    if (!$best) return false;
    lcup_pair_apply($bid, $best, $p); return true;
}
/** Pair log records that did not find their row yet (rows found later by warm/night scans, or edited before the first look). */
function lcup_pair_pending(int $bid, array &$sum): void {
    $a = db()->prepare("SELECT * FROM lcup_acts WHERE branch_id = ? AND type = 'payment_created' AND pay_id IS NULL AND state = 'open' AND amount IS NOT NULL AND amount > 0 AND act_time >= ?");
    $a->execute([$bid, date('Y-m-d H:i:s', strtotime('-' . ((int)lcup_num('window_days') + 5) . ' days'))]);
    foreach ($a->fetchAll() as $act) {
        $c = db()->prepare("SELECT * FROM lcup_payments WHERE branch_id = ? AND act_id IS NULL AND deleted_at IS NULL AND student_name = ? COLLATE NOCASE");
        $c->execute([$bid, $act['student_name']]); $best = null; $bs = null;
        foreach ($c->fetchAll() as $p) {
            if (lc_norm((string)$p['group_name']) !== lc_norm((string)$act['group_name'])) continue;
            $dt = $p['baseline'] ? 0 : abs(strtotime($p['first_seen']) - strtotime($act['first_seen']));
            if (!$p['baseline'] && $dt > 6 * 3600) continue;
            $exact = abs((float)$act['amount'] - (float)$p['amount']) < 0.5; $score = ($exact ? 0 : 1000000000) + $dt;
            if ($bs === null || $score < $bs) { $bs = $score; $best = $p; }
        }
        if ($best) lcup_pair_apply($bid, $act, $best);
    }
}
/** REQUIREMENT: the amount LC-UP logged when the payment was created must still be the amount in the list. */
function lcup_check_amounts(int $bid, array &$sum): void {
    db()->prepare("UPDATE lcup_acts SET cur_amount = (SELECT p.amount FROM lcup_payments p WHERE p.branch_id = lcup_acts.branch_id AND p.pay_id = lcup_acts.pay_id) WHERE branch_id = ? AND state = 'paired'")->execute([$bid]);
    $q = db()->prepare("SELECT a.*, p.amount AS pamount, p.method AS pmethod FROM lcup_acts a JOIN lcup_payments p ON p.branch_id = a.branch_id AND p.pay_id = a.pay_id
        WHERE a.branch_id = ? AND a.state = 'paired' AND p.deleted_at IS NULL AND ABS(p.amount - a.amount) >= 0.5 AND (a.alert_amount IS NULL OR ABS(a.alert_amount - p.amount) >= 0.5)");
    $q->execute([$bid]);
    foreach ($q->fetchAll() as $a) {
        $diff = (float)$a['pamount'] - (float)$a['amount'];
        lcup_event_add(['type' => 'amount_changed', 'branch_id' => $bid, 'source' => 'reconcile', 'staff' => $a['staff'], 'student_id' => $a['student_id'], 'student_name' => $a['student_name'], 'group_name' => $a['group_name'],
            'amount' => $a['pamount'], 'method' => $a['pmethod'], 'created_at' => $a['act_time'], 'before' => ['amount' => (float)$a['amount']], 'after' => ['amount' => (float)$a['pamount']],
            'details' => "Yaratilganda: " . lcup_amount_text($a['amount']) . " → hozir: " . lcup_amount_text($a['pamount']) . " (farq " . fmt_signed($diff) . ") · yaratilgan " . date('d.m H:i', strtotime($a['act_time'])),
            'dedupe_key' => "amt|$bid|{$a['act_id']}|" . (int)round($a['pamount'])]);
        db()->prepare("UPDATE lcup_acts SET alert_amount = ? WHERE branch_id = ? AND act_id = ?")->execute([$a['pamount'], $bid, $a['act_id']]);
    }
}

/* ============================================================== nightly full walk (default from 03:00) */
function lcup_nightly_slice(LcupClient $c, int $bid, array &$sum, float $deadline): void {
    $url = lcup_setting('payments_url'); $today = date('Y-m-d'); $key = "night_$bid";
    $st = json_decode((string)lcup_state_get($key, ''), true) ?: [];
    if (($st['date'] ?? '') === $today && !empty($st['done'])) return;
    if (($st['date'] ?? '') !== $today) { if (date('H:i') < (string)lcup_setting('night_time')) return; $st = ['date' => $today, 'phase' => 'list', 'page' => 1, 'arch' => 1, 'sl' => 1, 'started' => lcup_now(), 'pages' => 0, 'students' => 0, 'sfail' => 0, 'done' => 0]; }
    $per = max(1, (int)lcup_num('night_pages_per_run')); $maxP = (int)lcup_num('night_max_pages'); $ord = [];
    for ($i = 0; $i < $per && microtime(true) < $deadline - 2 && in_array($st['phase'], ['list', 'arch', 'slist'], true); $i++) {
        if ($st['phase'] === 'list') {
            $a = lcup_fetch_page($c, $bid, $url, (int)$st['page'], 'payments', $sum);
            if ($a) foreach ($a['rows'] as $r) lcup_pay_upsert($bid, $r, 'night', true, $ord);
            $st['pages']++; $st['page']++;
            if (!$a || !$a['rows'] || !$a['page']['has_next'] || $st['page'] > $maxP) $st['phase'] = 'arch';
        } elseif ($st['phase'] === 'arch') {
            $a = lcup_fetch_page($c, $bid, lcup_setting('archive_url'), (int)$st['arch'], 'archive', $sum);
            if ($a) lcup_archive_rows($bid, $a['rows'], false);
            $st['arch']++;
            if (!$a || !$a['rows'] || !$a['page']['has_next'] || $st['arch'] > $maxP) $st['phase'] = lcup_setting('allow_switch_post') === '1' ? 'slist' : 'finish';
        } else {                                                     // slist: collect the student ids of this branch
            $sp = (int)$st['sl']; $r = $sp <= 1 ? $c->page(lcup_setting('students_url')) : $c->page(lcup_setting('students_url'), ['page' => $sp]);
            $l = lcup_parse_students_list($r['body']); $pi = lc_page_info(lc_dom($r['body'])); $added = 0;
            if ($sp > 1 && $pi['page'] !== $sp) $l['students'] = [];
            foreach ($l['students'] as $x) { $q = db()->prepare("INSERT OR IGNORE INTO lcup_student_ids (branch_id, student_id, name) VALUES (?,?,?)"); $q->execute([$bid, $x['id'], $x['name']]); $added += $q->rowCount(); if ($x['name'] !== '') db()->prepare("UPDATE lcup_student_ids SET name = ? WHERE branch_id = ? AND student_id = ? AND name = ''")->execute([$x['name'], $bid, $x['id']]); }
            $st['sl']++;
            if (!$l['students'] || !$pi['has_next'] || $st['sl'] > $maxP) { $st['phase'] = db()->query("SELECT COUNT(*) FROM lcup_student_ids WHERE branch_id = $bid")->fetchColumn() ? 'students' : 'finish'; if ($st['phase'] === 'finish') $sum['warnings'][] = (branch_get($bid)['name'] ?? $bid) . ": talabalar ro'yxati o'qilmadi — oylik to'lovlar snapshoti o'tkazib yuborildi."; }
        }
    }
    if ($st['phase'] === 'students') {
        $left = (int)lcup_num('charge_per_run'); $q = db()->prepare("SELECT student_id, name FROM lcup_student_ids WHERE branch_id = ? AND (last_check IS NULL OR last_check < ?) ORDER BY student_id LIMIT 1");
        while ($left-- > 0 && microtime(true) < $deadline - 4) {
            $q->execute([$bid, $st['started']]); $row = $q->fetch(); if (!$row) { $st['phase'] = 'finish'; break; }
            try { $rows = lcup_student_charges($c, $row['student_id']); lcup_charges_diff($bid, $row['student_id'], (string)$row['name'], $rows); $st['sfail'] = 0; }
            catch (LcupError $e) { if (in_array($e->kind, ['login', 'network', 'ratelimit', 'config'], true)) throw $e; $st['sfail']++; if ($st['sfail'] >= 3) { $sum['warnings'][] = (branch_get($bid)['name'] ?? $bid) . ": " . $e->getMessage() . " — 3 marta ketma-ket; tungi oylik-to'lov snapshoti to'xtatildi."; $st['phase'] = 'finish'; db()->prepare("UPDATE lcup_student_ids SET last_check = ? WHERE branch_id = ?")->execute([lcup_now(), $bid]); break; } }
            db()->prepare("UPDATE lcup_student_ids SET last_check = ? WHERE branch_id = ? AND student_id = ?")->execute([lcup_now(), $bid, $row['student_id']]); $st['students']++;
        }
    }
    if ($st['phase'] === 'finish') {
        // every payment that the full walk did NOT see counts twice towards "vanished" (needs two nights, or the archive row)
        db()->prepare("UPDATE lcup_payments SET miss_n = miss_n + 2, missing_since = COALESCE(missing_since, ?) WHERE branch_id = ? AND deleted_at IS NULL AND last_seen < ?")->execute([lcup_now(), $bid, $st['started']]);
        lcup_pair_pending($bid, $sum);
        $o = db()->prepare("SELECT * FROM lcup_acts WHERE branch_id = ? AND type = 'payment_created' AND pay_id IS NULL AND state = 'open' AND amount > 0 AND act_time < ?");
        $o->execute([$bid, date('Y-m-d H:i:s', strtotime('-2 days'))]);
        foreach ($o->fetchAll() as $a) {
            db()->prepare("UPDATE lcup_acts SET state = 'orphan' WHERE branch_id = ? AND act_id = ?")->execute([$bid, $a['act_id']]);
            lcup_event_add(['type' => 'payment_missing', 'branch_id' => $bid, 'source' => 'reconcile', 'staff' => $a['staff'], 'student_id' => $a['student_id'], 'student_name' => $a['student_name'], 'group_name' => $a['group_name'],
                'amount' => $a['amount'], 'flags' => ['deleted', 'no_reason'], 'created_at' => $a['act_time'], 'details' => "Jurnalda " . date('d.m H:i', strtotime($a['act_time'])) . " da yaratilgan to'lov ro'yxatda ham, arxivda ham topilmadi (talaba nomi o'zgargan bo'lishi mumkin)", 'dedupe_key' => "orphan|$bid|{$a['act_id']}"]);
        }
        $st['done'] = 1; lcup_state_set("night_last_$bid", lcup_now() . ' · ' . $st['pages'] . ' sahifa' . ($st['students'] ? ', ' . $st['students'] . " o'quvchi" : ''));
    }
    lcup_state_set($key, json_encode($st));
}

/* ============================================================== debtors + discounts (unverified pages) */
function lcup_fetch_table_pages(LcupClient $c, int $bid, string $url, string $kind, array &$sum, float $deadline, int $max = 15): array {
    $rows = []; $first = null;
    for ($pg = 1; $pg <= $max && microtime(true) < $deadline; $pg++) {
        $r = $pg === 1 ? $c->page($url) : $c->page($url, ['page' => $pg]);
        $a = $kind === 'debt' ? lcup_parse_debtors($r['body']) : lcup_parse_discounts($r['body']);
        if (!$a['structure']) { if ($pg === 1) throw new LcupError(($kind === 'debt' ? 'Qarzdorlar' : 'Chegirma') . " jadvali tanilmadi (SAHIFA: $url). «Diagnostika» ni bosing.", 'layout'); break; }
        $pi = lc_page_info(lc_dom($r['body'])); if ($pg > 1 && $pi['page'] !== $pg) break;
        $sig = md5(json_encode($a['rows'])); if ($sig === $first) break; if ($pg === 1) $first = $sig;
        foreach ($a['rows'] as $x) $rows[$x['key']] = $x;
        if (!$pi['has_next']) break;
    }
    return $rows;
}
function lcup_check_debts(LcupClient $c, int $bid, array &$sum, float $deadline): void {
    $url = lcup_setting('debtors_url'); if ($url === '') return;
    $last = lcup_state_get("debt_last_$bid"); if ($last && time() - (int)$last < max(0, (int)lcup_num('debt_interval')) * 60 - 5) return;
    $new = lcup_fetch_table_pages($c, $bid, $url, 'debt', $sum, $deadline); lcup_state_set("debt_last_$bid", time());
    $old = db()->prepare("SELECT * FROM lcup_debts WHERE branch_id = ?"); $old->execute([$bid]); $old = $old->fetchAll();
    $base = !lcup_state_get("base_debt_$bid");
    if (!$base && count($old) >= 10 && count($new) < 0.5 * count($old)) { $sum['warnings'][] = (branch_get($bid)['name'] ?? $bid) . ": qarzdorlar ro'yxati keskin qisqardi (" . count($old) . " → " . count($new) . ") — tekshiruv o'tkazib yuborildi."; return; }
    if (!$base) foreach ($old as $o) {
        $nd = isset($new[$o['d_key']]) ? (float)$new[$o['d_key']]['debt'] : 0.0; $delta = (float)$o['debt'] - $nd; if ($delta < 1) continue;
        $cov = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM lcup_acts WHERE branch_id = ? AND type = 'payment_created' AND amount > 0 AND first_seen >= ? AND (student_id = ? OR student_name = ? COLLATE NOCASE)");
        $cov->execute([$bid, date('Y-m-d H:i:s', strtotime($o['updated_at'] . ' -10 minutes')), $o['student_id'], $o['student_name']]);
        if ($delta <= (float)$cov->fetchColumn() * 1.02) continue;                      // a payment explains it
        // who was working with charges at that moment? ("minus to'lov tahrirlandi" rows carry neither student nor amount)
        $lg = db()->prepare("SELECT act_id, staff, act_time FROM lcup_acts WHERE branch_id = ? AND type = 'log_edit' AND first_seen >= ? ORDER BY act_time DESC LIMIT 5");
        $lg->execute([$bid, date('Y-m-d H:i:s', strtotime($o['updated_at'] . ' -15 minutes'))]); $logs = $lg->fetchAll();
        $staffs = array_values(array_unique(array_filter(array_column($logs, 'staff')))); $who = count($staffs) === 1 ? $staffs[0] : null;
        $link = $logs ? " · jurnal: «minus to'lov tahrirlandi» " . date('H:i', strtotime($logs[0]['act_time'])) . ($staffs ? ' (' . implode(', ', $staffs) . ')' : '') : '';
        if (count($logs) === 1 && $logs[0]) db()->prepare("UPDATE lcup_acts SET student_id = COALESCE(student_id, ?), student_name = COALESCE(student_name, ?) WHERE branch_id = ? AND act_id = ?")->execute([$o['student_id'], $o['student_name'], $bid, $logs[0]['act_id']]);
        lcup_event_add(['type' => 'debt_reduced', 'branch_id' => $bid, 'source' => 'debtors', 'staff' => $who, 'student_id' => $o['student_id'], 'student_name' => $o['student_name'], 'group_name' => $o['group_name'] ?? null, 'amount' => $delta,
            'before' => ['debt' => (float)$o['debt']], 'after' => ['debt' => $nd],
            'details' => "Qarz " . fmt($o['debt']) . " → " . fmt($nd) . " (−" . fmt($delta) . ($nd < 0.5 ? ", ro'yxatdan chiqdi" : '') . "), mos to'lov jurnalda topilmadi" . $link, 'dedupe_key' => "debt|$bid|{$o['d_key']}|" . (int)$nd . '|' . date('YmdHi')]);
    }
    db()->prepare("DELETE FROM lcup_debts WHERE branch_id = ?")->execute([$bid]);
    $ins = db()->prepare("INSERT OR REPLACE INTO lcup_debts (branch_id, d_key, student_id, student_name, group_name, debt, updated_at) VALUES (?,?,?,?,?,?,?)");
    foreach ($new as $x) $ins->execute([$bid, $x['key'], $x['student_id'], $x['student_name'], $x['group'] ?? '', $x['debt'], lcup_now()]);
    lcup_state_set("base_debt_$bid", '1');
}
function lcup_check_discounts(LcupClient $c, int $bid, array &$sum, float $deadline): void {
    $url = lcup_setting('discount_url'); if ($url === '') return;
    $last = lcup_state_get("disc_last_$bid"); if ($last && time() - (int)$last < max(0, (int)lcup_num('discount_interval')) * 60 - 5) return;
    $new = lcup_fetch_table_pages($c, $bid, $url, 'disc', $sum, $deadline); lcup_state_set("disc_last_$bid", time());
    $old = db()->prepare("SELECT * FROM lcup_discounts WHERE branch_id = ?"); $old->execute([$bid]); $oldMap = []; foreach ($old->fetchAll() as $o) $oldMap[$o['d_key']] = $o;
    $base = !lcup_state_get("base_disc_$bid"); $evs = [];
    if (!$base && count($oldMap) >= 10 && count($new) < 0.5 * count($oldMap)) { $sum['warnings'][] = (branch_get($bid)['name'] ?? $bid) . ": chegirma ro'yxati keskin qisqardi — tekshiruv o'tkazib yuborildi."; return; }
    if (!$base) {
        foreach ($new as $k => $x) {
            if (!isset($oldMap[$k])) $evs[] = ['discount_new', $x, null];
            elseif (abs((float)$oldMap[$k]['num'] - (float)$x['num']) >= 0.5 || trim((string)$oldMap[$k]['value']) !== trim((string)$x['value'])) $evs[] = ['discount_changed', $x, $oldMap[$k]];
        }
        foreach ($oldMap as $k => $o) if (!isset($new[$k])) $evs[] = ['discount_removed', ['key' => $k, 'student_id' => $o['student_id'], 'student_name' => $o['student_name'], 'group' => $o['group_name'], 'value' => $o['value'], 'num' => $o['num'], 'reason' => $o['reason'], 'staff' => $o['staff']], $o];
    }
    $mass = count($evs) > 15;                                        // e.g. the report switched to a new month: one summary instead of a flood
    foreach ($evs as [$type, $x, $o]) {
        $verb = $type === 'discount_removed' ? "Chegirma o'chirildi: " : 'Chegirma: ';
        lcup_event_add(['type' => $type, 'branch_id' => $bid, 'source' => 'discounts', 'staff' => $x['staff'] ?: null, 'student_id' => $x['student_id'], 'student_name' => $x['student_name'], 'group_name' => $x['group'], 'amount' => $x['num'],
            'before' => $o ? ['num' => (float)$o['num'], 'value' => $o['value']] : null, 'after' => $type === 'discount_removed' ? null : ['num' => (float)$x['num'], 'value' => $x['value']], 'info_only' => $mass,
            'details' => $verb . $x['value'] . ($o && $type === 'discount_changed' ? " (oldin: " . $o['value'] . ")" : '') . ($x['reason'] ? " · sabab: " . $x['reason'] : '') . ($mass ? ' · ommaviy o‘zgarish' : ''), 'dedupe_key' => "disc|$bid|{$x['key']}|$type|" . sha1($x['value'] . microtime())]);
    }
    db()->prepare("DELETE FROM lcup_discounts WHERE branch_id = ?")->execute([$bid]);
    $ins = db()->prepare("INSERT OR REPLACE INTO lcup_discounts (branch_id, d_key, student_id, student_name, group_name, value, num, reason, staff, first_seen, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($new as $k => $x) $ins->execute([$bid, $k, $x['student_id'], $x['student_name'], $x['group'], $x['value'], $x['num'], $x['reason'], $x['staff'], $oldMap[$k]['first_seen'] ?? lcup_now(), lcup_now()]);
    lcup_state_set("base_disc_$bid", '1');
}

/* ============================================================== nightly: monthly charges ("Oylik to'lov" / "Aktivlashtirilganda") of every active student */
/** The student "To'lovlar" tab is filled by Livewire.emit('studentTolovlarOchilsin', id) — whitelisted, read-only. Returns the negative / monthly rows. */
function lcup_student_charges(LcupClient $c, string $sid): array {
    $page = $c->view_page('/admin/students/' . rawurlencode($sid));
    $frags = $c->view_emit($page, 'studentTolovlarOchilsin', [(string)$sid]);
    if (!$frags) throw new LcupError("O'quvchi sahifasida «To'lovlar» komponenti topilmadi (#$sid).", 'layout');
    $p = lcup_parse_student_payments(implode("\n", $frags));
    if (!$p['structure']) throw new LcupError("O'quvchi «To'lovlar» tabi jadvali tanilmadi (#$sid). «Diagnostika» ni bosing.", 'layout');
    return array_values(array_filter($p['rows'], function ($r) { return (float)$r['amount'] < 0 || lc_is_monthly($r['status']) || preg_match('/aktivlash/iu', lc_apos((string)$r['status'])); }));
}
function lcup_charges_diff(int $bid, string $sid, string $name, array $rows): void {
    $st = db()->prepare("SELECT * FROM lcup_charges WHERE branch_id = ? AND student_id = ?"); $st->execute([$bid, $sid]); $old = []; foreach ($st->fetchAll() as $o) $old[$o['ckey']] = $o;
    $first = !$old && !lcup_state_get("chg_seen_{$bid}_$sid"); $ord = []; $seen = []; $now = lcup_now();
    foreach ($rows as $r) {
        $b = $r['date'] . '|' . lc_norm((string)$r['status']) . '|' . lc_norm((string)$r['group']); $ord[$b] = ($ord[$b] ?? 0) + 1; $key = !empty($r['pay_id']) ? 'id:' . $r['pay_id'] : substr(sha1($b . '|' . $ord[$b]), 0, 16); $seen[$key] = 1;
        if (isset($old[$key])) {
            if (abs((float)$old[$key]['amount'] - (float)$r['amount']) >= 0.5) {
                lcup_event_add(['type' => 'charge_changed', 'branch_id' => $bid, 'source' => 'charges', 'student_id' => $sid, 'student_name' => $name, 'group_name' => $r['group'], 'amount' => $r['amount'], 'before' => ['amount' => (float)$old[$key]['amount']], 'after' => ['amount' => (float)$r['amount']],
                    'details' => "Oylik to'lov (" . ($r['status'] ?: 'hisob') . ", " . ($r['date'] ?: '?') . ") " . fmt($old[$key]['amount']) . " → " . fmt($r['amount']) . " (kechagi nusxaga nisbatan)", 'dedupe_key' => "chg|$bid|$sid|$key|" . (int)$r['amount'] . '|' . date('Ymd')]);
                db()->prepare("UPDATE lcup_charges SET amount = ?, last_seen = ? WHERE branch_id = ? AND student_id = ? AND ckey = ?")->execute([$r['amount'], $now, $bid, $sid, $key]);
            } else db()->prepare("UPDATE lcup_charges SET last_seen = ? WHERE branch_id = ? AND student_id = ? AND ckey = ?")->execute([$now, $bid, $sid, $key]);
        } else db()->prepare("INSERT OR IGNORE INTO lcup_charges (branch_id, student_id, ckey, amount, pay_date, status, group_name, staff, created_at, first_seen, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([$bid, $sid, $key, $r['amount'], $r['date'], $r['status'], $r['group'], $r['staff'], $r['created_at'], $now, $now]);
    }
    if (!$first) foreach ($old as $key => $o) if (!isset($seen[$key]) && strtotime($o['last_seen']) > time() - 3 * 86400) {      // a charge that vanished since the last look
        lcup_event_add(['type' => 'charge_changed', 'branch_id' => $bid, 'source' => 'charges', 'student_id' => $sid, 'student_name' => $name, 'group_name' => $o['group_name'], 'amount' => 0, 'before' => ['amount' => (float)$o['amount']], 'after' => ['amount' => 0.0],
            'details' => "Oylik to'lov (" . ($o['status'] ?: 'hisob') . ", " . ($o['pay_date'] ?: '?') . ") " . fmt($o['amount']) . " → o'chirilgan", 'dedupe_key' => "chg|$bid|$sid|$key|gone"]);
        db()->prepare("DELETE FROM lcup_charges WHERE branch_id = ? AND student_id = ? AND ckey = ?")->execute([$bid, $sid, $key]);
    }
    lcup_state_set("chg_seen_{$bid}_$sid", '1');
}

/* ============================================================== end-of-day: cash entered in LC-UP but not in Argent */
function lcup_eod_check(array $map, array &$sum): void {
    $now = date('H:i'); $today = date('Y-m-d');
    foreach ([[0, (string)lcup_setting('eod_time')], [1, '23:50']] as [$i, $t]) {
        if ($now < $t) continue; $sk = "eod_{$i}_$today"; if (lcup_state_get($sk)) continue;
        foreach ($map as $m) {
            $res = lcup_reconcile($today, [(int)$m['branch_id']]);
            foreach ($res['rows'] as $r) {
                if ($r['l_total'] <= 0 || $r['student_id'] === null) continue;
                $short = $r['l_total'] - $r['a_total']; if ($short < 1) continue;
                $cash = min($r['l_cash'] + $r['l_unk'], $short); if ($cash < 1) continue;
                lcup_event_add(['type' => 'cash_missing', 'branch_id' => (int)$m['branch_id'], 'source' => 'reconcile', 'student_id' => $r['student_id'], 'student_name' => $r['name'], 'amount' => $cash,
                    'details' => "LC-UP da to'lov " . fmt($r['l_total']) . " so'm, Argent'ga kiritilgan " . fmt($r['a_total']) . " so'm — farq " . fmt($short) . " so'm (naqd: " . fmt($cash) . ")",
                    'dedupe_key' => "eod|$today|{$m['branch_id']}|{$r['student_id']}"]);
            }
        }
        lcup_state_set($sk, '1');
    }
}
