<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_role('admin');

$pageTitle = 'Users';
$role = 'Admin';
$activeItem = 'users';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid or expired form token. Please go back, refresh the page, and try again.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $fullname = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $newRole = strtolower(trim($_POST['role'] ?? ''));
        $password = $_POST['password'] ?? '';
        $allowedRoles = ['student','faculty','security','admin'];

        if ($fullname === '' || $username === '' || $password === '') {
            $error = 'Please complete all required fields.';
        } elseif (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $error = 'Username must be a valid email address.';
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $error = 'Invalid role selected.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $check->execute([$username]);
            if ($check->fetch()) {
                $error = 'That username/email is already registered.';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO users (fullname, username, password, role) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$fullname, $username, password_hash($password, PASSWORD_DEFAULT), $newRole]);
                    audit_log('user_created', 'Created account '.$username.' with role '.$newRole.'.');
                    $success = 'Account created successfully.';
                } catch (PDOException $e) {
                    $error = 'The account could not be created. Check that your users table supports the selected role.';
                }
            }
        }
    }

    if ($action === 'change_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newRole = strtolower(trim($_POST['role'] ?? ''));
        $allowedRoles = ['student','faculty','security','admin'];
        if ($userId === current_user_id()) {
            $error = 'You cannot change your own role from this screen.';
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $error = 'Invalid role.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->execute([$newRole, $userId]);
            audit_log('user_role_changed', 'Changed user #'.$userId.' role to '.$newRole.'.');
            $success = 'User role updated.';
        }
    }

    if ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === current_user_id()) {
            $error = 'You cannot deactivate your own account.';
        } else {
            // This action requires the optional status column used by login.php.
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ?");
                $stmt->execute([$userId]);
                audit_log('user_status_changed', 'Changed account #'.$userId.' status.');
                $success = 'Account status updated.';
            } catch (PDOException $e) {
                $error = 'Your users table does not have a status column yet. Add status ENUM(\'active\',\'inactive\') before using this action.';
            }
        }
    }
}

$users = $pdo->query('SELECT id, fullname, username, role' . (function() use ($pdo) { try { $pdo->query('SELECT status FROM users LIMIT 1'); return ', status'; } catch (PDOException $e) { return ''; } })() . ' FROM users ORDER BY id DESC')->fetchAll();

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-8">
        <div><p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Administration</p><h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">User accounts</h1><p class="mt-1.5 text-xs sm:text-sm text-slate-500">Create staff accounts and manage user roles. Public registration remains limited to Student and Faculty.</p></div>
    </div>

    <?php if ($error): ?><div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 flex items-start gap-3"><i class="fas fa-circle-exclamation text-rose-500 mt-0.5"></i><span><?= h($error) ?></span></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 flex items-start gap-3"><i class="fas fa-circle-check text-emerald-500 mt-0.5"></i><span><?= h($success) ?></span></div><?php endif; ?>

    <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">Create account</h2>
        <p class="mt-1 text-xs sm:text-sm text-slate-500">Use this for Security and other accounts that should not be publicly registered.</p>
        <form method="post" class="mt-6 grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Full name</label><input name="fullname" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Email / username</label><input type="email" name="username" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Role</label><select name="role" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"><option value="security">Security</option><option value="faculty">Faculty</option><option value="student">Student</option><option value="admin">Admin</option></select></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Temporary password</label><input type="password" name="password" minlength="8" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div class="sm:col-span-2 lg:col-span-4 flex justify-end"><button class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95 w-full sm:w-auto">Create account</button></div>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100"><h2 class="text-lg font-bold text-slate-900">Accounts</h2><p class="mt-1 text-xs sm:text-sm text-slate-500"><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?></p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[580px]">
                <thead class="bg-slate-50/50 text-xs uppercase tracking-wider text-slate-400"><tr><th class="px-6 py-4">Name</th><th class="px-6 py-4">Username</th><th class="px-6 py-4">Role</th><th class="px-6 py-4">Status</th><th class="px-6 py-4 text-right">Actions</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($users as $user): ?>
                    <tr class="hover:bg-slate-50/60 transition"><td class="px-6 py-4 font-semibold text-slate-800"><?= h($user['fullname']) ?></td><td class="px-6 py-4 text-slate-500 text-xs font-mono"><?= h($user['username']) ?></td><td class="px-6 py-4"><form method="post" class="flex items-center gap-2"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="change_role"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><select name="role" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-slate-300" <?= (int)$user['id'] === current_user_id() ? 'disabled' : '' ?>><?php foreach (['student','faculty','security','admin'] as $r): ?><option value="<?= $r ?>" <?= $user['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></form></td><td class="px-6 py-4"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700"><?= h(ucfirst($user['status'] ?? 'active')) ?></span></td><td class="px-6 py-4 text-right"><?php if ((int)$user['id'] !== current_user_id() && array_key_exists('status', $user)): ?><form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition"><?= ($user['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate' ?></button></form><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
