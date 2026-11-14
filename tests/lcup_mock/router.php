<?php
/**
 * Mock LC-UP (Livewire v2 style) for the accuracy tests. Markup follows the real pages (xf-row log rows, div.row.mb-2 Tarix rows,
 * branch-scoped payment / archive / debtors / groups lists). All names and ids are made up. State: state.json (edited by the test driver).
 * Center-wide staff ("center" in the state) appear in BOTH branches' activity logs with the SAME row ids, like LC-UP's "Admin".
 */
$DIR = __DIR__; $S = getenv('MOCK_STATE') ?: $DIR . '/state.json'; $LOG = getenv('MOCK_LOG') ?: $DIR . '/requests.log';
$uri = $_SERVER['REQUEST_URI']; $path = parse_url($uri, PHP_URL_PATH); $q = []; parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
$method = $_SERVER['REQUEST_METHOD'];
$__raw = $method === 'POST' ? file_get_contents('php://input') : ''; $__u = []; if ($__raw && ($__j = json_decode($__raw, true)) && isset($__j['updates'])) foreach ($__j['updates'] as $__x) $__u[] = $__x['type'] . ':' . ($__x['payload']['name'] ?? $__x['payload']['method'] ?? $__x['payload']['event'] ?? '') . (isset($__x['payload']['params'][0]) ? '(' . $__x['payload']['params'][0] . ')' : '');
file_put_contents($LOG, json_encode([$method, $path, $q, $__u]) . "\n", FILE_APPEND);
function st() { global $S; return json_decode(file_get_contents($S), true); }
function st_save($s) { global $S; file_put_contents($S, json_encode($s)); }
function out($body, $code = 200, $ct = 'text/html; charset=utf-8') { http_response_code($code); header('Content-Type: ' . $ct); echo $body; exit; }
function redirect($to) { http_response_code(302); header('Location: ' . $to); exit; }
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
$base = 'http://' . $_SERVER['HTTP_HOST'];

if (preg_match('~^/tg/bot[^/]+/sendMessage$~', $path)) { $s = st(); $s['tg'][] = $_POST['text'] ?? ''; st_save($s); out(json_encode(['ok' => true]), 200, 'application/json'); }

/* ---- sessions (cookie "mock_sid") ---- */
$s = st(); $sid = $_COOKIE['mock_sid'] ?? null;
if (!$sid || !isset($s['sessions'][$sid])) { $sid = bin2hex(random_bytes(8)); $s['sessions'][$sid] = ['csrf' => bin2hex(random_bytes(20)), 'auth' => 0, 'branch' => $s['default_branch']]; st_save($s); setcookie('mock_sid', $sid, 0, '/'); }
$sess = &$s['sessions'][$sid];
function save_sess() { global $s; st_save($s); }

if ($path === '/login') {
    if ($sess['auth']) redirect('/admin');
    $h = file_get_contents($DIR . '/login.html'); $h = preg_replace('/(name="_token" value=")[^"]*/', '${1}' . $sess['csrf'], $h); out($h);
}
/* ---- Livewire ---- */
if ($method === 'POST' && preg_match('~^/livewire/message/([\w.\-]+)$~', $path, $m)) {
    $name = $m[1]; $j = json_decode(file_get_contents('php://input'), true);
    if (($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') !== $sess['csrf'] || empty($_SERVER['HTTP_X_LIVEWIRE'])) out(json_encode(['message' => 'CSRF token mismatch']), 419, 'application/json');
    if (!is_array($j) || ($j['fingerprint']['name'] ?? '') !== $name || empty($j['serverMemo']['checksum'])) out('bad', 400);
    $memo = $j['serverMemo']; $eff = ['html' => '<div></div>'];
    if ($name === 'login-livewire') {
        $vals = []; $call = null;
        foreach ($j['updates'] as $u) { if ($u['type'] === 'syncInput') $vals[$u['payload']['name']] = $u['payload']['value']; if ($u['type'] === 'callMethod') $call = $u['payload']['method']; }
        if ($call === 'save') {
            $digits = preg_replace('/\D+/', '', $vals['mobile_number'] ?? '');
            if (substr($digits, -9) === substr($s['phone'], -9) && strpos($vals['mobile_number'], '+(998)-') === 0 && ($vals['password'] ?? '') === $s['password']) { $sess['auth'] = 1; save_sess(); $eff['redirect'] = $GLOBALS['base'] . '/admin'; }
            else $memo['errors'] = ['password' => ["Telefon raqam yoki parol noto'g'ri"]];
        }
    } elseif ($name === 'navbar.filial-select') {
        if (!$sess['auth']) out('x', 401);
        foreach ($j['updates'] as $u) {
            if ($u['type'] === 'callMethod' && $u['payload']['method'] === 'renderFilials') { $o = ''; foreach ($s['branches'] as $k => $b) $o .= '<option value="' . $k . '">' . esc($b['name']) . '</option>'; $eff['html'] = '<select wire:model="filial_id" wire:change="change_filial">' . $o . '</select>'; }
            if ($u['type'] === 'syncInput' && $u['payload']['name'] === 'filial_id') $memo['data']['filial_id'] = $u['payload']['value'];
            if ($u['type'] === 'callMethod' && $u['payload']['method'] === 'change_filial') { $k = (string)($memo['data']['filial_id'] ?? ''); if (isset($s['branches'][$k])) { $sess['branch'] = $k; save_sess(); $eff['redirect'] = $GLOBALS['base'] . '/admin'; } }
        }
    } elseif (in_array($name, ['xodimlar-faoliyati', 'xf-log', 'student-payments', 'students.history-livewire'], true)) {
        if (!$sess['auth']) out('x', 401);
        $B0 = $s['b'][$sess['branch']]; $stub = '<div wire:id="LOGID"></div>';
        foreach ($j['updates'] as $u) {
            $pl = $u['payload']; $mm = $pl['method'] ?? ''; $ev = $pl['event'] ?? '';
            if ($name === 'xodimlar-faoliyati' && $u['type'] === 'callMethod' && $mm === 'openHistory') { $memo['data']['staff_id'] = $pl['params'][0]; $eff['emits'] = [['event' => 'staffChanged', 'params' => [$pl['params'][0]]]]; $eff['html'] = '<div class="xf-staff-refreshed"></div>' . $stub; }
            elseif ($name === 'xf-log' && $u['type'] === 'fireEvent' && $ev === 'staffChanged') { $memo['data']['staff_id'] = $pl['params'][0]; $memo['data']['limit'] = 25; $eff['html'] = '<section wire:id="LOGID" class="xf-card xf-log">' . render_log($B0, $memo['data']['staff_id'], 25) . '</section>'; }
            elseif ($name === 'xf-log' && $u['type'] === 'callMethod' && $mm === 'loadMore') { $memo['data']['limit'] = ($memo['data']['limit'] ?? 25) + 25; $eff['html'] = '<section wire:id="LOGID" class="xf-card xf-log">' . render_log($B0, $memo['data']['staff_id'], $memo['data']['limit']) . '</section>'; }
            elseif ($name === 'xf-log' && $u['type'] === 'callMethod' && in_array($mm, ['setPreset', '$refresh', 'setCat'], true)) { $eff['html'] = '<section wire:id="LOGID" class="xf-card xf-log">' . render_log($B0, $memo['data']['staff_id'], $memo['data']['limit'] ?? 25) . '</section>'; }
            elseif ($name === 'student-payments' && $u['type'] === 'fireEvent' && $ev === 'studentTolovlarOchilsin') $eff['html'] = '<div><table><thead><tr><th>Sana</th><th>Summa</th><th>Guruh</th><th>Hodim</th><th>Holat</th><th>To\'lov usuli</th></tr></thead><tbody></tbody></table></div>';
            elseif ($name === 'students.history-livewire' && $u['type'] === 'fireEvent' && $ev === 'studentHistoryOchilsin') $eff['html'] = '<div wire:id="HISTID">' . render_history($pl['params'][0]) . '</div>';
            elseif ($u['type'] === 'syncInput') {}
            else out('FORBIDDEN-CALL ' . json_encode($u), 400);
        }
    } else out('FORBIDDEN-COMPONENT ' . $name, 400);
    out(json_encode(['effects' => $eff, 'serverMemo' => $memo]), 200, 'application/json');
}
if ($method !== 'GET') out('method not allowed', 405);
if (!$sess['auth']) redirect('/login');
$bk = $sess['branch']; $B = $s['b'][$bk]; $bname = $s['branches'][$bk]['name'];

function comp_attr($id, $name, $data, $listeners = []) {
    $memo = ['children' => [], 'errors' => [], 'htmlHash' => 'h', 'data' => $data, 'dataMeta' => [], 'checksum' => bin2hex(random_bytes(32))];
    return 'wire:id="' . $id . '" wire:initial-data="' . htmlspecialchars(json_encode(['fingerprint' => ['id' => $id, 'name' => $name, 'locale' => 'uz', 'path' => 'admin/x', 'method' => 'GET', 'v' => 'acj'], 'effects' => ['listeners' => $listeners], 'serverMemo' => $memo]), ENT_QUOTES) . '"';
}
/** Staff of a branch view: its own staff + the center-wide ones (same ids and rows in every branch). */
function staff_all($B) { global $s; return array_merge($B['staff_list'], $s['center_staff'] ?? []); }
function acts_of($B, $staffId) { global $s; $all = array_merge($B['acts'], $s['center_acts'] ?? []); return array_values(array_filter($all, function ($a) use ($staffId, $B) { return (string)($a['staff_id'] ?? $B['staff_list'][0]['id']) === (string)$staffId; })); }
function staff_name($B, $id) { foreach (staff_all($B) as $u) if ((string)$u['id'] === (string)$id) return $u['name']; return '?'; }
function render_log($B, $staffId, $limit) {
    $acts = acts_of($B, $staffId); usort($acts, function ($a, $b) { return $b['id'] <=> $a['id']; });
    $show = array_slice($acts, 0, $limit); $left = count($acts) - count($show); $byDay = []; foreach ($show as $a) $byDay[substr($a['time'], 0, 10)][] = $a; $days = '';
    foreach ($byDay as $d => $list) { $days .= '<div class="xf-day" wire:key="xf-day-' . $d . '"><div class="xf-dhead"><span class="xf-dt">' . $d . '</span><span class="xf-dc">' . count($list) . ' ta amal</span></div>';
        foreach ($list as $a) {
            $stu = !empty($a['student_id']) ? '<a class="xf-link" href="' . $GLOBALS['base'] . '/admin/students/' . $a['student_id'] . '">' . esc($a['student_name']) . '</a>' : (!empty($a['student_name']) ? '<span class="xf-link">' . esc($a['student_name']) . '</span>' : '');
            $amt = isset($a['amount_text']) ? '<span class="xf-amt">' . esc($a['amount_text']) . '</span>' : (isset($a['amount']) && $a['amount'] !== null ? '<span class="xf-amt">' . number_format($a['amount'], 0, '.', ' ') . ' so\'m</span>' : '');
            $grp = !empty($a['group_id']) ? '<a class="xf-gp" href="' . $GLOBALS['base'] . '/admin/groups/' . $a['group_id'] . '">' . esc($a['group_name']) . '</a>' : '';
            $days .= '<div class="xf-row" wire:key="xf-row-' . $a['id'] . '"><div><div class="xf-rt">' . esc($a['text']) . '</div><div class="xf-rm">' . $stu . $amt . $grp . '</div></div><span class="xf-time">' . substr($a['time'], 11, 5) . '</span></div>';
        }
        $days .= '</div>'; }
    return '<div class="xf-who"><h1>' . esc(staff_name($B, $staffId)) . '</h1></div>' . $days . ($left > 0 ? '<button type="button" class="xf-more" wire:click="loadMore">Yana ko\'rsatish (' . $left . ')</button>' : '');
}
/** Student "Tarix": the real markup (div.card-body > div.row.mb-2, newest first, <hr> between rows). Rows: [action, group, old|null, new|null, actor, 'Y-m-d H:i']. */
function render_history($sid) {
    global $s; $rows = $s['history'][$sid] ?? []; usort($rows, function ($a, $b) { return strcmp($b[5], $a[5]); });
    $money = function ($v) { return ($v < 0 ? '-' : '') . number_format(abs($v), 0, '.', ','); };
    $h = '<div class="card"><div class="card-body">';
    foreach ($rows as $r) {
        [$text, $grp, $old, $new, $who, $at] = $r; $deleted = stripos($text, "o'chirildi") !== false;
        if ($old !== null && $new !== null) $amt = (stripos($text, 'minus') !== false) ? ' ' . $money($old) . ' -> ' . $money($new) : ' <br> <span class="text-danger">' . $money($old) . '</span> -> <span class="text-success">' . $money($new) . '</span>';
        elseif ($new !== null) $amt = ' <br> <span class="' . ($deleted || $new < 0 ? 'text-danger' : 'text-success') . '">' . $money($new) . '</span>';
        else $amt = '';
        $h .= '<div class="row mb-2"><div class="col-4 d-flex align-items-center"><span style="font-size: 12px">' . esc($text) . '</span></div>'
            . '<div class="col-4 d-flex align-items-center"><span style="font-size: 12px">' . esc($grp) . $amt . '</span></div>'
            . '<div class="col-2"><span style="font-size: 11px">' . esc($who) . '</span></div><div class="col-2"><span style="font-size: 11px">' . esc($at) . '</span></div></div><hr>';
    }
    return $h . '</div></div>';
}
function page_chrome($inner) {
    global $sess, $bk, $bname;
    $fp = ['id' => '9oCIfrUrxJaJIufeSf8U', 'name' => 'navbar.filial-select', 'locale' => 'uz', 'path' => 'admin', 'method' => 'GET', 'v' => 'acj'];
    $memo = ['children' => [], 'errors' => [], 'htmlHash' => 'abc', 'data' => ['filial_id' => $bk], 'dataMeta' => [], 'checksum' => bin2hex(random_bytes(32))];
    $init = htmlspecialchars(json_encode(['fingerprint' => $fp, 'effects' => ['listeners' => []], 'serverMemo' => $memo]), ENT_QUOTES);
    return '<!DOCTYPE html><html><head><title>LC-UP</title></head><body><form method="POST" action="/admin/x"><input type="hidden" name="_token" value="' . $sess['csrf'] . '"></form><button id="navbarfilialname">' . esc($bname) . '</button>'
        . '<select wire:id="9oCIfrUrxJaJIufeSf8U" wire:initial-data="' . $init . '" class="form-select" wire:model="filial_id" wire:change="change_filial" wire:focus="renderFilials"><option value="' . esc($bk) . '">' . esc($bname) . '</option><option value="">Yuklanmoqda...</option></select>'
        . $inner . '</body></html>';
}
function page_of($rows, $page, $per = 15) { return array_slice($rows, ($page - 1) * $per, $per); }
function pager($total, $page, $per = 15) {
    $pages = max(1, (int)ceil($total / $per)); $h = '<nav><ul class="pagination">';
    for ($i = 1; $i <= $pages; $i++) $h .= $i === $page ? '<li class="page-item active"><span class="page-link">' . $i . '</span></li>' : '<li class="page-item"><button type="button" class="page-link" wire:click="gotoPage(' . $i . ', \'page\')">' . $i . '</button></li>';
    if ($page < $pages) $h .= '<li class="page-item"><button type="button" class="page-link" wire:click="nextPage(\'page\')" rel="next">›</button></li>';
    return $h . '</ul></nav>';
}
function money($n) { return number_format($n, 0, '.', ','); }
function rows_table($rows, $archive) {
    $th = '<th>T/r</th><th>Sana</th><th>FISH</th><th>Narx</th><th>To\'lov usuli</th><th>O\'qituvchi bo\'yicha</th><th>guruh</th><th>Hodim</th>' . ($archive ? '<th>O\'chirilgan sana</th><th>Izoh</th>' : '<th>Izoh</th>') . '<th></th>';
    $h = '<table id="myDataTable"><thead><tr>' . $th . '</tr></thead><tbody>'; $i = 0;
    foreach ($rows as $r) { $i++;
        $h .= '<tr><td>' . $i . '.</td><td>' . date('M j, Y', strtotime($r['date'])) . '</td><td><a href="javascript:void(0);">' . esc($r['student']) . '</a></td><td>' . money($r['amount']) . ' so\'m</td><td>' . esc($r['method'] ?? 'Naqd pul') . '</td><td><span>T</span></td><td>' . esc($r['group']) . '</td><td>' . esc($r['staff']) . '</td>'
            . ($archive ? '<td>' . esc($r['deleted_at']) . '</td><td>' . esc($r['reason'] ?? '') . '</td>' : '<td></td>') . '<td><i onclick="deleteTrashed(' . $r['id'] . ')"></i></td></tr>'; }
    return $h . '</tbody></table>';
}
function sorted($rows) { usort($rows, function ($a, $b) { return [$b['date'], $b['id']] <=> [$a['date'], $a['id']]; }); return $rows; }

if ($path === '/admin/worker_reports') {
    $def = $B['staff_list'][0]['id']; $staff = '';
    foreach (staff_all($B) as $u) $staff .= '<button type="button" class="xf-s-item ' . ((string)$u['id'] === (string)$def ? 'xf-on' : '') . '" wire:click="openHistory(\'' . $u['id'] . '\')"><span class="xf-s-info"><span class="xf-s-name">' . esc($u['name']) . '</span></span><span class="xf-s-cnt">' . count(acts_of($B, $u['id'])) . '</span></button>';
    out(page_chrome('<div class="xf-page"><div ' . comp_attr('OUTERID', 'xodimlar-faoliyati', ['staff_id' => (string)$def]) . '><div class="xf-wrap"><aside class="xf-staff">' . $staff . '</aside><section ' . comp_attr('LOGID', 'xf-log', ['staff_id' => (string)$def, 'limit' => 25], ['staffChanged']) . ' class="xf-card xf-log">' . render_log($B, $def, 25) . '</section></div></div></div>'));
}
if ($path === '/admin/groups') {
    $g = []; foreach ($B['groups'] as $id => $nm) $g[] = [$id, $nm]; $pg = max(1, (int)($q['page'] ?? 1));
    $h = '<table><thead><tr><th>Guruh</th></tr></thead><tbody>'; foreach (page_of($g, $pg, 10) as [$id, $nm]) $h .= '<tr><td><a href="' . $base . '/admin/groups/' . $id . '">' . esc($nm) . '</a></td></tr>';
    out(page_chrome($h . '</tbody></table>' . pager(count($g), $pg, 10)));
}
if ($path === '/admin/students') { $h = '<table>'; foreach (($B['students'] ?? []) as $i => $nm) $h .= '<tr><td><a href="' . $base . '/admin/students/' . $i . '">' . esc($nm) . '</a></td></tr>'; out(page_chrome($h . '</table>')); }
if (preg_match('~^/admin/students/(\d+)$~', $path, $mm)) {
    if (in_array($mm[1], $s['deleted_students'] ?? [], true)) out('Not found', 404);
    $sid = $mm[1];
    out(page_chrome('<div><h1>Talaba</h1><div ' . comp_attr('PAYID', 'student-payments', ['sid' => $sid], ['studentTolovlarOchilsin']) . '></div><div ' . comp_attr('EDITID', 'students.edit-livewire', ['sid' => $sid], ['studentHistoryOchilsin', 'studentTolovlarOchilsin']) . '></div>'
        . '<div role="tabpanel" id="history"><div ' . comp_attr('HISTID', 'students.history-livewire', ['sid' => $sid], ['studentHistoryOchilsin']) . '><div class="skeleton"></div></div></div></div>'));
}
if ($path === '/admin/archive/tolovlars' || $path === '/admin/tolovlars') {
    $arch = strpos($path, 'archive') !== false; $rows = sorted($arch ? $B['archive'] : $B['payments']); $pg = max(1, (int)($q['page'] ?? 1));
    out(page_chrome('<div>' . rows_table(page_of($rows, $pg), $arch) . '<span>Jami: ' . money(array_sum(array_column($rows, 'amount'))) . ' so\'m / ' . count($rows) . ' ta</span>' . pager(count($rows), $pg) . '</div>'));
}
if ($path === '/admin/discount') out(page_chrome('<table><thead><tr><th>FISH</th><th>Guruh</th><th>Chegirma</th><th>Sabab</th><th>Hodim</th></tr></thead><tbody></tbody></table>'));
if ($path === '/admin/qarzdorlar') {
    $rows = $B['debtors']; $pg = max(1, (int)($q['page'] ?? 1));
    $h = '<table><thead><tr><th>T/r</th><th>FISH</th><th>Guruhlar</th><th>Balans</th><th>Izoh</th></tr></thead><tbody>'; $i = ($pg - 1) * 15;
    foreach (page_of($rows, $pg) as $d) { $i++; $h .= '<tr><td>' . $i . '</td><td><a href="' . $base . '/admin/students/' . $d['student_id'] . '">' . esc($d['student']) . '</a></td><td>' . esc($d['group'] ?? '') . '</td><td>' . ($d['balance'] < 0 ? '-' : '') . money(abs($d['balance'])) . ' so\'m</td><td>' . esc($d['comment'] ?? '') . '</td></tr>'; }
    $jami = array_sum(array_column($rows, 'balance'));
    out(page_chrome($h . '</tbody></table><div>Jami: ' . ($jami < 0 ? '-' : '') . money(abs($jami)) . ' so\'m / ' . count($rows) . ' ta</div>' . pager(count($rows), $pg)));
}
if ($path === '/admin') out(page_chrome('<h1>Dashboard</h1>'));
out('not found', 404);
