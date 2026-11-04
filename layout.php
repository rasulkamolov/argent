<?php
/**
 * Shared layout + helpers (design system shell).
 * Usage in a page:
 *   require 'layout.php';  ui_head('Sarlavha');  ui_start('daily'); ... ui_end();
 */
date_default_timezone_set('Asia/Tashkent');
if (session_status() === PHP_SESSION_NONE) session_start();

const APP_NAME = 'OXFORD';
const APP_SUB  = "Bo'zqal'a";

/* ---------- Escaping & formatting ---------- */
function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt($n): string { return number_format((float)($n ?: 0), 0, '.', ' '); }
function fmt_signed($n): string { $n = (float)$n; return ($n < 0 ? '−' : ($n > 0 ? '+' : '')) . fmt(abs($n)); }
/** Compact money for tight spaces: 1 250 000 -> 1,25 mln */
function fmt_short($n): string {
    $n = (float)$n; $a = abs($n); $s = $n < 0 ? '−' : '';
    if ($a >= 1e9) return $s . rtrim(rtrim(number_format($a / 1e9, 2, ',', ''), '0'), ',') . ' mlrd';
    if ($a >= 1e6) return $s . rtrim(rtrim(number_format($a / 1e6, 2, ',', ''), '0'), ',') . ' mln';
    if ($a >= 1e4) return $s . rtrim(rtrim(number_format($a / 1e3, 1, ',', ''), '0'), ',') . ' ming';
    return $s . fmt($a);
}
function uz_month(int $m, bool $short = false): string {
    $l = ['yanvar','fevral','mart','aprel','may','iyun','iyul','avgust','sentabr','oktabr','noyabr','dekabr'];
    $s = ['yan','fev','mar','apr','may','iyn','iyl','avg','sen','okt','noy','dek'];
    return ($short ? $s : $l)[$m - 1];
}
function uz_weekday(string $ymd, bool $short = false): string {
    $l = ['Yakshanba','Dushanba','Seshanba','Chorshanba','Payshanba','Juma','Shanba'];
    $s = ['Yak','Dush','Sesh','Chor','Pay','Juma','Shan'];
    $w = (int)date('w', strtotime($ymd));
    return $short ? $s[$w] : $l[$w];
}
/** "4-noyabr 2026" */
function uz_date(string $ymd, bool $withYear = true): string {
    $t = strtotime($ymd);
    return (int)date('j', $t) . '-' . uz_month((int)date('n', $t)) . ($withYear ? ' ' . date('Y', $t) : '');
}
/** created_at is stored in UTC by SQLite CURRENT_TIMESTAMP -> local HH:MM */
function local_time(?string $utc): string {
    if (!$utc) return '';
    $t = strtotime($utc . ' UTC');
    return $t ? date('H:i', $t) : '';
}
function valid_date(?string $d): bool {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

/* ---------- Domain helpers ---------- */
function method_of(array $r): string { return !empty($r['bank']) ? 'bank' : (!empty($r['click']) ? 'click' : 'cash'); }
function method_meta(string $m): array {
    $all = [
        'cash'  => ['label' => 'Naqd',  'icon' => 'banknote',   'cls' => 'cash'],
        'click' => ['label' => 'Click', 'icon' => 'smartphone', 'cls' => 'click'],
        'bank'  => ['label' => 'Bank',  'icon' => 'building-2', 'cls' => 'bank'],
    ];
    return $all[$m] ?? $all['cash'];
}
function linkify(?string $text): string {
    $t = e($text);
    return preg_replace('~(https?://[^\s<>\)&]+)~i', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $t);
}
function is_salary_category(string $name): bool {
    $n = mb_strtolower($name);
    return str_contains($n, 'salary') || str_contains($n, 'oylik') || str_contains($n, 'maosh');
}

/* ---------- Assets & icons ---------- */
function asset(string $path): string {
    $f = __DIR__ . '/' . $path;
    return $path . (is_file($f) ? '?v=' . filemtime($f) : '');
}
function icon(string $name, string $cls = ''): string {
    return '<svg class="i ' . e($cls) . '" aria-hidden="true"><use href="assets/icons.svg#' . e($name) . '"/></svg>';
}

/* ---------- Flash messages ---------- */
function flash_set(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function flash_get(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

/* ---------- Auth guards ---------- */
function require_login(): void { if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; } }
function require_admin(): void { if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: index.php'); exit; } }

/* ---------- Page chrome ---------- */
function ui_head(string $title, array $o = []): void {
    $full = $title . ' — ' . APP_NAME;
    ?><!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($full) ?></title>
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#171a23" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" href="logo.png">
<link rel="apple-touch-icon" href="logo.png">
<script>try{var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
<?= $o['head'] ?? '' ?>
</head>
<?php
}

function ui_nav_items(string $role): array {
    $items = [['daily', 'index.php', 'Kunlik', 'calendar-days', 'Kunlik hisob']];
    if ($role === 'admin') {
        $items[] = ['reports',   'hisobot.php',   'Hisobot',   'file-text',  'Hisobotlar'];
        $items[] = ['analytics', 'analitika.php', 'Analitika', 'chart-line', 'Analitika'];
        $items[] = ['admin',     'admin.php',     'Admin',     'settings',   'Admin panel'];
    }
    return $items;
}

function ui_brand(): string {
    return '<a class="brand" href="index.php" aria-label="' . e(APP_NAME) . '"><span class="brand-mark"><img src="logo.png" alt="" width="30" height="30"></span>'
        . '<span><span class="brand-name">' . e(APP_NAME) . ' <span>' . e(APP_SUB) . '</span></span></span></a>';
}

/** Opens <body>, sidebar (desktop), topbar (mobile) and <main>. */
function ui_start(string $active, array $o = []): void {
    $role = $_SESSION['role'] ?? 'staff';
    $user = $_SESSION['username'] ?? '';
    $items = ui_nav_items($role);
    $bnav = count($items) > 1;
    ?><body class="<?= $bnav ? 'has-bnav' : '' ?>">
<div class="app">
  <aside class="sidebar no-print" aria-label="Asosiy navigatsiya">
    <?= ui_brand() ?>
    <nav class="nav" aria-label="Bo'limlar">
      <div class="eyebrow nav-label">Bo'limlar</div>
      <?php foreach ($items as [$key, $href, $short, $ic, $long]): ?>
        <a href="<?= e($href) ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e($long) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="user-chip">
        <span class="avatar avatar-sm"><?= e(mb_substr($user, 0, 1)) ?></span>
        <span class="grow"><span class="fw-7 fs-md truncate d-block" style="display:block"><?= e($user) ?></span><span class="badge <?= $role === 'admin' ? 'badge-primary' : 'badge-in' ?>"><?= e($role) ?></span></span>
      </div>
      <div class="row gap-2">
        <button class="btn btn-sm grow" type="button" data-theme-toggle aria-label="Mavzuni almashtirish"><?= icon('moon', 'i-sm') ?> Mavzu</button>
        <a class="btn btn-sm btn-danger-soft" href="index.php?logout=true" aria-label="Chiqish"><?= icon('log-out', 'i-sm') ?> Chiqish</a>
      </div>
    </div>
  </aside>

  <div class="grow" style="min-width:0">
    <header class="topbar no-print">
      <?= ui_brand() ?>
      <div class="row gap-1">
        <button class="btn btn-ghost btn-icon" type="button" data-theme-toggle aria-label="Mavzuni almashtirish"><?= icon('moon') ?></button>
        <a class="btn btn-ghost btn-icon" href="index.php?logout=true" aria-label="Chiqish"><?= icon('log-out') ?></a>
      </div>
    </header>
    <main class="main <?= $bnav ? '' : 'no-bnav' ?>" id="main">
<?php
}

function ui_end(array $o = []): void {
    $role = $_SESSION['role'] ?? 'staff';
    $active = $o['active'] ?? '';
    $items = ui_nav_items($role);
    ?>
    </main>
  </div>
</div>
<?php if (count($items) > 1): ?>
<nav class="bnav no-print" aria-label="Pastki navigatsiya">
  <?php foreach ($items as [$key, $href, $short, $ic]): ?>
    <a href="<?= e($href) ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($short) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?= $o['after'] ?? '' ?>
<?php ui_footer($o); ?>
<?php
}

/** Confirm dialog, toasts, scripts. Also used by pages without the shell (login). */
function ui_footer(array $o = []): void {
    $flash = flash_get();
    ?>
<dialog class="modal modal-sm" id="confirmDialog" aria-labelledby="confirmTitle">
  <div class="modal-box">
    <div class="row" style="align-items:flex-start;gap:14px">
      <span class="icon-tile out" style="flex:none"><?= icon('triangle-alert') ?></span>
      <div class="grow">
        <h3 class="modal-title" id="confirmTitle">Tasdiqlang</h3>
        <p class="text-2 mt-1" id="confirmMsg"></p>
      </div>
    </div>
    <div class="row gap-2 mt-4" style="justify-content:flex-end">
      <button type="button" class="btn" data-close>Bekor qilish</button>
      <button type="button" class="btn btn-danger" id="confirmOk">Ha</button>
    </div>
  </div>
</dialog>
<div class="toasts" id="toasts" aria-live="polite"></div>
<script>window.__ICONS='assets/icons.svg';<?= $flash ? 'window.__flash=' . json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';' : '' ?></script>
<script src="<?= e(asset('assets/app.js')) ?>"></script>
<?= $o['scripts'] ?? '' ?>
</body>
</html>
<?php
}

/** Sub navigation between Hisobot and Analitika, keeping the active filter query string. */
function ui_report_subnav(string $active, string $qs = ''): void {
    $q = $qs !== '' ? '?' . $qs : '';
    ?>
<div class="subnav no-print" role="navigation" aria-label="Hisobot turlari">
  <a href="hisobot.php<?= e($q) ?>" <?= $active === 'reports' ? 'aria-current="page"' : '' ?>><?= icon('file-text', 'i-sm') ?> Hisobot</a>
  <a href="analitika.php<?= e($q) ?>" <?= $active === 'analytics' ? 'aria-current="page"' : '' ?>><?= icon('chart-line', 'i-sm') ?> Grafik analitika</a>
</div>
<?php
}
