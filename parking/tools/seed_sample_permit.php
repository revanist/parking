<?php
require_once __DIR__ . '/../includes/dbconn.php';

$pdo->beginTransaction();

// Check if vehicle exists
$stmt = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = 'NBC-2026'");
$stmt->execute();
$veh = $stmt->fetch();

if (!$veh) {
    $pdo->prepare("INSERT INTO vehicles (user_id, plate_number, vehicle_type, make, model, color, vehicle_year, status) VALUES (5, 'NBC-2026', 'car', 'Toyota', 'Vios', 'Silver', 2024, 'active')")->execute();
    $vehicleId = (int)$pdo->lastInsertId();
} else {
    $vehicleId = (int)$veh['id'];
}

// Reserve slot 1 (STU-01)
$pdo->prepare("UPDATE parking_slots SET status = 'reserved' WHERE id = 1")->execute();

// Check if permit exists
$stmt = $pdo->prepare("SELECT id FROM parking_permits WHERE permit_number = 'CP-2026-000001'");
$stmt->execute();
$permit = $stmt->fetch();

$token = 'a1b2c3d4e5f60718293a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e';

if (!$permit) {
    $ins = $pdo->prepare("INSERT INTO parking_permits (user_id, vehicle_id, parking_slot_id, permit_number, qr_token, status, applied_at, reviewed_at, expires_at) VALUES (5, ?, 1, 'CP-2026-000001', ?, 'approved', NOW(), NOW(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))");
    $ins->execute([$vehicleId, $token]);
    $permitId = (int)$pdo->lastInsertId();
} else {
    $permitId = (int)$permit['id'];
}

$pdo->commit();

echo "Sample approved permit verified!\nPermit ID: $permitId\nToken: $token\nPlate: NBC-2026\nSlot: STU-01\n";
