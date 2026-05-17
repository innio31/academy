<?php
// manifest-dynamic.php - Dynamic manifest based on selected school
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/config.php';


require_once __DIR__ . '/includes/config.php';

// Get school from session (selected by user on login)
$school_id = $_SESSION['selected_school_id'] ?? 1;

// Fetch school settings
$stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

if (!$school) {
    // Fallback to first available school
    $stmt = $pdo->query("SELECT * FROM schools LIMIT 1");
    $school = $stmt->fetch();
}

$school_name = $school['school_name'] ?? 'School Portal';
$primary_color = $school['primary_color'] ?? '#1B2A4A';
$logo_path = $school['logo_path'] ?? '/assets/logos/default.png';

// Generate manifest
$manifest = [
    'name' => $school_name . ' Portal',
    'short_name' => substr(preg_replace('/[^A-Za-z0-9]/', '', $school_name), 0, 12),
    'description' => $school_name . ' - School Management Portal',
    'start_url' => '/?school=' . $school['id'],
    'display' => 'standalone',
    'background_color' => $primary_color,
    'theme_color' => $primary_color,
    'icons' => [
        [
            'src' => $logo_path,
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => $logo_path,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
];

echo json_encode($manifest, JSON_PRETTY_PRINT);
