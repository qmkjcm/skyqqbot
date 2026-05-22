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

    // 获取用户信息及兑换所需积分
    $stmt = $pdo->prepare("SELECT id, points FROM users WHERE user_identifier = ? FOR UPDATE");
    $stmt->execute([$identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) sendResponse(404, '用户不存在，请先签到');

    $required = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'redeem_points_required'")->fetchColumn();
    $required = $required ? (int)$required : 100;
    $userId = $user['id'];
    $currentPoints = (int)$user['points'];

    if ($currentPoints < $required) sendResponse(400, "积分不足，需要 {$required} 积分，当前 {$currentPoints}");

    $newPoints = $currentPoints - $required;

    // 生成唯一卡密
    do {
        $code = 'HM-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $stmt = $pdo->prepare("SELECT id FROM redeem_code WHERE code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());

    // 扣积分、插卡密、记录流水
    $pdo->prepare("UPDATE users SET points = ? WHERE id = ?")->execute([$newPoints, $userId]);
    $pdo->prepare("INSERT INTO redeem_code (code, user_id, status) VALUES (?, ?, 0)")->execute([$code, $userId]);
    $redeemId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO points_log (user_id, change_type, points, balance_after, related_id) VALUES (?, 'redeem', ?, ?, ?)")
        ->execute([$userId, -$required, $newPoints, $redeemId]);

    $pdo->commit();
    sendResponse(200, '兑换成功', ['code' => $code, 'remaining_points' => $newPoints]);
} catch (PDOException $e) {
    $pdo->rollBack();
    sendResponse(500, '兑换失败：' . $e->getMessage());
}
?>