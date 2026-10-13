<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_role('security');

$pageTitle = 'Vehicle Exit';
$role = 'Security Staff';
$activeItem = 'exit';
$sidebarItems = [
    ['key'=>'dashboard','label'=>'Dashboard','icon'=>'fa-chart-line','href'=>'../dashboards/security.php'],
 ['key'=>'scan','label'=>'Scan QR Permit','icon'=>'fa-qrcode','href'=>'../security/scan.php'],
    ['key'=>'entry','label'=>'Vehicle Entry','icon'=>'fa-right-to-bracket','href'=>'../security/entry.php'],
    ['key'=>'exit','label'=>'Vehicle Exit','icon'=>'fa-right-from-bracket','href'=>'../security/exit.php'],
    ['key'=>'current','label'=>'Current Vehicles','icon'=>'fa-car-side','href'=>'../parking/current.php'],
    ['key'=>'visitors','label'=>'Visitors','icon'=>'fa-user-group','href'=>'../security/visitors.php'],
    ['key'=>'logs','label'=>'Parking Logs','icon'=>'fa-clock-rotate-left','href'=>'../parking/logs.php'],
    ['key'=>'violations','label'=>'Violations','icon'=>'fa-triangle-exclamation','href'=>'../violations/index.php'],
];

$error = '';
$success = '';
$vehicle = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid or expired form token. Please go back, refresh the page, and try again.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plate = strtoupper(trim($_POST['plate_number'] ?? ''));

    if ($plate === '') {
        $error = 'Enter a vehicle plate number.';
    } else {
        try {
            $pdo->beginTransaction();

            $cleanPlate = str_replace([' ', '-'], '', $plate);
            $stmt = $pdo->prepare("SELECT pl.id, pl.entry_time, pl.parking_slot_id, pl.visitor_id,
                                          COALESCE(v.plate_number, vis.vehicle_plate) AS plate_number,
                                          COALESCE(v.make, 'Visitor') AS make,
                                          COALESCE(v.model, vis.visitor_name) AS model,
                                          COALESCE(u.fullname, vis.visitor_name) AS fullname,
                                          ps.slot_code, pa.area_name
                                   FROM parking_logs pl
                                   LEFT JOIN vehicles v ON v.id = pl.vehicle_id
                                   LEFT JOIN users u ON u.id = v.user_id
                                   LEFT JOIN visitors vis ON vis.id = pl.visitor_id
                                   LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id
                                   LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
                                   WHERE (UPPER(v.plate_number) = ? OR REPLACE(REPLACE(UPPER(v.plate_number), '-', ''), ' ', '') = ?
                                      OR UPPER(vis.vehicle_plate) = ? OR REPLACE(REPLACE(UPPER(vis.vehicle_plate), '-', ''), ' ', '') = ?)
                                     AND pl.status = 'inside'
                                   ORDER BY pl.id DESC LIMIT 1 FOR UPDATE");
            $stmt->execute([$plate, $cleanPlate, $plate, $cleanPlate]);
            $vehicle = $stmt->fetch();

            if (!$vehicle) {
                throw new RuntimeException('No vehicle currently recorded as inside was found for plate "' . $plate . '".');
            }

            $update = $pdo->prepare("UPDATE parking_logs SET exit_time = NOW(), status = 'completed', recorded_by = ?, notes = ? WHERE id = ? AND status = 'inside'");
            $update->execute([current_user_id(), 'Security recorded vehicle exit.', $vehicle['id']]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The parking record could not be completed. Please try again.');
            }

            // If a visitor exited, also update the visitor record and free the assigned visitor slot
            if (!empty($vehicle['visitor_id'])) {
                $pdo->prepare("UPDATE visitors SET status = 'exited', exit_time = NOW() WHERE id = ?")->execute([$vehicle['visitor_id']]);
                if (!empty($vehicle['parking_slot_id'])) {
                    $pdo->prepare("UPDATE parking_slots SET status = 'available' WHERE id = ?")->execute([$vehicle['parking_slot_id']]);
                }
            }

            $pdo->commit();
            audit_log('vehicle_exit', 'Vehicle exit recorded for ' . $vehicle['plate_number'] . ' (' . $vehicle['fullname'] . ')');
            $success = 'Vehicle exit recorded successfully for ' . $vehicle['plate_number'] . '.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Vehicle exit could not be recorded.';
        }
    }
}

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="mb-8">
        <p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Campus Parking · Security</p>
        <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Vehicle Exit</h1>
        <p class="mt-1.5 text-xs sm:text-sm text-slate-500">Record the departure of a vehicle currently inside the campus.</p>
    </div>

    <?php if ($error): ?><div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 flex items-start gap-3"><i class="fas fa-circle-exclamation text-rose-500 mt-0.5"></i><span><?= h($error) ?></span></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 flex items-start gap-3"><i class="fas fa-circle-check text-emerald-500 mt-0.5"></i><span><?= h($success) ?></span></div><?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">Record exit</h2>
            <p class="mt-1 text-xs sm:text-sm text-slate-500">Enter the plate number of the vehicle leaving.</p>
            <form method="post" class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-end"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="flex-1 w-full">
                    <label for="plate_number" class="mb-2 block text-xs font-bold uppercase text-slate-500">Plate number</label>
                    <input id="plate_number" name="plate_number" required maxlength="20" autocomplete="off" autofocus value="<?= h($_POST['plate_number'] ?? $_GET['plate'] ?? '') ?>" placeholder="ABC-1234" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 uppercase text-sm font-mono font-semibold focus:outline-none focus:ring-2 focus:ring-slate-300">
                </div>
                <button class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-6 py-3 font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95 w-full sm:w-auto">
                    <i class="fas fa-right-from-bracket text-xs"></i> Record Exit
                </button>
            </form>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Exit result</p>
            <p class="mt-4 text-xs sm:text-sm text-slate-600 leading-relaxed">The active parking session is changed from <b>inside</b> to <b>completed</b> and an exit time is recorded in real time.</p>
        </section>
    </div>

    <?php if ($vehicle && $success): ?>
    <section class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 sm:p-6 shadow-sm">
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            <p class="text-xs font-bold uppercase tracking-wider text-emerald-800">Exit recorded successfully</p>
        </div>
        <div class="mt-4 grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
            <div class="bg-white/80 p-3.5 rounded-xl border border-emerald-200/50"><p class="text-[11px] font-semibold text-slate-400 uppercase">Plate</p><p class="mt-1 font-bold font-mono text-slate-900 text-lg"><?= h($vehicle['plate_number']) ?></p></div>
            <div class="bg-white/80 p-3.5 rounded-xl border border-emerald-200/50"><p class="text-[11px] font-semibold text-slate-400 uppercase">Owner</p><p class="mt-1 font-semibold text-slate-900"><?= h($vehicle['fullname']) ?></p></div>
            <div class="bg-white/80 p-3.5 rounded-xl border border-emerald-200/50"><p class="text-[11px] font-semibold text-slate-400 uppercase">Parking slot</p><p class="mt-1 font-bold text-slate-900"><?= h($vehicle['slot_code'] ?? '—') ?></p><p class="text-xs text-slate-500"><?= h($vehicle['area_name'] ?? '') ?></p></div>
            <div class="bg-white/80 p-3.5 rounded-xl border border-emerald-200/50"><p class="text-[11px] font-semibold text-slate-400 uppercase">Entered at</p><p class="mt-1 font-semibold font-mono text-xs text-slate-800"><?= h(date('M d, Y h:i A', strtotime($vehicle['entry_time']))) ?></p></div>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
