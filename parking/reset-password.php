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

$pageTitle = "Reset Password";
$activePage = "login";
$error = '';
$resetSuccess = false;
$invalidToken = false;
$tokenErrorMessage = '';

$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (empty($rawToken)) {
    $invalidToken = true;
    $tokenErrorMessage = 'No password reset token was supplied. Please request a new link.';
} else {
    $record = verify_password_reset_token($pdo, $rawToken);

    if (!$record) {
        $invalidToken = true;
        $tokenErrorMessage = 'This password reset link is invalid, expired, or has already been used. Please request a fresh reset link.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valid($_POST['csrf_token'] ?? null)) {
            $error = 'Your form session expired. Refresh the page and try again.';
        } else {
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($password)) {
                $error = 'Please enter a new password.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters long.';
            } elseif ($password !== $confirmPassword) {
                $error = 'The passwords do not match.';
            } else {
                if (complete_password_reset($pdo, $rawToken, $password)) {
                    $resetSuccess = true;
                } else {
                    $error = 'Failed to reset password. The token may have expired or already been used.';
                    $record = verify_password_reset_token($pdo, $rawToken);
                    if (!$record) {
                        $invalidToken = true;
                        $tokenErrorMessage = 'This reset link has expired or has already been used.';
                    }
                }
            }
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
                <h1 class="mt-4 text-2xl font-bold text-ink-900">
                    <?= $resetSuccess ? 'Password Updated!' : ($invalidToken ? 'Reset Link Expired' : 'Set New Password') ?>
                </h1>
                <p class="mt-2 text-sm text-ink-500">
                    <?php if ($resetSuccess): ?>
                        Your account password has been successfully updated.
                    <?php elseif ($invalidToken): ?>
                        The link you used cannot be verified.
                    <?php else: ?>
                        Enter a strong password for <span class="font-semibold text-ink-800"><?= h($record['username']) ?></span>.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($resetSuccess): ?>
                <div class="mb-6 rounded-xl bg-emerald-50 p-5 text-sm text-emerald-800 border border-emerald-200 text-center">
                    <div class="w-12 h-12 mx-auto rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xl mb-3">
                        <i class="fas fa-check"></i>
                    </div>
                    <p class="font-bold text-base text-emerald-900">All Set!</p>
                    <p class="mt-1 text-xs text-emerald-700">
                        You can now sign in with your new password on any device.
                    </p>
                </div>

                <a href="login.php"
                   class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-ink-900 px-4 py-3 font-semibold text-white hover:bg-ink-800 transition shadow-sm hover:-translate-y-0.5 hover:shadow-md">
                    <i class="fas fa-arrow-right-to-bracket text-xs"></i> Sign In to Your Account
                </a>

            <?php elseif ($invalidToken): ?>
                <div class="mb-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 border border-amber-200 flex items-start gap-3">
                    <i class="fas fa-triangle-exclamation mt-0.5 text-base text-amber-600"></i>
                    <div>
                        <p class="font-semibold text-amber-900">Unable to proceed</p>
                        <p class="mt-1 text-xs text-amber-700"><?= h($tokenErrorMessage) ?></p>
                    </div>
                </div>

                <div class="space-y-3">
                    <a href="forgot-password.php"
                       class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-ink-900 px-4 py-3 font-semibold text-white hover:bg-ink-800 transition shadow-sm">
                        <i class="fas fa-rotate-left text-xs"></i> Request New Reset Link
                    </a>
                    <a href="login.php"
                       class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-ink-200 bg-ink-50 px-4 py-3 font-semibold text-ink-700 hover:bg-ink-100 transition">
                        Return to Sign In
                    </a>
                </div>

            <?php else: ?>
                <?php if ($error): ?>
                    <div class="mb-6 rounded-xl bg-red-50 p-4 text-sm text-red-600 border border-red-200 flex items-start gap-3">
                        <i class="fas fa-circle-exclamation mt-0.5 text-base text-red-500"></i>
                        <div><?= h($error) ?></div>
                    </div>
                <?php endif; ?>

                <form action="reset-password.php" method="post" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="token" value="<?= h($rawToken) ?>">

                    <div>
                        <label class="mb-2 block text-sm font-semibold text-ink-700">New Password</label>
                        <div class="relative">
                            <input type="password" id="new_password" name="password" required minlength="8" placeholder="••••••••"
                                class="w-full rounded-xl border border-ink-200 px-4 py-3 pr-11 outline-none transition focus:border-ink-900 focus:ring-1 focus:ring-ink-900 placeholder:text-ink-300 text-ink-900 bg-ink-50">
                            <button type="button" onclick="togglePasswordVisibility('new_password', this)"
                                class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-ink-400 hover:text-ink-700 transition"
                                title="Show/hide password" aria-label="Show/hide password">
                                <i class="fas fa-eye text-sm"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-ink-500">Must be at least 8 characters long.</p>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-semibold text-ink-700">Confirm New Password</label>
                        <div class="relative">
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="••••••••"
                                class="w-full rounded-xl border border-ink-200 px-4 py-3 pr-11 outline-none transition focus:border-ink-900 focus:ring-1 focus:ring-ink-900 placeholder:text-ink-300 text-ink-900 bg-ink-50">
                            <button type="button" onclick="togglePasswordVisibility('confirm_password', this)"
                                class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-ink-400 hover:text-ink-700 transition"
                                title="Show/hide password" aria-label="Show/hide password">
                                <i class="fas fa-eye text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full rounded-xl bg-ink-900 px-4 py-3 font-semibold text-white hover:bg-ink-800 transition shadow-sm hover:-translate-y-0.5 hover:shadow-md flex items-center justify-center gap-2">
                        <i class="fas fa-shield-halved text-xs"></i> Update Password
                    </button>
                </form>

                <div class="mt-8 border-t border-ink-200 pt-6 text-center text-sm text-ink-500">
                    Changed your mind?
                    <a href="login.php" class="font-semibold text-ink-900 hover:text-ink-700 transition">Return to sign in</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(fieldId, btn) {
    var field = document.getElementById(fieldId);
    if (!field) return;
    var icon = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        if (icon) {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    } else {
        field.type = 'password';
        if (icon) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye-ready', 'fa-eye');
        }
    }
}
</script>

<?php include __DIR__ . "/layouts/footer.php"; ?>
