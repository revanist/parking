<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
} 
require_once __DIR__ . '/../includes/dbconn.php'; 
require_once __DIR__ . '/../includes/auth.php'; 
require_role('admin');

$pageTitle = 'Audit Log'; 
$role = 'Administrator'; 
$activeItem = 'audit'; 
$q = trim($_GET['q'] ?? '');

if ($q !== '') {
    $stmt = $pdo->prepare("SELECT a.*, u.fullname FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.action LIKE ? OR a.description LIKE ? OR u.fullname LIKE ? ORDER BY a.created_at DESC LIMIT 300");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
} else {
    $stmt = $pdo->query("SELECT a.*, u.fullname FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 300");
}
$rows = $stmt->fetchAll(); 
include __DIR__ . '/../layouts/dashboard_header.php'; 
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="mb-8">
        <p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Administration</p>
        <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Audit Log</h1>
        <p class="mt-1.5 text-xs sm:text-sm text-slate-500">Chronological activity record of important actions performed in the system.</p>
    </div>
    <form class="mb-6 flex flex-col sm:flex-row gap-3">
        <input name="q" value="<?= h($q) ?>" placeholder="Search action, description, or user..." class="w-full sm:flex-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
        <button class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95 w-full sm:w-auto">
            <i class="fas fa-search text-xs"></i> Search
        </button>
    </form>
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between">
            <p class="text-sm font-semibold text-slate-700"><?= count($rows) ?> activity record(s)</p>
            <span class="text-xs text-slate-400">Security telemetry</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[580px]">
                <thead class="bg-slate-50/50 text-xs uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4">User</th>
                        <th class="px-6 py-4">Action</th>
                        <th class="px-6 py-4">Description</th>
                        <th class="px-6 py-4 text-right">IP Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center text-slate-400">
                                <i class="fas fa-clipboard-list text-3xl mb-3 text-slate-300"></i>
                                <p class="text-sm font-medium text-slate-600">No audit records found</p>
                                <p class="text-xs text-slate-400 mt-1">Try refining your search terms.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr class="hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?= h(date('M d, Y h:i A', strtotime($r['created_at']))) ?></td>
                        <td class="px-6 py-4 font-semibold text-slate-800"><?= h($r['fullname'] ?? 'System') ?></td>
                        <td class="px-6 py-4"><span class="font-mono text-xs bg-slate-100 px-2 py-1 rounded text-slate-700 border border-slate-200"><?= h($r['action']) ?></span></td>
                        <td class="px-6 py-4 text-slate-600 text-xs"><?= h($r['description'] ?? '—') ?></td>
                        <td class="px-6 py-4 text-slate-400 text-xs font-mono text-right"><?= h($r['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
