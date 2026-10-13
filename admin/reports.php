<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle='Reports'; $role='Admin'; $activeItem='reports';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from=date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) $to=date('Y-m-d');
$start=$from.' 00:00:00'; $end=$to.' 23:59:59';
$stmt=$pdo->prepare("SELECT COUNT(*) FROM parking_logs WHERE entry_time BETWEEN ? AND ?"); $stmt->execute([$start,$end]); $totalEntries=(int)$stmt->fetchColumn();
$stmt=$pdo->prepare("SELECT COUNT(*) FROM parking_logs WHERE entry_time BETWEEN ? AND ? AND status='completed'"); $stmt->execute([$start,$end]); $completed=(int)$stmt->fetchColumn();
$stmt=$pdo->prepare("SELECT COUNT(*) FROM violations WHERE violation_date BETWEEN ? AND ?"); $stmt->execute([$start,$end]); $violations=(int)$stmt->fetchColumn();
$stmt=$pdo->prepare("SELECT COUNT(*) FROM parking_permits WHERE status='approved' AND reviewed_at BETWEEN ? AND ?"); $stmt->execute([$start,$end]); $permits=(int)$stmt->fetchColumn();
$areas=$pdo->query("SELECT pa.area_name, COUNT(ps.id) total_slots, SUM(ps.status='reserved') reserved_slots, SUM(ps.status='available') available_slots FROM parking_areas pa LEFT JOIN parking_slots ps ON ps.parking_area_id=pa.id WHERE pa.status='active' GROUP BY pa.id, pa.area_name ORDER BY pa.area_name")->fetchAll();
$dailyStmt=$pdo->prepare("SELECT DATE(entry_time) day, COUNT(*) entries FROM parking_logs WHERE entry_time BETWEEN ? AND ? GROUP BY DATE(entry_time) ORDER BY day DESC LIMIT 31"); $dailyStmt->execute([$start,$end]); $daily=$dailyStmt->fetchAll();
include __DIR__.'/../layouts/dashboard_header.php'; include __DIR__.'/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
  <div class="mb-8"><p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Administration</p><h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Reports</h1><p class="mt-1.5 text-xs sm:text-sm text-slate-500">Review parking activity and analytics for a selected date range.</p></div>
  <form class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm flex flex-col sm:flex-row sm:items-end gap-3 sm:gap-4">
    <div class="w-full sm:w-auto"><label class="mb-2 block text-xs font-bold uppercase text-slate-500">From</label><input type="date" name="from" value="<?= h($from) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
    <div class="w-full sm:w-auto"><label class="mb-2 block text-xs font-bold uppercase text-slate-500">To</label><input type="date" name="to" value="<?= h($to) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
    <button class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95 w-full sm:w-auto"><i class="fas fa-chart-line text-xs"></i> Generate Report</button>
  </form>
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><?php foreach([['Entries',$totalEntries,'fa-car'],['Completed sessions',$completed,'fa-circle-check'],['Violations',$violations,'fa-triangle-exclamation'],['Approved permits',$permits,'fa-id-card']] as $c): ?><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-start justify-between"><div><p class="text-xs font-bold uppercase tracking-wider text-slate-400"><?= h($c[0]) ?></p><p class="mt-2 text-3xl font-bold font-mono text-slate-900"><?= $c[1] ?></p></div><div class="w-9 h-9 rounded-xl bg-slate-100 flex items-center justify-center text-slate-700"><i class="fas <?= $c[2] ?> text-sm"></i></div></div></div><?php endforeach; ?></div>
  <div class="mt-8 grid gap-6 xl:grid-cols-2">
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden"><div class="p-5 sm:p-6 border-b border-slate-100"><h2 class="font-bold text-slate-900">Parking capacity</h2></div><div class="overflow-x-auto"><table class="w-full text-sm min-w-[480px]"><thead class="bg-slate-50/50 text-xs uppercase text-slate-400 tracking-wider"><tr><th class="px-6 py-4 text-left">Area</th><th class="px-6 py-4 text-right">Slots</th><th class="px-6 py-4 text-right">Reserved</th><th class="px-6 py-4 text-right">Available</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach($areas as $a): ?><tr class="hover:bg-slate-50/60 transition"><td class="px-6 py-4 font-semibold text-slate-800"><?= h($a['area_name']) ?></td><td class="px-6 py-4 text-right font-mono"><?= (int)$a['total_slots'] ?></td><td class="px-6 py-4 text-right font-mono"><?= (int)$a['reserved_slots'] ?></td><td class="px-6 py-4 text-right font-mono text-emerald-600 font-bold"><?= (int)$a['available_slots'] ?></td></tr><?php endforeach; ?><?php if(!$areas): ?><tr><td colspan="4" class="px-6 py-8 text-center text-slate-400">No active areas found.</td></tr><?php endif; ?></tbody></table></div></section>
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden"><div class="p-5 sm:p-6 border-b border-slate-100"><h2 class="font-bold text-slate-900">Daily entries</h2></div><div class="overflow-x-auto"><table class="w-full text-sm min-w-[320px]"><thead class="bg-slate-50/50 text-xs uppercase text-slate-400 tracking-wider"><tr><th class="px-6 py-4 text-left">Date</th><th class="px-6 py-4 text-right">Entries</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach($daily as $d): ?><tr class="hover:bg-slate-50/60 transition"><td class="px-6 py-4 font-mono text-xs text-slate-600"><?= h(date('M d, Y',strtotime($d['day']))) ?></td><td class="px-6 py-4 text-right font-bold font-mono text-slate-900"><?= (int)$d['entries'] ?></td></tr><?php endforeach; ?><?php if(!$daily): ?><tr><td colspan="2" class="px-6 py-8 text-center text-slate-400">No entries recorded in this range.</td></tr><?php endif; ?></tbody></table></div></section>
  </div>
</div>
<?php include __DIR__.'/../layouts/dashboard_footer.php'; ?>
