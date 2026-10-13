<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('security');

$pageTitle = 'Current Vehicles';
$role = 'Security Staff';
$activeItem = 'current';
$sidebarItems = [
    ['key'=>'dashboard','label'=>'Dashboard','icon'=>'fa-chart-line','href'=>'../dashboards/security.php'],
    ['key'=>'entry','label'=>'Vehicle Entry','icon'=>'fa-right-to-bracket','href'=>'../security/entry.php'],
    ['key'=>'exit','label'=>'Vehicle Exit','icon'=>'fa-right-from-bracket','href'=>'../security/exit.php'],
    ['key'=>'current','label'=>'Current Vehicles','icon'=>'fa-car-side','href'=>'../parking/current.php'],
    ['key'=>'visitors','label'=>'Visitors','icon'=>'fa-user-group','href'=>'../security/visitors.php'],
    ['key'=>'logs','label'=>'Parking Logs','icon'=>'fa-clock-rotate-left','href'=>'../parking/logs.php'],
    ['key'=>'violations','label'=>'Violations','icon'=>'fa-triangle-exclamation','href'=>'../violations/index.php'],
];

$stmt = $pdo->query("
    SELECT pl.id, pl.entry_time, v.plate_number, v.vehicle_type, v.make, v.model,
           u.fullname, ps.slot_code, pa.area_name
    FROM parking_logs pl
    JOIN vehicles v ON v.id = pl.vehicle_id
    JOIN users u ON u.id = v.user_id
    LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id
    LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
    WHERE pl.status = 'inside'
    ORDER BY pl.entry_time DESC
");
$currentVehicles = $stmt->fetchAll();

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="mb-8">
        <p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Campus Parking · Security</p>
        <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Current Vehicles</h1>
        <p class="mt-1.5 text-xs sm:text-sm text-slate-500">Vehicles currently recorded as inside the campus.</p>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between">
            <p class="text-sm font-semibold text-slate-700"><?= count($currentVehicles) ?> vehicle(s) currently inside</p>
            <a href="../security/scan.php" class="text-xs font-semibold text-slate-900 hover:text-blue-600 flex items-center gap-1 transition">
                Gate Checkpoint <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[580px]">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-4">Plate</th>
                        <th class="px-6 py-4">Owner</th>
                        <th class="px-6 py-4">Vehicle</th>
                        <th class="px-6 py-4">Slot</th>
                        <th class="px-6 py-4">Entry Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$currentVehicles): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center text-slate-400">
                                <i class="fas fa-car-side text-3xl mb-3 text-slate-300"></i>
                                <p class="text-sm font-medium text-slate-600">No vehicles are currently recorded inside</p>
                                <p class="text-xs text-slate-400 mt-1">Vehicles will show up here once entered through the gate checkpoint.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($currentVehicles as $row): ?>
                        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/60 transition">
                            <td class="px-6 py-4 font-bold font-mono text-slate-900"><?= h($row['plate_number']) ?></td>
                            <td class="px-6 py-4 text-slate-700"><?= h($row['fullname']) ?></td>
                            <td class="px-6 py-4 text-slate-700"><?= h($row['make'] . ' ' . $row['model']) ?><div class="text-xs text-slate-400"><?= h(ucfirst($row['vehicle_type'])) ?></div></td>
                            <td class="px-6 py-4 font-semibold text-slate-800"><?= h($row['slot_code'] ?? '—') ?><div class="text-xs font-normal text-slate-400"><?= h($row['area_name'] ?? '') ?></div></td>
                            <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?= h(date('M d, Y h:i A', strtotime($row['entry_time']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
