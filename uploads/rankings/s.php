<?php
/**
 * 自动清空当前文件夹下的所有图片文件
 * 注意：此脚本会直接删除文件，无法恢复，请谨慎使用！
 * 建议在运行前备份重要数据。
 */

// 设置纯文本输出（适合命令行或浏览器直接访问）
header('Content-Type: text/plain; charset=utf-8');

// 需要删除的图片扩展名列表（可根据需要增减）
$imageExtensions = [
    'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp',
    'ico', 'svg', 'tiff', 'tif', 'avif', 'heic'
];

// 当前脚本所在目录（即“当前文件夹”）
$targetDir = __DIR__;

if (!is_dir($targetDir)) {
    die("错误：目录 {$targetDir} 不存在或不可访问。\n");
}

// 打开目录并读取所有文件
$files = scandir($targetDir);
if ($files === false) {
    die("错误：无法扫描目录 {$targetDir}。\n");
}

$deletedCount = 0;
$failedFiles = [];

foreach ($files as $file) {
    // 忽略 . 和 .. 以及子目录
    if ($file === '.' || $file === '..') {
        continue;
    }
    $filePath = $targetDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($filePath)) {
        continue; // 跳过非文件（例如子目录）
    }

    // 检查扩展名是否匹配图片格式
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, $imageExtensions)) {
        // 尝试删除文件（@ 抑制错误，自行记录）
        if (@unlink($filePath)) {
            $deletedCount++;
            echo "已删除: {$file}\n";
        } else {
            $failedFiles[] = $file;
            echo "删除失败: {$file} (权限不足或文件被占用)\n";
        }
    }
}

// 输出统计结果
echo "\n操作完成。成功删除 {$deletedCount} 个图片文件。\n";
if (!empty($failedFiles)) {
    echo "删除失败的文件 (" . count($failedFiles) . " 个): " . implode(', ', $failedFiles) . "\n";
}