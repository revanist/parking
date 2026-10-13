<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/includes/dbconn.php';
require_once __DIR__ . '/includes/auth.php';

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

$pageTitle = "Sign in";
$activePage = "login";

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf_token'] ?? null)) {
    $error = 'Your form session expired. Refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if (isset($user['status']) && $user['status'] !== 'active') {
                $error = "Your account is inactive. Please contact support.";
            } else {
                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['role'] = $user['role'];

                if ($user['role'] === 'admin') {
                    header("Location: dashboards/admin.php");
                } elseif ($user['role'] === 'security') {
                    header("Location: dashboards/security.php");
                } else {
                    header("Location: dashboards/users.php");
                }
                exit;
            }
        } else {
            $error = "Invalid email or password.";
        }
    }
}

include __DIR__ . "/layouts/header.php";
?>

<div class="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
        <div class="rounded-2xl border border-ink-200 bg-white p-6 sm:p-8 shadow-soft">
            <div class="mb-8 text-center">
                <img src="img/campus_parking_logo.png" alt="Campus Parking Logo" class="mx-auto h-20 w-20 object-contain drop-shadow-sm">
                <h1 class="mt-4 text-2xl font-bold text-ink-900">Welcome to Campus Parking</h1>
                <p class="mt-2 text-sm text-ink-500">Sign in to access our campus parking services.</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 rounded-xl bg-red-50 p-4 text-sm text-red-600 border border-red-200">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="post" class="space-y-5"><input type="hidden" name="csrf_token"
                    value="<?= h(csrf_token()) ?>">
                <div>
                    <label class="mb-2 block text-sm font-semibold text-ink-700">Email or username</label>
                    <input type="text" name="username" required placeholder="you@example.com"
                        class="w-full rounded-xl border border-ink-200 px-4 py-3 outline-none transition focus:border-ink-900 focus:ring-1 focus:ring-ink-900 placeholder:text-ink-300 text-ink-900 bg-ink-50"
                        value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>

                <div>
                    <div class="mb-2 flex justify-between">
                        <label class="text-sm font-semibold text-ink-700">Password</label>
                        <a href="forgot-password.php"
                            class="text-sm font-medium text-ink-600 hover:text-ink-900 transition">Forgot password?</a>
                    </div>
                    <input type="password" name="password" required placeholder="••••••••"
                        class="w-full rounded-xl border border-ink-200 px-4 py-3 outline-none transition focus:border-ink-900 focus:ring-1 focus:ring-ink-900 placeholder:text-ink-300 text-ink-900 bg-ink-50">
                </div>

                <button type="submit"
                    class="w-full rounded-xl bg-ink-900 px-4 py-3 font-semibold text-white hover:bg-ink-800 transition shadow-sm hover:-translate-y-0.5 hover:shadow-md">
                    Sign in
                </button>
            </form>

            <div class="mt-8 border-t border-ink-200 pt-6 text-center text-sm text-ink-500">
                Don't have an account?
                <a href="register.php" class="font-semibold text-ink-900 hover:text-ink-700 transition">Create one</a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . "/layouts/footer.php"; ?>