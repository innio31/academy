<?php
// generate-icons.php - Run once to generate all icon sizes
// Access: https://acad.com.ng/generate-icons.php

// First, upload your source logo to: /assets/logos/cba-source.png
$source_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/logos/cba-source.png';
$output_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/logos/';

// Check if source exists
if (!file_exists($source_path)) {
    die('Source logo not found. Please upload your logo to: /assets/logos/cba-source.png');
}

// Create output directory if not exists
if (!file_exists($output_dir)) {
    mkdir($output_dir, 0755, true);
}

// Load source image
$source = imagecreatefrompng($source_path);
if (!$source) {
    // Try jpg
    $source = imagecreatefromjpeg($source_path);
}
if (!$source) {
    die('Could not load source image. Please use PNG or JPG format.');
}

// Get source dimensions
$src_width = imagesx($source);
$src_height = imagesy($source);

// Sizes needed for PWA
$sizes = [72, 96, 128, 144, 152, 192, 384, 512];

echo "<h2>Generating PWA Icons for Great Optimist School</h2>";
echo "<pre>";

foreach ($sizes as $size) {
    // Create blank canvas with navy background (#1B2A4A)
    $canvas = imagecreatetruecolor($size, $size);
    $navy = imagecolorallocate($canvas, 27, 42, 74); // #1B2A4A
    imagefill($canvas, 0, 0, $navy);

    // Calculate resize dimensions (fit logo inside, maintain aspect ratio)
    $ratio = min($size / $src_width, $size / $src_height);
    $new_width = (int)($src_width * $ratio);
    $new_height = (int)($src_height * $ratio);
    $x = (int)(($size - $new_width) / 2);
    $y = (int)(($size - $new_height) / 2);

    // Resample source onto canvas
    imagecopyresampled($canvas, $source, $x, $y, 0, 0, $new_width, $new_height, $src_width, $src_height);

    // Save as PNG
    $output_file = $output_dir . "cba-{$size}.png";
    imagepng($canvas, $output_file, 9);
    imagedestroy($canvas);

    echo "✓ Generated: cba-{$size}.png ({$size}x{$size})\n";
    flush();
    ob_flush();
}

imagedestroy($source);
echo "\n✅ All icons generated successfully!\n";
echo "Location: /assets/logos/\n";
echo "\nNow update your manifest.json to reference these icons.\n";
echo "\n<a href='/tcba/'>Go to Portal</a>";
?>
</pre>