<?php
/**
 * LC-UP webhook receiver (POST). LC-UP signs the raw body:  X-LC-UP-Signature: <hex HMAC-SHA256(body, LCUP_WEBHOOK_SECRET)>
 * ("sha256=" prefix accepted). Valid events are stored, then one sync run is started immediately so alerts are near-instant.
 */
define('ARGENT_NO_SESSION', 1);
require_once __DIR__ . '/lcup_sync.php';

header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
function wh_out(int $code, array $j): void { http_response_code($code); echo json_encode($j); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); wh_out(405, ['ok' => false, 'error' => 'POST only']); exit; }
$secret = (string)cfg('LCUP_WEBHOOK_SECRET', '');
if ($secret === '') { wh_out(503, ['ok' => false, 'error' => 'webhook secret not configured']); exit; }
$raw = file_get_contents('php://input', false, null, 0, 524289);
if ($raw === false || $raw === '' || strlen($raw) > 524288) { wh_out(413, ['ok' => false, 'error' => 'bad body size']); exit; }
$sig = strtolower(trim((string)($_SERVER['HTTP_X_LC_UP_SIGNATURE'] ?? '')));
if (strpos($sig, 'sha256=') === 0) $sig = substr($sig, 7);
if ($sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) { lcup_log('webhook: bad signature from ' . client_ip()); wh_out(401, ['ok' => false, 'error' => 'bad signature']); exit; }

$j = json_decode($raw, true); if (!is_array($j)) $j = ['_raw' => mb_substr($raw, 0, 500)];
$name = (string)($j['event'] ?? $j['type'] ?? $j['name'] ?? 'event');
$flat = function ($k) use ($j) { foreach ([$j, $j['data'] ?? [], $j['payload'] ?? []] as $a) if (is_array($a) && isset($a[$k]) && is_scalar($a[$k])) return (string)$a[$k]; return null; };
$bid = null; $fk = $flat('filial_id') ?? $flat('branch_id');
if ($fk !== null) { $q = db()->prepare("SELECT branch_id FROM lcup_branch_map WHERE lcup_key = ?"); $q->execute([$fk]); $bid = $q->fetchColumn() ?: null; }
$amount = $flat('amount') ?? $flat('summa') ?? $flat('sum');
$id = lcup_event_add(['type' => 'webhook', 'source' => 'webhook', 'branch_id' => $bid, 'student_id' => $flat('student_id'), 'student_name' => $flat('student_name') ?? $flat('name'),
    'amount' => $amount !== null && is_numeric($amount) ? (float)$amount : null, 'details' => 'Webhook: ' . mb_substr($name, 0, 80), 'raw' => mb_substr($raw, 0, 3000), 'info_only' => true, 'dedupe_key' => 'wh|' . sha1($raw)]);
wh_out(200, ['ok' => true, 'stored' => $id !== null]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_flush(); @flush(); }
try { lcup_run(['trigger' => 'webhook']); } catch (Throwable $e) { lcup_log('webhook sync: ' . get_class($e)); }
