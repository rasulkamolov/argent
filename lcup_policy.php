<?php
/**
 * THE WHITELIST. Everything the monitor is allowed to send to LC-UP besides plain GET requests is written down here, and only here.
 * Every other POST / Livewire call is refused by LcupClient before it leaves the server (see LcupClient::request / lw).
 *
 *   login   : the Livewire call of the login component
 *   filial  : the top-bar branch dropdown (selects which branch is shown; changes no data)
 *   view    : read-only calls on /admin/worker_reports (choose staff, period, category, "yana ko'rsatish") and the student "To'lovlar" tab
 *
 * Deliberately NOT allowed (examples): save, create*, store*, update*, edit*, delete*, destroy*, remove*, restore, and the payment
 * restore call  Livewire.emit('refresh', id).   Allowed names are matched EXACTLY, never by pattern.
 * Every request that passes (and every refusal) is written to the lcup_reqlog table (no values, no credentials).
 */
require_once __DIR__ . '/lcup_lib.php';

const LCUP_LW_ALLOWED = [
    'login'  => ['methods' => ['save'],                                                         'props' => ['mobile_number', 'password'], 'events' => ['globalSetMobileNumber']],
    'filial' => ['methods' => ['renderFilials', 'change_filial'],                               'props' => ['filial_id'],                 'events' => []],
    'view'   => ['methods' => ['openHistory', 'setPreset', 'setCat', 'loadMore', '$refresh'],   'props' => [],                            'events' => ['studentTolovlarOchilsin']],
];
/** Allowed parameter shapes per method (regex on the single parameter; null = no parameter). */
const LCUP_LW_PARAMS = [
    'openHistory' => '/^\d{1,12}$/', 'setPreset' => '/^(today|7|30)$/', 'setCat' => '/^(all|lead|student|payment|group|message|other)$/',
    'loadMore' => null, '$refresh' => null, 'renderFilials' => null, 'change_filial' => null, 'save' => null,
];
/** Event names that are refused when they come back from the server inside a cascade (e.g. the payment restore event 'refresh'). */
const LCUP_LW_FORBIDDEN = '/(delete|destroy|remove|restore|create|store|update|edit|insert|add|refresh$|^refresh|trash|save(?!$)|pay|sms|send|toggle|status)/i';

/** Throws LcupError('config') unless every update is on the whitelist for $purpose. $cascade = events emitted by an already-allowed call. */
function lcup_policy_check(string $purpose, array $updates, bool $cascade = false): void {
    $p = LCUP_LW_ALLOWED[$purpose] ?? null;
    if ($p === null) lcup_policy_refuse($purpose, 'noma\'lum maqsad');
    foreach ($updates as $u) {
        $t = $u['type'] ?? ''; $pl = $u['payload'] ?? [];
        if ($t === 'syncInput') { if (!in_array($pl['name'] ?? '', $p['props'], true)) lcup_policy_refuse($purpose, 'syncInput ' . ($pl['name'] ?? '?')); }
        elseif ($t === 'callMethod') {
            $m = (string)($pl['method'] ?? ''); $par = array_values((array)($pl['params'] ?? []));
            if (!in_array($m, $p['methods'], true) || !array_key_exists($m, LCUP_LW_PARAMS)) lcup_policy_refuse($purpose, 'callMethod ' . $m);
            $re = LCUP_LW_PARAMS[$m];
            if ($m === 'change_filial') { if (count($par) > 1 || (count($par) === 1 && !preg_match('/^\d{1,12}$/', (string)$par[0]))) lcup_policy_refuse($purpose, "callMethod $m: parametr mos emas"); }
            elseif ($re === null) { if ($par) lcup_policy_refuse($purpose, "callMethod $m: parametr bo'lmasligi kerak"); }
            elseif (count($par) !== 1 || !preg_match($re, (string)$par[0])) lcup_policy_refuse($purpose, "callMethod $m: parametr mos emas");
        }
        elseif ($t === 'fireEvent') {
            $ev = (string)($pl['event'] ?? '');
            $ok = in_array($ev, $p['events'], true) || ($cascade && preg_match('/^[A-Za-z0-9_.\-]{1,60}$/', $ev) && !preg_match(LCUP_LW_FORBIDDEN, $ev));
            if (!$ok) lcup_policy_refuse($purpose, 'fireEvent ' . $ev);
            if ($ev === 'studentTolovlarOchilsin' && (count((array)($pl['params'] ?? [])) !== 1 || !preg_match('/^\d{1,12}$/', (string)$pl['params'][0]))) lcup_policy_refuse($purpose, 'fireEvent parametr');
        }
        else lcup_policy_refuse($purpose, 'update turi ' . $t);
    }
}
function lcup_policy_refuse(string $purpose, string $what): void {
    lcup_reqlog('REFUSED', '', $purpose, $what, 0, 0);
    throw new LcupError("Faqat-o'qish qoidasi: «" . $what . "» oq ro'yxatda yo'q va yuborilmadi.", 'config');
}
/** One line per request. Never contains parameter values other than ids, never credentials. */
function lcup_reqlog(string $method, string $path, string $purpose, string $detail, int $status, int $ms): void {
    try {
        db()->prepare("INSERT INTO lcup_reqlog (at, method, path, purpose, detail, status, ms) VALUES (?,?,?,?,?,?,?)")->execute([lcup_now(), $method, mb_substr($path, 0, 200), $purpose, mb_substr(lcup_redact($detail), 0, 200), $status, $ms]);
        if (mt_rand(1, 200) === 1) db()->exec("DELETE FROM lcup_reqlog WHERE id < (SELECT MAX(id) - 20000 FROM lcup_reqlog)");
    } catch (Throwable $e) { /* logging must never break a sync */ }
}
/** Short, value-free description of Livewire updates for the log: "callMethod:openHistory(175338) syncInput:filial_id" */
function lcup_updates_summary(array $updates): string {
    $o = [];
    foreach ($updates as $u) { $pl = $u['payload'] ?? []; $t = $u['type'] ?? '?';
        if ($t === 'syncInput') $o[] = 'syncInput:' . ($pl['name'] ?? '?');
        elseif ($t === 'callMethod') $o[] = 'callMethod:' . ($pl['method'] ?? '?') . '(' . implode(',', array_map(function ($x) { return preg_match('/^[\w$-]{1,12}$/', (string)$x) ? (string)$x : '…'; }, (array)($pl['params'] ?? []))) . ')';
        elseif ($t === 'fireEvent') $o[] = 'fireEvent:' . ($pl['event'] ?? '?'); }
    return implode(' ', $o);
}
