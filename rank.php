<?php
require_once 'db_config.php';
header('Content-Type: application/json; charset=utf-8');

$stmt = $pdo->query("SELECT user_identifier, points FROM users ORDER BY points DESC LIMIT 6");
$rank = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['code'=>200,'msg'=>'success','data'=>$rank], JSON_UNESCAPED_UNICODE);
?>