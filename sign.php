<?php
require_once 'db_config.php';
header('Content-Type: application/json; charset=utf-8');

function sendResponse($code, $msg, $data = null) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$identifier = isset($_REQUEST['user_identifier']) ? trim($_REQUEST['user_identifier']) : '';
if ($identifier === '') sendResponse(400, '缺少 user_identifier');

try {
    $pdo->beginTransaction();

    // 获取或创建用户（行锁）
    $stmt = $pdo->prepare("SELECT id, points FROM users WHERE user_identifier = ? FOR UPDATE");
    $stmt->execute([$identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (user_identifier, points) VALUES (?, 0)");
        $stmt->execute([$identifier]);
        $userId = $pdo->lastInsertId();
        $currentPoints = 0;
    } else {
        $userId = $user['id'];
        $currentPoints = (int)$user['points'];
    }

    // 检查今日是否已签到
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT id FROM sign_log WHERE user_id = ? AND sign_date = ?");
    $stmt->execute([$userId, $today]);
    if ($stmt->fetch()) {
        $pdo->rollBack();
        sendResponse(400, '今日已签到，请明天再来');
    }

    // 读取签到积分范围
    $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('sign_points_min','sign_points_max')");
    $stmt->execute();
    $cfg = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $min = isset($cfg['sign_points_min']) ? (int)$cfg['sign_points_min'] : 1;
    $max = isset($cfg['sign_points_max']) ? (int)$cfg['sign_points_max'] : 3;
    $earnPoints = rand($min, $max);
    $newPoints = $currentPoints + $earnPoints;

    // 插入签到记录
    $stmt = $pdo->prepare("INSERT INTO sign_log (user_id, sign_date) VALUES (?, ?)");
    $stmt->execute([$userId, $today]);
    $signId = $pdo->lastInsertId();

    // 更新积分
    $pdo->prepare("UPDATE users SET points = ? WHERE id = ?")->execute([$newPoints, $userId]);

    // 记录流水
    $pdo->prepare("INSERT INTO points_log (user_id, change_type, points, balance_after, related_id) VALUES (?, 'sign', ?, ?, ?)")
        ->execute([$userId, $earnPoints, $newPoints, $signId]);

    $pdo->commit();
    sendResponse(200, '签到成功', ['earned_points' => $earnPoints, 'total_points' => $newPoints]);
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->errorInfo[1] == 1062) sendResponse(400, '今日已签到');
    else sendResponse(500, '签到失败：' . $e->getMessage());
}
?>