<?php
// includes/image_helper.php - Image Compression & Optimization

/**
 * Compress and resize image to meet size requirements (<100KB)
 * 
 * @param string $source_path Source image path (uploaded file)
 * @param string $target_path Target path to save compressed image
 * @param int $max_size_kb Maximum file size in KB (default 100)
 * @param int $max_width Maximum width in pixels
 * @param int $max_height Maximum height in pixels
 * @param int $quality Initial quality (0-100)
 * @return array ['success' => bool, 'path' => string, 'size' => int, 'error' => string]
 */
function compressImage($source_path, $target_path, $max_size_kb = 100, $max_width = 800, $max_height = 800, $quality = 85)
{
    // Check if source exists
    if (!file_exists($source_path)) {
        return ['success' => false, 'error' => 'Source image not found'];
    }
    
    // Get image info
    $image_info = getimagesize($source_path);
    if (!$image_info) {
        return ['success' => false, 'error' => 'Invalid image file'];
    }
    
    $width = $image_info[0];
    $height = $image_info[1];
    $type = $image_info[2];
    $mime = $image_info['mime'];
    
    // Calculate new dimensions (maintain aspect ratio)
    $ratio = min($max_width / $width, $max_height / $height);
    if ($ratio < 1) {
        $new_width = round($width * $ratio);
        $new_height = round($height * $ratio);
    } else {
        $new_width = $width;
        $new_height = $height;
    }
    
    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = imagecreatefromjpeg($source_path);
            break;
        case IMAGETYPE_PNG:
            $src = imagecreatefrompng($source_path);
            break;
        case IMAGETYPE_GIF:
            $src = imagecreatefromgif($source_path);
            break;
        case IMAGETYPE_WEBP:
            $src = imagecreatefromwebp($source_path);
            break;
        default:
            return ['success' => false, 'error' => 'Unsupported image type'];
    }
    
    if (!$src) {
        return ['success' => false, 'error' => 'Failed to create image resource'];
    }
    
    // Create new image
    $dst = imagecreatetruecolor($new_width, $new_height);
    
    // Preserve transparency for PNG
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $new_width, $new_height, $transparent);
    }
    
    // Resize
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    // Progressive compression to meet size requirement
    $temp_file = $target_path . '.tmp';
    $current_quality = $quality;
    $file_size = PHP_INT_MAX;
    $min_quality = 20;
    
    do {
        // Save with current quality
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($dst, $temp_file, $current_quality);
                break;
            case IMAGETYPE_PNG:
                // PNG quality is 0-9 (0 = no compression, 9 = max)
                $png_quality = 9 - round(($current_quality / 100) * 9);
                $png_quality = max(0, min(9, $png_quality));
                imagepng($dst, $temp_file, $png_quality);
                break;
            case IMAGETYPE_GIF:
                imagegif($dst, $temp_file);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($dst, $temp_file, $current_quality);
                break;
        }
        
        $file_size = filesize($temp_file) / 1024; // Size in KB
        $current_quality -= 10;
        
    } while ($file_size > $max_size_kb && $current_quality >= $min_quality);
    
    // If still too large after max compression, resize further
    if ($file_size > $max_size_kb && $new_width > 200) {
        imagedestroy($dst);
        
        // Recursively compress with smaller dimensions
        $new_max_width = round($max_width * 0.7);
        $new_max_height = round($max_height * 0.7);
        
        return compressImage($source_path, $target_path, $max_size_kb, $new_max_width, $new_max_height, $quality);
    }
    
    // Rename temp file to target
    if (rename($temp_file, $target_path)) {
        $final_size = filesize($target_path) / 1024;
        
        // Clean up
        imagedestroy($src);
        imagedestroy($dst);
        
        return [
            'success' => true,
            'path' => $target_path,
            'size' => round($final_size, 2),
            'original_size' => round(filesize($source_path) / 1024, 2),
            'compression_ratio' => round(($final_size / (filesize($source_path) / 1024)) * 100, 1)
        ];
    }
    
    // Clean up
    imagedestroy($src);
    imagedestroy($dst);
    
    return ['success' => false, 'error' => 'Failed to save compressed image'];
}

/**
 * Upload and compress image from file input
 * 
 * @param array $file $_FILES['input_name']
 * @param string $upload_dir Directory to upload to
 * @param string $prefix File prefix (e.g., 'proof_', 'avatar_')
 * @param int $max_size_kb Maximum file size in KB
 * @return array ['success' => bool, 'path' => string, 'error' => string]
 */
function uploadAndCompressImage($file, $upload_dir, $prefix = '', $max_size_kb = 100)
{
    // Validate file
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }
    
    // Check file size (before compression)
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB max original
        return ['success' => false, 'error' => 'File too large. Max 5MB.'];
    }
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'error' => 'Invalid file type. Allowed: JPEG, PNG, GIF, WEBP'];
    }
    
    // Create directory if not exists
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $extension = 'jpg';
    }
    
    $filename = $prefix . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $temp_path = $upload_dir . 'temp_' . $filename;
    $final_path = $upload_dir . $filename;
    
    // Move uploaded file to temp location
    if (!move_uploaded_file($file['tmp_name'], $temp_path)) {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
    
    // Compress image
    $result = compressImage($temp_path, $final_path, $max_size_kb, 800, 800, 85);
    
    // Delete temp file
    if (file_exists($temp_path)) {
        unlink($temp_path);
    }
    
    if ($result['success']) {
        // Convert to web-accessible path
        $web_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $final_path);
        return [
            'success' => true,
            'path' => $web_path,
            'size' => $result['size'],
            'original_size' => $result['original_size']
        ];
    }
    
    return $result;
}

/**
 * Upload base64 image (from camera capture)
 * 
 * @param string $base64_data Base64 encoded image data
 * @param string $upload_dir Directory to upload to
 * @param string $prefix File prefix
 * @param int $max_size_kb Maximum file size in KB
 * @return array
 */
function uploadBase64Image($base64_data, $upload_dir, $prefix = '', $max_size_kb = 100)
{
    // Extract base64 data
    if (preg_match('/^data:image\/(\w+);base64,/', $base64_data, $matches)) {
        $image_type = $matches[1];
        $base64_data = substr($base64_data, strpos($base64_data, ',') + 1);
    } else {
        return ['success' => false, 'error' => 'Invalid image data'];
    }
    
    // Decode base64
    $image_data = base64_decode($base64_data);
    if (!$image_data) {
        return ['success' => false, 'error' => 'Failed to decode image'];
    }
    
    // Create directory if not exists
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Save temp file
    $extension = $image_type === 'jpeg' ? 'jpg' : $image_type;
    $filename = $prefix . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $temp_path = $upload_dir . 'temp_' . $filename;
    
    file_put_contents($temp_path, $image_data);
    
    // Compress
    $final_path = $upload_dir . $filename;
    $result = compressImage($temp_path, $final_path, $max_size_kb, 800, 800, 85);
    
    // Delete temp
    if (file_exists($temp_path)) {
        unlink($temp_path);
    }
    
    if ($result['success']) {
        $web_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $final_path);
        return [
            'success' => true,
            'path' => $web_path,
            'size' => $result['size']
        ];
    }
    
    return $result;
}

/**
 * Delete an image file
 * 
 * @param string $path Image path
 * @return bool
 */
function deleteImage($path)
{
    $full_path = $_SERVER['DOCUMENT_ROOT'] . $path;
    if (file_exists($full_path)) {
        return unlink($full_path);
    }
    return false;
}

/**
 * Get image as data URI for display
 * 
 * @param string $path Image path
 * @return string|null
 */
function getImageDataURI($path)
{
    $full_path = $_SERVER['DOCUMENT_ROOT'] . $path;
    if (!file_exists($full_path)) {
        return null;
    }
    
    $image_data = file_get_contents($full_path);
    $base64 = base64_encode($image_data);
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $full_path);
    finfo_close($finfo);
    
    return "data:{$mime};base64,{$base64}";
}