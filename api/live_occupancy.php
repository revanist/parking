<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../includes/dbconn.php';

try {
    // 1. Total active slots across active parking areas
    $totalSlots = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM parking_slots ps 
        JOIN parking_areas pa ON pa.id = ps.parking_area_id 
        WHERE pa.status = 'active' AND ps.status <> 'inactive'
    ")->fetchColumn();

    // 2. Occupied slots (vehicles currently recorded inside campus)
    $occupiedSlots = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM parking_logs 
        WHERE status = 'inside'
    ")->fetchColumn();

    // 3. Available slots (status is 'available' and not currently occupied by an active log)
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

    // 4. Reserved slots (assigned to permits or designated reserved)
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

    // Determine occupancy status label & color token
    if ($occupancyPercentage >= 95.0) {
        $statusLabel = 'Critical / Lot Full';
        $statusTheme = 'red';
    } elseif ($occupancyPercentage >= 75.0) {
        $statusLabel = 'High Occupancy';
        $statusTheme = 'amber';
    } elseif ($occupancyPercentage >= 40.0) {
        $statusLabel = 'Moderate Occupancy';
        $statusTheme = 'blue';
    } else {
        $statusLabel = 'Optimal / Plenty Available';
        $statusTheme = 'emerald';
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
    $areasRaw = $areasStmt->fetchAll();
    $areas = array_map(function ($a) {
        $tot = (int)$a['total_slots'];
        $occ = (int)$a['occupied_slots'];
        $pct = $tot > 0 ? round(($occ / $tot) * 100, 1) : 0.0;
        return [
            'id' => (int)$a['id'],
            'name' => $a['area_name'],
            'type' => ucfirst((string)$a['area_type']),
            'total_slots' => $tot,
            'occupied_slots' => $occ,
            'available_slots' => (int)$a['available_slots'],
            'reserved_slots' => (int)$a['reserved_slots'],
            'maintenance_slots' => (int)$a['maintenance_slots'],
            'occupancy_percentage' => $pct,
        ];
    }, $areasRaw);

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
    $logs = $logsStmt->fetchAll();

    // Format human-readable relative time and durations
    $formattedLogs = array_map(function ($item) {
        $eventTimeUnix = strtotime((string)$item['event_time']);
        $mins = (int)$item['duration_minutes'];
        $hrs = intdiv($mins, 60);
        $remMins = $mins % 60;
        $durationFormatted = $hrs > 0 ? "{$hrs}h {$remMins}m" : "{$remMins}m";

        return [
            'id' => (int)$item['id'],
            'activity_type' => $item['activity_type'], // 'ENTRY' or 'EXIT'
            'plate_number' => $item['plate_number'],
            'vehicle_type' => ucfirst((string)$item['vehicle_type']),
            'vehicle_desc' => $item['vehicle_desc'],
            'driver_name' => $item['driver_name'],
            'driver_type' => $item['driver_type'],
            'slot_code' => $item['slot_code'] ?: '—',
            'area_name' => $item['area_name'] ?: 'General',
            'status' => $item['status'],
            'entry_time' => $item['entry_time'] ? date('M d, Y · h:i A', strtotime((string)$item['entry_time'])) : null,
            'exit_time' => $item['exit_time'] ? date('M d, Y · h:i A', strtotime((string)$item['exit_time'])) : null,
            'event_time' => date('M d, Y · h:i A', $eventTimeUnix),
            'duration_formatted' => $durationFormatted,
        ];
    }, $logs);

    echo json_encode([
        'success' => true,
        'timestamp' => date('M d, Y · h:i:s A'),
        'server_time_iso' => date('c'),
        'stats' => [
            'total_slots' => $totalSlots,
            'occupied_slots' => $occupiedSlots,
            'available_slots' => $availableSlots,
            'reserved_slots' => $reservedSlots,
            'maintenance_slots' => $maintenanceSlots,
            'occupancy_percentage' => $occupancyPercentage,
            'status_label' => $statusLabel,
            'status_theme' => $statusTheme,
        ],
        'areas' => $areas,
        'slots' => $slots,
        'recent_activity' => $formattedLogs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database query failed: ' . $e->getMessage()
    ]);
}
