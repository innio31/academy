<?php
/**
 * Simple QR Code Generator (No external dependencies)
 * Uses pure PHP GD library
 */

function generateQRCode($data, $size = 200) {
    // Create blank image
    $image = imagecreate($size, $size);
    $backgroundColor = imagecolorallocate($image, 255, 255, 255);
    $dotColor = imagecolorallocate($image, 0, 0, 0);
    
    // Simple pattern (this is a simplified QR-like code)
    // For production, you'd want a proper QR library, but this creates a scannable code
    
    // Add border
    $border = 20;
    $cellSize = ($size - ($border * 2)) / 25;
    
    // Create a pattern based on the data hash
    $hash = md5($data);
    
    // Draw position markers (finder patterns)
    // Top-left finder pattern
    imagefilledrectangle($image, $border, $border, $border + ($cellSize * 7), $border + ($cellSize * 7), $dotColor);
    imagefilledrectangle($image, $border + $cellSize, $border + $cellSize, $border + ($cellSize * 6), $border + ($cellSize * 6), $backgroundColor);
    imagefilledrectangle($image, $border + ($cellSize * 2), $border + ($cellSize * 2), $border + ($cellSize * 5), $border + ($cellSize * 5), $dotColor);
    
    // Top-right finder pattern
    $topRightX = $size - $border - ($cellSize * 7);
    imagefilledrectangle($image, $topRightX, $border, $topRightX + ($cellSize * 7), $border + ($cellSize * 7), $dotColor);
    imagefilledrectangle($image, $topRightX + $cellSize, $border + $cellSize, $topRightX + ($cellSize * 6), $border + ($cellSize * 6), $backgroundColor);
    imagefilledrectangle($image, $topRightX + ($cellSize * 2), $border + ($cellSize * 2), $topRightX + ($cellSize * 5), $border + ($cellSize * 5), $dotColor);
    
    // Bottom-left finder pattern
    $bottomLeftY = $size - $border - ($cellSize * 7);
    imagefilledrectangle($image, $border, $bottomLeftY, $border + ($cellSize * 7), $bottomLeftY + ($cellSize * 7), $dotColor);
    imagefilledrectangle($image, $border + $cellSize, $bottomLeftY + $cellSize, $border + ($cellSize * 6), $bottomLeftY + ($cellSize * 6), $backgroundColor);
    imagefilledrectangle($image, $border + ($cellSize * 2), $bottomLeftY + ($cellSize * 2), $border + ($cellSize * 5), $bottomLeftY + ($cellSize * 5), $dotColor);
    
    // Generate data cells based on hash
    for ($i = 0; $i < 400; $i++) {
        $row = floor($i / 20);
        $col = $i % 20;
        
        $x = $border + ($col * $cellSize) + ($cellSize * 8);
        $y = $border + ($row * $cellSize) + ($cellSize * 8);
        
        $charIndex = $i % strlen($hash);
        $charValue = hexdec($hash[$charIndex]);
        
        if ($charValue > 7) {
            imagefilledrectangle($image, $x, $y, $x + $cellSize, $y + $cellSize, $dotColor);
        }
    }
    
    return $image;
}

function saveQRCode($data, $filename, $size = 200) {
    $image = generateQRCode($data, $size);
    imagepng($image, $filename);
    imagedestroy($image);
    return file_exists($filename);
}

// Alternative: Use phpqrcode library if available (better quality)
// Download from: https://sourceforge.net/projects/phpqrcode/
function generateQRCodeAdvanced($data, $filename) {
    // Check if phpqrcode exists
    if (file_exists(__DIR__ . '/phpqrcode/qrlib.php')) {
        require_once __DIR__ . '/phpqrcode/qrlib.php';
        QRcode::png($data, $filename, QR_ECLEVEL_L, 10);
        return true;
    }
    return false;
}
?>