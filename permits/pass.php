<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';

$permitId = (int)($_GET['id'] ?? 0);
$token = strtolower(trim((string)($_GET['token'] ?? '')));
$autoPrint = isset($_GET['print']);

// Security & Authentication resolution
$permit = null;

if ($permitId > 0) {
    // Authenticated access by permit ID
    require_login();
    $currentUserId = current_user_id();
    $currentRole = current_role();

    $stmt = $pdo->prepare("
        SELECT pp.*, 
               u.fullname, u.username, u.role AS user_role,
               v.plate_number, v.vehicle_type, v.make, v.model, v.color, v.vehicle_year, v.status AS vehicle_status,
               ps.slot_code, ps.status AS slot_status,
               pa.area_name, pa.area_type
        FROM parking_permits pp
        JOIN users u ON u.id = pp.user_id
        JOIN vehicles v ON v.id = pp.vehicle_id
        LEFT JOIN parking_slots ps ON ps.id = pp.parking_slot_id
        LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
        WHERE pp.id = ?
        LIMIT 1
    ");
    $stmt->execute([$permitId]);
    $permit = $stmt->fetch();

    if ($permit) {
        // Enforce ownership unless admin or security staff
        if ($currentRole !== 'admin' && $currentRole !== 'security' && (int)$permit['user_id'] !== $currentUserId) {
            http_response_code(403);
            exit('Access denied. You do not have permission to view this parking permit pass.');
        }
    }
} elseif ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    // Unpredictable cryptographic token access (for mobile presentation or QR link)
    $stmt = $pdo->prepare("
        SELECT pp.*, 
               u.fullname, u.username, u.role AS user_role,
               v.plate_number, v.vehicle_type, v.make, v.model, v.color, v.vehicle_year, v.status AS vehicle_status,
               ps.slot_code, ps.status AS slot_status,
               pa.area_name, pa.area_type
        FROM parking_permits pp
        JOIN users u ON u.id = pp.user_id
        JOIN vehicles v ON v.id = pp.vehicle_id
        LEFT JOIN parking_slots ps ON ps.id = pp.parking_slot_id
        LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
        WHERE pp.qr_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $permit = $stmt->fetch();
} else {
    // Fallback: If logged in as student or faculty, load their latest approved permit
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("
            SELECT pp.*, 
                   u.fullname, u.username, u.role AS user_role,
                   v.plate_number, v.vehicle_type, v.make, v.model, v.color, v.vehicle_year, v.status AS vehicle_status,
                   ps.slot_code, ps.status AS slot_status,
                   pa.area_name, pa.area_type
            FROM parking_permits pp
            JOIN users u ON u.id = pp.user_id
            JOIN vehicles v ON v.id = pp.vehicle_id
            LEFT JOIN parking_slots ps ON ps.id = pp.parking_slot_id
            LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
            WHERE pp.user_id = ? AND pp.status = 'approved'
            ORDER BY pp.applied_at DESC
            LIMIT 1
        ");
        $stmt->execute([current_user_id()]);
        $permit = $stmt->fetch();
    }
}

if (!$permit) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Permit Not Found</title><script src="/parking/assets/js/tailwindcss.js"></script></head><body class="bg-slate-50 flex items-center justify-center min-h-screen p-4"><div class="max-w-md w-full bg-white p-8 rounded-2xl border border-slate-200 text-center"><h1 class="text-xl font-bold text-slate-900 mb-2">Permit Not Found</h1><p class="text-sm text-slate-500 mb-6">No approved digital parking permit pass matches the requested identifier or credentials.</p><a href="/parking/permits/index.php" class="px-5 py-2.5 bg-slate-900 text-white rounded-xl text-sm font-semibold hover:bg-slate-800 transition">Return to Permits</a></div></body></html>';
    exit;
}

$isApproved = $permit['status'] === 'approved';
$isExpired = !empty($permit['expires_at']) && $permit['expires_at'] < date('Y-m-d');
$isActive = $isApproved && !$isExpired && ($permit['vehicle_status'] ?? 'active') === 'active';

// Compute verification URL for QR code
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$verifyUrl = $scheme . $host . '/parking/permits/verify.php?token=' . rawurlencode($permit['qr_token'] ?? '');
$passUrl = $scheme . $host . '/parking/permits/pass.php?token=' . rawurlencode($permit['qr_token'] ?? '');

// Expiration time calculations
$expiresTimestamp = !empty($permit['expires_at']) ? strtotime($permit['expires_at']) : null;
$daysRemaining = $expiresTimestamp ? (int)ceil(($expiresTimestamp - time()) / 86400) : null;

$tokenShort = !empty($permit['qr_token']) ? substr($permit['qr_token'], 0, 8) . '...' . substr($permit['qr_token'], -8) : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Parking Pass — <?= htmlspecialchars($permit['permit_number'] ?? 'Permit') ?> — Campus Parking</title>
    <script src="/parking/assets/js/tailwindcss.js"></script>
    <script src="/parking/assets/js/qrcode.min.js"></script>
    <link rel="stylesheet" href="/parking/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/parking/assets/css/fonts.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }

        /* Print formatting */
        @media print {
            body { background: #ffffff !important; padding: 0 !important; margin: 0 !important; }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .digital-screen-card { display: none !important; }
            .print-placard { display: block !important; border: 2px dashed #000000 !important; box-shadow: none !important; page-break-inside: avoid !important; }
            @page {
                size: portrait;
                margin: 10mm;
            }
        }
        @media screen {
            .print-only { display: none; }
        }

        /* Subtle metallic and card shine */
        .card-sheen {
            background: linear-gradient(135deg, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 60%);
        }
        .gold-border {
            border: 1px solid rgba(245, 158, 11, 0.4);
            box-shadow: 0 0 20px -5px rgba(245, 158, 11, 0.2);
        }
        .license-plate {
            background: linear-gradient(180deg, #ffffff 0%, #f1f5f9 100%);
            border: 2px solid #0f172a;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.2), 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col justify-between antialiased selection:bg-amber-400 selection:text-slate-950">

    <!-- Top Action Bar (Hidden during Print) -->
    <header class="no-print w-full border-b border-slate-800 bg-slate-950/80 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="/parking/permits/index.php" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-slate-300 hover:text-white hover:bg-slate-800 transition">
                    <i class="fas fa-arrow-left text-[10px]"></i>
                    Back
                </a>
                <span class="text-slate-700">|</span>
                <div class="flex items-center gap-2">
                    <img src="/parking/img/campus_parking_logo.png" alt="Campus Parking Logo" class="w-6 h-6 object-contain">
                    <span class="text-xs font-bold text-slate-300 hidden sm:inline">Digital Credential</span>
                </div>
            </div>

            <!-- Action Controls -->
            <div class="flex items-center gap-2">
                <!-- Enlarge QR Modal Button -->
                <button type="button" id="zoom-qr-btn" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition shadow-sm">
                    <i class="fas fa-qrcode text-xs"></i>
                    <span class="hidden sm:inline">Zoom QR</span>
                </button>

                <!-- Copy Link Button -->
                <button type="button" id="copy-link-btn" data-url="<?= htmlspecialchars($passUrl) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition shadow-sm">
                    <i class="fas fa-share-nodes text-xs"></i>
                    <span class="hidden sm:inline">Share</span>
                </button>

                <!-- Print Button -->
                <button type="button" onclick="window.print()" class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-xl bg-amber-400 hover:bg-amber-300 text-slate-950 text-xs font-extrabold shadow-sm transition active:scale-95">
                    <i class="fas fa-print text-xs"></i>
                    Print Pass
                </button>
            </div>
        </div>
    </header>

    <!-- Main Presentation Container -->
    <main class="w-full max-w-4xl mx-auto px-4 py-8 sm:py-12 flex-1 flex flex-col items-center justify-center">

        <!-- ======================================================== -->
        <!-- 1. SCREEN VIEW: Premium Digital Wallet Card Pass         -->
        <!-- ======================================================== -->
        <div class="digital-screen-card w-full max-w-lg">
            
            <!-- Pass Card Wrapper -->
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-b from-slate-950 via-slate-900 to-blue-950 text-white shadow-2xl gold-border">
                
                <!-- Card Sheen Reflection -->
                <div class="absolute inset-0 card-sheen pointer-events-none"></div>

                <!-- Card Header -->
                <div class="p-6 sm:p-7 pb-5 border-b border-white/10 relative z-10 flex items-start justify-between">
                    <div class="flex items-center gap-3.5">
                        <img src="/parking/img/campus_parking_logo.png" alt="Campus Parking Logo" class="w-12 h-12 object-contain drop-shadow">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-400">Official Campus Parking System</p>
                            <h1 class="text-lg font-black tracking-tight text-white mt-0.5">Campus Parking Pass</h1>
                            <p class="text-[11px] text-slate-400 font-mono"><?= htmlspecialchars($permit['permit_number'] ?? 'PERMIT') ?></p>
                        </div>
                    </div>

                    <!-- Status Pill -->
                    <div>
                        <?php if ($isActive): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-400/40">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            ACTIVE
                        </span>
                        <?php elseif ($isExpired): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-red-500/20 text-red-300 border border-red-400/40">
                            EXPIRED
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-400/40">
                            <?= htmlspecialchars(strtoupper($permit['status'])) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Core Information Body -->
                <div class="p-6 sm:p-7 space-y-6 relative z-10">

                    <!-- Big Slot & Area Banner -->
                    <div class="rounded-2xl bg-white/5 border border-white/10 p-5 flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Assigned Parking Bay</p>
                            <p class="mt-1 text-3xl sm:text-4xl font-black font-mono tracking-tight text-amber-400">
                                <?= htmlspecialchars($permit['slot_code'] ?? 'Unassigned') ?>
                            </p>
                            <p class="mt-1 text-xs text-slate-300 font-medium">
                                <?= htmlspecialchars($permit['area_name'] ?? 'General Campus Lot') ?>
                                <span class="text-slate-500 font-normal">• <?= ucfirst(htmlspecialchars((string)($permit['area_type'] ?? ''))) ?></span>
                            </p>
                        </div>

                        <div class="w-14 h-14 rounded-2xl bg-amber-400/10 border border-amber-400/30 flex items-center justify-center text-amber-400">
                            <i class="fas fa-square-parking text-2xl"></i>
                        </div>
                    </div>

                    <!-- License Plate Box -->
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Authorized Vehicle Plate</p>
                        <div class="license-plate rounded-xl p-3 flex items-center justify-between text-slate-900">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 tracking-wider">PH</span>
                                <span class="text-2xl font-black font-mono tracking-widest uppercase">
                                    <?= htmlspecialchars($permit['plate_number'] ?? '—') ?>
                                </span>
                            </div>
                            <span class="text-xs font-bold text-slate-600 uppercase tracking-tight">
                                <?= htmlspecialchars(ucfirst((string)($permit['vehicle_type'] ?? 'car'))) ?>
                            </span>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-400">
                            <?= htmlspecialchars(trim(($permit['make'] ?? '') . ' ' . ($permit['model'] ?? '') . ' ' . ($permit['color'] ? '· ' . $permit['color'] : ''))) ?>
                        </p>
                    </div>

                    <!-- Driver & Validity Grid -->
                    <div class="grid grid-cols-2 gap-4 pt-2 border-t border-white/10 text-xs">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Permit Holder</p>
                            <p class="mt-1 font-bold text-white text-sm"><?= htmlspecialchars($permit['fullname'] ?? 'Student') ?></p>
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide"><?= htmlspecialchars($permit['user_role'] ?? 'Member') ?></p>
                        </div>

                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Valid Through</p>
                            <p class="mt-1 font-bold text-white text-sm">
                                <?= !empty($permit['expires_at']) ? htmlspecialchars(date('M d, Y', strtotime($permit['expires_at']))) : 'No Expiry Set' ?>
                            </p>
                            <p class="text-[11px] <?= ($daysRemaining !== null && $daysRemaining <= 14) ? 'text-amber-400' : 'text-emerald-400' ?>">
                                <?= ($daysRemaining !== null && $daysRemaining >= 0) ? "{$daysRemaining} days remaining" : ($isExpired ? 'Expired' : 'Active') ?>
                            </p>
                        </div>
                    </div>

                    <!-- QR Code Centerpiece Section -->
                    <div class="pt-4 border-t border-white/10 flex flex-col items-center justify-center text-center">
                        <div class="p-3.5 bg-white rounded-2xl shadow-lg border border-slate-200 relative group">
                            <!-- Local Canvas QR Generator Target -->
                            <div id="qrcode-container" class="flex justify-center"></div>
                            <!-- Remote Image Fallback (noscript or JS failed) -->
                            <noscript>
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= rawurlencode($verifyUrl) ?>" alt="QR Permit Code" class="w-48 h-48 rounded-lg">
                            </noscript>
                        </div>

                        <p class="mt-3 text-xs font-semibold text-slate-300">Scan for Official Verification</p>
                        <p class="text-[10px] font-mono text-slate-500 mt-0.5 max-w-xs break-all">
                            Token: <?= htmlspecialchars($tokenShort) ?>
                        </p>
                    </div>

                </div>

                <!-- Security Footer Banner -->
                <div class="px-6 py-4 bg-slate-950 border-t border-white/10 flex items-center justify-between text-[11px] text-slate-400">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-shield-halved text-amber-400 text-xs"></i>
                        <span>Server-side verification strictly enforced</span>
                    </div>
                    <span class="font-mono text-[10px] text-slate-500">PARK • SEC-<?= (int)$permit['id'] ?></span>
                </div>

            </div>

            <!-- Presentation Advice note -->
            <p class="no-print mt-4 text-center text-xs text-slate-400 leading-relaxed">
                Present this screen directly to campus security officers at entry gates, or print a dashboard placard below.
            </p>

            <!-- Quick Print Switcher in screen view -->
            <div class="no-print mt-6 flex justify-center gap-3">
                <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold transition border border-slate-700">
                    <i class="fas fa-print"></i>
                    Print Dashboard Placard
                </button>
            </div>

        </div>

        <!-- ======================================================== -->
        <!-- 2. PRINT-FRIENDLY PLACARD (Rearview Mirror / Dashboard)   -->
        <!-- ======================================================== -->
        <div class="print-only print-placard w-full max-w-2xl bg-white text-black p-8 rounded-2xl border-4 border-dashed border-black relative">
            
            <!-- Cut Guide Icon -->
            <div class="absolute -top-3.5 left-8 bg-white px-2 text-xs font-bold text-black uppercase tracking-wider flex items-center gap-1.5">
                <i class="fas fa-scissors"></i>
                Cut along dashed line and display on vehicle dashboard
            </div>

            <!-- Header -->
            <div class="border-b-2 border-black pb-4 mb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <img src="/parking/img/campus_parking_logo.png" alt="Campus Parking Logo" class="w-14 h-14 object-contain">
                    <div>
                        <p class="text-xs font-black uppercase tracking-widest text-black">Official Campus Parking System</p>
                        <h2 class="text-2xl font-black uppercase tracking-tight text-black">Official Parking Placard</h2>
                        <p class="text-xs text-gray-700 font-mono">Permit Ref: <?= htmlspecialchars($permit['permit_number'] ?? '—') ?></p>
                    </div>
                </div>

                <div class="text-right">
                    <span class="inline-block border-2 border-black px-3 py-1 text-sm font-black uppercase">
                        <?= $isActive ? 'VALID PERMIT' : 'EXPIRED' ?>
                    </span>
                    <p class="text-[10px] font-mono mt-1 text-gray-600"><?= date('Y') ?> Academic Term</p>
                </div>
            </div>

            <!-- Massive Bay & Plate Row -->
            <div class="grid grid-cols-2 gap-6 border-b-2 border-black pb-6 mb-6">
                <!-- Designated Slot -->
                <div class="border-2 border-black p-4 text-center rounded-lg bg-gray-50">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-600">Assigned Parking Bay</p>
                    <p class="text-5xl font-black font-mono tracking-tight my-1 text-black">
                        <?= htmlspecialchars($permit['slot_code'] ?? '—') ?>
                    </p>
                    <p class="text-xs font-bold uppercase text-black"><?= htmlspecialchars($permit['area_name'] ?? 'General') ?></p>
                </div>

                <!-- Plate Number -->
                <div class="border-2 border-black p-4 text-center rounded-lg bg-gray-50 flex flex-col justify-between">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-600">Vehicle Plate Number</p>
                    <p class="text-4xl font-black font-mono tracking-widest my-1 text-black uppercase">
                        <?= htmlspecialchars($permit['plate_number'] ?? '—') ?>
                    </p>
                    <p class="text-xs text-gray-700 font-semibold uppercase">
                        <?= htmlspecialchars(trim(($permit['make'] ?? '') . ' ' . ($permit['model'] ?? '') . ' (' . ($permit['color'] ?? '') . ')')) ?>
                    </p>
                </div>
            </div>

            <!-- Details & QR Row -->
            <div class="flex items-center justify-between gap-6 pb-6 border-b-2 border-black">
                <div class="space-y-3 text-sm">
                    <div>
                        <span class="text-xs font-bold uppercase text-gray-600 block">Registered Driver:</span>
                        <span class="text-base font-bold text-black"><?= htmlspecialchars($permit['fullname'] ?? 'User') ?> (<?= ucfirst(htmlspecialchars((string)$permit['user_role'])) ?>)</span>
                    </div>
                    <div>
                        <span class="text-xs font-bold uppercase text-gray-600 block">Placard Expiration Date:</span>
                        <span class="text-base font-bold text-black"><?= !empty($permit['expires_at']) ? htmlspecialchars(date('F d, Y', strtotime($permit['expires_at']))) : 'Permanent' ?></span>
                    </div>
                    <div>
                        <span class="text-xs font-bold uppercase text-gray-600 block">Campus Security Directive:</span>
                        <span class="text-xs text-gray-700">Display face-up on driver-side dashboard or hang from rearview mirror.</span>
                    </div>
                </div>

                <!-- Scannable Print QR -->
                <div class="text-center shrink-0">
                    <div id="print-qrcode-container" class="border border-black p-2 bg-white rounded inline-block"></div>
                    <p class="text-[9px] font-mono text-gray-600 mt-1">Gate Verification QR</p>
                </div>
            </div>

            <!-- Footer Warning -->
            <div class="pt-4 flex items-center justify-between text-[10px] text-gray-600 leading-tight">
                <p>
                    <strong>NOTICE:</strong> This permit is non-transferable and subject to campus parking regulations.
                    Automated and manual gate scanning confirms server authorization.
                </p>
                <div class="text-right font-mono shrink-0 ml-4">
                    <span>TOKEN: <?= htmlspecialchars($tokenShort) ?></span>
                </div>
            </div>

        </div>

    </main>

    <!-- Modal for Full-Screen Zoomed QR Code (Mobile Gate Presentation) -->
    <div id="qr-modal" class="no-print fixed inset-0 z-50 bg-black/90 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl p-6 sm:p-8 max-w-sm w-full text-slate-900 text-center relative shadow-2xl animate-fade-in">
            <button type="button" id="close-modal-btn" class="absolute top-4 right-4 w-9 h-9 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center transition">
                <i class="fas fa-times"></i>
            </button>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Campus Checkpoint</p>
            <h3 class="text-xl font-black text-slate-900 mt-1">Gate Scanner QR</h3>
            <p class="text-xs text-slate-500 mt-1 mb-5">Present this screen directly to the security camera scanner.</p>
            
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 flex justify-center">
                <div id="modal-qrcode-container"></div>
            </div>

            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-mono">
                <span class="font-bold text-slate-900"><?= htmlspecialchars($permit['plate_number'] ?? '') ?></span>
                <span class="text-emerald-600 font-bold"><?= htmlspecialchars($permit['slot_code'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <!-- Toast Notification (Share/Copy Link) -->
    <div id="toast" class="no-print fixed bottom-6 left-1/2 -translate-x-1/2 bg-slate-950 text-white px-4 py-2.5 rounded-xl shadow-xl border border-slate-800 text-xs font-semibold flex items-center gap-2 transition-all duration-300 opacity-0 pointer-events-none z-50">
        <i class="fas fa-check-circle text-emerald-400"></i>
        <span id="toast-msg">Pass link copied to clipboard!</span>
    </div>

    <!-- Client-Side QRCode Rendering & Interactivity -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const verifyUrl = <?= json_encode($verifyUrl) ?>;

        // Render main screen QR Code
        const qrContainer = document.getElementById('qrcode-container');
        if (qrContainer && typeof QRCode !== 'undefined') {
            new QRCode(qrContainer, {
                text: verifyUrl,
                width: 180,
                height: 180,
                colorDark: "#0f172a",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
        }

        // Render print placard QR Code
        const printQrContainer = document.getElementById('print-qrcode-container');
        if (printQrContainer && typeof QRCode !== 'undefined') {
            new QRCode(printQrContainer, {
                text: verifyUrl,
                width: 140,
                height: 140,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
        }

        // Render Zoom Modal QR Code
        const modalQrContainer = document.getElementById('modal-qrcode-container');
        if (modalQrContainer && typeof QRCode !== 'undefined') {
            new QRCode(modalQrContainer, {
                text: verifyUrl,
                width: 240,
                height: 240,
                colorDark: "#0f172a",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
        }

        // Modal Controls
        const zoomBtn = document.getElementById('zoom-qr-btn');
        const modal = document.getElementById('qr-modal');
        const closeModalBtn = document.getElementById('close-modal-btn');

        if (zoomBtn && modal) {
            zoomBtn.addEventListener('click', () => modal.classList.remove('hidden'));
        }
        if (closeModalBtn && modal) {
            closeModalBtn.addEventListener('click', () => modal.classList.add('hidden'));
        }
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.classList.add('hidden');
            });
        }

        // Share / Copy Link
        const copyBtn = document.getElementById('copy-link-btn');
        const toast = document.getElementById('toast');
        if (copyBtn) {
            copyBtn.addEventListener('click', async () => {
                const url = copyBtn.dataset.url || window.location.href;
                try {
                    if (navigator.clipboard) {
                        await navigator.clipboard.writeText(url);
                    } else {
                        const input = document.createElement('input');
                        input.value = url;
                        document.body.appendChild(input);
                        input.select();
                        document.execCommand('copy');
                        document.body.removeChild(input);
                    }
                    showToast('Direct pass link copied to clipboard!');
                } catch (err) {
                    showToast('Sharing link: ' + url);
                }
            });
        }

        function showToast(msg) {
            if (!toast) return;
            const toastMsg = document.getElementById('toast-msg');
            if (toastMsg) toastMsg.textContent = msg;
            toast.classList.remove('opacity-0', 'pointer-events-none');
            setTimeout(() => {
                toast.classList.add('opacity-0', 'pointer-events-none');
            }, 3000);
        }

        <?php if ($autoPrint): ?>
        setTimeout(() => {
            window.print();
        }, 500);
        <?php endif; ?>
    });
    </script>
</body>
</html>
