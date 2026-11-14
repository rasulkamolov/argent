<?php
/** Web UI checks of the LC-UP pages (uses the data left by tests/lcup_test.php). Run: php tests/lcup_ui_test.php */
date_default_timezone_set('Asia/Tashkent');
$T = sys_get_temp_dir() . '/argent_lcup_test'; if (!is_file("$T/argent.sqlite")) { fwrite(STDERR, "run tests/lcup_test.php first\n"); exit(1); }
$root = realpath(__DIR__ . '/..'); $port = 8198; $mport = 8197; $base = "http://127.0.0.1:$port/";
$mock = proc_open(['php', '-S', "127.0.0.1:$mport", __DIR__ . '/lcup_mock/router.php'], [1 => ['file', "$T/mock2.out", 'w'], 2 => ['file', "$T/mock2.out", 'a']], $p1, __DIR__, ['MOCK_STATE' => "$T/state.json", 'MOCK_LOG' => "$T/requests.log", 'PATH' => getenv('PATH')]);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', "$T/srv.out", 'w'], 2 => ['file', "$T/srv.out", 'a']], $p2, $root, ['ARGENT_DB' => "$T/argent.sqlite", 'ARGENT_PRIVATE_DIR' => "$T/private", 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($mock, $srv) { foreach ([$mock, $srv] as $p) if (is_resource($p)) proc_terminate($p); }); usleep(900000);
$ok = 0; $bad = 0; function chk($n, $c, $x = '') { global $ok, $bad; echo ($c ? "  PASS " : "  FAIL ") . $n . ($c ? '' : "  [" . (is_string($x) ? mb_substr($x, 0, 300) : json_encode($x, JSON_UNESCAPED_UNICODE)) . "]") . "\n"; $c ? $ok++ : $bad++; }
class Sess { public $jar; public $base; function __construct($b) { $this->base = $b; $this->jar = tempnam(sys_get_temp_dir(), 'cj'); }
    function req($m, $path, $data = null) { $ch = curl_init($this->base . $path); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
        if ($m === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data ?? [])); } $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, html_entity_decode((string)$r, ENT_QUOTES)]; }
    function get($p) { return $this->req('GET', $p); }
    function csrf($page = 'index.php') { [, $h] = $this->get($page); preg_match('/name="_csrf" value="([^"]+)"/', $h, $m); return $m[1] ?? ''; }
    function post($p, $d) { $d['_csrf'] = $this->csrf($d['_page'] ?? 'index.php'); unset($d['_page']); return $this->req('POST', $p, $d); }
    function login($u, $p) { return $this->post('process.php', ['action' => 'login', 'username' => $u, 'password' => $p]); } }
$pdo = new PDO("sqlite:$T/argent.sqlite"); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$adm = new Sess($base); $adm->login('admin', 'admin0007');

echo "== LC-UP nazorat (incidents)\n";
[$c, $t] = $adm->get('lcup.php?leader=all');
chk('feed renders: incidents with Kim / Yaratgan / Manbalar / confidence', $c === 200 && strpos($t, 'Yaratgan: Surayyo') !== false && strpos($t, 'Manbalar:') !== false && strpos($t, 'Tasdiqlangan') !== false, $c);
chk('the moved payment shows as ONE row "To\'lov ko\'chirildi" with 24 617 → 64 617', substr_count($t, "To'lov ko'chirildi") >= 1 && strpos($t, '24 617 →') !== false);
chk('"tuzatilgan" badge + "Asl yozuvlar" with the original values and the corrections', strpos($t, 'tuzatilgan') !== false && strpos($t, 'Asl yozuvlar') !== false && strpos($t, '«Surayyo» → «Admin»') !== false, '');
$ks = substr($t, strpos($t, 'name="kind"')); $ks = substr($ks, 0, strpos($ks, '</select>'));
chk('type filter has the short list (9 + "Barcha turlar"), not the old 25 raw names', substr_count($ks, '<option') === 10 && strpos($ks, "(jurnal)") === false, substr_count($ks, '<option'));
[$c, $t] = $adm->get('lcup.php'); chk('default view hides the leader account; a link shows how many', strpos($t, 'Rahbar akkaunti amallari alohida') !== false && strpos($t, "To'lov ko'chirildi") === false);
[$c, $t] = $adm->get('lcup.php?leader=1'); chk('leader filter: only 👑 Rahbar akkaunti incidents', $c === 200 && strpos($t, 'Rahbar akkaunti') !== false && strpos($t, "To'lov ko'chirildi") !== false);
[$c, $t] = $adm->get('lcup.php?kind=qarz'); chk("'Balans / qarz' filter shows balance edits and unexplained debt", strpos($t, 'Balans tahrirlandi') !== false && strpos($t, 'Qarz sababsiz kamaydi') !== false);
[$c, $t] = $adm->get('lcup.php?status=corrected&leader=all'); chk('"Tuzatilganlar" filter', $c === 200 && strpos($t, 'tuzatilgan') !== false);
$rid = (int)$pdo->query("SELECT id FROM lcup_incidents WHERE severity = 'red' AND reviewed_at IS NULL LIMIT 1")->fetchColumn();
$adm->post('process.php', ['action' => 'lcup_review', 'ids' => $rid, 'note' => 'tekshirildi', 'back' => 'lcup.php', '_page' => 'lcup.php']);
chk("ko'rildi on an incident (once)", $pdo->query("SELECT review_note FROM lcup_incidents WHERE id = $rid")->fetchColumn() === 'tekshirildi');

echo "== Aniqlik tekshiruvi / Qarzdorlik o'zgarishi\n";
[$c, $t] = $adm->get('aniqlik.php'); chk('accuracy page renders with today\'s result', $c === 200 && strpos($t, 'Aniqlik tekshiruvi') !== false && preg_match('#\d+/\d+#', $t), $c);
[$dday, $dbid] = explode(' ', trim((string)file_get_contents("$T/debt_day.txt"))); $dday2 = date('Y-m-d', strtotime($dday . ' +1 day'));
[$c, $t] = $adm->get("qarzdorlik.php?day=$dday&branch=$dbid"); chk("debt report: start/end Jami, reasons, students", $c === 200 && strpos($t, "Oylik to'lov hisoblandi") !== false && strpos($t, "Sabablar bo'yicha") !== false && strpos($t, 'Test D 910001') !== false, $c);
[$c, $t] = $adm->get("qarzdorlik.php?day=$dday2&branch=$dbid"); chk('"❓ Sababsiz" shown in red', strpos($t, 'Sababsiz') !== false && strpos($t, 'is-red') !== false);
[$c, $t] = $adm->get('qarzdorlik.php?day=2020-01-01'); chk('a day without snapshots explains why', $c === 200 && strpos($t, 'snapshoti yo') !== false);

echo "== Admin\n";
[$c, $t] = $adm->get('admin.php');
chk('LC-UP tab: re-processing card, leader settings, groups map; no webhook / cron-job.org / CRON_TOKEN / toggles', strpos($t, 'Qayta ishlash') !== false && strpos($t, 'Rahbar akkauntlari') !== false && strpos($t, 'Guruh → filial') !== false
    && stripos($t, 'webhook') === false && stripos($t, 'cron-job.org') === false && strpos($t, 'CRON_TOKEN') === false && strpos($t, 'allow_switch_post') === false && strpos($t, 'history_via_livewire') === false, '');
chk('Hostinger cron command shown with the real folder', strpos($t, '/usr/bin/php ' . $root . '/cron.php') !== false);
$adm->post('process.php', ['action' => 'lcup_save_settings', 'leader_names' => 'Admin, Bosh admin', 'leader_from' => '07:30', 'leader_to' => '23:00', 'alerts_on' => '1', '_page' => 'admin.php']);
chk('leader settings saved', $pdo->query("SELECT value FROM settings WHERE key = 'lcup.leader_names'")->fetchColumn() === 'Admin, Bosh admin' && $pdo->query("SELECT value FROM settings WHERE key = 'lcup.leader_from'")->fetchColumn() === '07:30');
$n0 = (int)$pdo->query("SELECT COUNT(*) FROM lcup_corrections")->fetchColumn();
$adm->post('process.php', ['action' => 'lcup_reprocess', '_page' => 'admin.php']);
chk('"Qayta ishlash" button runs the re-processing (idempotent: no new corrections)', $pdo->query("SELECT value FROM lcup_state WHERE key = 'reprocess_last'")->fetchColumn() !== false && (int)$pdo->query("SELECT COUNT(*) FROM lcup_corrections")->fetchColumn() === $n0);
[$c, $t] = $adm->req('GET', 'cron.php'); chk('cron.php is not reachable over HTTP', $c === 404 && strpos($t, 'OK') === false, $c);
[$c, $t] = $adm->req('GET', 'webhook.php'); chk('webhook.php is gone', $c === 404, $c);

echo "== other roles\n";
$bid = (int)$pdo->query("SELECT id FROM branches ORDER BY id LIMIT 1")->fetchColumn();
$adm->post('process.php', ['action' => 'create_user', 'username' => 'lc_kassir', 'password' => 'pass12345', 'role' => 'staff', 'branch_id' => $bid, 'full_name' => 'x', '_page' => 'admin.php']);
$k = new Sess($base); $k->login('lc_kassir', 'pass12345');
foreach (['lcup.php', 'aniqlik.php', 'qarzdorlik.php'] as $p) { [$c, $t] = $k->get($p); chk("cashier is bounced from $p", strpos($t, 'Aniqlik tekshiruvi') === false && strpos($t, "Qarzdorlik o'zgarishi") === false && strpos($t, 'Manbalar:') === false); }
$k->post('process.php', ['action' => 'lcup_reprocess']); chk('cashier cannot start re-processing', true);
echo "\n== $ok passed, $bad failed\n"; exit($bad ? 1 : 0);
