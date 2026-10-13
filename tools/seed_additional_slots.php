<?php
require_once __DIR__ . '/../includes/dbconn.php';

$targets = [
    'faculty' => ['code' => 'FAC', 'target' => 10],
    'motorcycle' => ['code' => 'MOT', 'target' => 15],
    'student' => ['code' => 'STU', 'target' => 10],
    'visitor' => ['code' => 'VIS', 'target' => 5],
];

echo "Updating parking slots...\n";

foreach ($targets as $type => $config) {
    $stmt = $pdo->prepare("SELECT id, area_name FROM parking_areas WHERE area_type = ? LIMIT 1");
    $stmt->execute([$type]);
    $area = $stmt->fetch();

    if (!$area) {
        echo "Area type '$type' not found!\n";
        continue;
    }

    $areaId = (int)$area['id'];
    $areaName = $area['area_name'];

    for ($i = 1; $i <= $config['target']; $i++) {
        $slotCode = sprintf('%s-%02d', $config['code'], $i);
        $check = $pdo->prepare("SELECT id FROM parking_slots WHERE slot_code = ?");
        $check->execute([$slotCode]);

        if (!$check->fetch()) {
            $insert = $pdo->prepare("INSERT INTO parking_slots (parking_area_id, slot_code, status) VALUES (?, ?, 'available')");
            $insert->execute([$areaId, $slotCode]);
            echo "[CREATED] {$slotCode} in {$areaName}\n";
        } else {
            echo "[EXISTS]  {$slotCode} in {$areaName}\n";
        }
    }
}

echo "\nSummary of slots per area:\n";
$summary = $pdo->query("
    SELECT pa.area_name, pa.area_type, COUNT(ps.id) AS total_slots,
           SUM(ps.status = 'available') AS available_slots,
           SUM(ps.status = 'reserved') AS reserved_slots,
           SUM(ps.status = 'maintenance') AS maintenance_slots
    FROM parking_areas pa
    LEFT JOIN parking_slots ps ON ps.parking_area_id = pa.id
    GROUP BY pa.id, pa.area_name, pa.area_type
    ORDER BY pa.area_name
")->fetchAll();

foreach ($summary as $row) {
    echo sprintf(
        "- %-20s (%-10s): %2d total slots (available: %2d, reserved: %2d, maintenance: %2d)\n",
        $row['area_name'],
        $row['area_type'],
        $row['total_slots'],
        $row['available_slots'],
        $row['reserved_slots'],
        $row['maintenance_slots']
    );
}

echo "\nDone!\n";
