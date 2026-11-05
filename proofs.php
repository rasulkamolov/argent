<?php
/**
 * Payment-proof screenshots (Click / Bank transfers).
 * Files live in data/proofs (never directly web-accessible) and are served by proof.php after a branch check.
 */
require_once __DIR__ . '/auth.php';

const PROOF_MAX_BYTES = 15 * 1024 * 1024;
const PROOF_MAX_SIDE = 1600;

function proof_dir(): ?string {
    $d = __DIR__ . '/data/proofs';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (is_dir($d) && !is_file(__DIR__ . '/data/.htaccess')) @file_put_contents(__DIR__ . '/data/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    return (is_dir($d) && is_writable($d)) ? $d : null;
}
/** Absolute path of a stored proof (guards against path tricks), or null. */
function proof_abs(?string $rel): ?string {
    if (!$rel || !preg_match('~^\d{4}/\d{2}/[A-Za-z0-9_]+\.(jpg|png|webp|gif)$~', $rel)) return null;
    $f = __DIR__ . '/data/proofs/' . $rel;
    return is_file($f) ? $f : null;
}
function proof_since(): string { return (string)setting('proof_since', '2000-01-01'); }
/** Must a cashier attach a screenshot for this branch? (admins/managers are never forced) */
function proof_required(int $branchId): bool {
    $u = auth_user();
    return $u && $u['role'] === 'staff' && setting('require_proof', '1', $branchId) === '1';
}
/** SQL fragment: Click/Bank transactions that should have a screenshot but don't (alias t). */
function proof_missing_sql(string $a = 't'): string {
    return "($a.click = 1 OR $a.bank = 1) AND ($a.proof IS NULL OR $a.proof = '') AND $a.date >= " . db()->quote(proof_since());
}
function proof_is_missing(array $tx): bool {
    return (!empty($tx['click']) || !empty($tx['bank'])) && empty($tx['proof']) && ($tx['date'] ?? '') >= proof_since();
}

/**
 * Validate + store an uploaded screenshot.
 * @return array [relativePath|null, errorMessage|null]   (null,null) = nothing uploaded
 */
function proof_save(?array $f, int $branchId): array {
    if (!$f || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) return [null, "Rasm hajmi juda katta (serverda ruxsat etilganidan oshdi)."];
    if ($f['error'] !== UPLOAD_ERR_OK) return [null, "Rasmni yuklashda xatolik (kod {$f['error']})."];
    if ($f['size'] > PROOF_MAX_BYTES) return [null, "Rasm juda katta (maks. 15 MB)."];
    if (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli') return [null, "Noto'g'ri fayl."];
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) $types[IMAGETYPE_WEBP] = 'webp';
    if (!$info || !isset($types[$info[2]])) return [null, "Faqat rasm (JPG, PNG, WEBP) yuklang."];
    $dir = proof_dir(); if (!$dir) return [null, "Rasm saqlanadigan papka (data/proofs) yozishga ochiq emas."];
    $sub = date('Y') . '/' . date('m');
    if (!is_dir("$dir/$sub")) @mkdir("$dir/$sub", 0750, true);
    $base = 'b' . $branchId . '_' . bin2hex(random_bytes(10));
    $ext = $types[$info[2]];
    $ok = false;

    // Re-encode with GD when available: strips EXIF/GPS, bounds the size, flattens transparency.
    if (function_exists('imagecreatetruecolor') && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF] + (defined('IMAGETYPE_WEBP') ? [IMAGETYPE_WEBP] : []), true)) {
        $src = null;
        if ($info[2] === IMAGETYPE_JPEG) $src = @imagecreatefromjpeg($f['tmp_name']);
        elseif ($info[2] === IMAGETYPE_PNG) $src = @imagecreatefrompng($f['tmp_name']);
        elseif ($info[2] === IMAGETYPE_GIF) $src = @imagecreatefromgif($f['tmp_name']);
        elseif (function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($f['tmp_name']);
        if ($src) {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $ex = @exif_read_data($f['tmp_name']);
                $o = (int)($ex['Orientation'] ?? 1);
                if ($o === 3) $src = imagerotate($src, 180, 0); elseif ($o === 6) $src = imagerotate($src, -90, 0); elseif ($o === 8) $src = imagerotate($src, 90, 0);
            }
            $w = imagesx($src); $h = imagesy($src); $sc = min(1, PROOF_MAX_SIDE / max($w, $h));
            $nw = max(1, (int)round($w * $sc)); $nh = max(1, (int)round($h * $sc));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ext = 'jpg'; $ok = @imagejpeg($dst, "$dir/$sub/$base.$ext", 86);
            imagedestroy($src); imagedestroy($dst);
        }
    }
    if (!$ok) {   // no GD (or decode failed): keep the validated original
        $moved = PHP_SAPI === 'cli' ? @copy($f['tmp_name'], "$dir/$sub/$base.$ext") : @move_uploaded_file($f['tmp_name'], "$dir/$sub/$base.$ext");
        if (!$moved) return [null, "Rasmni saqlab bo'lmadi."];
    }
    @chmod("$dir/$sub/$base.$ext", 0640);
    return ["$sub/$base.$ext", null];
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
    foreach (['harvards_finance.sqlite' => 'Ma\'lumotlar bazasi (harvards_finance.sqlite)', 'data/.htaccess' => 'data/ papkasi (skrinshotlar, zaxira nusxalar)'] as $path => $label) {
        $h = @get_headers($base . $path, 0, $ctx);
        if (!$h || !preg_match('~HTTP/\S+\s+(\d{3})~', $h[0], $mm)) continue;
        $answered++;
        if ((int)$mm[1] === 200) $exposed[] = $label;
    }
    $res = $answered === 0 ? null : $exposed;
    try { setting_set('exposure_check', time() . '|' . ($res === null ? '?' : implode(',', $exposed))); } catch (Throwable $e) {}
    return $res;
}
