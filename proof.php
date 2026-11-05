<?php
/**
 * Streams a payment proof after checking that the user may access the transaction's branch.
 *   proof.php?id=ID          images/PDF open inline, other files download
 *   proof.php?id=ID&dl=1     always download
 */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/proofs.php';
require_login();
$t = tx_authorized((int)($_GET['id'] ?? 0));
$f = $t ? proof_abs($t['proof'] ?? null) : null;
if (!$f) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Fayl topilmadi"; exit; }
$i = proof_info($t['proof']);
$inline = $i['inline'] && empty($_GET['dl']);
$name = ($t['proof_name'] ?? '') !== '' ? $t['proof_name'] : ('isbot_' . (int)$t['id'] . '.' . $i['ext']);
$ascii = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $name) ?: ('isbot_' . (int)$t['id'] . '.' . $i['ext']);
header('Content-Type: ' . ($inline || $i['kind'] !== 'file' ? $i['mime'] : 'application/octet-stream'));
header('Content-Length: ' . filesize($f));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
if ($i['kind'] !== 'pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
readfile($f);
