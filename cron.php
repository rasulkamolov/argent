<?php
/**
 * Monitoring run (LC-UP + Do'konPro). Hostinger → Cron Jobs, every minute (* * * * *):
 *   /usr/bin/php /home/u180105715/domains/oxfordlc.uz/public_html/kirimchiqim/argent/cron.php
 * Command line only: a web request to this file is refused (there is no HTTP cron / token any more).
 * Prints one plain-text line; exit code 1 = the run failed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Not found\n"; exit; }
define('ARGENT_NO_SESSION', 1);
require_once __DIR__ . '/lcup_sync.php';

$r = lcup_run(['trigger' => 'cron-cli']);
// Do'konPro monitoring (read-only on the shop). Independent of LC-UP: its failure never hides the LC-UP result and vice versa.
$shopLine = ''; $shopFail = [];
try { require_once __DIR__ . '/shop_report.php'; $sr = shop_run(['trigger' => 'cron-cli']);
    $shopLine = $sr['skipped'] ? ' shop=skipped(' . $sr['skipped'] . ')' : ($sr['busy'] ? ' shop=busy' : ' shop=' . ($sr['ok'] ? 'ok' : 'FAIL') . ' shop_new_events=' . $sr['new_events']);
    if (!$sr['ok']) $shopFail = $sr['errors']; } catch (Throwable $e) { $shopLine = ' shop=FAIL'; $shopFail = ['Ichki xatolik: ' . get_class($e)]; error_log('shop run: ' . $e->getMessage()); }
if (!empty($r['busy'])) { echo "BUSY" . $shopLine . "\n"; exit(0); }
echo ($r['ok'] ? 'OK' : 'FAIL') . " requests={$r['requests']} new_events={$r['new_events']}" . ($r['errors'] ? ' errors=' . count($r['errors']) : '') . $shopLine . "\n";
foreach ($r['errors'] as $e) echo ' - ' . $e . "\n";
foreach ($shopFail as $e) echo ' - shop: ' . $e . "\n";
exit($r['ok'] && !$shopFail ? 0 : 1);
