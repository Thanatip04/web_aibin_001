<?php
// get_data.php
// ดึงข้อมูลตาม action ที่ส่งมาใน query string
// action=dashboard        → สรุปข้อมูล KPI/Types/Last7Days + Users + Bin + Feedback
// action=student_history  → ข้อมูลส่วนตัว + ประวัติการคัดแยกของนักเรียน
// action=all_waste_history → ประวัติการคัดแยกทั้งหมด (สำหรับ Admin)
// action=system_health    → ตรวจสอบสถานะระบบ
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// ตรวจสอบว่าตัวแปร $pdo ถูกสร้างขึ้นมาหรือไม่
if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ไม่สามารถเชื่อมต่อฐานข้อมูลได้']);
    exit;
}

$action = $_GET['action'] ?? 'dashboard';

try {
    $result = [];

    switch ($action) {
        case 'dashboard':
            // ---------- KPI ----------
            $kpiStmt = $pdo->query("SELECT todayCount, aiAccuracy, totalCount FROM dashboard_kpi LIMIT 1");
            $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: ['todayCount' => 0, 'aiAccuracy' => 0, 'totalCount' => 0];

            // ---------- Last 7 Days ----------
            $last7Stmt = $pdo->query("SELECT data_value FROM dashboard_last7days ORDER BY data_date ASC");
            $last7 = array_map('intval', $last7Stmt->fetchAll(PDO::FETCH_COLUMN));
            $last7[count($last7) - 1] = (int)($kpi['todayCount'] ?? 0);
            if (!$last7) {
                $last7 = array_fill(0, 7, 0);
            }

            // ---------- Types ----------
            $typeStmt = $pdo->query("SELECT type_name, type_count FROM dashboard_types");
            $types = [];
            foreach ($typeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $types[$row['type_name']] = (int)$row['type_count'];
            }

            // ---------- Users (รวมคะแนนใน query เดียว) ----------
            $usersStmt = $pdo->query("
                SELECT 
                    u.id, 
                    u.name, 
                    u.role, 
                    u.class,
                    u.avatar,
                    COALESCE(SUM(wh.score), 0) AS score
                FROM users u
                LEFT JOIN waste_history wh ON u.id = wh.user_id
                GROUP BY u.id
                ORDER BY score DESC
            ");
            $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

            // ---------- Bins ----------
            $binsStmt = $pdo->query("
                SELECT 
                    id, 
                    location, 
                    plastic_status, 
                    recycle_status, 
                    general_status, 
                    last_updated
                FROM bin_status
                ORDER BY id ASC
            ");
            $bins = $binsStmt->fetchAll(PDO::FETCH_ASSOC);

            // ---------- Feedback ล่าสุด ----------
            $fbStmt = $pdo->query("
                SELECT 
                    f.id,
                    f.user_id,
                    u.name AS user_name,
                    f.bin_id,
                    f.message,
                    f.timestamp
                FROM feedback f
                LEFT JOIN users u ON f.user_id = u.id
                ORDER BY f.timestamp DESC
                LIMIT 20
            ");
            $feedback = $fbStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // เตรียมผลลัพธ์
            $result = [
                'dashboard' => [
                    'todayCount' => (int)($kpi['todayCount'] ?? 0),
                    'aiAccuracy' => (float)($kpi['aiAccuracy'] ?? 0),
                    'totalCount' => (int)($kpi['totalCount'] ?? 0),
                    'last7Days' => $last7,
                    'types' => $types
                ],
                'users'    => $users,
                'bins'     => $bins,
                'feedback' => $feedback
            ];
            break;

        case 'student_history':
            $userId = trim($_GET['user_id'] ?? '');
            if (empty($userId)) {
                http_response_code(400);
                $result = ['status' => 'error', 'message' => 'กรุณาระบุ user_id'];
                break;
            }

            // ดึงข้อมูลผู้ใช้และคะแนนสะสมใน query เดียว
            $userStmt = $pdo->prepare("
                SELECT 
                    u.id, 
                    u.name, 
                    u.role, 
                    u.class, 
                    u.avatar,
                    COALESCE(SUM(wh.score), 0) AS score
                FROM users u
                LEFT JOIN waste_history wh ON u.id = wh.user_id
                WHERE u.id = ?
                GROUP BY u.id
            ");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                http_response_code(404);
                $result = ['status' => 'error', 'message' => 'ไม่พบนักเรียน'];
                break;
            }

            // ดึงประวัติการคัดแยก
            $historyStmt = $pdo->prepare("
                SELECT 
                    CONCAT(wh.date, ' ', TIME_FORMAT(wh.time, '%H:%i:%s')) AS timestamp,
                    wt.type_name AS waste_type,
                    wh.score,
                    bs.location AS bin_location
                FROM waste_history wh
                JOIN waste_types wt ON wh.type = wt.id
                JOIN bin_status bs ON wh.bin_id = bs.id
                WHERE wh.user_id = ?
                ORDER BY wh.date DESC, wh.time DESC
                LIMIT 20
            ");
            $historyStmt->execute([$userId]);
            $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [
                'status'  => 'success',
                'profile' => $user,
                'history' => $history
            ];
            break;

        case 'all_waste_history':
            $stmt = $pdo->query("
                SELECT 
                    wh.bin_id,
                    bs.location AS bin_location,
                    wh.user_id,
                    u.name AS user_name,
                    CONCAT(wh.date, ' ', TIME_FORMAT(wh.time, '%H:%i:%s')) AS timestamp,
                    wt.type_name AS waste_type,
                    wh.score
                FROM waste_history wh
                LEFT JOIN users u ON wh.user_id = u.id
                LEFT JOIN waste_types wt ON wh.type = wt.id
                LEFT JOIN bin_status bs ON wh.bin_id = bs.id
                ORDER BY wh.date DESC, wh.time DESC
                LIMIT 100
            ");
            $historyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [
                'status' => 'success',
                'history' => $historyData
            ];
            break;

        case 'system_health':
            $result = [
                'ai' => true,
                'camera' => false,
                'microbit' => true,
                'uptime' => date('Y-m-d H:i:s')
            ];
            break;

        default:
            http_response_code(400);
            $result = ['status' => 'error', 'message' => 'action ไม่ถูกต้อง'];
            break;
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดภายในระบบ: ' . $e->getMessage()]);
}