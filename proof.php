<?php
/** Streams a payment screenshot after checking that the user may access the transaction's branch. */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/proofs.php';
require_login();
$t = tx_authorized((int)($_GET['id'] ?? 0));
$f = $t ? proof_abs($t['proof'] ?? null) : null;
if (!$f) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Skrinshot topilmadi"; exit; }
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($f));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: inline; filename="skrinshot_' . (int)$t['id'] . '.' . pathinfo($f, PATHINFO_EXTENSION) . '"');
readfile($f);
