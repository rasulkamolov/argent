<?php
require_once __DIR__ . '/layout.php';
require_admin();
$tmp = sys_get_temp_dir() . '/argent_dl_' . bin2hex(random_bytes(6)) . '.sqlite';
try { db()->exec("VACUUM INTO " . db()->quote($tmp)); }
catch (Throwable $e) { if (!@copy(DB_FILE, $tmp)) { flash_set('error', "Zaxira nusxani tayyorlab bo'lmadi."); header('Location: admin.php#settings'); exit; } }
audit('backup.download', 'database');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="argent_backup_' . date('Ymd_His') . '.sqlite"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp); @unlink($tmp); exit;
