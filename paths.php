<?php
/**
 * Where private files live (outside the web root when possible).
 *
 *   <hosting>/domains/oxfordlc.uz/
 *       public_html/argent/        <- this application (web-accessible)
 *       argent_private/            <- config.php, settings.json (Telegram), LC-UP cookies, diagnostics,
 *                                     and, after "Xavfsiz joyga ko'chirish", the database and data/
 *
 * This file is loaded by db.php, so it must not depend on anything else.
 */

/** Folders that may hold the private area, best (outside public_html) first. */
function private_candidates(): array {
    $c = [];
    if (($e = getenv('ARGENT_PRIVATE_DIR')) !== false && $e !== '') $c[] = rtrim($e, '/\\');
    $p = __DIR__;
    for ($i = 0; $i < 8; $i++) {                       // .../public_html/argent  ->  .../argent_private
        if (basename($p) === 'public_html') { $c[] = dirname($p) . '/argent_private'; break; }
        $up = dirname($p); if ($up === $p) break; $p = $up;
    }
    $c[] = dirname(__DIR__) . '/argent_private';       // next to the app folder (may still be inside public_html)
    return array_values(array_unique($c));
}
/** Existing private folder: the one that has config.php, else the first one that exists, else null. */
function private_dir(): ?string {
    static $d = false;
    if ($d !== false) return $d;
    $d = null;
    foreach (private_candidates() as $c) if (is_file($c . '/config.php')) { $d = $c; return $d; }
    foreach (private_candidates() as $c) if (is_dir($c)) { $d = $c; return $d; }
    return $d;
}
/** Folder we would create for the private area (first candidate = outside public_html when it can be told). */
function private_dir_target(): string { return private_candidates()[0]; }

function path_inside_webroot(string $path): bool {
    $doc = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $real = realpath($path) ?: realpath(dirname($path));
    if ($doc && $real) { $doc = rtrim(str_replace('\\', '/', $doc), '/') . '/'; $real = rtrim(str_replace('\\', '/', $real), '/') . '/'; return strpos($real, $doc) === 0; }
    // CLI / unknown document root: a folder below a "public_html" directory counts as inside
    return (bool)preg_match('~/public_html(/|$)~', str_replace('\\', '/', $real ?: $path));
}
function private_dir_safe(): bool { $d = private_dir(); return $d !== null && !path_inside_webroot($d); }

/** Values from argent_private/config.php (`return [...]`). Never printed anywhere. */
function private_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    $d = private_dir();
    if ($d && is_file($d . '/config.php')) {
        try { $r = (static function ($f) { return include $f; })($d . '/config.php'); if (is_array($r)) $cfg = $r; }
        catch (Throwable $e) { error_log('argent config.php: ' . get_class($e)); }
    }
    return $cfg;
}
function cfg(string $key, $default = '') { $c = private_config(); return (isset($c[$key]) && $c[$key] !== '') ? $c[$key] : $default; }

/** Small key/value store written by the admin panel (Telegram token, chat id, app url). Lives in the private folder. */
function private_store_file(): ?string { $d = private_dir(); return $d ? $d . '/settings.json' : null; }
function private_get(string $key, $default = '') {
    static $s = null;
    if ($s === null) {
        $s = []; $f = private_store_file();
        if ($f && is_file($f)) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j)) $s = $j; }
    }
    return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
}
function private_set(array $kv): bool {
    $d = private_dir() ?: private_dir_target();
    if (!is_dir($d) && !@mkdir($d, 0750, true)) return false;
    if (!is_writable($d)) return false;
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    $f = $d . '/settings.json'; $cur = [];
    if (is_file($f)) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j)) $cur = $j; }
    foreach ($kv as $k => $v) { if ($v === null) unset($cur[$k]); else $cur[$k] = $v; }
    $ok = @file_put_contents($f, json_encode($cur, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    @chmod($f, 0640);
    return $ok;
}

/** Root of runtime files (sessions, backups, proofs, imports). Moves to the private area once "ko'chirish" ran. */
function data_root(): string {
    $d = private_dir();
    if ($d && is_dir($d . '/data') && !path_inside_webroot($d)) return $d . '/data';
    return __DIR__ . '/data';
}
function data_dir(string $sub = ''): string { return data_root() . ($sub !== '' ? '/' . ltrim($sub, '/') : ''); }
/** Creates the folder (+ deny-all .htaccess in data root) and returns it when writable. */
function data_dir_ready(string $sub = ''): ?string {
    $root = data_root();
    if (!is_dir($root)) @mkdir($root, 0750, true);
    if (is_dir($root) && !is_file($root . '/.htaccess')) @file_put_contents($root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    $d = data_dir($sub);
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return (is_dir($d) && is_writable($d)) ? $d : null;
}

/** Database file: ARGENT_DB env > argent_private/harvards_finance.sqlite (if it exists) > next to the app. */
function db_resolve_file(): string {
    if (($e = getenv('ARGENT_DB')) !== false && $e !== '') return $e;
    $d = private_dir();
    if ($d && is_file($d . '/harvards_finance.sqlite') && !path_inside_webroot($d)) return $d . '/harvards_finance.sqlite';
    return __DIR__ . '/harvards_finance.sqlite';
}
function db_is_private(): bool { return defined('DB_FILE') && !path_inside_webroot(dirname(DB_FILE)); }

/** Recursive delete of a folder tree we created (used only by the move tool). */
function rrmdir_safe(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) { if ($f === '.' || $f === '..') continue; $p = $dir . '/' . $f; is_dir($p) && !is_link($p) ? rrmdir_safe($p) : @unlink($p); }
    @rmdir($dir);
}
function rcopy_safe(string $src, string $dst): bool {
    if (is_file($src)) return @copy($src, $dst);
    if (!is_dir($dst) && !@mkdir($dst, 0750, true)) return false;
    foreach (scandir($src) ?: [] as $f) { if ($f === '.' || $f === '..') continue; if (!rcopy_safe($src . '/' . $f, $dst . '/' . $f)) return false; }
    return true;
}

/**
 * One-click fix for "the database may be publicly downloadable": copies the database and data/ to the private
 * folder (outside public_html), verifies the copy, then removes the web-accessible originals.
 * @return array [bool ok, string message]
 */
function security_move_to_private(): array {
    $target = private_dir_target();
    if (path_inside_webroot(data_root()) === false && db_is_private()) return [true, "Baza va data/ allaqachon public_html dan tashqarida: " . dirname(DB_FILE)];
    if (path_inside_webroot($target)) return [false, "public_html dan tashqarida papka aniqlanmadi. Hosting File Manager'da public_html bilan yonma-yon «argent_private» papkasini yarating va qayta urinib ko'ring."];
    if (!is_dir($target) && !@mkdir($target, 0750, true)) return [false, "Papka yaratib bo'lmadi: $target. Uni hosting File Manager orqali o'zingiz yarating (public_html bilan yonma-yon)."];
    if (!is_writable($target)) return [false, "Papka yozishga ochiq emas: $target"];
    $dst = $target . '/harvards_finance.sqlite';
    if (is_file($dst) && filesize($dst) > 0) return [false, "Nishon papkada baza allaqachon bor ($dst). Avval uni tekshiring yoki o'chiring."];
    $srcData = __DIR__ . '/data'; $dstData = $target . '/data';
    if (is_dir($dstData)) return [false, "Nishon papkada data/ allaqachon bor ($dstData)."];

    try {
        setting_set('exposure_check', '');                       // forget the cached warning (written before the copy so the copy has it)
        audit('security.move', 'database', null, ['to' => basename($target)]);
        // 1) database
        try { db()->exec("VACUUM INTO " . db()->quote($dst)); }
        catch (Throwable $e) { @unlink($dst); if (!@copy(DB_FILE, $dst)) return [false, "Bazani nusxalab bo'lmadi."]; }
        @chmod($dst, 0640);
        $chk = new PDO('sqlite:' . $dst); $chk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($chk->query("PRAGMA integrity_check")->fetchColumn() !== 'ok') { @unlink($dst); return [false, "Nusxa tekshiruvdan o'tmadi — hech narsa o'chirilmadi."]; }
        foreach (['users', 'transactions', 'branches'] as $t) {
            if ((int)$chk->query("SELECT COUNT(*) FROM $t")->fetchColumn() !== (int)db()->query("SELECT COUNT(*) FROM $t")->fetchColumn()) { @unlink($dst); return [false, "Nusxadagi qatorlar soni mos kelmadi ($t) — hech narsa o'chirilmadi."]; }
        }
        $chk = null;
        // 2) data/ (sessions, backups, proofs, imports)
        $sid = session_id();
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();     // flush the session file before copying it
        if (is_dir($srcData)) {
            if (!rcopy_safe($srcData, $dstData)) { rrmdir_safe($dstData); @unlink($dst); return [false, "data/ papkasini nusxalab bo'lmadi — hech narsa o'chirilmadi."]; }
        } else @mkdir($dstData, 0750, true);
        @file_put_contents($dstData . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        if (!is_file($target . '/.htaccess')) @file_put_contents($target . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        // 3) remove the public originals
        $left = [];
        foreach ([DB_FILE, DB_FILE . '-journal', DB_FILE . '-wal', DB_FILE . '-shm'] as $f) if (is_file($f) && !@unlink($f)) $left[] = basename($f);
        rrmdir_safe($srcData); if (is_dir($srcData)) $left[] = 'data/';
        // keep the admin logged in: reopen the session from its new place
        if ($sid && PHP_SAPI !== 'cli') { @session_save_path($dstData . '/sessions'); @session_id($sid); @session_start(); }
        if ($left) return [true, "Ko'chirildi, lekin eski fayllarni o'chirib bo'lmadi (qo'lda o'chiring): " . implode(', ', $left)];
        return [true, "Baza va data/ papkasi xavfsiz joyga ko'chirildi: $target"];
    } catch (Throwable $e) {
        return [false, "Ko'chirishda xatolik: " . $e->getMessage()];
    }
}
