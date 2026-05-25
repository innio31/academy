<?php
// submit_admission.php - API endpoint for admission form submissions
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://eaglescitadel.acad.com.ng');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Database connection
$host = 'localhost';
$dbname = 'impactdi_school_portal';
$username = 'impactdi_school_portal';
$password = 'Innioluwa@1995';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

// If not JSON, get from form data
if (!$input) {
    $input = $_POST;
}

// Validate required fields
$required = ['applyingLevel', 'firstName', 'lastName', 'dob', 'gender', 'parentName', 'relationship', 'phone', 'address'];
$missing = [];

foreach ($required as $field) {
    if (empty($input[$field])) {
        $missing[] = $field;
    }
}

if (!empty($missing)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: ' . implode(', ', $missing)
    ]);
    exit;
}

// Validate phone number (Nigerian format)
$phone = $input['phone'];
if (!preg_match('/^0[789][01]\d{8}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Nigerian phone number format']);
    exit;
}

// Generate unique application number
$application_number = 'EAG/' . date('Y') . '/' . strtoupper(substr(uniqid(), -6));

// School ID (from your database)
$school_id = 6;

try {
    // Prepare insert statement
    $sql = "INSERT INTO admission_applications (
        school_id, application_number, applying_level, first_name, last_name, 
        date_of_birth, gender, parent_name, relationship, phone_number, 
        email_address, home_address, additional_notes, ip_address, user_agent, status
    ) VALUES (
        :school_id, :app_number, :applying_level, :first_name, :last_name,
        :dob, :gender, :parent_name, :relationship, :phone,
        :email, :address, :notes, :ip, :user_agent, 'pending'
    )";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':school_id' => $school_id,
        ':app_number' => $application_number,
        ':applying_level' => $input['applyingLevel'],
        ':first_name' => ucfirst(strtolower(trim($input['firstName']))),
        ':last_name' => ucfirst(strtolower(trim($input['lastName']))),
        ':dob' => $input['dob'],
        ':gender' => $input['gender'],
        ':parent_name' => ucwords(strtolower(trim($input['parentName']))),
        ':relationship' => $input['relationship'],
        ':phone' => $phone,
        ':email' => !empty($input['email']) ? $input['email'] : null,
        ':address' => trim($input['address']),
        ':notes' => !empty($input['notes']) ? $input['notes'] : null,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    $application_id = $pdo->lastInsertId();

    // Log the submission
    $logSql = "INSERT INTO admission_application_logs (
        application_id, school_id, action, note, performed_by_type, ip_address
    ) VALUES (
        :app_id, :school_id, 'submitted', 'Application submitted via website', 'system', :ip
    )";

    $logStmt = $pdo->prepare($logSql);
    $logStmt->execute([
        ':app_id' => $application_id,
        ':school_id' => $school_id,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    // Optional: Send email notification to admin
    $admin_email = "eacteam2023@gmail.com";
    $subject = "New Admission Application - $application_number";
    $message = "A new admission application has been submitted.\n\n";
    $message .= "Application Number: $application_number\n";
    $message .= "Child: " . $input['firstName'] . " " . $input['lastName'] . "\n";
    $message .= "Parent: " . $input['parentName'] . "\n";
    $message .= "Phone: $phone\n";
    $message .= "Level: " . $input['applyingLevel'] . "\n\n";
    $message .= "View in portal: https://acad.com.ng/admin/admissions.php";

    // Uncomment to enable email notifications
    // mail($admin_email, $subject, $message, "From: admissions@eaglescitadel.acad.com.ng");

    echo json_encode([
        'success' => true,
        'message' => 'Application submitted successfully!',
        'application_number' => $application_number
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
