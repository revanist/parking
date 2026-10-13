<?php
require_once __DIR__ . '/dbconn.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';

/**
 * Ensures the password_resets table exists in the database.
 */
function ensure_password_reset_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS `password_resets` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `token_hash` CHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_pwd_reset_user` (`user_id`),
        KEY `idx_pwd_reset_token` (`token_hash`),
        KEY `idx_pwd_reset_expires` (`expires_at`),
        CONSTRAINT `fk_pwd_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
    $checked = true;
}

/**
 * Creates a password reset token for a user.
 * Returns the raw token (to be sent in the reset URL).
 */
function create_password_reset(PDO $pdo, int $userId): string
{
    ensure_password_reset_table($pdo);

    // Invalidate existing unused tokens for this user
    $cancelStmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    $cancelStmt->execute([$userId]);

    // Generate 32 bytes cryptographically secure random token (64 hex characters)
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    // Token expires in 1 hour
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

    $insertStmt = $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $insertStmt->execute([$userId, $tokenHash, $expiresAt]);

    return $rawToken;
}

/**
 * Verifies if a raw reset token is valid and unexpired.
 * Returns the reset record + user row, or null if invalid/expired.
 */
function verify_password_reset_token(PDO $pdo, string $rawToken): ?array
{
    ensure_password_reset_table($pdo);

    $rawToken = trim($rawToken);
    if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
        return null;
    }

    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare("
        SELECT pr.id AS reset_id, pr.user_id, pr.expires_at, pr.used_at,
               u.fullname, u.username, u.role, u.status
        FROM password_resets pr
        INNER JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ?
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $record = $stmt->fetch();

    if (!$record) {
        return null;
    }

    // Check if token already used
    if (!empty($record['used_at'])) {
        return null;
    }

    // Check expiration against current time
    if (strtotime($record['expires_at']) < time()) {
        return null;
    }

    // Check if user is active
    if (($record['status'] ?? 'active') !== 'active') {
        return null;
    }

    return $record;
}

/**
 * Resets the password for the token and invalidates the token.
 */
function complete_password_reset(PDO $pdo, string $rawToken, string $newPassword): bool
{
    $record = verify_password_reset_token($pdo, $rawToken);
    if (!$record) {
        return false;
    }

    $userId = (int)$record['user_id'];
    $resetId = (int)$record['reset_id'];
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    try {
        $pdo->beginTransaction();

        // 1. Update user password
        $updateUser = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $updateUser->execute([$hashedPassword, $userId]);

        // 2. Mark this token as used
        $updateToken = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
        $updateToken->execute([$resetId]);

        // 3. Invalidate any other tokens for this user
        $cancelOthers = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $cancelOthers->execute([$userId]);

        $pdo->commit();

        audit_log('password_reset_success', "Password successfully reset for account {$record['username']}");
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CampusPark] Password reset failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Attempts to deliver an email via PHP mail().
 * Fails gracefully in offline/local environments without raising fatal errors.
 */
function send_password_reset_email(string $toEmail, string $userName, string $resetUrl): bool
{
    $subject = "Reset Your CampusPark Password";
    $message = "Hello " . htmlspecialchars($userName) . ",\r\n\r\n" .
        "We received a request to reset your password for your Campus Parking account.\r\n\r\n" .
        "To reset your password, please click the link below (valid for 60 minutes):\r\n" .
        $resetUrl . "\r\n\r\n" .
        "If you did not request a password reset, you can safely ignore this email.\r\n\r\n" .
        "Regards,\r\nCampus Parking Administration";

    $headers = [
        'From: noreply@campusparking.local',
        'Reply-To: noreply@campusparking.local',
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8'
    ];

    try {
        return @mail($toEmail, $subject, $message, implode("\r\n", $headers));
    } catch (Throwable $e) {
        return false;
    }
}
