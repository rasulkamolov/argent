<?php
/**
 * Admin-only database downloads.
 *   backup.php              -> the whole database (all branches)
 *   backup.php?branch=ID    -> a standalone SQLite file containing ONLY that branch
 *                              (its transactions, cashiers, employees, categories, history).
 *                              Can be re-imported with Admin -> Import.
 */
require_once __DIR__ . '/layout.php';
require_admin();

$branchId = (int)($_GET['branch'] ?? 0);
$b = $branchId ? branch_get($branchId) : null;
if ($branchId && !$b) { flash_set('error', 'Filial topilmadi.'); header('Location: admin.php#branches'); exit; }

$tmp = sys_get_temp_dir() . '/argent_dl_' . bin2hex(random_bytes(6)) . '.sqlite';
try { db()->exec("VACUUM INTO " . db()->quote($tmp)); }
catch (Throwable $e) { if (!@copy(DB_FILE, $tmp)) { flash_set('error', "Zaxira nusxani tayyorlab bo'lmadi."); header('Location: admin.php#settings'); exit; } }

if ($b) {   // keep only this branch's rows
    $x = new PDO('sqlite:' . $tmp); $x->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $id = (int)$branchId;
    $x->exec("DELETE FROM transaction_history WHERE transaction_id NOT IN (SELECT id FROM transactions WHERE branch_id = $id)");
    $x->exec("DELETE FROM transactions WHERE branch_id <> $id OR branch_id IS NULL");
    $x->exec("DELETE FROM employees WHERE branch_id IS NOT $id");
    $x->exec("DELETE FROM categories WHERE branch_id IS NOT NULL AND branch_id <> $id");
    $x->exec("DELETE FROM cash_counts WHERE branch_id <> $id");
    $x->exec("DELETE FROM users WHERE role = 'admin' OR branch_id IS NOT $id");   // no admin accounts / hashes in a branch file
    $x->exec("DELETE FROM branches WHERE id <> $id");
    $x->exec("DELETE FROM audit_log WHERE branch_id IS NOT $id");
    $x->exec("DELETE FROM login_attempts");
    $x->exec("DELETE FROM settings WHERE key NOT LIKE 'b$id.%' AND key NOT IN ('staff_can_edit','staff_can_delete','require_proof','proof_since')");
    $x->exec("VACUUM");
    $x = null;
}
audit('backup.download', 'database', null, ['branch' => $b['name'] ?? 'all'], $branchId ?: null);
$slug = $b ? preg_replace('/[^a-z0-9]+/i', '-', $b['name']) : 'barcha-filiallar';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="argent_' . trim($slug, '-') . '_' . date('Ymd_His') . '.sqlite"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
exit;
