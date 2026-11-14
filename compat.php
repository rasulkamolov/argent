<?php
/** Small polyfills so the app runs on PHP 7.4+ (Hostinger plans differ). */
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool { return $n === '' || substr($h, -strlen($n)) === $n; }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower($s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null) { return $len === null ? substr($s, $start) : substr($s, $start, $len); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return strlen($s); }
}
if (!function_exists('mb_convert_case')) {
    function mb_convert_case($s, $mode, $enc = null) { return ucwords(strtolower($s)); }
    if (!defined('MB_CASE_TITLE')) define('MB_CASE_TITLE', 2);
}
