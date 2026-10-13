<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('student', 'faculty', 'admin');

$pageTitle = 'My Vehicles';
$role = ucfirst(current_role());
$activeItem = 'vehicle';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid or expired form token. Please go back, refresh the page, and try again.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' && current_role() !== 'admin') {
        $plate = strtoupper(preg_replace('/\s+/', '', trim($_POST['plate_number'] ?? '')));
        $type = strtolower(trim($_POST['vehicle_type'] ?? 'car'));
        if ($type === 'suv' || $type === 'pickup') { $type = 'other'; }
        $make = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $color = trim($_POST['color'] ?? '');
        $year = trim($_POST['vehicle_year'] ?? '');
        $allowedTypes = ['car','motorcycle','van','other'];
        $photo = $_FILES['vehicle_photo'] ?? null;
        $photoRelativePath = null;
        $newPhotoAbsolutePath = null;

        if ($plate === '' || $make === '' || $model === '' || $color === '') {
            $error = 'Please fill in all required vehicle fields.';
        } elseif (!in_array($type, $allowedTypes, true)) {
            $error = 'Invalid vehicle type.';
        } elseif ($year !== '' && (!ctype_digit($year) || (int)$year < 1900 || (int)$year > ((int)date('Y') + 1))) {
            $error = 'Please enter a valid vehicle year.';
        } elseif (!$photo || $photo['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a vehicle photo (maximum 5 MB).';
        } elseif ($photo['size'] > 5 * 1024 * 1024) {
            $error = 'Vehicle photo must be 5 MB or smaller.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($photo['tmp_name']);
            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowedMimes[$mime]) || @getimagesize($photo['tmp_name']) === false) {
                $error = 'Please upload a valid JPG, PNG, or WEBP image.';
            } else {
                $check = $pdo->prepare('SELECT id FROM vehicles WHERE plate_number = ? LIMIT 1');
                $check->execute([$plate]);
                if ($check->fetch()) {
                    $error = 'That plate number is already registered.';
                } else {
                    $uploadDirectory = __DIR__ . '/../uploads/vehicles';
                    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                        $error = 'Vehicle photo storage is unavailable. Please contact the administrator.';
                    } else {
                        $filename = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
                        $newPhotoAbsolutePath = $uploadDirectory . '/' . $filename;
                        $photoRelativePath = 'uploads/vehicles/' . $filename;
                        if (!move_uploaded_file($photo['tmp_name'], $newPhotoAbsolutePath)) {
                            $error = 'The vehicle photo could not be saved. Please try again.';
                        } else {
                            try {
                                $stmt = $pdo->prepare('INSERT INTO vehicles (user_id, plate_number, vehicle_type, make, model, color, vehicle_year, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                                $stmt->execute([current_user_id(), $plate, $type, $make, $model, $color, $year === '' ? null : (int)$year, $photoRelativePath]);
                                $success = 'Vehicle registered successfully.';
                            } catch (PDOException $e) {
                                if (is_file($newPhotoAbsolutePath)) { @unlink($newPhotoAbsolutePath); }
                                $error = 'The vehicle could not be registered. Check that the database photo migration has been imported and that the plate number is unique.';
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'deactivate') {
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        if ($vehicleId > 0) {
            if (current_role() === 'admin') {
                $stmt = $pdo->prepare("UPDATE vehicles SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$vehicleId]);
            } else {
                $stmt = $pdo->prepare("UPDATE vehicles SET status = 'inactive' WHERE id = ? AND user_id = ?");
                $stmt->execute([$vehicleId, current_user_id()]);
            }
            $success = 'Vehicle marked inactive.';
        }
    } elseif ($action === 'reactivate') {
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        if ($vehicleId > 0) {
            if (current_role() === 'admin') {
                $stmt = $pdo->prepare("UPDATE vehicles SET status = 'active' WHERE id = ?");
                $stmt->execute([$vehicleId]);
            } else {
                $stmt = $pdo->prepare("UPDATE vehicles SET status = 'active' WHERE id = ? AND user_id = ?");
                $stmt->execute([$vehicleId, current_user_id()]);
            }
            $success = 'Vehicle reactivated.';
        }
    }
}

if (current_role() === 'admin') {
    $stmt = $pdo->query("SELECT v.*, u.fullname FROM vehicles v LEFT JOIN users u ON u.id = v.user_id ORDER BY v.created_at DESC");
} else {
    $stmt = $pdo->prepare("SELECT v.*, u.fullname FROM vehicles v LEFT JOIN users u ON u.id = v.user_id WHERE v.user_id = ? ORDER BY v.created_at DESC");
    $stmt->execute([current_user_id()]);
}
$vehicles = $stmt->fetchAll();

include __DIR__ . '/../layouts/dashboard_header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-8">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-ink-500">Campus Parking</p>
            <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900"><?= current_role() === 'admin' ? 'Registered Vehicles' : 'My Vehicles' ?></h1>
            <p class="mt-2 text-sm text-slate-500"><?= current_role() === 'admin' ? 'View vehicles registered by campus users.' : 'Register the vehicle you intend to use for campus parking.' ?></p>
        </div>
        <?php if (current_role() !== 'admin'): ?>
            <div class="flex items-center gap-3 flex-wrap">
                <a href="../permits/apply.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm active:scale-[0.99]">
                    <i class="fas fa-file-signature text-xs"></i> Apply for Sticker
                </a>
                <button type="button" onclick="document.getElementById('vehicle-form').scrollIntoView({behavior:'smooth'})" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800 transition shadow-sm active:scale-[0.99]">
                    <i class="fas fa-plus text-xs"></i> Add vehicle
                </button>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 shadow-sm">
            <i class="fas fa-circle-exclamation mt-0.5 text-rose-600 text-base shrink-0"></i>
            <div><p class="font-bold">Registration issue</p><p class="mt-0.5"><?= h($error) ?></p></div>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 shadow-sm">
            <i class="fas fa-circle-check mt-0.5 text-emerald-600 text-base shrink-0"></i>
            <div><p class="font-bold">Success</p><p class="mt-0.5"><?= h($success) ?></p></div>
        </div>
    <?php endif; ?>

    <?php if (current_role() !== 'admin'): ?>
    <section id="vehicle-form" class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 shadow-sm">
        <div class="mb-6">
            <h2 class="text-lg font-bold text-slate-900">Register a vehicle</h2>
            <p class="mt-1 text-sm text-slate-500">One vehicle can later be used for a parking permit application.</p>
        </div>
        <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="add">
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Plate number</label>
                <input name="plate_number" required maxlength="20" placeholder="ABC-1234" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-base uppercase outline-none focus:border-slate-900 focus:bg-white focus:ring-1 focus:ring-slate-900 transition">
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Vehicle type</label>
                <select name="vehicle_type" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-slate-900 focus:bg-white focus:ring-1 focus:ring-slate-900 transition">
                    <?php foreach ([['car','Car'],['motorcycle','Motorcycle'],['van','Van'],['other','Other']] as $type): ?><option value="<?= $type[0] ?>"><?= $type[1] ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Make</label>
                <input name="make" required maxlength="60" placeholder="Toyota" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-slate-900 focus:bg-white focus:ring-1 focus:ring-slate-900 transition">
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Model</label>
                <input name="model" required maxlength="60" placeholder="Vios" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-slate-900 focus:bg-white focus:ring-1 focus:ring-slate-900 transition">
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Color</label>
                <input name="color" required maxlength="40" placeholder="White" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-slate-900 focus:bg-white focus:ring-1 focus:ring-slate-900 transition">
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Year <span class="font-normal text-slate-400">(optional)</span></label>
                <input type="number" name="vehicle_year" min="1900" max="<?= (int)date('Y') + 1 ?>" placeholder="2024" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-slate-900 focus:bg-white focus:ring-1 focus:ring-slate-900 transition">
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
                <label class="mb-2 block text-sm font-semibold text-slate-700">Vehicle photo <span class="text-red-600">*</span></label>
                <input type="file" name="vehicle_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm outline-none focus:border-slate-900 focus:ring-1 focus:ring-slate-900">
                <p class="mt-2 text-xs text-slate-500">Upload a clear photo of the actual vehicle. JPG, PNG, or WEBP; maximum 5 MB.</p>
                <div id="vehicle-photo-preview-wrap" class="mt-3 hidden"><img id="vehicle-photo-preview" alt="Vehicle photo preview" class="h-40 max-w-full rounded-xl border border-slate-200 object-cover"><p class="mt-1 text-xs text-slate-500">Photo preview</p></div>
            </div>
            <div class="sm:col-span-2 lg:col-span-3 flex justify-end">
                <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-6 py-3.5 text-sm font-semibold text-white hover:bg-slate-800 transition shadow-sm active:scale-[0.99]">
                    <i class="fas fa-check text-xs"></i> Register vehicle
                </button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-5 sm:p-6 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900">Vehicles</h2>
                <p class="mt-0.5 text-xs sm:text-sm text-slate-500"><?= count($vehicles) ?> registered vehicle<?= count($vehicles) === 1 ? '' : 's' ?></p>
            </div>
            <span class="text-xs text-slate-400 lg:hidden flex items-center gap-1"><i class="fas fa-arrows-left-right text-[10px]"></i> Scroll table</span>
        </div>
        <?php if (!$vehicles): ?>
            <div class="px-6 py-12 text-center text-slate-500">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 mb-3">
                    <i class="fas fa-car text-2xl"></i>
                </div>
                <p class="font-bold text-slate-800">No vehicles registered yet</p>
                <p class="mt-1 text-sm text-slate-500 max-w-sm mx-auto">Register your car, motorcycle, or van to apply for campus parking permits.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto w-full">
                <table class="w-full text-left text-sm min-w-[640px]">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-400">
                        <tr>
                            <?php if (current_role() === 'admin'): ?><th class="px-6 py-4">Owner</th><?php endif; ?>
                            <th class="px-6 py-4">Photo</th><th class="px-6 py-4">Plate</th><th class="px-6 py-4">Vehicle</th><th class="px-6 py-4">Color</th><th class="px-6 py-4">Status</th><th class="px-6 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($vehicles as $vehicle): ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <?php if (current_role() === 'admin'): ?><td class="px-6 py-4 font-medium text-slate-800"><?= h($vehicle['fullname'] ?? 'Unknown') ?></td><?php endif; ?>
                            <td class="px-6 py-4">
                                <?php if (!empty($vehicle['photo_path'])): ?>
                                    <a href="../<?= h($vehicle['photo_path']) ?>" target="_blank" rel="noopener"><img src="../<?= h($vehicle['photo_path']) ?>" alt="Vehicle <?= h($vehicle['plate_number']) ?>" loading="lazy" class="h-14 w-20 rounded-lg border border-slate-200 object-cover shadow-xs"></a>
                                <?php else: ?>
                                    <span class="inline-flex h-14 w-20 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-400">No photo</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 font-mono font-bold text-slate-900"><?= h($vehicle['plate_number']) ?></td>
                            <td class="px-6 py-4"><div class="font-semibold text-slate-800"><?= h($vehicle['make'] . ' ' . $vehicle['model']) ?></div><div class="text-xs text-slate-500"><?= h(ucfirst($vehicle['vehicle_type'])) ?><?= $vehicle['vehicle_year'] ? ' · ' . h((string)$vehicle['vehicle_year']) : '' ?></div></td>
                            <td class="px-6 py-4 text-slate-600"><?= h($vehicle['color']) ?></td>
                            <td class="px-6 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= $vehicle['status'] === 'active' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500' ?>"><?= h(ucfirst($vehicle['status'])) ?></span></td>
                            <td class="px-6 py-4 text-right">
                                <form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $vehicle['status'] === 'active' ? 'deactivate' : 'reactivate' ?>"><input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>"><button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs transition"><?= $vehicle['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.querySelector('input[name="vehicle_photo"]');
    const wrap = document.getElementById('vehicle-photo-preview-wrap');
    const preview = document.getElementById('vehicle-photo-preview');
    if (!input || !wrap || !preview) return;
    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        if (!file) { wrap.classList.add('hidden'); preview.removeAttribute('src'); return; }
        if (file.size > 5 * 1024 * 1024) {
            input.value = '';
            wrap.classList.add('hidden');
            alert('Please choose an image that is 5 MB or smaller.');
            return;
        }
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            input.value = '';
            wrap.classList.add('hidden');
            alert('Please choose a JPG, PNG, or WEBP image.');
            return;
        }
        preview.src = URL.createObjectURL(file);
        wrap.classList.remove('hidden');
    });
});
</script>

<?php include __DIR__ . '/../layouts/dashboard_footer.php'; ?>
