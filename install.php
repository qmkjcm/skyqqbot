<?php
if (file_exists(__DIR__ . '/installed.lock')) {
    die('<h2>系统已安装</h2><p>如需重新安装，请先删除 <code>installed.lock</code> 文件。</p>');
}

$step = isset($_POST['step']) ? intval($_POST['step']) : 1;
$error = '';

if ($step == 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host']);
    $dbName = trim($_POST['db_name']);
    $dbUser = trim($_POST['db_user']);
    $dbPass = $_POST['db_pass'];
    $dbPort = trim($_POST['db_port']) ?: 3306;

    if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
        $error = '请填写完整的数据库连接信息（主机、数据库名、用户名）';
        $step = 1;
    } else {
        try {
            $dsn = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbName`");

            // 创建所有表
            $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_identifier` VARCHAR(100) NOT NULL,
                `points` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_identifier` (`user_identifier`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `sign_log` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `sign_date` DATE NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_date` (`user_id`, `sign_date`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `points_log` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `change_type` ENUM('sign', 'redeem', 'admin') NOT NULL,
                `points` INT NOT NULL,
                `balance_after` INT UNSIGNED NOT NULL,
                `related_id` INT UNSIGNED DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `redeem_code` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(32) NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `status` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `used_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_code` (`code`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `system_config` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `config_key` VARCHAR(50) NOT NULL,
                `config_value` TEXT NOT NULL,
                `description` VARCHAR(255) DEFAULT NULL,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_key` (`config_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_users` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `username` VARCHAR(50) NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // 插入默认配置
            $pdo->exec("INSERT IGNORE INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
                ('points_name', '积分', '积分的显示名称'),
                ('sign_points_min', '1', '签到获得最小积分'),
                ('sign_points_max', '3', '签到获得最大积分'),
                ('redeem_points_required', '100', '兑换一次卡密所需积分')");

            // 插入默认管理员 (密码 admin123)
            $defaultHash = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->exec("INSERT IGNORE INTO `admin_users` (`username`, `password_hash`) VALUES ('admin', '$defaultHash')");

            // 生成 db_config.php
            $configContent = "<?php
\$dbHost = '$dbHost';
\$dbName = '$dbName';
\$dbUser = '$dbUser';
\$dbPass = '$dbPass';
\$dbPort = $dbPort;

try {
    \$pdo = new PDO(\"mysql:host=\$dbHost;port=\$dbPort;dbname=\$dbName;charset=utf8mb4\", \$dbUser, \$dbPass);
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \$pdo->exec(\"SET time_zone = '+08:00'\");
} catch (PDOException \$e) {
    die(json_encode(['code' => 500, 'msg' => '数据库连接失败：' . \$e->getMessage()]));
}
?>";
            file_put_contents(__DIR__ . '/db_config.php', $configContent);
            file_put_contents(__DIR__ . '/installed.lock', date('Y-m-d H:i:s'));
            $success = true;
        } catch (PDOException $e) {
            $error = '数据库操作失败：' . $e->getMessage();
            $step = 1;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>安装向导</title><style>body{font-family:Arial;max-width:600px;margin:50px auto;}</style></head>
<body>
<h1>光之娱乐签到系统 - 安装</h1>
<?php if (isset($success) && $success): ?>
    <div style="color:green">✅ 安装成功！<br>配置文件已生成。请立即删除 install.php 文件。<br><a href="admin.php">进入后台</a></div>
<?php elseif ($error): ?>
    <div style="color:red">❌ 安装失败：<?= htmlspecialchars($error) ?></div>
    <?php $step = 1; ?>
<?php endif; ?>
<?php if ($step == 1 && !isset($success)): ?>
    <form method="post">
        <input type="hidden" name="step" value="2">
        <p><input type="text" name="db_host" placeholder="数据库主机" value="127.0.0.1" required></p>
        <p><input type="text" name="db_port" placeholder="端口" value="3306"></p>
        <p><input type="text" name="db_name" placeholder="数据库名" required></p>
        <p><input type="text" name="db_user" placeholder="用户名" required></p>
        <p><input type="password" name="db_pass" placeholder="密码"></p>
        <button type="submit">开始安装</button>
    </form>
<?php endif; ?>
</body>
</html>