<?php
require_once 'db_config.php';
header('Content-Type: application/json; charset=utf-8');

$identifier = isset($_GET['user_identifier']) ? trim($_GET['user_identifier']) : '';
if (!$identifier) die(json_encode(['code'=>400,'msg'=>'缺少 user_identifier']));
$page = max(1, isset($_GET['page']) ? intval($_GET['page']) : 1);
$pageSize = min(50, isset($_GET['page_size']) ? intval($_GET['page_size']) : 10);
$offset = ($page-1)*$pageSize;

$stmt = $pdo->prepare("SELECT id FROM users WHERE user_identifier = ?");
$stmt->execute([$identifier]);
$user = $stmt->fetch();
if (!$user) die(json_encode(['code'=>404,'msg'=>'用户不存在']));
$userId = $user['id'];

$total = $pdo->prepare("SELECT COUNT(*) FROM points_log WHERE user_id = ?");
$total->execute([$userId]);
$totalCount = $total->fetchColumn();

$sql = "SELECT l.id, l.change_type, l.points, l.balance_after, l.created_at,
        CASE 
            WHEN l.change_type = 'sign' THEN (SELECT sign_date FROM sign_log WHERE id = l.related_id)
            WHEN l.change_type = 'redeem' THEN (SELECT code FROM redeem_code WHERE id = l.related_id)
            ELSE ''
        END AS extra_info
        FROM points_log l WHERE l.user_id = ?
        ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$userId, $pageSize, $offset]);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['code'=>200,'msg'=>'获取成功','data'=>['total'=>(int)$totalCount,'page'=>$page,'page_size'=>$pageSize,'list'=>$list]], JSON_UNESCAPED_UNICODE);
?>