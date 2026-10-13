<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('student', 'faculty');

$pageTitle = 'Parking History';
$role = 'Student / Faculty';
$activeItem = 'history';
$sidebarItems = [
    ['key'=>'dashboard','label'=>'Dashboard','icon'=>'layout-dashboard','href'=>'/parking/dashboards/users.php'],
    ['key'=>'occupancy','label'=>'Live Occupancy','icon'=>'gauge','href'=>'/parking/parking/occupancy.php'],
    ['key'=>'vehicle','label'=>'My Vehicles','icon'=>'car','href'=>'/parking/vehicles/index.php'],
    ['key'=>'permit','label'=>'Parking Permit','icon'=>'credit-card','href'=>'/parking/permits/index.php'],
    ['key'=>'history','label'=>'Parking History','icon'=>'history','href'=>'/parking/parking/history.php'],
    ['key'=>'violations','label'=>'Violations','icon'=>'alert-triangle','href'=>'/parking/violations/index.php']
];

$stmt = $pdo->prepare("SELECT pl.entry_time, pl.exit_time, pl.status, v.plate_number, v.make, v.model, ps.slot_code, pa.area_name
                       FROM parking_logs pl
                       JOIN vehicles v ON v.id = pl.vehicle_id
                       LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id
                       LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
                       WHERE v.user_id = ? ORDER BY pl.entry_time DESC LIMIT 100");
$stmt->execute([current_user_id()]);
$history = $stmt->fetchAll();

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="mb-8"><p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Campus Parking · My Account</p><h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Parking History</h1><p class="mt-1.5 text-xs sm:text-sm text-slate-500">Your previous campus parking sessions.</p></div>
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100"><p class="text-sm font-semibold text-slate-700"><?= count($history) ?> parking session(s)</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[560px]">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-4">Vehicle</th>
                        <th class="px-6 py-4">Slot</th>
                        <th class="px-6 py-4">Entry</th>
                        <th class="px-6 py-4">Exit</th>
                        <th class="px-6 py-4">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$history): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center text-slate-400">
                                <i class="fas fa-clock-rotate-left text-3xl mb-3 text-slate-300"></i>
                                <p class="text-sm font-medium text-slate-600">No parking history yet</p>
                                <p class="text-xs text-slate-400 mt-1">Sessions will be recorded once your vehicle enters the campus.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($history as $row): ?>
                    <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 font-semibold text-slate-800"><?= h($row['make'].' '.$row['model']) ?><div class="text-xs font-mono text-slate-400"><?= h($row['plate_number']) ?></div></td>
                        <td class="px-6 py-4 font-semibold text-slate-800"><?= h($row['slot_code'] ?? '—') ?><div class="text-xs font-normal text-slate-400"><?= h($row['area_name'] ?? '') ?></div></td>
                        <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?= h(date('M d, Y h:i A', strtotime($row['entry_time']))) ?></td>
                        <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?= $row['exit_time'] ? h(date('M d, Y h:i A', strtotime($row['exit_time']))) : '<span class="text-emerald-600 font-semibold">Still inside</span>' ?></td>
                        <td class="px-6 py-4"><span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $row['status'] === 'inside' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
