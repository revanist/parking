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

$pageTitle = 'Visitors';
$role = 'Security Staff';
$activeItem = 'visitors';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid or expired form token. Please go back, refresh the page, and try again.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $pdo->beginTransaction();

        if ($action === 'entry') {
            $name = trim($_POST['visitor_name'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $purpose = trim($_POST['purpose'] ?? '');
            $person = trim($_POST['person_to_visit'] ?? '');
            $plate = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
            $type = $_POST['vehicle_type'] ?? 'car';
            if ($name === '' || $plate === '') throw new RuntimeException('Visitor name and vehicle plate are required.');
            if (!in_array($type, ['car','motorcycle','van','other'], true)) throw new RuntimeException('Invalid vehicle type.');

            $check = $pdo->prepare("SELECT id FROM visitors WHERE UPPER(vehicle_plate)=? AND status='inside' LIMIT 1 FOR UPDATE");
            $check->execute([$plate]);
            if ($check->fetch()) throw new RuntimeException('A visitor vehicle with this plate is already inside.');

            $slot = $pdo->query("SELECT ps.id, ps.slot_code, pa.area_name FROM parking_slots ps JOIN parking_areas pa ON pa.id=ps.parking_area_id WHERE ps.status='available' AND pa.status='active' AND pa.area_type='visitor' ORDER BY ps.id LIMIT 1 FOR UPDATE")->fetch();
            if (!$slot) throw new RuntimeException('No visitor parking slot is currently available.');

            $insert = $pdo->prepare("INSERT INTO visitors (visitor_name, contact, purpose, person_to_visit, vehicle_plate, vehicle_type, parking_slot_id, status, entry_time) VALUES (?, ?, ?, ?, ?, ?, ?, 'inside', NOW())");
            $insert->execute([$name, $contact ?: null, $purpose ?: null, $person ?: null, $plate, $type, $slot['id']]);
            $visitorId = (int)$pdo->lastInsertId();

            $log = $pdo->prepare("INSERT INTO parking_logs (visitor_id, parking_slot_id, entry_time, recorded_by, status, notes) VALUES (?, ?, NOW(), ?, 'inside', ?)");
            $log->execute([$visitorId, $slot['id'], current_user_id(), 'Security recorded visitor entry.']);

            $reserve = $pdo->prepare("UPDATE parking_slots SET status='reserved' WHERE id=? AND status='available'");
            $reserve->execute([$slot['id']]);
            if ($reserve->rowCount() !== 1) throw new RuntimeException('The visitor slot could not be reserved.');

            $pdo->commit();
            audit_log('visitor_entry', 'Visitor '.$name.' entered and was assigned slot '.$slot['slot_code'].'.');
            $success = "Visitor entry recorded. Assigned slot {$slot['slot_code']}.";
        } elseif ($action === 'exit') {
            $visitorId = (int)($_POST['visitor_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT v.id, v.visitor_name, v.vehicle_plate, v.entry_time, v.parking_slot_id, ps.slot_code FROM visitors v LEFT JOIN parking_slots ps ON ps.id=v.parking_slot_id WHERE v.id=? AND v.status='inside' FOR UPDATE");
            $stmt->execute([$visitorId]);
            $visitor = $stmt->fetch();
            if (!$visitor) throw new RuntimeException('Current visitor record was not found.');

            $up = $pdo->prepare("UPDATE visitors SET status='exited', exit_time=NOW() WHERE id=? AND status='inside'");
            $up->execute([$visitorId]);
            $log = $pdo->prepare("UPDATE parking_logs SET exit_time=NOW(), status='completed', recorded_by=?, notes=? WHERE visitor_id=? AND status='inside'");
            $log->execute([current_user_id(), 'Security recorded visitor exit.', $visitorId]);
            if (!empty($visitor['parking_slot_id'])) {
                $slotStmt = $pdo->prepare("UPDATE parking_slots SET status='available' WHERE id=?");
                $slotStmt->execute([$visitor['parking_slot_id']]);
            }
            $pdo->commit();
            audit_log('visitor_exit', 'Visitor '.$visitor['visitor_name'].' exited.');
            $success = "Visitor {$visitor['visitor_name']} ({$visitor['vehicle_plate']}) has exited.";
        } else {
            throw new RuntimeException('Invalid visitor action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Visitor transaction could not be completed.';
    }
}

$current = $pdo->query("SELECT v.*, ps.slot_code, pa.area_name FROM visitors v LEFT JOIN parking_slots ps ON ps.id=v.parking_slot_id LEFT JOIN parking_areas pa ON pa.id=ps.parking_area_id WHERE v.status='inside' ORDER BY v.entry_time DESC")->fetchAll();
$recent = $pdo->query("SELECT v.*, ps.slot_code FROM visitors v LEFT JOIN parking_slots ps ON ps.id=v.parking_slot_id WHERE v.status='exited' ORDER BY v.exit_time DESC LIMIT 20")->fetchAll();

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="mb-8"><p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Campus Parking · Security</p><h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Visitors</h1><p class="mt-1.5 text-xs sm:text-sm text-slate-500">Register temporary visitor parking and record visitor exits.</p></div>
    <?php if ($error): ?><div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 flex items-start gap-3"><i class="fas fa-circle-exclamation text-rose-500 mt-0.5"></i><span><?= h($error) ?></span></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 flex items-start gap-3"><i class="fas fa-circle-check text-emerald-500 mt-0.5"></i><span><?= h($success) ?></span></div><?php endif; ?>

    <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900">Register visitor entry</h2>
        <p class="mt-1 text-xs sm:text-sm text-slate-500">A visitor slot is assigned automatically from the active Visitor Parking area.</p>
        <form method="post" class="mt-5 grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="entry">
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Visitor name</label><input name="visitor_name" required maxlength="120" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Vehicle plate</label><input name="vehicle_plate" required maxlength="20" value="<?= h($_POST['vehicle_plate'] ?? $_GET['plate'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Vehicle type</label><select name="vehicle_type" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"><?php foreach(['car','motorcycle','van','other'] as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Contact</label><input name="contact" maxlength="30" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Person to visit</label><input name="person_to_visit" maxlength="120" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div class="sm:col-span-2"><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Purpose</label><input name="purpose" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300" placeholder="Meeting, appointment, delivery, etc."></div>
            <div class="flex items-end"><button class="w-full rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95">Record Visitor</button></div>
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100"><p class="text-sm font-semibold text-slate-700"><?= count($current) ?> visitor(s) currently inside</p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[640px]">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-4">Visitor</th>
                        <th class="px-6 py-4">Plate</th>
                        <th class="px-6 py-4">Purpose</th>
                        <th class="px-6 py-4">Slot</th>
                        <th class="px-6 py-4">Entry</th>
                        <th class="px-6 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$current): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center text-slate-400">
                                <i class="fas fa-user-group text-3xl mb-3 text-slate-300"></i>
                                <p class="text-sm font-medium text-slate-600">No visitors are currently inside</p>
                                <p class="text-xs text-slate-400 mt-1">Register a temporary visitor entry above to assign a parking slot.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach($current as $v): ?>
                    <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 font-semibold text-slate-800"><?= h($v['visitor_name']) ?><div class="text-xs font-normal text-slate-400"><?= h($v['contact'] ?? '') ?></div></td>
                        <td class="px-6 py-4 font-mono font-bold text-slate-900"><?= h($v['vehicle_plate']) ?></td>
                        <td class="px-6 py-4 text-slate-600 text-xs"><?= h($v['purpose'] ?? '—') ?><div class="text-[11px] text-slate-400">Visiting: <?= h($v['person_to_visit'] ?? '—') ?></div></td>
                        <td class="px-6 py-4 font-semibold text-slate-800"><?= h($v['slot_code'] ?? '—') ?><div class="text-xs font-normal text-slate-400"><?= h($v['area_name'] ?? '') ?></div></td>
                        <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?= h(date('M d, Y h:i A', strtotime($v['entry_time']))) ?></td>
                        <td class="px-6 py-4 text-right">
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="exit">
                                <input type="hidden" name="visitor_id" value="<?= $v['id'] ?>">
                                <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50 transition">Record Exit</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100"><h2 class="font-bold text-slate-900">Recent visitor exits</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[500px]">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-4">Visitor</th>
                        <th class="px-6 py-4">Plate</th>
                        <th class="px-6 py-4">Slot</th>
                        <th class="px-6 py-4">Exit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($recent as $v): ?>
                    <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50/60 transition">
                        <td class="px-6 py-4 font-semibold text-slate-800"><?= h($v['visitor_name']) ?></td>
                        <td class="px-6 py-4 font-mono font-bold text-slate-900"><?= h($v['vehicle_plate']) ?></td>
                        <td class="px-6 py-4 text-slate-600"><?= h($v['slot_code'] ?? '—') ?></td>
                        <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?= h($v['exit_time'] ? date('M d, Y h:i A', strtotime($v['exit_time'])) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$recent): ?>
                    <tr><td colspan="4" class="px-6 py-8 text-center text-slate-400 text-sm">No visitor exits recorded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
