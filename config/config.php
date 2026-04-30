<?php
function envValue(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    if (array_key_exists($key, $_ENV)) {
        return (string)$_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        return (string)$_SERVER[$key];
    }
    return $default;
}

function envValueAny(array $keys, ?string $default = null): ?string
{
    foreach ($keys as $key) {
        $value = envValue($key);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return $default;
}

function buildDbDsnFromParts(): ?string
{
    $host = envValueAny(['QB_DB_HOST', 'DB_HOST']);
    $name = envValueAny(['QB_DB_NAME', 'DB_NAME']);
    if ($host === null || $name === null) {
        return null;
    }

    $charset = envValueAny(['QB_DB_CHARSET', 'DB_CHARSET'], 'utf8mb4');
    $port = envValueAny(['QB_DB_PORT', 'DB_PORT']);
    $dsn = 'mysql:host=' . $host;
    if ($port !== null && $port !== '') {
        $dsn .= ';port=' . $port;
    }
    $dsn .= ';dbname=' . $name . ';charset=' . $charset;
    return $dsn;
}

function loadEnvFile(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }

    foreach ($lines as $line) {
        if (str_starts_with($line, "\xEF\xBB\xBF")) {
            $line = substr($line, 3);
        }
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (envValue($key) === null) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    return true;
}

$envCandidates = [
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
    dirname(__DIR__) . '/server/.env',
    dirname(__DIR__) . '/server/public/.env',
];
$loadedEnvPath = '';
foreach ($envCandidates as $envPath) {
    if (loadEnvFile($envPath)) {
        $loadedEnvPath = $envPath;
        break;
    }
}

if (!defined('ENV_FILE_LOADED')) {
    define('ENV_FILE_LOADED', $loadedEnvPath);
}
if (!defined('ENV_FILE_CANDIDATES')) {
    define('ENV_FILE_CANDIDATES', implode(', ', $envCandidates));
}

// 環境を判定（.env の APP_ENV またはホスト名で判断）
$env = envValue('APP_ENV') ?: (($_SERVER['HTTP_HOST'] ?? '') === 'qkdom.com' ? 'production' : 'test');

// 環境ごとの設定ファイルを読み込む
if ($env === 'production') {
    require_once __DIR__ . '/config.production.php';
} else {
    require_once __DIR__ . '/config.test.php';
}

// エラーレポート設定
if ($env === 'test') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
