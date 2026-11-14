<?php
/**
 * Re-processes EVERYTHING stored with the current accuracy rules (actor only from log/Tarix, branch from group/student,
 * one incident per real action, moved payments). The original events are never changed; corrections are added as separate records.
 * No Telegram messages are sent for history.
 *
 *   php tools/lcup_reprocess.php                 rebuild incidents + corrections (offline, no LC-UP requests)
 *   php tools/lcup_reprocess.php --tarix         ... then ALSO read students' Tarix from LC-UP (read-only) to fill unknown actors and
 *                                                the pending "minus to'lov tahrirlandi" rows, in batches, until done or --max-minutes
 *   php tools/lcup_reprocess.php --tarix --max-minutes=20
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ARGENT_NO_SESSION', 1);
require_once __DIR__ . '/../lcup_sync.php';
$opt = getopt('', ['tarix', 'max-minutes:']);
echo "Qayta ishlash boshlandi (" . DB_FILE . ")\n";
$r = lcup_reprocess_all(function ($n, $t) { echo "  $n / $t yozuv\n"; });
printf("Asl yozuvlar: %d → hodisalar: %d (oldin: %d)\nKo'chirilgan to'lovlar: %d · log qatorlari filiali yangilandi: %d\nYangi tuzatishlar: %d %s\nAniqlanmagan minus tahrirlar: %d · %.1f s\n",
    $r['events'], $r['incidents'], $r['old_incidents'], $r['moved'], $r['acts_branch'], $r['corrections_new'], json_encode($r['by_field'], JSON_UNESCAPED_UNICODE), $r['pending_minus'], $r['seconds']);
if (!isset($opt['tarix'])) { echo "Tarix o'qilmadi (--tarix bilan ishga tushiring). Cron har daqiqa ham bu ishni bosqichma-bosqich qiladi.\n"; exit(0); }
$until = time() + 60 * max(1, (int)($opt['max-minutes'] ?? 30)); $round = 0;
$c = LcupClient::fromConfig();
do {
    $round++; $sum = ['warnings' => [], 'errors' => []]; $deadline = microtime(true) + 50;
    foreach (lcup_map_rows() as $m) {
        $bid = (int)$m['branch_id'];
        try {
            $c->switch_branch((string)$m['lcup_key'], (string)$m['lcup_name'], lcup_setting('worker_url'));
            lcup_sync_groups($c, $bid, $sum, $deadline);
            lcup_resolve_charge_edits($c, $bid, $sum, $deadline);
            lcup_incidents_process(true);
            lcup_incident_tarix($c, $bid, $sum, $deadline);
        } catch (LcupError $e) { echo "  {$m['branch_name']}: " . $e->getMessage() . "\n"; if (in_array($e->kind, ['login', 'ratelimit', 'config'], true)) break 2; }
    }
    $p = (int)db()->query("SELECT COUNT(*) FROM lcup_acts WHERE type = 'charge_edited' AND state IN ('pending','unresolved')")->fetchColumn();
    $u = (int)db()->query("SELECT COUNT(*) FROM lcup_incidents WHERE actor IS NULL AND student_id IS NOT NULL AND kind IN ('created','edited','deleted','moved','moved_other','charge_edit','balance_edit') AND tarix_tries < 4")->fetchColumn();
    echo "  $round-bosqich: aniqlanmagan minus tahrir $p · xodimi noma'lum hodisa $u · so'rovlar {$c->requests}\n";
    foreach (array_slice($sum['warnings'], 0, 3) as $w) echo "    ! $w\n";
} while (($p > 0 || $u > 0) && time() < $until);
// re-derive once more so every incident uses the Tarix evidence just stored (still no Telegram for history)
foreach (db()->query("SELECT id FROM lcup_incidents")->fetchAll(PDO::FETCH_COLUMN) as $id) lcup_inc_recompute((int)$id, true);
echo "Tayyor.\n";
