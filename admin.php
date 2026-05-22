<?php
// 开启缓冲区，并清除可能已有的任何输出
if (ob_get_level()) ob_end_clean();
ob_start();

// 彻底关闭错误显示（防止警告输出干扰重定向），但记录错误日志
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

// 加载数据库配置（如果其中有输出或异常，会在这里被捕获）
try {
    require_once __DIR__ . '/db_config.php';
} catch (Exception $e) {
    // 捕获 db_config.php 中抛出的异常（比如连接失败）
    ob_end_clean();
    die('<h1>系统配置错误</h1><p>' . htmlspecialchars($e->getMessage()) . '</p><p>请检查数据库连接。</p>');
}

// 检查是否已安装（installed.lock 存在）
if (!file_exists(__DIR__ . '/installed.lock')) {
    ob_end_clean();
    die('系统尚未安装，请先运行 <a href="install.php">install.php</a> 完成安装。');
}

// 安全重定向函数（确保没有任何输出残留）
function safeRedirect($url) {
    while (ob_get_level()) ob_end_clean();
    header('Location: ' . $url);
    exit;
}

$isLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$loginError = '';

// 处理登录
if (!$isLoggedIn && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        safeRedirect('admin.php');
    } else {
        $loginError = '用户名或密码错误';
    }
}

// 注销
if (isset($_GET['logout'])) {
    session_destroy();
    safeRedirect('admin.php');
}

// 未登录时显示登录表单
if (!$isLoggedIn) {
    // 清除缓冲区并输出登录页
    while (ob_get_level()) ob_end_clean();
    ?><!DOCTYPE html>
    <html lang="zh-CN">
    <head><meta charset="UTF-8"><title>管理员登录</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
    <body><div class="container"><div class="row justify-content-center"><div class="col-md-4 mt-5"><div class="card"><div class="card-header bg-primary text-white">登录</div><div class="card-body">
    <?php if ($loginError): ?><div class="alert alert-danger"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
    <form method="post"><div class="mb-3"><label>用户名</label><input type="text" name="username" class="form-control" required></div><div class="mb-3"><label>密码</label><input type="password" name="password" class="form-control" required></div><button type="submit" name="login" class="btn btn-primary w-100">登录</button></form>
    </div></div></div></div></div></body></html><?php
    exit;
}

// ---------- 处理所有 POST 操作 ----------
$action = isset($_POST['action']) ? $_POST['action'] : '';

try {
    // 保存系统配置
    if ($action === 'save_config') {
        $pointsName = trim($_POST['points_name']);
        $signMin = intval($_POST['sign_points_min']);
        $signMax = intval($_POST['sign_points_max']);
        $redeemPoints = intval($_POST['redeem_points_required']);
        if ($signMin > $signMax) $signMax = $signMin;
        
        $pdo->prepare("REPLACE INTO system_config (config_key, config_value) VALUES ('points_name', ?)")->execute([$pointsName]);
        $pdo->prepare("REPLACE INTO system_config (config_key, config_value) VALUES ('sign_points_min', ?)")->execute([$signMin]);
        $pdo->prepare("REPLACE INTO system_config (config_key, config_value) VALUES ('sign_points_max', ?)")->execute([$signMax]);
        $pdo->prepare("REPLACE INTO system_config (config_key, config_value) VALUES ('redeem_points_required', ?)")->execute([$redeemPoints]);
        $_SESSION['admin_msg'] = ['type'=>'success', 'text'=>'配置已更新'];
        safeRedirect('admin.php?tab=config');
    }

    // 生成卡密
    if ($action === 'generate_codes') {
        $count = min(100, max(1, intval($_POST['code_count'])));
        $prefix = trim($_POST['code_prefix']) ?: 'HM';
        $generated = [];
        $pdo->beginTransaction();
        for ($i = 0; $i < $count; $i++) {
            do {
                $code = $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                $stmt = $pdo->prepare("SELECT id FROM redeem_code WHERE code = ?");
                $stmt->execute([$code]);
            } while ($stmt->fetch());
            $stmt = $pdo->prepare("INSERT INTO redeem_code (code, user_id, status) VALUES (?, 0, 0)");
            $stmt->execute([$code]);
            $generated[] = $code;
        }
        $pdo->commit();
        $_SESSION['admin_msg'] = ['type'=>'success', 'text'=>"成功生成 {$count} 个卡密"];
        $_SESSION['generated_codes'] = $generated;
        safeRedirect('admin.php?tab=codes');
    }

    // 删除卡密
    if ($action === 'delete_code' && isset($_POST['code_id'])) {
        $id = intval($_POST['code_id']);
        $pdo->prepare("DELETE FROM redeem_code WHERE id = ? AND user_id = 0")->execute([$id]);
        $_SESSION['admin_msg'] = ['type'=>'success', 'text'=>'卡密已删除'];
        safeRedirect('admin.php?tab=codes');
    }

    // 调整用户积分
    if ($action === 'adjust_points') {
        $userId = intval($_POST['user_id']);
        $pointsChange = intval($_POST['points_change']);
        $reason = trim($_POST['reason']);
        if ($pointsChange == 0) {
            $_SESSION['admin_msg'] = ['type'=>'warning', 'text'=>'积分变动不能为0'];
            safeRedirect('admin.php?tab=users');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT points FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) throw new Exception('用户不存在');
        $newPoints = max(0, $user['points'] + $pointsChange);
        $pdo->prepare("UPDATE users SET points = ? WHERE id = ?")->execute([$newPoints, $userId]);
        $pdo->prepare("INSERT INTO points_log (user_id, change_type, points, balance_after, related_id) VALUES (?, 'admin', ?, ?, ?)")
            ->execute([$userId, $pointsChange, $newPoints, 0]);
        $pdo->commit();
        $_SESSION['admin_msg'] = ['type'=>'success', 'text'=>"用户积分已变动 ".($pointsChange>=0?"+$pointsChange":"$pointsChange")."，当前积分: $newPoints"];
        safeRedirect('admin.php?tab=users');
    }

    // 修改管理员密码
    if ($action === 'change_password') {
        $old = $_POST['old_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];
        $username = $_SESSION['admin_username'];
        
        if ($new !== $confirm) {
            $_SESSION['admin_msg'] = ['type'=>'danger', 'text'=>'两次输入的新密码不一致'];
            safeRedirect('admin.php?tab=password');
        }
        $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($old, $admin['password_hash'])) {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE username = ?")->execute([$newHash, $username]);
            $_SESSION['admin_msg'] = ['type'=>'success', 'text'=>'密码已修改，请使用新密码重新登录'];
            session_destroy();
            safeRedirect('admin.php');
        } else {
            $_SESSION['admin_msg'] = ['type'=>'danger', 'text'=>'原密码错误'];
            safeRedirect('admin.php?tab=password');
        }
    }

    // 导入卡密
    if ($action === 'import_codes') {
        if (isset($_FILES['code_file']) && $_FILES['code_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['code_file']['tmp_name'];
            $content = file_get_contents($file);
            $lines = preg_split('/\r\n|\r|\n/', $content);
            $imported = 0;
            $failed = 0;
            $duplicate = 0;
            $pdo->beginTransaction();
            foreach ($lines as $line) {
                $code = trim($line);
                if (empty($code)) continue;
                $stmt = $pdo->prepare("SELECT id FROM redeem_code WHERE code = ?");
                $stmt->execute([$code]);
                if ($stmt->fetch()) {
                    $duplicate++;
                    continue;
                }
                $stmt = $pdo->prepare("INSERT INTO redeem_code (code, user_id, status) VALUES (?, 0, 0)");
                if ($stmt->execute([$code])) {
                    $imported++;
                } else {
                    $failed++;
                }
            }
            $pdo->commit();
            $_SESSION['admin_msg'] = ['type'=>'success', "text"=>"导入完成：成功 $imported 条，重复 $duplicate 条，失败 $failed 条"];
        } else {
            $_SESSION['admin_msg'] = ['type'=>'danger', 'text'=>'请上传文件'];
        }
        safeRedirect('admin.php?tab=codes');
    }

    // 导出卡密（注意：导出会直接输出文件，不需要重定向）
    if ($action === 'export_codes') {
        $status = isset($_POST['export_status']) ? intval($_POST['export_status']) : null;
        $sql = "SELECT code, user_id, status, created_at, used_at FROM redeem_code";
        if ($status !== null) {
            $sql .= " WHERE status = " . intval($status);
        }
        $stmt = $pdo->query($sql);
        $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="codes_export_'.date('Ymd_His').'.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['卡密', '用户ID', '状态', '生成时间', '使用时间']);
        foreach ($codes as $c) {
            fputcsv($output, [
                $c['code'],
                $c['user_id'] ?: '系统生成',
                $c['status'] == 0 ? '未使用' : '已使用',
                $c['created_at'],
                $c['used_at'] ?? ''
            ]);
        }
        fclose($output);
        exit;
    }

} catch (Exception $e) {
    // 捕获任何未预期的异常，显示错误消息但不破坏页面
    $_SESSION['admin_msg'] = ['type'=>'danger', 'text'=>'操作失败：' . $e->getMessage()];
    // 根据当前 tab 重定向，简单起见重定向到首页
    safeRedirect('admin.php');
}

// ---------- 正常页面显示 ----------
// 获取当前选项卡和消息
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$msg = isset($_SESSION['admin_msg']) ? $_SESSION['admin_msg'] : null;
unset($_SESSION['admin_msg']);
$generatedCodes = isset($_SESSION['generated_codes']) ? $_SESSION['generated_codes'] : [];
unset($_SESSION['generated_codes']);

// 读取系统配置
$config = [];
$stmt = $pdo->query("SELECT config_key, config_value FROM system_config");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $config[$row['config_key']] = $row['config_value'];
}
$pointsName = $config['points_name'] ?? '积分';
$signMin = $config['sign_points_min'] ?? 1;
$signMax = $config['sign_points_max'] ?? 3;
$redeemRequired = $config['redeem_points_required'] ?? 100;

// 卡密分页
$codesPage = isset($_GET['codes_page']) ? max(1, intval($_GET['codes_page'])) : 1;
$codesPerPage = 20;
$codesOffset = ($codesPage - 1) * $codesPerPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM redeem_code");
$stmt->execute();
$totalCodes = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT * FROM redeem_code ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $codesPerPage, PDO::PARAM_INT);
$stmt->bindValue(2, $codesOffset, PDO::PARAM_INT);
$stmt->execute();
$codes = $stmt->fetchAll();

// 用户分页
$usersPage = isset($_GET['users_page']) ? max(1, intval($_GET['users_page'])) : 1;
$usersPerPage = 20;
$usersOffset = ($usersPage - 1) * $usersPerPage;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
$totalUsers = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT id, user_identifier, points, created_at FROM users ORDER BY points DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $usersPerPage, PDO::PARAM_INT);
$stmt->bindValue(2, $usersOffset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

// 仪表盘数据
$totalUsersAll = $totalUsers;
$stmt = $pdo->query("SELECT COUNT(*) FROM sign_log WHERE sign_date = CURDATE()");
$todaySignCount = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT SUM(points) FROM users");
$totalPoints = $stmt->fetchColumn() ?: 0;
$stmt = $pdo->query("SELECT COUNT(*) FROM redeem_code WHERE status = 0");
$unusedCodes = $stmt->fetchColumn();

// 清空缓冲区并输出页面
while (ob_get_level()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>光之遇见签到系统 - 管理后台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-size: .875rem; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; bottom: 0; left: 0; z-index: 100; padding: 48px 0 0; box-shadow: inset -1px 0 0 rgba(0,0,0,.1); background: white; }
        .sidebar-sticky { position: relative; top: 0; height: calc(100vh - 48px); padding-top: .5rem; overflow-x: hidden; overflow-y: auto; }
        .sidebar .nav-link { font-weight: 500; color: #333; }
        .sidebar .nav-link .fa { margin-right: 4px; }
        .sidebar .nav-link.active { color: #0d6efd; background: #e9ecef; }
        main { padding-top: 20px; }
        .table-responsive { overflow-x: auto; }
        .avatar-img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; background: #f0f0f0; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark sticky-top flex-md-nowrap p-0 shadow">
    <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3" href="#">光之遇见签到系统</a>
    <div class="navbar-nav ms-auto flex-row">
        <a class="nav-link text-white" href="?logout=1"><i class="fas fa-sign-out-alt"></i> 退出</a>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
            <div class="sidebar-sticky">
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link <?= $tab=='dashboard'?'active':'' ?>" href="?tab=dashboard"><i class="fas fa-tachometer-alt"></i> 仪表盘</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab=='config'?'active':'' ?>" href="?tab=config"><i class="fas fa-sliders-h"></i> 系统配置</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab=='codes'?'active':'' ?>" href="?tab=codes"><i class="fas fa-ticket-alt"></i> 卡密管理</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab=='users'?'active':'' ?>" href="?tab=users"><i class="fas fa-users"></i> 用户管理</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab=='password'?'active':'' ?>" href="?tab=password"><i class="fas fa-key"></i> 修改密码</a></li>
                </ul>
            </div>
        </nav>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg['type'] ?> alert-dismissible fade show mt-3" role="alert">
                    <?= htmlspecialchars($msg['text']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($tab == 'dashboard'): ?>
                <!-- 仪表盘内容（保持不变） -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom"><h1 class="h2">仪表盘</h1></div>
                <div class="row">
                    <div class="col-md-3"><div class="card text-white bg-primary mb-3"><div class="card-body"><h5 class="card-title">总用户数</h5><p class="card-text display-6"><?= $totalUsersAll ?></p></div></div></div>
                    <div class="col-md-3"><div class="card text-white bg-success mb-3"><div class="card-body"><h5 class="card-title">今日签到</h5><p class="card-text display-6"><?= $todaySignCount ?></p></div></div></div>
                    <div class="col-md-3"><div class="card text-white bg-warning mb-3"><div class="card-body"><h5 class="card-title">总积分池</h5><p class="card-text display-6"><?= $totalPoints ?></p></div></div></div>
                    <div class="col-md-3"><div class="card text-white bg-info mb-3"><div class="card-body"><h5 class="card-title">未使用卡密</h5><p class="card-text display-6"><?= $unusedCodes ?></p></div></div></div>
                </div>

            <?php elseif ($tab == 'config'): ?>
                <!-- 系统配置表单（保持不变） -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom"><h1 class="h2">系统配置</h1></div>
                <form method="post" class="col-md-6">
                    <input type="hidden" name="action" value="save_config">
                    <div class="mb-3"><label>积分名称</label><input type="text" name="points_name" class="form-control" value="<?= htmlspecialchars($pointsName) ?>" required></div>
                    <div class="mb-3"><label>签到获得最小积分</label><input type="number" name="sign_points_min" class="form-control" value="<?= $signMin ?>" min="0" required></div>
                    <div class="mb-3"><label>签到获得最大积分</label><input type="number" name="sign_points_max" class="form-control" value="<?= $signMax ?>" min="0" required></div>
                    <div class="mb-3"><label>兑换一次卡密所需积分</label><input type="number" name="redeem_points_required" class="form-control" value="<?= $redeemRequired ?>" min="1" required></div>
                    <button type="submit" class="btn btn-primary">保存配置</button>
                </form>

            <?php elseif ($tab == 'codes'): ?>
                <!-- 卡密管理（保持不变，略） -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom"><h1 class="h2">卡密管理</h1></div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="card mb-4"><div class="card-header">生成新卡密</div><div class="card-body">
                            <form method="post"><input type="hidden" name="action" value="generate_codes"><div class="mb-2"><label>前缀</label><input type="text" name="code_prefix" class="form-control" value="HM"></div><div class="mb-2"><label>数量 (1-100)</label><input type="number" name="code_count" class="form-control" value="5" min="1" max="100"></div><button type="submit" class="btn btn-success">生成卡密</button></form>
                            <?php if ($generatedCodes): ?><hr><label>新生成的卡密：</label><textarea class="form-control mt-1" rows="4" readonly><?= implode("\n", $generatedCodes) ?></textarea><?php endif; ?>
                        </div></div>
                        <div class="card mb-4"><div class="card-header">导入卡密</div><div class="card-body"><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="import_codes"><div class="mb-2"><input type="file" name="code_file" class="form-control" accept=".csv,.txt" required></div><button type="submit" class="btn btn-info">导入卡密</button></form></div></div>
                        <div class="card"><div class="card-header">导出卡密</div><div class="card-body"><form method="post" target="_blank"><input type="hidden" name="action" value="export_codes"><div class="mb-2"><select name="export_status" class="form-select"><option value="">全部卡密</option><option value="0">仅未使用</option><option value="1">仅已使用</option></select></div><button type="submit" class="btn btn-secondary">导出为 CSV</button></form></div></div>
                    </div>
                    <div class="col-md-8">
                        <div class="card"><div class="card-header">卡密列表</div><div class="card-body table-responsive">
                            <table class="table table-sm table-bordered"><thead><tr><th>ID</th><th>卡密</th><th>所属用户ID</th><th>状态</th><th>生成时间</th><th>操作</th></tr></thead>
                            <tbody><?php foreach ($codes as $c): ?><tr><td><?= $c['id'] ?></td><td><code><?= htmlspecialchars($c['code']) ?></code></td><td><?= $c['user_id'] ? $c['user_id'] : '系统生成' ?></td><td><?= $c['status']==0 ? '<span class="badge bg-success">未使用</span>' : '<span class="badge bg-secondary">已使用</span>' ?></td><td><?= $c['created_at'] ?></td><td><?php if ($c['user_id'] == 0 && $c['status']==0): ?><form method="post" onsubmit="return confirm('确定删除该卡密？')" style="display:inline"><input type="hidden" name="action" value="delete_code"><input type="hidden" name="code_id" value="<?= $c['id'] ?>"><button type="submit" class="btn btn-sm btn-danger">删除</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody>
                            </table>
                            <?php if ($totalCodes > $codesPerPage): ?>
                            <nav><ul class="pagination pagination-sm"><?php for($i=1;$i<=ceil($totalCodes/$codesPerPage);$i++): ?><li class="page-item <?= $i==$codesPage?'active':'' ?>"><a class="page-link" href="?tab=codes&codes_page=<?= $i ?>"><?= $i ?></a></li><?php endfor; ?></ul></nav>
                            <?php endif; ?>
                        </div></div>
                    </div>
                </div>

            <?php elseif ($tab == 'users'): ?>
                <!-- 用户管理（保持不变，包含头像） -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom"><h1 class="h2">用户管理</h1></div>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead><tr><th>头像</th><th>ID</th><th>用户标识</th><th><?= htmlspecialchars($pointsName) ?></th><th>注册时间</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><img src="https://thirdqq.qlogo.cn/qqapp/102581657/<?= urlencode($u['user_identifier']) ?>/640" class="avatar-img" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'40\' height=\'40\' viewBox=\'0 0 40 40\'%3E%3Ccircle cx=\'20\' cy=\'20\' r=\'20\' fill=\'%23ccc\'/%3E%3Ctext x=\'20\' y=\'25\' text-anchor=\'middle\' fill=\'%23666\' font-size=\'12\' dy=\'.3em\'%3E?%3C/text%3E%3C/svg%3E'; this.onerror=null;"></td>
                                <td><?= $u['id'] ?></td>
                                <td><?= htmlspecialchars($u['user_identifier']) ?></td>
                                <td><?= $u['points'] ?></td>
                                <td><?= $u['created_at'] ?></td>
                                <td><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#adjustModal" data-user-id="<?= $u['id'] ?>">调整积分</button>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewLogsModal" data-user-id="<?= $u['id'] ?>" data-user-name="<?= htmlspecialchars($u['user_identifier']) ?>">流水</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($totalUsers > $usersPerPage): ?>
                    <nav><ul class="pagination pagination-sm"><?php for($i=1;$i<=ceil($totalUsers/$usersPerPage);$i++): ?><li class="page-item <?= $i==$usersPage?'active':'' ?>"><a class="page-link" href="?tab=users&users_page=<?= $i ?>"><?= $i ?></a></li><?php endfor; ?></ul></nav>
                    <?php endif; ?>
                </div>

                <!-- 调整积分模态框 -->
                <div class="modal fade" id="adjustModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">调整积分</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <form method="post"><input type="hidden" name="action" value="adjust_points"><input type="hidden" name="user_id" id="adjust_user_id"><div class="modal-body"><div class="mb-2"><label>变动积分（正数增加，负数减少）</label><input type="number" name="points_change" class="form-control" required></div><div class="mb-2"><label>原因备注</label><input type="text" name="reason" class="form-control"></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary">确认</button><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button></div></form></div></div></div>

                <!-- 查看流水模态框 -->
                <div class="modal fade" id="viewLogsModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">积分流水</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="logsContent">加载中...</div></div></div></div>

            <?php elseif ($tab == 'password'): ?>
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom"><h1 class="h2">修改管理员密码</h1></div>
                <form method="post" class="col-md-6"><input type="hidden" name="action" value="change_password"><div class="mb-3"><label>原密码</label><input type="password" name="old_password" class="form-control" required></div><div class="mb-3"><label>新密码</label><input type="password" name="new_password" class="form-control" required></div><div class="mb-3"><label>确认新密码</label><input type="password" name="confirm_password" class="form-control" required></div><button type="submit" class="btn btn-primary">修改密码</button></form>
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const adjustModal = document.getElementById('adjustModal');
    if (adjustModal) {
        adjustModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const userId = button.getAttribute('data-user-id');
            document.getElementById('adjust_user_id').value = userId;
        });
    }
    const logsModal = document.getElementById('viewLogsModal');
    if (logsModal) {
        logsModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const userName = button.getAttribute('data-user-name');
            const modalTitle = logsModal.querySelector('.modal-title');
            modalTitle.textContent = `${userName} 的积分流水`;
            const logsBody = document.getElementById('logsContent');
            logsBody.innerHTML = '加载中...';
            fetch(`get_points_log.php?user_identifier=${encodeURIComponent(userName)}&page=1&page_size=50`)
                .then(res => res.json())
                .then(data => {
                    if (data.code !== 200 || !data.data.list.length) {
                        logsBody.innerHTML = '<div class="alert alert-info">暂无流水记录</div>';
                        return;
                    }
                    let html = '<table class="table table-sm"><thead><tr><th>时间</th><th>类型</th><th>变动</th><th>余额</th><th>关联信息</th></tr></thead><tbody>';
                    data.data.list.forEach(log => {
                        let type = log.change_type === 'sign' ? '签到' : (log.change_type === 'redeem' ? '兑换' : '管理员调整');
                        let sign = log.points >=0 ? `+${log.points}` : log.points;
                        html += `<tr><td>${log.created_at}</td><td>${type}</td><td>${sign}</td><td>${log.balance_after}</td><td>${log.extra_info || ''}</td></tr>`;
                    });
                    html += '</tbody></table>';
                    logsBody.innerHTML = html;
                })
                .catch(() => logsBody.innerHTML = '<div class="alert alert-danger">加载失败</div>');
        });
    }
</script>
</body>
</html>