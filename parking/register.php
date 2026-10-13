<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/includes/dbconn.php';
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'dashboards/admin.php' : ($_SESSION['role'] === 'security' ? 'dashboards/security.php' : 'dashboards/users.php')));
    exit;
}

$pageTitle = "Create account";
$activePage = "register";
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf_token'] ?? null)) {
    $error = 'Your form session expired. Refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = strtolower(trim($_POST['role'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Public registration may only create normal campus-user accounts.
    $allowedPublicRoles = ['student', 'faculty'];

    if ($firstname === '' || $lastname === '' || $email === '' || $password === '') {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid campus email.";
    } elseif (!in_array($role, $allowedPublicRoles, true)) {
        $error = "Invalid account type.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $error = "An account with this email already exists.";
        } else {
            try {
                $fullname = $firstname . ' ' . $lastname;
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Do not trust a hidden/modified role value from the browser.
                $insert = $pdo->prepare("INSERT INTO users (fullname, username, password, role) VALUES (?, ?, ?, ?)");
                $insert->execute([$fullname, $email, $hashedPassword, $role]);
                $success = "Account created successfully. You can now sign in.";
                $_POST = [];
            } catch (PDOException $e) {
                $error = "Unable to create the account. Please try again.";
            }
        }
    }
}

include __DIR__ . "/layouts/header.php";
?>

<div class="min-h-[calc(100vh-4rem)] flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-lg">
        <div class="rounded-2xl border border-ink-200 bg-white p-6 sm:p-8 shadow-soft">
            <h1 class="text-2xl font-bold text-ink-900">Create an account</h1>
            <p class="mt-2 text-sm text-ink-500">Register as a student or faculty member. Staff accounts are created by an administrator.</p>

            <?php if ($error): ?>
                <div class="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-600 border border-red-200"><?= h($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="mt-4 rounded-xl bg-green-50 p-4 text-sm text-green-600 border border-green-200"><?= h($success) ?></div>
            <?php endif; ?>

            <form action="register.php" method="post" class="mt-8 space-y-5"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-ink-700">First name</label>
                        <input type="text" name="firstname" required class="w-full rounded-xl border border-ink-200 px-4 py-3 outline-none focus:border-ink-900 focus:ring-1 focus:ring-ink-900 text-ink-900 bg-ink-50" value="<?= h($_POST['firstname'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-ink-700">Last name</label>
                        <input type="text" name="lastname" required class="w-full rounded-xl border border-ink-200 px-4 py-3 outline-none focus:border-ink-900 focus:ring-1 focus:ring-ink-900 text-ink-900 bg-ink-50" value="<?= h($_POST['lastname'] ?? '') ?>">
                    </div>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-semibold text-ink-700">Campus email</label>
                    <input type="email" name="email" required class="w-full rounded-xl border border-ink-200 px-4 py-3 outline-none focus:border-ink-900 focus:ring-1 focus:ring-ink-900 text-ink-900 bg-ink-50" value="<?= h($_POST['email'] ?? '') ?>">
                </div>

                <div>
                    <label class="mb-2 block text-sm font-semibold text-ink-700">Account type</label>
                    <select name="role" required class="w-full rounded-xl border border-ink-200 bg-ink-50 px-4 py-3 outline-none focus:border-ink-900 focus:ring-1 focus:ring-ink-900 text-ink-900">
                        <option value="student" <?= (($_POST['role'] ?? '') === 'student') ? 'selected' : '' ?>>Student</option>
                        <option value="faculty" <?= (($_POST['role'] ?? '') === 'faculty') ? 'selected' : '' ?>>Faculty</option>
                    </select>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-ink-700">Password</label>
                        <input type="password" name="password" minlength="8" required class="w-full rounded-xl border border-ink-200 px-4 py-3 outline-none focus:border-ink-900 focus:ring-1 focus:ring-ink-900 text-ink-900 bg-ink-50">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-ink-700">Confirm password</label>
                        <input type="password" name="confirm_password" minlength="8" required class="w-full rounded-xl border border-ink-200 px-4 py-3 outline-none focus:border-ink-900 focus:ring-1 focus:ring-ink-900 text-ink-900 bg-ink-50">
                    </div>
                </div>

                <button type="submit" class="w-full rounded-xl bg-ink-900 px-4 py-3 font-semibold text-white hover:bg-ink-800 transition shadow-sm">Create account</button>

                <div class="border-t border-ink-200 pt-6 text-center text-sm text-ink-500">
                    Already have an account?
                    <a href="login.php" class="font-semibold text-ink-900 hover:text-ink-700 transition">Sign in</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . "/layouts/footer.php"; ?>
