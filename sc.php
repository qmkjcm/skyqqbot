<?php
/**
 * 光之遇见积分排行榜图片生成器（布局修复版）
 * 修复文字与头像重叠问题
 */

error_reporting(E_ALL & ~E_DEPRECATED);

class RankImageGenerator
{
    private $imageWidth  = 900;
    private $imageHeight = 0;
    
    const ROW_HEIGHT     = 110;
    const PADDING_TOP    = 30;
    const PADDING_BOTTOM = 50;
    const TITLE_AREA     = 110;
    const AVATAR_SIZE    = 70;
    
    private $saveDir = './uploads/rankings/';
    private $baseUrl = '';
    private $rankData = [];
    private $fontPath = null;
    
    private function findChineseFont()
    {
        $fontDir = __DIR__ . '/fonts/';
        if (!is_dir($fontDir)) return false;
        $files = array_merge(glob($fontDir . '*.{ttc,ttf}', GLOB_BRACE));
        foreach ($files as $font) {
            if (is_readable($font)) return $font;
        }
        return false;
    }
    
    public function __construct($baseUrl = '', $saveDir = './uploads/rankings/')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->saveDir = rtrim($saveDir, '/') . '/';
        $this->fontPath = $this->findChineseFont();
    }
    
    private function fetchRankData()
    {
        $url = 'http://skyapi.qmkjcm.cn/rank.php';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !$response) return false;
        $data = json_decode($response, true);
        if ($data['code'] !== 200 || empty($data['data'])) return false;
        $this->rankData = $data['data'];
        return true;
    }
    
    private function drawGradientBackground($image)
    {
        $startRed = 20; $startGreen = 30; $startBlue = 70;
        $endRed = 80; $endGreen = 50; $endBlue = 120;
        for ($y = 0; $y < $this->imageHeight; $y++) {
            $ratio = $y / $this->imageHeight;
            $red = (int)($startRed + ($endRed - $startRed) * $ratio);
            $green = (int)($startGreen + ($endGreen - $startGreen) * $ratio);
            $blue = (int)($startBlue + ($endBlue - $startBlue) * $ratio);
            $color = imagecolorallocate($image, $red, $green, $blue);
            imageline($image, 0, $y, $this->imageWidth, $y, $color);
        }
        for ($i = 0; $i < 150; $i++) {
            $starX = mt_rand(0, $this->imageWidth);
            $starY = mt_rand(0, $this->imageHeight);
            $starColor = imagecolorallocatealpha($image, 255, 255, 200, mt_rand(0, 60));
            imagefilledellipse($image, $starX, $starY, mt_rand(1,2), mt_rand(1,2), $starColor);
        }
        $cloudGlow = imagecolorallocatealpha($image, 255, 200, 150, 70);
        imagefilledrectangle($image, 0, $this->imageHeight - 100, $this->imageWidth, $this->imageHeight, $cloudGlow);
    }
    
    private function drawTitle($image)
    {
        $yTitle = self::PADDING_TOP;
        $glow = imagecolorallocatealpha($image, 255, 215, 150, 40);
        imagefilledrectangle($image, 40, $yTitle, $this->imageWidth - 40, $yTitle + self::TITLE_AREA - 15, $glow);
        
        $mainTitle = "光之遇见积分排行榜";
        $titleColor = imagecolorallocate($image, 255, 235, 200);
        $fontSize = 34;
        $bbox = imagettfbbox($fontSize, 0, $this->fontPath, $mainTitle);
        $textWidth = $bbox[2] - $bbox[0];
        $x = ($this->imageWidth - $textWidth) / 2;
        imagettftext($image, $fontSize, 0, $x+2, $yTitle + 55, imagecolorallocatealpha($image, 0,0,0, 50), $this->fontPath, $mainTitle);
        imagettftext($image, $fontSize, 0, $x, $yTitle + 53, $titleColor, $this->fontPath, $mainTitle);
        
        $subText = "——  Light Awaits  ——";
        $subColor = imagecolorallocate($image, 200, 180, 150);
        $fontSize = 16;
        $bbox = imagettfbbox($fontSize, 0, $this->fontPath, $subText);
        $textWidth = $bbox[2] - $bbox[0];
        $x = ($this->imageWidth - $textWidth) / 2;
        imagettftext($image, $fontSize, 0, $x, $yTitle + 90, $subColor, $this->fontPath, $subText);
    }
    
    private function drawTextWithShadow($image, $x, $y, $text, $size, $color, $shadowColor = null)
    {
        if (!$shadowColor) $shadowColor = imagecolorallocatealpha($image, 0, 0, 0, 70);
        $mainColor = imagecolorallocate($image, $color[0], $color[1], $color[2]);
        imagettftext($image, $size, 0, $x+2, $y+2, $shadowColor, $this->fontPath, $text);
        imagettftext($image, $size, 0, $x, $y, $mainColor, $this->fontPath, $text);
    }
    
    private function drawRankRow($image, $index, $item)
    {
        $y = self::PADDING_TOP + self::TITLE_AREA + $index * self::ROW_HEIGHT;
        $rank = $index + 1;
        
        $cardBg = imagecolorallocatealpha($image, 20, 25, 45, 80);
        imagefilledrectangle($image, 30, $y, $this->imageWidth - 30, $y + self::ROW_HEIGHT - 15, $cardBg);
        $borderColor = imagecolorallocatealpha($image, 255, 215, 0, 60);
        imagerectangle($image, 30, $y, $this->imageWidth - 30, $y + self::ROW_HEIGHT - 15, $borderColor);
        
        // ========== 排名文字（左对齐，无圆底，避免重叠） ==========
        $rankText = "第" . $rank . "名";
        $rankX = 45;          // 横坐标左移
        $rankY = $y + 48;     // 垂直居中
        $this->drawTextWithShadow($image, $rankX, $rankY, $rankText, 26, [220, 220, 255]);
        
        // ========== 头像区域（右移，拉开与排名的距离） ==========
        $avatarX = 145;       // 原130 → 145，加大间距
        $avatarY = $y + 12;
        $glowColor = imagecolorallocatealpha($image, 255, 215, 0, 45);
        imagefilledellipse($image, $avatarX + self::AVATAR_SIZE/2, $avatarY + self::AVATAR_SIZE/2, 
                          self::AVATAR_SIZE + 12, self::AVATAR_SIZE + 12, $glowColor);
        
        $avatarImage = $this->loadCircularAvatar($item['user_identifier']);
        if ($avatarImage) {
            imagecopyresampled($image, $avatarImage, $avatarX, $avatarY, 0, 0,
                             self::AVATAR_SIZE, self::AVATAR_SIZE, self::AVATAR_SIZE, self::AVATAR_SIZE);
            imagedestroy($avatarImage);
        } else {
            $defaultColor = imagecolorallocate($image, 150, 150, 150);
            imagefilledellipse($image, $avatarX + self::AVATAR_SIZE/2, $avatarY + self::AVATAR_SIZE/2,
                              self::AVATAR_SIZE, self::AVATAR_SIZE, $defaultColor);
        }
        
        // ========== 积分（右对齐，微调位置避免拥挤） ==========
        $pointsText = number_format($item['points']) . " 分";
        $this->drawTextWithShadow($image, $this->imageWidth - 125, $y + 48, $pointsText, 28, [255, 220, 150]);
        
        // 小星星装饰（可选）
        $starX = $this->imageWidth - 165;
        $starY = $y + 42;
        $starColor = imagecolorallocate($image, 255, 220, 100);
        imagefilledellipse($image, $starX, $starY, 8, 8, $starColor);
        imagefilledellipse($image, $starX-3, $starY-2, 4, 4, $starColor);
        imagefilledellipse($image, $starX+3, $starY-2, 4, 4, $starColor);
    }
    
    private function loadCircularAvatar($userIdentifier)
    {
        $url = "https://thirdqq.qlogo.cn/qqapp/102581657/{$userIdentifier}/640";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !$imageData) return false;
        $srcImage = @imagecreatefromstring($imageData);
        if (!$srcImage) return false;
        $width = imagesx($srcImage);
        $height = imagesy($srcImage);
        $size = min($width, $height);
        $squareImage = imagecreatetruecolor($size, $size);
        $srcX = ($width - $size) / 2;
        $srcY = ($height - $size) / 2;
        imagecopyresampled($squareImage, $srcImage, 0, 0, $srcX, $srcY, $size, $size, $size, $size);
        imagedestroy($srcImage);
        $circleImage = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        imagealphablending($circleImage, false);
        imagesavealpha($circleImage, true);
        $transparent = imagecolorallocatealpha($circleImage, 0, 0, 0, 127);
        imagefilledrectangle($circleImage, 0, 0, self::AVATAR_SIZE, self::AVATAR_SIZE, $transparent);
        $resizedSquare = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        imagecopyresampled($resizedSquare, $squareImage, 0, 0, 0, 0,
                          self::AVATAR_SIZE, self::AVATAR_SIZE, $size, $size);
        imagedestroy($squareImage);
        $centerX = self::AVATAR_SIZE / 2;
        $centerY = self::AVATAR_SIZE / 2;
        $radius = self::AVATAR_SIZE / 2;
        for ($x = 0; $x < self::AVATAR_SIZE; $x++) {
            for ($y = 0; $y < self::AVATAR_SIZE; $y++) {
                $distance = sqrt(pow($x - $centerX, 2) + pow($y - $centerY, 2));
                if ($distance <= $radius) $color = imagecolorat($resizedSquare, $x, $y);
                else $color = $transparent;
                imagesetpixel($circleImage, $x, $y, $color);
            }
        }
        imagedestroy($resizedSquare);
        return $circleImage;
    }
    
    private function saveToLocal($image)
    {
        if (!is_dir($this->saveDir)) {
            if (!mkdir($this->saveDir, 0755, true)) return false;
        }
        $filename = 'rank_' . date('Ymd_His_') . uniqid() . '.png';
        $filePath = $this->saveDir . $filename;
        if (!imagepng($image, $filePath, 9)) return false;
        if (empty($this->baseUrl)) return $this->saveDir . $filename;
        else return rtrim($this->baseUrl, '/') . '/' . ltrim($this->saveDir, './') . $filename;
    }
    
    public function generate()
    {
        if (!$this->fontPath) {
            return ['code' => 500, 'msg' => '未找到 TTF/TTC 中文字体，请将字体文件放入 /fonts/ 目录', 'data' => null];
        }
        if (!$this->fetchRankData()) {
            return ['code' => 500, 'msg' => '获取排行榜数据失败', 'data' => null];
        }
        $rankCount = count($this->rankData);
        $this->imageHeight = self::PADDING_TOP + self::TITLE_AREA + $rankCount * self::ROW_HEIGHT + self::PADDING_BOTTOM;
        $image = imagecreatetruecolor($this->imageWidth, $this->imageHeight);
        $this->drawGradientBackground($image);
        $this->drawTitle($image);
        foreach ($this->rankData as $index => $item) {
            $this->drawRankRow($image, $index, $item);
        }
        $imageUrl = $this->saveToLocal($image);
        imagedestroy($image);
        if (!$imageUrl) {
            return ['code' => 500, 'msg' => '保存图片失败，请检查目录权限', 'data' => null];
        }
        return ['code' => 200, 'msg' => 'success', 'data' => ['image_url' => $imageUrl]];
    }
}

header('Content-Type: application/json; charset=utf-8');
$baseUrl = 'https://skyapi.qmkjcm.cn';
$saveDir = './uploads/rankings/';
$generator = new RankImageGenerator($baseUrl, $saveDir);
$result = $generator->generate();
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);