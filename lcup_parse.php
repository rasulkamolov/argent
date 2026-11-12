<?php
/**
 * Defensive HTML parsers for LC-UP pages. They never trust column ORDER: columns are found by header text
 * (SANA, FISH, NARX, TO'LOV USULI, ...). Every parser returns ['structure' => bool, ...]: structure=false means
 * "this page does not look like what I expected" and the caller must treat that as an error, not as "no data".
 */

/* ------------------------------------------------------------------ text helpers */
function lc_apos(string $s): string { return str_replace(["ʻ", "ʼ", "’", "‘", "`", "´", "′"], "'", $s); }
function lc_clean(string $s): string { return trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $s))); }
function lc_norm(string $s): string { return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper(lc_apos($s))); }
function lc_text(?DOMNode $n): string { return $n ? lc_clean($n->textContent) : ''; }
function lc_dom(string $html): DOMDocument {
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
    libxml_clear_errors();
    $xp = new DOMXPath($d);
    foreach (iterator_to_array($xp->query('//script|//style|//noscript|//template')) as $n) $n->parentNode->removeChild($n);
    return $d;
}
/** "1 500 000", "−250 000 so'm", "1,500,000.00", "1500000" -> float, null when there is no number. */
function lc_money(?string $s): ?float {
    if ($s === null) return null;
    $s = lc_clean(str_replace(['−', '–', '—'], '-', lc_apos($s)));
    if (!preg_match('/-?\s*\d[\d\s.,]*/u', $s, $m)) return null;
    $n = str_replace(' ', '', $m[0]);
    $neg = strpos($n, '-') !== false; $n = ltrim($n, '-');
    if (strpos($n, ',') !== false && strpos($n, '.') !== false) { $n = strrpos($n, ',') > strrpos($n, '.') ? str_replace('.', '', $n) : str_replace(',', '', $n); $n = str_replace(',', '.', $n); }
    elseif (substr_count($n, ',') > 1 || preg_match('/,\d{3}$/', $n)) $n = str_replace(',', '', $n);
    elseif (substr_count($n, '.') > 1 || preg_match('/\.\d{3}$/', $n)) $n = str_replace('.', '', $n);
    else $n = str_replace(',', '.', $n);
    $n = rtrim($n, '.');
    if ($n === '' || !is_numeric($n)) return null;
    return $neg ? -(float)$n : (float)$n;
}
/** Date-time in several shapes -> 'Y-m-d H:i:s'; time-only needs $defaultDate. */
function lc_dt(?string $s, ?string $defaultDate = null): ?string {
    if ($s === null) return null; $s = lc_clean($s);
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?/', $s, $m)) return sprintf('%s-%s-%s %02d:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
    if (preg_match('/(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})[ ,]+(\d{1,2}):(\d{2})(?::(\d{2}))?/', $s, $m)) return sprintf('%s-%02d-%02d %02d:%s:%s', $m[3], $m[2], $m[1], $m[4], $m[5], $m[6] ?? '00');
    if ($defaultDate && preg_match('/(?<![\d:])([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?(?![\d:])/', $s, $m)) return sprintf('%s %02d:%s:%s', $defaultDate, $m[1], $m[2], $m[3] ?? '00');
    return null;
}
function lc_date(?string $s): ?string {
    if ($s === null) return null; $s = lc_clean($s);
    static $mon = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    if (preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})/i', $s, $m)) return sprintf('%s-%02d-%02d', $m[3], $mon[strtolower($m[1])], $m[2]);
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/', $s, $m)) return sprintf('%s-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}
function lc_method(?string $s): ?string {
    $t = mb_strtolower(lc_apos((string)$s)); if (trim($t) === '') return null;
    if (strpos($t, 'naqd') !== false || strpos($t, 'cash') !== false) return 'cash';
    if (preg_match('/plastik|karta|click|payme|uzcard|humo|terminal|card/u', $t)) return 'card';
    if (preg_match('/bank|hisob|o\'tkazma|perechislen/u', $t)) return 'bank';
    return 'other';
}
function lc_is_monthly(?string $status): bool { return (bool)preg_match("/oylik/iu", lc_apos((string)$status)); }

/* ------------------------------------------------------------------ DOM helpers */
function lc_closest(DOMNode $n, string $tag): ?DOMNode { for ($p = $n->parentNode; $p; $p = $p->parentNode) if ($p instanceof DOMElement && strtolower($p->tagName) === $tag) return $p; return null; }
function lc_attr(?DOMNode $n, string $a): string { return ($n instanceof DOMElement && $n->hasAttribute($a)) ? $n->getAttribute($a) : ''; }
/** [id, name] of the first /students/{id} link inside $n. */
function lc_student_link(DOMNode $n): array {
    $xp = new DOMXPath($n->ownerDocument);
    foreach ($xp->query('.//a[@href]', $n) as $a) {
        if (preg_match('~/students?/(\d+)~', $a->getAttribute('href'), $m)) return [$m[1], lc_text($a)];
    }
    return [null, ''];
}
/** All tables: ['headers' => normalized, 'raw_headers' => text, 'rows' => [['texts','cells','tr']]] */
function lc_tables(DOMDocument $d): array {
    $out = []; $xp = new DOMXPath($d);
    foreach ($d->getElementsByTagName('table') as $t) {
        $heads = []; $raw = []; $rows = []; $headRow = null;
        foreach ($xp->query('.//tr', $t) as $tr) {
            if (lc_closest($tr, 'table') !== $t) continue;
            $cells = []; foreach ($tr->childNodes as $c) if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['td', 'th'], true)) $cells[] = $c;
            if (!$cells) continue;
            $allTh = true; foreach ($cells as $c) if (strtolower($c->tagName) !== 'th') $allTh = false;
            if ($headRow === null && $allTh) { $headRow = $tr; foreach ($cells as $c) { $raw[] = lc_text($c); $heads[] = lc_norm(lc_text($c)); } continue; }
            $texts = []; foreach ($cells as $c) $texts[] = lc_text($c);
            $rows[] = ['texts' => $texts, 'cells' => $cells, 'tr' => $tr];
        }
        if ($headRow === null && $rows) {            // no <th>: the first row may still be a header
            $first = $rows[0]['texts']; $hit = 0; foreach ($first as $x) if (preg_match('/^[A-ZА-Я\'‘’ʻʼ\s.]{3,}$/u', $x)) $hit++;
            if ($hit >= max(2, count($first) - 1)) { $raw = $first; foreach ($first as $x) $heads[] = lc_norm($x); array_shift($rows); }
        }
        $out[] = ['headers' => $heads, 'raw_headers' => $raw, 'rows' => $rows];
    }
    return $out;
}
/** field => column index. Exact header match first, then "contains"; a column belongs to one field only. */
function lc_colmap(array $headers, array $aliases): array {
    $map = []; $used = [];
    foreach ($aliases as $field => $list) foreach ($headers as $i => $h) { if (isset($used[$i])) continue; if ($h !== '' && in_array($h, $list, true)) { $map[$field] = $i; $used[$i] = true; break; } }
    foreach ($aliases as $field => $list) {
        if (isset($map[$field])) continue;
        foreach ($headers as $i => $h) { if (isset($used[$i]) || $h === '') continue; foreach ($list as $a) if (strlen($a) >= 4 && strpos($h, $a) !== false) { $map[$field] = $i; $used[$i] = true; break 2; } }
    }
    return $map;
}
/** Best table for an alias map: needs all $required fields; returns [table, map] or null. */
function lc_pick_table(DOMDocument $d, array $aliases, array $required): ?array {
    $best = null; $bs = 0;
    foreach (lc_tables($d) as $t) {
        if (!$t['headers']) continue;
        $map = lc_colmap($t['headers'], $aliases);
        foreach ($required as $r) if (!isset($map[$r])) continue 2;
        if (count($map) > $bs) { $bs = count($map); $best = [$t, $map]; }
    }
    return $best;
}
function lc_cell(array $row, array $map, string $f): string { return isset($map[$f]) ? ($row['texts'][$map[$f]] ?? '') : ''; }
/**
 * LC-UP payment id per row. Looks at every attribute of the row and its children for an id passed to a handler
 * (onclick="deleteTrashed(10564667)", wire:click="edit(55)") or used in a link (/tolov/55/edit). The first "pattern"
 * that gives a DIFFERENT id for every row is used (a student id repeated on each row is therefore never taken).
 */
function lc_row_ids(array $rows): array {
    $n = count($rows); $bySig = [];
    foreach ($rows as $i => $r) {
        $tr = $r['tr']; $nodes = [$tr]; foreach ($tr->getElementsByTagName('*') as $c) $nodes[] = $c;
        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) continue;
            foreach ($node->attributes as $at) {
                $v = $at->value; $sig = null; $id = null;
                if (preg_match('/([A-Za-z_]\w*)\(\s*[\'"]?(\d{3,})[\'"]?\s*[,)]/', $v, $m)) { $sig = $at->name . ':' . $m[1]; $id = $m[2]; }
                elseif (in_array($at->name, ['data-id', 'data-payment-id', 'data-tolov-id', 'wire:key'], true) && preg_match('/(\d{3,})$/', $v, $m)) { $sig = $at->name; $id = $m[1]; }
                elseif ($at->name === 'href' && preg_match('~/(?:tolov|payment|pay)[a-z]*/(\d+)~i', $v, $m)) { $sig = 'href:tolov'; $id = $m[1]; }
                if ($sig !== null && !isset($bySig[$sig][$i])) $bySig[$sig][$i] = $id;
            }
        }
    }
    foreach ($bySig as $sig => $ids) if (count($ids) === $n && count(array_unique($ids)) === $n) { ksort($ids); return array_values($ids); }
    return array_fill(0, $n, null);
}
/** Livewire/Laravel paginator: ['page' => active page, 'has_next' => bool, 'max_seen' => highest page number visible] */
function lc_page_info(DOMDocument $d): array {
    $xp = new DOMXPath($d); $page = 1; $max = 1; $next = false;
    foreach ($xp->query('//ul[contains(@class,"pagination")]//li') as $li) {
        $cls = ' ' . lc_attr($li, 'class') . ' '; $t = lc_text($li);
        if (ctype_digit($t)) { $max = max($max, (int)$t); if (strpos($cls, ' active ') !== false) $page = (int)$t; }
        if (strpos($cls, ' disabled ') === false && (strpos($li->ownerDocument->saveHTML($li), 'nextPage') !== false || strpos($li->ownerDocument->saveHTML($li), 'rel="next"') !== false)) $next = true;
    }
    return ['page' => $page, 'has_next' => $next, 'max_seen' => $max];
}
/** Pagination: highest ?page=N seen in links. */
function lc_max_page(DOMDocument $d): int {
    $max = 1; $xp = new DOMXPath($d);
    foreach ($xp->query('//a[@href]') as $a) if (preg_match('/[?&]page=(\d+)/', $a->getAttribute('href'), $m)) $max = max($max, (int)$m[1]);
    return $max;
}
function lc_empty_state(DOMDocument $d): bool {
    return (bool)preg_match("/(ma'lumot(lar)? (topilmadi|yo'q|mavjud emas)|topilmadi|hech narsa|bo'sh|no data|no records)/iu", lc_apos(lc_text($d->documentElement)));
}

/* ------------------------------------------------------------------ event text classification (worker_reports) */
/** @return array [type, payment_related] */
function lcup_classify_event(string $text): array {
    $t = mb_strtolower(lc_apos($text));
    $pay = (bool)preg_match("/to'?lov/u", $t);
    if (preg_match("/muzlat.*pul|pul.*yechib/u", $t)) return ['freeze_charge', true];
    if ($pay && preg_match("/(o'chirildi|o'chirib|bekor qilindi|ochirildi)/u", $t)) return ['log_delete', true];
    if ($pay && preg_match("/(tahrirlandi|o'zgartirildi|o'zgardi|yangilandi)/u", $t)) return ['log_edit', true];
    if ($pay && preg_match("/(yaratildi|qo'shildi|qabul qilindi|kiritildi)/u", $t)) return ['payment_created', true];
    if (strpos($t, 'chegirma') !== false) return ['payment_other', true];
    if (strpos($t, 'qarz') !== false) return ['payment_other', true];
    if (preg_match("/muzlatildi|muzlatish/u", $t)) return ['student_frozen', true];
    if (preg_match("/aktivlashtirildi|aktivlashtirish|aktivlandi/u", $t)) return ['student_activated', true];
    if ($pay || preg_match("/pul |summa|narx|balans/u", $t)) return ['payment_other', true];
    return ['other', false];
}

/* ------------------------------------------------------------------ worker_reports ("Xodimlar faoliyati") */
/** wire:id of $n or of its nearest ancestor that has one. */
function lc_wire_id(?DOMNode $n): ?string { for (; $n; $n = $n->parentNode) if ($n instanceof DOMElement && $n->hasAttribute('wire:id')) return $n->getAttribute('wire:id'); return null; }
function lc_cx(string $c): string { return 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")'; }
/**
 * Real layout (checked against a saved page): a staff panel on the left (.xf-s-item, the selected one has .xf-on) and,
 * for the SELECTED staff member only, day groups (.xf-day wire:key="xf-day-YYYY-MM-DD") with rows
 * (.xf-row wire:key="xf-row-<activity id>") holding .xf-rt (text), .xf-link (student), .xf-amt, .xf-gp (group), .xf-time.
 */
function lcup_parse_worker_reports(string $html, string $today): array {
    $d = lc_dom($html); $xp = new DOMXPath($d);
    $res = ['structure' => false, 'strategy' => 'none', 'staff' => null, 'staff_list' => [], 'rows' => [], 'day_totals' => [], 'staff_comp' => null, 'log_comp' => null, 'more_comp' => null, 'more_left' => 0];
    foreach ($xp->query('//*[' . lc_cx('xf-s-item') . ']') as $b) {
        if ($res['staff_comp'] === null) $res['staff_comp'] = lc_wire_id($b);
        $oc = lc_attr($b, 'wire:click'); $id = preg_match('/openHistory\(\s*[\'"]?(\d+)/', $oc, $m) ? $m[1] : null;
        $nm = $xp->query('.//*[' . lc_cx('xf-s-name') . ']', $b)->item(0); $cn = $xp->query('.//*[' . lc_cx('xf-s-cnt') . ']', $b)->item(0);
        $sel = strpos(' ' . lc_attr($b, 'class') . ' ', ' xf-on ') !== false;
        $res['staff_list'][] = ['id' => $id, 'name' => lc_text($nm), 'count' => (int)preg_replace('/\D+/', '', lc_text($cn)), 'selected' => $sel];
        if ($sel) $res['staff'] = lc_text($nm);
    }
    if ($res['staff'] === null && ($h = $xp->query('//*[' . lc_cx('xf-who') . ']//h1')->item(0))) $res['staff'] = lc_text($h);
    $chrome = $xp->query('//*[' . lc_cx('xf-wrap') . ' or ' . lc_cx('xf-log') . ']')->length > 0;
    if (($lg = $xp->query('//*[' . lc_cx('xf-log') . ']')->item(0))) $res['log_comp'] = lc_wire_id($lg);
    if (($mb = $xp->query('//*[' . lc_cx('xf-more') . ']')->item(0))) { $res['more_comp'] = lc_wire_id($mb); $res['more_left'] = (int)preg_replace('/\D+/', '', lc_text($mb)); }
    foreach ($xp->query('//*[' . lc_cx('xf-day') . ']') as $day) {
        $date = preg_match('/xf-day-(\d{4}-\d{2}-\d{2})/', lc_attr($day, 'wire:key'), $m) ? $m[1] : $today;
        if (($dc = $xp->query('.//*[' . lc_cx('xf-dc') . ']', $day)->item(0))) $res['day_totals'][$date] = (int)preg_replace('/\D+/', '', lc_text($dc));
        foreach ($xp->query('.//*[' . lc_cx('xf-row') . ']', $day) as $row) {
            if (!preg_match('/xf-row-(\d+)/', lc_attr($row, 'wire:key'), $m)) continue;
            $q = function ($c) use ($xp, $row) { return $xp->query('.//*[' . lc_cx($c) . ']', $row)->item(0); };
            $text = lc_text($q('xf-rt')); if ($text === '') continue;
            $tm = lc_text($q('xf-time')); $sid = $sname = $gid = $gname = null;
            if (($l = $q('xf-link')) && preg_match('~/students/(\d+)~', lc_attr($l, 'href'), $mm)) { $sid = $mm[1]; $sname = lc_text($l); }
            if (($g = $q('xf-gp')) && preg_match('~/groups/(\d+)~', lc_attr($g, 'href'), $mm)) { $gid = $mm[1]; $gname = lc_text($g); }
            $amt = ($a = $q('xf-amt')) ? lc_money(lc_text($a)) : null;
            if ($amt !== null && ($a && strpos(lc_text($a), '-') !== false || strpos(lc_text($a), '−') !== false)) $amt = -abs($amt);
            $rm = lc_text($q('xf-rm'));
            if ($amt === null && preg_match("/([−\-+])\s*(-?[\d][\d\s.,]*)\s*so'?m/u", lc_apos($rm), $mm)) { $amt = lc_money($mm[2]); if ($amt !== null && $mm[1] !== '+') $amt = -abs($amt) * ($mm[2][0] === '-' ? -1 : 1); }
            $res['rows'][] = ['act_id' => $m[1], 'date' => $date, 'time' => lc_dt($tm, $date), 'text' => $text, 'student_id' => $sid, 'student_name' => $sname,
                              'group_id' => $gid, 'group_name' => $gname, 'amount' => $amt, 'raw' => lc_clean($text . ' ' . $rm . ' ' . $tm)];
        }
    }
    if ($res['rows']) { $res['structure'] = true; $res['strategy'] = 'xf'; }
    elseif ($chrome) { $res['structure'] = true; $res['strategy'] = 'empty'; }
    return $res;
}

/* ------------------------------------------------------------------ payment lists: /admin/tolovlars (live) and the archive */
function lcup_payment_aliases(): array {
    return ['date' => ['SANA'], 'student' => ['FISH', 'TALABA', 'OQUVCHI'], 'amount' => ['NARX', 'SUMMA', 'MIQDOR'], 'method' => ['TOLOVUSULI', 'USULI'],
            'teacher' => ['OQITUVCHI', 'USTOZ'], 'group' => ['GURUH'], 'staff' => ['HODIM', 'XODIM'], 'created' => ['YARATILGANSANA', 'YARATILGAN', 'YARATILGANVAQT', 'KIRITILGANSANA'],
            'deleted' => ['OCHIRILGANSANA', 'OCHIRILGAN', 'OCHIRILGANVAQT'], 'reason' => ['IZOH', 'SABAB'], 'status' => ['HOLATI', 'HOLAT', 'TURI', 'TOLOVTURI']];
}
function lcup_parse_payment_rows(DOMDocument $d, array $required): array {
    $res = ['structure' => false, 'rows' => [], 'page' => lc_page_info($d), 'total' => null, 'headers' => []];
    $pick = lc_pick_table($d, lcup_payment_aliases(), $required);
    if (!$pick) return $res;                          // no table with the expected headers => NOT "empty", it is an unknown layout
    [$t, $map] = $pick; $res['structure'] = true; $res['headers'] = $t['raw_headers'];
    if (preg_match("/jami\s*:?\s*(-?[\d][\d\s.,]*)\s*so'?m/iu", lc_apos(lc_text($d->documentElement)), $m)) $res['total'] = lc_money($m[1]);
    $ids = lc_row_ids($t['rows']);
    foreach ($t['rows'] as $i => $r) {
        $amt = lc_money(lc_cell($r, $map, 'amount')); if ($amt === null) continue;
        [$sid, $sname] = lc_student_link($r['tr']);
        $staffCell = lc_cell($r, $map, 'staff');
        $created = lc_dt(lc_cell($r, $map, 'created')) ?: lc_dt($staffCell);
        $staff = lc_clean(preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}(:\d{2})?|\d{1,2}[.\/]\d{1,2}[.\/]\d{4}[ ,]+\d{1,2}:\d{2}(:\d{2})?/', '', $staffCell));
        $res['rows'][] = ['pay_id' => $ids[$i], 'date' => lc_date(lc_cell($r, $map, 'date')), 'student_id' => $sid, 'student_name' => lc_cell($r, $map, 'student') ?: $sname, 'amount' => $amt,
                          'method' => lc_method(lc_cell($r, $map, 'method')), 'teacher' => lc_cell($r, $map, 'teacher'), 'group' => lc_cell($r, $map, 'group'), 'staff' => $staff,
                          'created_at' => $created, 'status' => lc_cell($r, $map, 'status') ?: null, 'deleted_at' => lc_dt(lc_cell($r, $map, 'deleted'), date('Y-m-d')),
                          'reason' => lc_cell($r, $map, 'reason'), 'raw' => implode(' | ', $r['texts'])];
    }
    return $res;
}
/** Live payment list (/admin/tolovlars). Same table family as the archive, without the deleted columns. */
function lcup_parse_payments(string $html): array { return lcup_parse_payment_rows(lc_dom($html), ['amount', 'student']); }
/** The student's own "To'lovlar" tab: no student column, so only the amount column is required (plus at least 3 recognised columns). */
function lcup_parse_student_payments(string $html): array {
    $r = lcup_parse_payment_rows(lc_dom($html), ['amount']);
    if ($r['structure'] && count($r['headers']) < 3) $r['structure'] = false;
    return $r;
}
/** Archive of deleted payments: row actions carry the payment id (deleteTrashed(10564667)). */
function lcup_parse_archive(string $html): array { return lcup_parse_payment_rows(lc_dom($html), ['amount', 'deleted']); }

/* ------------------------------------------------------------------ students list / discounts / debtors */
function lcup_parse_students_list(string $html): array {
    $d = lc_dom($html); $xp = new DOMXPath($d); $found = [];
    foreach ($xp->query('//a[@href]') as $a) {
        if (!preg_match('~/students/(\d+)(?:[/?#]|$)~', $a->getAttribute('href'), $m)) continue;
        $name = lc_text($a); if ($name === '' || preg_match('/^[\d\s]+$/', $name)) $name = '';
        if (!isset($found[$m[1]]) || mb_strlen($name) > mb_strlen($found[$m[1]])) $found[$m[1]] = $name;
    }
    $out = []; foreach ($found as $id => $n) $out[] = ['id' => (string)$id, 'name' => $n];
    return ['structure' => (bool)$out, 'students' => $out, 'max_page' => lc_max_page($d)];
}
function lcup_parse_discounts(string $html): array {
    $d = lc_dom($html);
    $al = ['student' => ['FISH', 'TALABA', 'OQUVCHI', 'ISM'], 'group' => ['GURUH'], 'value' => ['CHEGIRMA', 'CHEGIRMASUMMASI', 'FOIZ', 'SUMMA', 'MIQDOR'], 'reason' => ['SABAB', 'IZOH', 'TURI'], 'staff' => ['HODIM', 'XODIM'], 'date' => ['SANA', 'BERILGANSANA']];
    $res = ['structure' => false, 'rows' => [], 'max_page' => lc_max_page($d)];
    $pick = lc_pick_table($d, $al, ['student', 'value']);
    if (!$pick) return $res;
    [$t, $map] = $pick; $res['structure'] = true; $seen = [];
    foreach ($t['rows'] as $r) {
        [$sid, $sname] = lc_student_link($r['tr']); $name = $sname ?: lc_cell($r, $map, 'student'); if ($name === '' && !$sid) continue;
        $val = lc_cell($r, $map, 'value'); $grp = lc_cell($r, $map, 'group');
        $key = ($sid ?: 'n:' . lc_norm($name)) . '|' . lc_norm($grp); $seen[$key] = ($seen[$key] ?? 0) + 1; if ($seen[$key] > 1) $key .= '#' . $seen[$key];
        $res['rows'][] = ['key' => $key, 'student_id' => $sid, 'student_name' => $name, 'group' => $grp, 'value' => $val, 'num' => lc_money($val) ?? 0.0, 'reason' => lc_cell($r, $map, 'reason'), 'staff' => lc_cell($r, $map, 'staff')];
    }
    return $res;
}
function lcup_parse_debtors(string $html): array {
    $d = lc_dom($html);
    $al = ['student' => ['FISH', 'TALABA', 'OQUVCHI', 'ISM'], 'debt' => ['QARZ', 'QARZDORLIK', 'QARZSUMMASI', 'JAMIQARZ', 'BALANS', 'SUMMA', 'MIQDOR'], 'group' => ['GURUH']];
    $res = ['structure' => false, 'rows' => [], 'max_page' => lc_max_page($d)];
    $pick = lc_pick_table($d, $al, ['student', 'debt']);
    if (!$pick) return $res;
    [$t, $map] = $pick; $res['structure'] = true;
    foreach ($t['rows'] as $r) {
        [$sid, $sname] = lc_student_link($r['tr']); $name = $sname ?: lc_cell($r, $map, 'student'); $debt = lc_money(lc_cell($r, $map, 'debt'));
        if (($name === '' && !$sid) || $debt === null) continue;
        $res['rows'][] = ['key' => $sid ?: 'n:' . lc_norm($name), 'student_id' => $sid, 'student_name' => $name, 'group' => lc_cell($r, $map, 'group'), 'debt' => abs($debt)];
    }
    return $res;
}

/* ------------------------------------------------------------------ navigation + branch dropdown discovery */
/** [[text, href], ...] of every real link on the page (used to find report URLs and for Diagnostika). */
function lcup_nav_links(string $html): array {
    $d = lc_dom($html); $xp = new DOMXPath($d); $out = [];
    foreach ($xp->query('//a[@href]') as $a) {
        $h = trim($a->getAttribute('href')); if ($h === '' || $h[0] === '#' || stripos($h, 'javascript:') === 0) continue;
        $out[] = [lc_text($a), $h];
    }
    return $out;
}
function lcup_find_link(array $links, string $textRegex): ?string {
    foreach ($links as [$t, $h]) if (preg_match($textRegex, mb_strtolower(lc_apos($t)))) return $h;
    return null;
}
/** Livewire v2 components on a page: [['id','name','fingerprint','memo','effects']]. */
function lcup_lw_components(string $html): array {
    $d = lc_dom($html); $xp = new DOMXPath($d); $out = [];
    foreach ($xp->query('//*[@*[name()="wire:id"] and @*[name()="wire:initial-data"]]') as $n) {
        $j = json_decode($n->getAttribute('wire:initial-data'), true);
        if (!is_array($j) || empty($j['fingerprint'])) continue;
        $out[] = ['id' => $n->getAttribute('wire:id'), 'name' => $j['fingerprint']['name'] ?? '', 'fingerprint' => $j['fingerprint'], 'memo' => $j['serverMemo'] ?? [], 'effects' => $j['effects'] ?? []];
    }
    return $out;
}
function lcup_csrf_from(string $html): string {
    if (preg_match('/<input[^>]+name="_token"[^>]+value="([^"]+)"/i', $html, $m)) return html_entity_decode($m[1]);
    if (preg_match('/<meta[^>]+name="csrf-token"[^>]+content="([^"]+)"/i', $html, $m)) return html_entity_decode($m[1]);
    if (preg_match('/livewire_token\s*=\s*[\'"]([^\'"]+)/i', $html, $m)) return $m[1];
    return '';
}
/**
 * Finds the "which branch" dropdown. $keys = LC-UP values we know (414), $names = LC-UP branch names.
 * @return array|null ['mode','options'=>[[value,label,selected]],'current','prop','method','lw_id','form_action','form_method','select_name','url_prefix','links']
 */
function lcup_find_branch_select(string $html, array $keys, array $names): ?array {
    $d = lc_dom($html); $xp = new DOMXPath($d); $normNames = array_map('lc_norm', array_filter($names)); $best = null; $bs = 0;
    foreach ($xp->query('//select') as $s) {
        $opts = []; $score = 0; $cur = null; $first = null;
        foreach ($xp->query('.//option', $s) as $o) {
            $v = $o->getAttribute('value'); $l = lc_text($o); $sel = $o->hasAttribute('selected');
            $opts[] = ['value' => $v, 'label' => $l, 'selected' => $sel]; if ($sel) $cur = $v; if ($first === null && $v !== '') $first = $v;
            if (in_array($v, array_map('strval', $keys), true) || in_array(lc_norm($l), $normNames, true)) $score++;
        }
        $isFilial = false;                                   // <select wire:model="filial_id" wire:change="change_filial">
        foreach ($s->attributes as $a) if (strpos($a->name, 'wire:') === 0 && preg_match('/filial|branch/i', $a->value)) $isFilial = true;
        if (!$opts || ($score === 0 && !$isFilial)) continue;
        $score += $isFilial ? 5 : 0;
        if ($score <= $bs) continue;
        $bs = $score;
        $info = ['options' => $opts, 'current' => $cur ?? $first, 'select_name' => $s->getAttribute('name'), 'mode' => 'unknown', 'prop' => null, 'method' => null, 'lw_id' => null, 'form_action' => null, 'form_method' => null, 'url_prefix' => null];
        foreach ($s->attributes as $a) {
            if (strpos($a->name, 'wire:model') === 0) $info['prop'] = $a->value;
            if ($a->name === 'wire:change' && preg_match('/^\s*([A-Za-z_]\w*)\s*(\(|$)/', $a->value, $m)) $info['method'] = $m[1];
            if ($a->name === 'wire:id') $info['lw_id'] = $a->value;
            if ($a->name === 'wire:focus' && preg_match('/^\s*([A-Za-z_]\w*)/', $a->value, $m)) $info['focus_method'] = $m[1];
        }
        for ($p = $s->parentNode; $p; $p = $p->parentNode) {
            if (!($p instanceof DOMElement)) continue;
            if ($info['lw_id'] === null && $p->hasAttribute('wire:id')) $info['lw_id'] = $p->getAttribute('wire:id');
            if ($info['form_method'] === null && strtolower($p->tagName) === 'form') { $info['form_method'] = strtolower($p->getAttribute('method') ?: 'get'); $info['form_action'] = $p->getAttribute('action'); }
        }
        $oc = $s->getAttribute('onchange');
        if ($oc !== '' && preg_match('/(?:location(?:\.href)?\s*=|location\.assign\()\s*([\'"])([^\'"]*)\1\s*\+\s*(?:this|event\.target)\.value/', $oc, $m)) { $info['mode'] = 'onchange_url'; $info['url_prefix'] = $m[2]; }
        elseif ($info['lw_id'] && $info['method']) $info['mode'] = 'livewire_change';
        elseif ($info['lw_id'] && $info['prop']) $info['mode'] = 'livewire_model';
        elseif ($info['form_method'] === 'get' || ($info['form_method'] === null && $oc !== '' && stripos($oc, 'submit') !== false)) $info['mode'] = 'form_get';
        elseif ($info['form_method'] === 'post') $info['mode'] = 'form_post';
        $best = $info;
    }
    if ($best) { $nb = $xp->query('//*[@id="navbarfilialname"]')->item(0); $best['current_name'] = $nb ? lc_text($nb) : null; return $best; }
    // dropdown made of links:  <a href=".../414">Oxford LC</a>
    $links = [];
    foreach ($xp->query('//a[@href]') as $a) {
        $h = $a->getAttribute('href'); $t = lc_norm(lc_text($a));
        foreach ($keys as $k) if ($k !== '' && preg_match('~(?:/|=)' . preg_quote((string)$k, '~') . '(?:[/?&#]|$)~', $h)) $links[(string)$k] = $h;
        foreach ($names as $n) if ($n !== '' && $t === lc_norm($n) && strpos($h, '#') !== 0 && stripos($h, 'javascript') !== 0) $links['name:' . $n] = $h;
    }
    return $links ? ['mode' => 'links', 'links' => $links, 'options' => [], 'current' => null, 'current_name' => null] : null;
}
/** Name of the branch LC-UP currently shows in its top bar (button #navbarfilialname). */
function lcup_current_branch_name(string $html): ?string {
    $d = lc_dom($html); $xp = new DOMXPath($d); $nb = $xp->query('//*[@id="navbarfilialname"]')->item(0);
    return $nb ? lc_text($nb) : null;
}
/** Which branch is selected right now (value), from the same dropdown. null = cannot tell. */
function lcup_current_branch(string $html, array $keys, array $names): ?string {
    $s = lcup_find_branch_select($html, $keys, $names);
    return $s['current'] ?? null;
}
