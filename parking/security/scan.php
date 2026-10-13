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

$pageTitle = 'Gate Checkpoint & Scanner';
$role = 'Security Staff';
$activeItem = $activeItem ?? 'scan';
$sidebarItems = [
    ['key'=>'dashboard','label'=>'Dashboard','icon'=>'fa-chart-line','href'=>'../dashboards/security.php'],
    ['key'=>'occupancy','label'=>'Live Occupancy','icon'=>'fa-gauge-high','href'=>'../parking/occupancy.php'],
    ['key'=>'scan','label'=>'Gate Checkpoint & Scan','icon'=>'fa-qrcode','href'=>'../security/scan.php'],
    ['key'=>'entry','label'=>'Manual Plate Entry','icon'=>'fa-right-to-bracket','href'=>'../security/entry.php'],
    ['key'=>'exit','label'=>'Vehicle Exit','icon'=>'fa-right-from-bracket','href'=>'../security/exit.php'],
    ['key'=>'current','label'=>'Current Vehicles','icon'=>'fa-car-side','href'=>'../parking/current.php'],
    ['key'=>'visitors','label'=>'Visitors','icon'=>'fa-user-group','href'=>'../security/visitors.php'],
    ['key'=>'logs','label'=>'Parking Logs','icon'=>'fa-clock-rotate-left','href'=>'../parking/logs.php'],
    ['key'=>'violations','label'=>'Violations','icon'=>'fa-triangle-exclamation','href'=>'../violations/index.php'],
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Read flash confirmation feedback if redirected after recording entry
$flashConfirmation = $_SESSION['gate_entry_confirmed'] ?? null;
unset($_SESSION['gate_entry_confirmed']);

$error = '';
$success = '';
$token = strtolower(trim((string)($_POST['token'] ?? $_GET['token'] ?? '')));
$plate = strtoupper(trim((string)($_POST['plate_number'] ?? $_GET['plate'] ?? '')));
$defaultMode = $defaultMode ?? (isset($_GET['mode']) && $_GET['mode'] === 'manual' ? 'manual' : ($plate !== '' && $token === '' ? 'manual' : 'scan'));

/**
 * Evaluates permit or vehicle status for gate entry checkpoint.
 * Returns one of:
 * - 'valid': Approved permit, reserved slot, vehicle active, not inside
 * - 'expired': Permit exists but expires_at < CURDATE()
 * - 'already_inside': Vehicle currently recorded inside campus in parking_logs
 * - 'unauthorized': Unrecognized QR, unregistered plate, pending/rejected permit, inactive vehicle, unassigned slot
 */
function gate_evaluate_checkpoint(PDO $pdo, ?string $token, ?string $plate): array {
    $token = trim((string)$token);
    $plate = strtoupper(trim((string)$plate));

    // If a full URL was scanned or passed, extract the parameter
    if ($token !== '' && (str_contains($token, '://') || str_contains($token, '?') || str_contains($token, '&'))) {
        $parsedUrl = parse_url($token);
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            if (!empty($queryParams['token'])) {
                $token = trim($queryParams['token']);
            } elseif (!empty($queryParams['permit']) || !empty($queryParams['permit_number'])) {
                $token = trim($queryParams['permit'] ?? $queryParams['permit_number']);
            } elseif (!empty($queryParams['plate']) || !empty($queryParams['plate_number'])) {
                $plate = strtoupper(trim($queryParams['plate'] ?? $queryParams['plate_number']));
                $token = '';
            }
        }
    }

    // 1. EVALUATE BY QR TOKEN OR PERMIT NUMBER OR PLATE (PRIMARY SCANNER)
    if ($token !== '') {
        $stmt = $pdo->prepare("
            SELECT pp.id AS permit_id, pp.permit_number, pp.status AS permit_status, pp.expires_at, pp.qr_token,
                   v.id AS vehicle_id, v.plate_number, v.vehicle_type, v.make, v.model, v.color, v.status AS vehicle_status,
                   u.id AS user_id, u.fullname, u.role AS user_role, u.username,
                   ps.id AS parking_slot_id, ps.slot_code, ps.status AS slot_status,
                   pa.id AS area_id, pa.area_name, pa.status AS area_status, pa.area_type
            FROM parking_permits pp
            JOIN vehicles v ON v.id = pp.vehicle_id
            JOIN users u ON u.id = pp.user_id
            LEFT JOIN parking_slots ps ON ps.id = pp.parking_slot_id
            LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
            WHERE pp.qr_token = ? OR pp.permit_number = ? OR v.plate_number = ?
            LIMIT 1
        ");
        $stmt->execute([strtolower($token), strtoupper($token), strtoupper($token)]);
        $permit = $stmt->fetch();

        if (!$permit) {
            return [
                'status' => 'unauthorized',
                'badge' => 'UNRECOGNIZED PERMIT',
                'title' => 'Unauthorized Vehicle / Unrecognized QR Code',
                'reason' => 'No active parking permit matching the scanned code (' . htmlspecialchars($token) . ') was found in the database.',
                'advice' => 'Scan an official Campus Parking digital permit pass or verify the driver’s plate manually.',
                'permit' => null,
                'vehicle' => null,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        // Check if already inside
        $insideStmt = $pdo->prepare("
            SELECT pl.id, pl.entry_time, ps.slot_code, pa.area_name, sec.fullname AS officer_name
            FROM parking_logs pl
            LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id
            LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
            LEFT JOIN users sec ON sec.id = pl.recorded_by
            WHERE pl.vehicle_id = ? AND pl.status = 'inside'
            ORDER BY pl.id DESC LIMIT 1
        ");
        $insideStmt->execute([$permit['vehicle_id']]);
        $inside = $insideStmt->fetch();

        if ($inside) {
            return [
                'status' => 'already_inside',
                'badge' => 'ALREADY INSIDE',
                'title' => 'Vehicle Already Recorded Inside Campus',
                'reason' => 'Vehicle ' . $permit['plate_number'] . ' was logged inside campus today at ' . date('h:i A', strtotime($inside['entry_time'])) . '.',
                'advice' => 'Duplicate entry prevented. If the vehicle is departing campus, log its departure instead.',
                'permit' => $permit,
                'vehicle' => $permit,
                'inside_log' => $inside,
                'can_enter' => false,
            ];
        }

        // Check expiration
        if (!empty($permit['expires_at']) && $permit['expires_at'] < date('Y-m-d')) {
            $daysAgo = (int)floor((time() - strtotime($permit['expires_at'])) / 86400);
            return [
                'status' => 'expired',
                'badge' => 'EXPIRED PERMIT',
                'title' => 'Parking Permit Has Expired',
                'reason' => 'This permit expired on ' . date('M d, Y', strtotime($permit['expires_at'])) . ' (' . ($daysAgo > 0 ? $daysAgo . ' days ago' : 'today') . ').',
                'advice' => 'Campus entry is denied for expired permits. Advise driver to renew or register as a guest visitor.',
                'permit' => $permit,
                'vehicle' => $permit,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        // Check permit status
        if ($permit['permit_status'] !== 'approved') {
            return [
                'status' => 'unauthorized',
                'badge' => 'PERMIT NOT APPROVED',
                'title' => 'Unauthorized / Permit ' . ucfirst($permit['permit_status']),
                'reason' => 'Permit application status is currently "' . $permit['permit_status'] . '". Only approved permits may enter.',
                'advice' => 'Advise driver to check their permit status with the campus parking office.',
                'permit' => $permit,
                'vehicle' => $permit,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        // Check vehicle status
        if ($permit['vehicle_status'] !== 'active') {
            return [
                'status' => 'unauthorized',
                'badge' => 'INACTIVE VEHICLE',
                'title' => 'Unauthorized / Inactive Vehicle Record',
                'reason' => 'The registered vehicle record is marked inactive.',
                'advice' => 'Advise driver to update vehicle profile in their parking dashboard.',
                'permit' => $permit,
                'vehicle' => $permit,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        // Check slot assignment
        if (empty($permit['parking_slot_id']) || ($permit['slot_status'] ?? '') !== 'reserved') {
            return [
                'status' => 'unauthorized',
                'badge' => 'SLOT ISSUE',
                'title' => 'Unauthorized / Assigned Slot Inactive',
                'reason' => 'Assigned parking slot (' . ($permit['slot_code'] ?? 'None') . ') is currently unavailable or under maintenance.',
                'advice' => 'Contact parking administrator to reassign a parking bay.',
                'permit' => $permit,
                'vehicle' => $permit,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        return [
            'status' => 'valid',
            'badge' => 'VALID PERMIT',
            'title' => 'Valid Approved Parking Permit',
            'reason' => 'Permit is verified, active, and slot is reserved.',
            'advice' => 'Check physical license plate against vehicle before granting entry.',
            'permit' => $permit,
            'vehicle' => $permit,
            'inside_log' => null,
            'can_enter' => true,
        ];
    }

    // 2. EVALUATE BY MANUAL PLATE LOOKUP (FALLBACK)
    if ($plate !== '') {
        $cleanPlate = str_replace([' ', '-'], '', $plate);

        $vehStmt = $pdo->prepare("
            SELECT v.id AS vehicle_id, v.plate_number, v.vehicle_type, v.make, v.model, v.color, v.status AS vehicle_status,
                   u.id AS user_id, u.fullname, u.role AS user_role, u.username
            FROM vehicles v
            JOIN users u ON u.id = v.user_id
            WHERE UPPER(v.plate_number) = ?
               OR REPLACE(REPLACE(UPPER(v.plate_number), '-', ''), ' ', '') = ?
            LIMIT 1
        ");
        $vehStmt->execute([$plate, $cleanPlate]);
        $veh = $vehStmt->fetch();

        if (!$veh) {
            // Check if it is a visitor vehicle currently inside
            $visStmt = $pdo->prepare("
                SELECT vis.id, vis.vehicle_plate, vis.visitor_name, vis.entry_time, ps.slot_code, pa.area_name
                FROM visitors vis
                LEFT JOIN parking_slots ps ON ps.id = vis.parking_slot_id
                LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
                WHERE (UPPER(vis.vehicle_plate) = ? OR REPLACE(REPLACE(UPPER(vis.vehicle_plate), '-', ''), ' ', '') = ?)
                  AND vis.status = 'inside'
                ORDER BY vis.id DESC LIMIT 1
            ");
            $visStmt->execute([$plate, $cleanPlate]);
            $vis = $visStmt->fetch();

            if ($vis) {
                return [
                    'status' => 'already_inside',
                    'badge' => 'VISITOR INSIDE',
                    'title' => 'Visitor Vehicle Already Recorded Inside',
                    'reason' => 'Vehicle is currently registered as active visitor "' . $vis['visitor_name'] . '" inside campus since ' . date('h:i A', strtotime($vis['entry_time'])) . '.',
                    'advice' => 'Duplicate entry prevented. If the visitor is departing, record visitor exit.',
                    'permit' => null,
                    'vehicle' => ['plate_number' => $vis['vehicle_plate'], 'fullname' => $vis['visitor_name'], 'vehicle_type' => 'Visitor'],
                    'inside_log' => $vis,
                    'can_enter' => false,
                ];
            }

            return [
                'status' => 'unauthorized',
                'badge' => 'UNREGISTERED VEHICLE',
                'title' => 'Unauthorized Vehicle / No Permit on File',
                'reason' => 'Plate "' . $plate . '" is not registered in the CampusPark system.',
                'advice' => 'Vehicle cannot enter reserved bays. Register the driver as a guest visitor at the visitor desk.',
                'permit' => null,
                'vehicle' => ['plate_number' => $plate],
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        // Check if inside
        $insideStmt = $pdo->prepare("
            SELECT pl.id, pl.entry_time, ps.slot_code, pa.area_name, sec.fullname AS officer_name
            FROM parking_logs pl
            LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id
            LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
            LEFT JOIN users sec ON sec.id = pl.recorded_by
            WHERE pl.vehicle_id = ? AND pl.status = 'inside'
            ORDER BY pl.id DESC LIMIT 1
        ");
        $insideStmt->execute([$veh['vehicle_id']]);
        $inside = $insideStmt->fetch();

        if ($inside) {
            return [
                'status' => 'already_inside',
                'badge' => 'ALREADY INSIDE',
                'title' => 'Vehicle Already Recorded Inside Campus',
                'reason' => 'Vehicle ' . $veh['plate_number'] . ' was logged inside campus today at ' . date('h:i A', strtotime($inside['entry_time'])) . '.',
                'advice' => 'Duplicate entry prevented. If the vehicle is departing campus, log its departure instead.',
                'permit' => null,
                'vehicle' => $veh,
                'inside_log' => $inside,
                'can_enter' => false,
            ];
        }

        // Look up permit for vehicle
        $pStmt = $pdo->prepare("
            SELECT pp.id AS permit_id, pp.permit_number, pp.status AS permit_status, pp.expires_at, pp.qr_token,
                   ps.id AS parking_slot_id, ps.slot_code, ps.status AS slot_status,
                   pa.id AS area_id, pa.area_name, pa.status AS area_status, pa.area_type
            FROM parking_permits pp
            LEFT JOIN parking_slots ps ON ps.id = pp.parking_slot_id
            LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
            WHERE pp.vehicle_id = ?
            ORDER BY pp.id DESC LIMIT 1
        ");
        $pStmt->execute([$veh['vehicle_id']]);
        $permit = $pStmt->fetch();

        $combined = array_merge($veh, $permit ?: []);

        if (!$permit) {
            return [
                'status' => 'unauthorized',
                'badge' => 'NO PERMIT',
                'title' => 'Unauthorized / No Parking Permit on File',
                'reason' => 'Vehicle is registered to ' . $veh['fullname'] . ', but has no parking permit record.',
                'advice' => 'Advise driver to apply for a parking permit or register as a guest visitor.',
                'permit' => null,
                'vehicle' => $combined,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        // Check expiration
        if (!empty($permit['expires_at']) && $permit['expires_at'] < date('Y-m-d')) {
            $daysAgo = (int)floor((time() - strtotime($permit['expires_at'])) / 86400);
            return [
                'status' => 'expired',
                'badge' => 'EXPIRED PERMIT',
                'title' => 'Parking Permit Has Expired',
                'reason' => 'This permit expired on ' . date('M d, Y', strtotime($permit['expires_at'])) . ' (' . ($daysAgo > 0 ? $daysAgo . ' days ago' : 'today') . ').',
                'advice' => 'Campus entry is denied for expired permits. Advise driver to renew or register as a guest visitor.',
                'permit' => $combined,
                'vehicle' => $combined,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        // Check permit status
        if ($permit['permit_status'] !== 'approved') {
            return [
                'status' => 'unauthorized',
                'badge' => 'PERMIT NOT APPROVED',
                'title' => 'Unauthorized / Permit ' . ucfirst($permit['permit_status']),
                'reason' => 'Permit application status is currently "' . $permit['permit_status'] . '". Only approved permits may enter.',
                'advice' => 'Advise driver to check their permit application status.',
                'permit' => $combined,
                'vehicle' => $combined,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        // Check vehicle status
        if ($veh['vehicle_status'] !== 'active') {
            return [
                'status' => 'unauthorized',
                'badge' => 'INACTIVE VEHICLE',
                'title' => 'Unauthorized / Inactive Vehicle Record',
                'reason' => 'The registered vehicle record is marked inactive.',
                'advice' => 'Advise driver to update vehicle profile.',
                'permit' => $combined,
                'vehicle' => $combined,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        // Check slot assignment
        if (empty($permit['parking_slot_id']) || ($permit['slot_status'] ?? '') !== 'reserved') {
            return [
                'status' => 'unauthorized',
                'badge' => 'SLOT ISSUE',
                'title' => 'Unauthorized / Assigned Slot Inactive',
                'reason' => 'Assigned parking slot (' . ($permit['slot_code'] ?? 'None') . ') is currently unavailable or under maintenance.',
                'advice' => 'Contact parking administrator to reassign a parking bay.',
                'permit' => $combined,
                'vehicle' => $combined,
                'inside_log' => null,
                'can_enter' => false,
            ];
        }

        return [
            'status' => 'valid',
            'badge' => 'VALID PERMIT',
            'title' => 'Valid Approved Parking Permit',
            'reason' => 'Permit is verified, active, and slot is reserved.',
            'advice' => 'Check physical license plate against vehicle before granting entry.',
            'permit' => $combined,
            'vehicle' => $combined,
            'inside_log' => null,
            'can_enter' => true,
        ];
    }

    return [
        'status' => 'idle',
        'badge' => 'AWAITING SCAN',
        'title' => 'Awaiting Scan or Lookup',
        'reason' => '',
        'advice' => '',
        'permit' => null,
        'vehicle' => null,
        'inside_log' => null,
        'can_enter' => false,
    ];
}

// Perform evaluation if a token or plate was requested
$evaluation = gate_evaluate_checkpoint($pdo, $token, $plate);

// RECORD ENTRY ACTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_entry') {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session token expired. Please scan or search the permit again.';
    } elseif (!$evaluation['can_enter'] || !$evaluation['permit']) {
        $error = 'A valid approved permit is required to record entry. Status was: ' . $evaluation['title'];
    } else {
        $p = $evaluation['permit'];
        try {
            $pdo->beginTransaction();

            // Re-verify and lock vehicle
            $vLock = $pdo->prepare("SELECT id, plate_number, status FROM vehicles WHERE id = ? FOR UPDATE");
            $vLock->execute([$p['vehicle_id']]);
            $vehRow = $vLock->fetch();
            if (!$vehRow || $vehRow['status'] !== 'active') {
                throw new RuntimeException('Vehicle record is inactive or missing.');
            }

            // Re-verify and lock slot
            $sLock = $pdo->prepare("SELECT id, slot_code, status FROM parking_slots WHERE id = ? FOR UPDATE");
            $sLock->execute([$p['parking_slot_id']]);
            $slotRow = $sLock->fetch();
            if (!$slotRow || $slotRow['status'] !== 'reserved') {
                throw new RuntimeException('The assigned parking slot (' . ($p['slot_code'] ?? '') . ') is not reserved.');
            }

            // Check if already inside inside the transaction
            $inCheck = $pdo->prepare("SELECT id FROM parking_logs WHERE vehicle_id = ? AND status = 'inside' LIMIT 1 FOR UPDATE");
            $inCheck->execute([$p['vehicle_id']]);
            if ($inCheck->fetch()) {
                throw new RuntimeException('Vehicle is already recorded as inside campus.');
            }

            // Re-check permit status
            $pCheck = $pdo->prepare("SELECT id, status, expires_at FROM parking_permits WHERE id = ? FOR UPDATE");
            $pCheck->execute([$p['permit_id']]);
            $permRow = $pCheck->fetch();
            if (!$permRow || $permRow['status'] !== 'approved' || (!empty($permRow['expires_at']) && $permRow['expires_at'] < date('Y-m-d'))) {
                throw new RuntimeException('Permit is no longer valid or has expired.');
            }

            // Insert parking log
            $ins = $pdo->prepare("
                INSERT INTO parking_logs (vehicle_id, parking_slot_id, entry_time, recorded_by, status, notes)
                VALUES (?, ?, NOW(), ?, 'inside', ?)
            ");
            $ins->execute([
                $p['vehicle_id'],
                $p['parking_slot_id'],
                current_user_id(),
                'Security confirmed entry via ' . ($token !== '' ? 'QR scan' : 'manual plate lookup') . '.'
            ]);

            $pdo->commit();
            audit_log('vehicle_entry', 'Entry recorded for vehicle ' . $p['plate_number'] . ' at slot ' . $p['slot_code']);

            // Set flash confirmation data for rich feedback
            $_SESSION['gate_entry_confirmed'] = [
                'plate_number' => $p['plate_number'],
                'driver_name' => $p['fullname'] ?? 'Driver',
                'slot_code' => $p['slot_code'] ?? 'Assigned Bay',
                'area_name' => $p['area_name'] ?? 'Parking Area',
                'vehicle_info' => trim(($p['color'] ?? '') . ' ' . ($p['make'] ?? '') . ' ' . ($p['model'] ?? '')),
                'permit_number' => $p['permit_number'] ?? '',
                'entry_time' => date('h:i A'),
            ];

            // Post/Redirect/Get to prevent duplicate submissions
            header('Location: /parking/security/scan.php?confirmed=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Entry could not be recorded. Please try again.';
        }
    }
}

// FETCH RECENT GATE ACTIVITY (LAST 10 ENTRIES/EXITS TODAY)
$recentActivityStmt = $pdo->prepare("
    SELECT pl.id, pl.entry_time, pl.exit_time, pl.status, pl.notes,
           COALESCE(v.plate_number, vis.vehicle_plate, 'UNKNOWN') AS plate_number,
           COALESCE(v.vehicle_type, vis.vehicle_type, 'car') AS vehicle_type,
           v.make, v.model, v.color,
           COALESCE(u.fullname, vis.visitor_name, 'Guest Driver') AS driver_name,
           COALESCE(u.role, 'visitor') AS driver_role,
           sec.fullname AS officer_name,
           ps.slot_code, pa.area_name
    FROM parking_logs pl
    LEFT JOIN vehicles v ON v.id = pl.vehicle_id
    LEFT JOIN users u ON u.id = v.user_id
    LEFT JOIN visitors vis ON vis.id = pl.visitor_id
    LEFT JOIN users sec ON sec.id = pl.recorded_by
    LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id
    LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
    WHERE pl.entry_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY pl.entry_time DESC
    LIMIT 10
");
$recentActivityStmt->execute();
$recentActivities = $recentActivityStmt->fetchAll();

// Quick Gate Counters
$todayEntriesCount = (int)$pdo->query("SELECT COUNT(*) FROM parking_logs WHERE DATE(entry_time) = CURDATE()")->fetchColumn();
$vehiclesInsideCount = (int)$pdo->query("SELECT COUNT(*) FROM parking_logs WHERE status = 'inside'")->fetchColumn();

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="w-full max-w-7xl mx-auto px-4 py-6 sm:px-6 lg:px-8 lg:py-8 space-y-6">

  <!-- PAGE HEADER & STATUS RIBBON -->
  <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between border-b border-slate-200 pb-5">
    <div>
      <div class="inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-700">
        <i class="fas fa-shield-halved"></i> Gate Checkpoint Workflow
      </div>
      <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Vehicle Security Verification</h1>
      <p class="mt-1 text-sm text-slate-500">Live permit camera scanner with instant manual plate lookup fallback and gate activity auditing.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2.5">
      <button id="toggle-audio-btn" type="button" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
        <i id="audio-icon" class="fas fa-volume-high text-emerald-600"></i>
        <span id="audio-label">Gate Chimes: On</span>
      </button>
      <a href="/parking/parking/occupancy.php" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
        <i class="fas fa-gauge-high text-blue-600"></i> Occupancy Dashboard
      </a>
      <a href="/parking/dashboards/security.php" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-800">
        <i class="fas fa-arrow-left"></i> Security Hub
      </a>
    </div>
  </div>

  <!-- CONFIRMATION FEEDBACK NOTIFICATION (TRIGGERED ON SUCCESSFUL ENTRY) -->
  <?php if ($flashConfirmation): ?>
    <div id="gate-confirmation-banner" role="status" class="relative overflow-hidden rounded-2xl border-2 border-emerald-500 bg-gradient-to-r from-emerald-50 via-teal-50 to-emerald-100 p-5 shadow-lg shadow-emerald-900/10">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-start gap-4">
          <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-md shadow-emerald-600/30">
            <i class="fas fa-check text-2xl"></i>
          </div>
          <div>
            <div class="flex items-center gap-2">
              <span class="rounded-full bg-emerald-700 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider text-white">Entry Confirmed</span>
              <span class="text-xs font-semibold text-emerald-800">Recorded at <?= h($flashConfirmation['entry_time']) ?></span>
            </div>
            <h2 class="mt-1 text-xl font-extrabold text-slate-950">
              Vehicle <span class="font-mono text-emerald-900 underline decoration-emerald-400 decoration-2"><?= h($flashConfirmation['plate_number']) ?></span> Granted Entry
            </h2>
            <p class="mt-1 text-sm text-slate-700">
              Driver: <strong><?= h($flashConfirmation['driver_name']) ?></strong> &bull; Assigned Bay: <strong class="text-emerald-950 bg-emerald-200/70 px-2 py-0.5 rounded font-mono font-bold"><?= h($flashConfirmation['slot_code']) ?></strong> (<?= h($flashConfirmation['area_name']) ?>)
            </p>
          </div>
        </div>
        <div class="flex items-center gap-2 self-end sm:self-center">
          <a href="/parking/security/scan.php" class="inline-flex items-center gap-2 rounded-xl bg-emerald-800 px-4 py-2.5 text-xs font-bold text-white shadow transition hover:bg-emerald-900">
            <i class="fas fa-camera"></i> Ready for Next Vehicle
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- ERROR ALERT -->
  <?php if ($error): ?>
    <div role="alert" class="flex items-start gap-3 rounded-2xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 shadow-sm">
      <i class="fas fa-triangle-exclamation mt-0.5 text-red-600 text-base"></i>
      <div>
        <p class="font-bold">Entry Action Failed</p>
        <p class="mt-0.5 text-red-800"><?= h($error) ?></p>
      </div>
    </div>
  <?php endif; ?>

  <!-- MAIN TWO-COLUMN WORKFLOW GRID -->
  <div class="grid items-start gap-6 lg:grid-cols-12">

    <!-- LEFT COLUMN (COL 1-6): SCANNER & MANUAL LOOKUP CONSOLE -->
    <div class="lg:col-span-6 space-y-4">
      <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-100">
        
        <!-- WORKFLOW SELECTOR TABS -->
        <div class="flex border-b border-slate-200 bg-slate-50/80 p-2 gap-2">
          <button id="tab-scanner-btn" type="button" class="flex-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-xs font-bold tracking-wide transition-all <?= $defaultMode === 'scan' ? 'bg-white text-blue-700 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-900 hover:bg-white/50' ?>">
            <i class="fas fa-qrcode text-sm"></i>
            <span>Scanner (Primary)</span>
          </button>
          <button id="tab-manual-btn" type="button" class="flex-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-xs font-bold tracking-wide transition-all <?= $defaultMode === 'manual' ? 'bg-white text-blue-700 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-900 hover:bg-white/50' ?>">
            <i class="fas fa-keyboard text-sm"></i>
            <span>Manual Plate Lookup</span>
          </button>
        </div>

        <div class="p-5 sm:p-6">
          
          <!-- TAB 1: QR CAMERA SCANNER INTERFACE -->
          <div id="scanner-panel" class="<?= $defaultMode === 'scan' ? 'block' : 'hidden' ?> space-y-4">
            <div class="flex items-center justify-between">
              <div>
                <h2 class="text-base font-bold text-slate-900">Live Camera Scanner</h2>
                <p class="text-xs text-slate-500">Hold student or faculty permit QR code up to camera</p>
              </div>
              <span id="scan-pulse-badge" class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600">
                <span id="scan-dot" class="h-2 w-2 rounded-full bg-slate-400"></span>
                <span id="scan-status-text">Standby</span>
              </span>
            </div>

            <!-- CAMERA SOURCE SELECTOR (SHOWN IF MULTIPLE CAMERAS) -->
            <div id="camera-select-container" class="hidden">
              <label for="camera-select" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Camera Input Device:</label>
              <select id="camera-select" class="w-full text-xs rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-slate-800 outline-none focus:bg-white focus:border-blue-600 transition">
              </select>
            </div>

            <!-- CAMERA VIEWFINDER -->
            <div id="viewfinder-container" class="relative overflow-hidden rounded-2xl border-2 border-slate-800 bg-slate-950 shadow-inner transition-all duration-200">
              <div id="reader" class="min-h-[280px] w-full flex items-center justify-center [&_video]:w-full [&_video]:max-h-[340px] [&_video]:object-cover"></div>
              
              <!-- SCANNER CORNER RETICLE OVERLAY -->
              <div id="scanner-reticle" class="pointer-events-none absolute inset-0 flex items-center justify-center opacity-70 transition-opacity">
                <div class="relative h-56 w-56 rounded-xl border border-white/20">
                  <div class="absolute -top-1 -left-1 h-7 w-7 border-t-4 border-l-4 border-emerald-400 rounded-tl-lg"></div>
                  <div class="absolute -top-1 -right-1 h-7 w-7 border-t-4 border-r-4 border-emerald-400 rounded-tr-lg"></div>
                  <div class="absolute -bottom-1 -left-1 h-7 w-7 border-b-4 border-l-4 border-emerald-400 rounded-bl-lg"></div>
                  <div class="absolute -bottom-1 -right-1 h-7 w-7 border-b-4 border-r-4 border-emerald-400 rounded-br-lg"></div>
                </div>
              </div>

              <!-- SCAN LASER INDICATOR (PULSES DURING ANALYSIS) -->
              <div id="scan-laser" class="pointer-events-none absolute inset-x-8 top-1/2 h-0.5 bg-emerald-400 shadow-[0_0_8px_#34d399] opacity-0 transition-opacity"></div>

              <!-- BOTTOM VIEWFINDER STATUS PROMPT -->
              <div class="absolute bottom-2 inset-x-2 rounded-xl bg-black/60 backdrop-blur-sm px-3 py-2 text-center text-xs text-white">
                <span id="camera-hint-text">Select <strong>Start camera</strong> or upload / paste a photo to scan.</span>
              </div>
            </div>

            <!-- SCANNER CONTROLS -->
            <div class="grid grid-cols-2 gap-3 pt-1">
              <button id="start-scan" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100">
                <i class="fas fa-camera"></i> Start camera
              </button>
              <button id="stop-scan" type="button" disabled class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40">
                <i class="fas fa-stop"></i> Stop camera
              </button>
            </div>

            <!-- ALTERNATIVE SCANNING METHODS: PHOTO / FILE / SAMPLE TEST -->
            <div class="pt-3 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-2.5">
              <input type="file" id="qr-input-file" accept="image/*" class="hidden">
              <button id="file-scan-btn" type="button" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-3.5 py-2 text-xs font-semibold rounded-xl border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition shadow-xs">
                <i class="fas fa-image text-blue-600"></i> Scan from Image / Photo
              </button>
              <a href="/parking/security/scan.php?token=a1b2c3d4e5f60718293a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e" class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-3.5 py-2 text-xs font-bold rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-200 hover:bg-emerald-100 transition shadow-xs" title="Verify with active test permit NBC-2026">
                <i class="fas fa-bolt text-amber-500"></i> Test Sample Pass (NBC-2026)
              </a>
            </div>

            <p class="text-center text-xs text-slate-400">
              <i class="fas fa-info-circle"></i> Tip: Drag & drop photo here or press <strong>Ctrl+V</strong> to paste a screenshot directly.
            </p>
          </div>

          <!-- TAB 2: MANUAL PLATE LOOKUP INTERFACE (FALLBACK) -->
          <div id="manual-panel" class="<?= $defaultMode === 'manual' ? 'block' : 'hidden' ?> space-y-4">
            <div class="flex items-center justify-between">
              <div>
                <h2 class="text-base font-bold text-slate-900">Manual License Plate Fallback</h2>
                <p class="text-xs text-slate-500">Search vehicle if camera is unavailable or phone screen is broken</p>
              </div>
              <span class="rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-bold text-blue-700">Fallback Mode</span>
            </div>

            <form method="get" action="/parking/security/scan.php" class="space-y-4">
              <input type="hidden" name="mode" value="manual">
              <div>
                <label for="plate_number_input" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">
                  Vehicle License Plate
                </label>
                <div class="relative">
                  <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                    <i class="fas fa-car-side text-lg"></i>
                  </div>
                  <input
                    type="text"
                    id="plate_number_input"
                    name="plate"
                    required
                    maxlength="20"
                    autocomplete="off"
                    value="<?= h($plate) ?>"
                    placeholder="ABC-1234 or ABC1234"
                    class="w-full rounded-2xl border-2 border-slate-300 bg-slate-50/60 py-3.5 pl-12 pr-4 font-mono text-xl font-black uppercase tracking-wider text-slate-900 outline-none transition focus:border-blue-600 focus:bg-white focus:ring-4 focus:ring-blue-100"
                  >
                </div>
                <p class="mt-1.5 text-xs text-slate-500">Supports standard plates with or without spaces/hyphens (e.g., ABC-1234, ABC 1234, ABC1234).</p>
              </div>

              <div class="flex gap-2">
                <button type="submit" class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-3.5 text-sm font-bold text-white shadow transition hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-300">
                  <i class="fas fa-magnifying-glass"></i> Verify License Plate
                </button>
                <?php if ($plate !== '' || $token !== ''): ?>
                  <a href="/parking/security/scan.php?mode=manual" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm font-semibold text-slate-600 hover:bg-slate-50" title="Clear form">
                    <i class="fas fa-xmark"></i>
                  </a>
                <?php endif; ?>
              </div>
            </form>

            <div class="rounded-xl border border-amber-200 bg-amber-50/80 p-3.5 text-xs text-amber-900">
              <p class="font-bold flex items-center gap-1.5"><i class="fas fa-lightbulb text-amber-600"></i> Gate Tip for Manual Verification</p>
              <p class="mt-1 text-slate-600">Always check that the driver name and vehicle color match the physical car in front of the gate before opening the boom barrier.</p>
            </div>
          </div>

        </div>
      </section>

      <!-- GATE QUICK METRICS -->
      <div class="grid grid-cols-2 gap-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Today's Total Entries</p>
          <div class="mt-1 flex items-baseline gap-2">
            <span class="text-2xl font-black text-slate-900"><?= $todayEntriesCount ?></span>
            <span class="text-xs text-emerald-600 font-semibold"><i class="fas fa-arrow-up"></i> logged</span>
          </div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Vehicles Currently Inside</p>
          <div class="mt-1 flex items-baseline gap-2">
            <span class="text-2xl font-black text-blue-700"><?= $vehiclesInsideCount ?></span>
            <span class="text-xs text-slate-500">active sessions</span>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT COLUMN (COL 7-12): VERIFICATION RESULT DISPLAY -->
    <div class="lg:col-span-6 space-y-4">
      
      <!-- STATE 0: AWAITING SCAN / LOOKUP -->
      <?php if ($evaluation['status'] === 'idle'): ?>
        <section class="flex min-h-[460px] flex-col items-center justify-center rounded-3xl border-2 border-dashed border-slate-200 bg-white p-8 text-center shadow-sm">
          <div class="flex h-20 w-20 items-center justify-center rounded-3xl bg-slate-100 text-slate-400 shadow-inner">
            <i class="fas fa-qrcode text-4xl"></i>
          </div>
          <h2 class="mt-5 text-xl font-bold text-slate-900">Awaiting Permit or Plate</h2>
          <p class="mt-2 max-w-sm text-sm text-slate-500">
            Start the camera to scan a digital permit QR code, or use the manual plate lookup fallback to verify a vehicle.
          </p>
          <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">
              <span class="h-2 w-2 rounded-full bg-slate-400"></span> Ready at gate
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">
              <i class="fas fa-shield-check"></i> Automated permit check
            </span>
          </div>
        </section>

      <!-- STATE 1: VALID PERMIT (EMERALD) -->
      <?php elseif ($evaluation['status'] === 'valid'): ?>
        <?php $p = $evaluation['permit']; ?>
        <section class="overflow-hidden rounded-3xl border-2 border-emerald-400 bg-white shadow-xl shadow-emerald-950/5 ring-1 ring-emerald-300">
          
          <!-- BANNER HEADER -->
          <div class="border-b border-emerald-100 bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-700 px-6 py-5 text-white">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/20 ring-1 ring-white/30">
                  <i class="fas fa-circle-check text-2xl text-white"></i>
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <span class="rounded bg-white/20 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-100">Status 1 of 4</span>
                    <span class="text-xs font-bold text-emerald-100">Access Approved</span>
                  </div>
                  <h2 class="text-xl font-extrabold tracking-tight">VALID PARKING PERMIT</h2>
                </div>
              </div>
              <span class="hidden sm:inline-flex rounded-full bg-emerald-900/40 px-3 py-1 text-xs font-bold uppercase tracking-wider text-emerald-200">
                Verified
              </span>
            </div>
          </div>

          <div class="p-6 space-y-5">
            
            <!-- LICENSE PLATE CARD DISPLAY -->
            <div class="flex items-center justify-between rounded-2xl border-2 border-slate-900 bg-slate-900 p-4 text-white shadow-md">
              <div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">LICENSE PLATE</p>
                <p class="mt-0.5 font-mono text-3xl font-black tracking-wider text-white"><?= h($p['plate_number']) ?></p>
              </div>
              <div class="text-right">
                <span class="rounded bg-emerald-500/20 px-2.5 py-1 text-xs font-bold text-emerald-300 border border-emerald-500/30">
                  <?= h(strtoupper($p['vehicle_type'])) ?>
                </span>
                <p class="mt-1 text-xs text-slate-400"><?= h(trim(($p['color'] ?? '') . ' ' . ($p['make'] ?? '') . ' ' . ($p['model'] ?? ''))) ?></p>
              </div>
            </div>

            <!-- DETAILS GRID -->
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Permit Number</dt>
                <dd class="mt-1 font-mono font-bold text-slate-900"><?= h($p['permit_number']) ?></dd>
              </div>
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Driver / Holder</dt>
                <dd class="mt-1 font-bold text-slate-900"><?= h($p['fullname']) ?></dd>
                <p class="text-[11px] capitalize text-slate-500"><?= h($p['user_role']) ?></p>
              </div>
              <div class="rounded-xl border border-emerald-200 bg-emerald-50/70 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">Assigned Parking Bay</dt>
                <dd class="mt-1 font-mono text-lg font-black text-emerald-950"><?= h($p['slot_code'] ?? '—') ?></dd>
                <p class="text-xs text-emerald-700"><?= h($p['area_name'] ?? 'Assigned Area') ?></p>
              </div>
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Expiration Date</dt>
                <dd class="mt-1 font-bold text-slate-900"><?= h(!empty($p['expires_at']) ? date('M d, Y', strtotime($p['expires_at'])) : 'No expiration') ?></dd>
                <p class="text-[11px] text-emerald-600 font-semibold"><i class="fas fa-shield-check"></i> Permit Active</p>
              </div>
            </dl>

            <!-- CONFIRMATION ACTION FORM -->
            <form method="post" action="/parking/security/scan.php" class="pt-2" onsubmit="return confirm('Confirm plate: Does the physical vehicle match plate <?= h($p['plate_number']) ?>? Record vehicle entry now?');">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="record_entry">
              <input type="hidden" name="token" value="<?= h($token) ?>">
              <input type="hidden" name="plate_number" value="<?= h($p['plate_number']) ?>">

              <button type="submit" id="confirm-entry-btn" class="w-full inline-flex items-center justify-center gap-3 rounded-2xl bg-emerald-600 px-6 py-4 text-base font-extrabold text-white shadow-lg shadow-emerald-600/30 transition hover:bg-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-200">
                <i class="fas fa-check-circle text-xl"></i>
                <span>Confirm Plate &amp; Record Entry</span>
              </button>
            </form>

            <div class="flex items-center justify-between text-xs text-slate-500 pt-1">
              <span>Gate Officer: <strong><?= h($_SESSION['fullname'] ?? current_user()['fullname'] ?? 'Officer') ?></strong></span>
              <a href="/parking/security/scan.php" class="font-semibold text-slate-600 hover:text-slate-900 underline">Cancel / Scan Next</a>
            </div>

          </div>
        </section>

      <!-- STATE 2: EXPIRED PERMIT (AMBER / CORAL) -->
      <?php elseif ($evaluation['status'] === 'expired'): ?>
        <?php $p = $evaluation['permit'] ?: $evaluation['vehicle']; ?>
        <section class="overflow-hidden rounded-3xl border-2 border-amber-400 bg-white shadow-xl shadow-amber-950/5 ring-1 ring-amber-300">
          
          <div class="border-b border-amber-100 bg-gradient-to-r from-amber-600 via-orange-600 to-amber-700 px-6 py-5 text-white">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/20 ring-1 ring-white/30">
                  <i class="fas fa-clock-rotate-left text-2xl text-white"></i>
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <span class="rounded bg-white/20 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-amber-100">Status 2 of 4</span>
                    <span class="text-xs font-bold text-amber-100">Access Denied</span>
                  </div>
                  <h2 class="text-xl font-extrabold tracking-tight">EXPIRED PARKING PERMIT</h2>
                </div>
              </div>
              <span class="hidden sm:inline-flex rounded-full bg-amber-900/40 px-3 py-1 text-xs font-bold uppercase tracking-wider text-amber-200">
                Denied
              </span>
            </div>
          </div>

          <div class="p-6 space-y-5">
            
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
              <div class="flex items-start gap-3">
                <i class="fas fa-circle-exclamation mt-1 text-lg text-amber-600"></i>
                <div>
                  <p class="font-extrabold text-amber-950"><?= h($evaluation['title']) ?></p>
                  <p class="mt-1 text-sm text-amber-800"><?= h($evaluation['reason']) ?></p>
                  <p class="mt-2 text-xs font-semibold text-amber-900"><?= h($evaluation['advice']) ?></p>
                </div>
              </div>
            </div>

            <!-- VEHICLE DETAILS SUMMARY -->
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">License Plate</dt>
                <dd class="mt-1 font-mono text-xl font-black text-slate-900"><?= h($p['plate_number'] ?? $plate) ?></dd>
              </div>
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Permit Holder</dt>
                <dd class="mt-1 font-bold text-slate-900"><?= h($p['fullname'] ?? 'Driver') ?></dd>
                <p class="text-[11px] text-slate-500 capitalize"><?= h($p['user_role'] ?? 'Permit Holder') ?></p>
              </div>
              <div class="rounded-xl border border-red-200 bg-red-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-red-700">Expired Date</dt>
                <dd class="mt-1 font-bold text-red-900"><?= h(!empty($p['expires_at']) ? date('M d, Y', strtotime($p['expires_at'])) : 'Past due') ?></dd>
              </div>
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Assigned Slot</dt>
                <dd class="mt-1 font-mono font-bold text-slate-700"><?= h($p['slot_code'] ?? 'Suspended') ?></dd>
              </div>
            </div>

            <!-- ACTION BUTTONS -->
            <div class="space-y-2 pt-2">
              <a href="/parking/security/visitors.php?plate=<?= urlencode($p['plate_number'] ?? $plate) ?>" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-3.5 text-sm font-bold text-white shadow transition hover:bg-slate-800">
                <i class="fas fa-user-plus"></i> Issue Temporary Visitor Pass
              </a>
              <a href="/parking/security/scan.php" class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <i class="fas fa-rotate"></i> Scan Next Vehicle
              </a>
            </div>

          </div>
        </section>

      <!-- STATE 3: ALREADY INSIDE (INDIGO / AMBER) -->
      <?php elseif ($evaluation['status'] === 'already_inside'): ?>
        <?php $insideLog = $evaluation['inside_log']; ?>
        <section class="overflow-hidden rounded-3xl border-2 border-indigo-400 bg-white shadow-xl shadow-indigo-950/5 ring-1 ring-indigo-300">
          
          <div class="border-b border-indigo-100 bg-gradient-to-r from-indigo-700 via-blue-700 to-indigo-800 px-6 py-5 text-white">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/20 ring-1 ring-white/30">
                  <i class="fas fa-car-side text-2xl text-white"></i>
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <span class="rounded bg-white/20 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-indigo-100">Status 3 of 4</span>
                    <span class="text-xs font-bold text-indigo-100">Duplicate Prevented</span>
                  </div>
                  <h2 class="text-xl font-extrabold tracking-tight">ALREADY INSIDE CAMPUS</h2>
                </div>
              </div>
              <span class="hidden sm:inline-flex rounded-full bg-indigo-950/40 px-3 py-1 text-xs font-bold uppercase tracking-wider text-indigo-200">
                Active Session
              </span>
            </div>
          </div>

          <div class="p-6 space-y-5">
            
            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
              <div class="flex items-start gap-3">
                <i class="fas fa-circle-info mt-1 text-lg text-indigo-600"></i>
                <div>
                  <p class="font-extrabold text-indigo-950"><?= h($evaluation['title']) ?></p>
                  <p class="mt-1 text-sm text-indigo-800"><?= h($evaluation['reason']) ?></p>
                  <p class="mt-2 text-xs font-semibold text-indigo-900"><?= h($evaluation['advice']) ?></p>
                </div>
              </div>
            </div>

            <!-- SESSION DETAILS GRID -->
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">License Plate</dt>
                <dd class="mt-1 font-mono text-xl font-black text-slate-900"><?= h($evaluation['vehicle']['plate_number'] ?? $plate) ?></dd>
              </div>
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Driver</dt>
                <dd class="mt-1 font-bold text-slate-900"><?= h($evaluation['vehicle']['fullname'] ?? 'Driver') ?></dd>
              </div>
              <div class="rounded-xl border border-indigo-200 bg-indigo-50/70 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-indigo-700">Occupied Slot</dt>
                <dd class="mt-1 font-mono text-lg font-black text-indigo-950"><?= h($insideLog['slot_code'] ?? 'Assigned Bay') ?></dd>
                <p class="text-xs text-indigo-700"><?= h($insideLog['area_name'] ?? 'Campus Area') ?></p>
              </div>
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Entry Recorded At</dt>
                <dd class="mt-1 font-bold text-slate-900"><?= h(date('h:i A · M d', strtotime($insideLog['entry_time']))) ?></dd>
                <p class="text-xs text-slate-500">By <?= h($insideLog['officer_name'] ?? 'Security Officer') ?></p>
              </div>
            </dl>

            <!-- ACTION BUTTONS -->
            <div class="space-y-2 pt-2">
              <a href="/parking/security/exit.php?plate=<?= urlencode($evaluation['vehicle']['plate_number'] ?? $plate) ?>" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-700 px-5 py-3.5 text-sm font-bold text-white shadow transition hover:bg-indigo-800">
                <i class="fas fa-right-from-bracket"></i> Switch to Vehicle Exit (Log Departure)
              </a>
              <a href="/parking/security/scan.php" class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <i class="fas fa-rotate"></i> Scan Next Vehicle
              </a>
            </div>

          </div>
        </section>

      <!-- STATE 4: UNAUTHORIZED VEHICLE (ROSE / RED) -->
      <?php elseif ($evaluation['status'] === 'unauthorized'): ?>
        <section class="overflow-hidden rounded-3xl border-2 border-rose-400 bg-white shadow-xl shadow-rose-950/5 ring-1 ring-rose-300">
          
          <div class="border-b border-rose-100 bg-gradient-to-r from-rose-700 via-red-600 to-rose-800 px-6 py-5 text-white">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/20 ring-1 ring-white/30">
                  <i class="fas fa-ban text-2xl text-white"></i>
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <span class="rounded bg-white/20 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-rose-100">Status 4 of 4</span>
                    <span class="text-xs font-bold text-rose-100">Unauthorized</span>
                  </div>
                  <h2 class="text-xl font-extrabold tracking-tight">UNAUTHORIZED VEHICLE</h2>
                </div>
              </div>
              <span class="hidden sm:inline-flex rounded-full bg-rose-950/40 px-3 py-1 text-xs font-bold uppercase tracking-wider text-rose-200">
                Access Denied
              </span>
            </div>
          </div>

          <div class="p-6 space-y-5">
            
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4">
              <div class="flex items-start gap-3">
                <i class="fas fa-circle-xmark mt-1 text-lg text-rose-600"></i>
                <div>
                  <p class="font-extrabold text-rose-950"><?= h($evaluation['title']) ?></p>
                  <p class="mt-1 text-sm text-rose-800"><?= h($evaluation['reason']) ?></p>
                  <p class="mt-2 text-xs font-semibold text-rose-900"><?= h($evaluation['advice']) ?></p>
                </div>
              </div>
            </div>

            <!-- VEHICLE PLATE CARD DISPLAY -->
            <div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Queried License Plate</p>
                <p class="font-mono text-2xl font-black text-slate-900"><?= h($plate !== '' ? $plate : 'Unknown / Scanned Token') ?></p>
              </div>
              <div class="text-right">
                <span class="inline-flex items-center gap-1 rounded bg-rose-100 px-2.5 py-1 text-xs font-bold text-rose-800">
                  <i class="fas fa-xmark"></i> No Valid Permit
                </span>
              </div>
            </div>

            <!-- ACTION BUTTONS -->
            <div class="space-y-2 pt-2">
              <a href="/parking/security/visitors.php?plate=<?= urlencode($plate) ?>" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-3.5 text-sm font-bold text-white shadow transition hover:bg-slate-800">
                <i class="fas fa-user-plus"></i> Register as Temporary Guest Visitor
              </a>
              <a href="/parking/security/scan.php" class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <i class="fas fa-rotate"></i> Scan Next Vehicle
              </a>
            </div>

          </div>
        </section>
      <?php endif; ?>

    </div>
  </div>

  <!-- GATE RECENT ACTIVITY STREAM (AUDIT FEED TO REDUCE MISTAKES) -->
  <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-100">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 px-6 py-5 bg-slate-50/70 gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
          <i class="fas fa-clock-rotate-left"></i>
        </div>
        <div>
          <h2 class="text-base font-bold text-slate-900">Gate Recent Activity (Past 24 Hours)</h2>
          <p class="text-xs text-slate-500">Real-time log of verified entries and exits to prevent duplicate passes and gate errors</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
          <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Live Log
        </span>
        <a href="/parking/parking/logs.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900 underline">
          View full logs &rarr;
        </a>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-left text-sm min-w-[640px]">
        <thead>
          <tr class="border-b border-slate-200 bg-white text-[11px] font-bold uppercase tracking-wider text-slate-400">
            <th class="px-6 py-3.5">Time</th>
            <th class="px-6 py-3.5">Plate Number</th>
            <th class="px-6 py-3.5">Driver / Type</th>
            <th class="px-6 py-3.5">Assigned Slot</th>
            <th class="px-6 py-3.5">Gate Officer</th>
            <th class="px-6 py-3.5">Current Status</th>
            <th class="px-6 py-3.5 text-right">Quick Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (!$recentActivities): ?>
            <tr>
              <td colspan="7" class="px-6 py-12 text-center text-slate-500">
                <i class="fas fa-inbox text-3xl text-slate-300 mb-2 block"></i>
                No vehicle activity recorded in the past 24 hours.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($recentActivities as $log): ?>
              <?php
                $isInside = $log['status'] === 'inside';
                $entryTime = strtotime($log['entry_time']);
                $diffMinutes = (int)floor((time() - $entryTime) / 60);
                $relTime = $diffMinutes < 1 ? 'Just now' : ($diffMinutes < 60 ? $diffMinutes . 'm ago' : (int)floor($diffMinutes/60) . 'h ago');
              ?>
              <tr class="hover:bg-slate-50/80 transition-colors">
                
                <!-- TIME -->
                <td class="px-6 py-4 whitespace-nowrap">
                  <p class="font-mono font-bold text-slate-900"><?= h(date('h:i A', $entryTime)) ?></p>
                  <p class="text-[11px] text-slate-400"><?= h($relTime) ?></p>
                </td>

                <!-- PLATE -->
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-slate-900 px-2.5 py-1 font-mono text-xs font-black tracking-wider text-white shadow-sm">
                    <?= h($log['plate_number']) ?>
                  </span>
                  <?php if (!empty($log['make'])): ?>
                    <p class="text-[11px] text-slate-400 mt-0.5"><?= h(trim(($log['color'] ?? '') . ' ' . $log['make'])) ?></p>
                  <?php endif; ?>
                </td>

                <!-- DRIVER / ROLE -->
                <td class="px-6 py-4 whitespace-nowrap">
                  <p class="font-semibold text-slate-900"><?= h($log['driver_name']) ?></p>
                  <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider <?= $log['driver_role'] === 'student' ? 'bg-blue-50 text-blue-700' : ($log['driver_role'] === 'faculty' ? 'bg-purple-50 text-purple-700' : 'bg-amber-50 text-amber-700') ?>">
                    <?= h($log['driver_role']) ?>
                  </span>
                </td>

                <!-- ASSIGNED SLOT -->
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="font-mono font-bold text-slate-900"><?= h($log['slot_code'] ?? '—') ?></span>
                  <?php if (!empty($log['area_name'])): ?>
                    <p class="text-[11px] text-slate-400"><?= h($log['area_name']) ?></p>
                  <?php endif; ?>
                </td>

                <!-- GATE OFFICER -->
                <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-600">
                  <?= h($log['officer_name'] ?? 'Gate Security') ?>
                </td>

                <!-- STATUS -->
                <td class="px-6 py-4 whitespace-nowrap">
                  <?php if ($isInside): ?>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 border border-emerald-200 px-2.5 py-1 text-xs font-bold text-emerald-700">
                      <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Inside
                    </span>
                  <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                      <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span> Exited (<?= !empty($log['exit_time']) ? h(date('h:i A', strtotime($log['exit_time']))) : 'Closed' ?>)
                    </span>
                  <?php endif; ?>
                </td>

                <!-- QUICK ACTION -->
                <td class="px-6 py-4 whitespace-nowrap text-right">
                  <?php if ($isInside): ?>
                    <a href="/parking/security/exit.php?plate=<?= urlencode($log['plate_number']) ?>" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 hover:text-slate-900 transition">
                      <i class="fas fa-right-from-bracket text-[10px]"></i> Log Exit
                    </a>
                  <?php else: ?>
                    <span class="text-xs text-slate-400">—</span>
                  <?php endif; ?>
                </td>

              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>

<!-- LOCAL JSQR & HTML5 QRCODE LIBRARIES -->
<script src="/parking/assets/js/jsqr.min.js"></script>
<script src="/parking/assets/js/html5-qrcode.min.js"></script>
<script>
if (typeof Html5Qrcode === 'undefined') {
  document.write('<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"><\/script>');
}
</script>

<!-- GATE WORKFLOW CONTROLLER JAVASCRIPT -->
<script>
(() => {
  // 1. AUDIO CHIME ENGINE (WEB AUDIO API SYNTHESIZER - ZERO EXTERNAL ASSETS NEEDED)
  let audioMuted = localStorage.getItem('campus_gate_audio_muted') === 'true';
  const audioBtn = document.getElementById('toggle-audio-btn');
  const audioIcon = document.getElementById('audio-icon');
  const audioLabel = document.getElementById('audio-label');

  function updateAudioUi() {
    if (!audioBtn || !audioIcon || !audioLabel) return;
    if (audioMuted) {
      audioIcon.className = 'fas fa-volume-xmark text-slate-400';
      audioLabel.textContent = 'Gate Chimes: Muted';
      audioBtn.classList.add('opacity-75');
    } else {
      audioIcon.className = 'fas fa-volume-high text-emerald-600';
      audioLabel.textContent = 'Gate Chimes: On';
      audioBtn.classList.remove('opacity-75');
    }
  }
  updateAudioUi();

  if (audioBtn) {
    audioBtn.addEventListener('click', () => {
      audioMuted = !audioMuted;
      localStorage.setItem('campus_gate_audio_muted', audioMuted ? 'true' : 'false');
      updateAudioUi();
      if (!audioMuted) playChime('success');
    });
  }

  function playChime(type) {
    if (audioMuted) return;
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      const ctx = new AudioCtx();
      if (ctx.state === 'suspended') {
        ctx.resume();
      }

      if (type === 'success') {
        // High pleasant two-tone chime (C5 523Hz -> G5 784Hz)
        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = 'sine';
        osc.frequency.setValueAtTime(523.25, now);
        osc.frequency.setValueAtTime(783.99, now + 0.12);
        gain.gain.setValueAtTime(0.2, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.45);
        osc.start(now);
        osc.stop(now + 0.45);
      } else if (type === 'alert' || type === 'denied') {
        // Warning double-tone alert (G3 196Hz -> Eb3 155Hz)
        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = 'sawtooth';
        osc.frequency.setValueAtTime(220, now);
        osc.frequency.setValueAtTime(164.81, now + 0.14);
        gain.gain.setValueAtTime(0.18, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.38);
        osc.start(now);
        osc.stop(now + 0.38);
      }
    } catch (e) {
      // Audio autoplay policy or browser restriction
    }
  }

  // Auto-play audio chime on page load if feedback state is present
  <?php if ($flashConfirmation): ?>
    window.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => playChime('success'), 150);
    });
  <?php elseif (in_array($evaluation['status'], ['expired', 'already_inside', 'unauthorized'], true)): ?>
    window.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => playChime('alert'), 150);
    });
  <?php elseif ($evaluation['status'] === 'valid'): ?>
    window.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => playChime('success'), 150);
    });
  <?php endif; ?>

  // 2. TAB SWITCHER (SCANNER PRIMARY VS MANUAL FALLBACK)
  const tabScannerBtn = document.getElementById('tab-scanner-btn');
  const tabManualBtn = document.getElementById('tab-manual-btn');
  const scannerPanel = document.getElementById('scanner-panel');
  const manualPanel = document.getElementById('manual-panel');
  const plateInput = document.getElementById('plate_number_input');

  function setMode(mode) {
    if (mode === 'scan') {
      if (tabScannerBtn) tabScannerBtn.className = 'flex-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-xs font-bold tracking-wide transition-all bg-white text-blue-700 shadow-sm ring-1 ring-slate-200';
      if (tabManualBtn) tabManualBtn.className = 'flex-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-xs font-bold tracking-wide transition-all text-slate-500 hover:text-slate-900 hover:bg-white/50';
      if (scannerPanel) scannerPanel.classList.remove('hidden');
      if (manualPanel) manualPanel.classList.add('hidden');
    } else {
      if (tabManualBtn) tabManualBtn.className = 'flex-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-xs font-bold tracking-wide transition-all bg-white text-blue-700 shadow-sm ring-1 ring-slate-200';
      if (tabScannerBtn) tabScannerBtn.className = 'flex-1 flex items-center justify-center gap-2 rounded-xl py-2.5 text-xs font-bold tracking-wide transition-all text-slate-500 hover:text-slate-900 hover:bg-white/50';
      if (manualPanel) manualPanel.classList.remove('hidden');
      if (scannerPanel) scannerPanel.classList.add('hidden');
      setTimeout(() => { if (plateInput) plateInput.focus(); }, 50);
    }
  }

  if (tabScannerBtn) tabScannerBtn.addEventListener('click', () => setMode('scan'));
  if (tabManualBtn) tabManualBtn.addEventListener('click', () => setMode('manual'));

  // Hotkey: Ctrl+K or '/' focuses manual lookup
  window.addEventListener('keydown', (e) => {
    if ((e.ctrlKey && e.key.toLowerCase() === 'k') || (e.key === '/' && document.activeElement !== plateInput)) {
      e.preventDefault();
      setMode('manual');
    }
  });

  // 3. UI CONTROLLER REFS
  const startBtn = document.getElementById('start-scan');
  const stopBtn = document.getElementById('stop-scan');
  const hintText = document.getElementById('camera-hint-text');
  const scanDot = document.getElementById('scan-dot');
  const scanStatusText = document.getElementById('scan-status-text');
  const cameraSelect = document.getElementById('camera-select');
  const cameraSelectContainer = document.getElementById('camera-select-container');
  const fileScanBtn = document.getElementById('file-scan-btn');
  const qrFileInput = document.getElementById('qr-input-file');
  const readerEl = document.getElementById('reader');
  const viewfinderContainer = document.getElementById('viewfinder-container');
  const scannerReticle = document.getElementById('scanner-reticle');
  const scanLaser = document.getElementById('scan-laser');

  let scanner = null;
  let running = false;
  let availableCameras = [];

  // Populate available video cameras
  if (typeof Html5Qrcode !== 'undefined') {
    Html5Qrcode.getCameras().then(devices => {
      if (devices && devices.length) {
        availableCameras = devices;
        if (cameraSelect) {
          cameraSelect.innerHTML = '';
          devices.forEach((d, i) => {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.label || `Camera ${i + 1}`;
            cameraSelect.appendChild(opt);
          });
          if (devices.length > 1 && cameraSelectContainer) {
            cameraSelectContainer.classList.remove('hidden');
          }
        }
      }
    }).catch(() => {});
  }

  // Flexible token extraction for any QR code format
  function extractToken(decodedText) {
    if (!decodedText) return null;
    let str = String(decodedText).trim();

    // 1. JSON QR code format
    if (str.startsWith('{') && str.endsWith('}')) {
      try {
        const d = JSON.parse(str);
        if (d.token) return { type: 'token', value: String(d.token).trim() };
        if (d.permit || d.permit_number) return { type: 'token', value: String(d.permit || d.permit_number).trim() };
        if (d.plate || d.plate_number) return { type: 'plate', value: String(d.plate || d.plate_number).trim() };
      } catch(e) {}
    }

    // 2. URL search parameters
    try {
      if (str.includes('://') || str.startsWith('/')) {
        const u = new URL(str, window.location.origin);
        if (u.searchParams.get('token')) {
          return { type: 'token', value: u.searchParams.get('token').trim() };
        }
        if (u.searchParams.get('permit') || u.searchParams.get('permit_number')) {
          return { type: 'token', value: (u.searchParams.get('permit') || u.searchParams.get('permit_number')).trim() };
        }
        if (u.searchParams.get('plate') || u.searchParams.get('plate_number')) {
          return { type: 'plate', value: (u.searchParams.get('plate') || u.searchParams.get('plate_number')).trim() };
        }
      }
    } catch(e) {}

    // 3. URL regex matching
    const tokenMatch = str.match(/[?&]token=([a-f0-9]{32,64})/i);
    if (tokenMatch) return { type: 'token', value: tokenMatch[1].toLowerCase() };

    const permitMatch = str.match(/[?&]permit=([^&]+)/i);
    if (permitMatch) return { type: 'token', value: decodeURIComponent(permitMatch[1]).trim() };

    const plateMatch = str.match(/[?&]plate=([^&]+)/i);
    if (plateMatch) return { type: 'plate', value: decodeURIComponent(plateMatch[1]).trim() };

    // 4. Raw 64-char hex token
    if (/^[a-f0-9]{64}$/i.test(str)) {
      return { type: 'token', value: str.toLowerCase() };
    }

    // 5. Official permit format (CP-YYYY-... or STK-YYYY-...)
    if (/^(CP|STK)-[0-9]{4}-[A-Z0-9]+$/i.test(str)) {
      return { type: 'token', value: str.toUpperCase() };
    }

    // 6. License plate format (e.g. ABC-1234 or ABC 1234 or NBC2026)
    if (/^[A-Z]{2,4}[ -]?[0-9]{3,4}$/i.test(str)) {
      return { type: 'plate', value: str.toUpperCase() };
    }

    // 7. Generic string fallback
    return { type: 'token', value: str };
  }

  function handleDetectedCode(decodedText) {
    const extracted = extractToken(decodedText);
    if (!extracted || !extracted.value) {
      if (hintText) hintText.innerHTML = '<span class="text-amber-300 font-bold">Unrecognized QR format. Please try again.</span>';
      playChime('alert');
      return;
    }

    if (hintText) hintText.innerHTML = '<span class="text-emerald-300 font-bold"><i class="fas fa-check-circle"></i> QR Code Verified! Evaluating Checkpoint…</span>';
    if (scanDot) scanDot.className = 'h-2 w-2 rounded-full bg-emerald-400 animate-ping';
    if (scanStatusText) scanStatusText.textContent = 'Pass Verified';
    if (scanLaser) {
      scanLaser.classList.remove('opacity-0');
      scanLaser.classList.add('opacity-100', 'bg-emerald-300', 'shadow-[0_0_12px_#6ee7b7]');
    }
    if (viewfinderContainer) {
      viewfinderContainer.classList.add('border-emerald-500', 'ring-4', 'ring-emerald-500/20');
    }
    playChime('success');
    
    if (scanner && running) {
      try { scanner.stop(); } catch(e) {}
      running = false;
    }

    // Redirect after slight visual confirmation
    setTimeout(() => {
      if (extracted.type === 'plate') {
        window.location.href = '/parking/security/scan.php?mode=manual&plate=' + encodeURIComponent(extracted.value);
      } else {
        window.location.href = '/parking/security/scan.php?token=' + encodeURIComponent(extracted.value);
      }
    }, 280);
  }

  // 4. MULTI-STRATEGY QR CODE DECODER FOR PHOTOS & SCREENSHOTS
  async function decodeQrFromImageElement(imgElement) {
    // Strategy 1: Hardware-accelerated Native BarcodeDetector (Chrome, Edge, Samsung Internet, Android Webview)
    if ('BarcodeDetector' in window) {
      try {
        const detector = new BarcodeDetector({ formats: ['qr_code'] });
        const barcodes = await detector.detect(imgElement);
        if (barcodes && barcodes.length > 0 && barcodes[0].rawValue) {
          return barcodes[0].rawValue;
        }
      } catch(e) {}
    }

    if (typeof jsQR === 'undefined') {
      return null;
    }

    const origW = imgElement.naturalWidth || imgElement.videoWidth || imgElement.width || 0;
    const origH = imgElement.naturalHeight || imgElement.videoHeight || imgElement.height || 0;
    if (!origW || !origH) return null;

    // Helper: run jsQR on an HTMLCanvasElement
    function scanCanvas(canvas) {
      try {
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const idata = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const res = jsQR(idata.data, canvas.width, canvas.height, {
          inversionAttempts: 'attemptBoth'
        });
        return (res && res.data) ? res.data : null;
      } catch(e) {
        return null;
      }
    }

    // Strategy 2: jsQR Pass A - Full resolution or normalized clamped scale (max dimension 1400px)
    const maxDim = 1400;
    let scale = 1.0;
    if (Math.max(origW, origH) > maxDim) {
      scale = maxDim / Math.max(origW, origH);
    }
    const scaledW = Math.max(1, Math.round(origW * scale));
    const scaledH = Math.max(1, Math.round(origH * scale));

    const mainCanvas = document.createElement('canvas');
    mainCanvas.width = scaledW;
    mainCanvas.height = scaledH;
    const mainCtx = mainCanvas.getContext('2d', { willReadFrequently: true });
    mainCtx.drawImage(imgElement, 0, 0, scaledW, scaledH);

    let decoded = scanCanvas(mainCanvas);
    if (decoded) return decoded;

    // Strategy 3: Native BarcodeDetector on the normalized canvas
    if ('BarcodeDetector' in window) {
      try {
        const detector = new BarcodeDetector({ formats: ['qr_code'] });
        const barcodes = await detector.detect(mainCanvas);
        if (barcodes && barcodes.length > 0 && barcodes[0].rawValue) {
          return barcodes[0].rawValue;
        }
      } catch(e) {}
    }

    // Strategy 4: Center Crop Passes (75%, 60%, 45% zoom)
    // Eliminates surrounding dark frames, modal backdrops, screen bezels, and phone borders
    const cropFactors = [0.75, 0.60, 0.45];
    for (const factor of cropFactors) {
      const cw = Math.max(1, Math.round(scaledW * factor));
      const ch = Math.max(1, Math.round(scaledH * factor));
      const cx = Math.round((scaledW - cw) / 2);
      const cy = Math.round((scaledH - ch) / 2);

      const cropCanvas = document.createElement('canvas');
      cropCanvas.width = cw;
      cropCanvas.height = ch;
      const cropCtx = cropCanvas.getContext('2d', { willReadFrequently: true });
      cropCtx.drawImage(mainCanvas, cx, cy, cw, ch, 0, 0, cw, ch);

      if ('BarcodeDetector' in window) {
        try {
          const detector = new BarcodeDetector({ formats: ['qr_code'] });
          const barcodes = await detector.detect(cropCanvas);
          if (barcodes && barcodes.length > 0 && barcodes[0].rawValue) {
            return barcodes[0].rawValue;
          }
        } catch(e) {}
      }

      decoded = scanCanvas(cropCanvas);
      if (decoded) return decoded;
    }

    // Strategy 5: Explicit Inversion Pass
    // Useful for inverted theme QR codes or dark modal backgrounds
    try {
      const invCanvas = document.createElement('canvas');
      invCanvas.width = scaledW;
      invCanvas.height = scaledH;
      const invCtx = invCanvas.getContext('2d', { willReadFrequently: true });
      const imgData = mainCtx.getImageData(0, 0, scaledW, scaledH);
      const d = imgData.data;
      for (let i = 0; i < d.length; i += 4) {
        d[i] = 255 - d[i];
        d[i+1] = 255 - d[i+1];
        d[i+2] = 255 - d[i+2];
      }
      invCtx.putImageData(imgData, 0, 0);

      decoded = scanCanvas(invCanvas);
      if (decoded) return decoded;

      // Center crop on inverted canvas
      for (const factor of [0.75, 0.55]) {
        const cw = Math.max(1, Math.round(scaledW * factor));
        const ch = Math.max(1, Math.round(scaledH * factor));
        const cx = Math.round((scaledW - cw) / 2);
        const cy = Math.round((scaledH - ch) / 2);

        const cropCanvas = document.createElement('canvas');
        cropCanvas.width = cw;
        cropCanvas.height = ch;
        const cropCtx = cropCanvas.getContext('2d', { willReadFrequently: true });
        cropCtx.drawImage(invCanvas, cx, cy, cw, ch, 0, 0, cw, ch);

        decoded = scanCanvas(cropCanvas);
        if (decoded) return decoded;
      }
    } catch(e) {}

    return null;
  }

  async function stopCameraQuietly() {
    if (scanner && running) {
      try {
        await scanner.stop();
      } catch (e) {}
      running = false;
      if (startBtn) {
        startBtn.disabled = false;
        startBtn.classList.remove('opacity-60', 'cursor-not-allowed');
      }
      if (stopBtn) stopBtn.disabled = true;
      if (scanDot) scanDot.className = 'h-2 w-2 rounded-full bg-slate-400';
      if (scanStatusText) scanStatusText.textContent = 'Camera Stopped';
      if (scanLaser) {
        scanLaser.classList.remove('opacity-100', 'animate-pulse');
        scanLaser.classList.add('opacity-0');
      }
    }
  }

  async function processImageFile(file) {
    if (!file || !file.type || !file.type.startsWith('image/')) {
      if (hintText) hintText.innerHTML = '<span class="text-amber-300 font-semibold">Please select a valid image or screenshot file (JPG, PNG, WebP).</span>';
      return;
    }

    // 1. Safely stop any running camera
    await stopCameraQuietly();

    // 2. Update status and show laser indicator
    if (hintText) hintText.innerHTML = '<span class="text-blue-300"><i class="fas fa-spinner fa-spin"></i> Analyzing photo with multi-pass QR engine…</span>';
    if (scanDot) scanDot.className = 'h-2 w-2 rounded-full bg-blue-500 animate-pulse';
    if (scanStatusText) scanStatusText.textContent = 'Scanning Photo';
    if (scanLaser) {
      scanLaser.classList.remove('opacity-0');
      scanLaser.classList.add('opacity-100', 'animate-pulse');
    }

    // 3. Render clean preview inside viewfinder
    const objectUrl = URL.createObjectURL(file);
    if (readerEl) {
      readerEl.innerHTML = `
        <div class="relative w-full h-[300px] flex items-center justify-center bg-slate-950 p-2 overflow-hidden">
          <img src="${objectUrl}" class="max-h-[280px] max-w-full object-contain rounded-xl shadow-lg border border-slate-800" alt="Scanned permit photo">
          <div class="pointer-events-none absolute inset-0 bg-blue-500/5"></div>
        </div>
      `;
    }

    try {
      // 4. Load into Image object
      const img = new Image();
      await new Promise((resolve, reject) => {
        img.onload = resolve;
        img.onerror = () => reject(new Error('Failed to load image into memory'));
        img.src = objectUrl;
      });

      // 5. Decode using multi-pass BarcodeDetector & jsQR
      let decodedText = await decodeQrFromImageElement(img);

      // 6. Strategy 6: Fallback to Html5Qrcode.scanFile with renderImage=false
      if (!decodedText && typeof Html5Qrcode !== 'undefined') {
        try {
          if (!scanner) {
            scanner = new Html5Qrcode('reader');
          }
          decodedText = await scanner.scanFile(file, false);
        } catch(h5Err) {}
      }

      if (decodedText) {
        handleDetectedCode(decodedText);
      } else {
        if (hintText) {
          hintText.innerHTML = '<span class="text-rose-400 font-bold">No QR code found in photo. Please ensure good lighting or enter the plate below.</span>';
        }
        if (scanDot) scanDot.className = 'h-2 w-2 rounded-full bg-amber-500';
        if (scanStatusText) scanStatusText.textContent = 'Decode Failed';
        if (scanLaser) {
          scanLaser.classList.remove('opacity-100', 'animate-pulse');
          scanLaser.classList.add('opacity-0');
        }
        playChime('alert');
      }
    } catch (err) {
      if (hintText) hintText.innerHTML = '<span class="text-rose-400 font-bold">Could not process image. Please try another picture.</span>';
      if (scanDot) scanDot.className = 'h-2 w-2 rounded-full bg-red-500';
      if (scanStatusText) scanStatusText.textContent = 'Error';
      if (scanLaser) {
        scanLaser.classList.remove('opacity-100', 'animate-pulse');
        scanLaser.classList.add('opacity-0');
      }
      playChime('alert');
    } finally {
      setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
      if (qrFileInput) qrFileInput.value = '';
    }
  }

  // 5. LIVE CAMERA SCANNER CONTROLLER
  if (startBtn) {
    startBtn.addEventListener('click', async () => {
      if (running) return;
      startBtn.disabled = true;
      startBtn.classList.add('opacity-60', 'cursor-not-allowed');

      // Reset viewfinder DOM
      if (readerEl) readerEl.innerHTML = '';
      if (viewfinderContainer) {
        viewfinderContainer.classList.remove('border-emerald-500', 'ring-4', 'ring-emerald-500/20');
      }

      if (typeof Html5Qrcode === 'undefined') {
        if (hintText) hintText.innerHTML = '<span class="text-rose-400 font-bold">Scanner library could not load. Ensure network access or use file upload.</span>';
        if (scanDot) scanDot.className = 'h-2 w-2 rounded-full bg-red-500';
        if (scanStatusText) scanStatusText.textContent = 'Library Error';
        startBtn.disabled = false;
        startBtn.classList.remove('opacity-60', 'cursor-not-allowed');
        return;
      }

      if (!scanner) {
        scanner = new Html5Qrcode('reader');
      }
      if (hintText) hintText.textContent = 'Starting camera viewfinder…';

      const scanConfig = {
        fps: 12,
        qrbox: (w, h) => {
          const edge = Math.min(w, h);
          const size = Math.max(Math.floor(edge * 0.85), 220);
          return { width: size, height: size };
        },
        aspectRatio: 1.0,
        experimentalFeatures: {
          useBarCodeDetectorIfSupported: true
        }
      };

      const getTargetCamera = () => {
        if (cameraSelect && cameraSelect.value) {
          return cameraSelect.value;
        }
        return { facingMode: 'environment' };
      };

      try {
        // First attempt: environment / selected camera
        await scanner.start(getTargetCamera(), scanConfig, handleDetectedCode, () => {});
      } catch (e1) {
        try {
          // Second attempt: front / user webcam (for laptops & desktops)
          if (hintText) hintText.textContent = 'Switching to default webcam…';
          await scanner.start({ facingMode: 'user' }, scanConfig, handleDetectedCode, () => {});
        } catch (e2) {
          try {
            // Third attempt: first enumerated device
            const devs = await Html5Qrcode.getCameras();
            if (devs && devs.length) {
              await scanner.start(devs[0].id, scanConfig, handleDetectedCode, () => {});
            } else {
              throw e2;
            }
          } catch (e3) {
            running = false;
            startBtn.disabled = false;
            startBtn.classList.remove('opacity-60', 'cursor-not-allowed');
            if (stopBtn) stopBtn.disabled = true;
            if (scanDot) scanDot.className = 'h-2 w-2 rounded-full bg-red-500';
            if (scanStatusText) scanStatusText.textContent = 'Camera Unavailable';
            if (hintText) hintText.innerHTML = '<span class="text-rose-300">Camera access failed. Ensure camera permission is granted or use <strong>Scan from Image / Photo</strong>.</span>';
            return;
          }
        }
      }

      running = true;
      startBtn.disabled = true;
      if (stopBtn) stopBtn.disabled = false;
      if (scanDot) scanDot.className = 'h-2 w-2 rounded-full bg-emerald-500 animate-pulse';
      if (scanStatusText) scanStatusText.textContent = 'Camera Active';
      if (hintText) hintText.textContent = 'Camera is active. Hold permit QR code in front of camera.';
      if (scanLaser) {
        scanLaser.classList.remove('opacity-0');
        scanLaser.classList.add('opacity-100', 'animate-pulse');
      }
    });
  }

  if (stopBtn) {
    stopBtn.addEventListener('click', async () => {
      await stopCameraQuietly();
      if (hintText) hintText.textContent = 'Camera stopped. Start camera or upload / paste photo when ready.';
    });
  }

  // Switch camera when user selects a different input
  if (cameraSelect) {
    cameraSelect.addEventListener('change', async () => {
      if (running && scanner) {
        await stopCameraQuietly();
        if (startBtn) startBtn.click();
      }
    });
  }

  // File Upload Button & Input
  if (fileScanBtn && qrFileInput) {
    fileScanBtn.addEventListener('click', () => {
      qrFileInput.click();
    });

    qrFileInput.addEventListener('change', async (e) => {
      if (!e.target.files || !e.target.files.length) return;
      processImageFile(e.target.files[0]);
    });
  }

  // 6. DRAG & DROP SUPPORT ON VIEW FINDER AND PANEL
  const dropZone = document.getElementById('viewfinder-container') || scannerPanel;
  if (dropZone) {
    ['dragenter', 'dragover'].forEach(name => {
      dropZone.addEventListener(name, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.add('ring-4', 'ring-blue-500/40', 'border-blue-400');
        if (hintText) hintText.innerHTML = '<span class="text-blue-300 font-bold"><i class="fas fa-file-import"></i> Drop image to scan QR code</span>';
      });
    });

    ['dragleave', 'drop'].forEach(name => {
      dropZone.addEventListener(name, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropZone.classList.remove('ring-4', 'ring-blue-500/40', 'border-blue-400');
      });
    });

    dropZone.addEventListener('drop', (e) => {
      const dt = e.dataTransfer;
      if (dt && dt.files && dt.files.length > 0) {
        processImageFile(dt.files[0]);
      }
    });
  }

  // 7. CLIPBOARD PASTE SUPPORT (Ctrl+V)
  window.addEventListener('paste', (e) => {
    // Only capture if user is not actively typing into a text input or textarea
    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA')) {
      return;
    }
    const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
    if (!items) return;
    for (let i = 0; i < items.length; i++) {
      if (items[i].type && items[i].type.startsWith('image/')) {
        const file = items[i].getAsFile();
        if (file) {
          e.preventDefault();
          setMode('scan');
          processImageFile(file);
          break;
        }
      }
    }
  });

  // 8. PREVENT DOUBLE SUBMIT ON ENTRY FORM
  const confirmEntryBtn = document.getElementById('confirm-entry-btn');
  if (confirmEntryBtn) {
    confirmEntryBtn.closest('form')?.addEventListener('submit', () => {
      confirmEntryBtn.disabled = true;
      confirmEntryBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin text-xl"></i><span>Recording Entry…</span>';
      confirmEntryBtn.classList.add('opacity-75', 'cursor-wait');
    });
  }
})();
</script>

<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>

