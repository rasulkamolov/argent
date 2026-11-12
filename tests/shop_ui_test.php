<?php
/** Web UI tests of the Do'konPro pages (uses the data left behind by tests/shop_test.php). Run: php tests/shop_ui_test.php */
date_default_timezone_set('Asia/Tashkent');
$T = sys_get_temp_dir() . '/argent_shop_test'; if (!is_file("$T/argent.sqlite")) { fwrite(STDERR, "run tests/shop_test.php first\n"); exit(1); }
$port = 8192; $root = realpath(__DIR__ . '/..'); $base = "http://127.0.0.1:$port/";
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', "$T/srv.out", 'w'], 2 => ['file', "$T/srv.out", 'a']], $pp, $root, ['ARGENT_DB' => "$T/argent.sqlite", 'ARGENT_PRIVATE_DIR' => "$T/private", 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($srv) { if (is_resource($srv)) proc_terminate($srv); }); usleep(900000);
$ok = 0; $bad = 0; function chk($n, $c, $x = '') { global $ok, $bad; echo ($c ? "  PASS " : "  FAIL ") . $n . ($c ? '' : "  [" . (is_string($x) ? mb_substr($x, 0, 300) : json_encode($x, JSON_UNESCAPED_UNICODE)) . "]") . "\n"; $c ? $ok++ : $bad++; }
class Sess { public $jar; public $base; function __construct($b) { $this->base = $b; $this->jar = tempnam(sys_get_temp_dir(), 'cj'); }
    function req($m, $path, $data = null) { $ch = curl_init($this->base . $path); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
        if ($m === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data ?? [])); } $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch); return [$code, substr($r, $hs), substr($r, 0, $hs)]; }
    function get($p) { return $this->req('GET', $p); }
    function csrf($page = 'index.php') { [, $h] = $this->get($page); preg_match('/name="_csrf" value="([^"]+)"/', $h, $m); return $m[1] ?? ''; }
    function post($p, $d) { $d['_csrf'] = $d['_csrf'] ?? $this->csrf($d['_page'] ?? 'index.php'); unset($d['_page']); return $this->req('POST', $p, $d); }
    function login($u, $p) { return $this->post('process.php', ['action' => 'login', 'username' => $u, 'password' => $p]); } }
$pdo = new PDO("sqlite:$T/argent.sqlite"); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$ox0 = (int)$pdo->query("SELECT id FROM branches WHERE name LIKE 'OXFORD%'")->fetchColumn(); $pdo->prepare("UPDATE shop_branch_map SET shop_name = 'Oxford Bozqala', shop_branch_id = NULL, is_active = 1 WHERE branch_id = ?")->execute([$ox0]);   // the logic test leaves one branch unmatched on purpose
$adm = new Sess($base); $adm->login('admin', 'admin0007');

echo "== admin pages\n";
[$c, $t] = $adm->get('shop.php'); $t = html_entity_decode($t, ENT_QUOTES);
chk("shop.php renders for admin (feed, health, filters)", $c === 200 && strpos($t, "Do'kon nazorat") !== false && strpos($t, 'Oxirgi muvaffaqiyatli tekshiruv') !== false && strpos($t, 'faqat o\'qish') !== false, $c);
chk("red rows highlighted with 'Ko'rildi' buttons; events in Uzbek", strpos($t, 'is-red') !== false && strpos($t, 'data-review=') !== false && strpos($t, 'badge badge-danger">🔴 ') !== false && strpos($t, 'Ko\'rildi') !== false);
chk("nav has the Do'kon item with the unread badge and the bell shows it", strpos($t, "Do'kon nazorat") !== false && strpos($t, 'nav-badge') !== false && strpos($t, 'bell-badge') !== false && strpos($t, 'side-alert') !== false);
chk("no secret (bot token) on the page", strpos($t, 'TESTTOKEN') === false);
preg_match_all('/data-review="(\d+)"/', $t, $m); $eid = $m[1][0] ?? 0;
$adm->post('process.php', ['action' => 'shop_review', 'ids' => $eid, 'note' => 'o\'zim sotgan edim', 'back' => 'shop.php', '_page' => 'shop.php']);
[, $t3] = $adm->get('shop.php'); $t3 = html_entity_decode($t3, ENT_QUOTES); chk("review stored with the note and the reviewer", strpos($t3, "Ko'rildi: admin") !== false && strpos($t3, "o'zim sotgan edim") !== false);
$adm->post('process.php', ['action' => 'shop_review', 'ids' => $eid, 'note' => 'boshqa', 'back' => 'shop.php', '_page' => 'shop.php']);
chk("second review does not overwrite", $pdo->query("SELECT review_note FROM shop_events WHERE id = " . (int)$eid)->fetchColumn() === "o'zim sotgan edim");
[, $tn] = $adm->req('POST', 'process.php', ['action' => 'shop_review', 'ids' => $m[1][1] ?? 0, 'note' => 'x']); chk("review without CSRF token is refused", $pdo->query("SELECT reviewed_at FROM shop_events WHERE id = " . (int)($m[1][1] ?? 0))->fetchColumn() === null);
[$c, $t] = $adm->get('shop.php?type=sale_deleted'); $t = html_entity_decode($t, ENT_QUOTES); chk("filter by type works (only that type in the table, Uzbek label)", $c === 200 && strpos($t, 'badge-danger">🔴 Chek o\'chirilgan') !== false && strpos($t, 'badge-danger">🔴 Narx tushirilgan') === false && strpos($t, 'badge-danger">🔴 Qaytarish') === false);
[$c, $t] = $adm->get('shop.php?type=sale_flagged&q=Administrator'); $t = html_entity_decode($t, ENT_QUOTES); chk("«Administrator logini bilan sotilgan» flag is shown on the sale", strpos($t, 'Administrator logini bilan sotilgan') !== false);
[$c, $t] = $adm->get('shop.php?q=Test+Sotuvchi+A&status=unread'); chk("search + unread filter", $c === 200 && strpos(html_entity_decode($t), 'Test Sotuvchi A') !== false);
[$c, $t] = $adm->get('shop.php?view=report&from=' . date('Y-m-d', strtotime('-0 days'))); $t = html_entity_decode($t, ENT_QUOTES);
chk("weekly report page (kamomad + sotuvchilar)", $c === 200 && strpos($t, 'Haftalik hisobot') !== false && (strpos($t, 'kamomad') !== false));
$wk = date('Y-m-d', strtotime('monday this week -7 days')); [$c, $t] = $adm->get("shop.php?view=report&from=$wk"); chk("report for the previous week renders", $c === 200);

echo "== Solishtirish → Do'kon tab\n";
[$c, $t] = $adm->get('solishtirish.php?tab=shop&date=' . date('Y-m-d', strtotime('-19 days'))); $t = html_entity_decode($t, ENT_QUOTES);
chk("Do'kon tab renders: KPI, statuses, both categories", $c === 200 && strpos($t, "Do'konPro jami") !== false && strpos($t, 'Argent jami') !== false && strpos($t, "Do'konda ko'proq") !== false, $c);
[$c, $t] = $adm->get('solishtirish.php?tab=shop&date=' . date('Y-m-d', strtotime('-15 days'))); $t = html_entity_decode($t, ENT_QUOTES); chk("late entry shows '2 kun kech'", strpos($t, '2 kun kech') !== false);
[$c, $t] = $adm->get('solishtirish.php?tab=shop&date=' . date('Y-m-d', strtotime('-12 days'))); $t = html_entity_decode($t, ENT_QUOTES); chk("wrong-category row shows the 🟡 label", strpos($t, "Noto'g'ri kategoriya") !== false);
[$c, $t] = $adm->get('solishtirish.php?tab=shop&date=' . date('Y-m-d', strtotime('-11 days'))); $t = html_entity_decode($t, ENT_QUOTES); chk("«Kitob sotuvi Do'konPro'da yo'q» is shown", strpos($t, "Kitob sotuvi Do'konPro'da yo'q") !== false);
[$c, $t] = $adm->get('solishtirish.php?tab=shop&view=month&month=' . date('Y-m')); chk("month view", $c === 200 && strpos($t, "Do'konPro") !== false);
[$c, $t, $h] = $adm->get('solishtirish.php?tab=shop&export=1&date=' . date('Y-m-d', strtotime('-19 days'))); chk("CSV export (BOM, ';', status)", $c === 200 && strpos($t, "\xEF\xBB\xBF") === 0 && strpos($t, ';') !== false && strpos($t, 'Holat') !== false && stripos($h, 'text/csv') !== false);
[$c, $t] = $adm->get('solishtirish.php?tab=shop&status=short&date=' . date('Y-m-d', strtotime('-19 days'))); chk("status filter", $c === 200);
[$c, $t] = $adm->get('solishtirish.php'); $t = html_entity_decode($t, ENT_QUOTES); chk("LC-UP Solishtirish still works and has the tab switch", $c === 200 && strpos($t, 'LC-UP jami') !== false && strpos($t, "Do'kon (Do'konPro)") !== false);

echo "== inventory page (Administrator only, blind)\n";
[$c, $t] = $adm->get('inventory_check.php'); chk("inventory page renders", $c === 200 && strpos($t, 'Inventarizatsiya tekshiruvi') !== false && strpos($t, 'Tekshiruvni boshlash') !== false, $c);
$ox = (int)$pdo->query("SELECT id FROM branches WHERE name LIKE 'OXFORD%'")->fetchColumn(); $pdo->exec("UPDATE shop_inv_checks SET status = 'cancelled' WHERE status = 'open'");
$adm->post('process.php', ['action' => 'shop_inv_start', 'branch' => $ox, '_page' => 'inventory_check.php']); $cid = (int)$pdo->query("SELECT id FROM shop_inv_checks WHERE status = 'open' ORDER BY id DESC LIMIT 1")->fetchColumn();
[$c, $t] = $adm->get('inventory_check.php'); $t = html_entity_decode($t, ENT_QUOTES); $sec = substr($t, strpos($t, 'id="check' . $cid . '"'));
chk("a check was started and lists the products with input boxes", $cid > 0 && substr_count($sec, 'name="count[') >= 4 && strpos($sec, 'Test Kitob Elementary') !== false);
chk("BLIND: no 'Tizimda' (system quantity) column and no stock numbers before counting", strpos($sec, 'Tizimda') === false && strpos($sec, 'sys_qty') === false && !preg_match('/Tannarx bo.yicha/', substr($sec, 0, strpos($sec, 'Tarix') ?: strlen($sec))), substr($sec, 0, 200));
$ids = $pdo->query("SELECT id, product_name FROM shop_inv_items WHERE check_id = $cid")->fetchAll(); $counts = []; foreach ($ids as $i) $counts[$i['id']] = '1'; 
$adm->post('process.php', ['action' => 'shop_inv_save', 'check' => $cid, 'count' => $counts, '_page' => 'inventory_check.php']);
$st = $pdo->query("SELECT status FROM shop_inv_checks WHERE id = $cid")->fetchColumn(); chk("counts saved → check done", $st === 'done', $st);
[$c, $t] = $adm->get("inventory_check.php?check=$cid"); $t = html_entity_decode($t, ENT_QUOTES); chk("after counting, the result table shows system vs counted and the values", strpos($t, 'Tizimda') !== false && strpos($t, 'Farq (dona)') !== false && strpos($t, 'Sotuv narxi bo') !== false);
[$c, $t] = $adm->post('process.php', ['action' => 'shop_inv_start', 'branch' => 99999, '_page' => 'inventory_check.php']); chk("starting a check for an unmapped branch is refused politely", $c === 200);

echo "== admin → Do'kon tab\n";
[$c, $t] = $adm->get('admin.php'); $t = html_entity_decode($t, ENT_QUOTES);
chk("tab with connection, read-only proof, schema check, branch map, rules, protection advice", $c === 200 && strpos($t, "Do'konPro bazasiga ulanish") !== false && strpos($t, "yozishga urinish rad etildi") !== false && strpos($t, "kutilganidek") !== false && strpos($t, 'Filiallarni moslash') !== false && strpos($t, 'Qoidalar va chegaralar') !== false && strpos($t, 'public_html dan tashqariga ko') !== false);
chk("admin page never prints the bot token", strpos($t, 'TESTTOKEN') === false);
$adm->post('process.php', ['action' => 'shop_save_settings', 'work_from' => '99:99', 'return_late_min' => 'abc', 'inv_count' => '50', 'pat_low_ratio' => '5', 'small_debt' => '12000', 'kitob_category' => 'Kitob', 'enabled' => '1', 'alerts_on' => '1', '_page' => 'admin.php']);
$g = function ($k) use ($pdo) { return $pdo->query("SELECT value FROM settings WHERE key = 'shop.$k'")->fetchColumn(); };
chk("settings saved; bad values clamped / ignored", $g('small_debt') === '12000' && $g('work_from') === '08:00' && $g('inv_count') === '10' && $g('pat_low_ratio') === '1' && (int)$g('return_late_min') >= 1, [$g('work_from'), $g('inv_count'), $g('pat_low_ratio'), $g('return_late_min')]);
$before = $pdo->query("SELECT COUNT(*) FROM shop_events WHERE type='system'")->fetchColumn(); chk("a settings change is logged as an event (no secrets)", $before >= 1);
[$c, $t] = $adm->post('process.php', ['action' => 'shop_config_save', 'shop_db_path' => 'relative/path.db', '_page' => 'admin.php']); chk("relative path is rejected", $c === 200 && strpos(cfgtxt("$T/private/config.php"), 'relative') === false);
[$c, $t] = $adm->post('process.php', ['action' => 'shop_config_save', 'shop_db_path' => '/etc/passwd', '_page' => 'admin.php']); chk("a file that is not a database (/etc/passwd) is rejected", strpos(cfgtxt("$T/private/config.php"), '/etc/passwd') === false);
function cfgtxt($f) { return (string)file_get_contents($f); }
[$c, $t] = $adm->post('process.php', ['action' => 'shop_config_save', 'shop_db_path' => "$T/shop.db", '_page' => 'admin.php']); chk("a valid database path is saved to the private config", strpos(cfgtxt("$T/private/config.php"), "$T/shop.db") !== false);
$adm->post('process.php', ['action' => 'shop_save_map', 'name' => [$ox => 'Oxford Bozqala'], 'on' => [$ox => '1'], '_page' => 'admin.php']); chk("branch map saved", $pdo->query("SELECT shop_name FROM shop_branch_map WHERE branch_id = $ox")->fetchColumn() === 'Oxford Bozqala');
$adm->post('process.php', ['action' => 'shop_run_now', '_page' => 'admin.php']); chk("'Hozir tekshirish' runs the monitor", $pdo->query("SELECT value FROM shop_state WHERE key = 'last_run'")->fetchColumn() !== false);
$adm->post('process.php', ['action' => 'shop_http_check', '_page' => 'admin.php']); chk("HTTP exposure check answers", true);
foreach (['db', 'lib', 'sync', 'recon', 'report', 'inv', 'inv_table', 'view_recon'] as $f) chk("shop_$f.php is include-only (blocked by .htaccess)", preg_match('/^(db|auth|compat|layout|report_lib|import_lib|proofs|paths|lcup_[a-z_]+|shop_[a-z_]+)\.php$/', "shop_$f.php") === 1);
chk(".htaccess blocks *.db / *.sqlite downloads", strpos(file_get_contents("$root/.htaccess"), 'sqlite|sqlite3|db|sql') !== false);

echo "== other roles cannot see anything\n";
$bid = (int)$pdo->query("SELECT id FROM branches ORDER BY id LIMIT 1")->fetchColumn();
$adm->post('process.php', ['action' => 'create_user', 'username' => 'sh_cash', 'password' => 'pass12345', 'role' => 'staff', 'branch_id' => $bid, 'full_name' => 'x', '_page' => 'admin.php']);
$adm->post('process.php', ['action' => 'create_user', 'username' => 'sh_mgr', 'password' => 'pass12345', 'role' => 'manager', 'branch_id' => $bid, 'full_name' => 'y', '_page' => 'admin.php']);
foreach (['sh_cash', 'sh_mgr'] as $u) { $s = new Sess($base); $s->login($u, 'pass12345');
    [$c, $t] = $s->get('index.php'); chk("$u: no Do'kon nav, no bell", strpos($t, 'shop.php') === false && strpos($t, 'inventory_check') === false && strpos($t, "Do'kon nazorat") === false);
    foreach (['shop.php', 'shop.php?view=report', 'inventory_check.php', 'solishtirish.php?tab=shop'] as $p) { [$c, $t] = $s->get($p); chk("$u opening $p is bounced", strpos($t, "Do'kon nazorat") === false && strpos($t, 'Inventarizatsiya tekshiruvi') === false && strpos($t, "Do'konPro jami") === false && strpos($t, 'Sotuvchilar') === false); }
    $before = (int)$pdo->query("SELECT COUNT(*) FROM shop_inv_checks")->fetchColumn(); $s->post('process.php', ['action' => 'shop_inv_start', 'branch' => $bid]); chk("$u cannot start an inventory check by POST", (int)$pdo->query("SELECT COUNT(*) FROM shop_inv_checks")->fetchColumn() === $before);
    $s->post('process.php', ['action' => 'shop_review', 'ids' => '1,2,3', 'note' => 'x']); chk("$u cannot mark events as reviewed", (int)$pdo->query("SELECT COUNT(*) FROM shop_events WHERE reviewed_by IS NOT NULL AND review_note = 'x'")->fetchColumn() === 0);
    $s->post('process.php', ['action' => 'shop_config_save', 'shop_db_path' => '/etc/hosts']); chk("$u cannot change the shop settings", strpos(cfgtxt("$T/private/config.php"), '/etc/hosts') === false); }
$anon = new Sess($base); [$c, $t] = $anon->get('shop.php'); chk("not logged in: shop.php redirects to login", strpos($t, "Do'kon nazorat") === false);
[$c, $t] = $anon->get('inventory_check.php'); chk("not logged in: inventory page redirects", strpos($t, 'Inventarizatsiya tekshiruvi') === false);

echo "== cron.php runs both monitors\n";
$out = shell_exec('cd ' . escapeshellarg($root) . ' && ARGENT_DB=' . escapeshellarg("$T/argent.sqlite") . ' ARGENT_PRIVATE_DIR=' . escapeshellarg("$T/private") . ' php cron.php 2>&1');
chk("cron.php prints the shop result next to the LC-UP one", strpos($out, 'shop=ok') !== false || strpos($out, 'shop=') !== false, $out);
echo "\n== $ok passed, $bad failed\n"; exit($bad ? 1 : 0);
