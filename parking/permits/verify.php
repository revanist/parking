<?php
// Public QR verification endpoint. The random token is an opaque identifier;
// only a small set of permit details is shown to avoid exposing account data.
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';

$token = strtolower(trim((string)($_GET['token'] ?? '')));
$permit = null;
$isValid = false;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $stmt = $pdo->prepare("SELECT pp.permit_number, pp.status, pp.expires_at, pp.reviewed_at,
                                      v.plate_number, v.vehicle_type, v.make, v.model, v.status AS vehicle_status,
                                      ps.id AS parking_slot_id, ps.slot_code, pa.area_name
                               FROM parking_permits pp
                               JOIN vehicles v ON v.id = pp.vehicle_id
                               LEFT JOIN parking_slots ps ON ps.id = pp.parking_slot_id
                               LEFT JOIN parking_areas pa ON pa.id = ps.parking_area_id
                               WHERE pp.qr_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $permit = $stmt->fetch();
        $isValid = $permit
            && $permit['status'] === 'approved'
            && $permit['vehicle_status'] === 'active'
            && !empty($permit['permit_number'])
            && !empty($permit['parking_slot_id'] ?? null)
            && (empty($permit['expires_at']) || $permit['expires_at'] >= date('Y-m-d'));
    } catch (Throwable $e) {
        // Keep verification failure generic; do not expose database details.
        $permit = null;
    }
}
function out(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parking Permit Verification | CampusPark</title>
    <script src="../assets/js/tailwindcss.js"></script>
<style>@media print { body { background: white !important; padding: 0 !important; } main { box-shadow: none !important; } button { display: none !important; } }</style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 flex items-center justify-center p-4">
    <main class="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <header class="bg-slate-900 px-6 py-5 text-white flex items-center gap-4">
            <img src="/parking/img/campus_parking_logo.png" alt="Campus Parking Logo" class="w-12 h-12 object-contain shrink-0">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-amber-400">Campus Parking</p>
                <h1 class="mt-1 text-2xl font-extrabold text-white">Permit Verification</h1>
                <p class="text-xs text-slate-300">Official Campus Parking System</p>
            </div>
        </header>
        <section class="p-6">
            <?php if ($isValid): ?>
                <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <p class="font-bold text-emerald-800">✓ VALID PARKING PERMIT</p>
                    <p class="mt-1 text-sm text-emerald-700">The permit is approved and has not expired as of <?= date('M j, Y') ?>.</p>
                </div>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Permit number</dt><dd class="mt-1 font-bold"><?= out($permit['permit_number']) ?></dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Plate number</dt><dd class="mt-1 font-bold font-mono"><?= out($permit['plate_number']) ?></dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Vehicle</dt><dd class="mt-1 font-semibold"><?= out(trim(($permit['make'] ?? '') . ' ' . ($permit['model'] ?? ''))) ?> (<?= out(ucfirst((string)$permit['vehicle_type'])) ?>)</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Assigned slot</dt><dd class="mt-1 font-semibold"><?= out($permit['slot_code'] ?? 'Not assigned') ?></dd><dd class="text-sm text-slate-500"><?= out($permit['area_name'] ?? '') ?></dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Valid through</dt><dd class="mt-1 font-semibold"><?= !empty($permit['expires_at']) ? out(date('F j, Y', strtotime($permit['expires_at']))) : 'No expiration date recorded' ?></dd></div>
                </dl>
                <p class="mt-6 rounded-lg bg-slate-50 p-3 text-xs leading-5 text-slate-500">This page confirms permit status only. Security staff must still record vehicle entry in the authenticated CampusPark system.</p>
            <?php else: ?>
                <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                    <p class="font-bold text-red-800">✕ INVALID OR UNAVAILABLE PERMIT</p>
                    <p class="mt-1 text-sm text-red-700">This QR code is invalid, the permit is not approved, the vehicle is inactive, or the permit has expired. Ask the vehicle owner to contact the campus administrator.</p>
                </div>
            <?php endif; ?>

            <?php if ($isValid): ?>
            <div class="mt-5 flex flex-wrap items-center justify-center gap-3 print:hidden">
                <button type="button" onclick="window.print()" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">Print verification</button>
                <a href="pass.php?token=<?= rawurlencode($token) ?>" class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800 transition">View Official Digital Pass</a>
            </div>
            <?php endif; ?>
            <p class="mt-6 text-center text-xs text-slate-400">CampusPark · College Parking Management System</p>
        </section>
    </main>
</body>
</html>
