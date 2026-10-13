<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../includes/audit.php';

$pageTitle = 'Settings';
$role = 'Admin';
$activeItem = 'settings';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid or expired form token. Please go back, refresh the page, and try again.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $open = $_POST['parking_open_time'] ?? '06:00';
        $close = $_POST['parking_close_time'] ?? '22:00';
        $months = (int)($_POST['default_permit_months'] ?? 6);
        $timezone = trim($_POST['timezone'] ?? 'Asia/Manila');

        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'Asia/Manila';
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close) || $months < 1 || $months > 24) {
            $error = 'Enter valid parking hours and a permit duration from 1 to 24 months.';
        } else {
            $up = $pdo->prepare('INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach ([
                ['parking_open_time', $open],
                ['parking_close_time', $close],
                ['default_permit_months', (string)$months],
                ['timezone', $timezone]
            ] as $x) {
                $up->execute($x);
            }

            // Sync current runtime timezone immediately
            date_default_timezone_set($timezone);
            try {
                $tzOffset = (new DateTime('now', new DateTimeZone($timezone)))->format('P');
                $pdo->exec("SET time_zone = '{$tzOffset}'");
            } catch (Throwable $e) {}

            audit_log('settings_updated', 'Updated parking rules and system timezone to ' . $timezone . '.');
            $success = 'Settings saved successfully! Timezone set to ' . $timezone . '.';
        }
    }

    if ($action === 'purge_data') {
        $selected = $_POST['purge_items'] ?? [];
        $confirmed = isset($_POST['purge_confirm']) && $_POST['purge_confirm'] === '1';
        $allowed = [
            'vehicles',
            'parking_logs',
            'visitors',
            'permits',
            'violations',
            'student_accounts',
            'security_accounts',
            'reset_slots',
            'audit_logs'
        ];
        $selected = array_values(array_intersect($allowed, is_array($selected) ? $selected : []));

        if (!$confirmed) {
            $error = 'Please check the confirmation box to verify that you understand this action is permanent.';
        } elseif (empty($selected)) {
            $error = 'Please select at least one data category to purge.';
        } else {
            try {
                $pdo->beginTransaction();
                $purgedSummary = [];

                // 1. Parking Logs
                if (in_array('parking_logs', $selected, true)) {
                    $count = (int)$pdo->exec("DELETE FROM parking_logs");
                    $purgedSummary[] = "Parking Logs ($count cleared)";
                }

                // 2. Violations
                if (in_array('violations', $selected, true)) {
                    $count = (int)$pdo->exec("DELETE FROM violations");
                    $purgedSummary[] = "Violations ($count cleared)";
                }

                // 3. Visitors
                if (in_array('visitors', $selected, true)) {
                    $pdo->exec("UPDATE parking_logs SET visitor_id = NULL WHERE visitor_id IS NOT NULL");
                    $pdo->exec("UPDATE violations SET visitor_id = NULL WHERE visitor_id IS NOT NULL");
                    $count = (int)$pdo->exec("DELETE FROM visitors");
                    $purgedSummary[] = "Visitor Records ($count cleared)";
                }

                // 4. Permits
                if (in_array('permits', $selected, true)) {
                    $count = (int)$pdo->exec("DELETE FROM parking_permits");
                    $purgedSummary[] = "Parking Permits ($count cleared)";
                }

                // 5. Registered Vehicles
                if (in_array('vehicles', $selected, true)) {
                    $pdo->exec("DELETE FROM parking_permits");
                    $pdo->exec("UPDATE parking_logs SET vehicle_id = NULL WHERE vehicle_id IS NOT NULL");
                    $pdo->exec("UPDATE violations SET vehicle_id = NULL WHERE vehicle_id IS NOT NULL");
                    $count = (int)$pdo->exec("DELETE FROM vehicles");
                    $purgedSummary[] = "Registered Vehicles ($count cleared)";
                }

                // 6. Student & Faculty Accounts
                if (in_array('student_accounts', $selected, true)) {
                    $stmt = $pdo->query("SELECT id FROM users WHERE role IN ('student','faculty') AND COALESCE(is_super_admin,0) = 0");
                    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($userIds)) {
                        $inIds = implode(',', array_map('intval', $userIds));
                        $pdo->exec("DELETE FROM parking_permits WHERE user_id IN ($inIds)");
                        $pdo->exec("DELETE FROM vehicles WHERE user_id IN ($inIds)");
                        $count = (int)$pdo->exec("DELETE FROM users WHERE id IN ($inIds)");
                        $purgedSummary[] = "Student/Faculty Accounts ($count deleted)";
                    } else {
                        $purgedSummary[] = "Student/Faculty Accounts (0 found)";
                    }
                }

                // 7. Security Staff Accounts
                if (in_array('security_accounts', $selected, true)) {
                    $currentId = current_user_id();
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'security' AND COALESCE(is_super_admin,0) = 0 AND id <> ?");
                    $stmt->execute([$currentId]);
                    $secIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($secIds)) {
                        $inSec = implode(',', array_map('intval', $secIds));
                        $pdo->exec("DELETE FROM parking_permits WHERE user_id IN ($inSec)");
                        $count = (int)$pdo->exec("DELETE FROM users WHERE id IN ($inSec)");
                        $purgedSummary[] = "Security Accounts ($count deleted)";
                    } else {
                        $purgedSummary[] = "Security Accounts (0 found)";
                    }
                }

                // 8. Reset Slot Statuses
                if (in_array('reset_slots', $selected, true)) {
                    $count = (int)$pdo->exec("UPDATE parking_slots SET status = 'available' WHERE status IN ('reserved', 'maintenance')");
                    $purgedSummary[] = "Parking Slots ($count reset to available)";
                }

                // 9. Audit Logs
                if (in_array('audit_logs', $selected, true)) {
                    $tableCheck = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetch();
                    if ($tableCheck) {
                        $count = (int)$pdo->exec("DELETE FROM audit_logs");
                        $purgedSummary[] = "Audit Logs ($count cleared)";
                    }
                } else {
                    audit_log('database_purged', 'Purged selected datasets: ' . implode(', ', $purgedSummary));
                }

                $pdo->commit();
                $success = 'Data purge completed successfully: ' . implode(' · ', $purgedSummary);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Purge failed: ' . $e->getMessage();
            }
        }
    }
}

$settings = [];
foreach ($pdo->query('SELECT setting_key,setting_value FROM system_settings') as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

// Live record counts for the purge tool modal
$dataCounts = [
    'vehicles' => (int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn(),
    'parking_logs' => (int)$pdo->query("SELECT COUNT(*) FROM parking_logs")->fetchColumn(),
    'visitors' => (int)$pdo->query("SELECT COUNT(*) FROM visitors")->fetchColumn(),
    'permits' => (int)$pdo->query("SELECT COUNT(*) FROM parking_permits")->fetchColumn(),
    'violations' => (int)$pdo->query("SELECT COUNT(*) FROM violations")->fetchColumn(),
    'student_accounts' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('student','faculty')")->fetchColumn(),
    'security_accounts' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'security'")->fetchColumn(),
    'occupied_slots' => (int)$pdo->query("SELECT COUNT(*) FROM parking_slots WHERE status IN ('reserved','maintenance')")->fetchColumn(),
    'audit_logs' => (function() use ($pdo) {
        $c = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetch();
        return $c ? (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn() : 0;
    })(),
];

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-5xl mx-auto w-full">
    <div class="mb-8">
        <p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Administration</p>
        <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">System Settings</h1>
        <p class="mt-1.5 text-xs sm:text-sm text-slate-500">Configure campus parking operational hours, default permit durations, and general system rules.</p>
    </div>

    <?php if ($error): ?><div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 flex items-start gap-3"><i class="fas fa-circle-exclamation text-rose-500 mt-0.5"></i><span><?= h($error) ?></span></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 flex items-start gap-3"><i class="fas fa-circle-check text-emerald-500 mt-0.5"></i><span><?= h($success) ?></span></div><?php endif; ?>

    <!-- Operational Settings Form -->
    <form method="post" class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm space-y-6"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_settings">
        <div><h2 class="text-lg font-bold text-slate-900">Parking Rules & Operational Hours</h2><p class="text-xs sm:text-sm text-slate-500 mt-0.5">These values define the campus parking gate hours and default validity duration for approved permits.</p></div>
        <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Opening time</label><input type="time" name="parking_open_time" value="<?= h($settings['parking_open_time'] ?? '06:00') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Closing time</label><input type="time" name="parking_close_time" value="<?= h($settings['parking_close_time'] ?? '22:00') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div><label class="mb-2 block text-xs font-bold uppercase text-slate-500">Default permit months</label><input type="number" min="1" max="24" name="default_permit_months" value="<?= h($settings['default_permit_months'] ?? '6') ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></div>
            <div>
                <label class="mb-2 block text-xs font-bold uppercase text-slate-500">System Timezone</label>
                <select name="timezone" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
                    <?php
                    $currentTimezone = $settings['timezone'] ?? date_default_timezone_get();
                    $timezones = [
                        'Asia/Manila' => 'Asia/Manila (PST / GMT+8 - Philippines)',
                        'Asia/Singapore' => 'Asia/Singapore (SGT / GMT+8)',
                        'Asia/Tokyo' => 'Asia/Tokyo (JST / GMT+9)',
                        'Asia/Hong_Kong' => 'Asia/Hong_Kong (HKT / GMT+8)',
                        'Asia/Bangkok' => 'Asia/Bangkok (ICT / GMT+7)',
                        'Asia/Dubai' => 'Asia/Dubai (GST / GMT+4)',
                        'UTC' => 'UTC (Coordinated Universal Time)',
                        'Europe/London' => 'Europe/London (GMT/BST)',
                        'Europe/Berlin' => 'Europe/Berlin (CET/CEST)',
                        'Europe/Paris' => 'Europe/Paris (CET/CEST)',
                        'America/New_York' => 'America/New_York (EST/EDT)',
                        'America/Chicago' => 'America/Chicago (CST/CDT)',
                        'America/Los_Angeles' => 'America/Los_Angeles (PST/PDT)',
                        'Australia/Sydney' => 'Australia/Sydney (AEST/AEDT)',
                    ];
                    if (!array_key_exists($currentTimezone, $timezones)) {
                        $timezones[$currentTimezone] = $currentTimezone;
                    }
                    ?>
                    <?php foreach ($timezones as $tzKey => $tzLabel): ?>
                        <option value="<?= h($tzKey) ?>" <?= $currentTimezone === $tzKey ? 'selected' : '' ?>><?= h($tzLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95 w-full sm:w-auto">Save settings</button>
    </form>

    <!-- System Information Card -->
    <section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-100 bg-slate-50/60 p-5 sm:p-6">
            <p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Environment</p>
            <h2 class="mt-1 text-xl font-bold text-slate-900">System Information</h2>
            <p class="mt-1.5 text-xs sm:text-sm text-slate-500">Current application environment and operational status.</p>
        </div>
        <div class="p-5 sm:p-6 grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 p-4">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Application</span>
                <p class="mt-1.5 text-sm font-semibold text-slate-900">Campus Parking</p>
                <p class="text-xs text-slate-500">Production Build</p>
            </div>
            <div class="rounded-xl border border-slate-200 p-4">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Database Engine</span>
                <p class="mt-1.5 text-sm font-semibold text-emerald-600 flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> MySQL / MariaDB</p>
                <p class="text-xs text-slate-500">Status: Active</p>
            </div>
            <div class="rounded-xl border border-slate-200 p-4">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Active Timezone</span>
                <p class="mt-1.5 text-sm font-semibold text-emerald-700 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span> <?= date_default_timezone_get() ?>
                </p>
                <p class="text-xs font-mono text-slate-500"><?= date('Y-m-d h:i A') ?></p>
            </div>
            <div class="rounded-xl border border-slate-200 p-4">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">PHP Runtime</span>
                <p class="mt-1.5 text-sm font-semibold text-slate-900">PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?></p>
                <p class="text-xs text-slate-500">OPcache / PDO Enabled</p>
            </div>
        </div>
    </section>

    <!-- Data Management & Purge Launcher Card -->
    <section class="mt-8 rounded-2xl border border-rose-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">
            <div>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-rose-600">Advanced Database Control</p>
                </div>
                <h2 class="mt-1.5 text-lg sm:text-xl font-bold text-slate-900">Data Management & Purge Tool</h2>
                <p class="mt-1 text-xs sm:text-sm text-slate-500 max-w-xl">
                    Need to remove test data, purge old parking logs, wipe registered vehicles, or reset slot allocations? Launch the selective data management tool.
                </p>
            </div>
            <div class="shrink-0">
                <button type="button" id="open-purge-modal-btn" class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 transition active:scale-95 w-full sm:w-auto">
                    <i class="fas fa-trash-can text-xs"></i> Open Data Management Tool
                </button>
            </div>
        </div>
    </section>
</div>

<!-- ========================================================= -->
<!-- DATA PURGE POPUP MODAL WINDOW                             -->
<!-- ========================================================= -->
<div id="purge-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/60 backdrop-blur-xs transition-opacity duration-200 hidden opacity-0" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div id="purge-modal-dialog" class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-2xl max-h-[92vh] flex flex-col transform transition-all duration-200 scale-95 overflow-hidden">
        
        <!-- Modal Header -->
        <div class="p-5 sm:p-6 border-b border-slate-100 flex items-start justify-between bg-slate-50/80 shrink-0">
            <div class="flex items-start gap-3.5">
                <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0 mt-0.5">
                    <i class="fas fa-database text-lg"></i>
                </div>
                <div>
                    <h3 id="modal-title" class="text-lg font-bold text-slate-900">Selective Data Purge & Reset</h3>
                    <p class="text-xs sm:text-sm text-slate-500 mt-0.5">Select exactly which data categories you want to delete. Protected Super Admin accounts and parking area layouts are preserved.</p>
                </div>
            </div>
            <button type="button" id="close-purge-modal-btn" class="p-2 -mr-2 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition" aria-label="Close dialog">
                <i class="fas fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- Selection Toolbar -->
        <div class="px-5 sm:px-6 py-2.5 bg-slate-100/70 border-b border-slate-200/80 flex items-center justify-between text-xs shrink-0">
            <span class="text-slate-600 font-semibold" id="selected-count-label">0 of 9 categories selected</span>
            <div class="flex items-center gap-2">
                <button type="button" id="select-all-btn" class="text-blue-600 hover:text-blue-800 font-semibold px-2 py-1 rounded hover:bg-white transition">Select All</button>
                <span class="text-slate-300">|</span>
                <button type="button" id="clear-all-btn" class="text-slate-500 hover:text-slate-800 font-semibold px-2 py-1 rounded hover:bg-white transition">Clear All</button>
            </div>
        </div>

        <!-- Modal Form with Scrollable Body -->
        <form method="post" id="purge-form" class="flex flex-col flex-1 overflow-hidden" onsubmit="return handlePurgeSubmit(this);">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="purge_data">

            <div class="p-5 sm:p-6 overflow-y-auto space-y-3 flex-1 custom-scrollbar">
                
                <!-- 1. Registered Vehicles -->
                <label class="purge-item-card flex items-start gap-3.5 rounded-xl border border-slate-200 p-4 hover:border-slate-300 hover:bg-slate-50/70 transition cursor-pointer select-none">
                    <input type="checkbox" name="purge_items[]" value="vehicles" class="purge-item-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="fas fa-car text-xs text-blue-500"></i> Registered Vehicles
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono font-bold <?= $dataCounts['vehicles'] > 0 ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $dataCounts['vehicles'] ?> vehicle<?= $dataCounts['vehicles'] === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Deletes registered vehicles, plate numbers, make/model specs, and vehicle photos.</p>
                    </div>
                </label>

                <!-- 2. Parking Logs & Recent Activity -->
                <label class="purge-item-card flex items-start gap-3.5 rounded-xl border border-slate-200 p-4 hover:border-slate-300 hover:bg-slate-50/70 transition cursor-pointer select-none">
                    <input type="checkbox" name="purge_items[]" value="parking_logs" class="purge-item-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="fas fa-clock-rotate-left text-xs text-indigo-500"></i> Parking Logs & Gate Activity
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono font-bold <?= $dataCounts['parking_logs'] > 0 ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $dataCounts['parking_logs'] ?> log<?= $dataCounts['parking_logs'] === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Clears vehicle entry/exit history, active parking sessions, and recent gate scan logs.</p>
                    </div>
                </label>

                <!-- 3. Visitor Records -->
                <label class="purge-item-card flex items-start gap-3.5 rounded-xl border border-slate-200 p-4 hover:border-slate-300 hover:bg-slate-50/70 transition cursor-pointer select-none">
                    <input type="checkbox" name="purge_items[]" value="visitors" class="purge-item-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="fas fa-user-group text-xs text-amber-500"></i> Visitor Records
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono font-bold <?= $dataCounts['visitors'] > 0 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $dataCounts['visitors'] ?> visitor<?= $dataCounts['visitors'] === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Deletes visitor passes, contact records, visit purposes, and visitor entry/exit records.</p>
                    </div>
                </label>

                <!-- 4. Parking Permits -->
                <label class="purge-item-card flex items-start gap-3.5 rounded-xl border border-slate-200 p-4 hover:border-slate-300 hover:bg-slate-50/70 transition cursor-pointer select-none">
                    <input type="checkbox" name="purge_items[]" value="permits" class="purge-item-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="fas fa-id-card text-xs text-emerald-500"></i> Parking Permits & QR Passes
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono font-bold <?= $dataCounts['permits'] > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $dataCounts['permits'] ?> permit<?= $dataCounts['permits'] === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Deletes all permit applications, digital passes, QR tokens, and slot assignments.</p>
                    </div>
                </label>

                <!-- 5. Violations -->
                <label class="purge-item-card flex items-start gap-3.5 rounded-xl border border-slate-200 p-4 hover:border-slate-300 hover:bg-slate-50/70 transition cursor-pointer select-none">
                    <input type="checkbox" name="purge_items[]" value="violations" class="purge-item-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="fas fa-triangle-exclamation text-xs text-rose-500"></i> Violations & Citations
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono font-bold <?= $dataCounts['violations'] > 0 ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $dataCounts['violations'] ?> violation<?= $dataCounts['violations'] === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Clears recorded parking violations, ticket descriptions, and resolution history.</p>
                    </div>
                </label>

                <!-- 6. Student & Faculty Accounts -->
                <label class="purge-item-card flex items-start gap-3.5 rounded-xl border border-slate-200 p-4 hover:border-slate-300 hover:bg-slate-50/70 transition cursor-pointer select-none">
                    <input type="checkbox" name="purge_items[]" value="student_accounts" class="purge-item-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="fas fa-graduation-cap text-xs text-cyan-600"></i> Student & Faculty Accounts
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono font-bold <?= $dataCounts['student_accounts'] > 0 ? 'bg-cyan-50 text-cyan-700' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $dataCounts['student_accounts'] ?> account<?= $dataCounts['student_accounts'] === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Deletes Student and Faculty user logins, including their owned vehicles and permits.</p>
                    </div>
                </label>

                <!-- 7. Security Staff Accounts -->
                <label class="purge-item-card flex items-start gap-3.5 rounded-xl border border-slate-200 p-4 hover:border-slate-300 hover:bg-slate-50/70 transition cursor-pointer select-none">
                    <input type="checkbox" name="purge_items[]" value="security_accounts" class="purge-item-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="fas fa-shield-halved text-xs text-purple-600"></i> Security Staff Accounts
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono font-bold <?= $dataCounts['security_accounts'] > 0 ? 'bg-purple-50 text-purple-700' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $dataCounts['security_accounts'] ?> account<?= $dataCounts['security_accounts'] === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Deletes Security guard logins (your active Administrator login remains preserved).</p>
                    </div>
                </label>

                <!-- 8. Reset Parking Slot Status -->
                <label class="purge-item-card flex items-start gap-3.5 rounded-xl border border-slate-200 p-4 hover:border-slate-300 hover:bg-slate-50/70 transition cursor-pointer select-none">
                    <input type="checkbox" name="purge_items[]" value="reset_slots" class="purge-item-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="fas fa-square-parking text-xs text-emerald-600"></i> Reset Slot Status to Available
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono font-bold <?= $dataCounts['occupied_slots'] > 0 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $dataCounts['occupied_slots'] ?> reserved / maintenance
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Resets reserved and maintenance slots back to 'available' without removing the slots themselves.</p>
                    </div>
                </label>

                <!-- 9. Audit Logs -->
                <label class="purge-item-card flex items-start gap-3.5 rounded-xl border border-slate-200 p-4 hover:border-slate-300 hover:bg-slate-50/70 transition cursor-pointer select-none">
                    <input type="checkbox" name="purge_items[]" value="audit_logs" class="purge-item-checkbox mt-1 h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="fas fa-clipboard-list text-xs text-slate-500"></i> Audit Trail & Activity Logs
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono font-bold <?= $dataCounts['audit_logs'] > 0 ? 'bg-slate-200 text-slate-700' : 'bg-slate-100 text-slate-500' ?>">
                                <?= $dataCounts['audit_logs'] ?> event<?= $dataCounts['audit_logs'] === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Clears administrative and security action logs and system activity trails.</p>
                    </div>
                </label>
            </div>

            <!-- Modal Footer with Confirmation and Actions -->
            <div class="p-5 sm:p-6 border-t border-slate-100 bg-slate-50/80 shrink-0 space-y-4">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-3.5">
                    <label class="flex items-start gap-3 cursor-pointer select-none">
                        <input type="checkbox" name="purge_confirm" value="1" id="purge_confirm" class="mt-0.5 h-4 w-4 rounded border-amber-300 text-rose-600 focus:ring-rose-500">
                        <span class="text-xs sm:text-sm text-amber-900 leading-snug">
                            <strong>I understand that the selected data will be permanently and irreversibly deleted.</strong>
                            <span class="block text-[11px] text-amber-700 mt-0.5">Parking area definitions and Super Admin accounts are protected and will remain untouched.</span>
                        </span>
                    </label>
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2.5">
                    <button type="button" id="cancel-purge-btn" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition w-full sm:w-auto">
                        Cancel
                    </button>
                    <button type="submit" id="submit-purge-btn" class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 transition active:scale-95 w-full sm:w-auto">
                        <i class="fas fa-trash-can text-xs"></i> Purge Selected Records
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('purge-modal');
    const dialog = document.getElementById('purge-modal-dialog');
    const openBtn = document.getElementById('open-purge-modal-btn');
    const closeBtn = document.getElementById('close-purge-modal-btn');
    const cancelBtn = document.getElementById('cancel-purge-btn');
    const selectAllBtn = document.getElementById('select-all-btn');
    const clearAllBtn = document.getElementById('clear-all-btn');
    const checkboxes = document.querySelectorAll('.purge-item-checkbox');
    const countLabel = document.getElementById('selected-count-label');

    function updateCount() {
        const checked = document.querySelectorAll('.purge-item-checkbox:checked').length;
        if (countLabel) {
            countLabel.textContent = checked + ' of ' + checkboxes.length + ' categories selected';
        }
    }

    function openModal() {
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        requestAnimationFrame(() => {
            modal.classList.remove('opacity-0');
            if (dialog) {
                dialog.classList.remove('scale-95');
                dialog.classList.add('scale-100');
            }
        });
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.add('opacity-0');
        if (dialog) {
            dialog.classList.remove('scale-100');
            dialog.classList.add('scale-95');
        }
        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }, 200);
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    // Close on backdrop click
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
    }

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });

    // Select All / Clear All
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            checkboxes.forEach(cb => cb.checked = true);
            updateCount();
        });
    }

    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', function() {
            checkboxes.forEach(cb => cb.checked = false);
            updateCount();
        });
    }

    checkboxes.forEach(cb => cb.addEventListener('change', updateCount));
    updateCount();
});

function handlePurgeSubmit(form) {
    const checked = form.querySelectorAll('.purge-item-checkbox:checked').length;
    const confirmed = form.querySelector('#purge_confirm:checked');
    if (checked === 0) {
        alert('Please select at least one data category to delete.');
        return false;
    }
    if (!confirmed) {
        alert('Please check the confirmation box acknowledging that this data will be permanently deleted.');
        return false;
    }
    return confirm('WARNING: Are you sure you want to permanently delete the ' + checked + ' selected data category(ies)? This action is irreversible and cannot be undone.');
}
</script>

<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
