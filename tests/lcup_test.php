<?php
/**
 * LC-UP accuracy tests (mock LC-UP built from the real markup; every name and id is made up).
 *   php tests/lcup_test.php
 * Cases: the two verified real cases (anonymised), center-wide user seen in both branches, archive creator ≠ deleter, payment moved
 * (same student / other student), snapshot-only deletion matched later via Tarix, balance edit, unexplained debt drop, minus edit via Tarix,
 * leader account + off-hours alert, accuracy check, re-processing of old wrong events, append-only rules, debt day report, whitelist.
 */
date_default_timezone_set('Asia/Tashkent');
$T = sys_get_temp_dir() . '/argent_lcup_test'; exec('rm -rf ' . escapeshellarg($T)); mkdir("$T/private", 0700, true);
$port = 8197; $BASE = "http://127.0.0.1:$port";
putenv("MOCK_STATE=$T/state.json"); putenv("MOCK_LOG=$T/requests.log"); file_put_contents("$T/requests.log", '');
$srv = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/lcup_mock/router.php'], [1 => ['file', "$T/mock.out", 'w'], 2 => ['file', "$T/mock.out", 'a']], $pp, __DIR__, ['MOCK_STATE' => "$T/state.json", 'MOCK_LOG' => "$T/requests.log", 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($srv) { if (is_resource($srv)) proc_terminate($srv); });
putenv("ARGENT_DB=$T/argent.sqlite"); putenv("ARGENT_PRIVATE_DIR=$T/private"); define('ARGENT_NO_SESSION', 1);
file_put_contents("$T/private/config.php", "<?php return ['LCUP_BASE_URL'=>'$BASE','LCUP_PHONE'=>'+998901112233','LCUP_PASSWORD'=>'test-pass','TELEGRAM_BOT_TOKEN'=>'123:TESTTOKEN','TELEGRAM_CHAT_ID'=>'555','TELEGRAM_API_BASE'=>'$BASE/tg'];");

/* ---------------- mock LC-UP state ---------------- */
$G1 = ['21117' => 'Math kids Test 1/3/5 9:00', '21118' => 'Kids Test 2/4/6 14:00'];
for ($i = 1; $i <= 10; $i++) $G1[(string)(21200 + $i)] = "Yangibozor guruh $i";                 // 12 groups -> 2 pages of 10
$G2 = []; for ($i = 1; $i <= 13; $i++) $G2[(string)(30000 + $i)] = "Bozqala guruh $i";            // 13 groups, none is 21117
$state = ['phone' => '+998901112233', 'password' => 'test-pass', 'default_branch' => '414', 'sessions' => new stdClass(), 'tg' => [],
    'branches' => ['414' => ['name' => 'Oxford LC'], '415' => ["name" => "Oxford Bo'zqal'a"]],
    'center_staff' => [['id' => '157859', 'name' => 'Admin']], 'center_acts' => [], 'history' => new stdClass(), 'deleted_students' => [],
    'b' => [
        '414' => ['staff_list' => [['id' => '175338', 'name' => 'Madinabonu'], ['id' => '175339', 'name' => 'Surayyo']], 'acts' => [], 'payments' => [], 'archive' => [], 'groups' => $G1, 'debtors' => [], 'students' => []],
        '415' => ['staff_list' => [['id' => '2', 'name' => 'Dilnoza']], 'acts' => [], 'payments' => [], 'archive' => [], 'groups' => $G2, 'debtors' => [], 'students' => []]]];
file_put_contents("$T/state.json", json_encode($state)); usleep(800000);
function mock() { global $T; return json_decode(file_get_contents("$T/state.json"), true); }
function mock_save($s) { global $T; if (empty($s['history'])) $s['history'] = new stdClass(); if (empty($s['sessions'])) $s['sessions'] = new stdClass(); file_put_contents("$T/state.json", json_encode($s)); }
function tg() { return mock()['tg'] ?? []; }
function reqlog() { global $T; return array_map('json_decode', array_filter(explode("\n", (string)file_get_contents("$T/requests.log")))); }
function tm(int $minAgo): string { return date('Y-m-d H:i:00', time() - $minAgo * 60); }
function hm(int $minAgo): string { return date('Y-m-d H:i', time() - $minAgo * 60); }
function act($id, $staffId, $time, $text, $sid = null, $sname = null, $gid = null, $gname = null, $amount = null, $amountText = null) {
    $a = ['id' => (int)$id, 'staff_id' => (string)$staffId, 'time' => $time, 'text' => $text, 'student_id' => $sid, 'student_name' => $sname, 'group_id' => $gid, 'group_name' => $gname, 'amount' => $amount];
    if ($amountText !== null) $a['amount_text'] = $amountText; return $a;
}
function pay($id, $student, $group, $amount, $staff, $date = null) { return ['id' => $id, 'student' => $student, 'group' => $group, 'amount' => $amount, 'date' => $date ?: date('Y-m-01'), 'method' => 'Naqd pul', 'staff' => $staff]; }

require __DIR__ . '/../lcup_sync.php';
$ok = 0; $bad = 0;
function chk($n, $c, $x = '') { global $ok, $bad; echo ($c ? "  PASS " : "  FAIL ") . $n . ($c ? '' : "  [" . (is_string($x) ? mb_substr($x, 0, 600) : mb_substr(json_encode($x, JSON_UNESCAPED_UNICODE), 0, 900)) . "]") . "\n"; $c ? $ok++ : $bad++; }
function run() { $r = lcup_run(['trigger' => 'test']); if (!$r['ok']) echo "    (run errors: " . implode(' | ', $r['errors']) . ")\n"; return $r; }
function incs(string $w = '1=1') { return db()->query("SELECT * FROM lcup_incidents WHERE $w ORDER BY id")->fetchAll(); }

// Argent side: the two branches + LC-UP mapping (a fresh database: the shipped one holds real data and is never used by tests)
db()->exec("INSERT INTO branches (name, color, sort_order) VALUES ('Yangibozor Markaz', 1, 2)");
$YB = (int)db()->query("SELECT id FROM branches WHERE name = 'Yangibozor Markaz'")->fetchColumn(); $OX = (int)db()->query("SELECT id FROM branches WHERE name LIKE 'OXFORD%'")->fetchColumn();
db()->exec("DELETE FROM lcup_branch_map");
db()->prepare("INSERT INTO lcup_branch_map (branch_id, lcup_key, lcup_name) VALUES (?,?,?), (?,?,?)")->execute([$YB, '414', 'Oxford LC', $OX, '415', "Oxford Bo'zqal'a"]);
foreach (['delay_ms' => '0', 'night_time' => '23:59', 'accuracy_time' => '23:59', 'eod_time' => '23:59', 'summary_time' => '23:59', 'work_from' => '00:00', 'work_to' => '23:59', 'debt_interval' => '0', 'discount_interval' => '999', 'leader_names' => '', 'hot_pages' => '3', 'warm_pages' => '0'] as $k => $v) setting_set("lcup.$k", $v);
lcup_state_set('alerts_since', '2000-01-01 00:00:00');
$S1 = 'Test Talaba Alfa'; $S2 = 'Test Talaba Beta'; $S3 = 'Test Talaba Gamma';

echo "== baseline\n";
$s = mock();
$s['b']['414']['payments'][] = pay(8001, 'Old Student', $G1['21118'], 100000, 'Madinabonu');
$s['b']['414']['acts'][] = act(5000001, '175338', tm(600), "Talaba uchun to'lov yaratildi", '800001', 'Old Student', '21118', $G1['21118'], 100000);
$s['b']['415']['payments'][] = pay(8501, $S2, $G2['30001'], 50000, 'Dilnoza');               // case 2: created earlier by a Bo'zqal'a staff member
$s['b']['415']['acts'][] = act(5000002, '2', tm(500), "Talaba uchun to'lov yaratildi", '287001', $S2, '30001', $G2['30001'], 50000);
$s['b']['414']['payments'][] = pay(8002, $S3, $G1['21118'], 70000, 'Madinabonu', date('Y-m-01', strtotime('first day of next month')));   // case: snapshot-only deletion later (a newer billing month than the oldest one on the first page)
$s['b']['414']['debtors'] = [['student_id' => '900010', 'student' => 'Test Qarzdor Bir', 'balance' => -200000, 'group' => $G1['21118']], ['student_id' => '900011', 'student' => 'Test Qarzdor Ikki', 'balance' => -200000, 'group' => $G1['21118']],
    ['student_id' => '900012', 'student' => 'Test Balans Talaba', 'balance' => -285000, 'group' => $G1['21118']], ['student_id' => '900013', 'student' => 'Test Minus Talaba', 'balance' => -300000, 'group' => $G1['21118']]];
$s['b']['414']['students'] = ['900010' => 'Test Qarzdor Bir', '900011' => 'Test Qarzdor Ikki', '900012' => 'Test Balans Talaba', '900013' => 'Test Minus Talaba'];
mock_save($s);
$r = run(); chk('baseline run ok', $r['ok'], $r['errors']);
chk('group map built from BOTH branches (paginated): 12 + 13 groups, #21117 belongs to Yangibozor only', (int)db()->query("SELECT COUNT(*) FROM lcup_groups")->fetchColumn() === 25 && (int)db()->query("SELECT branch_id FROM lcup_groups WHERE group_id = '21117'")->fetchColumn() === $YB, db()->query("SELECT branch_id, COUNT(*) FROM lcup_groups GROUP BY 1")->fetchAll());
chk('baseline: no alert', !tg(), tg());
chk('debtors read with signed balance + Jami', (float)db()->query("SELECT balance FROM lcup_debts WHERE student_id = '900010'")->fetchColumn() === -200000.0 && (json_decode(lcup_state_get("debt_jami_$YB"), true)['jami'] ?? null) == -985000, lcup_state_get("debt_jami_$YB"));

echo "== CASE 1 (real case, anonymised): created 24,617 (Madinabonu), 40,000 (Surayyo); Admin edits 24,617 -> 64,617 and deletes 40,000 at the same minute\n";
$s = mock(); $c1 = tm(180); $c2 = tm(90); $tA = tm(2);
$s['b']['414']['acts'][] = act(5761100, '175338', $c1, "Talaba uchun to'lov yaratildi", '229001', $S1, '21117', $G1['21117'], 24617); $s['b']['414']['payments'][] = pay(9001, $S1, $G1['21117'], 24617, 'Madinabonu');
mock_save($s); run();
$s = mock(); $s['b']['414']['acts'][] = act(5761150, '175339', $c2, "Talaba uchun to'lov yaratildi", '229001', $S1, '21117', $G1['21117'], 40000); $s['b']['414']['payments'][] = pay(9002, $S1, $G1['21117'], 40000, 'Surayyo');
mock_save($s); run();
$s = mock();
$s['center_acts'][] = act(5761202, '157859', $tA, "Talaba uchun to'lov tahrirlandi", '229001', $S1, '21117', $G1['21117'], null, "64 617 so'm");
$s['center_acts'][] = act(5761203, '157859', $tA, "Talaba uchun to'lov o'chirildi", '229001', $S1, '21117', $G1['21117'], null, "− 40,000 so'm");
foreach ($s['b']['414']['payments'] as &$p) if ($p['id'] === 9001) $p['amount'] = 64617; unset($p);
$s['b']['414']['payments'] = array_values(array_filter($s['b']['414']['payments'], function ($p) { return $p['id'] !== 9002; }));
$s['b']['414']['archive'][] = ['id' => 9002, 'student' => $S1, 'group' => $G1['21117'], 'amount' => 40000, 'date' => date('Y-m-01'), 'method' => 'Naqd pul', 'staff' => 'Surayyo', 'deleted_at' => hm(2), 'reason' => ''];
$s['history']['229001'] = [["Talaba uchun to'lov yaratildi", $G1['21117'], null, 24617, 'Madinabonu', substr($c1, 0, 16)], ["Talaba uchun to'lov yaratildi", $G1['21117'], null, 40000, 'Surayyo', substr($c2, 0, 16)],
    ["Talaba uchun to'lov tahrirlandi", $G1['21117'], 24617, 64617, 'Admin', hm(2)], ["Talaba uchun to'lov o'chirildi", $G1['21117'], null, 40000, 'Admin', hm(2)]];
mock_save($s); $t0 = count(tg()); $r = run(); run();
$i1 = incs("student_id = '229001' AND kind NOT IN ('created')");
chk('ONE incident for the deletion + edit: "To\'lov ko\'chirildi" (not 2-3 separate ones)', count($i1) === 1 && $i1[0]['kind'] === 'moved', array_map(function ($x) { return [$x['kind'], $x['actor'], $x['sources'], $x['amount'], $x['old_amount'], $x['new_amount']]; }, $i1));
$m = $i1[0] ?? [];
chk('actor = Admin (from the log), NOT Surayyo (creator of the 40,000) and NOT Madinabonu (creator of the 24,617)', ($m['actor'] ?? '') === 'Admin' && in_array($m['actor_src'] ?? '', ['jurnal', 'tarix'], true), $m);
chk('creator shown separately: "Yaratgan: Surayyo"', ($m['creator'] ?? '') === 'Surayyo', $m['creator'] ?? null);
chk('branch = Yangibozor Markaz (from group #21117), not OXFORD Bo\'zqal\'a', (int)($m['branch_id'] ?? 0) === $YB && ($m['branch_src'] ?? '') === 'guruh', [$m['branch_id'] ?? null, $m['branch_src'] ?? null]);
chk('amounts: 40,000 moved, 24,617 -> 64,617', abs((float)$m['amount'] - 40000) < 1 && abs((float)$m['old_amount'] - 24617) < 1 && abs((float)$m['new_amount'] - 64617) < 1, $m);
chk('🟡 yellow (total paid unchanged), no Telegram alert for it', ($m['severity'] ?? '') === 'yellow' && !array_filter(array_slice(tg(), $t0), function ($t) use ($S1) { return strpos($t, $S1) !== false; }), [$m['severity'] ?? null, array_slice(tg(), $t0)]);
chk('sources merged: jurnal + arxiv (+ snapshot / Tarix), confidence ✅', strpos($m['sources'], 'jurnal') !== false && strpos($m['sources'], 'arxiv') !== false && $m['confidence'] === 'confirmed', $m['sources']);
chk('center-wide Admin rows (same row id in both branch views) stored ONCE, seen in both', (int)db()->query("SELECT COUNT(*) FROM lcup_acts WHERE act_id IN ('5761202','5761203')")->fetchColumn() === 2 && strpos((string)db()->query("SELECT seen_in FROM lcup_acts WHERE act_id = '5761203'")->fetchColumn(), ',') !== false, db()->query("SELECT act_id, branch_id, seen_in FROM lcup_acts WHERE act_id IN ('5761202','5761203')")->fetchAll());
chk('no incident of this student under OXFORD Bo\'zqal\'a', !incs("student_id = '229001' AND branch_id = $OX"));
chk('no event / incident names Surayyo as the deleter or Madinabonu as the editor', !incs("student_id = '229001' AND kind IN ('deleted','moved','edited') AND actor IN ('Surayyo','Madinabonu')") && !db()->query("SELECT COUNT(*) FROM lcup_events WHERE student_id = '229001' AND type IN ('payment_deleted','payment_edited') AND staff IN ('Surayyo','Madinabonu')")->fetchColumn());

echo "== CASE 2 (real case, anonymised): Admin deletes a Bo'zqal'a student's payment (created by Dilnoza), read first in the Oxford LC view\n";
$s = mock(); $tB = tm(3);
$s['center_acts'][] = act(5761300, '157859', $tB, "Talaba uchun to'lov o'chirildi", '287001', $S2, '30001', $G2['30001'], null, "− 50 000 so'm");
$s['b']['415']['payments'] = array_values(array_filter($s['b']['415']['payments'], function ($p) { return $p['id'] !== 8501; }));
$s['b']['415']['archive'][] = ['id' => 8501, 'student' => $S2, 'group' => $G2['30001'], 'amount' => 50000, 'date' => date('Y-m-01'), 'method' => 'Naqd pul', 'staff' => 'Dilnoza', 'deleted_at' => hm(3), 'reason' => ''];
$s['history']['287001'] = [["Talaba uchun to'lov yaratildi", $G2['30001'], null, 50000, 'Dilnoza', hm(500)], ["Talaba uchun to'lov o'chirildi", $G2['30001'], null, 50000, 'Admin', hm(3)]];
mock_save($s); $t0 = count(tg()); run(); run();
$i2 = incs("student_id = '287001' AND kind = 'deleted'");
chk('ONE deletion incident (jurnal + arxiv merged)', count($i2) === 1 && strpos($i2[0]['sources'], 'jurnal') !== false && strpos($i2[0]['sources'], 'arxiv') !== false, array_map(function ($x) { return [$x['kind'], $x['actor'], $x['sources'], $x['branch_id']]; }, incs("student_id = '287001'")));
chk("actor Admin, creator Dilnoza, branch OXFORD Bo'zqal'a (group #30001), 🔴", ($i2[0]['actor'] ?? '') === 'Admin' && ($i2[0]['creator'] ?? '') === 'Dilnoza' && (int)($i2[0]['branch_id'] ?? 0) === $OX && ($i2[0]['severity'] ?? '') === 'red', $i2[0] ?? null);
$msgs = array_values(array_filter(array_slice(tg(), $t0), function ($t) use ($S2) { return strpos($t, $S2) !== false; }));
chk('exactly ONE Telegram alert for it, with "Kim: Admin · Yaratgan: Dilnoza" and the right branch', count($msgs) === 1 && strpos($msgs[0], 'Kim: Admin') !== false && strpos($msgs[0], 'Yaratgan: Dilnoza') !== false && strpos($msgs[0], "OXFORD Bo'zqal'a") !== false && strpos($msgs[0], 'Manbalar') !== false, $msgs);

echo "== snapshot-only deletion (no log row, no archive row) -> 'Noma\\'lum (aniqlanmoqda)' -> actor from Tarix, one alert with the real actor\n";
$s = mock(); $tC = tm(4);
$s['b']['414']['payments'] = array_values(array_filter($s['b']['414']['payments'], function ($p) { return $p['id'] !== 8002; }));
$s['history']['229003'] = [["Talaba uchun to'lov o'chirildi", $G1['21118'], null, 70000, 'Surayyo', hm(0)]];
mock_save($s); db()->exec("UPDATE lcup_payments SET student_id = '229003' WHERE pay_id = '8002'"); $t0 = count(tg());
for ($k = 0; $k < 4; $k++) run();
$i3 = incs("student_name = '$S3' AND kind = 'deleted'");
chk('one deletion incident', count($i3) === 1, array_map(function ($x) { return [$x['kind'], $x['actor'], $x['sources']]; }, incs("student_name = '$S3'")));
chk('actor filled in from the student\'s Tarix (Surayyo), ✅ confirmed, sources snapshot + Tarix', ($i3[0]['actor'] ?? '') === 'Surayyo' && $i3[0]['actor_src'] === 'tarix' && strpos($i3[0]['sources'], 'tarix') !== false && $i3[0]['confidence'] === 'confirmed', $i3[0] ?? null);
chk('the creator in the list (Madinabonu) is never shown as the deleter', ($i3[0]['creator'] ?? '') === 'Madinabonu' && $i3[0]['actor'] !== 'Madinabonu');
$m3 = array_values(array_filter(array_slice(tg(), $t0), function ($t) use ($S3) { return strpos($t, $S3) !== false; }));
chk('one Telegram alert, sent after the Tarix look-up, naming Surayyo (not "Noma\'lum")', count($m3) === 1 && strpos($m3[0], 'Kim: Surayyo') !== false, $m3);

echo "== payment moved to ANOTHER student (same minute, same person) = 🔴\n";
$s = mock(); $tD = tm(5);
$s['b']['414']['payments'][] = pay(9101, 'Test Talaba Delta', $G1['21118'], 30000, 'Madinabonu'); mock_save($s); run();
$s = mock();
$s['b']['414']['acts'][] = act(5762001, '175338', $tD, "Talaba uchun to'lov o'chirildi", '229004', 'Test Talaba Delta', '21118', $G1['21118'], null, "− 30 000 so'm");
$s['b']['414']['acts'][] = act(5762002, '175338', $tD, "Talaba uchun to'lov yaratildi", '229005', 'Test Talaba Epsilon', '21118', $G1['21118'], 30000);
$s['b']['414']['payments'] = array_values(array_filter($s['b']['414']['payments'], function ($p) { return $p['id'] !== 9101; })); $s['b']['414']['payments'][] = pay(9102, 'Test Talaba Epsilon', $G1['21118'], 30000, 'Madinabonu');
$s['history']['229004'] = [["Talaba uchun to'lov o'chirildi", $G1['21118'], null, 30000, 'Madinabonu', substr($tD, 0, 16)]]; $s['history']['229005'] = [["Talaba uchun to'lov yaratildi", $G1['21118'], null, 30000, 'Madinabonu', substr($tD, 0, 16)]];
mock_save($s); run();
$d = incs("student_id = '229004'"); $e = incs("student_id = '229005'");
chk('deletion -> "Boshqa talabaga ko\'chirildi" 🔴, the receiving payment is linked to it', ($d[0]['kind'] ?? '') === 'moved_other' && $d[0]['severity'] === 'red' && (int)$d[0]['related_id'] === (int)($e[0]['id'] ?? -1) && strpos($e[0]['flags'], 'moved_in') !== false, [$d, $e]);

echo "== Balans tahrirlandi (name without link): ≤10 so'm = 🟡 yaxlitlash, more = 🔴\n";
$s = mock();
$s['b']['414']['acts'][] = act(5763001, '175338', tm(6), 'Balans tahrirlandi', null, 'Test Balans Talaba', null, null, null, '-285 000 → -285 001');
$s['b']['414']['acts'][] = act(5763002, '175338', tm(6), 'Balans tahrirlandi', null, 'Test Balans Talaba', null, null, null, '-285 001 → -185 001');
mock_save($s); run();
$b = incs("kind = 'balance_edit'");
chk('student resolved by name from the debtors list (#900012), branch Yangibozor', count($b) === 2 && $b[0]['student_id'] === '900012' && (int)$b[0]['branch_id'] === $YB, array_map(function ($x) { return [$x['student_id'], $x['branch_id'], $x['old_amount'], $x['new_amount'], $x['severity']]; }, $b));
chk('−1 so\'m = 🟡 "Yaxlitlash", +100 000 = 🔴', ($b[0]['severity'] ?? '') === 'yellow' && strpos($b[0]['flags'], 'rounding') !== false && ($b[1]['severity'] ?? '') === 'red', $b);

echo "== minus edit resolved via Tarix (old -> new), then the debt drop it caused is explained\n";
$s = mock(); $tM = tm(7);
$s['b']['414']['acts'][] = act(5764001, '175338', $tM, "Talaba uchun minus to'lov tahrirlandi");
foreach ($s['b']['414']['debtors'] as &$x) if ($x['student_id'] === '900013') $x['balance'] = -250000; unset($x);
$s['history']['900013'] = [["Talaba uchun minus to'lov tahrirlandi", $G1['21118'], -300000, -250000, 'Madinabonu', hm(7)]];
mock_save($s); run(); run();
$mi = incs("kind = 'charge_edit' AND student_id = '900013'");
chk('incident: student #900013, actor Madinabonu, −300 000 → −250 000, 🔴 (charge reduced)', count($mi) === 1 && $mi[0]['actor'] === 'Madinabonu' && abs((float)$mi[0]['old_amount'] + 300000) < 1 && abs((float)$mi[0]['new_amount'] + 250000) < 1 && $mi[0]['severity'] === 'red', array_map(function ($x) { return [$x['student_id'], $x['actor'], $x['old_amount'], $x['new_amount'], $x['severity'], $x['sources']]; }, incs("kind = 'charge_edit'")));
db()->exec("UPDATE lcup_debt_pending SET at = '" . date('Y-m-d H:i:s', time() - 3600) . "', from_at = '" . date('Y-m-d H:i:s', time() - 3700) . "'"); run(); run();
chk('…and the matching debt decrease is explained (no "Qarz sababsiz kamaydi" for #900013)', !incs("kind = 'debt' AND student_id = '900013'") && db()->query("SELECT state FROM lcup_debt_pending WHERE student_id = '900013'")->fetchColumn() === 'explained', db()->query("SELECT student_id, state FROM lcup_debt_pending")->fetchAll());

echo "== debt went down with nothing behind it -> 🔴 'Qarz sababsiz kamaydi' (actor Noma'lum); a payment explains the other one\n";
$s = mock();
foreach ($s['b']['414']['debtors'] as &$x) { if ($x['student_id'] === '900010') $x['balance'] = -100000; if ($x['student_id'] === '900011') $x['balance'] = -150000; } unset($x);
$s['b']['414']['acts'][] = act(5765001, '175339', tm(1), "Talaba uchun to'lov yaratildi", '900011', 'Test Qarzdor Ikki', '21118', $G1['21118'], 50000); $s['b']['414']['payments'][] = pay(9201, 'Test Qarzdor Ikki', $G1['21118'], 50000, 'Surayyo');
$s['history']['900011'] = [["Talaba uchun to'lov yaratildi", $G1['21118'], null, 50000, 'Surayyo', hm(1)]];
mock_save($s); run();
db()->exec("UPDATE lcup_debt_pending SET at = '" . date('Y-m-d H:i:s', time() - 3600) . "', from_at = '" . date('Y-m-d H:i:s', time() - 3700) . "' WHERE state = 'open'"); $t0 = count(tg()); run(); run();
$dx = incs("kind = 'debt'");
chk('#900010 (−100 000 with no row): one red incident, actor unknown', count($dx) === 1 && $dx[0]['student_id'] === '900010' && $dx[0]['severity'] === 'red' && $dx[0]['actor'] === null && abs((float)$dx[0]['amount'] - 100000) < 1, array_map(function ($x) { return [$x['student_id'], $x['amount'], $x['severity']]; }, $dx));
chk('#900011 (−50 000 = the payment) explained, no incident', db()->query("SELECT state FROM lcup_debt_pending WHERE student_id = '900011'")->fetchColumn() === 'explained');
chk('Telegram for the unexplained one', (bool)array_filter(array_slice(tg(), $t0), function ($t) { return strpos($t, 'Qarz sababsiz kamaydi') !== false; }), array_slice(tg(), $t0));

echo "== no duplicates on the next runs\n";
$n0 = count(incs()); $t0 = count(tg()); run(); run(); chk('incidents and alerts stay the same', count(incs()) === $n0 && count(tg()) === $t0, [count(incs()) - $n0, array_slice(tg(), $t0)]);

echo "== Rahbar akkaunti (leader): logged, 🟡, own filter, ONE off-hours alert per period\n";
setting_set('lcup.leader_names', 'Admin'); setting_set('lcup.leader_from', date('H:i', time() + 3600)); setting_set('lcup.leader_to', date('H:i', time() + 7200));
foreach (incs("actor = 'Admin'") as $x) lcup_inc_recompute((int)$x['id']);
chk("Admin's incidents are now leader = 1 and 🟡 (case 2 included)", !incs("actor = 'Admin' AND (leader = 0 OR severity = 'red')"), array_map(function ($x) { return [$x['id'], $x['kind'], $x['severity'], $x['leader']]; }, incs("actor = 'Admin'")));
$s = mock(); $s['center_acts'][] = act(5766001, '157859', tm(1), "Talaba uchun to'lov yaratildi", '229001', $S1, '21117', $G1['21117'], 10000); $s['center_acts'][] = act(5766002, '157859', tm(1), "Talaba uchun to'lov yaratildi", '229001', $S1, '21117', $G1['21117'], 20000);
$s['b']['414']['payments'][] = pay(9301, $S1, $G1['21117'], 10000, 'Admin'); $s['b']['414']['payments'][] = pay(9302, $S1, $G1['21117'], 20000, 'Admin');
$s['history']['229001'][] = ["Talaba uchun to'lov yaratildi", $G1['21117'], null, 10000, 'Admin', hm(1)]; $s['history']['229001'][] = ["Talaba uchun to'lov yaratildi", $G1['21117'], null, 20000, 'Admin', hm(1)]; mock_save($s); $t0 = count(tg()); run(); run();
$off = array_values(array_filter(array_slice(tg(), $t0), function ($t) { return strpos($t, 'Rahbar akkaunti ish vaqtidan tashqari') !== false; }));
chk('two actions outside the leader hours -> exactly ONE Telegram alert', count($off) === 1, array_slice(tg(), $t0));

echo "== re-processing of OLD wrong events (as the previous version stored them)\n";
$old = function ($type, $src, $staff, $bid, $amount, $time, $key, $extra = []) use ($S1) { return lcup_event_add(array_merge(['type' => $type, 'source' => $src, 'staff' => $staff, 'branch_id' => $bid, 'student_id' => '229777', 'student_name' => 'Test Talaba Old', 'group_name' => 'Math kids Test 1/3/5 9:00', 'amount' => $amount, 'lcup_time' => $time, 'details' => 'eski', 'dedupe_key' => $key, 'info_only' => true], $extra)); };
$to = tm(30);
$old('payment_deleted', 'archive', 'Surayyo', $YB, 40000, $to, 'arch|1|old1');                       // old: archive HODIM stored as staff
$old('log_delete', 'worker_report', 'Admin', $YB, -40000, $to, 'act|' . $YB . '|7770001');            // old: the same log row stored per branch ...
$old('log_delete', 'worker_report', 'Admin', $OX, -40000, $to, 'act|' . $OX . '|7770001');            // ... also under OXFORD Bo'zqal'a
$old('payment_deleted', 'snapshot', 'Surayyo', $YB, 40000, null, 'miss|1|old1', ['flags' => ['weak']]);
$s = mock(); $s['history']['229777'] = [["Talaba uchun to'lov o'chirildi", 'Math kids Test 1/3/5 9:00', null, 40000, 'Admin', substr($to, 0, 16)]]; mock_save($s);
$r = lcup_reprocess_all();
$io = incs("student_id = '229777'");
chk('4 old events -> ONE incident (jurnal + arxiv + snapshot)', count($io) === 1 && strpos($io[0]['sources'], 'jurnal') !== false && strpos($io[0]['sources'], 'arxiv') !== false && strpos($io[0]['sources'], 'snapshot') !== false, array_map(function ($x) { return [$x['kind'], $x['actor'], $x['sources'], $x['branch_id']]; }, $io));
chk('…actor Admin (not Surayyo), creator Surayyo, branch Yangibozor (group map), not OXFORD Bo\'zqal\'a', ($io[0]['actor'] ?? '') === 'Admin' && ($io[0]['creator'] ?? '') === 'Surayyo' && (int)($io[0]['branch_id'] ?? 0) === $YB, $io[0] ?? null);
$cr = lcup_inc_corrections((int)($io[0]['id'] ?? 0)); $fields = array_count_values(array_column($cr, 'field'));
chk('corrections stored next to the originals: actor (Surayyo -> Admin, reason HODIM = creator), branch (OXFORD -> Yangibozor), duplicates', ($fields['actor'] ?? 0) >= 2 && ($fields['branch'] ?? 0) >= 1 && ($fields['duplicate'] ?? 0) >= 3 && (bool)array_filter($cr, function ($c) { return $c['field'] === 'actor' && $c['original'] === 'Surayyo' && $c['corrected'] === 'Admin' && strpos($c['reason'], 'YARATGAN') !== false; }), array_map(function ($c) { return [$c['field'], $c['original'], $c['corrected']]; }, $cr));
chk('the original events are untouched (staff still "Surayyo" in the archive event)', db()->query("SELECT staff FROM lcup_events WHERE dedupe_key = 'arch|1|old1'")->fetchColumn() === 'Surayyo');
$c0 = (int)db()->query("SELECT COUNT(*) FROM lcup_corrections")->fetchColumn(); $tg0 = count(tg()); $r2 = lcup_reprocess_all();
chk('re-processing again adds nothing and sends no Telegram', (int)db()->query("SELECT COUNT(*) FROM lcup_corrections")->fetchColumn() === $c0 && count(tg()) === $tg0 && $r2['incidents'] === $r['incidents'], [$r, $r2]);
chk('the two real cases survive re-processing unchanged', ($x = incs("student_id = '229001' AND kind = 'moved'")) && $x[0]['actor'] === 'Admin' && (int)$x[0]['branch_id'] === $YB && ($y = incs("student_id = '287001' AND kind = 'deleted'")) && (int)$y[0]['branch_id'] === $OX);

echo "== accuracy check (fresh Tarix): correct data = N/N; a wrong actor is caught and corrected\n";
db()->exec("UPDATE lcup_incidents SET first_detected = '" . date('Y-m-d H:i:s', time() - 3600) . "'"); lcup_state_del('acc_pick_' . date('Y-m-d'));
$c = LcupClient::fromConfig(); $sum = ['warnings' => [], 'errors' => []];
foreach (lcup_map_rows() as $mrow) { $c->switch_branch((string)$mrow['lcup_key'], (string)$mrow['lcup_name'], lcup_setting('worker_url')); lcup_accuracy_due($c, (int)$mrow['branch_id'], $sum, microtime(true) + 60, true); }
$ad = lcup_accuracy_day(date('Y-m-d'));
chk("all checked incidents correct ({$ad['ok']}/{$ad['checked']})", $ad['checked'] >= 3 && $ad['ok'] === $ad['checked'], array_map(function ($x) { return [$x['incident_id'], $x['note']]; }, $ad['rows']));
$wrong = incs("student_id = '287001' AND kind = 'deleted'")[0];
db()->prepare("UPDATE lcup_incidents SET actor = 'Dilnoza' WHERE id = ?")->execute([$wrong['id']]); $wi = lcup_inc_get((int)$wrong['id']);
$h = lcup_fetch_history($c, $OX, '287001'); $res = lcup_accuracy_check_one($wi, $h['rows'], date('Y-m-d', strtotime('+1 day')));
chk('a wrong actor is detected by the check', !$res['actor_ok'] && strpos($res['note'], 'Dilnoza') !== false, $res);
chk('…and the incident is corrected back to Admin from Tarix', lcup_inc_get((int)$wrong['id'])['actor'] === 'Admin', lcup_inc_get((int)$wrong['id']));

echo "== debt day report (Qarzdorlik o'zgarishi), synthetic snapshots on the 1st of a month\n";
$day = date('Y-m-01', strtotime('first day of last month')); file_put_contents("$T/debt_day.txt", $day . ' ' . $YB);
db()->prepare("INSERT INTO lcup_debt_snaps (branch_id, day, kind, taken_at, jami, cnt, rows_n) VALUES (?,?,?,?,?,?,?)")->execute([$YB, $day, 'start', "$day 00:05:00", -350000, 3, 3]); $sa = (int)db()->lastInsertId();
$ins = db()->prepare("INSERT INTO lcup_debt_snap_rows (snap_id, student_id, d_key, student_name, groups, balance, comment) VALUES (?,?,?,?,?,?,?)");
foreach ([['910001', -100000], ['910002', -150000], ['910003', -100000]] as [$sid, $b]) $ins->execute([$sa, $sid, $sid, "Test D $sid", 'G', $b, '']);
db()->prepare("INSERT INTO lcup_debt_snaps (branch_id, day, kind, taken_at, jami, cnt, rows_n) VALUES (?,?,?,?,?,?,?)")->execute([$YB, $day, 'end', "$day 23:55:00", -510000, 3, 3]); $sb = (int)db()->lastInsertId();
foreach ([['910001', -60000], ['910002', -300000], ['910004', -150000]] as [$sid, $b]) $ins->execute([$sb, $sid, $sid, "Test D $sid", 'G', $b, '']);
db()->prepare("INSERT INTO lcup_acts (act_id, branch_id, staff, act_time, act_text, type, student_id, student_name, amount, first_seen) VALUES ('d1', ?, 'Surayyo', ?, ?, 'payment_created', '910001', 'Test D 910001', 40000, ?)")->execute([$YB, "$day 10:00:00", "Talaba uchun to'lov yaratildi", lcup_now()]);
db()->prepare("INSERT INTO lcup_acts (act_id, branch_id, staff, act_time, act_text, type, student_id, student_name, amount, first_seen) VALUES ('d2', ?, 'Admin', ?, ?, 'payment_created', '910003', 'Test D 910003', 100000, ?)")->execute([$YB, "$day 11:00:00", "Talaba uchun to'lov yaratildi", lcup_now()]);
$dr = lcup_debt_day($YB, $day);
chk('start/end Jami and change in debt terms (−350 000 → −510 000 = +160 000)', $dr['start']['jami'] == -350000 && $dr['end']['jami'] == -510000 && abs($dr['change'] - 160000) < 1, [$dr['start'], $dr['end'], $dr['change']]);
chk("payments −140 000 (2 students), monthly charge on the 1st +300 000 (incl. the new debtor), nothing unexplained", abs(($dr['by']['tolov']['amount'] ?? 0) + 140000) < 1 && ($dr['by']['tolov']['n'] ?? 0) === 2 && abs(($dr['by']['oylik']['amount'] ?? 0) - 300000) < 1 && ($dr['by']['oylik']['n'] ?? 0) === 2 && abs($dr['unexplained']) < 1, $dr['by']);
chk('the new debtor is marked as joined, the one who left as left', (bool)array_filter($dr['students'], function ($x) { return $x['student_id'] === '910004' && $x['joined']; }) && (bool)array_filter($dr['students'], function ($x) { return $x['student_id'] === '910003' && $x['left']; }));
chk('sum of reasons = the Jami change (no hidden difference)', abs($dr['check'] ?? 99) < 1 && abs(array_sum(array_column($dr['by'], 'amount')) - $dr['change']) < 1, [$dr['check'], array_sum(array_column($dr['by'], 'amount'))]);
$day2 = date('Y-m-d', strtotime($day . ' +1 day'));
db()->prepare("INSERT INTO lcup_debt_snaps (branch_id, day, kind, taken_at, jami, cnt, rows_n) VALUES (?,?,?,?,?,?,?)")->execute([$YB, $day2, 'end', "$day2 23:55:00", -460000, 3, 3]); $sc = (int)db()->lastInsertId();
foreach ([['910001', -10000], ['910002', -300000], ['910004', -150000]] as [$sid, $b]) $ins->execute([$sc, $sid, $sid, "Test D $sid", 'G', $b, '']);
$dr2 = lcup_debt_day($YB, $day2);
chk('next day: start = previous end; a −50 000 debt drop with no row = ❓ Sababsiz (red in the report)', $dr2['start']['label'] === 'oldingi kun oxiri' && abs($dr2['unexplained'] + 50000) < 1 && isset($dr2['by']['sababsiz']), [$dr2['start'], $dr2['by']]);
chk('summary line for the 21:00 report', strpos((string)lcup_debt_summary_line($YB, $day), "Yangibozor Markaz: qarz") === 0 && strpos((string)lcup_debt_summary_line($YB, $day), 'oylik') !== false, lcup_debt_summary_line($YB, $day));

echo "== append-only\n";
foreach (["UPDATE lcup_corrections SET corrected = 'x'", "DELETE FROM lcup_corrections", "UPDATE lcup_debt_snaps SET jami = 0", "DELETE FROM lcup_debt_snap_rows", "UPDATE lcup_events SET staff = 'x'", "DELETE FROM lcup_events"] as $sql) { $e = ''; try { db()->exec($sql); } catch (Throwable $x) { $e = $x->getMessage(); } chk("refused: $sql", $e !== '', $e); }
$iid = (int)incs("severity = 'red' AND reviewed_at IS NULL")[0]['id'];
chk("ko'rildi once (incident + its original events), never rewritten", lcup_review($iid, 1, 'tekshirdim') && !lcup_review($iid, 1, 'boshqa') && (int)db()->query("SELECT COUNT(*) FROM lcup_events e JOIN lcup_incident_events l ON l.event_id = e.id WHERE l.incident_id = $iid AND e.review_note = 'tekshirdim'")->fetchColumn() >= 1);

echo "== read-only whitelist\n";
$ups = []; $posts = []; foreach (reqlog() as $l) if ($l[0] === 'POST') { $posts[] = $l[1]; foreach ($l[3] ?? [] as $u) $ups[] = $u; }
$allowed = '/^(callMethod:(openHistory\(\d+\)|loadMore|setPreset\((today|7|30)\)|setCat\(\w+\)|\$refresh|renderFilials|change_filial|save)|syncInput:(filial_id|mobile_number|password)|fireEvent:(staffChanged|studentTolovlarOchilsin|studentHistoryOchilsin|globalSetMobileNumber)(\(.*\))?)$/';
chk('every Livewire update is on the whitelist', !array_filter(array_unique($ups), function ($u) use ($allowed) { return !preg_match($allowed, $u); }), array_values(array_filter(array_unique($ups), function ($u) use ($allowed) { return !preg_match($allowed, $u); })));
chk('Tarix only via students.history-livewire; nothing sent to students.edit-livewire', in_array('/livewire/message/students.history-livewire', $posts, true) && !array_filter($posts, function ($p) { return strpos($p, 'edit-livewire') !== false; }));
chk('no secret in Telegram texts', !array_filter(tg(), function ($t) { return strpos($t, 'TESTTOKEN') !== false || strpos($t, 'test-pass') !== false; }));

echo "\n== $ok passed, $bad failed\n";
exit($bad ? 1 : 0);
