<?php
/**
 * LC-UP monitoring: settings, event recording + red-flag rules, Telegram alerts, health, reconciliation.
 * Pure helpers — the HTTP client is lcup_client.php, the HTML parsers lcup_parse.php, the sync engine lcup_sync.php.
 */
require_once __DIR__ . '/layout.php';   // fmt(), e() ... (definitions only, prints nothing)

/** Flags that turn an event red (and trigger a Telegram alert). */
const LCUP_RED_FLAGS = ['amount_changed', 'edited', 'deleted', 'debt_reduction', 'new_discount', 'discount_up', 'small_amount', 'off_hours', 'cash_missing', 'no_details'];

function lcup_now(): string { return date('Y-m-d H:i:s'); }
function lcup_defaults(): array {
    return ['small_amount' => '5000', 'work_from' => '08:00', 'work_to' => '21:00', 'late_minutes' => '60', 'eod_time' => '21:30',
            'delay_ms' => '350', 'budget_s' => '40', 'window_days' => '40', 'debt_interval' => '5', 'discount_interval' => '5',
            'hot_pages' => '3', 'warm_pages' => '3', 'night_time' => '03:00', 'night_max_pages' => '3000', 'night_pages_per_run' => '25',
            'allow_switch_post' => '0', 'switch_url' => '',
            'worker_url' => '/admin/worker_reports', 'archive_url' => '/admin/archive/tolovlars', 'payments_url' => '/admin/tolovlars',
            'discount_url' => '/admin/discount', 'debtors_url' => '/admin/qarzdorlar', 'alerts_on' => '1'];
}
function lcup_setting(string $k) { $d = lcup_defaults(); $v = setting('lcup.' . $k, $d[$k] ?? ''); return ($v === '' || $v === null) ? ($d[$k] ?? '') : $v; }
function lcup_num(string $k): float { return (float)lcup_setting($k); }
function lcup_state_get(string $k, $d = null) {
    $st = db()->prepare("SELECT value FROM lcup_state WHERE key = ?"); $st->execute([$k]); $v = $st->fetchColumn();
    return $v === false ? $d : $v;
}
function lcup_state_set(string $k, $v): void {
    db()->prepare("INSERT INTO lcup_state (key, value, updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")->execute([$k, (string)$v, lcup_now()]);
}
function lcup_state_del(string $k): void { db()->prepare("DELETE FROM lcup_state WHERE key = ?")->execute([$k]); }

/* ----------------------------------------------------------------- logging (never contains secrets) */
function lcup_secrets(): array {
    $s = [];
    foreach (['LCUP_PASSWORD', 'LCUP_PHONE', 'CRON_TOKEN', 'LCUP_WEBHOOK_SECRET', 'TELEGRAM_BOT_TOKEN'] as $k) { $v = (string)cfg($k, ''); if (strlen($v) >= 4) $s[] = $v; }
    $t = (string)private_get('telegram_token', ''); if (strlen($t) >= 8) $s[] = $t;
    $ph = preg_replace('/\D+/', '', (string)cfg('LCUP_PHONE', '')); if (strlen($ph) >= 7) { $s[] = $ph; $s[] = substr($ph, -9); }
    return $s;
}
function lcup_redact(string $t): string {
    foreach (lcup_secrets() as $x) $t = str_replace($x, '***', $t);
    return preg_replace('/\b[A-Za-z0-9]{36,}\b/', '***', $t);            // CSRF / session style tokens
}
function lcup_log(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . lcup_redact($msg) . "\n";
    $d = private_dir(); $f = $d && is_dir($d) && is_writable($d) ? $d . '/lcup.log' : null;
    if (!$f) { error_log('lcup: ' . trim($line)); return; }
    if (is_file($f) && filesize($f) > 1048576) @rename($f, $f . '.1');
    @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
}

/* ----------------------------------------------------------------- mapping */
function lcup_map_rows(bool $onlyActive = true): array {
    $rows = db()->query("SELECT m.*, b.name AS branch_name, b.is_active AS branch_active FROM lcup_branch_map m JOIN branches b ON b.id = m.branch_id ORDER BY b.sort_order, b.id")->fetchAll();
    return $onlyActive ? array_values(array_filter($rows, function ($r) { return (int)$r['is_active'] === 1 && (int)$r['branch_active'] === 1; })) : $rows;
}
function lcup_branch_ids(): array { return array_map(function ($r) { return (int)$r['branch_id']; }, lcup_map_rows()); }

/* ----------------------------------------------------------------- labels */
function lcup_type_labels(): array {
    return ['payment_created' => "To'lov yaratildi", 'payment_changed' => "To'lov o'zgartirildi", 'payment_deleted' => "To'lov o'chirildi",
            'log_edit' => "To'lov tahrirlandi (tafsilotsiz)", 'log_delete' => "To'lov o'chirildi (jurnal)", 'debt_reduced' => 'Qarz kamaygan',
            'amount_changed' => "To'lov summasi o'zgartirilgan", 'payment_missing' => "To'lov ro'yxatda topilmadi", 'staff_activity' => 'Xodim faoliyati (tafsilotsiz)', 'discount_new' => 'Yangi chegirma', 'discount_changed' => "Chegirma o'zgargan", 'freeze_charge' => 'Muzlatishda pul yechildi',
            'student_frozen' => 'Talaba muzlatildi', 'student_activated' => 'Talaba aktivlashtirildi', 'payment_other' => "To'lov bilan bog'liq amal",
            'cash_missing' => 'Naqd Argentga kiritilmagan', 'webhook' => 'Webhook', 'system' => 'Tizim', 'other' => 'Boshqa'];
}
function lcup_type_label(string $t): string { return lcup_type_labels()[$t] ?? $t; }
function lcup_flag_labels(): array {
    $late = (int)lcup_num('late_minutes'); $lt = $late % 60 === 0 ? ($late / 60) . ' soatdan keyin' : $late . " daqiqadan keyin";
    return ['amount_changed' => "Summa o'zgartirilgan", 'edited' => 'Tahrirlangan', 'late_edit' => "$lt tahrirlangan", 'deleted' => "O'chirilgan", 'late_delete' => "$lt o'chirilgan", 'no_reason' => 'Sababsiz',
            'debt_reduction' => 'Qarz/hisob kamaygan', 'new_discount' => 'Yangi chegirma', 'discount_up' => 'Chegirma oshgan', 'small_amount' => fmt(lcup_num('small_amount')) . " dan kam",
            'off_hours' => 'Ish vaqtidan tashqari', 'cash_missing' => 'Naqd Argentga kiritilmagan', 'no_details' => "Tafsilot yo'q", 'weak' => "Taxminiy aniqlash"];
}
function lcup_method_label(?string $m): string { return ['cash' => 'Naqd', 'card' => 'Plastik karta', 'bank' => 'Bank hisobi', 'other' => 'Boshqa'][$m ?? ''] ?? (string)$m; }
function lcup_method_class(?string $m): string { return $m === 'cash' ? 'cash' : 'noncash'; }
function lcup_field_label(string $f): string { return ['amount' => 'Summa', 'pay_date' => 'Sana (oy)', 'method' => 'Usul', 'group_name' => 'Guruh', 'status' => 'Holat', 'student_name' => 'Talaba', 'staff' => 'Xodim', 'debt' => 'Qarz', 'value' => 'Chegirma'][$f] ?? $f; }
/** "Summa: 500 000 → 600 000; Usul: Naqd → Plastik karta" */
function lcup_change_text(array $before, array $after): string {
    $out = [];
    foreach ($after as $k => $v) {
        $b = $before[$k] ?? null; if ((string)$b === (string)$v) continue;
        $fmtv = function ($x) use ($k) { if ($x === null || $x === '') return '—'; if ($k === 'amount' || $k === 'debt') return fmt($x); if ($k === 'method') return lcup_method_label($x); return (string)$x; };
        $out[] = lcup_field_label($k) . ': ' . $fmtv($b) . ' → ' . $fmtv($v);
    }
    return implode('; ', $out);
}

/* ----------------------------------------------------------------- flags + recording */
function lcup_outside_hours(string $time): bool {   // $time = 'HH:MM'
    return $time < (string)lcup_setting('work_from') || $time >= (string)lcup_setting('work_to');
}
function lcup_compute_flags(array $e): array {
    $f = (array)($e['flags'] ?? []); $type = $e['type'] ?? 'other';
    $when = !empty($e['lcup_time']) ? strtotime($e['lcup_time']) : time();
    $age = (!empty($e['created_at']) && ($c = strtotime($e['created_at']))) ? ($when - $c) : null;
    $late = (int)lcup_num('late_minutes') * 60;
    $before = (array)($e['before'] ?? []); $after = (array)($e['after'] ?? []);
    $charge = !empty($e['is_charge']) || (isset($before['amount']) && (float)$before['amount'] < 0);
    if ($type === 'payment_changed' || $type === 'log_edit') {
        $f[] = 'edited'; if ($age !== null && $age > $late) $f[] = 'late_edit';
        if ($type === 'log_edit' && !$before && empty($e['amount'])) $f[] = 'no_details';
        if ($charge && isset($before['amount'], $after['amount']) && abs((float)$after['amount']) < abs((float)$before['amount'])) $f[] = 'debt_reduction';
        if ($charge && $type === 'log_edit') $f[] = 'debt_reduction';            // "minus to'lov tahrirlandi": a charge was edited
    }
    if ($type === 'payment_deleted' || $type === 'log_delete') {
        $f[] = 'deleted'; if ($age !== null && $age > $late) $f[] = 'late_delete';
        if ($type === 'payment_deleted' && trim((string)($e['reason'] ?? '')) === '') $f[] = 'no_reason';
        if ($charge) $f[] = 'debt_reduction';
    }
    if ($type === 'amount_changed') $f[] = 'amount_changed';
    if ($type === 'payment_created' && !$charge && isset($e['amount']) && (float)$e['amount'] > 0 && (float)$e['amount'] < lcup_num('small_amount')) $f[] = 'small_amount';
    if ($type === 'debt_reduced') $f[] = 'debt_reduction';
    if ($type === 'discount_new') $f[] = 'new_discount';
    if ($type === 'discount_changed' && ((float)($after['num'] ?? 0) > (float)($before['num'] ?? 0))) $f[] = 'discount_up';
    if ($type === 'cash_missing') $f[] = 'cash_missing';
    if (!in_array($type, ['cash_missing', 'system', 'webhook', 'debt_reduced', 'amount_changed', 'payment_missing', 'staff_activity'], true)) {
        $t = !empty($e['lcup_time']) ? substr($e['lcup_time'], 11, 5) : date('H:i');
        if (strlen($t) === 5 && lcup_outside_hours($t) && !in_array($type, ['discount_changed'], true)) $f[] = 'off_hours';
    }
    return array_values(array_unique($f));
}
/** Appends an event. Returns its id, or null when the same event is already stored (dedupe). */
function lcup_event_add(array $e): ?int {
    $flags = lcup_compute_flags($e);
    $sev = (empty($e['info_only']) && array_intersect($flags, LCUP_RED_FLAGS)) ? 'red' : 'info';
    $now = lcup_now(); $lt = !empty($e['lcup_time']) ? $e['lcup_time'] : null;
    $cut = function ($s, $n) { return $s === null ? null : mb_substr((string)$s, 0, $n); };
    $st = db()->prepare("INSERT OR IGNORE INTO lcup_events (detected_at, lcup_time, event_date, branch_id, source, type, staff, student_id, student_name, group_name, amount, method,
            before_json, after_json, details, raw, severity, flags, backfill, dedupe_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$now, $lt, $lt ? substr($lt, 0, 10) : substr($now, 0, 10), $e['branch_id'] ?? null, $e['source'] ?? 'snapshot', $e['type'] ?? 'other',
        $cut($e['staff'] ?? null, 120), $cut($e['student_id'] ?? null, 40), $cut($e['student_name'] ?? null, 160), $cut($e['group_name'] ?? null, 160),
        isset($e['amount']) ? (float)$e['amount'] : null, $e['method'] ?? null,
        !empty($e['before']) ? json_encode($e['before'], JSON_UNESCAPED_UNICODE) : null, !empty($e['after']) ? json_encode($e['after'], JSON_UNESCAPED_UNICODE) : null,
        $cut($e['details'] ?? null, 600), $cut($e['raw'] ?? null, 3000), $sev, implode(',', $flags), !empty($e['backfill']) ? 1 : 0, (string)$e['dedupe_key']]);
    return $st->rowCount() ? (int)db()->lastInsertId() : null;
}
function lcup_unread_count(): int {
    static $n = null;
    if ($n === null) { try { $n = (int)db()->query("SELECT COUNT(*) FROM lcup_events WHERE severity = 'red' AND reviewed_at IS NULL AND backfill = 0")->fetchColumn(); } catch (Throwable $e) { $n = 0; } }
    return $n;
}
function lcup_review(int $id, int $userId, string $note): bool {
    $st = db()->prepare("UPDATE lcup_events SET reviewed_at = ?, reviewed_by = ?, review_note = ? WHERE id = ? AND reviewed_at IS NULL");
    $st->execute([lcup_now(), $userId, mb_substr(trim($note), 0, 500), $id]);
    return $st->rowCount() > 0;
}

/* ----------------------------------------------------------------- telegram */
function lcup_tg(): array {
    $tok = (string)private_get('telegram_token', '') ?: (string)cfg('TELEGRAM_BOT_TOKEN', '');
    $chat = (string)private_get('telegram_chat', '') ?: (string)cfg('TELEGRAM_CHAT_ID', '');
    return [$tok, $chat];
}
function lcup_tg_ready(): bool { [$t, $c] = lcup_tg(); return $t !== '' && $c !== ''; }
function lcup_tg_send(string $html, ?string &$err = null): bool {
    [$tok, $chat] = lcup_tg();
    if ($tok === '' || $chat === '') { $err = "Telegram sozlanmagan"; return false; }
    if (!function_exists('curl_init')) { $err = "PHP cURL yo'q"; return false; }
    $api = rtrim((string)cfg('TELEGRAM_API_BASE', 'https://api.telegram.org'), '/');
    $ch = curl_init("$api/bot$tok/sendMessage");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => mb_substr($html, 0, 3900), 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ce = curl_error($ch); curl_close($ch);
    if ($body === false) { $err = lcup_redact("Telegram'ga ulanib bo'lmadi: $ce"); return false; }
    $j = json_decode((string)$body, true);
    if ($code !== 200 || empty($j['ok'])) { $err = lcup_redact("Telegram xatosi ($code): " . ($j['description'] ?? mb_substr((string)$body, 0, 120))); return false; }
    return true;
}
function lcup_app_url(): string {
    $u = (string)private_get('app_url', '');
    return $u !== '' ? rtrim($u, '/') : '';
}
/** Remember how the admin reaches the app, so cron/CLI alerts can contain links. */
function lcup_remember_app_url(): void {
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $u = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (private_get('app_url', '') !== $u) private_set(['app_url' => $u]);
}
function lcup_alert_html(array $ev): string {
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_NOQUOTES, 'UTF-8'); };
    $b = $ev['branch_id'] ? (branch_get((int)$ev['branch_id'])['name'] ?? '') : '';
    $labels = lcup_flag_labels(); $fl = [];
    foreach (array_filter(explode(',', (string)$ev['flags'])) as $f) $fl[] = $labels[$f] ?? $f;
    $t = "🔴 <b>LC-UP: " . $h(lcup_type_label($ev['type'])) . "</b>\n";
    if ($b !== '') $t .= "🏢 " . $h($b) . "\n";
    if ($ev['staff'] !== null && $ev['staff'] !== '') $t .= "👤 Xodim: " . $h($ev['staff']) . "\n";
    if ($ev['student_name'] || $ev['student_id']) $t .= "🎓 Talaba: " . $h($ev['student_name'] ?: '—') . ($ev['student_id'] ? ' (#' . $h($ev['student_id']) . ')' : '') . "\n";
    if ($ev['amount'] !== null) $t .= "💰 Summa: " . fmt($ev['amount']) . " so'm\n";
    if ($ev['details']) $t .= "📝 " . $h($ev['details']) . "\n";
    $t .= "🕒 " . $h($ev['lcup_time'] ? date('d.m.Y H:i', strtotime($ev['lcup_time'])) : date('d.m.Y H:i', strtotime($ev['detected_at']))) . "\n";
    if ($fl) $t .= "⚠️ " . $h(implode(', ', $fl)) . "\n";
    $base = lcup_app_url(); if ($base !== '') $t .= '🔗 ' . $h($base . '/lcup.php?id=' . (int)$ev['id']);
    return trim($t);
}
/** Sends Telegram messages for red events not yet alerted (max $max per call, the rest summarised). */
function lcup_alerts_flush(int $max = 8): array {
    $sent = 0; $fail = 0;
    if (lcup_setting('alerts_on') !== '1' || !lcup_tg_ready()) return ['sent' => 0, 'failed' => 0, 'skipped' => true];
    $since = lcup_state_get('alerts_since', '2000-01-01 00:00:00');
    $st = db()->prepare("SELECT * FROM lcup_events WHERE severity = 'red' AND backfill = 0 AND alerted_at IS NULL AND detected_at >= ? ORDER BY id LIMIT 60");
    $st->execute([$since]); $evs = $st->fetchAll();
    $mark = db()->prepare("UPDATE lcup_events SET alerted_at = ? WHERE id = ? AND alerted_at IS NULL");
    foreach ($evs as $i => $ev) {
        if ($i >= $max) {
            $rest = array_slice($evs, $i); $err = null;
            $ok = lcup_tg_send("🔴 <b>LC-UP: yana " . count($rest) . " ta shubhali hodisa</b>\nBatafsil: " . (lcup_app_url() ? lcup_app_url() . '/lcup.php' : 'Argent → LC-UP nazorat'), $err);
            if ($ok) foreach ($rest as $r) $mark->execute([lcup_now(), $r['id']]);
            $ok ? $sent++ : $fail++;
            break;
        }
        $err = null;
        if (lcup_tg_send(lcup_alert_html($ev), $err)) { $mark->execute([lcup_now(), $ev['id']]); $sent++; usleep(250000); }
        else { $fail++; lcup_state_set('tg_last_error', $err); break; }
    }
    if ($sent && !$fail) lcup_state_del('tg_last_error');
    return ['sent' => $sent, 'failed' => $fail];
}

/* ----------------------------------------------------------------- health */
function lcup_health_update(bool $ok, string $msg = ''): void {
    $fails = (int)lcup_state_get('fail_count', 0);
    lcup_state_set('last_run', lcup_now());
    if ($ok) {
        lcup_state_set('last_success', lcup_now());
        if ($fails >= 5 && lcup_state_get('fail_alert_sent')) lcup_tg_send("✅ <b>LC-UP sinxronizatsiya tiklandi</b> ($fails ta muvaffaqiyatsiz urinishdan keyin).");
        lcup_state_set('fail_count', 0); lcup_state_del('fail_alert_sent'); lcup_state_del('last_error');
        return;
    }
    $fails++; lcup_state_set('fail_count', $fails); lcup_state_set('last_error', lcup_redact($msg));
    if ($fails === 5 || ($fails > 5 && ($fails - 5) % 60 === 0)) {
        $last = lcup_state_get('last_success'); $err = null;
        $okSend = lcup_tg_send("⚠️ <b>LC-UP sinxronizatsiya " . $fails . " marta ketma-ket muvaffaqiyatsiz</b>\n" . htmlspecialchars(lcup_redact($msg), ENT_NOQUOTES) . "\nOxirgi muvaffaqiyatli: " . ($last ? date('d.m.Y H:i', strtotime($last)) : 'hech qachon'), $err);
        if ($okSend) lcup_state_set('fail_alert_sent', lcup_now());
    }
}
/** Minutes since the last sync attempt (cron may have stopped). null = never ran. */
function lcup_minutes_since_run(): ?int {
    $t = lcup_state_get('last_run'); return $t ? (int)floor((time() - strtotime($t)) / 60) : null;
}
/** Called when an admin opens a page: if cron has stopped, say so on Telegram (at most once an hour). */
function lcup_stale_check(): void {
    $m = lcup_minutes_since_run(); if ($m === null || $m < 10) return;
    $last = lcup_state_get('stale_alert_at'); if ($last && time() - strtotime($last) < 3600) return;
    if (lcup_tg_send("⚠️ <b>LC-UP sinxronizatsiya to'xtab qolgan</b>\nOxirgi urinish $m daqiqa oldin edi. Cron ishlayaptimi?")) lcup_state_set('stale_alert_at', lcup_now());
}

/* ----------------------------------------------------------------- reconciliation (LC-UP vs Argent) */
function lcup_student_id_from_text(?string $t): ?string {
    if ($t === null) return null;
    if (preg_match('~lc-up\.com/admin/students/(\d+)~i', $t, $m)) return $m[1];
    if (preg_match('~/admin/students/(\d+)~i', $t, $m)) return $m[1];
    return null;
}
function lcup_is_payment_category(?string $name): bool {
    return (bool)preg_match("/^to['’ʻʼ`]?lov/iu", trim((string)$name));
}
/**
 * Day comparison for the given branches (Argent branch ids).
 * LC-UP side = "to'lov yaratildi" activity records of that DAY (their time is when the money was received; the list's SANA is only
 * the billing month), amount = what the payment is worth NOW (edited amounts count), deleted payments are left out.
 */
function lcup_reconcile(string $date, array $branchIds): array {
    $rows = []; $unlinked = []; $blank = ['l_total' => 0, 'l_orig' => 0, 'l_cash' => 0, 'l_non' => 0, 'l_unk' => 0, 'l_n' => 0, 'l_items' => [], 'a_total' => 0, 'a_cash' => 0, 'a_non' => 0, 'a_n' => 0, 'a_items' => []];
    $branchIds = array_values(array_filter(array_map('intval', $branchIds)));
    foreach ($branchIds as $bid) {
        $st = db()->prepare("SELECT a.*, p.method AS pmethod, p.amount AS pamount FROM lcup_acts a LEFT JOIN lcup_payments p ON p.branch_id = a.branch_id AND p.pay_id = a.pay_id
                             WHERE a.branch_id = ? AND a.type = 'payment_created' AND a.state <> 'deleted' AND a.amount > 0 AND substr(a.act_time, 1, 10) = ? ORDER BY a.act_time");
        $st->execute([$bid, $date]);
        foreach ($st->fetchAll() as $p) {
            $sid = $p['student_id'] ?: 'n:' . lc_norm((string)$p['student_name']); $k = $bid . '|' . $sid;
            if (!isset($rows[$k])) $rows[$k] = ['key' => $k, 'branch_id' => $bid, 'student_id' => $p['student_id'] ?: null, 'name' => $p['student_name']] + $blank;
            $cur = $p['pamount'] !== null ? (float)$p['pamount'] : (float)$p['amount']; $p['cur'] = $cur;
            $r = &$rows[$k]; $r['l_total'] += $cur; $r['l_orig'] += $p['amount']; $r['l_n']++;
            if ($p['pmethod'] === null) $r['l_unk'] += $cur; elseif ($p['pmethod'] === 'cash') $r['l_cash'] += $cur; else $r['l_non'] += $cur;
            $r['l_items'][] = $p; unset($r);
        }
        $st = db()->prepare("SELECT t.*, c.name AS cat_name, u.username AS author FROM transactions t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN users u ON u.id = t.user_id
                             WHERE t.branch_id = ? AND t.date = ? AND t.cash_in = 1 ORDER BY t.id");
        $st->execute([$bid, $date]);
        foreach ($st->fetchAll() as $t) {
            $sid = lcup_student_id_from_text($t['comment']);
            if ($sid === null) { if (lcup_is_payment_category($t['cat_name'])) $unlinked[] = $t; continue; }
            $k = $bid . '|' . $sid;
            if (!isset($rows[$k])) {
                $nm = db()->prepare("SELECT student_name FROM lcup_acts WHERE branch_id = ? AND student_id = ? AND student_name IS NOT NULL ORDER BY act_time DESC LIMIT 1"); $nm->execute([$bid, $sid]); $nm = $nm->fetchColumn();
                $rows[$k] = ['key' => $k, 'branch_id' => $bid, 'student_id' => $sid, 'name' => $nm ?: ''] + $blank;
            }
            $r = &$rows[$k]; $r['a_total'] += $t['payment']; $r['a_n']++; if (!empty($t['click']) || !empty($t['bank'])) $r['a_non'] += $t['payment']; else $r['a_cash'] += $t['payment'];
            $r['a_items'][] = $t; unset($r);
        }
    }
    $order = ['missing' => 0, 'diff' => 1, 'method' => 2, 'extra' => 3, 'nolink' => 4, 'ok' => 5];
    $tot = ['lcup' => 0, 'argent' => 0, 'problems' => 0, 'by' => ['ok' => 0, 'missing' => 0, 'extra' => 0, 'diff' => 0, 'method' => 0, 'nolink' => 0]];
    foreach ($rows as &$r) {
        $r['diff'] = $r['a_total'] - $r['l_total'];
        if ($r['l_total'] > 0 && $r['a_total'] <= 0) $r['status'] = 'missing';
        elseif ($r['l_total'] <= 0 && $r['a_total'] > 0) $r['status'] = 'extra';
        elseif (abs($r['diff']) >= 1) $r['status'] = 'diff';
        elseif ($r['l_unk'] < 1 && abs($r['l_cash'] - $r['a_cash']) >= 1) $r['status'] = 'method';
        else $r['status'] = 'ok';
        $tot['lcup'] += $r['l_total']; $tot['argent'] += $r['a_total']; $tot['by'][$r['status']]++;
    } unset($r);
    foreach ($unlinked as $t) { $non = (!empty($t['click']) || !empty($t['bank'])); $tot['argent'] += $t['payment']; $tot['by']['nolink']++;
        $rows['nolink|' . $t['id']] = ['key' => 'nolink|' . $t['id'], 'branch_id' => (int)$t['branch_id'], 'student_id' => null, 'name' => ''] + array_merge($blank, ['a_total' => (float)$t['payment'], 'a_cash' => $non ? 0 : (float)$t['payment'], 'a_non' => $non ? (float)$t['payment'] : 0, 'a_n' => 1, 'a_items' => [$t]]) + ['diff' => (float)$t['payment'], 'status' => 'nolink']; }
    $tot['problems'] = array_sum($tot['by']) - $tot['by']['ok'];
    $tot['diff'] = $tot['argent'] - $tot['lcup'];
    uasort($rows, function ($a, $b) use ($order) { return [$order[$a['status']], -max($a['l_total'], $a['a_total'])] <=> [$order[$b['status']], -max($b['l_total'], $b['a_total'])]; });
    return ['rows' => array_values($rows), 'totals' => $tot];
}
/** One summary row per day of the month ('Y-m'). */
function lcup_reconcile_month(string $ym, array $branchIds): array {
    $days = []; $last = min(date('Y-m-t', strtotime($ym . '-01')), date('Y-m-d'));
    for ($d = $ym . '-01'; $d <= $last; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $r = lcup_reconcile($d, $branchIds); $days[] = ['date' => $d] + $r['totals']; }
    return $days;
}
/** Date before which LC-UP data may be incomplete (monitoring/backfill start). */
function lcup_coverage_start(array $branchIds): ?string {
    $min = null;
    foreach ($branchIds as $b) {
        $st = db()->prepare("SELECT MIN(substr(act_time,1,10)) FROM lcup_acts WHERE branch_id = ?"); $st->execute([(int)$b]); $v = $st->fetchColumn();
        if (!$v) return null; if ($min === null || $v > $min) $min = $v;
    }
    return $min;
}
