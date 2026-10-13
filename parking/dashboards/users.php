<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_role('student', 'faculty');
require_once __DIR__ . '/../includes/dbconn.php';

$vehicleStmt = $pdo->prepare("SELECT * FROM vehicles WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
$vehicleStmt->execute([current_user_id()]);
$dashboardVehicle = $vehicleStmt->fetch();

// A student/faculty account does NOT receive a permit automatically.
// A permit only becomes approved after the user applies and an admin reviews it.
$permitStmt = $pdo->prepare("SELECT pp.status, pp.expires_at, pp.permit_number, ps.slot_code, pa.area_name
    FROM parking_permits pp
    LEFT JOIN parking_slots ps ON ps.id = pp.parking_slot_id
    LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
    WHERE pp.user_id = ?
    ORDER BY pp.applied_at DESC LIMIT 1");
$permitStmt->execute([current_user_id()]);
$dashboardPermit = $permitStmt->fetch() ?: null;

$permitStatus = $dashboardPermit['status'] ?? 'none';
$permitLabel = $permitStatus === 'approved' && (!empty($dashboardPermit['expires_at']) && $dashboardPermit['expires_at'] >= date('Y-m-d'))
    ? 'Active'
    : ($permitStatus === 'pending' ? 'Pending' : ($permitStatus === 'rejected' ? 'Rejected' : ($permitStatus === 'expired' ? 'Expired' : 'No permit')));
$permitBadgeClass = $permitLabel === 'Active' ? 'text-emerald-700 bg-emerald-50 border-emerald-200' : 'text-amber-700 bg-amber-50 border-amber-200';
$pageTitle='Student / Faculty Dashboard'; 
$role='Student / Faculty'; 
$activeItem='dashboard';

$sidebarItems=[
 ['key'=>'dashboard','label'=>'Dashboard','icon'=>'layout-dashboard','href'=>'/parking/dashboards/users.php'],
 ['key'=>'occupancy','label'=>'Live Occupancy','icon'=>'gauge','href'=>'/parking/parking/occupancy.php'],
 ['key'=>'vehicle','label'=>'My Vehicles','icon'=>'car','href'=>'/parking/vehicles/index.php'],
 ['key'=>'permit','label'=>'Parking Permit','icon'=>'credit-card','href'=>'/parking/permits/index.php'],
 ['key'=>'history','label'=>'Parking History','icon'=>'history','href'=>'/parking/parking/history.php'],
 ['key'=>'violations','label'=>'Violations','icon'=>'alert-triangle','href'=>'/parking/violations/index.php']
];

include __DIR__.'/../layouts/dashboard_header.php'; 
include __DIR__.'/../includes/sidebar.php';
?>

        <!-- Dashboard Content -->
        <div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
            
            <!-- Page Title Area -->
            <div class="flex flex-col sm:flex-row sm:items-end justify-between mb-8 gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight mb-1.5">Welcome back, <?= htmlspecialchars($_SESSION['fullname'] ?? 'User') ?>.</h1>
                    <p class="text-slate-500 text-xs sm:text-sm">Here is your current campus parking information.</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="flex items-center gap-2 text-xs font-medium <?= $permitBadgeClass ?> px-3 py-1.5 rounded-full border">
                        <span class="w-1.5 h-1.5 rounded-full <?= $permitLabel === 'Active' ? 'bg-emerald-500' : 'bg-amber-500' ?>"></span>
                        Permit <?= h(strtolower($permitLabel)) ?>
                    </span>
                </div>
            </div>

            <!-- Top Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5 mb-8">
                <!-- Status Card -->
                <div class="bg-white p-5 rounded-2xl border border-slate-100 card-shadow flex justify-between items-center transition-all">
                    <div>
                        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Permit Status</p>
                        <p class="text-2xl font-bold <?= $permitLabel === 'Active' ? 'text-emerald-500' : 'text-amber-500' ?>"><?= h($permitLabel) ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-full bg-slate-900 flex items-center justify-center text-white">
                        <i data-lucide="credit-card" class="w-5 h-5"></i>
                    </div>
                </div>

                <!-- Slot Card -->
                <div class="bg-white p-5 rounded-2xl border border-slate-100 card-shadow flex justify-between items-center transition-all">
                    <div>
                        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Assigned Slot</p>
                        <p class="text-2xl font-bold text-slate-800"><?= h(($permitLabel === 'Active' && !empty($dashboardPermit['slot_code'])) ? $dashboardPermit['slot_code'] : 'Not assigned') ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-full bg-slate-900 flex items-center justify-center text-white">
                        <i data-lucide="square-parking" class="w-5 h-5"></i>
                    </div>
                </div>

                <!-- Vehicle Card -->
                <div class="bg-white p-5 rounded-2xl border border-slate-100 card-shadow flex justify-between items-center transition-all">
                    <div>
                        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Vehicle</p>
                        <p class="text-2xl font-bold text-slate-800 tracking-tight"><?= htmlspecialchars($dashboardVehicle['plate_number'] ?? 'None') ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-full bg-slate-900 flex items-center justify-center text-white">
                        <i data-lucide="car" class="w-5 h-5"></i>
                    </div>
                </div>

                <!-- Violations Card -->
                <div class="bg-white p-5 rounded-2xl border border-slate-100 card-shadow flex justify-between items-center transition-all">
                    <div>
                        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Violations</p>
                        <p class="text-2xl font-bold text-emerald-500">0</p>
                    </div>
                    <div class="w-10 h-10 rounded-full bg-slate-900 flex items-center justify-center text-white">
                        <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    </div>
                </div>
            </div>

            <!-- Bottom Section Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Current Parking Details -->
                <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-100 card-shadow p-6">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Your Parking</p>
                            <h2 class="text-lg font-bold text-slate-800">Current parking details</h2>
                        </div>
                        <a href="../vehicles/index.php" class="text-sm font-medium text-slate-500 hover:text-slate-900 flex items-center gap-1 transition-colors">
                            Manage <i data-lucide="arrow-right" class="w-4 h-4"></i>
                        </a>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Vehicle Detail Card -->
                        <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                            <div class="w-8 h-8 rounded-md bg-slate-800 flex items-center justify-center text-white mb-3">
                                <i data-lucide="car" class="w-4 h-4"></i>
                            </div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Vehicle</p>
                            <p class="font-semibold text-slate-800 mb-0.5"><?= htmlspecialchars(($dashboardVehicle['make'] ?? 'No') . ' ' . ($dashboardVehicle['model'] ?? 'vehicle')) ?></p>
                            <p class="text-xs text-slate-500"><?= htmlspecialchars($dashboardVehicle['plate_number'] ?? 'Register a vehicle') ?></p>
                        </div>

                        <!-- Permit Detail Card -->
                        <div class="bg-emerald-50/50 rounded-xl p-4 border border-emerald-100 relative overflow-hidden">
                            <div class="absolute top-0 right-0 w-16 h-16 bg-emerald-100 rounded-bl-full opacity-50"></div>
                            <div class="w-8 h-8 rounded-md bg-emerald-500 flex items-center justify-center text-white mb-3 relative z-10">
                                <i data-lucide="credit-card" class="w-4 h-4"></i>
                            </div>
                            <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider mb-1 relative z-10">Permit</p>
                            <p class="font-semibold text-slate-800 mb-0.5 relative z-10"><?= h($permitLabel) ?></p>
                            <p class="text-xs text-slate-500 relative z-10"><?php if ($permitLabel === 'Active'): ?>Until <?= h(date('M j, Y', strtotime($dashboardPermit['expires_at']))) ?><?php elseif ($permitLabel === 'Pending'): ?>Waiting for administrator approval<?php else: ?>Apply for a parking permit<?php endif; ?></p>
                            <?php if ($permitLabel === 'Active'): ?>
                            <div class="mt-3 relative z-10">
                                <a href="../permits/pass.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition shadow-sm">
                                    <i class="fas fa-id-card text-[11px]"></i> View Digital Pass
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="mt-3 relative z-10">
                                <a href="../permits/apply.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold transition shadow-sm">
                                    <i class="fas fa-file-signature text-[11px]"></i> Apply for Sticker
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Slot Detail Card -->
                        <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                            <div class="w-8 h-8 rounded-md bg-slate-800 flex items-center justify-center text-white mb-3">
                                <i data-lucide="square-parking" class="w-4 h-4"></i>
                            </div>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Assigned Slot</p>
                            <p class="font-semibold text-slate-800 mb-0.5"><?= h($dashboardPermit['slot_code'] ?? 'Not assigned') ?></p>
                            <p class="text-xs text-slate-500"><?= h($dashboardPermit['area_name'] ?? 'No approved slot') ?></p>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-2xl border border-slate-100 card-shadow p-6">
                    <div class="mb-6">
                        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Activity</p>
                        <h2 class="text-lg font-bold text-slate-800">Recent activity</h2>
                    </div>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                            <span class="text-sm text-slate-600">Vehicle entered</span>
                            <span class="text-xs font-medium text-slate-400">7:42 AM</span>
                        </div>
                        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                            <span class="text-sm text-slate-600">Permit approved</span>
                            <span class="text-xs font-medium text-slate-400">Yesterday</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-600">Vehicle registered</span>
                            <span class="text-xs font-medium text-slate-400">Sep 24</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

<?php include __DIR__.'/../layouts/dashboard_footer.php'; ?>
