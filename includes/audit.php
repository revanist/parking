<?php
require_once __DIR__ . '/dbconn.php';
require_once __DIR__ . '/auth.php';

function audit_log(string $action, string $description = ''): void
{
    try {
        $stmt = $GLOBALS['pdo']->prepare('INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt->execute([current_user_id() ?: null, $action, $description ?: null, $ip]);
    } catch (Throwable $e) {
        // Audit logging must never break the main parking transaction.
    }
}
