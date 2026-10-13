<?php
// Shared authentication, authorization, output-escaping, and CSRF helpers.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && $token !== '' &&
        hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        $script = str_replace('\\\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $depth = substr_count(trim(dirname($script), '/.'), '/');
        $prefix = $depth > 0 ? str_repeat('../', $depth) : '';
        header('Location: ' . $prefix . 'login.php');
        exit;
    }
}

function require_role(string ...$roles): void
{
    require_login();
    $currentRole = strtolower((string)($_SESSION['role'] ?? ''));
    $allowed = array_map('strtolower', $roles);
    if (!in_array($currentRole, $allowed, true)) {
        http_response_code(403);
        exit('Access denied. You do not have permission to view this page.');
    }
}

function current_user_id(): int { return (int)($_SESSION['user_id'] ?? 0); }
function current_role(): string { return strtolower((string)($_SESSION['role'] ?? '')); }
function current_user(): array {
    return [
        'id' => current_user_id(),
        'fullname' => (string)($_SESSION['fullname'] ?? 'Officer'),
        'role' => current_role(),
        'username' => (string)($_SESSION['username'] ?? ''),
    ];
}
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
