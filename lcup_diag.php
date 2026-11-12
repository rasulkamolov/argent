<?php
/**
 * Connection test + Diagnostika. Both only LOG IN and GET pages (plus the opted-in branch switch) — nothing is ever submitted.
 * Diagnostika saves the raw HTML of every page the monitor depends on (tokens and credentials removed) into
 * argent_private/diagnostics/<time>/ and zips it, so it can be sent for fixing the parsers.
 */
require_once __DIR__ . '/lcup_sync.php';

function lcup_diag_clean(string $h): string {
    $h = preg_replace('/(name="_token"\s+value=")[^"]*/i', '$1[REDACTED]', $h);
    $h = preg_replace('/(name="csrf-token"\s+content=")[^"]*/i', '$1[REDACTED]', $h);
    $h = preg_replace('/(livewire_token\s*=\s*[\'"])[^\'"]*/i', '$1[REDACTED]', $h);
    $h = preg_replace('/(&quot;checksum&quot;:&quot;)[0-9a-f]{20,}/i', '$1[REDACTED]', $h);
    return lcup_redact($h);
}
/** Human-readable check of every source. Returns [[ok(bool|null), text], ...]. Persists a discovered branch key. */
function lcup_selftest(): array {
    $out = []; $add = function ($ok, $t) use (&$out) { $out[] = [$ok, $t]; };
    $pd = private_dir();
    $add($pd !== null && is_file($pd . '/config.php'), $pd ? "Maxfiy papka: " . $pd . (path_inside_webroot($pd) ? '  ⚠ public_html ICHIDA!' : '  (public_html dan tashqarida ✓)') : "argent_private papkasi topilmadi");
    $add((string)cfg('LCUP_PHONE', '') !== '' && (string)cfg('LCUP_PASSWORD', '') !== '', "LCUP_PHONE / LCUP_PASSWORD config.php da " . (((string)cfg('LCUP_PHONE', '') !== '' && (string)cfg('LCUP_PASSWORD', '') !== '') ? "to'ldirilgan" : "TO'LDIRILMAGAN"));
    $add((string)cfg('CRON_TOKEN', '') !== '', "CRON_TOKEN " . ((string)cfg('CRON_TOKEN', '') !== '' ? "sozlangan" : "sozlanmagan (cron.php HTTP orqali ishlamaydi)"));
    $add(lcup_tg_ready(), "Telegram " . (lcup_tg_ready() ? "sozlangan" : "sozlanmagan"));
    if ((string)cfg('LCUP_PHONE', '') === '' || (string)cfg('LCUP_PASSWORD', '') === '') return $out;
    try {
        $c = LcupClient::fromConfig();
        $c->page(lcup_setting('worker_url')); $add(true, "LC-UP ga kirish ✓ (Livewire login)");
        foreach (lcup_map_rows() as $m) {
            $nm = $m['branch_name'] . ' ↔ ' . ($m['lcup_name'] ?: $m['lcup_key']);
            try {
                $html = $c->switch_branch((string)$m['lcup_key'], (string)$m['lcup_name'], lcup_setting('worker_url'));
                if ($c->resolved_key && $c->resolved_key !== (string)$m['lcup_key']) db()->prepare("UPDATE lcup_branch_map SET lcup_key = ? WHERE branch_id = ?")->execute([$c->resolved_key, $m['branch_id']]);
                $add(true, "Filial: $nm — LC-UP filiali tasdiqlandi (" . (lcup_current_branch_name($html) ?? '?') . ")");
                $w = lcup_parse_worker_reports($html, date('Y-m-d'));
                $add($w['structure'], "  Xodimlar faoliyati: " . ($w['structure'] ? count($w['rows']) . " ta amal, ochiq xodim «" . ($w['staff'] ?? '?') . "», xodimlar: " . count($w['staff_list']) : "TUZILMA TANILMADI"));
                $sg = []; foreach ($w['staff_list'] as $s) if (empty($s['selected'])) $sg[] = $s['name'] . ' (' . $s['count'] . ')'; if ($sg) $add(null, "  Faqat hisoblagich ko'rinadigan xodimlar: " . implode(', ', $sg) . " — ularning amallari tafsiloti uchun LC-UP Livewire so'rovi kerak");
                if (lcup_setting('allow_switch_post') === '1') {
                    $others = array_values(array_filter($w['staff_list'], function ($x) { return empty($x['selected']) && $x['id'] !== null; }));
                    if ($others && $w['staff_comp']) {
                        try { $pg = ['url' => $c->url(lcup_setting('worker_url')), 'html' => $html, 'comps' => []]; foreach (lcup_lw_components($html) as $x) $pg['comps'][$x['id']] = $x; $c->adopt_csrf($html);
                            $fr = $c->view_run($pg, $w['staff_comp'], [LcupClient::call('openHistory', [(string)$others[0]['id']])]); $pp = lcup_parse_worker_reports(implode("\n", $fr), date('Y-m-d'));
                            $add($pp['rows'] ? true : false, "  Xodim tanlash (Livewire openHistory): «" . $others[0]['name'] . "» → " . count($pp['rows']) . " ta amal" . ($pp['staff'] ? ", sarlavha «" . $pp['staff'] . "»" : ", sarlavha topilmadi")); }
                        catch (LcupError $e) { $add(false, "  Xodim tanlash: " . $e->getMessage()); }
                    }
                    $sid = null; foreach ($w['rows'] as $r) if ($r['student_id']) { $sid = $r['student_id']; break; }
                    if ($sid) { try { $rows = lcup_student_charges($c, $sid); $add(true, "  O'quvchi «To'lovlar» tabi (Livewire): #$sid — " . count($rows) . " ta manfiy/oylik yozuv"); } catch (LcupError $e) { $add(false, "  O'quvchi tabi: " . $e->getMessage()); } }
                } else $add(null, "  Livewire o'qish amallari o'chiq: faqat bitta xodim va filial o'qiladi");
                $dummy = ['warnings' => []];
                foreach ([['archive', lcup_setting('archive_url'), "O'chirilgan to'lovlar"], ['payments', lcup_setting('payments_url'), "To'lovlar ro'yxati (" . lcup_setting('payments_url') . ")"]] as [$kind, $url, $label]) {
                    try { $a = lcup_fetch_page($c, (int)$m['branch_id'], $url, 1, $kind, $dummy); $ids = count(array_filter(array_column($a['rows'], 'pay_id')));
                        $add($ids === count($a['rows']), "  $label: " . count($a['rows']) . " qator, to'lov ID: $ids ta" . ($a['total'] !== null ? ", jami " . fmt($a['total']) : '') . ", sahifa " . $a['page']['page']);
                        if (!$a['page']['has_next']) $add(null, "  $label: ikkinchi sahifa yo'q (hammasi bitta sahifada)");
                        else { $a2 = lcup_fetch_page($c, (int)$m['branch_id'], $url, 2, $kind, $dummy); $add($a2 !== null, "  $label: ?page=2 GET orqali " . ($a2 ? "ishlaydi ✓ (" . count($a2['rows']) . " qator)" : "ISHLAMAYDI — faqat 1-sahifa o'qiladi")); } }
                    catch (LcupError $e) { $add(false, "  $label: " . $e->getMessage()); }
                }
                foreach ([['discount_url', 'disc', 'Chegirmalar'], ['debtors_url', 'debt', 'Qarzdorlar']] as [$k, $kind, $label]) {
                    try { $rows = lcup_fetch_table_pages($c, (int)$m['branch_id'], lcup_setting($k), $kind, $dummy, microtime(true) + 20, 1); $add(true, "  $label: " . count($rows) . " qator o'qildi"); }
                    catch (LcupError $e) { $add(false, "  $label: " . $e->getMessage()); }
                }
            } catch (LcupError $e) { $add(false, "Filial: $nm — " . $e->getMessage()); }
        }
    } catch (LcupError $e) { $add(false, $e->getMessage()); }
    return $out;
}
/** Saves raw pages + a parse report. Returns ['dir' => name, 'files' => [...], 'zip' => path|null, 'lines' => [...]]. */
function lcup_diagnostics(): array {
    $pd = private_dir() ?: private_dir_target(); $name = date('Ymd_His'); $dir = $pd . '/diagnostics/' . $name;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return ['dir' => null, 'files' => [], 'zip' => null, 'lines' => ["Papka yaratib bo'lmadi: $pd/diagnostics"]];
    $files = []; $lines = [];
    $save = function (string $fn, string $content) use ($dir, &$files) { file_put_contents("$dir/$fn", $content); if (!in_array($fn, $files, true)) $files[] = $fn; };
    $info = "Argent LC-UP diagnostika " . date('c') . "\nPHP " . PHP_VERSION . "  curl=" . (function_exists('curl_init') ? 'ha' : "yo'q") . "  zip=" . (class_exists('ZipArchive') ? 'ha' : "yo'q") . "\n";
    $info .= "Sozlamalar: " . json_encode(array_intersect_key(lcup_defaults(), array_flip(['worker_url', 'archive_url', 'payments_url', 'discount_url', 'debtors_url'])) + ['allow_switch_post' => lcup_setting('allow_switch_post'), 'hot_pages' => lcup_setting('hot_pages')]) . "\n";
    $info .= "Mapping: " . json_encode(array_map(function ($m) { return [$m['branch_name'], $m['lcup_key'], $m['lcup_name']]; }, lcup_map_rows()), JSON_UNESCAPED_UNICODE) . "\n";
    try {
        $c = LcupClient::fromConfig(); $c->capture = [];
        try {
            $login = $c->get('/login'); $save('login_page.html', lcup_diag_clean($login['body']));
            $comps = lcup_lw_components($login['body']);
            $save('login_components.json', json_encode(array_map(function ($x) { return ['name' => $x['name'], 'listeners' => $x['effects']['listeners'] ?? [], 'data_keys' => array_keys($x['memo']['data'] ?? [])]; }, $comps), JSON_PRETTY_PRINT));
        } catch (LcupError $e) { $lines[] = "login sahifasi: " . $e->getMessage(); }
        try { $c->page(lcup_setting('worker_url')); $lines[] = "Login: ✓"; } catch (LcupError $e) { $lines[] = "Login: ✗ " . $e->getMessage(); }
        $save('login_exchange.json', lcup_diag_clean(json_encode($c->capture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));
        $pages = [['worker', lcup_setting('worker_url')], ['archive_p1', lcup_setting('archive_url')], ['archive_p2', lcup_setting('archive_url') . '?page=2'], ['payments_p1', lcup_setting('payments_url')],
                  ['payments_p2', lcup_setting('payments_url') . '?page=2'], ['discount', lcup_setting('discount_url')], ['debtors', lcup_setting('debtors_url')], ['dashboard', '/admin']];
        $first = true;
        foreach (lcup_map_rows() as $m) {
            $tag = 'b' . (int)$m['branch_id'];
            try {
                $html = $c->switch_branch((string)$m['lcup_key'], (string)$m['lcup_name'], lcup_setting('worker_url')); $lines[] = "{$m['branch_name']}: filial tasdiqlandi";
                $sel = lcup_find_branch_select($html, array_filter([$m['lcup_key']]), [$m['lcup_name']]); $save("{$tag}_branch_select.json", json_encode($sel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } catch (LcupError $e) { $lines[] = "{$m['branch_name']}: filial: " . $e->getMessage(); $save("{$tag}_error.txt", lcup_redact($e->getMessage())); continue; }
            foreach ($pages as [$key, $url]) {
                if ($url === '' || $url === '?page=2') continue;
                try {
                    $r = $c->page($url); $save("{$tag}_{$key}.html", lcup_diag_clean($r['body']));
                    if ($key === 'worker' && lcup_setting('allow_switch_post') === '1') {
                        $p0 = lcup_parse_worker_reports($r['body'], date('Y-m-d')); $pg = ['url' => $r['url'], 'html' => $r['body'], 'comps' => []]; foreach (lcup_lw_components($r['body']) as $x) $pg['comps'][$x['id']] = $x; $c->adopt_csrf($r['body']);
                        $save("{$tag}_components.json", json_encode(array_map(function ($x) { return ['id' => $x['id'], 'name' => $x['name'], 'listeners' => $x['effects']['listeners'] ?? [], 'data_keys' => array_keys($x['memo']['data'] ?? [])]; }, array_values($pg['comps'])), JSON_PRETTY_PRINT));
                        foreach ($p0['staff_list'] as $s0) { if (!empty($s0['selected']) || $s0['id'] === null || !$p0['staff_comp']) continue;
                            try { $fr = $c->view_run($pg, $p0['staff_comp'], [LcupClient::call('openHistory', [(string)$s0['id']])]); $save("{$tag}_staff_{$s0['id']}.html", lcup_diag_clean(implode("\n<!-- fragment -->\n", $fr))); $pp = lcup_parse_worker_reports(implode("\n", $fr), date('Y-m-d')); $lines[] = "  openHistory({$s0['id']}): fragments=" . count($fr) . " rows=" . count($pp['rows']) . " header=" . ($pp['staff'] ?? '?') . " more=" . ($pp['more_left'] ?? 0); }
                            catch (LcupError $e) { $lines[] = "  openHistory({$s0['id']}): " . $e->getMessage(); } break; }
                        $sid0 = null; foreach ($p0['rows'] as $rr) if ($rr['student_id']) { $sid0 = $rr['student_id']; break; }
                        if ($sid0) { try { $sp = $c->view_page('/admin/students/' . $sid0); $fr = $c->view_emit($sp, 'studentTolovlarOchilsin', [(string)$sid0]); $save("{$tag}_student_{$sid0}_page.html", lcup_diag_clean($sp['html'])); $save("{$tag}_student_{$sid0}_payments_tab.html", lcup_diag_clean(implode("\n<!-- fragment -->\n", $fr))); $sx = lcup_parse_student_payments(implode("\n", $fr)); $lines[] = "  student tab #$sid0: fragments=" . count($fr) . " structure=" . ($sx['structure'] ? 'ha' : "YO'Q") . " rows=" . count($sx['rows']) . " headers=" . implode('/', $sx['headers']); }
                            catch (LcupError $e) { $lines[] = "  student tab #$sid0: " . $e->getMessage(); } }
                    }
                    if ($key === 'worker') {
                        $links = lcup_nav_links($r['body']); $save("{$tag}_links.txt", implode("\n", array_map(function ($l) { return $l[0] . ' -> ' . $l[1]; }, $links)));
                        $p = lcup_parse_worker_reports($r['body'], date('Y-m-d')); $lines[] = "  worker_reports: strategy={$p['strategy']} rows=" . count($p['rows']) . " staff=" . ($p['staff'] ?? '?');
                    } elseif (strpos($key, 'archive') === 0 || strpos($key, 'payments') === 0) {
                        $a = strpos($key, 'archive') === 0 ? lcup_parse_archive($r['body']) : lcup_parse_payments($r['body']);
                        $lines[] = "  $key: structure=" . ($a['structure'] ? 'ha' : "YO'Q") . " rows=" . count($a['rows']) . " page=" . $a['page']['page'] . " ids=" . count(array_filter(array_column($a['rows'], 'pay_id'))) . " headers=" . implode('/', $a['headers']);
                    } elseif ($key === 'discount') { $a = lcup_parse_discounts($r['body']); $lines[] = "  discount: structure=" . ($a['structure'] ? 'ha' : "YO'Q") . " rows=" . count($a['rows']); }
                    elseif ($key === 'debtors') { $a = lcup_parse_debtors($r['body']); $lines[] = "  debtors: structure=" . ($a['structure'] ? 'ha' : "YO'Q") . " rows=" . count($a['rows']); }
                } catch (LcupError $e) { $lines[] = "  $key: " . $e->getMessage(); $save("{$tag}_{$key}_error.txt", lcup_redact($e->getMessage())); }
                if ($key === 'archive_p1') { try { $r2 = $c->page(lcup_setting('archive_url'), ['page' => 2]); $save("{$tag}_archive_p2.html", lcup_diag_clean($r2['body'])); $pi = lc_page_info(lc_dom($r2['body'])); $lines[] = "  archive ?page=2 → LC-UP active page = " . $pi['page']; } catch (LcupError $e) {} }
                if ($key === 'payments_p1' && lcup_setting('payments_url') !== '') { try { $r2 = $c->page(lcup_setting('payments_url'), ['page' => 2]); $save("{$tag}_payments_p2.html", lcup_diag_clean($r2['body'])); $pi = lc_page_info(lc_dom($r2['body'])); $lines[] = "  payments ?page=2 → LC-UP active page = " . $pi['page']; } catch (LcupError $e) {} }
            }
        }
    } catch (LcupError $e) { $lines[] = "XATO: " . $e->getMessage(); }
    $save('exchanges.json', lcup_diag_clean(json_encode(isset($c) ? $c->capture : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));
    $rl = db()->query("SELECT at, method, purpose, path, detail, status FROM lcup_reqlog ORDER BY id DESC LIMIT 80")->fetchAll(); $save('request_log.txt', lcup_redact(implode("\n", array_map(function ($q) { return implode(' ', $q); }, array_reverse($rl)))));
    $info .= "So'rovlar (usul yo'l holat):\n"; foreach (($c->trace ?? []) as $t) $info .= "  {$t[0]} {$t[1]} {$t[2]}\n";
    $save('00_info.txt', $info . "\n" . implode("\n", $lines) . "\n");
    $zip = null;
    if (class_exists('ZipArchive')) { $z = new ZipArchive(); $zp = $pd . '/diagnostics/' . $name . '.zip'; if ($z->open($zp, ZipArchive::CREATE) === true) { foreach ($files as $f) $z->addFile("$dir/$f", $f); $z->close(); $zip = $zp; } }
    return ['dir' => $name, 'files' => $files, 'zip' => $zip, 'lines' => $lines];
}
