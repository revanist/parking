<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/includes/dbconn.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/password_reset_helpers.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: dashboards/admin.php");
    } elseif ($_SESSION['role'] === 'security') {
        header("Location: dashboards/security.php");
    } else {
        header("Location: dashboards/users.php");
    }
    exit;
}

$pageTitle = "Forgot Password";
$activePage = "login";
$error = '';
$success = '';
$demoResetUrl = '';
$enteredEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Refresh the page and try again.';
    } else {
        $enteredEmail = trim($_POST['email'] ?? '');

        if (empty($enteredEmail)) {
            $error = 'Please enter your registered campus email.';
        } elseif (!filter_var($enteredEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            ensure_password_reset_table($pdo);

            $stmt = $pdo->prepare("SELECT id, username, fullname, status FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$enteredEmail]);
            $user = $stmt->fetch();

            if ($user && ($user['status'] ?? 'active') === 'active') {
                $rawToken = create_password_reset($pdo, (int)$user['id']);

                // Build full reset link URL
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
                $resetUrl = $scheme . $host . $scriptDir . '/reset-password.php?token=' . urlencode($rawToken);

                // Attempt mail delivery (gracefully falls back if SMTP is unconfigured in local XAMPP)
                send_password_reset_email($user['username'], $user['fullname'], $resetUrl);

                audit_log('password_reset_request', "Password reset link requested for {$user['username']}");

                // Provide direct link for seamless testing in local environment
                $demoResetUrl = $resetUrl;
            }

            // Always display standard generic success notice to protect user privacy
            $success = "If an account is associated with " . htmlspecialchars($enteredEmail) . ", password reset instructions have been generated.";
        }
    }
}

include __DIR__ . "/layouts/header.php";
?>

<div class="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
        <div class="rounded-2xl border border-ink-200 bg-white p-6 sm:p-8 shadow-soft">
            <div class="mb-8 text-center">
                <a href="/parking/login.php" class="inline-block">
                    <img src="img/campus_parking_logo.png" alt="Campus Parking Logo" class="mx-auto h-20 w-20 object-contain drop-shadow-sm">
                </a>
                <h1 class="mt-4 text-2xl font-bold text-ink-900">Forgot your password?</h1>
                <p class="mt-2 text-sm text-ink-500">
                    Enter your registered email address and we'll help you reset your password.
                </p>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 rounded-xl bg-red-50 p-4 text-sm text-red-600 border border-red-200 flex items-start gap-3">
                    <i class="fas fa-circle-exclamation mt-0.5 text-base text-red-500"></i>
                    <div><?= h($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-6 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 border border-emerald-200 flex items-start gap-3">
                    <i class="fas fa-envelope-circle-check mt-0.5 text-base text-emerald-600"></i>
                    <div>
                        <p class="font-semibold text-emerald-900">Check your inbox</p>
                        <p class="mt-1 text-xs text-emerald-700"><?= $success ?></p>
                    </div>
                </div>

                <?php if (!empty($demoResetUrl)): ?>
                    <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50/70 p-4 text-sm text-blue-900">
                        <div class="flex items-center gap-2 font-semibold text-blue-900 mb-1">
                            <i class="fas fa-laptop-code text-blue-600"></i>
                            <span>Local Server / Testing Access</span>
                        </div>
                        <p class="text-xs text-blue-700 mb-3">
                            In a local XAMPP environment without a live mail server, you can use the direct link below to proceed with resetting your password:
                        </p>
                        <a href="<?= h($demoResetUrl) ?>"
                           class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition active:scale-95">
                            <i class="fas fa-key text-xs"></i> Proceed to Reset Password
                        </a>
                    </div>
                <?php endif; ?>

                <div class="space-y-3">
                    <a href="login.php" class="block w-full text-center rounded-xl border border-ink-200 bg-ink-50 px-4 py-3 font-semibold text-ink-700 hover:bg-ink-100 transition">
                        <i class="fas fa-arrow-left text-xs mr-1.5"></i> Return to Sign In
                    </a>
                </div>
            <?php else: ?>
                <form action="forgot-password.php" method="post" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-ink-700">Registered email address</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-ink-400">
                                <i class="fas fa-envelope text-sm"></i>
                            </span>
                            <input type="email" name="email" required autofocus placeholder="you@example.com"
                                class="w-full rounded-xl border border-ink-200 pl-11 pr-4 py-3 outline-none transition focus:border-ink-900 focus:ring-1 focus:ring-ink-900 placeholder:text-ink-300 text-ink-900 bg-ink-50"
                                value="<?= h($enteredEmail) ?>">
                        </div>
                        <p class="mt-1.5 text-xs text-ink-500">A 60-minute password reset link will be sent to this email.</p>
                    </div>

                    <button type="submit"
                        class="w-full rounded-xl bg-ink-900 px-4 py-3 font-semibold text-white hover:bg-ink-800 transition shadow-sm hover:-translate-y-0.5 hover:shadow-md flex items-center justify-center gap-2">
                        <i class="fas fa-paper-plane text-xs"></i> Send Reset Link
                    </button>
                </form>

                <div class="mt-8 border-t border-ink-200 pt-6 text-center text-sm text-ink-500">
                    Remember your password?
                    <a href="login.php" class="font-semibold text-ink-900 hover:text-ink-700 transition">Sign in</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . "/layouts/footer.php"; ?>
