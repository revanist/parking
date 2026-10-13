<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_role('admin', 'security', 'student', 'faculty');

$roleNow = current_role();
$pageTitle = 'Violations';
$role = $roleNow === 'admin' ? 'Administrator' : ($roleNow === 'security' ? 'Security Staff' : 'Student / Faculty');
$activeItem = 'violations';
$sidebarItems = match ($roleNow) {
    'security' => [
        ['key'=>'dashboard','label'=>'Dashboard','icon'=>'fa-chart-line','href'=>'../dashboards/admin.php'],
        ['key'=>'entry','label'=>'Vehicle Entry','icon'=>'fa-right-to-bracket','href'=>'../security/entry.php'],
        ['key'=>'exit','label'=>'Vehicle Exit','icon'=>'fa-right-from-bracket','href'=>'../security/exit.php'],
        ['key'=>'current','label'=>'Current Vehicles','icon'=>'fa-car-side','href'=>'../parking/current.php'],
        ['key'=>'visitors','label'=>'Visitors','icon'=>'fa-user-group','href'=>'../security/visitors.php'],
        ['key'=>'logs','label'=>'Parking Logs','icon'=>'fa-clock-rotate-left','href'=>'../parking/logs.php'],
        ['key'=>'violations','label'=>'Violations','icon'=>'fa-triangle-exclamation','href'=>'index.php']
    ],
    default => [
        ['key'=>'dashboard','label'=>'Dashboard','icon'=>'layout-dashboard','href'=>$roleNow === 'admin' ? '/parking/dashboards/admin.php' : '/parking/dashboards/users.php'],
        ['key'=>'occupancy','label'=>'Live Occupancy','icon'=>'gauge','href'=>'/parking/parking/occupancy.php'],
        ['key'=>'vehicle','label'=>'My Vehicles','icon'=>'car','href'=>'/parking/vehicles/index.php'],
        ['key'=>'permit','label'=>'Parking Permit','icon'=>'credit-card','href'=>'/parking/permits/index.php'],
        ['key'=>'history','label'=>'Parking History','icon'=>'history','href'=>'/parking/parking/history.php'],
        ['key'=>'violations','label'=>'Violations','icon'=>'alert-triangle','href'=>'/parking/violations/index.php']
    ]
};

if ($roleNow === 'admin') {
    $sidebarItems = [
    ['key'=>'dashboard','label'=>'Dashboard','icon'=>'fa-chart-line','href'=>'../dashboards/admin.php'],
    ['key'=>'entry','label'=>'Vehicle Entry','icon'=>'fa-right-to-bracket','href'=>'../security/entry.php'],
    ['key'=>'exit','label'=>'Vehicle Exit','icon'=>'fa-right-from-bracket','href'=>'../security/exit.php'],
    ['key'=>'current','label'=>'Current Vehicles','icon'=>'fa-car-side','href'=>'../parking/current.php'],
    ['key'=>'visitors','label'=>'Visitors','icon'=>'fa-user-group','href'=>'../security/visitors.php'],
    ['key'=>'logs','label'=>'Parking Logs','icon'=>'fa-clock-rotate-left','href'=>'../parking/logs.php'],
    ['key'=>'violations','label'=>'Violations','icon'=>'fa-triangle-exclamation','href'=>'../violations/index.php'],
];
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($roleNow, ['admin','security'], true)) {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session token expired or is invalid. Refresh the page and try again.';
    } else {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'record') {
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $visitorId = (int)($_POST['visitor_id'] ?? 0);
            $type = $_POST['violation_type'] ?? 'other';
            $description = trim($_POST['description'] ?? '');
            $allowed = ['unauthorized_parking','wrong_slot','expired_permit','restricted_area','blocking','other'];
            if (!in_array($type, $allowed, true)) throw new RuntimeException('Invalid violation type.');
            if (($vehicleId && $visitorId) || (!$vehicleId && !$visitorId)) throw new RuntimeException('Select either a vehicle or a visitor, not both.');
            $userId = null;
            if ($vehicleId) {
                $q = $pdo->prepare('SELECT user_id FROM vehicles WHERE id = ?'); $q->execute([$vehicleId]); $userId = $q->fetchColumn() ?: null;
            }
            $q = $pdo->prepare('INSERT INTO violations (user_id, vehicle_id, visitor_id, violation_type, description, recorded_by) VALUES (?, ?, ?, ?, ?, ?)');
            $q->execute([$userId, $vehicleId ?: null, $visitorId ?: null, $type, $description ?: null, current_user_id()]);
            audit_log('violation_recorded', 'Parking violation recorded.');
            header('Location: index.php?success=recorded'); exit;
        }
        if ($action === 'resolve' && $roleNow === 'admin') {
            $id = (int)($_POST['violation_id'] ?? 0);
            $q = $pdo->prepare("UPDATE violations SET status='resolved', resolved_at=NOW() WHERE id=? AND status='unresolved'");
            $q->execute([$id]);
            audit_log('violation_resolved', 'Parking violation resolved.');
            header('Location: index.php?success=resolved'); exit;
        }
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : 'The violation could not be saved.'; }
    }
}

if ($roleNow === 'student' || $roleNow === 'faculty') {
    $stmt = $pdo->prepare("SELECT vi.*, v.plate_number FROM violations vi LEFT JOIN vehicles v ON v.id = vi.vehicle_id WHERE vi.user_id = ? ORDER BY vi.violation_date DESC");
    $stmt->execute([current_user_id()]);
} else {
    $stmt = $pdo->query("SELECT vi.*, v.plate_number, u.fullname, vis.visitor_name FROM violations vi LEFT JOIN vehicles v ON v.id=vi.vehicle_id LEFT JOIN users u ON u.id=vi.user_id LEFT JOIN visitors vis ON vis.id=vi.visitor_id ORDER BY vi.violation_date DESC LIMIT 200");
}
$violations = $stmt->fetchAll();
$vehicles = [];
$visitors = [];
if (in_array($roleNow, ['admin','security'], true)) {
    $vehicles = $pdo->query("SELECT id, plate_number, make, model FROM vehicles WHERE status='active' ORDER BY plate_number")->fetchAll();
    $visitors = $pdo->query("SELECT id, visitor_name, vehicle_plate FROM visitors WHERE status='inside' ORDER BY visitor_name")->fetchAll();
}

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="mb-8"><p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Campus Parking</p><h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Violations</h1><p class="mt-1.5 text-xs sm:text-sm text-slate-500">Record, review, and resolve campus parking violations.</p></div>
    <?php if ($error): ?><div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 flex items-start gap-3"><i class="fas fa-circle-exclamation text-rose-500 mt-0.5"></i><span><?= h($error) ?></span></div><?php endif; ?>
    <?php if (isset($_GET['success'])): ?><div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 flex items-start gap-3"><i class="fas fa-circle-check text-emerald-500 mt-0.5"></i><span><?= $_GET['success']==='resolved' ? 'Violation marked as resolved.' : 'Violation recorded successfully.' ?></span></div><?php endif; ?>

    <?php if (in_array($roleNow, ['admin','security'], true)): ?>
    <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">Record violation</h2>
        <form method="post" class="mt-5 grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-5"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="record">
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Vehicle</label><select name="vehicle_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"><option value="">Select vehicle</option><?php foreach($vehicles as $v): ?><option value="<?= $v['id'] ?>"><?= h($v['plate_number'].' — '.$v['make'].' '.$v['model']) ?></option><?php endforeach; ?></select></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Visitor</label><select name="visitor_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"><option value="">Select visitor</option><?php foreach($visitors as $v): ?><option value="<?= $v['id'] ?>"><?= h($v['visitor_name'].' — '.$v['vehicle_plate']) ?></option><?php endforeach; ?></select></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Type</label><select name="violation_type" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"><?php foreach(['unauthorized_parking','wrong_slot','expired_permit','restricted_area','blocking','other'] as $t): ?><option value="<?= $t ?>"><?= h(ucwords(str_replace('_',' ',$t))) ?></option><?php endforeach; ?></select></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Description</label><input name="description" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300" placeholder="Optional details"></div>
            <div class="flex items-end"><button class="w-full rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95">Record</button></div>
        </form>
        <p class="mt-3 text-xs text-slate-400">Choose either a registered vehicle or a current visitor, not both.</p>
    </section>
    <?php endif; ?>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100"><p class="text-sm font-semibold text-slate-700"><?= count($violations) ?> violation(s) on record</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[640px]">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4">Subject</th>
                        <th class="px-6 py-4">Type</th>
                        <th class="px-6 py-4">Description</th>
                        <th class="px-6 py-4">Status</th>
                        <?php if($roleNow==='admin'): ?><th class="px-6 py-4 text-right">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$violations): ?>
                    <tr>
                        <td colspan="<?= $roleNow==='admin'?6:5 ?>" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center text-slate-400">
                                <i class="fas fa-triangle-exclamation text-3xl mb-3 text-slate-300"></i>
                                <p class="text-sm font-medium text-slate-600">No violations found</p>
                                <p class="text-xs text-slate-400 mt-1">There are currently no recorded violations.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach($violations as $v): ?>
                    <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?= h(date('M d, Y h:i A', strtotime($v['violation_date']))) ?></td>
                        <td class="px-6 py-4 font-semibold text-slate-800"><?= h($v['plate_number'] ?? $v['visitor_name'] ?? 'Unknown') ?><div class="text-xs font-normal text-slate-400"><?= h($v['fullname'] ?? '') ?></div></td>
                        <td class="px-6 py-4 text-slate-700"><?= h(ucwords(str_replace('_',' ',$v['violation_type']))) ?></td>
                        <td class="px-6 py-4 text-slate-500 text-xs"><?= h($v['description'] ?? '—') ?></td>
                        <td class="px-6 py-4"><span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $v['status']==='unresolved' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' ?>"><?= h(ucfirst($v['status'])) ?></span></td>
                        <?php if($roleNow==='admin'): ?>
                        <td class="px-6 py-4 text-right">
                            <?php if($v['status']==='unresolved'): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="resolve">
                                <input type="hidden" name="violation_id" value="<?= $v['id'] ?>">
                                <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50 transition">Resolve</button>
                            </form>
                            <?php else: ?>
                            <span class="text-xs text-slate-400">Resolved</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
