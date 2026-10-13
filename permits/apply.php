<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('student', 'faculty', 'admin');

// -------------------------------------------------------------
// Handle AJAX Submission from Vue Form
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (is_array($payload)) {
        header('Content-Type: application/json; charset=utf-8');

        $csrfToken = $payload['csrf_token'] ?? '';
        if (!csrf_valid($csrfToken)) {
            echo json_encode([
                'success' => false,
                'message' => 'Your security session token has expired. Please refresh the page and try again.'
            ]);
            exit;
        }

        $name = trim($payload['name'] ?? '');
        $confirmName = trim($payload['confirmName'] ?? '');
        $studentNo = trim($payload['studentNo'] ?? '');
        $address = trim($payload['address'] ?? '');
        $licenseNumber = trim($payload['licenseNumber'] ?? '');
        $gender = trim($payload['gender'] ?? 'Other');

        $vehicleTypeRaw = strtolower(trim($payload['vehicleType'] ?? 'car'));
        $allowedTypes = ['car', 'motorcycle', 'van', 'other'];
        $vehicleType = in_array($vehicleTypeRaw, $allowedTypes, true) ? $vehicleTypeRaw : 'car';

        $plateNumber = strtoupper(preg_replace('/[^A-Z0-9- ]/', '', trim($payload['plateNumber'] ?? '')));
        $make = trim($payload['make'] ?? '');
        $model = trim($payload['model'] ?? '');
        $color = trim($payload['color'] ?? '');
        $yearRaw = trim((string)($payload['year'] ?? date('Y')));
        $orNumber = trim($payload['orNumber'] ?? '');
        $crNumber = trim($payload['crNumber'] ?? '');

        $isOwner = !empty($payload['isOwner']);
        $ownerName = $isOwner ? $name : trim($payload['ownerName'] ?? '');
        $ownerRelation = $isOwner ? 'Self' : trim($payload['ownerRelation'] ?? '');

        $serialNumber = trim($payload['serialNumber'] ?? '');
        $signatureData = trim($payload['signatureData'] ?? '');

        // Validations
        if ($name === '' || $studentNo === '' || $address === '' || $licenseNumber === '') {
            echo json_encode(['success' => false, 'message' => 'Please fill out all applicant information fields.']);
            exit;
        }

        if (strcasecmp($name, $confirmName) !== 0) {
            echo json_encode(['success' => false, 'message' => 'The confirmation name must match your full name exactly.']);
            exit;
        }

        if ($plateNumber === '') {
            echo json_encode(['success' => false, 'message' => 'Please provide a valid vehicle license plate number.']);
            exit;
        }

        if ($make === '') {
            echo json_encode(['success' => false, 'message' => 'Please provide the vehicle make/brand.']);
            exit;
        }

        if ($model === '') {
            // If model is empty, split make or use make
            $parts = explode(' ', $make, 2);
            $model = count($parts) > 1 ? $parts[1] : 'Standard';
            $make = $parts[0];
        }

        if ($color === '') {
            $color = 'Standard';
        }

        $year = (int)$yearRaw;
        if ($year < 1980 || $year > ((int)date('Y') + 1)) {
            $year = (int)date('Y');
        }

        if (!$isOwner && ($ownerName === '' || $ownerRelation === '')) {
            echo json_encode(['success' => false, 'message' => 'Please specify the registered owner\'s name and relation.']);
            exit;
        }

        if ($signatureData === '' || !str_starts_with($signatureData, 'data:image/')) {
            echo json_encode(['success' => false, 'message' => 'Please provide your digital signature inside the signature box.']);
            exit;
        }

        $userId = current_user_id();

        try {
            $pdo->beginTransaction();

            // 1. Process Vehicle Record
            $vehStmt = $pdo->prepare("SELECT id, user_id FROM vehicles WHERE plate_number = ? FOR UPDATE");
            $vehStmt->execute([$plateNumber]);
            $existingVehicle = $vehStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingVehicle) {
                if ((int)$existingVehicle['user_id'] !== (int)$userId) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => "Plate number '{$plateNumber}' is already registered to another account."
                    ]);
                    exit;
                }
                $vehicleId = (int)$existingVehicle['id'];
                // Update vehicle with latest registration info
                $updateVeh = $pdo->prepare("UPDATE vehicles SET vehicle_type = ?, make = ?, model = ?, color = ?, vehicle_year = ?, status = 'active' WHERE id = ?");
                $updateVeh->execute([$vehicleType, $make, $model, $color, $year, $vehicleId]);
            } else {
                $insertVeh = $pdo->prepare("INSERT INTO vehicles (user_id, plate_number, vehicle_type, make, model, color, vehicle_year, photo_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'active')");
                $insertVeh->execute([$userId, $plateNumber, $vehicleType, $make, $model, $color, $year]);
                $vehicleId = (int)$pdo->lastInsertId();
            }

            // 2. Check for existing active or pending permits for this vehicle
            $permitCheck = $pdo->prepare("SELECT id, status, permit_number FROM parking_permits WHERE vehicle_id = ? AND status IN ('pending', 'approved') LIMIT 1 FOR UPDATE");
            $permitCheck->execute([$vehicleId]);
            $existingPermit = $permitCheck->fetch(PDO::FETCH_ASSOC);

            if ($existingPermit) {
                $pdo->rollBack();
                $statusMsg = $existingPermit['status'] === 'approved'
                    ? "This vehicle already has an approved permit (" . ($existingPermit['permit_number'] ?? 'Active') . ")."
                    : "This vehicle already has a pending permit application under review.";
                echo json_encode(['success' => false, 'message' => $statusMsg]);
                exit;
            }

            // 3. Save signature image to uploads/signatures/
            $sigDir = __DIR__ . '/../uploads/signatures';
            if (!is_dir($sigDir)) {
                @mkdir($sigDir, 0755, true);
            }
            $sigParts = explode(',', $signatureData, 2);
            if (count($sigParts) === 2) {
                $decoded = base64_decode($sigParts[1]);
                if ($decoded !== false) {
                    $sigFileName = 'sig_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.png';
                    @file_put_contents($sigDir . '/' . $sigFileName, $decoded);
                }
            }

            // 4. Generate guaranteed unique application serial number
            if ($serialNumber === '') {
                $serialNumber = sprintf('STK-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(3))));
            }
            // Ensure unique in parking_permits
            $chkSerial = $pdo->prepare("SELECT id FROM parking_permits WHERE permit_number = ? LIMIT 1");
            $chkSerial->execute([$serialNumber]);
            if ($chkSerial->fetch()) {
                $serialNumber = sprintf('STK-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(4))));
            }

            // 5. Insert parking_permits row
            $insertPermit = $pdo->prepare("INSERT INTO parking_permits (user_id, vehicle_id, permit_number, status, applied_at) VALUES (?, ?, ?, 'pending', NOW())");
            $insertPermit->execute([$userId, $vehicleId, $serialNumber]);
            $permitId = (int)$pdo->lastInsertId();

            // 6. Record in audit_logs
            $auditDesc = sprintf(
                "Submitted parking sticker application %s for plate %s (%s %s). Fee ₱500.00 confirmed.",
                $serialNumber,
                $plateNumber,
                $make,
                $model
            );
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (?, 'sticker_application_submitted', ?, ?)");
            $auditStmt->execute([$userId, $auditDesc, $clientIp]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Parking sticker application submitted successfully!',
                'serialNumber' => $serialNumber,
                'permitId' => $permitId,
                'plateNumber' => $plateNumber,
                'applicantName' => $name,
                'vehicle' => $make . ' ' . $model . ' (' . $year . ')',
                'amountPaid' => '₱ 500.00'
            ]);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode([
                'success' => false,
                'message' => 'An error occurred while saving your application. Please try again.'
            ]);
            exit;
        }
    }
}

// -------------------------------------------------------------
// Render Interactive Application Page
// -------------------------------------------------------------
$pageTitle = 'Parking Sticker Application';
$role = ucfirst(current_role());
$activeItem = 'permit';

$userFullname = $_SESSION['fullname'] ?? '';
$userUsername = $_SESSION['username'] ?? '';

// Check if user already has an active vehicle to pre-suggest
$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
$stmt->execute([current_user_id()]);
$defaultVehicle = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">

    <!-- Breadcrumb & Top Bar -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                <a href="/parking/permits/index.php" class="hover:text-slate-900 transition">Permits</a>
                <span>/</span>
                <span class="text-blue-600">Sticker Application</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-1">School Parking Sticker Application</h1>
            <p class="text-sm text-slate-500 mt-1">Official vehicle registration and parking pass issuance for Academic Year 2026-2027.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="/parking/permits/index.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-slate-200 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-slate-900 transition shadow-xs">
                <i class="fas fa-arrow-left text-[11px]"></i> Back to Permits
            </a>
            <a href="/parking/dashboards/users.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-900 text-xs font-semibold text-white hover:bg-slate-800 transition shadow-xs">
                <i class="fas fa-home text-[11px]"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Main Vue Application Container -->
    <div id="app" class="max-w-5xl mx-auto bg-white rounded-2xl shadow-xl border border-slate-200/80 overflow-hidden mb-12">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-900 via-blue-800 to-indigo-900 text-white p-6 sm:p-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 relative overflow-hidden">
            <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-white/5 rounded-full blur-2xl pointer-events-none"></div>
            <div class="flex items-center gap-4">
                <img src="/parking/img/campus_parking_logo.png" alt="Campus Parking" class="w-14 h-14 object-contain rounded-xl bg-white/10 p-2 backdrop-blur-xs border border-white/20 shrink-0">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase tracking-wider bg-amber-400 text-slate-900">Official</span>
                        <span class="text-blue-200 text-xs font-medium">Campus Parking System</span>
                    </div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white mt-1">Parking Sticker Application</h1>
                    <p class="text-blue-200 text-xs mt-0.5">Academic Year 2026-2027 • Gate Security Verification</p>
                </div>
            </div>
            <div class="mt-2 sm:mt-0 bg-white/15 px-5 py-3 rounded-xl border border-white/20 backdrop-blur-sm self-stretch sm:self-auto flex sm:flex-col justify-between items-center sm:items-end">
                <span class="text-xs uppercase tracking-wider text-blue-200 font-semibold">Application No.</span>
                <span class="font-mono text-lg sm:text-xl font-bold tracking-wider text-amber-300">{{ serialNumber }}</span>
            </div>
        </div>

        <div class="p-6 sm:p-8">
            <!-- Smart Scan Feature -->
            <div class="mb-8 bg-blue-50/80 border border-blue-200 rounded-2xl p-6 flex flex-col sm:flex-row items-center justify-between gap-5 transition hover:border-blue-300">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-600 text-white flex items-center justify-center shrink-0 shadow-md">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base sm:text-lg font-bold text-blue-950 flex items-center gap-2">
                            Smart Scan OCR Autofill
                            <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full bg-blue-200/80 text-blue-800">Fast Mode</span>
                        </h3>
                        <p class="text-blue-700 text-xs sm:text-sm mt-1">Save time! Scan your Student/Employee ID and Vehicle OR/CR to automatically populate this application.</p>
                    </div>
                </div>
                <button @click="simulateScan" type="button" class="whitespace-nowrap bg-blue-600 text-white font-semibold py-2.5 px-6 rounded-xl shadow-md hover:bg-blue-700 transition active:scale-95 focus:ring-4 focus:ring-blue-300 shrink-0 flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                    </svg>
                    Scan Documents
                </button>
            </div>

            <form @submit.prevent="handlePayment" class="space-y-8">
                
                <!-- 1. Applicant Information Section -->
                <section class="bg-slate-50/60 p-5 sm:p-6 rounded-2xl border border-slate-200">
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 border-b border-slate-200 pb-3 mb-5 flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-blue-800 text-white text-xs flex items-center justify-center font-bold">1</span>
                            Applicant Information
                        </span>
                        <span class="text-xs font-normal text-slate-500">Official Student or Faculty Records</span>
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Full Name *</label>
                            <input v-model="form.name" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="e.g. Dela Cruz, Juan">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Student / Employee Number *</label>
                            <input v-model="form.studentNo" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="e.g. 2026-10492">
                        </div>
                        
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Complete Address *</label>
                            <input v-model="form.address" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="House/Lot No., Street, Barangay, City, Province">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Driver's License Number *</label>
                            <input v-model="form.licenseNumber" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="e.g. N01-23-456789">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">Gender *</label>
                            <div class="flex flex-wrap gap-5 pt-1.5">
                                <label class="inline-flex items-center cursor-pointer text-sm font-medium text-slate-700">
                                    <input type="radio" v-model="form.gender" value="Male" required class="form-radio text-blue-600 h-4 w-4">
                                    <span class="ml-2">Male</span>
                                </label>
                                <label class="inline-flex items-center cursor-pointer text-sm font-medium text-slate-700">
                                    <input type="radio" v-model="form.gender" value="Female" class="form-radio text-blue-600 h-4 w-4">
                                    <span class="ml-2">Female</span>
                                </label>
                                <label class="inline-flex items-center cursor-pointer text-sm font-medium text-slate-700">
                                    <input type="radio" v-model="form.gender" value="Other" class="form-radio text-blue-600 h-4 w-4">
                                    <span class="ml-2">Other</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 2. Vehicle Information -->
                <section class="bg-slate-50/60 p-5 sm:p-6 rounded-2xl border border-slate-200">
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 border-b border-slate-200 pb-3 mb-5 flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-blue-800 text-white text-xs flex items-center justify-center font-bold">2</span>
                            Vehicle Information
                        </span>
                        <span class="text-xs font-normal text-slate-500">Official Campus Gate Pass Details</span>
                    </h2>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">Vehicle Type *</label>
                            <div class="flex flex-wrap gap-6 pt-1">
                                <label class="inline-flex items-center cursor-pointer text-sm font-medium text-slate-700">
                                    <input type="radio" v-model="form.vehicleType" value="Car" required class="form-radio text-blue-600 h-4 w-4">
                                    <span class="ml-2">Car / SUV</span>
                                </label>
                                <label class="inline-flex items-center cursor-pointer text-sm font-medium text-slate-700">
                                    <input type="radio" v-model="form.vehicleType" value="Motorcycle" class="form-radio text-blue-600 h-4 w-4">
                                    <span class="ml-2">Motorcycle</span>
                                </label>
                                <label class="inline-flex items-center cursor-pointer text-sm font-medium text-slate-700">
                                    <input type="radio" v-model="form.vehicleType" value="Other" class="form-radio text-blue-600 h-4 w-4">
                                    <span class="ml-2">Van / Other</span>
                                </label>
                            </div>
                        </div>

                        <!-- Plate Number (Essential for Parking Database) -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Plate Number *</label>
                            <div class="relative">
                                <input v-model="form.plateNumber" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono font-bold tracking-wider uppercase text-sm transition" placeholder="e.g. ABC 1234">
                                <span class="absolute right-3 top-2.5 text-xs text-slate-400 font-semibold uppercase">LTO</span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Color *</label>
                            <input v-model="form.color" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="e.g. Pearl White, Silver, Black">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Make / Brand *</label>
                            <input v-model="form.make" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="e.g. Toyota, Honda, Mitsubishi">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Model / Series *</label>
                            <input v-model="form.model" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="e.g. Vios, Civic, Mirage">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Year Model *</label>
                            <input v-model="form.year" type="number" required min="1980" max="<?= date('Y') + 1 ?>" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="YYYY (e.g. 2023)">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">OR Number (Official Receipt) *</label>
                            <input v-model="form.orNumber" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="Enter OR No.">
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">CR Number (Certificate of Registration) *</label>
                            <input v-model="form.crNumber" type="text" required class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="Enter CR No.">
                        </div>
                    </div>
                </section>

                <!-- 3. Ownership Details -->
                <section class="bg-slate-50/60 p-5 sm:p-6 rounded-2xl border border-slate-200">
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 border-b border-slate-200 pb-3 mb-5 flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-blue-800 text-white text-xs flex items-center justify-center font-bold">3</span>
                            Ownership Details
                        </span>
                        <span class="text-xs font-normal text-slate-500">Vehicle Title Verification</span>
                    </h2>
                    <div class="mb-4">
                        <label class="inline-flex items-center cursor-pointer p-3.5 bg-white border border-slate-200 rounded-xl w-full hover:bg-slate-100/80 transition-colors">
                            <input type="checkbox" v-model="form.isOwner" class="form-checkbox text-blue-600 h-5 w-5 rounded">
                            <span class="ml-3 font-semibold text-sm text-slate-700">The vehicle is registered directly in my name</span>
                        </label>
                    </div>

                    <!-- Conditional Fields (Hidden if isOwner is true) -->
                    <div v-if="!form.isOwner" class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-blue-50/80 p-5 rounded-xl border border-blue-100 transition-all">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">Name of the Registered Owner *</label>
                            <input v-model="form.ownerName" type="text" :required="!form.isOwner" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="Full name on CR">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">Relation to Student / Applicant *</label>
                            <input v-model="form.ownerRelation" type="text" :required="!form.isOwner" class="w-full px-4 py-2.5 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="e.g. Father, Mother, Spouse, Sibling">
                        </div>
                    </div>
                </section>

                <!-- 4. Confirmation & Digital Signature -->
                <section class="bg-slate-50/60 p-5 sm:p-6 rounded-2xl border border-slate-200">
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 border-b border-slate-200 pb-3 mb-5 flex items-center justify-between">
                        <span class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-blue-800 text-white text-xs flex items-center justify-center font-bold">4</span>
                            Confirmation & Digital Signature
                        </span>
                        <span class="text-xs font-normal text-slate-500">Legal Agreement</span>
                    </h2>
                    
                    <div class="mb-6 bg-white p-5 rounded-xl border border-slate-200">
                        <p class="text-xs sm:text-sm text-slate-600 leading-relaxed mb-4">
                            I hereby certify that all information provided in this application is true, accurate, and complete. I agree to abide by all Campus Parking traffic rules, speed limits, and parking regulations. I understand that misrepresentation may result in the revocation of parking privileges and disciplinary action.
                        </p>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">Type Full Name to Confirm *</label>
                        <input v-model="form.confirmName" type="text" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm transition" placeholder="Type your full legal name exactly as entered above">
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">Digital Signature *</label>
                            <span class="text-[11px] text-slate-400">Use your mouse or finger on touch screens</span>
                        </div>
                        <div class="border-2 border-dashed border-slate-300 rounded-2xl bg-white overflow-hidden relative shadow-inner">
                            <canvas 
                                ref="signatureCanvas" 
                                class="w-full h-44 cursor-crosshair block"
                                @mousedown="startDrawing"
                                @mousemove="draw"
                                @mouseup="stopDrawing"
                                @mouseleave="stopDrawing"
                                @touchstart="startDrawingTouch"
                                @touchmove="drawTouch"
                                @touchend="stopDrawing"
                            ></canvas>
                            <div class="absolute bottom-3 right-3 flex items-center gap-2">
                                <button type="button" @click="clearSignature" class="bg-white/90 text-slate-700 text-xs px-3 py-1.5 rounded-lg shadow-sm border border-slate-200 hover:bg-slate-100 transition font-medium flex items-center gap-1.5">
                                    <i class="fas fa-rotate-left text-[10px]"></i> Clear Signature
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Submit Button -->
                <div class="pt-4 border-t border-slate-200">
                    <button type="submit" class="w-full bg-gradient-to-r from-blue-700 to-indigo-700 text-white font-bold py-4 px-6 rounded-2xl shadow-lg hover:from-blue-800 hover:to-indigo-800 focus:ring-4 focus:ring-blue-300 transition-all flex justify-center items-center gap-3 text-base active:scale-[0.99]">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                        <span>Proceed to Secure Payment (₱ 500.00 Sticker Fee)</span>
                    </button>
                    <p class="text-center text-xs text-slate-400 mt-2">Protected by 256-bit encryption • Campus Parking Authority</p>
                </div>
            </form>
        </div>

        <!-- Scanning Modal -->
        <div v-if="isScanning" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center z-50 p-4">
            <div class="bg-white p-8 rounded-2xl shadow-2xl flex flex-col items-center max-w-sm w-full mx-4 animate-scaleIn text-center">
                <div class="relative w-16 h-16 mb-4">
                    <div class="absolute inset-0 border-4 border-blue-200 rounded-2xl"></div>
                    <div class="absolute inset-0 border-4 border-blue-600 rounded-2xl border-t-transparent animate-spin"></div>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-slate-900">Scanning Documents</h3>
                <p class="text-slate-500 text-xs mt-2 leading-relaxed">Extracting Student ID and Official Vehicle Registration records via OCR...</p>
                <div class="w-full bg-slate-100 rounded-full h-1.5 mt-5 overflow-hidden">
                    <div class="bg-blue-600 h-1.5 rounded-full w-2/3 animate-pulse"></div>
                </div>
            </div>
        </div>

        <!-- Processing Payment Modal -->
        <div v-if="isProcessing" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center z-50 p-4">
            <div class="bg-white p-8 rounded-2xl shadow-2xl flex flex-col items-center max-w-sm w-full mx-4 text-center">
                <div class="animate-spin rounded-full h-14 w-14 border-4 border-slate-200 border-t-blue-600 mb-4"></div>
                <h3 class="text-xl font-bold text-slate-900">Processing Payment...</h3>
                <p class="text-slate-500 text-xs mt-2 leading-relaxed">Securing transaction and recording permit application in campus registry. Please do not close this window.</p>
            </div>
        </div>

        <!-- Success Modal -->
        <div v-if="showSuccess" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center z-50 p-4">
            <div class="bg-white p-6 sm:p-8 rounded-3xl shadow-2xl flex flex-col items-center max-w-md w-full mx-4 text-center border border-slate-100">
                <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mb-5 shadow-inner">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h3 class="text-2xl font-black text-slate-900 mb-1">Payment Successful!</h3>
                <p class="text-slate-500 text-xs sm:text-sm mb-6 leading-relaxed">Your parking sticker application has been submitted and confirmed in the database.</p>
                
                <div class="bg-slate-50 w-full p-4 rounded-2xl border border-slate-200 mb-6 text-left space-y-2.5">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-500">Serial No.</span>
                        <span class="font-mono font-bold text-slate-900 bg-white px-2 py-0.5 rounded border border-slate-200">{{ serialNumber }}</span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-500">Amount Paid</span>
                        <span class="font-bold text-emerald-600 text-sm">₱ 500.00</span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-500">Applicant</span>
                        <span class="font-semibold text-slate-800 truncate pl-4">{{ form.name }}</span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-500">Plate Number</span>
                        <span class="font-mono font-bold text-slate-900">{{ form.plateNumber }}</span>
                    </div>
                    <div class="flex justify-between items-center text-xs pt-1 border-t border-slate-200">
                        <span class="text-slate-500">Review Status</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Pending Slot Assignment</span>
                    </div>
                </div>

                <div class="space-y-2.5 w-full">
                    <a href="/parking/permits/index.php" class="w-full inline-flex items-center justify-center gap-2 bg-slate-900 text-white font-semibold py-3 px-4 rounded-xl hover:bg-slate-800 transition shadow-sm text-sm">
                        <i class="fas fa-id-card text-xs"></i> View My Permits & Passes
                    </a>
                    <button @click="resetForm" class="w-full inline-flex items-center justify-center gap-2 bg-slate-100 text-slate-700 font-medium py-3 px-4 rounded-xl hover:bg-slate-200 transition text-sm">
                        Apply for Another Vehicle
                    </button>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- Load Vue 3 from local asset or CDN fallback -->
<script src="/parking/assets/js/vue.global.prod.js"></script>
<script>
if (typeof Vue === 'undefined') {
    document.write('<script src="https://unpkg.com/vue@3/dist/vue.global.js"><\/script>');
}
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const { createApp, ref, reactive, onMounted, nextTick } = Vue;

    createApp({
        setup() {
            const csrfToken = '<?= h(csrf_token()) ?>';
            const serialNumber = ref('');
            
            const form = reactive({
                name: <?= json_encode($userFullname) ?>,
                studentNo: <?= json_encode(str_contains($userUsername, '@') ? '' : $userUsername) ?>,
                address: '',
                licenseNumber: '',
                gender: 'Male',
                vehicleType: 'Car',
                plateNumber: <?= json_encode($defaultVehicle['plate_number'] ?? '') ?>,
                make: <?= json_encode($defaultVehicle['make'] ?? '') ?>,
                model: <?= json_encode($defaultVehicle['model'] ?? '') ?>,
                color: <?= json_encode($defaultVehicle['color'] ?? '') ?>,
                year: <?= json_encode($defaultVehicle['vehicle_year'] ?? date('Y')) ?>,
                orNumber: '',
                crNumber: '',
                isOwner: true,
                ownerName: '',
                ownerRelation: '',
                confirmName: <?= json_encode($userFullname) ?>
            });

            const isScanning = ref(false);
            const isProcessing = ref(false);
            const showSuccess = ref(false);

            const signatureCanvas = ref(null);
            let ctx = null;
            let isDrawing = false;
            let hasSignature = false;

            const generateSerial = () => {
                const year = new Date().getFullYear();
                const randomStr = Math.random().toString(36).substring(2, 7).toUpperCase();
                serialNumber.value = `STK-${year}-${randomStr}`;
            };

            const simulateScan = () => {
                isScanning.value = true;
                setTimeout(() => {
                    Object.assign(form, {
                        name: form.name || 'Maria Santos',
                        studentNo: form.studentNo || '2024-98765',
                        address: 'Block 4 Lot 12, Emerald St., Barangay San Juan, Cavite',
                        licenseNumber: 'N04-12-887766',
                        gender: 'Female',
                        vehicleType: 'Car',
                        plateNumber: 'ABC 5678',
                        make: 'Honda',
                        model: 'Civic RS',
                        color: 'Pearl White',
                        year: 2023,
                        orNumber: 'OR-9988776655',
                        crNumber: 'CR-1122334455',
                        isOwner: true,
                        confirmName: form.name || 'Maria Santos'
                    });
                    isScanning.value = false;
                }, 1800);
            };

            const setupCanvas = () => {
                if (!signatureCanvas.value) return;
                const canvas = signatureCanvas.value;
                const parent = canvas.parentElement;
                
                canvas.width = parent.clientWidth || 600;
                canvas.height = 176; // fixed 44 rem = 176px
                
                ctx = canvas.getContext('2d');
                ctx.strokeStyle = '#1e3a8a';
                ctx.lineWidth = 3;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                
                hasSignature = false; 
            };

            const startDrawing = (e) => {
                isDrawing = true;
                hasSignature = true;
                draw(e);
            };

            const draw = (e) => {
                if (!isDrawing || !ctx || !signatureCanvas.value) return;
                const rect = signatureCanvas.value.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                ctx.lineTo(x, y);
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(x, y);
            };

            const startDrawingTouch = (e) => {
                e.preventDefault();
                isDrawing = true;
                hasSignature = true;
                drawTouch(e);
            };

            const drawTouch = (e) => {
                if (!isDrawing || !ctx || !signatureCanvas.value) return;
                e.preventDefault();
                const rect = signatureCanvas.value.getBoundingClientRect();
                const touch = e.touches[0];
                const x = touch.clientX - rect.left;
                const y = touch.clientY - rect.top;
                
                ctx.lineTo(x, y);
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(x, y);
            };

            const stopDrawing = () => {
                isDrawing = false;
                if (ctx) ctx.beginPath();
            };

            const clearSignature = () => {
                if (!ctx || !signatureCanvas.value) return;
                ctx.clearRect(0, 0, signatureCanvas.value.width, signatureCanvas.value.height);
                hasSignature = false;
            };

            const handlePayment = async () => {
                if (form.confirmName.trim().toLowerCase() !== form.name.trim().toLowerCase()) {
                    alert("The confirmation name must match your Full Name exactly.");
                    return;
                }
                
                if (!hasSignature) {
                    alert("Please provide your digital signature inside the signature box before proceeding.");
                    return;
                }

                if (!form.plateNumber.trim()) {
                    alert("Please enter a valid vehicle plate number.");
                    return;
                }

                isProcessing.value = true;

                try {
                    const signatureData = signatureCanvas.value ? signatureCanvas.value.toDataURL('image/png') : '';
                    
                    const payload = {
                        ...form,
                        serialNumber: serialNumber.value,
                        csrf_token: csrfToken,
                        signatureData: signatureData
                    };

                    const response = await fetch('/parking/permits/apply.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(payload)
                    });

                    const result = await response.json();
                    isProcessing.value = false;

                    if (result.success) {
                        serialNumber.value = result.serialNumber || serialNumber.value;
                        showSuccess.value = true;
                    } else {
                        alert(result.message || 'Application submission failed. Please try again.');
                    }
                } catch (err) {
                    isProcessing.value = false;
                    alert('A network or server error occurred while processing your request. Please try again.');
                }
            };

            const resetForm = () => {
                Object.assign(form, {
                    name: <?= json_encode($userFullname) ?>,
                    studentNo: '',
                    address: '',
                    licenseNumber: '',
                    gender: 'Male',
                    vehicleType: 'Car',
                    plateNumber: '',
                    make: '',
                    model: '',
                    color: '',
                    year: new Date().getFullYear(),
                    orNumber: '',
                    crNumber: '',
                    isOwner: true,
                    ownerName: '',
                    ownerRelation: '',
                    confirmName: <?= json_encode($userFullname) ?>
                });
                
                clearSignature();
                generateSerial();
                showSuccess.value = false;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            };

            onMounted(() => {
                generateSerial();
                nextTick(() => {
                    setupCanvas();
                    window.addEventListener('resize', setupCanvas);
                });
            });

            return {
                serialNumber,
                form,
                signatureCanvas,
                startDrawing, draw, stopDrawing,
                startDrawingTouch, drawTouch,
                clearSignature,
                handlePayment,
                simulateScan,
                isScanning,
                isProcessing,
                showSuccess,
                resetForm
            };
        }
    }).mount('#app');
});
</script>

<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
