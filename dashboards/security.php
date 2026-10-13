<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_role('security');
$pageTitle='Security Dashboard'; $role='Security Staff'; $activeItem='dashboard';
$sidebarItems=[
 ['key'=>'dashboard','label'=>'Dashboard','icon'=>'fa-chart-line','href'=>'security.php'],
 ['key'=>'scan','label'=>'Scan QR Permit','icon'=>'fa-qrcode','href'=>'../security/scan.php'],
 ['key'=>'entry','label'=>'Vehicle Entry','icon'=>'fa-right-to-bracket','href'=>'../security/entry.php'],
 ['key'=>'exit','label'=>'Vehicle Exit','icon'=>'fa-right-from-bracket','href'=>'../security/exit.php'],
 ['key'=>'current','label'=>'Current Vehicles','icon'=>'fa-car-side','href'=>'../parking/current.php'],
 ['key'=>'visitors','label'=>'Visitors','icon'=>'fa-user-group','href'=>'../security/visitors.php'],
 ['key'=>'logs','label'=>'Parking Logs','icon'=>'fa-clock-rotate-left','href'=>'../parking/logs.php'],
 ['key'=>'violations','label'=>'Violations','icon'=>'fa-triangle-exclamation','href'=>'../violations/index.php']
];
require_once __DIR__ . '/../includes/dbconn.php';

$currentCount = (int)$pdo->query("SELECT COUNT(*) FROM parking_logs WHERE status = 'inside'")->fetchColumn();
$todayEntries = (int)$pdo->query("SELECT COUNT(*) FROM parking_logs WHERE DATE(entry_time) = CURDATE()")->fetchColumn();
$currentVisitors = (int)$pdo->query("SELECT COUNT(*) FROM visitors WHERE status='inside'")->fetchColumn();
$recentStmt = $pdo->query("SELECT pl.entry_time, v.plate_number, ps.slot_code FROM parking_logs pl JOIN vehicles v ON v.id = pl.vehicle_id LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id WHERE pl.status = 'inside' ORDER BY pl.entry_time DESC LIMIT 5");
$recentVehicles = $recentStmt->fetchAll();

include __DIR__.'/../layouts/dashboard_header.php'; include __DIR__.'/../includes/sidebar.php';
?>

  <div class="px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
    <div class="flex flex-col xl:flex-row xl:items-end xl:justify-between gap-5 fade-up"><div><p class="text-xs font-bold uppercase tracking-[.18em] text-ink-500">Campus Parking · Security</p><h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Parking control center</h1><p class="mt-2 text-ink-500">Monitor vehicle entry, exit, and current campus occupancy.</p></div><div class="flex flex-wrap items-center gap-2 sm:gap-3"><a href="../parking/occupancy.php" class="inline-flex items-center gap-2 rounded-xl bg-ink-950 px-4 py-2.5 text-xs font-semibold text-white shadow-soft hover:bg-ink-800 transition"><i class="fas fa-gauge-high"></i> Live Occupancy</a><span class="inline-flex items-center gap-2 rounded-full bg-white border border-ink-200 px-3.5 py-2 text-xs font-semibold text-ink-600 shadow-soft"><span class="w-1.5 h-1.5 rounded-full bg-green-400"></span>System online</span></div></div>
    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><?php foreach([['Vehicles inside',(string)$currentCount,'fa-car'],['Available slots',(string)$pdo->query("SELECT COUNT(*) FROM parking_slots ps JOIN parking_areas pa ON pa.id = ps.parking_area_id WHERE ps.status = 'available' AND pa.status = 'active'")->fetchColumn(),'fa-square-parking'],["Today's entries",(string)$todayEntries,'fa-right-to-bracket'],['Alerts','0','fa-triangle-exclamation']] as $i=>$c): ?><div class="rounded-2xl border border-ink-200 bg-white p-5 shadow-soft hover:shadow-elevated transition"><div class="flex items-start justify-between"><div><p class="text-xs font-semibold uppercase tracking-wider text-ink-400"><?= $c[0] ?></p><p class="mt-3 text-3xl font-bold font-mono <?= $i===1?'text-green-600':($i===3?'text-red-600':'') ?>"><?= $c[1] ?></p></div><div class="w-10 h-10 rounded-xl bg-ink-950 flex items-center justify-center"><i class="fas <?= $c[2] ?> text-white text-sm"></i></div></div></div><?php endforeach; ?></div>

    <!-- GATE QR SCANNER BANNER -->
    <div class="mt-6 rounded-3xl bg-gradient-to-r from-blue-900 via-slate-900 to-indigo-950 p-6 sm:p-7 text-white shadow-xl relative overflow-hidden flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
      <div class="relative z-10 max-w-xl">
        <div class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-200 border border-white/20">
          <i class="fas fa-qrcode text-amber-400"></i> Gate Entry & Checkpoint Scanner
        </div>
        <h2 class="mt-2 text-xl sm:text-2xl font-black text-white">Live Permit QR Code Scanner</h2>
        <p class="mt-1 text-xs sm:text-sm text-slate-300">Scan digital permit passes using your camera or upload a QR image to verify vehicle access and slot assignment.</p>
      </div>
      <div class="relative z-10 flex flex-wrap items-center gap-3 w-full md:w-auto">
        <a href="../security/scan.php" class="flex-1 md:flex-initial inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3.5 text-xs sm:text-sm font-bold text-white hover:bg-blue-500 shadow-lg transition active:scale-95">
          <i class="fas fa-camera"></i> Launch Camera Scanner
        </a>
        <a href="../security/scan.php?token=a1b2c3d4e5f60718293a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e" class="flex-1 md:flex-initial inline-flex items-center justify-center gap-2 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-3.5 text-xs sm:text-sm font-semibold text-white transition">
          <i class="fas fa-bolt text-amber-400"></i> Test Active Pass (NBC-2026)
        </a>
      </div>
    </div>
    <div class="mt-6 grid gap-6 xl:grid-cols-3">
      <section class="xl:col-span-2 rounded-2xl border border-ink-200 bg-white p-6 shadow-soft"><div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wider text-ink-400">Live monitoring</p><h2 class="mt-1 text-lg font-bold">Vehicles currently inside</h2></div><a href="../security/scan.php" class="inline-flex items-center justify-center rounded-xl bg-ink-900 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white hover:bg-ink-800 transition"><i class="fas fa-qrcode mr-1.5"></i> Gate Checkpoint</a></div><div class="mt-6 overflow-x-auto"><table class="w-full text-left text-sm min-w-[480px]"><thead><tr class="border-b border-ink-200 text-xs uppercase tracking-wider text-ink-400"><th class="pb-3">Plate</th><th class="pb-3">Slot</th><th class="pb-3">Entry</th><th class="pb-3">Status</th></tr></thead><tbody><?php if (!$recentVehicles): ?><tr><td colspan="4" class="py-8 text-center text-ink-500">No vehicles are currently inside.</td></tr><?php else: ?><?php foreach($recentVehicles as $x): ?><tr class="border-b border-ink-100 last:border-0"><td class="py-4 font-bold font-mono"><?= h($x['plate_number']) ?></td><td><?= h($x['slot_code'] ?? '—') ?></td><td class="font-mono text-ink-500"><?= h(date('h:i A', strtotime($x['entry_time']))) ?></td><td><span class="inline-flex items-center gap-1.5 rounded-full bg-ink-950 text-white px-2.5 py-1 text-xs font-semibold"><span class="w-1.5 h-1.5 rounded-full bg-green-400"></span>Inside</span></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></section>
      <section class="rounded-2xl border border-ink-200 bg-white p-6 shadow-soft"><p class="text-xs font-bold uppercase tracking-wider text-ink-400">Shortcuts</p><h2 class="mt-1 text-lg font-bold">Quick actions</h2><div class="mt-5 grid gap-3"><?php foreach([['Live occupancy dashboard','fa-gauge-high','../parking/occupancy.php'],['Gate Checkpoint & Scanner','fa-qrcode','../security/scan.php'],['Manual plate lookup fallback','fa-keyboard','../security/entry.php'],['Record vehicle exit','fa-right-from-bracket','../security/exit.php'],['View current vehicles','fa-car-side','../parking/current.php'],['Manage visitors','fa-user-group','../security/visitors.php']] as $x): ?><a href="<?= $x[2] ?>" class="group flex items-center gap-3 rounded-xl border border-ink-200 p-4 hover:bg-ink-50 transition"><span class="w-9 h-9 rounded-lg bg-ink-950 flex items-center justify-center"><i class="fas <?= $x[1] ?> text-white text-xs"></i></span><span class="text-sm font-semibold flex-1"><?= $x[0] ?></span><i class="fas fa-arrow-right text-xs text-ink-300 group-hover:text-ink-700"></i></a><?php endforeach; ?></div></section>
    </div>
  </div>
<?php include __DIR__.'/../layouts/dashboard_footer.php'; ?>
