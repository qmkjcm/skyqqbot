<?php
require_once 'db_config.php';
header('Content-Type: application/json; charset=utf-8');

$identifier = isset($_GET['user_identifier']) ? trim($_GET['user_identifier']) : '';
if ($identifier === '') die(json_encode(['code'=>400,'msg'=>'缺少 user_identifier']));

$stmt = $pdo->prepare("SELECT points FROM users WHERE user_identifier = ?");
$stmt->execute([$identifier]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) echo json_encode(['code'=>404,'msg'=>'用户不存在']);
else echo json_encode(['code'=>200,'msg'=>'success','data'=>['points'=>(int)$user['points']]]);
?>