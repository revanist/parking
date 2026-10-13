<?php
require_once __DIR__ . '/../includes/dbconn.php';

try {
    $pdo->beginTransaction();

    // 1. Check or add vehicle for user donard (id=2)
    $stmt = $pdo->query("SELECT id FROM vehicles WHERE plate_number = 'ABC-1234' LIMIT 1");
    $vehicleId = $stmt->fetchColumn();
    if (!$vehicleId) {
        $stmt = $pdo->prepare("INSERT INTO vehicles (user_id, plate_number, vehicle_type, make, model, color, vehicle_year, status) VALUES (?, 'ABC-1234', 'car', 'Toyota', 'Corolla', 'White', 2022, 'active')");
        $stmt->execute([2]);
        $vehicleId = (int)$pdo->lastInsertId();
    }

    // 2. Add vehicle for user admin or create permit
    $slotStu1 = (int)$pdo->query("SELECT id FROM parking_slots WHERE slot_code = 'STU-01'")->fetchColumn();
    $slotStu3 = (int)$pdo->query("SELECT id FROM parking_slots WHERE slot_code = 'STU-03'")->fetchColumn();
    $slotVis1 = (int)$pdo->query("SELECT id FROM parking_slots WHERE slot_code = 'VIS-01'")->fetchColumn();
    $slotVis2 = (int)$pdo->query("SELECT id FROM parking_slots WHERE slot_code = 'VIS-02'")->fetchColumn();

    // Set STU-03 to maintenance
    if ($slotStu3) {
        $pdo->prepare("UPDATE parking_slots SET status = 'maintenance' WHERE id = ?")->execute([$slotStu3]);
    }

    // Check permit for donard
    $chkStmt = $pdo->prepare("SELECT id FROM parking_permits WHERE vehicle_id = ? LIMIT 1");
    $chkStmt->execute([$vehicleId]);
    $permitId = $chkStmt->fetchColumn();
    if (!$permitId && $slotStu1) {
        $stmt = $pdo->prepare("INSERT INTO parking_permits (user_id, vehicle_id, parking_slot_id, permit_number, qr_token, status, applied_at, reviewed_at, expires_at) VALUES (2, ?, ?, 'PRM-2026-0001', SHA2(CONCAT(NOW(), RAND()), 256), 'approved', NOW(), NOW(), DATE_ADD(CURDATE(), INTERVAL 6 MONTH))");
        $stmt->execute([$vehicleId, $slotStu1]);
        $permitId = (int)$pdo->lastInsertId();
    }

    // Check visitors
    $vis1 = $pdo->query("SELECT id FROM visitors WHERE vehicle_plate = 'XYZ-9876' LIMIT 1")->fetchColumn();
    if (!$vis1 && $slotVis1) {
        $stmt = $pdo->prepare("INSERT INTO visitors (visitor_name, contact, purpose, person_to_visit, vehicle_plate, vehicle_type, parking_slot_id, status, entry_time, exit_time) VALUES ('Sarah Connor', '09171234567', 'Official Inquiry', 'Registrar', 'XYZ-9876', 'car', ?, 'exited', DATE_SUB(NOW(), INTERVAL 110 MINUTE), DATE_SUB(NOW(), INTERVAL 25 MINUTE))");
        $stmt->execute([$slotVis1]);
        $vis1 = (int)$pdo->lastInsertId();
    }

    $vis2 = $pdo->query("SELECT id FROM visitors WHERE vehicle_plate = 'DND-4455' LIMIT 1")->fetchColumn();
    if (!$vis2 && $slotVis2) {
        $stmt = $pdo->prepare("INSERT INTO visitors (visitor_name, contact, purpose, person_to_visit, vehicle_plate, vehicle_type, parking_slot_id, status, entry_time) VALUES ('Michael Scott', '09189876543', 'Campus Guest Lecture', 'Dean Office', 'DND-4455', 'van', ?, 'inside', DATE_SUB(NOW(), INTERVAL 20 MINUTE))");
        $stmt->execute([$slotVis2]);
        $vis2 = (int)$pdo->lastInsertId();
    }

    // Insert parking logs if empty
    $logCount = (int)$pdo->query("SELECT COUNT(*) FROM parking_logs")->fetchColumn();
    if ($logCount === 0) {
        // Log 1: Sarah Connor entered and exited
        if ($vis1 && $slotVis1) {
            $stmt = $pdo->prepare("INSERT INTO parking_logs (visitor_id, parking_slot_id, entry_time, exit_time, recorded_by, status, notes) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 110 MINUTE), DATE_SUB(NOW(), INTERVAL 25 MINUTE), 1, 'completed', 'Visitor parking completed.')");
            $stmt->execute([$vis1, $slotVis1]);
        }

        // Log 2: donard entered STU-01 and is inside
        if ($vehicleId && $slotStu1) {
            $stmt = $pdo->prepare("INSERT INTO parking_logs (vehicle_id, parking_slot_id, entry_time, recorded_by, status, notes) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 50 MINUTE), 1, 'inside', 'Permit vehicle entered campus.')");
            $stmt->execute([$vehicleId, $slotStu1]);
        }

        // Log 3: Michael Scott entered VIS-02 and is inside
        if ($vis2 && $slotVis2) {
            $stmt = $pdo->prepare("INSERT INTO parking_logs (visitor_id, parking_slot_id, entry_time, recorded_by, status, notes) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 20 MINUTE), 1, 'inside', 'Guest visitor checked in.')");
            $stmt->execute([$vis2, $slotVis2]);
        }
    }

    $pdo->commit();
    echo "Seed completed successfully.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Seed error: " . $e->getMessage() . "\n";
}
