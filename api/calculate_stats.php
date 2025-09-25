<?php
// calculate_stats.php
// สคริปต์นี้จะรันเป็น Cron Job เพื่อคำนวณและอัปเดตข้อมูลในตาราง Dashboard

// ป้องกันการเข้าถึงโดยตรงผ่าน URL
if (php_sapi_name() !== 'cli' && !isset($_GET['run_manually'])) {
    http_response_code(403);
    echo "Access denied. This script can only be run from the command line or with a specific parameter.";
    exit;
}

require_once __DIR__ . '/config.php';

try {
    $pdo->beginTransaction();

    // 1. อัปเดต Total Count (dashboard_kpi)
    $totalCountStmt = $pdo->query("SELECT COUNT(*) AS total FROM waste_history");
    $totalCount = (int)$totalCountStmt->fetch()['total'];
    $kpiTotalUpdateStmt = $pdo->prepare("UPDATE dashboard_kpi SET totalCount = ? WHERE id = 1");
    $kpiTotalUpdateStmt->execute([$totalCount]);

    // 2. อัปเดต Today Count (dashboard_kpi)
    $todayCountStmt = $pdo->query("SELECT COUNT(*) AS today_count FROM waste_history WHERE date = CURDATE()");
    $todayCount = (int)$todayCountStmt->fetch()['today_count'];
    $kpiTodayUpdateStmt = $pdo->prepare("UPDATE dashboard_kpi SET todayCount = ? WHERE id = 1");
    $kpiTodayUpdateStmt->execute([$todayCount]);
    
    // อัปเดต Today Count ใน dashboard_last7days
    // ก่อนอื่นต้องลบข้อมูลเก่า (8 วันขึ้นไป)
    $pdo->query("DELETE FROM dashboard_last7days WHERE data_date < CURDATE() - INTERVAL 6 DAY");
    
    // ตรวจสอบว่ามีข้อมูลของวันนี้อยู่แล้วหรือยัง ถ้าไม่มีให้เพิ่ม ถ้ามีให้อัปเดต
    $todayRowStmt = $pdo->prepare("SELECT COUNT(*) FROM dashboard_last7days WHERE data_date = CURDATE()");
    $todayRowStmt->execute();
    if ($todayRowStmt->fetchColumn() > 0) {
        $last7UpdateStmt = $pdo->prepare("UPDATE dashboard_last7days SET data_value = ? WHERE data_date = CURDATE()");
        $last7UpdateStmt->execute([$todayCount]);
    } else {
        $last7InsertStmt = $pdo->prepare("INSERT INTO dashboard_last7days (data_date, data_value) VALUES (CURDATE(), ?)");
        $last7InsertStmt->execute([$todayCount]);
    }

    // 3. อัปเดต Types (dashboard_types)
    $typesStmt = $pdo->query("SELECT type, COUNT(*) AS type_count FROM waste_history GROUP BY type");
    $typesData = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

    // ลบข้อมูลเก่าทั้งหมดก่อน เพื่อป้องกันประเภทขยะที่ไม่มีข้อมูลแล้วยังค้างอยู่
    $pdo->query("TRUNCATE TABLE dashboard_types");
    
    $typeInsertStmt = $pdo->prepare("INSERT INTO dashboard_types (id, type_name, type_count) VALUES (?, ?, ?)");
    foreach ($typesData as $row) {
        // ดึงชื่อประเภทจาก waste_types
        $typeNameStmt = $pdo->prepare("SELECT type_name FROM waste_types WHERE id = ?");
        $typeNameStmt->execute([$row['type']]);
        $typeName = $typeNameStmt->fetchColumn();

        if ($typeName) {
            $typeInsertStmt->execute([$row['type'], $typeName, $row['type_count']]);
        }
    }

    $pdo->commit();
    echo "Statistics updated successfully.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error in calculate_stats.php: " . $e->getMessage());
    echo "An error occurred: " . $e->getMessage() . "\n";
}
?>