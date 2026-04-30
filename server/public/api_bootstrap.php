<?php
// api_bootstrap.php
declare(strict_types=1);

// どのAPIでも最初に読み込む
// 1) ぜんぶバッファに貯める（途中のecho/警告を握りつぶす）
// 2) 落ちたら必ずJSONで返す（display_errors=0でも安心）
// 3) ヘッダ/キャッシュを統一
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
ob_start(); // ← まずバッファ

// 統一ヘッダ（先出しOK。途中でtext/htmlになる事故を防ぐ）
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

function api_clear_all_buffers(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function api_json_send(array $payload, int $status = 200): never
{
    // 最後にバッファの中身は捨ててからJSONだけ出す
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = json_encode([
            'error' => true,
            'message' => 'json encode failed',
            'detail' => json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $status = 500;
    }
    http_response_code($status);
    api_clear_all_buffers();
    echo $json;
    exit;
}

function api_json_fail(string $message, int $status = 400, array $extra = []): never
{
    api_json_send(['error' => true, 'message' => $message] + $extra, $status);
}

// PHP致命的エラー時もJSONで返す
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // ここでバッファに溜まったHTMLをJSONに置き換える
        api_json_send([
            'error' => true,
            'message' => 'fatal error',
            'type' => $e['type'],
            'file' => $e['file'] ?? null,
            'line' => $e['line'] ?? null,
        ], 500);
    }
});
