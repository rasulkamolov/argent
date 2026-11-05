<?php
/**
 * Payment proofs for Click / Bank transfers: screenshots, PDF receipts and common documents.
 * Files live in data/proofs (never directly web-accessible) and are served by proof.php after a branch check.
 *
 * Safety rules: only whitelisted types; the stored extension is derived from the DETECTED type (never from the
 * uploaded name), so a script can never be saved as .php; non-image/PDF files are always served as downloads.
 */
require_once __DIR__ . '/auth.php';

const PROOF_MAX_BYTES = 15 * 1024 * 1024;
const PROOF_MAX_SIDE = 1600;

/** ext => [mime, kind(image|pdf|file), inline?] */
function proof_types(): array {
    return [
        'jpg'  => ['image/jpeg', 'image', true],  'png' => ['image/png', 'image', true], 'webp' => ['image/webp', 'image', true], 'gif' => ['image/gif', 'image', true],
        'pdf'  => ['application/pdf', 'pdf', true],
        'doc'  => ['application/msword', 'file', false], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'file', false],
        'xls'  => ['application/vnd.ms-excel', 'file', false], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'file', false],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'file', false], 'rtf' => ['application/rtf', 'file', false],
        'txt'  => ['text/plain', 'file', false], 'csv' => ['text/csv', 'file', false],
    ];
}
const PROOF_ALLOWED_LABEL = 'rasm (JPG, PNG, WEBP), PDF, Word, Excel, TXT/CSV';

function proof_dir(): ?string {
    $d = __DIR__ . '/data/proofs';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (is_dir($d) && !is_file(__DIR__ . '/data/.htaccess')) @file_put_contents(__DIR__ . '/data/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    return (is_dir($d) && is_writable($d)) ? $d : null;
}
/** Absolute path of a stored proof (guards against path tricks), or null. */
function proof_abs(?string $rel): ?string {
    if (!$rel || !preg_match('~^\d{4}/\d{2}/[A-Za-z0-9_]+\.([a-z0-9]{2,4})$~', $rel, $m) || !isset(proof_types()[$m[1]])) return null;
    $f = __DIR__ . '/data/proofs/' . $rel;
    return is_file($f) ? $f : null;
}
/** ['kind' => image|pdf|file, 'mime' => ..., 'inline' => bool, 'ext' => ...] for a stored proof path. */
function proof_info(?string $rel): array {
    $ext = strtolower(pathinfo((string)$rel, PATHINFO_EXTENSION)); $t = proof_types()[$ext] ?? ['application/octet-stream', 'file', false];
    return ['kind' => $t[1], 'mime' => $t[0], 'inline' => $t[2], 'ext' => $ext];
}
/** HTML data-attributes the viewer needs: data-proof-view="id" data-proof-kind=".." data-proof-name=".." */
function proof_attrs(array $tx): string {
    $i = proof_info($tx['proof'] ?? null);
    return 'data-proof-view="' . (int)$tx['id'] . '" data-proof-kind="' . e($i['kind']) . '" data-proof-name="' . e($tx['proof_name'] ?? '') . '"';
}
function proof_since(): string { return (string)setting('proof_since', '2000-01-01'); }
/** Must a cashier attach a proof for this branch? (admins/managers are never forced) */
function proof_required(int $branchId): bool {
    $u = auth_user();
    return $u && $u['role'] === 'staff' && setting('require_proof', '1', $branchId) === '1';
}
/** SQL fragment: Click/Bank transactions that should have a proof but don't (alias t). */
function proof_missing_sql(string $a = 't'): string {
    return "($a.click = 1 OR $a.bank = 1) AND ($a.proof IS NULL OR $a.proof = '') AND $a.date >= " . db()->quote(proof_since());
}
function proof_is_missing(array $tx): bool {
    return (!empty($tx['click']) || !empty($tx['bank'])) && empty($tx['proof']) && ($tx['date'] ?? '') >= proof_since();
}
function proof_clean_name(string $n): string {
    $n = preg_replace('/[^\p{L}\p{N}\s._\-()]+/u', '', basename(str_replace('\\', '/', $n)));
    $n = trim(preg_replace('/\s+/', ' ', $n), " .");
    return mb_substr($n !== '' ? $n : 'fayl', 0, 80);
}

/** Detect one of the allowed types from the file's content (+ its extension only to tell zip-based formats apart). */
function proof_detect(string $tmp, string $origName): ?string {
    $h = (string)@file_get_contents($tmp, false, null, 0, 4096);
    if ($h === '') return null;
    $info = @getimagesize($tmp);
    if ($info) { $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif']; if (defined('IMAGETYPE_WEBP')) $map[IMAGETYPE_WEBP] = 'webp'; return $map[$info[2]] ?? null; }
    if (strncmp($h, '%PDF-', 5) === 0 || strpos(substr($h, 0, 1024), '%PDF-') !== false) return 'pdf';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (strncmp($h, "\xD0\xCF\x11\xE0", 4) === 0 && in_array($ext, ['doc', 'xls'], true)) return $ext;               // legacy Office
    if (strncmp($h, "PK\x03\x04", 4) === 0 && in_array($ext, ['docx', 'xlsx', 'odt'], true)) return $ext;           // zip-based documents
    if (strncmp($h, '{\\rtf', 5) === 0) return 'rtf';
    if (in_array($ext, ['txt', 'csv'], true) && strpos($h, "\0") === false) return $ext;                             // plain text
    return null;
}

/**
 * Validate + store an uploaded proof.
 * @return array [relativePath|null, errorMessage|null, displayName|null]   (null,null,null) = nothing uploaded
 */
function proof_save(?array $f, int $branchId): array {
    if (!$f || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return [null, null, null];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) return [null, "Fayl hajmi juda katta (serverda ruxsat etilganidan oshdi).", null];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, "Faylni yuklashda xatolik (kod {$f['error']}).", null];
    if ($f['size'] > PROOF_MAX_BYTES) return [null, "Fayl juda katta (maks. 15 MB).", null];
    if (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli') return [null, "Noto'g'ri fayl.", null];
    $ext = proof_detect($f['tmp_name'], (string)($f['name'] ?? ''));
    if (!$ext) return [null, "Bu fayl turi ruxsat etilmagan. Ruxsat etilgan: " . PROOF_ALLOWED_LABEL . ".", null];
    $dir = proof_dir(); if (!$dir) return [null, "Fayl saqlanadigan papka (data/proofs) yozishga ochiq emas.", null];
    $sub = date('Y') . '/' . date('m');
    if (!is_dir("$dir/$sub")) @mkdir("$dir/$sub", 0750, true);
    $base = 'b' . $branchId . '_' . bin2hex(random_bytes(10));
    $name = proof_clean_name((string)($f['name'] ?? 'fayl'));
    $ok = false;

    // Images: re-encode with GD when available (strips EXIF/GPS, bounds the size, flattens transparency).
    if (proof_types()[$ext][1] === 'image' && function_exists('imagecreatetruecolor')) {
        $src = null;
        if ($ext === 'jpg') $src = @imagecreatefromjpeg($f['tmp_name']);
        elseif ($ext === 'png') $src = @imagecreatefrompng($f['tmp_name']);
        elseif ($ext === 'gif') $src = @imagecreatefromgif($f['tmp_name']);
        elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($f['tmp_name']);
        if ($src) {
            if ($ext === 'jpg' && function_exists('exif_read_data')) {
                $ex = @exif_read_data($f['tmp_name']); $o = (int)($ex['Orientation'] ?? 1);
                if ($o === 3) $src = imagerotate($src, 180, 0); elseif ($o === 6) $src = imagerotate($src, -90, 0); elseif ($o === 8) $src = imagerotate($src, 90, 0);
            }
            $w = imagesx($src); $h = imagesy($src); $sc = min(1, PROOF_MAX_SIDE / max($w, $h));
            $nw = max(1, (int)round($w * $sc)); $nh = max(1, (int)round($h * $sc));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ext = 'jpg'; $ok = @imagejpeg($dst, "$dir/$sub/$base.$ext", 86);
            imagedestroy($src); imagedestroy($dst);
            if ($ok) $name = preg_replace('/\.\w+$/', '', $name) . '.jpg';
        }
    }
    if (!$ok) {   // PDFs/documents, or no GD: keep the validated original under a neutral, detected extension
        $moved = PHP_SAPI === 'cli' ? @copy($f['tmp_name'], "$dir/$sub/$base.$ext") : @move_uploaded_file($f['tmp_name'], "$dir/$sub/$base.$ext");
        if (!$moved) return [null, "Faylni saqlab bo'lmadi.", null];
    }
    @chmod("$dir/$sub/$base.$ext", 0640);
    return ["$sub/$base.$ext", null, $name];
}
function proof_delete(?string $rel): void { $f = proof_abs($rel); if ($f) @unlink($f); }

/**
 * Asks the web server (over HTTP, from the server itself) whether private files are publicly downloadable.
 * Returns a list of exposed items, [] when everything is protected, or null when it could not be checked.
 * The answer is cached for 12 hours.
 */
function web_exposure_check(): ?array {
    $cache = setting('exposure_check', '');
    if ($cache && preg_match('/^(\d+)\|(.*)$/', $cache, $m) && (int)$m[1] > time() - 43200) return $m[2] === '?' ? null : ($m[2] === '' ? [] : explode(',', $m[2]));
    if (empty($_SERVER['HTTP_HOST']) || !ini_get('allow_url_fopen')) return null;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 3, 'ignore_errors' => true, 'follow_location' => 0], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $exposed = []; $answered = 0;
    foreach (['harvards_finance.sqlite' => 'Ma\'lumotlar bazasi (harvards_finance.sqlite)', 'data/.htaccess' => 'data/ papkasi (isbot fayllari, zaxira nusxalar)'] as $path => $label) {
        $h = @get_headers($base . $path, 0, $ctx);
        if (!$h || !preg_match('~HTTP/\S+\s+(\d{3})~', $h[0], $mm)) continue;
        $answered++;
        if ((int)$mm[1] === 200) $exposed[] = $label;
    }
    $res = $answered === 0 ? null : $exposed;
    try { setting_set('exposure_check', time() . '|' . ($res === null ? '?' : implode(',', $exposed))); } catch (Throwable $e) {}
    return $res;
}
