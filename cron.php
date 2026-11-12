<?php
/**
 * LC-UP sync entry point. Run every minute:
 *   Hostinger cron (CLI):  /usr/bin/php /home/uXXXX/domains/oxfordlc.uz/public_html/argent/cron.php
 *   cron-job.org (HTTP):   https://oxfordlc.uz/argent/cron.php?token=<CRON_TOKEN from argent_private/config.php>
 * Answers with one plain-text line. HTTP 500 = the run failed (cron-job.org can e-mail you about that).
 */
define('ARGENT_NO_SESSION', 1);
require_once __DIR__ . '/lcup_sync.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
    $want = (string)cfg('CRON_TOKEN', '');
    if ($want === '') { http_response_code(503); echo "CRON_TOKEN argent_private/config.php da sozlanmagan\n"; exit; }
    $got = (string)($_GET['token'] ?? '');
    if (!hash_equals($want, $got)) { http_response_code(403); echo "Forbidden\n"; exit; }
}
$r = lcup_run(['trigger' => $cli ? 'cron-cli' : 'cron-http']);
// Do'konPro monitoring (read-only on the shop). Independent of LC-UP: its failure never hides the LC-UP result and vice versa.
$shopLine = '';
try { require_once __DIR__ . '/shop_report.php'; $sr = shop_run(['trigger' => $cli ? 'cron-cli' : 'cron-http']);
    $shopLine = $sr['skipped'] ? ' shop=skipped(' . $sr['skipped'] . ')' : ($sr['busy'] ? ' shop=busy' : ' shop=' . ($sr['ok'] ? 'ok' : 'FAIL') . ' shop_new_events=' . $sr['new_events']);
    if (!$sr['ok'] && !$cli) { $shopFail = $sr['errors']; } } catch (Throwable $e) { $shopLine = ' shop=FAIL'; $shopFail = ['Ichki xatolik: ' . get_class($e)]; error_log('shop run: ' . $e->getMessage()); }
if (!empty($r['busy'])) { echo "BUSY\n"; exit(0); }
$line = ($r['ok'] ? 'OK' : 'FAIL') . " requests={$r['requests']} new_events={$r['new_events']}" . ($r['errors'] ? ' errors=' . count($r['errors']) : '') . $shopLine;
if ((!$r['ok'] || !empty($shopFail)) && !$cli) { http_response_code(500); }
echo $line . "\n";
if (!$r['ok']) foreach ($r['errors'] as $e) echo ' - ' . $e . "\n";
foreach (($shopFail ?? []) as $e) echo ' - shop: ' . $e . "\n";
exit($r['ok'] && empty($shopFail) ? 0 : 1);
