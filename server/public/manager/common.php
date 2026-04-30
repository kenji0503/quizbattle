<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function manager_base_url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/manager/' . ltrim($path, '/');
}

function manager_env_value(array $keys, ?string $default = null): ?string
{
    if (function_exists('envValueAny')) {
        return envValueAny($keys, $default);
    }

    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return $default;
}

function manager_auth_user(): string
{
    return (string)manager_env_value(['QB_MANAGER_USER', 'MANAGER_USER'], '');
}

function manager_auth_pass(): string
{
    return (string)manager_env_value(['QB_MANAGER_PASS', 'MANAGER_PASS'], '');
}

function manager_auth_pass_hash(): string
{
    return (string)manager_env_value(['QB_MANAGER_PASS_HASH', 'MANAGER_PASS_HASH'], '');
}

function manager_login_configured(): bool
{
    return manager_auth_user() !== '' && (manager_auth_pass() !== '' || manager_auth_pass_hash() !== '');
}

function manager_verify_credentials(string $loginId, string $password): bool
{
    $expectedUser = manager_auth_user();
    if ($expectedUser === '' || !hash_equals($expectedUser, $loginId)) {
        return false;
    }

    $hash = manager_auth_pass_hash();
    if ($hash !== '') {
        return password_verify($password, $hash);
    }

    $plain = manager_auth_pass();
    return $plain !== '' && hash_equals($plain, $password);
}

function manager_is_logged_in(): bool
{
    return !empty($_SESSION['manager_logged_in']) && !empty($_SESSION['manager_login_id']);
}

function manager_login(string $loginId): void
{
    session_regenerate_id(true);
    $_SESSION['manager_logged_in'] = 1;
    $_SESSION['manager_login_id'] = $loginId;
}

function manager_logout(): void
{
    unset($_SESSION['manager_logged_in'], $_SESSION['manager_login_id'], $_SESSION['manager_csrf']);
}

function manager_require_login(): void
{
    if (!manager_is_logged_in()) {
        header('Location: ' . manager_base_url('login.php'));
        exit;
    }
}

function manager_csrf_token(): string
{
    if (empty($_SESSION['manager_csrf'])) {
        $_SESSION['manager_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['manager_csrf'];
}

function manager_verify_csrf(?string $token): bool
{
    $sessionToken = (string)($_SESSION['manager_csrf'] ?? '');
    return $sessionToken !== '' && $token !== null && hash_equals($sessionToken, $token);
}
