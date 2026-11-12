<?php
/**
 * Read-only HTTP client for LC-UP (Laravel + Livewire v2).
 *
 * Hard rules enforced HERE (not by convention):
 *   - every request is a GET, except the Livewire calls listed in lcup_policy.php (login / filial / view) — nothing else can be sent
 *   - only the configured LC-UP host is ever contacted
 *   - credentials come from argent_private/config.php and are never logged or shown
 */
require_once __DIR__ . '/lcup_lib.php';
require_once __DIR__ . '/lcup_parse.php';
require_once __DIR__ . '/lcup_policy.php';

class LcupError extends RuntimeException {
    public $kind;
    public function __construct(string $msg, string $kind = 'error') { parent::__construct($msg); $this->kind = $kind; }
}

/** "+998901234567" -> "+(998)-90-123-45-67" (what the login box's input mask produces). */
function lcup_format_phone(string $raw, string $format = 'mask'): string {
    $d = preg_replace('/\D+/', '', $raw);
    if ($format === 'raw') return $raw;
    $n = strlen($d) >= 12 && strpos($d, '998') === 0 ? substr($d, 3, 9) : (strlen($d) === 9 ? $d : '');
    if ($n === '') return $format === 'digits' ? $d : $raw;
    if ($format === 'digits') return '998' . $n;
    return '+(998)-' . substr($n, 0, 2) . '-' . substr($n, 2, 3) . '-' . substr($n, 5, 2) . '-' . substr($n, 7, 2);
}
function lcup_cookie_file(): ?string {
    $d = private_dir();
    if ($d && is_dir($d) && is_writable($d)) return $d . '/lcup_cookies.txt';
    $x = data_dir_ready('lcup'); return $x ? $x . '/lcup_cookies.txt' : null;
}

class LcupClient {
    public $base; public $requests = 0; public $trace = []; public $capture = null;   // capture: Diagnostika records WHAT was exchanged (no request values)
    private $jar; private $delayMs; private $last = 0.0; private $csrf = ''; private $host;

    public function __construct(string $base, ?string $jarFile, int $delayMs = 350) {
        if (!function_exists('curl_init')) throw new LcupError("PHP cURL kengaytmasi yo'q (hosting → PHP sozlamalari).", 'config');
        $this->base = rtrim($base, '/'); $this->host = parse_url($this->base, PHP_URL_HOST);
        if (!$this->host) throw new LcupError("LCUP_BASE_URL noto'g'ri.", 'config');
        $this->jar = $jarFile ?: tempnam(sys_get_temp_dir(), 'lcj'); $this->delayMs = max(0, $delayMs);
        if (is_file($this->jar)) @chmod($this->jar, 0600);
    }
    public static function fromConfig(): self {
        $base = (string)cfg('LCUP_BASE_URL', 'https://oxfordlc.lc-up.com');
        return new self($base, lcup_cookie_file(), (int)lcup_num('delay_ms'));
    }
    public function url(string $pathOrUrl, array $query = []): string {
        $u = preg_match('~^https?://~i', $pathOrUrl) ? $pathOrUrl : $this->base . '/' . ltrim($pathOrUrl, '/');
        if ($query) $u .= (strpos($u, '?') === false ? '?' : '&') . http_build_query($query);
        return $u;
    }

    /* ---------------------------------------------------------------- transport */
    private function request(string $method, string $url, array $o = []): array {
        $method = strtoupper($method); $purpose = $o['purpose'] ?? '';
        if ($method !== 'GET') {
            $okPost = $method === 'POST' && isset(LCUP_LW_ALLOWED[$purpose]) && preg_match('~/livewire/message/[\w.\-]+$~', $url)
                && ($purpose === 'login' ? (bool)preg_match('~/livewire/message/[\w.\-]*login[\w.\-]*$~i', $url)
                    : ($purpose === 'history' ? (lcup_setting('history_via_livewire') === '1' && (bool)preg_match('~/livewire/message/students\.history-livewire$~', $url)) : lcup_setting('allow_switch_post') === '1'));
            if (!$okPost) { lcup_reqlog('REFUSED', (string)parse_url($url, PHP_URL_PATH), $purpose ?: $method, $method . ' ruxsat etilmagan', 0, 0); throw new LcupError("Faqat-o'qish qoidasi: bu so'rov ($method) taqiqlangan.", 'config'); }
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (strcasecmp((string)$host, (string)$this->host) !== 0) throw new LcupError("Boshqa domenga so'rov yuborish taqiqlangan.", 'config');
        $gap = $this->delayMs / 1000 - (microtime(true) - $this->last);
        if ($gap > 0) usleep((int)($gap * 1e6));
        $t0 = microtime(true); $ch = curl_init($url);
        $hdr = ['Accept: ' . ($o['accept'] ?? 'text/html,application/xhtml+xml'), 'Accept-Language: uz,en;q=0.8'];
        foreach (($o['headers'] ?? []) as $h) $hdr[] = $h;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => $method === 'GET', CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'ArgentMonitor/1.0 (read-only; contact: oxfordlc.uz)',
            CURLOPT_HTTPHEADER => $hdr, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body'] ?? ''); }
        $body = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $eff = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        $this->last = microtime(true); $this->requests++;
        lcup_reqlog($method, (string)parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) ? '?' . parse_url($url, PHP_URL_QUERY) : ''), $purpose !== '' ? $purpose : 'get', (string)($o['detail'] ?? ''), $code, (int)round((microtime(true) - $t0) * 1000));
        $this->trace[] = [$method, parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) ? '?' . parse_url($url, PHP_URL_QUERY) : ''), $code];
        if ($body === false) throw new LcupError(lcup_redact("LC-UP ga ulanib bo'lmadi: $err"), 'network');
        if (strcasecmp((string)parse_url($eff, PHP_URL_HOST), (string)$this->host) !== 0) throw new LcupError("So'rov boshqa domenga yo'naltirildi.", 'network');
        if ($code === 429 || $code === 503) throw new LcupError("LC-UP so'rovlarni cheklayapti (HTTP $code). Keyinroq qayta uriniladi.", 'ratelimit');
        if ($code >= 500) throw new LcupError("LC-UP serveri xatosi (HTTP $code).", 'network');
        return ['status' => $code, 'url' => $eff, 'body' => (string)$body];
    }
    public function get(string $pathOrUrl, array $query = []): array { return $this->request('GET', $this->url($pathOrUrl, $query)); }

    public static function is_login_page(array $res): bool {
        $path = (string)parse_url($res['url'], PHP_URL_PATH);
        if (preg_match('~/login/?$~', $path)) return true;
        return strpos($res['body'], 'login-livewire') !== false || (strpos($res['body'], 'wire:model.defer="password"') !== false && strpos($res['body'], 'mobile_number') !== false);
    }
    /** GET with automatic re-login when the session has expired. */
    public function page(string $pathOrUrl, array $query = []): array {
        $r = $this->get($pathOrUrl, $query);
        if (self::is_login_page($r)) {
            $this->login();
            $r = $this->get($pathOrUrl, $query);
            if (self::is_login_page($r)) throw new LcupError("Login muvaffaqiyatli bo'ldi, lekin sahifa baribir login sahifasiga qaytardi.", 'login');
        }
        if ($r['status'] >= 400) throw new LcupError("Sahifa ochilmadi (HTTP {$r['status']}): " . (string)parse_url($r['url'], PHP_URL_PATH), 'layout');
        return $r;
    }

    /* ---------------------------------------------------------------- Livewire v2 */
    private function lw(string $purpose, array &$comp, array $updates, string $referer, bool $cascade = false): array {
        $name = $comp['fingerprint']['name'] ?? '';
        lcup_policy_check($purpose, $updates, $cascade, $name);                        // the whitelist: nothing else gets past this line
        $payload = json_encode(['fingerprint' => $comp['fingerprint'], 'serverMemo' => $comp['memo'], 'updates' => $updates], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = $this->request('POST', $this->base . '/livewire/message/' . $name, ['purpose' => $purpose, 'detail' => lcup_updates_summary($updates), 'body' => $payload, 'accept' => 'text/html, application/xhtml+xml',
            'headers' => ['Content-Type: application/json', 'X-Livewire: true', 'X-CSRF-TOKEN: ' . $this->csrf, 'X-Requested-With: XMLHttpRequest', 'Origin: ' . $this->base, 'Referer: ' . $referer]]);
        $j = json_decode($r['body'], true);
        if ($this->capture !== null) $this->capture[] = ['purpose' => $purpose, 'component' => $name, 'status' => $r['status'], 'update_types' => array_map(function ($u) { return $u['type'] . ':' . ($u['payload']['name'] ?? $u['payload']['method'] ?? $u['payload']['event'] ?? ''); }, $updates),
            'response' => is_array($j) ? ['effects' => array_map(function ($v) { return is_string($v) ? (strlen($v) > 200 ? '[' . strlen($v) . ' chars]' : $v) : $v; }, $j['effects'] ?? []), 'errors' => $j['serverMemo']['errors'] ?? null, 'data_keys' => array_keys($j['serverMemo']['data'] ?? [])] : mb_substr($r['body'], 0, 300)];
        if ($r['status'] === 419) throw new LcupError("LC-UP CSRF/sessiya xatosi (419) — qayta uriniladi.", 'login');
        if (!is_array($j)) throw new LcupError("LC-UP Livewire javobi JSON emas (HTTP {$r['status']}).", 'layout');
        foreach (($j['serverMemo'] ?? []) as $k => $v) { if ($k === 'data' && is_array($v) && isset($comp['memo']['data'])) $comp['memo']['data'] = array_replace($comp['memo']['data'], $v); else $comp['memo'][$k] = $v; }
        return $j;
    }
    public static function lw_id(): string { return substr(bin2hex(random_bytes(4)), 0, 4); }
    private static function lw_sync(string $name, $value): array { return ['type' => 'syncInput', 'payload' => ['id' => self::lw_id(), 'name' => $name, 'value' => $value]]; }
    private static function lw_call(string $method, array $params = []): array { return ['type' => 'callMethod', 'payload' => ['id' => self::lw_id(), 'method' => $method, 'params' => $params]]; }
    private static function lw_event(string $event, array $params): array { return ['type' => 'fireEvent', 'payload' => ['id' => self::lw_id(), 'event' => $event, 'params' => $params]]; }

    /** Logs in through the Livewire login component. Throws LcupError('login'|'config'|...) with a clear message. */
    public function login(): void {
        $phone = (string)cfg('LCUP_PHONE', ''); $pass = (string)cfg('LCUP_PASSWORD', '');
        if ($phone === '' || $pass === '') throw new LcupError("LCUP_PHONE / LCUP_PASSWORD argent_private/config.php da to'ldirilmagan.", 'config');
        $until = lcup_state_get('login_backoff_until');
        if ($until && time() < (int)$until) throw new LcupError("Login vaqtincha to'xtatilgan (blokirovkadan saqlanish uchun): oxirgi xato — " . lcup_state_get('login_last_error', '?') . ". Keyingi urinish " . date('H:i', (int)$until) . " da.", 'login');
        $fmt = (string)cfg('LCUP_PHONE_FORMAT', 'mask');
        $page = $this->get('/login');
        $comps = lcup_lw_components($page['body']); $comp = null;
        foreach ($comps as $c) if (stripos($c['name'], 'login') !== false || array_key_exists('mobile_number', $c['memo']['data'] ?? [])) { $comp = $c; break; }
        if (!$comp) {
            if (!self::is_login_page($page)) return;                                        // already signed in (cookie still valid)
            throw new LcupError("Login sahifasida Livewire login komponenti topilmadi — LC-UP sahifasi o'zgargan bo'lishi mumkin. «Diagnostika» ni bosing.", 'layout');
        }
        $this->csrf = lcup_csrf_from($page['body']);
        if ($this->csrf === '') throw new LcupError("Login sahifasida CSRF token topilmadi.", 'layout');
        $fail = function (string $msg) { $n = (int)lcup_state_get('login_fail', 0) + 1; lcup_state_set('login_fail', $n); lcup_state_set('login_last_error', lcup_redact($msg));
            $wait = [0, 2, 5, 15, 30][min($n, 4)] * 60; if ($wait) lcup_state_set('login_backoff_until', time() + $wait); throw new LcupError(lcup_redact($msg), 'login'); };
        $ref = $this->base . '/login'; $val = lcup_format_phone($phone, $fmt);
        // the browser emits this event while the phone number is typed; replay it when the component listens for it
        if (in_array('globalSetMobileNumber', (array)($comp['effects']['listeners'] ?? []), true)) {
            $j = $this->lw('login', $comp, [self::lw_event('globalSetMobileNumber', [$val])], $ref);
            if (!empty($comp['memo']['data']['show_filials'])) $fail("Login sahifasi filial tanlashni talab qilyapti (show_filials). Bu holat hozircha qo'llab-quvvatlanmaydi — «Diagnostika» natijasini yuboring.");
        }
        $j = $this->lw('login', $comp, [self::lw_sync('mobile_number', $val), self::lw_sync('password', $pass), self::lw_call('save')], $ref);
        $redir = $j['effects']['redirect'] ?? null;
        if (!$redir) {
            $msgs = [];
            foreach (($j['serverMemo']['errors'] ?? []) as $f => $m) $msgs[] = is_array($m) ? implode(' ', $m) : (string)$m;
            if (!$msgs && !empty($j['effects']['html'])) { $t = lc_clean(strip_tags((string)$j['effects']['html'])); if (preg_match("/(xato|noto'g'ri|topilmadi|parol|invalid|incorrect)[^.!]{0,80}/iu", lc_apos($t), $m)) $msgs[] = $m[0]; }
            $fail("LC-UP login qabul qilmadi" . ($msgs ? ': ' . implode('; ', array_slice($msgs, 0, 3)) : " (javobda yo'naltirish yo'q — telefon formati yoki parolni tekshiring; LCUP_PHONE_FORMAT = mask/digits/raw)"));
        }
        $r = $this->get((string)$redir);
        if (self::is_login_page($r)) $fail("Login javobida yo'naltirish bor edi, lekin sessiya ochilmadi.");
        lcup_state_del('login_fail'); lcup_state_del('login_backoff_until'); lcup_state_del('login_last_error');
        $this->csrf = lcup_csrf_from($r['body']) ?: $this->csrf;
    }

    /* ---------------------------------------------------------------- read-only Livewire "view" session (whitelisted calls only) */
    /** GET a page and index its Livewire components by id. */
    public function view_page(string $pathOrUrl): array {
        $r = $this->page($pathOrUrl); $this->csrf = lcup_csrf_from($r['body']) ?: $this->csrf;
        $comps = []; foreach (lcup_lw_components($r['body']) as $c) $comps[$c['id']] = $c;
        return ['url' => $r['url'], 'html' => $r['body'], 'comps' => $comps];
    }
    /** Runs whitelisted updates on component $id, then delivers the events the server emitted to the listening components. Returns every HTML fragment received. */
    public function view_run(array &$page, string $id, array $updates): array {
        if (!isset($page['comps'][$id])) throw new LcupError("Livewire komponenti topilmadi (sahifa tuzilishi o'zgargan bo'lishi mumkin).", 'layout');
        $frags = []; $queue = [[$id, $updates, false]]; $guard = 0;
        while ($queue && $guard++ < 12) {
            [$cid, $ups, $casc] = array_shift($queue);
            $j = $this->lw('view', $page['comps'][$cid], $ups, $page['url'], $casc);
            $html = (string)($j['effects']['html'] ?? '');
            if ($html !== '') { $frags[] = $html; foreach (lcup_lw_components('<div>' . $html . '</div>') as $nc) if (!isset($page['comps'][$nc['id']])) $page['comps'][$nc['id']] = $nc; }
            foreach ((array)($j['effects']['emits'] ?? []) as $em) {
                $ev = (string)($em['event'] ?? ''); if ($ev === '' || !empty($em['ancestorsOnly'])) continue;
                foreach ($page['comps'] as $oid => $oc) {
                    if (!empty($em['selfOnly']) && $oid !== $cid) continue;
                    if (!empty($em['to']) && ($oc['fingerprint']['name'] ?? '') !== $em['to']) continue;
                    if (!in_array($ev, (array)($oc['effects']['listeners'] ?? []), true)) continue;
                    $queue[] = [$oid, [self::lw_event($ev, array_values((array)($em['params'] ?? [])))], true];
                }
            }
        }
        return $frags;
    }
    /** Livewire.emit(event, params) as the browser does it: one fireEvent to every component listening for it. Only whitelisted events pass. */
    public function view_emit(array &$page, string $event, array $params): array {
        $frags = [];
        foreach ($page['comps'] as $oid => $oc) {
            if (!in_array($event, (array)($oc['effects']['listeners'] ?? []), true)) continue;
            $cn = (string)($oc['fingerprint']['name'] ?? '');
            if (preg_match(LCUP_LW_DENY_COMPONENT, $cn)) { lcup_reqlog('SKIPPED', '', 'view', "komponent o'tkazib yuborildi (taqiqlangan): $cn / $event", 0, 0); continue; }
            $j = $this->lw('view', $page['comps'][$oid], [self::lw_event($event, $params)], $page['url']);
            if (!empty($j['effects']['html'])) $frags[] = (string)$j['effects']['html'];
        }
        return $frags;
    }
    /** Student "Tarix" tab: Livewire.emit('studentHistoryOchilsin', id) delivered ONLY to students.history-livewire. Every other listener is skipped and logged. */
    public function view_history(array &$page, string $sid): array {
        $frags = [];
        foreach ($page['comps'] as $oid => $oc) {
            $cn = (string)($oc['fingerprint']['name'] ?? '');
            if (!in_array('studentHistoryOchilsin', (array)($oc['effects']['listeners'] ?? []), true)) continue;
            if ($cn !== 'students.history-livewire') { lcup_reqlog('SKIPPED', '', 'history', "komponent oq ro'yxatda yo'q: $cn", 0, 0); continue; }
            $j = $this->lw('history', $page['comps'][$oid], [self::lw_event('studentHistoryOchilsin', [(string)$sid])], $page['url']);
            if (!empty($j['effects']['html'])) $frags[] = (string)$j['effects']['html'];
        }
        return $frags;
    }
    public static function call(string $method, array $params = []): array { return self::lw_call($method, $params); }
    public function adopt_csrf(string $html): void { $this->csrf = lcup_csrf_from($html) ?: $this->csrf; }

    /* ---------------------------------------------------------------- branches */
    public $resolved_key = null;
    /**
     * Makes the mapped LC-UP branch active and returns the verified HTML of $probePath for it.
     * LC-UP's dropdown is a Livewire <select wire:model="filial_id" wire:change="change_filial"> that initially lists ONLY the current
     * branch; the others are loaded by a "renderFilials" call. Switching is therefore a (view-state) POST, which this client
     * performs only when the admin ticked "allow_switch_post" — and only with these three names taken from the page itself.
     */
    public function switch_branch(string $key, string $name, string $probePath): string {
        $r = $this->page($probePath); $html = $r['body'];
        $sel = lcup_find_branch_select($html, array_filter([$key]), [$name]);
        if (!$sel) throw new LcupError("Filial tanlagich sahifada topilmadi. «Diagnostika» ni bosing va natijani yuboring.", 'branch');
        $isTarget = function (string $h) use ($key, $name) {
            $s = lcup_find_branch_select($h, array_filter([$key]), [$name]); if (!$s) return false;
            if ($key !== '' && ($s['current'] ?? null) === $key) return true;
            $nm = lcup_current_branch_name($h) ?? ($s['current_name'] ?? null);
            return $name !== '' && $nm !== null && lc_norm($nm) === lc_norm($name);
        };
        $this->resolved_key = $key !== '' ? $key : null;
        if ($isTarget($html)) { if ($key === '' && !empty($sel['current'])) $this->resolved_key = (string)$sel['current']; return $html; }
        if (lcup_setting('allow_switch_post') !== '1')
            throw new LcupError("«" . ($name ?: $key) . "» filialini ochish uchun LC-UP dagi filial tanlagichga so'rov (POST) yuborish kerak. «Faqat-o'qish» qoidasi tufayli yuborilmadi. Admin → LC-UP da «Filialni almashtirishga ruxsat» ni yoqing (u faqat joriy filialni almashtiradi, ma'lumotni o'zgartirmaydi), yoki har bir filial uchun alohida LC-UP akkaunti ishlating.", 'branch');
        $tpl = trim((string)lcup_setting('switch_url'));
        if ($tpl !== '' && $key !== '') { $u = str_replace('{id}', rawurlencode($key), $tpl); $this->get($u[0] === '?' ? $probePath . $u : $u); }
        else {
            if ($sel['mode'] !== 'livewire_change' || ($sel['prop'] ?? '') === '' || !preg_match('/filial|branch/i', $sel['prop'] . ' ' . $sel['method']))
                throw new LcupError("Filial tanlagich kutilgan ko'rinishda emas (wire:model=filial_id / wire:change=change_filial). «Diagnostika» ni yuboring.", 'branch');
            $comp = null; foreach (lcup_lw_components($html) as $c) if ($c['id'] === $sel['lw_id']) { $comp = $c; break; }
            if (!$comp) throw new LcupError("Filial komponenti topilmadi (wire:initial-data yo'q).", 'branch');
            $this->csrf = lcup_csrf_from($html) ?: $this->csrf; $ref = $this->url($probePath);
            if ($key === '') {                                  // the other branches are only listed after the dropdown gets focus
                $fm = $sel['focus_method'] ?? ''; if ($fm === '' || !preg_match('/filial|branch/i', $fm)) throw new LcupError("Filial ro'yxatini yuklash usuli topilmadi. Mapping'da filial ID sini qo'lda kiriting.", 'branch');
                $j = $this->lw('filial', $comp, [self::lw_call($fm)], $ref);
                $opt = lcup_find_branch_select('<html><body>' . ($j['effects']['html'] ?? '') . '</body></html>', [], [$name]);
                foreach (($opt['options'] ?? []) as $o) if ($o['value'] !== '' && lc_norm($o['label']) === lc_norm($name)) $key = (string)$o['value'];
                if ($key === '') throw new LcupError("«$name» filiali LC-UP ro'yxatida topilmadi. Mavjud: " . implode(', ', array_map(function ($o) { return $o['label'] . '=' . $o['value']; }, $opt['options'] ?? [])), 'branch');
                $this->resolved_key = $key;
            }
            $this->lw('filial', $comp, [self::lw_sync($sel['prop'], $key), self::lw_call($sel['method'])], $ref);
        }
        $r = $this->page($probePath);
        if (!$isTarget($r['body']) && !(function () use ($r, $key, $name) { $s = lcup_find_branch_select($r['body'], [$key], [$name]); return $s && $key !== '' && ($s['current'] ?? null) === $key; })())
            throw new LcupError("Filial almashtirildi, lekin tasdiqlanmadi (LC-UP hozir: «" . (lcup_current_branch_name($r['body']) ?? 'aniqlanmadi') . "», kerak: «" . ($name ?: $key) . "»).", 'branch');
        return $r['body'];
    }
}
