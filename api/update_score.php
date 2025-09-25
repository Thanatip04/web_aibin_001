<?php
// update_score.php
// รับ POST: user_id, waste_type -> บันทึกประวัติและคะแนนลงในฐานข้อมูล
// waste_type ควรเป็น ID ตัวเลข (1=พลาสติก, 2=ทั่วไป, 3=รีไซเคิล)

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// 1. รับค่าจาก POST
$userId = isset($_POST['user_id']) ? trim($_POST['user_id']) : '';
$wasteType = isset($_POST['waste_type']) ? (int)$_POST['waste_type'] : 0;

// 2. ตรวจสอบข้อมูล
if ($userId === '' || $wasteType === 0) {
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน: user_id หรือ waste_type หายไป'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 3. ดึงคะแนนจากตาราง waste_types
    $scoreStmt = $pdo->prepare("SELECT points FROM waste_types WHERE id = ?");
    $scoreStmt->execute([$wasteType]);
    $scoreData = $scoreStmt->fetch();

    if (!$scoreData) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบประเภทขยะที่ระบุ'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $points = (int)$scoreData['points'];

    // 4. บันทึกประวัติการทิ้งขยะลงในตาราง waste_history
    $insertStmt = $pdo->prepare("
        INSERT INTO waste_history (user_id, type, score, date, time) 
        VALUES (?, ?, ?, CURDATE(), CURTIME())
    ");
    $insertStmt->execute([$userId, $wasteType, $points]);
    
    // 5. อัปเดตคะแนนรวมในตาราง users
    // โค้ดส่วนนี้อาจจะเกินความจำเป็น แต่ถ้าต้องการเก็บคะแนนรวมไว้ในตาราง users ด้วยก็ใช้ได้เลย
    $updateStmt = $pdo->prepare("
        UPDATE users SET score = score + ? WHERE id = ?
    ");
    $updateStmt->execute([$points, $userId]);

    echo json_encode([
        'status' => 'success',
        'message' => 'บันทึกคะแนนเรียบร้อย',
        'points_added' => $points
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล'], JSON_UNESCAPED_UNICODE);
}
?>