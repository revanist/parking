<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';

$isLoggedIn = !empty($_SESSION['user_id']);
$roleNow = $isLoggedIn ? current_role() : '';
$pageTitle = 'Live Parking Occupancy';
$activeItem = 'occupancy';
$role = match ($roleNow) {
    'admin' => 'Administrator',
    'security' => 'Security Staff',
    'student' => 'Student',
    'faculty' => 'Faculty',
    default => 'Guest Visitor'
};

// ==========================================
// 1. DATABASE QUERIES (100% Dynamic from DB)
// ==========================================

// 1. Total active slots
$totalSlots = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM parking_slots ps 
    JOIN parking_areas pa ON pa.id = ps.parking_area_id 
    WHERE pa.status = 'active' AND ps.status <> 'inactive'
")->fetchColumn();

// 2. Occupied slots (vehicles currently inside)
$occupiedSlots = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM parking_logs 
    WHERE status = 'inside'
")->fetchColumn();

// 3. Available slots (available and not occupied by an active log)
$availableSlots = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM parking_slots ps 
    JOIN parking_areas pa ON pa.id = ps.parking_area_id 
    WHERE pa.status = 'active' 
      AND ps.status = 'available'
      AND ps.id NOT IN (
          SELECT parking_slot_id 
          FROM parking_logs 
          WHERE status = 'inside' AND parking_slot_id IS NOT NULL
      )
")->fetchColumn();

// 4. Reserved slots
$reservedSlots = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM parking_slots ps 
    JOIN parking_areas pa ON pa.id = ps.parking_area_id 
    WHERE pa.status = 'active' 
      AND ps.status = 'reserved'
")->fetchColumn();

// 5. Slots under maintenance
$maintenanceSlots = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM parking_slots ps 
    JOIN parking_areas pa ON pa.id = ps.parking_area_id 
    WHERE pa.status = 'active' 
      AND ps.status = 'maintenance'
")->fetchColumn();

// 6. Simple occupancy percentage: (Occupied / Total) * 100
$occupancyPercentage = $totalSlots > 0 ? round(($occupiedSlots / $totalSlots) * 100, 1) : 0.0;

// Occupancy classification
if ($occupancyPercentage >= 95.0) {
    $statusLabel = 'Critical / Lot Full';
    $statusColorClass = 'text-red-700 bg-red-50 border-red-200';
    $statusDotClass = 'bg-red-500';
} elseif ($occupancyPercentage >= 75.0) {
    $statusLabel = 'High Occupancy';
    $statusColorClass = 'text-amber-700 bg-amber-50 border-amber-200';
    $statusDotClass = 'bg-amber-500';
} elseif ($occupancyPercentage >= 40.0) {
    $statusLabel = 'Moderate Occupancy';
    $statusColorClass = 'text-blue-700 bg-blue-50 border-blue-200';
    $statusDotClass = 'bg-blue-500';
} else {
    $statusLabel = 'Optimal / Plenty Available';
    $statusColorClass = 'text-emerald-700 bg-emerald-50 border-emerald-200';
    $statusDotClass = 'bg-emerald-500';
}

// 7. Area Breakdown
$areasStmt = $pdo->query("
    SELECT 
        pa.id,
        pa.area_name,
        pa.area_type,
        COUNT(ps.id) AS total_slots,
        SUM(CASE WHEN ps.status = 'available' AND (pl.id IS NULL) THEN 1 ELSE 0 END) AS available_slots,
        SUM(CASE WHEN pl.id IS NOT NULL THEN 1 ELSE 0 END) AS occupied_slots,
        SUM(CASE WHEN ps.status = 'reserved' THEN 1 ELSE 0 END) AS reserved_slots,
        SUM(CASE WHEN ps.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_slots
    FROM parking_areas pa
    LEFT JOIN parking_slots ps ON ps.parking_area_id = pa.id AND ps.status <> 'inactive'
    LEFT JOIN parking_logs pl ON pl.parking_slot_id = ps.id AND pl.status = 'inside'
    WHERE pa.status = 'active'
    GROUP BY pa.id, pa.area_name, pa.area_type
    ORDER BY pa.area_name
");
$areas = $areasStmt->fetchAll();

// 8. Individual Parking Slots Grid
$slotsStmt = $pdo->query("
    SELECT 
        ps.id AS slot_id,
        ps.slot_code,
        ps.status AS db_status,
        pa.id AS area_id,
        pa.area_name,
        pa.area_type,
        pl.id AS active_log_id,
        pl.entry_time AS active_entry_time,
        COALESCE(v.plate_number, vis.vehicle_plate) AS active_plate,
        COALESCE(u.fullname, vis.visitor_name) AS active_occupant_name,
        CASE 
            WHEN pl.id IS NOT NULL THEN 'occupied'
            WHEN ps.status = 'maintenance' THEN 'maintenance'
            WHEN ps.status = 'reserved' THEN 'reserved'
            ELSE 'available'
        END AS live_status
    FROM parking_slots ps
    JOIN parking_areas pa ON pa.id = ps.parking_area_id
    LEFT JOIN parking_logs pl ON pl.parking_slot_id = ps.id AND pl.status = 'inside'
    LEFT JOIN vehicles v ON v.id = pl.vehicle_id
    LEFT JOIN users u ON u.id = v.user_id
    LEFT JOIN visitors vis ON vis.id = pl.visitor_id
    WHERE pa.status = 'active' AND ps.status <> 'inactive'
    ORDER BY pa.area_name, ps.slot_code
");
$slots = $slotsStmt->fetchAll();

// 9. Recent Entry / Exit Activity
$logsStmt = $pdo->query("
    SELECT 
        pl.id,
        pl.entry_time,
        pl.exit_time,
        pl.status,
        pl.notes,
        COALESCE(v.plate_number, vis.vehicle_plate, 'VISITOR') AS plate_number,
        COALESCE(v.vehicle_type, vis.vehicle_type, 'car') AS vehicle_type,
        COALESCE(CONCAT(v.make, ' ', v.model), vis.visitor_name, 'Visitor Vehicle') AS vehicle_desc,
        COALESCE(u.fullname, vis.visitor_name, 'Guest Visitor') AS driver_name,
        CASE 
            WHEN vis.id IS NOT NULL THEN 'Visitor'
            WHEN u.role IS NOT NULL THEN UPPER(u.role)
            ELSE 'Registered'
        END AS driver_type,
        ps.slot_code,
        pa.area_name,
        CASE 
            WHEN pl.status = 'inside' THEN 'ENTRY'
            WHEN pl.exit_time IS NOT NULL THEN 'EXIT'
            ELSE 'LOG'
        END AS activity_type,
        COALESCE(pl.exit_time, pl.entry_time) AS event_time,
        TIMESTAMPDIFF(MINUTE, pl.entry_time, COALESCE(pl.exit_time, NOW())) AS duration_minutes
    FROM parking_logs pl
    LEFT JOIN vehicles v ON v.id = pl.vehicle_id
    LEFT JOIN users u ON u.id = v.user_id
    LEFT JOIN visitors vis ON vis.id = pl.visitor_id
    LEFT JOIN parking_slots ps ON ps.id = pl.parking_slot_id
    LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
    ORDER BY COALESCE(pl.exit_time, pl.entry_time) DESC
    LIMIT 25
");
$recentLogs = $logsStmt->fetchAll();

// If user is logged in, wrap in dashboard chrome; otherwise use public header
if ($isLoggedIn) {
    include __DIR__ . '/../layouts/dashboard_header.php';
    include __DIR__ . '/../includes/sidebar.php';
} else {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Parking Occupancy — CampusPark</title>
    <script src="/parking/assets/js/tailwindcss.js"></script>
    <script src="/parking/assets/js/lucide.js"></script>
    <link rel="stylesheet" href="/parking/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/parking/assets/css/fonts.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .bg-grid { background-size: 40px 40px; background-image: linear-gradient(to right, #f1f5f9 1px, transparent 1px), linear-gradient(to bottom, #f1f5f9 1px, transparent 1px); }
    </style>
</head>
<body class="text-slate-800 min-h-screen bg-slate-50 bg-grid">
    <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="/parking/index.php" class="flex items-center gap-3">
                <img src="/parking/img/campus_parking_logo.png" alt="Campus Parking Logo" class="w-8 h-8 object-contain">
                <span class="font-extrabold text-lg tracking-tight text-slate-900">Campus Parking</span>
                <span class="text-xs px-2.5 py-0.5 rounded-full bg-slate-100 font-semibold text-slate-600 border border-slate-200">Live Telemetry</span>
            </a>
            <div class="flex items-center gap-3">
                <a href="/parking/login.php" class="text-sm font-semibold text-slate-600 hover:text-slate-900 px-3 py-1.5 transition">Sign In</a>
                <a href="/parking/register.php" class="text-sm font-semibold text-white bg-slate-900 hover:bg-slate-800 px-4 py-1.5 rounded-lg transition shadow-sm">Register</a>
            </div>
        </div>
    </header>
    <main class="w-full">
<?php
}
?>

<div class="px-4 sm:px-6 lg:px-8 py-8 lg:py-10 max-w-7xl mx-auto w-full">

    <!-- Top Hero Section -->
    <div class="flex flex-col xl:flex-row xl:items-end xl:justify-between gap-5 mb-8">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-[.18em] text-slate-500">
                    Campus Parking Telemetry
                </span>
                <span class="text-slate-300">•</span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    Live Data Stream
                </span>
            </div>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900">
                Live Parking Occupancy
            </h1>
            <p class="mt-1.5 text-sm sm:text-base text-slate-500 max-w-2xl">
                Real-time campus slot availability, telemetry statistics, and vehicle entry/exit activity straight from the database.
            </p>
        </div>

        <!-- Telemetry Controls -->
        <div class="flex flex-wrap items-center gap-2.5 sm:gap-3 bg-white p-2 sm:p-2.5 rounded-2xl border border-slate-200 shadow-sm">
            <!-- Server Time -->
            <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 text-xs font-mono text-slate-500 border-r border-slate-200">
                <i class="far fa-clock text-slate-400"></i>
                <span id="live-server-clock"><?= date('h:i:s A') ?></span>
            </div>

            <!-- Auto-refresh Countdown Pill -->
            <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-medium text-slate-600">
                <span class="relative flex h-2 w-2">
                    <span id="countdown-ping" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                </span>
                <span id="refresh-status-text">Updating in <strong id="refresh-countdown">10</strong>s</span>
            </div>

            <!-- Pause / Play Toggle Button -->
            <button id="toggle-refresh-btn" type="button" title="Pause or resume auto-refresh" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition">
                <i id="toggle-refresh-icon" class="fas fa-pause text-xs"></i>
            </button>

            <!-- Manual Refresh Button -->
            <button id="manual-refresh-btn" type="button" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold shadow-sm transition active:scale-95">
                <i id="manual-refresh-icon" class="fas fa-rotate text-xs"></i>
                <span>Refresh</span>
            </button>
        </div>
    </div>

    <!-- 5 Core Metric Cards (Database-driven) -->
    <div class="grid gap-4 grid-cols-2 lg:grid-cols-5 mb-8">
        
        <!-- Total Slots -->
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Slots</p>
                    <p id="stat-total" class="mt-2 text-3xl sm:text-4xl font-extrabold font-mono text-slate-900"><?= $totalSlots ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-700">
                    <i class="fas fa-square-parking text-base"></i>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between text-xs text-slate-500 pt-3 border-t border-slate-100">
                <span>Active campus slots</span>
                <span class="font-semibold text-slate-700"><?= count($areas) ?> Areas</span>
            </div>
        </div>

        <!-- Occupied Slots -->
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Occupied Slots</p>
                    <p id="stat-occupied" class="mt-2 text-3xl sm:text-4xl font-extrabold font-mono text-indigo-950"><?= $occupiedSlots ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-slate-900 flex items-center justify-center text-white shadow-sm">
                    <i class="fas fa-car text-base"></i>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between text-xs text-slate-500 pt-3 border-t border-slate-100">
                <span>Currently inside</span>
                <span class="font-semibold text-slate-700 font-mono"><?= $occupancyPercentage ?>%</span>
            </div>
        </div>

        <!-- Available Slots -->
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Available Slots</p>
                    <p id="stat-available" class="mt-2 text-3xl sm:text-4xl font-extrabold font-mono text-emerald-600"><?= $availableSlots ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-600 flex items-center justify-center text-white shadow-sm">
                    <i class="fas fa-circle-check text-base"></i>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between text-xs text-emerald-700 pt-3 border-t border-emerald-100">
                <span>Ready for parking</span>
                <span class="font-semibold font-mono"><?= $totalSlots > 0 ? round(($availableSlots / $totalSlots) * 100, 1) : 0 ?>% free</span>
            </div>
        </div>

        <!-- Reserved Slots -->
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Reserved Slots</p>
                    <p id="stat-reserved" class="mt-2 text-3xl sm:text-4xl font-extrabold font-mono text-blue-600"><?= $reservedSlots ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-600">
                    <i class="fas fa-id-card text-base"></i>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between text-xs text-slate-500 pt-3 border-t border-slate-100">
                <span>Permits / Designated</span>
                <span class="font-semibold text-slate-700 font-mono"><?= $totalSlots > 0 ? round(($reservedSlots / $totalSlots) * 100, 1) : 0 ?>%</span>
            </div>
        </div>

        <!-- Maintenance Slots -->
        <div class="col-span-2 lg:col-span-1 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Maintenance</p>
                    <p id="stat-maintenance" class="mt-2 text-3xl sm:text-4xl font-extrabold font-mono text-amber-600"><?= $maintenanceSlots ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-center text-amber-600">
                    <i class="fas fa-wrench text-base"></i>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between text-xs text-slate-500 pt-3 border-t border-slate-100">
                <span>Out of service</span>
                <span class="font-semibold text-slate-700 font-mono"><?= $totalSlots > 0 ? round(($maintenanceSlots / $totalSlots) * 100, 1) : 0 ?>%</span>
            </div>
        </div>

    </div>

    <!-- Occupancy Meter & Parking Area Breakdown -->
    <div class="grid gap-6 lg:grid-cols-3 mb-8">
        
        <!-- Main Occupancy Percentage Meter -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Live Metric</p>
                        <h2 class="text-lg font-bold text-slate-900 mt-0.5">Occupancy Rate</h2>
                    </div>
                    <span id="badge-status-container" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border <?= $statusColorClass ?>">
                        <span id="badge-status-dot" class="w-1.5 h-1.5 rounded-full <?= $statusDotClass ?>"></span>
                        <span id="badge-status-label"><?= $statusLabel ?></span>
                    </span>
                </div>

                <!-- Big Numeric Percentage Display -->
                <div class="mt-6 flex items-baseline gap-2">
                    <span id="stat-pct" class="text-5xl sm:text-6xl font-black font-mono tracking-tight text-slate-900"><?= $occupancyPercentage ?></span>
                    <span class="text-3xl sm:text-4xl font-extrabold text-slate-400 font-mono">%</span>
                    <span class="ml-auto text-xs text-slate-400 font-mono text-right leading-tight">
                        <strong id="stat-occ-ratio"><?= $occupiedSlots ?></strong> occupied<br>of <strong id="stat-tot-ratio"><?= $totalSlots ?></strong> slots
                    </span>
                </div>

                <!-- Multi-segment visual progress bar -->
                <div class="mt-5">
                    <div class="h-3.5 w-full bg-slate-100 rounded-full overflow-hidden flex shadow-inner p-0.5">
                        <div id="bar-occupied" class="bg-slate-900 h-full rounded-l-full transition-all duration-500" style="width: <?= min(100, $occupancyPercentage) ?>%" title="Occupied"></div>
                        <div id="bar-reserved" class="bg-blue-500 h-full transition-all duration-500" style="width: <?= $totalSlots > 0 ? round(($reservedSlots / $totalSlots) * 100, 1) : 0 ?>%" title="Reserved"></div>
                        <div id="bar-maintenance" class="bg-amber-400 h-full transition-all duration-500" style="width: <?= $totalSlots > 0 ? round(($maintenanceSlots / $totalSlots) * 100, 1) : 0 ?>%" title="Maintenance"></div>
                        <div id="bar-available" class="bg-emerald-400 h-full rounded-r-full transition-all duration-500" style="width: <?= $totalSlots > 0 ? round(($availableSlots / $totalSlots) * 100, 1) : 0 ?>%" title="Available"></div>
                    </div>
                </div>

                <!-- Legend Breakdown -->
                <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                    <div class="flex items-center gap-2 text-slate-600">
                        <span class="w-2.5 h-2.5 rounded-sm bg-slate-900 shrink-0"></span>
                        <span>Occupied: <strong id="legend-occ" class="font-mono"><?= $occupiedSlots ?></strong> (<span id="legend-occ-pct"><?= $occupancyPercentage ?>%</span>)</span>
                    </div>
                    <div class="flex items-center gap-2 text-slate-600">
                        <span class="w-2.5 h-2.5 rounded-sm bg-emerald-400 shrink-0"></span>
                        <span>Available: <strong id="legend-avl" class="font-mono"><?= $availableSlots ?></strong> (<span id="legend-avl-pct"><?= $totalSlots > 0 ? round(($availableSlots / $totalSlots) * 100, 1) : 0 ?>%</span>)</span>
                    </div>
                    <div class="flex items-center gap-2 text-slate-600">
                        <span class="w-2.5 h-2.5 rounded-sm bg-blue-500 shrink-0"></span>
                        <span>Reserved: <strong id="legend-res" class="font-mono"><?= $reservedSlots ?></strong> (<span id="legend-res-pct"><?= $totalSlots > 0 ? round(($reservedSlots / $totalSlots) * 100, 1) : 0 ?>%</span>)</span>
                    </div>
                    <div class="flex items-center gap-2 text-slate-600">
                        <span class="w-2.5 h-2.5 rounded-sm bg-amber-400 shrink-0"></span>
                        <span>Maintenance: <strong id="legend-mnt" class="font-mono"><?= $maintenanceSlots ?></strong> (<span id="legend-mnt-pct"><?= $totalSlots > 0 ? round(($maintenanceSlots / $totalSlots) * 100, 1) : 0 ?>%</span>)</span>
                    </div>
                </div>
            </div>

            <!-- Mathematical Formula Footer -->
            <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
                <span>Calculation formula:</span>
                <span class="font-mono bg-slate-50 px-2 py-0.5 rounded border border-slate-200 text-slate-600">(Occupied ÷ Total) × 100</span>
            </div>
        </div>

        <!-- Parking Areas Occupancy Breakdown -->
        <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Campus Lots</p>
                        <h2 class="text-lg font-bold text-slate-900 mt-0.5">Occupancy by Parking Area</h2>
                    </div>
                    <span class="text-xs font-medium text-slate-500"><?= count($areas) ?> Active Areas</span>
                </div>

                <div id="area-breakdown-container" class="space-y-4">
                    <?php foreach ($areas as $area): 
                        $areaTot = (int)$area['total_slots'];
                        $areaOcc = (int)$area['occupied_slots'];
                        $areaAvl = (int)$area['available_slots'];
                        $areaPct = $areaTot > 0 ? round(($areaOcc / $areaTot) * 100, 1) : 0.0;
                    ?>
                    <div class="p-3.5 rounded-xl border border-slate-100 bg-slate-50/60 hover:bg-slate-50 transition">
                        <div class="flex items-center justify-between text-sm mb-2">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-slate-900"><?= h($area['area_name']) ?></span>
                                <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-white border border-slate-200 text-slate-600"><?= ucfirst(h($area['area_type'])) ?></span>
                            </div>
                            <div class="flex items-center gap-3 text-xs font-mono">
                                <span class="text-emerald-700 font-semibold bg-emerald-50 px-2 py-0.5 rounded"><?= $areaAvl ?> free</span>
                                <span class="text-slate-600 font-medium"><?= $areaOcc ?> / <?= $areaTot ?> occupied</span>
                                <span class="font-bold text-slate-900 w-12 text-right"><?= $areaPct ?>%</span>
                            </div>
                        </div>
                        <div class="h-2 w-full bg-slate-200/80 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500 <?= $areaPct >= 90 ? 'bg-red-500' : ($areaPct >= 70 ? 'bg-amber-500' : 'bg-slate-900') ?>" style="width: <?= min(100, $areaPct) ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                <span>Updated in real-time</span>
                <span class="text-slate-500">Live area telemetry stream</span>
            </div>
        </div>

    </div>

    <!-- Visual Interactive Slot Grid -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-8">
        <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Physical Layout</p>
                <h2 class="text-lg font-bold text-slate-900 mt-0.5">Interactive Slot Visualizer</h2>
                <p class="text-xs text-slate-500 mt-0.5">Real-time status of individual parking spaces across all campus lots.</p>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Status Filter Pills -->
                <div class="inline-flex rounded-xl p-1 bg-slate-100 border border-slate-200 text-xs font-semibold">
                    <button type="button" class="slot-filter-btn px-2.5 py-1 rounded-lg bg-white text-slate-900 shadow-sm transition" data-filter="all">All</button>
                    <button type="button" class="slot-filter-btn px-2.5 py-1 rounded-lg text-slate-600 hover:text-slate-900 transition" data-filter="available">Available</button>
                    <button type="button" class="slot-filter-btn px-2.5 py-1 rounded-lg text-slate-600 hover:text-slate-900 transition" data-filter="occupied">Occupied</button>
                    <button type="button" class="slot-filter-btn px-2.5 py-1 rounded-lg text-slate-600 hover:text-slate-900 transition" data-filter="reserved">Reserved</button>
                    <button type="button" class="slot-filter-btn px-2.5 py-1 rounded-lg text-slate-600 hover:text-slate-900 transition" data-filter="maintenance">Maintenance</button>
                </div>

                <!-- Area Dropdown Filter -->
                <select id="slot-area-filter" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-300">
                    <option value="all">All Parking Areas</option>
                    <?php foreach ($areas as $area): ?>
                    <option value="<?= h($area['area_name']) ?>"><?= h($area['area_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Slot Grid Container -->
        <div class="p-6">
            <div id="slots-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <?php foreach ($slots as $slot): 
                    $liveStatus = $slot['live_status'];
                    $statusStyles = match ($liveStatus) {
                        'occupied' => 'border-slate-900 bg-slate-900 text-white shadow-sm',
                        'available' => 'border-emerald-200 bg-emerald-50/60 text-slate-900 hover:bg-emerald-50',
                        'reserved' => 'border-blue-200 bg-blue-50/50 text-slate-900 hover:bg-blue-50',
                        'maintenance' => 'border-amber-200 bg-amber-50/60 text-slate-900 hover:bg-amber-50',
                        default => 'border-slate-200 bg-slate-50 text-slate-700'
                    };
                    $pillStyles = match ($liveStatus) {
                        'occupied' => 'bg-white/20 text-white',
                        'available' => 'bg-emerald-100 text-emerald-800',
                        'reserved' => 'bg-blue-100 text-blue-800',
                        'maintenance' => 'bg-amber-100 text-amber-800',
                        default => 'bg-slate-200 text-slate-800'
                    };
                ?>
                <div class="slot-card p-3 rounded-xl border transition-all duration-200 <?= $statusStyles ?>" 
                     data-status="<?= $liveStatus ?>" 
                     data-area="<?= h($slot['area_name']) ?>">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="font-mono font-bold text-sm <?= $liveStatus === 'occupied' ? 'text-white' : 'text-slate-900' ?>">
                            <?= h($slot['slot_code']) ?>
                        </span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide <?= $pillStyles ?>">
                            <?= ucfirst($liveStatus) ?>
                        </span>
                    </div>
                    <p class="text-[11px] truncate <?= $liveStatus === 'occupied' ? 'text-slate-300' : 'text-slate-500' ?>">
                        <?= h($slot['area_name']) ?>
                    </p>

                    <?php if ($liveStatus === 'occupied' && !empty($slot['active_plate'])): ?>
                    <div class="mt-2 pt-2 border-t border-white/10 flex items-center justify-between text-[11px]">
                        <span class="font-mono font-bold text-emerald-300"><?= h($slot['active_plate']) ?></span>
                        <span class="text-[10px] text-slate-300 truncate max-w-[70px]"><?= h($slot['active_occupant_name'] ?? 'Inside') ?></span>
                    </div>
                    <?php elseif ($liveStatus === 'maintenance'): ?>
                    <div class="mt-2 pt-2 border-t border-amber-200/60 text-[10px] text-amber-700 font-medium">
                        Service in progress
                    </div>
                    <?php elseif ($liveStatus === 'reserved'): ?>
                    <div class="mt-2 pt-2 border-t border-blue-200/60 text-[10px] text-blue-700 font-medium">
                        Permit assigned
                    </div>
                    <?php else: ?>
                    <div class="mt-2 pt-2 border-t border-emerald-200/60 text-[10px] text-emerald-700 font-medium">
                        Open for parking
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div id="no-slots-filtered" class="hidden py-10 text-center text-slate-400 text-sm">
                No parking slots match the selected filters.
            </div>
        </div>
    </div>

    <!-- Recent Entry / Exit Activity Table & Stream -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Real-Time Log</p>
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                </div>
                <h2 class="text-lg font-bold text-slate-900 mt-0.5">Recent Entry & Exit Activity</h2>
                <p class="text-xs text-slate-500 mt-0.5">Chronological record of vehicle arrivals and departures.</p>
            </div>

            <!-- Activity Filters & Search -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex rounded-xl p-1 bg-slate-100 border border-slate-200 text-xs font-semibold">
                    <button type="button" class="activity-filter-btn px-3 py-1 rounded-lg bg-white text-slate-900 shadow-sm transition" data-filter="all">All Events</button>
                    <button type="button" class="activity-filter-btn px-3 py-1 rounded-lg text-slate-600 hover:text-slate-900 transition" data-filter="ENTRY">Entries (In)</button>
                    <button type="button" class="activity-filter-btn px-3 py-1 rounded-lg text-slate-600 hover:text-slate-900 transition" data-filter="EXIT">Exits (Out)</button>
                </div>

                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-2.5 text-xs text-slate-400"></i>
                    <input id="activity-search-input" type="text" placeholder="Search plate or driver..." class="pl-8 pr-3 py-1.5 rounded-xl border border-slate-200 bg-slate-50 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-300 w-44 sm:w-56">
                </div>
            </div>
        </div>

        <!-- Activity Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[650px]">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-3.5">Action</th>
                        <th class="px-6 py-3.5">Plate Number</th>
                        <th class="px-6 py-3.5">Driver / Visitor</th>
                        <th class="px-6 py-3.5">Assigned Slot</th>
                        <th class="px-6 py-3.5">Event Timestamp</th>
                        <th class="px-6 py-3.5">Duration</th>
                        <th class="px-6 py-3.5 text-right">Status</th>
                    </tr>
                </thead>
                <tbody id="activity-tbody" class="divide-y divide-slate-100">
                    <?php if (!$recentLogs): ?>
                    <tr id="empty-activity-row">
                        <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-100 text-slate-400 mb-3">
                                <i class="fas fa-clock-rotate-left text-lg"></i>
                            </div>
                            <p class="font-semibold text-slate-700">No entry or exit activity recorded yet.</p>
                            <p class="text-xs text-slate-400 mt-1">Vehicle entries processed by security staff will appear here live.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($recentLogs as $log): 
                        $isEntry = $log['activity_type'] === 'ENTRY';
                        $mins = (int)$log['duration_minutes'];
                        $hrs = intdiv($mins, 60);
                        $remMins = $mins % 60;
                        $durationFormatted = $hrs > 0 ? "{$hrs}h {$remMins}m" : "{$remMins}m";
                    ?>
                    <tr class="activity-row hover:bg-slate-50/60 transition" 
                        data-action="<?= $log['activity_type'] ?>" 
                        data-search="<?= strtolower(h($log['plate_number'] . ' ' . $log['driver_name'] . ' ' . $log['slot_code'])) ?>">
                        <td class="px-6 py-4">
                            <?php if ($isEntry): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <i class="fas fa-arrow-right-to-bracket text-[10px]"></i>
                                ENTRY
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                <i class="fas fa-arrow-right-from-bracket text-[10px]"></i>
                                EXIT
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-mono font-bold text-slate-900"><?= h($log['plate_number']) ?></span>
                            <div class="text-xs text-slate-400"><?= h($log['vehicle_desc']) ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-semibold text-slate-800"><?= h($log['driver_name']) ?></span>
                            <span class="ml-1 text-[11px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 font-medium"><?= h($log['driver_type']) ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-mono font-semibold text-slate-800"><?= h($log['slot_code'] ?: '—') ?></span>
                            <div class="text-xs text-slate-400"><?= h($log['area_name'] ?: 'General') ?></div>
                        </td>
                        <td class="px-6 py-4 text-slate-600 font-mono text-xs">
                            <?= date('M d, Y · h:i A', strtotime((string)$log['event_time'])) ?>
                        </td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-500">
                            <?= $durationFormatted ?>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <?php if ($log['status'] === 'inside'): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                Inside
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200">
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
    </div>

</div>

<!-- Client-side Telemetry Script -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    let countdownSecs = 10;
    let isPaused = false;
    let countdownTimer = null;
    let currentFilterStatus = 'all';
    let currentFilterArea = 'all';
    let currentActivityFilter = 'all';

    const countdownEl = document.getElementById('refresh-countdown');
    const statusTextEl = document.getElementById('refresh-status-text');
    const toggleBtn = document.getElementById('toggle-refresh-btn');
    const toggleIcon = document.getElementById('toggle-refresh-icon');
    const manualBtn = document.getElementById('manual-refresh-btn');
    const manualIcon = document.getElementById('manual-refresh-icon');
    const serverClockEl = document.getElementById('live-server-clock');

    // 1. Fetch Latest Telemetry from API
    async function fetchTelemetry() {
        if (manualIcon) manualIcon.classList.add('fa-spin');
        try {
            const res = await fetch('/parking/api/live_occupancy.php?_=' + Date.now());
            if (!res.ok) throw new Error('Network response not ok: ' + res.status);
            const data = await res.json();
            if (data.success) {
                updateDashboardUI(data);
            }
        } catch (err) {
            console.error('[LiveTelemetry] Error fetching live occupancy:', err);
        } finally {
            if (manualIcon) manualIcon.classList.remove('fa-spin');
        }
    }

    // 2. Update UI DOM Elements
    function updateDashboardUI(data) {
        const stats = data.stats;
        
        // Update top 5 cards
        animateNumber('stat-total', stats.total_slots);
        animateNumber('stat-occupied', stats.occupied_slots);
        animateNumber('stat-available', stats.available_slots);
        animateNumber('stat-reserved', stats.reserved_slots);
        animateNumber('stat-maintenance', stats.maintenance_slots);

        // Update occupancy percentage and ratios
        const pctEl = document.getElementById('stat-pct');
        if (pctEl) pctEl.textContent = stats.occupancy_percentage.toFixed(1);

        const occRatio = document.getElementById('stat-occ-ratio');
        if (occRatio) occRatio.textContent = stats.occupied_slots;

        const totRatio = document.getElementById('stat-tot-ratio');
        if (totRatio) totRatio.textContent = stats.total_slots;

        // Progress bar segments
        const tot = Math.max(1, stats.total_slots);
        const occPct = Math.min(100, stats.occupancy_percentage);
        const resPct = ((stats.reserved_slots / tot) * 100).toFixed(1);
        const mntPct = ((stats.maintenance_slots / tot) * 100).toFixed(1);
        const avlPct = ((stats.available_slots / tot) * 100).toFixed(1);

        setBarWidth('bar-occupied', occPct + '%');
        setBarWidth('bar-reserved', resPct + '%');
        setBarWidth('bar-maintenance', mntPct + '%');
        setBarWidth('bar-available', avlPct + '%');

        // Legend values
        setText('legend-occ', stats.occupied_slots);
        setText('legend-occ-pct', occPct + '%');
        setText('legend-avl', stats.available_slots);
        setText('legend-avl-pct', avlPct + '%');
        setText('legend-res', stats.reserved_slots);
        setText('legend-res-pct', resPct + '%');
        setText('legend-mnt', stats.maintenance_slots);
        setText('legend-mnt-pct', mntPct + '%');

        // Badge Status Label and Colors
        const badgeLabel = document.getElementById('badge-status-label');
        if (badgeLabel) badgeLabel.textContent = stats.status_label;

        const badgeDot = document.getElementById('badge-status-dot');
        const badgeContainer = document.getElementById('badge-status-container');
        if (badgeContainer && badgeDot) {
            badgeContainer.className = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border ';
            if (stats.status_theme === 'red') {
                badgeContainer.className += 'text-red-700 bg-red-50 border-red-200';
                badgeDot.className = 'w-1.5 h-1.5 rounded-full bg-red-500';
            } else if (stats.status_theme === 'amber') {
                badgeContainer.className += 'text-amber-700 bg-amber-50 border-amber-200';
                badgeDot.className = 'w-1.5 h-1.5 rounded-full bg-amber-500';
            } else if (stats.status_theme === 'blue') {
                badgeContainer.className += 'text-blue-700 bg-blue-50 border-blue-200';
                badgeDot.className = 'w-1.5 h-1.5 rounded-full bg-blue-500';
            } else {
                badgeContainer.className += 'text-emerald-700 bg-emerald-50 border-emerald-200';
                badgeDot.className = 'w-1.5 h-1.5 rounded-full bg-emerald-500';
            }
        }

        // Update Areas Breakdown
        if (data.areas && data.areas.length) {
            const areaContainer = document.getElementById('area-breakdown-container');
            if (areaContainer) {
                areaContainer.innerHTML = data.areas.map(a => {
                    const colorClass = a.occupancy_percentage >= 90 ? 'bg-red-500' : (a.occupancy_percentage >= 70 ? 'bg-amber-500' : 'bg-slate-900');
                    return `
                    <div class="p-3.5 rounded-xl border border-slate-100 bg-slate-50/60 hover:bg-slate-50 transition">
                        <div class="flex items-center justify-between text-sm mb-2">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-slate-900">${escapeHtml(a.name)}</span>
                                <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-white border border-slate-200 text-slate-600">${escapeHtml(a.type)}</span>
                            </div>
                            <div class="flex items-center gap-3 text-xs font-mono">
                                <span class="text-emerald-700 font-semibold bg-emerald-50 px-2 py-0.5 rounded">${a.available_slots} free</span>
                                <span class="text-slate-600 font-medium">${a.occupied_slots} / ${a.total_slots} occupied</span>
                                <span class="font-bold text-slate-900 w-12 text-right">${a.occupancy_percentage.toFixed(1)}%</span>
                            </div>
                        </div>
                        <div class="h-2 w-full bg-slate-200/80 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500 ${colorClass}" style="width: ${Math.min(100, a.occupancy_percentage)}%"></div>
                        </div>
                    </div>`;
                }).join('');
            }
        }

        // Update Slot Visualizer Grid
        if (data.slots && data.slots.length) {
            const slotsGrid = document.getElementById('slots-grid');
            if (slotsGrid) {
                slotsGrid.innerHTML = data.slots.map(s => {
                    const isOcc = s.live_status === 'occupied';
                    const isAvl = s.live_status === 'available';
                    const isRes = s.live_status === 'reserved';
                    const isMnt = s.live_status === 'maintenance';

                    let cardClass = 'border-slate-200 bg-slate-50 text-slate-700';
                    let pillClass = 'bg-slate-200 text-slate-800';

                    if (isOcc) {
                        cardClass = 'border-slate-900 bg-slate-900 text-white shadow-sm';
                        pillClass = 'bg-white/20 text-white';
                    } else if (isAvl) {
                        cardClass = 'border-emerald-200 bg-emerald-50/60 text-slate-900 hover:bg-emerald-50';
                        pillClass = 'bg-emerald-100 text-emerald-800';
                    } else if (isRes) {
                        cardClass = 'border-blue-200 bg-blue-50/50 text-slate-900 hover:bg-blue-50';
                        pillClass = 'bg-blue-100 text-blue-800';
                    } else if (isMnt) {
                        cardClass = 'border-amber-200 bg-amber-50/60 text-slate-900 hover:bg-amber-50';
                        pillClass = 'bg-amber-100 text-amber-800';
                    }

                    let footerHtml = '';
                    if (isOcc && s.active_plate) {
                        footerHtml = `<div class="mt-2 pt-2 border-t border-white/10 flex items-center justify-between text-[11px]"><span class="font-mono font-bold text-emerald-300">${escapeHtml(s.active_plate)}</span><span class="text-[10px] text-slate-300 truncate max-w-[70px]">${escapeHtml(s.active_occupant_name || 'Inside')}</span></div>`;
                    } else if (isMnt) {
                        footerHtml = `<div class="mt-2 pt-2 border-t border-amber-200/60 text-[10px] text-amber-700 font-medium">Service in progress</div>`;
                    } else if (isRes) {
                        footerHtml = `<div class="mt-2 pt-2 border-t border-blue-200/60 text-[10px] text-blue-700 font-medium">Permit assigned</div>`;
                    } else {
                        footerHtml = `<div class="mt-2 pt-2 border-t border-emerald-200/60 text-[10px] text-emerald-700 font-medium">Open for parking</div>`;
                    }

                    return `
                    <div class="slot-card p-3 rounded-xl border transition-all duration-200 ${cardClass}" 
                         data-status="${s.live_status}" 
                         data-area="${escapeHtml(s.area_name)}">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="font-mono font-bold text-sm ${isOcc ? 'text-white' : 'text-slate-900'}">${escapeHtml(s.slot_code)}</span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide ${pillClass}">${s.live_status}</span>
                        </div>
                        <p class="text-[11px] truncate ${isOcc ? 'text-slate-300' : 'text-slate-500'}">${escapeHtml(s.area_name)}</p>
                        ${footerHtml}
                    </div>`;
                }).join('');
                applySlotFilters();
            }
        }

        // Update Recent Activity Table
        if (data.recent_activity) {
            const tbody = document.getElementById('activity-tbody');
            if (tbody) {
                if (data.recent_activity.length === 0) {
                    tbody.innerHTML = `
                    <tr id="empty-activity-row">
                        <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-100 text-slate-400 mb-3"><i class="fas fa-clock-rotate-left text-lg"></i></div>
                            <p class="font-semibold text-slate-700">No entry or exit activity recorded yet.</p>
                            <p class="text-xs text-slate-400 mt-1">Vehicle entries processed by security staff will appear here live.</p>
                        </td>
                    </tr>`;
                } else {
                    tbody.innerHTML = data.recent_activity.map(item => {
                        const isEntry = item.activity_type === 'ENTRY';
                        const badgeHtml = isEntry
                            ? `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"><i class="fas fa-arrow-right-to-bracket text-[10px]"></i> ENTRY</span>`
                            : `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200"><i class="fas fa-arrow-right-from-bracket text-[10px]"></i> EXIT</span>`;
                        
                        const statusPill = item.status === 'inside'
                            ? `<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Inside</span>`
                            : `<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200">Exited</span>`;

                        const searchKey = `${item.plate_number} ${item.driver_name} ${item.slot_code}`.toLowerCase();

                        return `
                        <tr class="activity-row hover:bg-slate-50/60 transition" data-action="${item.activity_type}" data-search="${escapeHtml(searchKey)}">
                            <td class="px-6 py-4">${badgeHtml}</td>
                            <td class="px-6 py-4">
                                <span class="font-mono font-bold text-slate-900">${escapeHtml(item.plate_number)}</span>
                                <div class="text-xs text-slate-400">${escapeHtml(item.vehicle_desc)}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-semibold text-slate-800">${escapeHtml(item.driver_name)}</span>
                                <span class="ml-1 text-[11px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 font-medium">${escapeHtml(item.driver_type)}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="font-mono font-semibold text-slate-800">${escapeHtml(item.slot_code)}</span>
                                <div class="text-xs text-slate-400">${escapeHtml(item.area_name)}</div>
                            </td>
                            <td class="px-6 py-4 text-slate-600 font-mono text-xs">${escapeHtml(item.event_time)}</td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-500">${escapeHtml(item.duration_formatted)}</td>
                            <td class="px-6 py-4 text-right">${statusPill}</td>
                        </tr>`;
                    }).join('');
                    applyActivityFilters();
                }
            }
        }

        // Update server clock display
        if (serverClockEl && data.timestamp) {
            const timePart = data.timestamp.split('·')[1];
            if (timePart) serverClockEl.textContent = timePart.trim();
        }
    }

    // Helper functions
    function setText(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }
    function setBarWidth(id, width) {
        const el = document.getElementById(id);
        if (el) el.style.width = width;
    }
    function animateNumber(id, newVal) {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.textContent !== String(newVal)) {
            el.textContent = newVal;
            el.classList.add('scale-110', 'text-emerald-500');
            setTimeout(() => el.classList.remove('scale-110', 'text-emerald-500'), 400);
        }
    }
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
    }

    // 3. Auto-Refresh Loop
    function startTimer() {
        if (countdownTimer) clearInterval(countdownTimer);
        countdownTimer = setInterval(() => {
            if (isPaused) return;
            countdownSecs--;
            if (countdownEl) countdownEl.textContent = countdownSecs;
            if (countdownSecs <= 0) {
                countdownSecs = 10;
                fetchTelemetry();
            }
        }, 1000);
    }

    // Toggle Pause/Play
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            isPaused = !isPaused;
            if (isPaused) {
                if (toggleIcon) toggleIcon.className = 'fas fa-play text-xs text-emerald-600';
                if (statusTextEl) statusTextEl.innerHTML = '<span class="text-amber-600 font-semibold">Paused</span>';
                const ping = document.getElementById('countdown-ping');
                if (ping) ping.classList.remove('animate-ping');
            } else {
                if (toggleIcon) toggleIcon.className = 'fas fa-pause text-xs';
                countdownSecs = 10;
                if (countdownEl) countdownEl.textContent = countdownSecs;
                if (statusTextEl) statusTextEl.innerHTML = 'Updating in <strong id="refresh-countdown">10</strong>s';
                const ping = document.getElementById('countdown-ping');
                if (ping) ping.classList.add('animate-ping');
            }
        });
    }

    // Manual Refresh
    if (manualBtn) {
        manualBtn.addEventListener('click', () => {
            countdownSecs = 10;
            if (countdownEl) countdownEl.textContent = countdownSecs;
            fetchTelemetry();
        });
    }

    // 4. Slot Filter Buttons
    document.querySelectorAll('.slot-filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.slot-filter-btn').forEach(b => {
                b.className = 'slot-filter-btn px-2.5 py-1 rounded-lg text-slate-600 hover:text-slate-900 transition';
            });
            btn.className = 'slot-filter-btn px-2.5 py-1 rounded-lg bg-white text-slate-900 shadow-sm transition';
            currentFilterStatus = btn.dataset.filter;
            applySlotFilters();
        });
    });

    const areaSelect = document.getElementById('slot-area-filter');
    if (areaSelect) {
        areaSelect.addEventListener('change', () => {
            currentFilterArea = areaSelect.value;
            applySlotFilters();
        });
    }

    function applySlotFilters() {
        const cards = document.querySelectorAll('.slot-card');
        let visibleCount = 0;
        cards.forEach(card => {
            const statusMatch = (currentFilterStatus === 'all' || card.dataset.status === currentFilterStatus);
            const areaMatch = (currentFilterArea === 'all' || card.dataset.area === currentFilterArea);
            if (statusMatch && areaMatch) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        const noSlots = document.getElementById('no-slots-filtered');
        if (noSlots) {
            noSlots.classList.toggle('hidden', visibleCount > 0);
        }
    }

    // 5. Activity Table Filtering and Search
    document.querySelectorAll('.activity-filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.activity-filter-btn').forEach(b => {
                b.className = 'activity-filter-btn px-3 py-1 rounded-lg text-slate-600 hover:text-slate-900 transition';
            });
            btn.className = 'activity-filter-btn px-3 py-1 rounded-lg bg-white text-slate-900 shadow-sm transition';
            currentActivityFilter = btn.dataset.filter;
            applyActivityFilters();
        });
    });

    const searchInput = document.getElementById('activity-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            applyActivityFilters();
        });
    }

    function applyActivityFilters() {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const rows = document.querySelectorAll('.activity-row');
        rows.forEach(row => {
            const actionMatch = (currentActivityFilter === 'all' || row.dataset.action === currentActivityFilter);
            const searchMatch = !query || (row.dataset.search && row.dataset.search.includes(query));
            row.style.display = (actionMatch && searchMatch) ? '' : 'none';
        });
    }

    // Kickoff countdown
    startTimer();
});
</script>

<?php
if ($isLoggedIn) {
    include __DIR__ . '/../layouts/dashboard_footer.php';
} else {
?>
    </main>
    <footer class="mt-16 border-t border-slate-200 py-6 text-center text-xs text-slate-400">
        CampusPark Real-Time Parking Telemetry • Updated live from the database
    </footer>
</body>
</html>
<?php
}
?>
