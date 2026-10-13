<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/dbconn.php';

$pageTitle = 'Admin Dashboard';
$role = 'Administrator';
$activeItem = 'dashboard';

// Key Metrics
$totalSlots = (int)$pdo->query("SELECT COUNT(*) FROM parking_slots ps JOIN parking_areas pa ON pa.id=ps.parking_area_id WHERE pa.status='active' AND ps.status<>'inactive'")->fetchColumn();
$availableSlots = (int)$pdo->query("SELECT COUNT(*) FROM parking_slots ps JOIN parking_areas pa ON pa.id=ps.parking_area_id WHERE pa.status='active' AND ps.status='available' AND ps.id NOT IN (SELECT parking_slot_id FROM parking_logs WHERE status='inside' AND parking_slot_id IS NOT NULL)")->fetchColumn();
$occupiedSlots = (int)$pdo->query("SELECT COUNT(*) FROM parking_logs WHERE status='inside'")->fetchColumn();
$activePermits = (int)$pdo->query("SELECT COUNT(*) FROM parking_permits WHERE status='approved' AND (expires_at IS NULL OR expires_at>=CURDATE())")->fetchColumn();

// Pending / Attention Items
$pendingPermits = (int)$pdo->query("SELECT COUNT(*) FROM parking_permits WHERE status='pending'")->fetchColumn();
$unresolvedViolations = (int)$pdo->query("SELECT COUNT(*) FROM violations WHERE status='unresolved'")->fetchColumn();
$newVehiclesToday = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Recent Gate Activity
$recentActivity = $pdo->query("
    SELECT 
        pl.id,
        pl.entry_time,
        pl.exit_time,
        pl.status,
        COALESCE(v.plate_number, vis.vehicle_plate, 'GUEST') AS plate_number,
        COALESCE(u.fullname, vis.visitor_name, 'Visitor') AS driver_name,
        ps.slot_code,
        pa.area_name,
        CASE WHEN pl.status = 'inside' THEN 'ENTRY' ELSE 'EXIT' END AS activity_type,
        COALESCE(pl.exit_time, pl.entry_time) AS event_time
    FROM parking_logs pl
    LEFT JOIN vehicles v ON v.id = pl.vehicle_id
    LEFT JOIN users u ON u.id = v.user_id
    LEFT JOIN visitors vis ON vis.id = pl.visitor_id
    LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id
    LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
    ORDER BY COALESCE(pl.exit_time, pl.entry_time) DESC
    LIMIT 6
")->fetchAll();

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Administration</p>
            <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Dashboard Overview</h1>
            <p class="mt-1.5 text-xs sm:text-sm text-slate-500">Real-time overview of campus parking operations and active tasks.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
            <a href="../parking/occupancy.php" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-slate-800 transition">
                <i class="fas fa-gauge-high"></i> Live Occupancy
            </a>
            <span class="inline-flex items-center gap-2 rounded-full bg-white border border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-600 shadow-sm">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span> System Online
            </span>
        </div>
    </div>

    <!-- KPI Metric Cards -->
    <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-8">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Slots</p>
                    <p class="mt-2 text-3xl font-bold font-mono text-slate-900"><?= $totalSlots ?></p>
                    <p class="mt-1 text-xs text-slate-500">Campus-wide capacity</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center">
                    <i class="fas fa-square-parking text-base"></i>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Available Slots</p>
                    <p class="mt-2 text-3xl font-bold font-mono text-emerald-600"><?= $availableSlots ?></p>
                    <p class="mt-1 text-xs text-slate-500">Ready for parking</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <i class="fas fa-circle-check text-base"></i>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Currently Parked</p>
                    <p class="mt-2 text-3xl font-bold font-mono text-blue-600"><?= $occupiedSlots ?></p>
                    <p class="mt-1 text-xs text-slate-500">Active sessions inside</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                    <i class="fas fa-car text-base"></i>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Active Permits</p>
                    <p class="mt-2 text-3xl font-bold font-mono text-indigo-600"><?= $activePermits ?></p>
                    <p class="mt-1 text-xs text-slate-500">Approved credentials</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-id-card text-base"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Section: Recent Activity & Action Items -->
    <div class="grid gap-6 grid-cols-1 xl:grid-cols-3 mb-8">
        <!-- Recent Gate Activity -->
        <section class="xl:col-span-2 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden flex flex-col">
            <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Recent Gate Activity</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Latest vehicle entries and exits recorded across gates</p>
                </div>
                <a href="../parking/logs.php" class="text-xs font-semibold text-slate-900 hover:text-blue-600 transition flex items-center gap-1">
                    All logs <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>

            <div class="overflow-x-auto flex-1">
                <table class="w-full text-left text-sm min-w-[500px]">
                    <thead class="bg-slate-50/50 text-xs uppercase tracking-wider text-slate-400 border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3.5">Vehicle</th>
                            <th class="px-5 py-3.5">Driver / Visitor</th>
                            <th class="px-5 py-3.5">Slot / Area</th>
                            <th class="px-5 py-3.5">Time</th>
                            <th class="px-5 py-3.5 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($recentActivity)): ?>
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-slate-400">
                                    <i class="fas fa-car-side text-2xl mb-2 text-slate-300 block"></i>
                                    No recent activity recorded.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentActivity as $log): ?>
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="px-5 py-3.5">
                                        <span class="font-mono font-bold text-slate-900"><?= h($log['plate_number']) ?></span>
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-700 text-xs">
                                        <?= h($log['driver_name']) ?>
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-600 text-xs">
                                        <?= h($log['slot_code'] ?: '—') ?>
                                        <?php if (!empty($log['area_name'])): ?>
                                            <span class="text-slate-400">(<?= h($log['area_name']) ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-500 text-xs font-mono">
                                        <?= date('h:i A', strtotime($log['event_time'])) ?>
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <?php if ($log['status'] === 'inside'): ?>
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Inside
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                                                Exited
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Action Items / Attention -->
        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 sm:p-6 flex flex-col justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Needs Attention</p>
                <h2 class="mt-1 text-base font-bold text-slate-900">Pending Operations</h2>
                <p class="text-xs text-slate-500 mt-0.5">Items requiring administrative action or review</p>

                <div class="mt-5 space-y-3">
                    <a href="../permits/index.php" class="flex items-center justify-between rounded-xl border border-slate-200 p-3.5 hover:bg-slate-50 hover:border-slate-300 transition group">
                        <div class="flex items-center gap-3">
                            <span class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                                <i class="fas fa-id-card text-sm"></i>
                            </span>
                            <div>
                                <p class="text-xs font-bold text-slate-900 group-hover:text-blue-600 transition">Pending Permits</p>
                                <p class="text-[11px] text-slate-400">Awaiting approval</p>
                            </div>
                        </div>
                        <span class="font-mono font-bold text-sm px-2.5 py-1 rounded-lg <?= $pendingPermits > 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600' ?>">
                            <?= $pendingPermits ?>
                        </span>
                    </a>

                    <a href="../violations/index.php" class="flex items-center justify-between rounded-xl border border-slate-200 p-3.5 hover:bg-slate-50 hover:border-slate-300 transition group">
                        <div class="flex items-center gap-3">
                            <span class="w-9 h-9 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center">
                                <i class="fas fa-triangle-exclamation text-sm"></i>
                            </span>
                            <div>
                                <p class="text-xs font-bold text-slate-900 group-hover:text-blue-600 transition">Unresolved Violations</p>
                                <p class="text-[11px] text-slate-400">Requires settlement</p>
                            </div>
                        </div>
                        <span class="font-mono font-bold text-sm px-2.5 py-1 rounded-lg <?= $unresolvedViolations > 0 ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-600' ?>">
                            <?= $unresolvedViolations ?>
                        </span>
                    </a>

                    <a href="../vehicles/index.php" class="flex items-center justify-between rounded-xl border border-slate-200 p-3.5 hover:bg-slate-50 hover:border-slate-300 transition group">
                        <div class="flex items-center gap-3">
                            <span class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
                                <i class="fas fa-car text-sm"></i>
                            </span>
                            <div>
                                <p class="text-xs font-bold text-slate-900 group-hover:text-blue-600 transition">Vehicles Registered Today</p>
                                <p class="text-[11px] text-slate-400">New vehicle records</p>
                            </div>
                        </div>
                        <span class="font-mono font-bold text-sm px-2.5 py-1 rounded-lg bg-slate-100 text-slate-600">
                            <?= $newVehiclesToday ?>
                        </span>
                    </a>

                    <a href="../admin/users.php" class="flex items-center justify-between rounded-xl border border-slate-200 p-3.5 hover:bg-slate-50 hover:border-slate-300 transition group">
                        <div class="flex items-center gap-3">
                            <span class="w-9 h-9 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center">
                                <i class="fas fa-users text-sm"></i>
                            </span>
                            <div>
                                <p class="text-xs font-bold text-slate-900 group-hover:text-blue-600 transition">Total User Accounts</p>
                                <p class="text-[11px] text-slate-400">Staff, faculty & students</p>
                            </div>
                        </div>
                        <span class="font-mono font-bold text-sm px-2.5 py-1 rounded-lg bg-slate-100 text-slate-600">
                            <?= $totalUsers ?>
                        </span>
                    </a>
                </div>
            </div>

            <div class="mt-5 pt-4 border-t border-slate-100 text-center">
                <a href="../admin/settings.php" class="text-xs text-slate-500 hover:text-slate-900 transition flex items-center justify-center gap-1.5 font-medium">
                    <i class="fas fa-gear text-[11px]"></i> Configure System Settings
                </a>
            </div>
        </section>
    </div>

    <!-- Administrative Quick Access -->
    <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
        <a href="../admin/areas.php" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 hover:shadow-md transition group">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center">
                    <i class="fas fa-square-parking text-base"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900 group-hover:text-blue-600 transition">Parking Areas</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Manage slots & zones</p>
                </div>
            </div>
        </a>

        <a href="../admin/users.php" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 hover:shadow-md transition group">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center">
                    <i class="fas fa-users text-base"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900 group-hover:text-blue-600 transition">User Management</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Staff roles & accounts</p>
                </div>
            </div>
        </a>

        <a href="../admin/reports.php" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 hover:shadow-md transition group">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center">
                    <i class="fas fa-chart-column text-base"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900 group-hover:text-blue-600 transition">Reports & Analytics</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Entry analytics & data</p>
                </div>
            </div>
        </a>

        <a href="../admin/audit.php" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-slate-300 hover:shadow-md transition group">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center">
                    <i class="fas fa-clipboard-list text-base"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-900 group-hover:text-blue-600 transition">Audit Logs</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Security event trail</p>
                </div>
            </div>
        </a>
    </div>
</div>

<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
