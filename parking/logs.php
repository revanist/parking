<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin', 'security', 'student', 'faculty');

$roleNow = current_role();
$pageTitle = 'Parking Logs';
$role = $roleNow === 'admin' ? 'Administrator' : ($roleNow === 'security' ? 'Security Staff' : 'Student / Faculty');
$activeItem = 'logs';
$sidebarItems = match ($roleNow) {
    'admin' => [],
    'security' => [
        ['key'=>'dashboard','label'=>'Dashboard','icon'=>'fa-chart-line','href'=>'../dashboards/security.php'],
        ['key'=>'entry','label'=>'Vehicle Entry','icon'=>'fa-right-to-bracket','href'=>'../security/entry.php'],
        ['key'=>'exit','label'=>'Vehicle Exit','icon'=>'fa-right-from-bracket','href'=>'../security/exit.php'],
        ['key'=>'current','label'=>'Current Vehicles','icon'=>'fa-car-side','href'=>'current.php'],
        ['key'=>'visitors','label'=>'Visitors','icon'=>'fa-user-group','href'=>'../security/visitors.php'],
        ['key'=>'logs','label'=>'Parking Logs','icon'=>'fa-clock-rotate-left','href'=>'logs.php'],
        ['key'=>'violations','label'=>'Violations','icon'=>'fa-triangle-exclamation','href'=>'../violations/index.php']
    ],
    default => [
        ['key'=>'dashboard','label'=>'Dashboard','icon'=>'layout-dashboard','href'=>'/parking/dashboards/users.php'],
        ['key'=>'occupancy','label'=>'Live Occupancy','icon'=>'gauge','href'=>'/parking/parking/occupancy.php'],
        ['key'=>'vehicle','label'=>'My Vehicles','icon'=>'car','href'=>'/parking/vehicles/index.php'],
        ['key'=>'permit','label'=>'Parking Permit','icon'=>'credit-card','href'=>'/parking/permits/index.php'],
        ['key'=>'history','label'=>'Parking History','icon'=>'history','href'=>'/parking/parking/history.php'],
        ['key'=>'violations','label'=>'Violations','icon'=>'alert-triangle','href'=>'/parking/violations/index.php']
    ]
};

if ($roleNow === 'admin') {
    $sidebarItems = [
    ['key'=>'dashboard','label'=>'Dashboard','icon'=>'fa-chart-line','href'=>'../dashboards/security.php'],
    ['key'=>'entry','label'=>'Vehicle Entry','icon'=>'fa-right-to-bracket','href'=>'../security/entry.php'],
    ['key'=>'exit','label'=>'Vehicle Exit','icon'=>'fa-right-from-bracket','href'=>'../security/exit.php'],
    ['key'=>'current','label'=>'Current Vehicles','icon'=>'fa-car-side','href'=>'../parking/current.php'],
    ['key'=>'visitors','label'=>'Visitors','icon'=>'fa-user-group','href'=>'../security/visitors.php'],
    ['key'=>'logs','label'=>'Parking Logs','icon'=>'fa-clock-rotate-left','href'=>'../parking/logs.php'],
    ['key'=>'violations','label'=>'Violations','icon'=>'fa-triangle-exclamation','href'=>'../violations/index.php'],
];
}

$search=trim($_GET['q']??''); $statusFilter=$_GET['status']??'';
if ($roleNow === 'security' || $roleNow === 'admin') {
    $where=[]; $params=[];
    if($search!==''){ $where[]='(v.plate_number LIKE ? OR vis.vehicle_plate LIKE ? OR u.fullname LIKE ? OR vis.visitor_name LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like,$like); }
    if(in_array($statusFilter,['inside','completed','cancelled'],true)){ $where[]='pl.status=?'; $params[]=$statusFilter; }
    $sql="SELECT pl.id, pl.entry_time, pl.exit_time, pl.status, COALESCE(v.plate_number, vis.vehicle_plate) AS plate_number, v.make, v.model, COALESCE(u.fullname, vis.visitor_name) AS fullname, ps.slot_code, pa.area_name FROM parking_logs pl LEFT JOIN vehicles v ON v.id=pl.vehicle_id LEFT JOIN users u ON u.id=v.user_id LEFT JOIN parking_slots ps ON ps.id=pl.parking_slot_id LEFT JOIN parking_areas pa ON pa.id=ps.parking_area_id LEFT JOIN visitors vis ON vis.id=pl.visitor_id".( $where ? ' WHERE '.implode(' AND ',$where):'')." ORDER BY pl.entry_time DESC LIMIT 200";
    $stmt=$pdo->prepare($sql); $stmt->execute($params); $logs=$stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT pl.id, pl.entry_time, pl.exit_time, pl.status, v.plate_number, v.make, v.model, ps.slot_code, pa.area_name
                          FROM parking_logs pl
                          JOIN vehicles v ON v.id = pl.vehicle_id
                          LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id
                          LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
                          WHERE v.user_id = ? ORDER BY pl.entry_time DESC LIMIT 100");
    $stmt->execute([current_user_id()]);
    $logs = $stmt->fetchAll();
}

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="mb-8"><p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Campus Parking</p><h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Parking Logs</h1><p class="mt-1.5 text-xs sm:text-sm text-slate-500"><?= $roleNow === 'student' || $roleNow === 'faculty' ? 'Your recorded parking sessions.' : 'Recorded vehicle parking activity.' ?></p></div>
    <form class="mb-6 flex flex-col sm:flex-row gap-3">
        <input name="q" value="<?= h($search) ?>" placeholder="Search plate or owner" class="w-full sm:flex-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
        <select name="status" class="w-full sm:w-auto rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
            <option value="">All statuses</option>
            <?php foreach(["inside","completed","cancelled"] as $st): ?>
            <option value="<?= $st ?>" <?= $statusFilter===$st?"selected":"" ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95 w-full sm:w-auto">
            <i class="fas fa-filter text-xs"></i> Filter
        </button>
    </form>
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between">
            <p class="text-sm font-semibold text-slate-700"><?= count($logs) ?> record(s)</p>
            <span class="text-xs text-slate-400">Sorted by newest</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[640px]">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-4">Plate</th>
                        <th class="px-6 py-4">Owner</th>
                        <th class="px-6 py-4">Slot</th>
                        <th class="px-6 py-4">Entry</th>
                        <th class="px-6 py-4">Exit</th>
                        <th class="px-6 py-4">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center text-slate-400">
                                <i class="fas fa-clock-rotate-left text-3xl mb-3 text-slate-300"></i>
                                <p class="text-sm font-medium text-slate-600">No parking records found</p>
                                <p class="text-xs text-slate-400 mt-1">Try adjusting your search criteria or check back later.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($logs as $row): ?>
                    <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 font-bold font-mono text-slate-900"><?= h($row['plate_number'] ?? 'Visitor') ?></td>
                        <td class="px-6 py-4 text-slate-700"><?= h($row['fullname'] ?? 'Visitor') ?></td>
                        <td class="px-6 py-4 font-semibold text-slate-800"><?= h($row['slot_code'] ?? '—') ?><div class="text-xs font-normal text-slate-400"><?= h($row['area_name'] ?? '') ?></div></td>
                        <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?= h(date('M d, Y h:i A', strtotime($row['entry_time']))) ?></td>
                        <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?= $row['exit_time'] ? h(date('M d, Y h:i A', strtotime($row['exit_time']))) : '—' ?></td>
                        <td class="px-6 py-4"><span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $row['status'] === 'inside' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
