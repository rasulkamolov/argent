<?php
/**
 * Do'konPro monitoring: settings, append-only event log, red/yellow rules, Telegram alerts, branch map.
 * Mirrors lcup_lib.php. Reads the shop only through shop_db.php (read-only).
 */
require_once __DIR__ . '/lcup_lib.php';
require_once __DIR__ . '/shop_db.php';

function shop_now(): string { return date('Y-m-d H:i:s'); }
function shop_defaults(): array {
    return ['enabled' => '1', 'alerts_on' => '1', 'kitob_category' => 'Kitob', 'argent_shop_cat' => 'shop', 'argent_kitob_cat' => 'kitob', 'argent_pay_cat' => "To'lov",
            'keywords' => "shop,do'kon,dokon,do‘kon,kitob,book", 'work_from' => '08:00', 'work_to' => '21:00', 'return_late_min' => '30', 'small_debt' => '10000', 'cost_jump_pct' => '30',
            'transfer_wait_h' => '24', 'window_days' => '90', 'inv_count' => '8', 'inv_top_days' => '30', 'eod_time' => '21:00', 'recon_next_time' => '10:00', 'tolerance' => '1',
            'pat_low_ratio' => '0.7', 'pat_high_ratio' => '2', 'pat_min_events' => '3', 'late_entry_days' => '1'];
}
function shop_setting(string $k) { $d = shop_defaults(); $v = setting('shop.' . $k, $d[$k] ?? ''); return ($v === '' || $v === null) ? ($d[$k] ?? '') : $v; }
function shop_num(string $k): float { return (float)shop_setting($k); }
function shop_state_get(string $k, $d = null) { $st = db()->prepare("SELECT value FROM shop_state WHERE key = ?"); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? $d : $v; }
function shop_state_set(string $k, $v): void { db()->prepare("INSERT INTO shop_state (key, value, updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")->execute([$k, (string)$v, shop_now()]); }
function shop_state_del(string $k): void { db()->prepare("DELETE FROM shop_state WHERE key = ?")->execute([$k]); }
function shop_log(string $m): void { lcup_log('[shop] ' . $m); }
function shop_lc(string $s): string { return mb_strtolower(trim($s), 'UTF-8'); }
/** Names compared loosely: case, apostrophes and spaces ignored. */
function shop_norm(string $s): string { return preg_replace("/[\s'’ʻʼ`‘]+/u", '', shop_lc($s)); }

/* ----------------------------------------------------------------- branch map (Argent branch <-> shop branch) */
function shop_map_rows(bool $onlyActive = true): array {
    $rows = db()->query("SELECT m.*, b.name AS branch_name, b.is_active AS branch_active FROM shop_branch_map m JOIN branches b ON b.id = m.branch_id ORDER BY b.sort_order, b.id")->fetchAll();
    return $onlyActive ? array_values(array_filter($rows, function ($r) { return (int)$r['is_active'] === 1 && (int)$r['branch_active'] === 1; })) : $rows;
}
function shop_branch_ids(): array { return array_map(function ($r) { return (int)$r['branch_id']; }, shop_map_rows()); }
/** Resolves the shop branch id of every map row (by id, else by name) and stores it. Returns [argent branch id => shop branch id]. */
function shop_resolve_map(): array {
    $shop = []; foreach (shop_branches() as $b) $shop[(int)$b['id']] = $b['name'];
    $out = [];
    foreach (shop_map_rows() as $m) {
        $id = (int)$m['shop_branch_id'];
        if (!$id || !isset($shop[$id])) {
            $id = 0; foreach ($shop as $sid => $nm) if (shop_norm($nm) === shop_norm((string)$m['shop_name']) && $m['shop_name'] !== '') { $id = $sid; break; }
            if ($id) db()->prepare("UPDATE shop_branch_map SET shop_branch_id = ? WHERE branch_id = ?")->execute([$id, $m['branch_id']]);
        }
        if ($id) $out[(int)$m['branch_id']] = $id;
    }
    return $out;
}
/** [shop branch id => argent branch id] */
function shop_map_inverse(): array { $o = []; foreach (shop_resolve_map() as $a => $s) $o[$s] = $a; return $o; }

/* ----------------------------------------------------------------- labels */
function shop_type_labels(): array {
    return ['sale_deleted' => "Chek o'chirilgan", 'receipt_gap' => "Chek raqami tushib qolgan", 'sale_changed' => "Chek o'zgartirilgan", 'sale_flagged' => 'Shubhali sotuv', 'return_new' => 'Qaytarish',
        'return_mismatch' => "Qaytarish yozuvsiz", 'debt_reduced' => "Qarz to'lovsiz kamaygan", 'debt_payment_changed' => "Qarz to'lovi o'zgartirilgan", 'debt_payment_deleted' => "Qarz to'lovi o'chirilgan",
        'stock_adjust' => "Omborga qo'lda tuzatish", 'stock_writeoff' => 'Hisobdan chiqarish', 'stock_inventory' => 'Inventarizatsiya', 'stock_other' => "Noma'lum ombor harakati",
        'price_down' => 'Narx tushirilgan', 'price_up' => 'Narx oshirilgan', 'price_restored' => 'Narx tushirilib, qaytarilgan', 'product_deleted' => "Tovar o'chirilgan",
        'purchase_edited' => "Kirim o'zgartirilgan", 'purchase_deleted' => "Kirim o'chirilgan", 'purchase_cancelled' => 'Kirim bekor qilingan', 'cost_high' => "Kirim narxi odatdagidan baland",
        'transfer_unmatched' => "Ko'chirish: qabul qilinmagan", 'expense_added' => "Do'kon xarajati qo'shildi", 'recon_gap' => "Do'kon ↔ Argent farqi", 'customer_edited' => "Mijoz ma'lumoti o'zgargan",
        'inv_shortage' => 'Inventarizatsiya tekshiruvida kamomad', 'user_new' => 'Yangi xodim', 'user_blocked' => 'Xodim bloklandi', 'user_unblocked' => 'Xodim blokdan chiqarildi', 'user_changed' => "Xodim roli o'zgargan", 'system' => 'Tizim'];
}
function shop_type_label(string $t): string { return shop_type_labels()[$t] ?? $t; }
function shop_flag_labels(): array {
    return ['items_changed' => "Tovarlar o'zgargan", 'amount_changed' => "Summa o'zgargan", 'payment_changed' => "To'lov turi o'zgargan", 'seller_changed' => "Sotuvchi o'zgargan", 'discount_changed' => "Chegirma o'zgargan", 'late_edit' => 'Yaratilgach keyin tahrirlangan',
        'admin_sale' => 'Administrator logini bilan sotilgan', 'blocked_user' => 'Bloklangan xodim sotgan', 'night_sale' => 'Ish vaqtidan tashqari', 'debt_no_phone' => "Qarz: mijozda telefon yo'q", 'debt_new_customer' => 'Qarz: yangi mijoz',
        'debt_staff' => 'Qarz: xodimga', 'debt_no_customer' => "Qarz: mijoz ko'rsatilmagan", 'below_price' => "Narx tovarning odatdagi narxidan past", 'negative_stock' => "Ombor 0 yoki minus bo'lib sotilgan", 'small_debt' => 'Kichik qarz', 'late_return' => 'Sotuvdan ancha keyin qaytarilgan',
        'other_user' => 'Sotuvchidan boshqa kishi qaytargan', 'cash_refund' => 'Naqd pul qaytarilgan', 'no_comment' => "Izoh yo'q", 'minus' => 'Minus', 'rewrite' => 'Narx tushirilib qaytarilgan', 'gap' => "Raqam bo'shlig'i",
        'wrong_category' => "Noto'g'ri kategoriya", 'unknown_type' => "Noma'lum tur",
        'time_changed' => "Chek vaqti o'zgargan", 'customer_changed' => "Mijoz o'zgargan", 'note_changed' => "Izoh o'zgargan", 'untracked' => "Ombor harakatlarida izi yo'q", 'writeoff' => 'Hisobdan chiqarish'];
}

/* ----------------------------------------------------------------- recording */
/**
 * Appends one event. Keys: type, severity (red|yellow|info), flags[], staff, ref, ref_key, amount, details, before[], after[], event_time, branch_id, shop_branch_id, backfill, dedupe_key.
 * Returns the id, or null if the same event was already stored.
 */
function shop_event_add(array $e): ?int {
    $now = shop_now(); $et = !empty($e['event_time']) ? $e['event_time'] : null; $cut = function ($s, $n) { return $s === null ? null : mb_substr((string)$s, 0, $n); };
    $sev = !empty($e['backfill']) ? 'info' : ($e['severity'] ?? 'info');
    $st = db()->prepare("INSERT OR IGNORE INTO shop_events (detected_at, event_time, event_date, branch_id, shop_branch_id, type, staff, ref, ref_key, amount, details, before_json, after_json, severity, flags, backfill, dedupe_key)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$now, $et, $et ? substr($et, 0, 10) : substr($now, 0, 10), $e['branch_id'] ?? null, $e['shop_branch_id'] ?? null, $e['type'] ?? 'system', $cut($e['staff'] ?? null, 120), $cut($e['ref'] ?? null, 80), $cut($e['ref_key'] ?? null, 80),
        isset($e['amount']) ? (float)$e['amount'] : null, $cut($e['details'] ?? null, 900), !empty($e['before']) ? json_encode($e['before'], JSON_UNESCAPED_UNICODE) : null, !empty($e['after']) ? json_encode($e['after'], JSON_UNESCAPED_UNICODE) : null,
        $sev, implode(',', array_values(array_unique((array)($e['flags'] ?? [])))), !empty($e['backfill']) ? 1 : 0, (string)$e['dedupe_key']]);
    return $st->rowCount() ? (int)db()->lastInsertId() : null;
}
function shop_unread_count(): int {
    static $n = null;
    if ($n === null) { try { $n = (int)db()->query("SELECT COUNT(*) FROM shop_events WHERE severity = 'red' AND reviewed_at IS NULL AND backfill = 0")->fetchColumn(); } catch (Throwable $e) { $n = 0; } }
    return $n;
}
function shop_review(int $id, int $userId, string $note): bool {
    $st = db()->prepare("UPDATE shop_events SET reviewed_at = ?, reviewed_by = ?, review_note = ? WHERE id = ? AND reviewed_at IS NULL");
    $st->execute([shop_now(), $userId, mb_substr(trim($note), 0, 500), $id]); return $st->rowCount() > 0;
}

/* ----------------------------------------------------------------- telegram */
function shop_alert_html(array $ev): string {
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_NOQUOTES, 'UTF-8'); };
    $b = $ev['branch_id'] ? (branch_get((int)$ev['branch_id'])['name'] ?? '') : ''; $labels = shop_flag_labels(); $fl = [];
    foreach (array_filter(explode(',', (string)$ev['flags'])) as $f) $fl[] = $labels[$f] ?? $f;
    $t = "🔴 <b>Do'konPro: " . $h(shop_type_label($ev['type'])) . "</b>\n";
    if ($b !== '') $t .= "🏢 " . $h($b) . "\n";
    if ($ev['ref']) $t .= "🧾 " . $h($ev['ref']) . "\n";
    if ($ev['staff'] !== null && $ev['staff'] !== '') $t .= "👤 " . $h($ev['staff']) . "\n";
    if ($ev['amount'] !== null) $t .= "💰 " . fmt($ev['amount']) . " so'm\n";
    if ($ev['details']) $t .= "📝 " . $h($ev['details']) . "\n";
    $t .= "🕒 " . $h(date('d.m.Y H:i', strtotime($ev['event_time'] ?: $ev['detected_at']))) . "\n";
    if ($fl) $t .= "⚠️ " . $h(implode(', ', $fl)) . "\n";
    $base = lcup_app_url(); if ($base !== '') $t .= '🔗 ' . $h($base . '/shop.php?id=' . (int)$ev['id']);
    return trim($t);
}
/** Sends one Telegram message per red event not yet alerted (max $max per call; the rest is summarised). */
function shop_alerts_flush(int $max = 10): array {
    if (shop_setting('alerts_on') !== '1' || !lcup_tg_ready()) return ['sent' => 0, 'failed' => 0, 'skipped' => true];
    $sent = 0; $fail = 0; $since = shop_state_get('alerts_since', '2000-01-01 00:00:00');
    $st = db()->prepare("SELECT * FROM shop_events WHERE severity = 'red' AND backfill = 0 AND alerted_at IS NULL AND detected_at >= ? ORDER BY id LIMIT 80"); $st->execute([$since]); $evs = $st->fetchAll();
    $mark = db()->prepare("UPDATE shop_events SET alerted_at = ? WHERE id = ? AND alerted_at IS NULL");
    foreach ($evs as $i => $ev) {
        if ($i >= $max) {
            $rest = array_slice($evs, $i); $err = null;
            $ok = lcup_tg_send("🔴 <b>Do'konPro: yana " . count($rest) . " ta shubhali hodisa</b>\nBatafsil: " . (lcup_app_url() ? lcup_app_url() . '/shop.php?status=unread' : "Argent → Do'kon nazorat"), $err);
            if ($ok) foreach ($rest as $r) $mark->execute([shop_now(), $r['id']]); $ok ? $sent++ : $fail++; break;
        }
        $err = null;
        if (lcup_tg_send(shop_alert_html($ev), $err)) { $mark->execute([shop_now(), $ev['id']]); $sent++; usleep(250000); } else { $fail++; shop_state_set('tg_last_error', $err); break; }
    }
    if ($sent && !$fail) shop_state_del('tg_last_error');
    return ['sent' => $sent, 'failed' => $fail];
}

/* ----------------------------------------------------------------- health */
function shop_health_update(bool $ok, string $msg = ''): void {
    $fails = (int)shop_state_get('fail_count', 0); shop_state_set('last_run', shop_now());
    if ($ok) {
        shop_state_set('last_success', shop_now());
        if ($fails >= 5 && shop_state_get('fail_alert_sent')) lcup_tg_send("✅ <b>Do'konPro monitoring tiklandi</b> ($fails ta muvaffaqiyatsiz urinishdan keyin).");
        shop_state_set('fail_count', 0); shop_state_del('fail_alert_sent'); shop_state_del('last_error'); return;
    }
    $fails++; shop_state_set('fail_count', $fails); shop_state_set('last_error', lcup_redact($msg));
    if ($fails === 5 || ($fails > 5 && ($fails - 5) % 60 === 0)) {
        $last = shop_state_get('last_success'); $err = null;
        if (lcup_tg_send("⚠️ <b>Do'konPro monitoring $fails marta ketma-ket ishlamadi</b>\n" . htmlspecialchars(lcup_redact($msg), ENT_NOQUOTES) . "\nOxirgi muvaffaqiyatli: " . ($last ? date('d.m.Y H:i', strtotime($last)) : 'hech qachon'), $err)) shop_state_set('fail_alert_sent', shop_now());
    }
}
function shop_minutes_since_run(): ?int { $t = shop_state_get('last_run'); return $t ? (int)floor((time() - strtotime($t)) / 60) : null; }

/* ----------------------------------------------------------------- Argent categories (shop / kitob), incl. mis-categorised "To'lov" entries */
function shop_keywords(): array { return array_values(array_filter(array_map('shop_lc', explode(',', (string)shop_setting('keywords'))))); }
/**
 * Classifies one Argent income transaction (row with cat_name, comment, id). Returns ['shop'|'kitob'|null, wrong(bool)].
 * Counts as shop/kitob when the category IS shop/kitob, OR the category is "To'lov", the entry has no LC-UP student link and the comment mentions shop/do'kon/kitob/book.
 */
function shop_classify_tx(array $t): array {
    $cat = shop_norm((string)($t['cat_name'] ?? ''));
    if ($cat === shop_norm((string)shop_setting('argent_shop_cat'))) return ['shop', false];
    if ($cat === shop_norm((string)shop_setting('argent_kitob_cat'))) return ['kitob', false];
    if ($cat !== shop_norm((string)shop_setting('argent_pay_cat')) && !lcup_is_payment_category($t['cat_name'] ?? '')) return [null, false];
    if (lcup_student_id_from_text($t['comment'] ?? null) !== null) return [null, false];
    $c = shop_lc((string)($t['comment'] ?? '')); $kit = false; $any = false;
    foreach (shop_keywords() as $k) if (mb_strpos($c, $k) !== false) { $any = true; if (in_array($k, ['kitob', 'book'], true)) $kit = true; }
    if (!$any) return [null, false];
    return [$kit ? 'kitob' : 'shop', true];
}

/* ----------------------------------------------------------------- is the shop database downloadable over HTTP? (HEAD request only: nothing is downloaded) */
function shop_http_exposure(): array {
    $path = shop_db_path(); if (!$path) return ['state' => 'unknown', 'msg' => "Do'konPro bazasi topilmadi — tekshirib bo'lmadi.", 'url' => ''];
    $doc = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/'); $real = str_replace('\\', '/', realpath($path) ?: $path);
    if ($doc === '' || strpos($real, $doc . '/') !== 0) return ['state' => 'private', 'msg' => "Baza fayli veb-papka (public_html) tashqarisida — internetdan ochilmaydi ✓", 'url' => ''];
    if (empty($_SERVER['HTTP_HOST']) || !function_exists('curl_init')) return ['state' => 'unknown', 'msg' => "Baza public_html ichida. Brauzerda sahifadan «Tekshirish» ni bosing (HTTP so'rov uchun cURL va domen kerak).", 'url' => ''];
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $url = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . implode('/', array_map('rawurlencode', explode('/', substr($real, strlen($doc)))));
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ce = curl_error($ch); curl_close($ch);
    if ($code === 0) return ['state' => 'unknown', 'msg' => "Tekshirib bo'lmadi: $ce", 'url' => $url];
    if ($code >= 200 && $code < 300) return ['state' => 'open', 'msg' => "XAVFLI: $url internetdan ochiladi (HTTP $code) — bazani hamma yuklab olishi mumkin! Quyidagi ko'rsatma bo'yicha himoyalang.", 'url' => $url];
    return ['state' => 'blocked', 'msg' => "Himoyalangan: $url → HTTP $code (yuklab bo'lmaydi) ✓", 'url' => $url];
}
