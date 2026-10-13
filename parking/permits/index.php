<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('student', 'faculty', 'admin');

$pageTitle = 'Parking Permits';
$role = ucfirst(current_role());
$activeItem = 'permit';
$sidebarItems = current_role() === 'admin'
    ? [
    ['key'=>'dashboard','label'=>'Dashboard','icon'=>'layout-dashboard','href'=>'../dashboards/admin.php'],
    ['key'=>'areas','label'=>'Parking Areas','icon'=>'square-parking','href'=>'../admin/areas.php'],
    ['key'=>'vehicles','label'=>'Vehicles','icon'=>'car','href'=>'../vehicles/index.php'],
    ['key'=>'permits','label'=>'Permit Applications','icon'=>'id-card','href'=>'../permits/index.php'],
    ['key'=>'logs','label'=>'Parking Logs','icon'=>'clock-rotate-left','href'=>'../parking/logs.php'],
    ['key'=>'violations','label'=>'Violations','icon'=>'triangle-alert','href'=>'../violations/index.php'],
    ['key'=>'reports','label'=>'Reports','icon'=>'chart-column','href'=>'../admin/reports.php'],
    ['key'=>'users','label'=>'Users','icon'=>'users','href'=>'../admin/users.php'],
  ] : [
        ['key'=>'dashboard','label'=>'Dashboard','icon'=>'layout-dashboard','href'=>'/parking/dashboards/users.php'],
        ['key'=>'occupancy','label'=>'Live Occupancy','icon'=>'gauge','href'=>'/parking/parking/occupancy.php'],
        ['key'=>'vehicle','label'=>'My Vehicles','icon'=>'car','href'=>'/parking/vehicles/index.php'],
        ['key'=>'permit','label'=>'Parking Permit','icon'=>'credit-card','href'=>'/parking/permits/index.php'],
        ['key'=>'history','label'=>'Parking History','icon'=>'history','href'=>'/parking/parking/history.php'],
        ['key'=>'violations','label'=>'Violations','icon'=>'alert-triangle','href'=>'/parking/violations/index.php'],
    ];

$error = '';
$success = '';

function permit_number(int $permitId): string {
    // The primary key makes this deterministic and collision-safe across concurrent approvals.
    return sprintf('CP-%s-%06d', date('Y'), $permitId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session token expired or is invalid. Refresh the page and try again.';
    } else try {
        if ($action === 'apply' && current_role() !== 'admin') {
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            if ($vehicleId < 1) throw new RuntimeException('Select one of your active vehicles.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ? AND status = 'active' FOR UPDATE");
            $stmt->execute([$vehicleId, current_user_id()]);
            if (!$stmt->fetch()) {
                throw new RuntimeException('Select one of your active vehicles.');
            }

            $stmt = $pdo->prepare("SELECT id FROM parking_permits WHERE vehicle_id = ? AND status IN ('pending','approved') LIMIT 1 FOR UPDATE");
            $stmt->execute([$vehicleId]);
            if ($stmt->fetch()) {
                throw new RuntimeException('This vehicle already has a pending or approved permit.');
            }

            $stmt = $pdo->prepare("INSERT INTO parking_permits (user_id, vehicle_id, status) VALUES (?, ?, 'pending')");
            $stmt->execute([current_user_id(), $vehicleId]);
            $pdo->commit();
            $success = 'Permit application submitted. It is now waiting for administrator approval.';
        }

        if ($action === 'approve' && current_role() === 'admin') {
            $permitId = (int)($_POST['permit_id'] ?? 0);
            $slotId = (int)($_POST['parking_slot_id'] ?? 0);
            $expiresAt = trim($_POST['expires_at'] ?? '');
            if (!$permitId || !$slotId || $expiresAt === '') throw new RuntimeException('Permit, parking slot, and expiration date are required.');
            if ($expiresAt < date('Y-m-d')) throw new RuntimeException('Expiration date cannot be in the past.');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT pp.*, u.role AS owner_role, v.vehicle_type, v.status AS vehicle_status, v.user_id AS vehicle_owner_id FROM parking_permits pp JOIN users u ON u.id = pp.user_id JOIN vehicles v ON v.id = pp.vehicle_id WHERE pp.id = ? FOR UPDATE");
            $stmt->execute([$permitId]);
            $permit = $stmt->fetch();
            if (!$permit || $permit['status'] !== 'pending') throw new RuntimeException('That permit is no longer pending.');
            if ((int)$permit['user_id'] !== (int)$permit['vehicle_owner_id']) throw new RuntimeException('The permit owner does not match the registered vehicle owner.');
            if ($permit['vehicle_status'] !== 'active') throw new RuntimeException('The vehicle is inactive.');

            $slotStmt = $pdo->prepare("SELECT ps.*, pa.area_type, pa.status AS area_status FROM parking_slots ps JOIN parking_areas pa ON pa.id = ps.parking_area_id WHERE ps.id = ? FOR UPDATE");
            $slotStmt->execute([$slotId]);
            $slot = $slotStmt->fetch();
            if (!$slot || $slot['status'] !== 'available' || $slot['area_status'] !== 'active') throw new RuntimeException('That parking slot is not available.');

            // Defend against stale/inconsistent slot status and duplicate active permits.
            $assigned = $pdo->prepare("SELECT id FROM parking_permits WHERE parking_slot_id = ? AND status = 'approved' AND id <> ? LIMIT 1 FOR UPDATE");
            $assigned->execute([$slotId, $permitId]);
            if ($assigned->fetch()) throw new RuntimeException('That slot is already assigned to another approved permit.');
            $vehiclePermit = $pdo->prepare("SELECT id FROM parking_permits WHERE vehicle_id = ? AND status = 'approved' AND id <> ? LIMIT 1 FOR UPDATE");
            $vehiclePermit->execute([$permit['vehicle_id'], $permitId]);
            if ($vehiclePermit->fetch()) throw new RuntimeException('This vehicle already has another approved permit.');

            $vehicleType = strtolower((string)$permit['vehicle_type']);
            $requiredArea = $vehicleType === 'motorcycle' ? 'motorcycle' : ($permit['owner_role'] === 'faculty' ? 'faculty' : 'student');
            if ($slot['area_type'] !== $requiredArea && $slot['area_type'] !== 'general') {
                throw new RuntimeException('That slot is not intended for this applicant.');
            }

            $number = permit_number((int)$permit['id']);
            $qrToken = bin2hex(random_bytes(32));
            $update = $pdo->prepare("UPDATE parking_permits SET parking_slot_id = ?, permit_number = ?, qr_token = ?, status = 'approved', reviewed_at = NOW(), expires_at = ?, rejection_reason = NULL WHERE id = ?");
            $update->execute([$slotId, $number, $qrToken, $expiresAt, $permitId]);
            $pdo->prepare("UPDATE parking_slots SET status = 'reserved' WHERE id = ?")->execute([$slotId]);
            $pdo->commit();
            $success = "Permit approved: $number.";
        }

        if ($action === 'reject' && current_role() === 'admin') {
            $permitId = (int)($_POST['permit_id'] ?? 0);
            $reason = trim($_POST['rejection_reason'] ?? 'Application did not meet parking requirements.');
            if (!$permitId) throw new RuntimeException('Invalid permit.');
            $stmt = $pdo->prepare("UPDATE parking_permits SET status = 'rejected', reviewed_at = NOW(), rejection_reason = ? WHERE id = ? AND status = 'pending'");
            $stmt->execute([$reason, $permitId]);
            if ($stmt->rowCount() === 0) throw new RuntimeException('That permit is no longer pending.');
            $success = 'Permit application rejected.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'The permit action could not be completed.';
    }
}

if (current_role() === 'admin') {
    $permits = $pdo->query("SELECT pp.*, u.fullname, u.role AS owner_role, v.plate_number, v.vehicle_type, v.make, v.model, ps.slot_code, pa.area_name FROM parking_permits pp JOIN users u ON u.id = pp.user_id JOIN vehicles v ON v.id = pp.vehicle_id LEFT JOIN parking_slots ps ON ps.id = pp.parking_slot_id LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id ORDER BY FIELD(pp.status,'pending','approved','rejected','expired','cancelled'), pp.applied_at DESC")->fetchAll();
    $slots = $pdo->query("SELECT ps.id, ps.slot_code, pa.area_name, pa.area_type FROM parking_slots ps JOIN parking_areas pa ON pa.id = ps.parking_area_id WHERE ps.status = 'available' AND pa.status = 'active' ORDER BY pa.area_name, ps.slot_code")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT pp.*, v.plate_number, v.vehicle_type, v.make, v.model, ps.slot_code, pa.area_name FROM parking_permits pp JOIN vehicles v ON v.id = pp.vehicle_id LEFT JOIN parking_slots ps ON ps.id = pp.parking_slot_id LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id WHERE pp.user_id = ? ORDER BY pp.applied_at DESC");
    $stmt->execute([current_user_id()]);
    $permits = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT v.id, v.plate_number, v.vehicle_type, v.make, v.model FROM vehicles v WHERE v.user_id = ? AND v.status = 'active' AND NOT EXISTS (SELECT 1 FROM parking_permits pp WHERE pp.vehicle_id = v.id AND pp.status IN ('pending','approved')) ORDER BY v.created_at DESC");
    $stmt->execute([current_user_id()]);
    $eligibleVehicles = $stmt->fetchAll();
}

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-ink-500">Campus Parking</p>
            <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900"><?= current_role() === 'admin' ? 'Permit Applications' : 'Parking Permits' ?></h1>
            <p class="mt-2 text-sm text-slate-500"><?= current_role() === 'admin' ? 'Review applications and assign an available parking slot when approving.' : 'Apply for a permit or submit an official school parking sticker registration.' ?></p>
        </div>
        <?php if (current_role() !== 'admin'): ?>
        <div>
            <a href="/parking/permits/apply.php" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-xs sm:text-sm font-bold text-white hover:bg-blue-700 transition shadow-md active:scale-95">
                <i class="fas fa-file-signature text-sm"></i> Apply for Parking Sticker
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 shadow-sm">
            <i class="fas fa-circle-exclamation mt-0.5 text-rose-600 text-base shrink-0"></i>
            <div><p class="font-bold">Permit Notice</p><p class="mt-0.5"><?= h($error) ?></p></div>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 shadow-sm">
            <i class="fas fa-circle-check mt-0.5 text-emerald-600 text-base shrink-0"></i>
            <div><p class="font-bold">Success</p><p class="mt-0.5"><?= h($success) ?></p></div>
        </div>
    <?php endif; ?>

    <?php if (current_role() !== 'admin'): ?>
    <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 shadow-sm">
        <div class="mb-6 p-4 rounded-xl bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                    <i class="fas fa-id-card-clip text-base"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-blue-950">New Online Sticker Application Form</h3>
                    <p class="text-xs text-blue-700 mt-0.5">Complete your sticker registration with Smart Scan OCR, digital signature, and instant verification.</p>
                </div>
            </div>
            <a href="/parking/permits/apply.php" class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-blue-600 text-xs font-bold text-white hover:bg-blue-700 shadow-xs transition">
                <span>Open Application</span>
                <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>

        <h2 class="text-base sm:text-lg font-bold text-slate-900">Quick Apply with Registered Vehicle</h2>
        <p class="mt-1 text-xs sm:text-sm text-slate-500">Already registered a vehicle in your profile? You can quickly submit it below:</p>
        <?php if ($eligibleVehicles): ?>
            <form method="post" class="mt-4 flex flex-col sm:flex-row gap-3">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="apply">
                <select name="vehicle_id" required class="w-full sm:flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-slate-900 focus:bg-white focus:ring-1 focus:ring-slate-900 transition text-sm">
                    <option value="">Select vehicle for permit</option>
                    <?php foreach ($eligibleVehicles as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"><?= h($v['plate_number'].' — '.$v['make'].' '.$v['model'].' ('.ucfirst(strtolower($v['vehicle_type'])).')') ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-6 py-3 font-semibold text-white hover:bg-slate-800 transition shadow-sm active:scale-[0.99] text-sm">
                    <i class="fas fa-paper-plane text-xs"></i> Submit application
                </button>
            </form>
        <?php else: ?>
            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs sm:text-sm text-slate-600 flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-2">
                    <i class="fas fa-info-circle text-slate-400"></i>
                    <span>No pre-registered vehicles available. Use our official sticker form to register vehicle & permit together.</span>
                </div>
                <a href="/parking/permits/apply.php" class="font-bold text-blue-600 hover:text-blue-800 underline">Apply via Sticker Form &rarr;</a>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between">
            <h2 class="text-base sm:text-lg font-bold text-slate-900">Permit Applications & Passes</h2>
            <span class="text-xs text-slate-400 lg:hidden flex items-center gap-1"><i class="fas fa-arrows-left-right text-[10px]"></i> Scroll table</span>
        </div>
        <div class="overflow-x-auto w-full">
            <table class="w-full text-left text-sm min-w-[720px]">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-400">
                    <tr>
                        <?php if(current_role()==='admin'): ?><th class="px-6 py-4">Applicant</th><?php endif; ?>
                        <th class="px-6 py-4">Vehicle</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Digital Pass & QR</th>
                        <th class="px-6 py-4">Slot</th>
                        <th class="px-6 py-4">Applied</th>
                        <th class="px-6 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($permits as $p): ?>
                <tr class="hover:bg-slate-50/80 transition">
                    <td class="px-6 py-4 font-semibold text-slate-800">
                        <?= current_role()==='admin' ? h($p['fullname']) : h($p['plate_number']) ?>
                        <?php if(current_role()==='admin'): ?><div class="text-xs text-slate-400 font-normal"><?= h(ucfirst((string)$p['owner_role'])) ?></div><?php endif; ?>
                    </td>
                    <?php if(current_role()==='admin'): ?>
                    <td class="px-6 py-4 text-slate-600">
                        <span class="font-mono font-bold text-slate-900"><?= h($p['plate_number']) ?></span>
                        <div class="text-xs text-slate-400"><?= h($p['make'].' '.$p['model'].' ('.ucfirst((string)$p['vehicle_type']).')') ?></div>
                    </td>
                    <?php endif; ?>
                    <?php if(current_role()!=='admin'): ?>
                    <td class="px-6 py-4 text-slate-600">
                        <span class="font-bold text-slate-900"><?= h($p['make'].' '.$p['model']) ?></span>
                        <div class="text-xs text-slate-400 font-mono"><?= h($p['plate_number']) ?></div>
                    </td>
                    <?php endif; ?>
                    <td class="px-6 py-4">
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $p['status']==='approved'?'bg-emerald-50 text-emerald-700 border border-emerald-200':($p['status']==='pending'?'bg-amber-50 text-amber-700 border border-amber-200':'bg-slate-100 text-slate-600') ?>">
                            <?= h(ucfirst($p['status'])) ?>
                        </span>
                        <?php if($p['permit_number']): ?>
                        <div class="mt-1 font-mono text-xs font-semibold text-slate-700"><?= h($p['permit_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if($p['status']==='approved' && !empty($p['qr_token'])): 
                            $passUrl = '/parking/permits/pass.php?id=' . (int)$p['id'];
                            $verifyUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/parking/permits/verify.php?token=' . rawurlencode($p['qr_token']);
                        ?>
                        <div class="flex items-center gap-3">
                            <a href="<?= h($passUrl) ?>" class="group inline-flex items-center gap-2 p-1.5 rounded-xl border border-slate-200 bg-white hover:border-amber-400 hover:shadow-md transition">
                                <img loading="lazy" class="h-12 w-12 rounded-lg bg-slate-50 object-contain p-1" alt="QR" src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= rawurlencode($verifyUrl) ?>">
                                <div class="pr-2 text-left">
                                    <span class="block text-xs font-bold text-slate-900 group-hover:text-amber-600 flex items-center gap-1">
                                        <i class="fas fa-id-card text-[11px] text-amber-500"></i> Open Pass
                                    </span>
                                    <span class="block text-[10px] text-slate-400">Digital / Print</span>
                                </div>
                            </a>
                        </div>
                        <?php elseif($p['status']==='pending'): ?>
                        <span class="text-xs text-amber-600 font-medium flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Awaiting Admin
                        </span>
                        <?php else: ?>
                        <span class="text-xs text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-slate-600">
                        <span class="font-mono font-bold text-slate-900"><?= h($p['slot_code'] ?? '—') ?></span>
                        <?= $p['area_name'] ? '<div class="text-xs text-slate-400">'.h($p['area_name']).'</div>' : '' ?>
                    </td>
                    <td class="px-6 py-4 text-slate-500 text-xs font-mono">
                        <?= h(date('M j, Y', strtotime($p['applied_at']))) ?>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <?php if(current_role()==='admin'): ?>
                            <?php if($p['status']==='pending'): ?>
                            <details class="inline-block text-left relative">
                                <summary class="cursor-pointer rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition">Review</summary>
                                <div class="absolute z-30 mt-2 right-0 w-[calc(100vw-3rem)] max-w-xs rounded-xl border border-slate-200 bg-white p-4 shadow-xl">
                                    <form method="post" class="space-y-3">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="permit_id" value="<?= (int)$p['id'] ?>">
                                        <label class="block text-xs font-semibold text-slate-600">Available slot</label>
                                        <select name="parking_slot_id" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm bg-slate-50 focus:bg-white">
                                            <option value="">Select slot</option>
                                            <?php foreach($slots as $s): ?>
                                            <option value="<?= (int)$s['id'] ?>"><?= h($s['slot_code'].' — '.$s['area_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label class="block text-xs font-semibold text-slate-600">Expires</label>
                                        <input type="date" name="expires_at" value="<?= date('Y-m-d', strtotime('+6 months')) ?>" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm bg-slate-50 focus:bg-white">
                                        <button class="w-full rounded-lg bg-emerald-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 transition">Approve & assign</button>
                                    </form>
                                    <form method="post" class="mt-2">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="permit_id" value="<?= (int)$p['id'] ?>">
                                        <input name="rejection_reason" placeholder="Reason (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm bg-slate-50 focus:bg-white">
                                        <button class="mt-2 w-full rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 transition">Reject</button>
                                    </form>
                                </div>
                            </details>
                            <?php elseif($p['status']==='approved'): ?>
                            <a href="/parking/permits/pass.php?id=<?= (int)$p['id'] ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50 shadow-xs transition">
                                <i class="fas fa-id-card text-amber-500"></i> Pass
                            </a>
                            <?php else: ?>
                            <span class="text-xs text-slate-400">Reviewed</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if($p['status']==='approved'): ?>
                            <div class="flex items-center justify-end gap-2">
                                <a href="/parking/permits/pass.php?id=<?= (int)$p['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-slate-900 text-white text-xs font-bold hover:bg-slate-800 shadow-xs transition">
                                    <i class="fas fa-eye text-[11px]"></i> View Pass
                                </a>
                                <a href="/parking/permits/pass.php?id=<?= (int)$p['id'] ?>&print=1" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-700 text-xs font-bold hover:bg-slate-50 shadow-xs transition" title="Print Placard">
                                    <i class="fas fa-print text-[11px]"></i>
                                </a>
                            </div>
                            <?php else: ?>
                            <span class="text-xs text-slate-400">—</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$permits): ?>
                    <tr>
                        <td colspan="<?= current_role()==='admin' ? 7 : 6 ?>" class="px-6 py-12 text-center text-slate-500">
                            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 mb-3">
                                <i class="fas fa-id-card text-2xl"></i>
                            </div>
                            <p class="font-bold text-slate-800">No permit applications recorded yet</p>
                            <p class="mt-1 text-sm text-slate-500 max-w-sm mx-auto">Registered vehicle owners can submit a permit application for campus parking approval.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
