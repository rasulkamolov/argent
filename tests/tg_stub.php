<?php
// Telegram API stub for the tests: records every sendMessage in $TG_LOG (one JSON per line) and answers {"ok":true}.
$log = getenv('TG_LOG') ?: sys_get_temp_dir() . '/tg_stub.log';
if (preg_match('~/bot[^/]+/sendMessage~', $_SERVER['REQUEST_URI'] ?? '')) {
    file_put_contents($log, json_encode(['text' => $_POST['text'] ?? '', 'chat' => $_POST['chat_id'] ?? ''], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    header('Content-Type: application/json'); echo '{"ok":true,"result":{}}'; return;
}
http_response_code(404); echo '{"ok":false}';
