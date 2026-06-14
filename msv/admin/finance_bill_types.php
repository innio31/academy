<?php
// msv/admin/finance_bill_types.php - Manage Bill Templates (Backend Logic Only)
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';
check_page_access(['acct', 'super_admin']);

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /msv/login.php");
    exit();
}

// Get admin info
if (isset($_SESSION['admin_id'])) {
    $admin_id = $_SESSION['admin_id'];
    $admin_name = $_SESSION['admin_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'super_admin';
} else {
    $admin_id = $_SESSION['user_id'];
    $admin_name = $_SESSION['user_name'] ?? 'Administrator';
    $admin_role = $_SESSION['admin_role'] ?? 'admin';
}

$school_id = SCHOOL_ID;
$school_name = SCHOOL_NAME;
$primary_color = SCHOOL_PRIMARY;
$secondary_color = SCHOOL_SECONDARY;

// Get current session
$current_session = date('Y') . '/' . (date('Y') + 1);

// ──────────────────────────────────────────────────────────────
// HELPER FUNCTION: Create or Get Category in fin_categories
// ──────────────────────────────────────────────────────────────
function createOrGetCategory($pdo, $school_id, $category_name, $type = 'income') {
    if (empty($category_name)) {
        return ['success' => false, 'message' => 'Category name is required'];
    }
    
    try {
        // Check if category exists
        $stmt = $pdo->prepare("SELECT id FROM fin_categories WHERE school_id = ? AND LOWER(name) = LOWER(?)");
        $stmt->execute([$school_id, $category_name]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            return ['success' => true, 'category_id' => $existing['id'], 'message' => 'Category already exists'];
        }
        
        // Create new category
        $stmt = $pdo->prepare("
            INSERT INTO fin_categories (school_id, name, type, is_active, created_at)
            VALUES (?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$school_id, $category_name, $type]);
        $category_id = $pdo->lastInsertId();
        
        return ['success' => true, 'category_id' => $category_id, 'message' => 'Category created successfully'];
        
    } catch (Exception $e) {
        error_log("Failed to create category: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ──────────────────────────────────────────────────────────────
// HELPER FUNCTION: Create account in fin_accounts AND fin_ledger
// ──────────────────────────────────────────────────────────────
function createBillTypeAccount($pdo, $school_id, $bill_type_name, $default_amount, $category_name = null) {
    try {
        // Sanitize account name
        $account_name = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $bill_type_name) . ' Income';
        
        // Check if account already exists
        $stmt = $pdo->prepare("SELECT id FROM fin_accounts WHERE school_id = ? AND account_name = ? AND account_type = 'income'");
        $stmt->execute([$school_id, $account_name]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            return ['success' => true, 'account_id' => $existing['id'], 'message' => 'Account already exists'];
        }
        
        // STEP 1: Insert into fin_accounts
        $description = "Income from " . $bill_type_name . ($category_name ? " (" . $category_name . ")" : "");
        $stmt = $pdo->prepare("
            INSERT INTO fin_accounts (
                school_id, 
                account_name, 
                account_type, 
                description, 
                opening_balance, 
                current_balance, 
                is_active, 
                created_at
            ) VALUES (?, ?, 'income', ?, 0, 0, 1, NOW())
        ");
        $stmt->execute([$school_id, $account_name, $description]);
        $account_id = $pdo->lastInsertId();
        
        // STEP 2: Insert into fin_ledger (opening entry for this account)
        $stmt = $pdo->prepare("
            INSERT INTO fin_ledger (
                school_id, 
                account_id, 
                entry_date, 
                entry_type, 
                amount, 
                balance, 
                description, 
                ref_type, 
                ref_id, 
                created_at
            ) VALUES (?, ?, CURDATE(), 'credit', 0, 0, ?, 'account_setup', ?, NOW())
        ");
        $stmt->execute([$school_id, $account_id, "Account created for: " . $bill_type_name, $account_id]);
        
        return [
            'success' => true, 
            'account_id' => $account_id, 
            'account_name' => $account_name,
            'message' => 'Account created in fin_accounts and fin_ledger'
        ];
        
    } catch (Exception $e) {
        error_log("Failed to create account: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ──────────────────────────────────────────────────────────────
// HELPER FUNCTION: Generate bills for students
// ──────────────────────────────────────────────────────────────
function generateBillsForClass($pdo, $school_id, $bill_type_id, $target_class, $admin_id) {
    try {
        // Get bill type details
        $stmt = $pdo->prepare("SELECT * FROM fin_bill_types WHERE id = ? AND school_id = ?");
        $stmt->execute([$bill_type_id, $school_id]);
        $bill_type = $stmt->fetch();
        
        if (!$bill_type) {
            return ['success' => false, 'message' => 'Bill template not found'];
        }
        
        // Get students in the class
        $stmt = $pdo->prepare("
            SELECT id, full_name, admission_number, class 
            FROM students 
            WHERE school_id = ? AND class = ? AND status = 'active'
        ");
        $stmt->execute([$school_id, $target_class]);
        $students = $stmt->fetchAll();
        
        if (empty($students)) {
            return ['success' => false, 'message' => "No active students found in class: $target_class"];
        }
        
        $generated = 0;
        $skipped = 0;
        
        foreach ($students as $student) {
            // Check if bill already exists for this student, session, term, and bill type
            $stmt = $pdo->prepare("
                SELECT id FROM fin_bills 
                WHERE school_id = ? AND student_id = ? AND bill_type_id = ? AND session = ? AND term = ?
            ");
            $stmt->execute([$school_id, $student['id'], $bill_type_id, $bill_type['session'], $bill_type['term']]);
            
            if (!$stmt->fetch()) {
                // Create bill for student
                $stmt = $pdo->prepare("
                    INSERT INTO fin_bills (
                        school_id, bill_type_id, student_id, class, session, term, 
                        description, amount, due_date, status, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
                ");
                $stmt->execute([
                    $school_id,
                    $bill_type_id,
                    $student['id'],
                    $student['class'],
                    $bill_type['session'],
                    $bill_type['term'],
                    $bill_type['name'] . ' - ' . $student['full_name'],
                    $bill_type['default_amount'],
                    $bill_type['due_date'],
                    $admin_id
                ]);
                $generated++;
            } else {
                $skipped++;
            }
        }
        
        return [
            'success' => true, 
            'generated' => $generated, 
            'skipped' => $skipped,
            'message' => "Generated $generated bills for class $target_class. Skipped $skipped (already exist)."
        ];
        
    } catch (Exception $e) {
        error_log("Error generating bills: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ──────────────────────────────────────────────────────────────
// PROCESS POST ACTIONS
// ──────────────────────────────────────────────────────────────
$message = '';
$message_type = '';
$redirect_after_action = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ── CREATE NEW BILL TYPE (with auto account creation) ──
    if ($_POST['action'] === 'create') {
        $name = trim($_POST['name'] ?? '');
        $new_category_name = trim($_POST['new_category_name'] ?? '');
        $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $applies_to_class = !empty($_POST['applies_to_class']) ? trim($_POST['applies_to_class']) : null;
        $session = trim($_POST['session'] ?? $current_session);
        $term = trim($_POST['term'] ?? 'First');
        $default_amount = floatval($_POST['default_amount'] ?? 0);
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $auto_create_account = isset($_POST['auto_create_account']) ? 1 : 0;
        
        if (empty($name) || $default_amount <= 0) {
            $_SESSION['flash_message'] = "Please fill in all required fields (Name and Amount)";
            $_SESSION['flash_type'] = "error";
        } else {
            try {
                // Handle category creation if new category name provided
                $message_add = '';
                if (!empty($new_category_name)) {
                    $category_result = createOrGetCategory($pdo, $school_id, $new_category_name, 'income');
                    if ($category_result['success']) {
                        $category_id = $category_result['category_id'];
                        $message_add = "Category '" . htmlspecialchars($new_category_name) . "' created. ";
                    }
                }
                
                // Insert bill type into fin_bill_types
                $stmt = $pdo->prepare("
                    INSERT INTO fin_bill_types (
                        school_id, name, category_id, applies_to_class, session, term, 
                        default_amount, is_recurring, due_date, description, is_active, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $school_id, $name, $category_id, $applies_to_class, $session, $term, 
                    $default_amount, $is_recurring, $due_date, $description, $is_active, $admin_id
                ]);
                
                $bill_type_id = $pdo->lastInsertId();
                
                // Auto-create accounts in fin_accounts and fin_ledger if requested
                $account_created = false;
                $account_message = '';
                if ($auto_create_account) {
                    $category_name = null;
                    if ($category_id) {
                        $stmt = $pdo->prepare("SELECT name FROM fin_categories WHERE id = ?");
                        $stmt->execute([$category_id]);
                        $cat = $stmt->fetch();
                        $category_name = $cat ? $cat['name'] : null;
                    }
                    $account_result = createBillTypeAccount($pdo, $school_id, $name, $default_amount, $category_name);
                    if ($account_result['success']) {
                        $account_created = true;
                        $account_message = " Account '{$account_result['account_name']}' created in fin_accounts and fin_ledger.";
                    } else {
                        $account_message = " Warning: " . $account_result['message'];
                    }
                }
                
                $_SESSION['flash_message'] = $message_add . "Bill template created successfully!" . $account_message;
                $_SESSION['flash_type'] = "success";
                $redirect_after_action = true;
                
            } catch (Exception $e) {
                $_SESSION['flash_message'] = "Error creating bill template: " . $e->getMessage();
                $_SESSION['flash_type'] = "error";
                error_log("Error creating bill template: " . $e->getMessage());
            }
        }
    }
    
    // ── UPDATE BILL TYPE ──
    elseif ($_POST['action'] === 'update') {
        $bill_type_id = intval($_POST['bill_type_id']);
        $name = trim($_POST['name'] ?? '');
        $new_category_name = trim($_POST['new_category_name'] ?? '');
        $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $applies_to_class = !empty($_POST['applies_to_class']) ? trim($_POST['applies_to_class']) : null;
        $session = trim($_POST['session'] ?? $current_session);
        $term = trim($_POST['term'] ?? 'First');
        $default_amount = floatval($_POST['default_amount'] ?? 0);
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($name) || $default_amount <= 0) {
            $_SESSION['flash_message'] = "Please fill in all required fields (Name and Amount)";
            $_SESSION['flash_type'] = "error";
        } else {
            try {
                // Handle category creation if new category name provided
                if (!empty($new_category_name)) {
                    $category_result = createOrGetCategory($pdo, $school_id, $new_category_name, 'income');
                    if ($category_result['success']) {
                        $category_id = $category_result['category_id'];
                        $_SESSION['flash_message'] = "Category '" . htmlspecialchars($new_category_name) . "' created. ";
                    }
                }
                
                $stmt = $pdo->prepare("
                    UPDATE fin_bill_types 
                    SET name = ?, category_id = ?, applies_to_class = ?, session = ?, term = ?, 
                        default_amount = ?, is_recurring = ?, due_date = ?, description = ?, is_active = ?
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([
                    $name, $category_id, $applies_to_class, $session, $term, 
                    $default_amount, $is_recurring, $due_date, $description, $is_active, 
                    $bill_type_id, $school_id
                ]);
                
                $_SESSION['flash_message'] = ($_SESSION['flash_message'] ?? '') . "Bill template updated successfully!";
                $_SESSION['flash_type'] = "success";
                $redirect_after_action = true;
                
            } catch (Exception $e) {
                $_SESSION['flash_message'] = "Error updating bill template: " . $e->getMessage();
                $_SESSION['flash_type'] = "error";
                error_log("Error updating bill template: " . $e->getMessage());
            }
        }
    }
    
    // ── GENERATE BILLS FROM TEMPLATE ──
    elseif ($_POST['action'] === 'generate_bills') {
        $bill_type_id = intval($_POST['bill_type_id']);
        $target_class = $_POST['target_class'] ?? null;
        
        if (!$bill_type_id || !$target_class) {
            $_SESSION['flash_message'] = "Please select a class to generate bills for";
            $_SESSION['flash_type'] = "error";
        } else {
            $result = generateBillsForClass($pdo, $school_id, $bill_type_id, $target_class, $admin_id);
            $_SESSION['flash_message'] = $result['message'];
            $_SESSION['flash_type'] = $result['success'] ? "success" : "error";
            $redirect_after_action = true;
        }
    }
    
    // Redirect after successful POST to prevent form resubmission
    if ($redirect_after_action) {
        header("Location: finance_bill_types.php");
        exit();
    }
}

// ──────────────────────────────────────────────────────────────
// PROCESS GET ACTIONS (Delete, Toggle Status)
// ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    
    // ── DELETE BILL TYPE ──
    if ($_GET['action'] === 'delete' && isset($_GET['id'])) {
        $bill_type_id = intval($_GET['id']);
        
        try {
            // Check if bills exist using this template
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_bills WHERE bill_type_id = ? AND school_id = ?");
            $stmt->execute([$bill_type_id, $school_id]);
            $bill_count = $stmt->fetchColumn();
            
            if ($bill_count > 0) {
                $_SESSION['flash_message'] = "Cannot delete this template as it has $bill_count associated bills. Consider deactivating it instead.";
                $_SESSION['flash_type'] = "error";
            } else {
                $stmt = $pdo->prepare("DELETE FROM fin_bill_types WHERE id = ? AND school_id = ?");
                $stmt->execute([$bill_type_id, $school_id]);
                $_SESSION['flash_message'] = "Bill template deleted successfully!";
                $_SESSION['flash_type'] = "success";
            }
        } catch (Exception $e) {
            $_SESSION['flash_message'] = "Error deleting bill template: " . $e->getMessage();
            $_SESSION['flash_type'] = "error";
            error_log("Error deleting bill template: " . $e->getMessage());
        }
        
        header("Location: finance_bill_types.php");
        exit();
    }
    
    // ── TOGGLE STATUS (Active/Inactive) ──
    if ($_GET['action'] === 'toggle_status' && isset($_GET['id'])) {
        $bill_type_id = intval($_GET['id']);
        
        try {
            $stmt = $pdo->prepare("SELECT is_active FROM fin_bill_types WHERE id = ? AND school_id = ?");
            $stmt->execute([$bill_type_id, $school_id]);
            $current = $stmt->fetch();
            
            if ($current) {
                $new_status = $current['is_active'] ? 0 : 1;
                $stmt = $pdo->prepare("UPDATE fin_bill_types SET is_active = ? WHERE id = ? AND school_id = ?");
                $stmt->execute([$new_status, $bill_type_id, $school_id]);
                
                $_SESSION['flash_message'] = "Bill template " . ($new_status ? "activated" : "deactivated") . " successfully!";
                $_SESSION['flash_type'] = "success";
            } else {
                $_SESSION['flash_message'] = "Bill template not found";
                $_SESSION['flash_type'] = "error";
            }
        } catch (Exception $e) {
            $_SESSION['flash_message'] = "Error toggling status: " . $e->getMessage();
            $_SESSION['flash_type'] = "error";
            error_log("Error toggling status: " . $e->getMessage());
        }
        
        header("Location: finance_bill_types.php");
        exit();
    }
}

// ──────────────────────────────────────────────────────────────
// FETCH DATA FOR DISPLAY (No HTML output, just data preparation)
// ──────────────────────────────────────────────────────────────

// Get flash messages from session
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_term = isset($_GET['term']) ? $_GET['term'] : 'all';
$filter_class = isset($_GET['class']) ? $_GET['class'] : 'all';

// Build WHERE clause
$where_clauses = ["bt.school_id = ?"];
$params = [$school_id];

if ($filter_status !== 'all') {
    $where_clauses[] = "bt.is_active = ?";
    $params[] = ($filter_status === 'active') ? 1 : 0;
}

if ($filter_term !== 'all') {
    $where_clauses[] = "bt.term = ?";
    $params[] = $filter_term;
}

if ($filter_class !== 'all') {
    $where_clauses[] = "bt.applies_to_class = ?";
    $params[] = $filter_class;
}

$where_sql = implode(" AND ", $where_clauses);

// Get total count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_bill_types bt WHERE $where_sql");
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = $total_records > 0 ? ceil($total_records / $per_page) : 1;

// Get bill types with category names
$stmt = $pdo->prepare("
    SELECT bt.*, c.name as category_name
    FROM fin_bill_types bt
    LEFT JOIN fin_categories c ON bt.category_id = c.id
    WHERE $where_sql
    ORDER BY bt.created_at DESC
    LIMIT $offset, $per_page
");
$stmt->execute($params);
$bill_types = $stmt->fetchAll();

// Get categories for dropdown
$stmt = $pdo->prepare("SELECT id, name FROM fin_categories WHERE school_id = ? AND type IN ('income', 'both') AND is_active = 1 ORDER BY name");
$stmt->execute([$school_id]);
$categories = $stmt->fetchAll();

// Get unique classes from students for filter
$stmt = $pdo->prepare("SELECT DISTINCT class FROM students WHERE school_id = ? AND status = 'active' AND class IS NOT NULL AND class != '' ORDER BY class");
$stmt->execute([$school_id]);
$available_classes = $stmt->fetchAll();

// Get edit data if requested
$edit_data = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM fin_bill_types WHERE id = ? AND school_id = ?");
    $stmt->execute([$_GET['edit'], $school_id]);
    $edit_data = $stmt->fetch();
}

// Get stats for dashboard display
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_templates,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_templates,
        COUNT(DISTINCT category_id) as total_categories
    FROM fin_bill_types 
    WHERE school_id = ?
");
$stmt->execute([$school_id]);
$stats = $stmt->fetch();

// ──────────────────────────────────────────────────────────────
// At this point, data is ready for the HTML view
// You would now include your HTML template here
// ──────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $school_name; ?> - Bill Templates</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: <?php echo $primary_color; ?>;
            --secondary-color: <?php echo $secondary_color; ?>;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --radius-sm: 8px;
            --radius-md: 12px;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f5f6fa; color: #333; min-height: 100vh; overflow-x: hidden; }

        .main-content { min-height: 100vh; padding: 20px; transition: var(--transition); }
        .mobile-menu-btn {
            position: fixed; top: 15px; right: 20px; z-index: 1001; width: 44px; height: 44px;
            background: var(--primary-color); color: white; border: none; border-radius: var(--radius-md);
            font-size: 20px; cursor: pointer; display: none;
        }
        .sidebar-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5); z-index: 999; opacity: 0; visibility: hidden;
            transition: var(--transition);
        }
        .sidebar-overlay.active { opacity: 1; visibility: visible; }

        @media (min-width: 768px) {
            .mobile-menu-btn, .sidebar-overlay { display: none; }
            .main-content { margin-left: var(--sidebar-width); }
        }
        @media (max-width: 767px) {
            .main-content { padding-top: 70px; }
            .mobile-menu-btn { display: flex; align-items: center; justify-content: center; }
        }

        .top-header {
            background: white; padding: 20px; border-radius: var(--radius-md); margin-bottom: 24px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
            box-shadow: var(--shadow-sm);
        }
        .header-title h1 { color: var(--primary-color); font-size: 1.5rem; margin-bottom: 5px; }
        .header-title p { color: #666; font-size: 0.85rem; }

        .alert { padding: 12px 20px; border-radius: var(--radius-sm); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d5f4e6; color: var(--success-color); border-left: 4px solid var(--success-color); }
        .alert-error { background: #f8d7da; color: var(--danger-color); border-left: 4px solid var(--danger-color); }
        .alert-info { background: #e3f2fd; color: var(--info-color); border-left: 4px solid var(--info-color); }

        .form-card { background: white; border-radius: var(--radius-md); padding: 20px; margin-bottom: 24px; box-shadow: var(--shadow-sm); }
        .form-title { color: var(--primary-color); font-size: 1rem; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--light-color); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 0.8rem; font-weight: 500; margin-bottom: 5px; color: #555; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px; border: 1px solid #ddd; border-radius: var(--radius-sm);
            font-size: 0.85rem; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: var(--secondary-color);
        }
        .checkbox-group { flex-direction: row; align-items: center; gap: 10px; }
        .checkbox-group label { margin-bottom: 0; cursor: pointer; }
        .checkbox-group input { width: auto; }

        .btn { padding: 10px 20px; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 0.85rem; font-weight: 500; transition: var(--transition); }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-secondary { background: var(--secondary-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-info { background: var(--info-color); color: white; }
        .btn-sm { padding: 5px 10px; font-size: 0.7rem; }

        .filter-bar {
            background: white; padding: 15px; border-radius: var(--radius-md); margin-bottom: 20px;
            display: flex; flex-wrap: wrap; gap: 10px; align-items: center; box-shadow: var(--shadow-sm);
        }
        .filter-btn {
            padding: 6px 15px; border: 1px solid #ddd; border-radius: 20px; background: white;
            cursor: pointer; font-size: 0.8rem; transition: var(--transition); text-decoration: none; color: #666;
        }
        .filter-btn.active { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .filter-btn:hover:not(.active) { border-color: var(--secondary-color); }

        .table-card { background: white; border-radius: var(--radius-md); padding: 20px; box-shadow: var(--shadow-sm); }
        .table-container { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #eee; font-size: 0.8rem; }
        .data-table th { background: var(--light-color); font-weight: 600; }
        .data-table tr:hover { background: #f9f9f9; }

        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 500; display: inline-block; }
        .status-active { background: #d5f4e6; color: var(--success-color); }
        .status-inactive { background: #f8d7da; color: var(--danger-color); }

        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .action-icon { padding: 6px; border-radius: var(--radius-sm); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-size: 0.7rem; }
        .action-icon.edit { background: var(--info-color); color: white; }
        .action-icon.generate { background: var(--success-color); color: white; }
        .action-icon.toggle { background: var(--warning-color); color: white; }
        .action-icon.delete { background: var(--danger-color); color: white; }

        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
        .page-link { padding: 8px 12px; border: 1px solid #ddd; border-radius: var(--radius-sm); text-decoration: none; color: #666; transition: var(--transition); }
        .page-link.active { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .page-link:hover:not(.active) { border-color: var(--secondary-color); }

        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 2000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: var(--radius-md); max-width: 500px; width: 90%; padding: 20px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid var(--light-color); }
        .modal-body { margin-bottom: 20px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; }

        .footer { text-align: center; padding: 20px; color: #666; font-size: 0.8rem; border-top: 1px solid var(--light-color); margin-top: 20px; }

        @media (max-width: 767px) {
            .form-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .data-table th, .data-table td { padding: 8px; }
        }

        .category-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .category-toggle button {
            flex: 1;
            padding: 8px;
            background: var(--light-color);
            border: 1px solid #ddd;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.8rem;
        }
        .category-toggle button.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .new-category-group {
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: var(--radius-sm);
        }
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid var(--info-color);
            padding: 10px;
            margin: 10px 0;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
        }
    </style>
</head>

<body>

    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-tags"></i> Bill Templates</h1>
                <p>Create and manage reusable bill templates with automatic account creation</p>
            </div>
            <div>
                <a href="finance_dashboard.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : ($message_type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Add/Edit Form -->
        <div class="form-card">
            <div class="form-title">
                <i class="fas fa-plus-circle"></i>
                <?php echo $edit_data ? 'Edit Bill Template' : 'Create New Bill Template'; ?>
            </div>
            <form method="POST" action="" id="billTypeForm">
                <input type="hidden" name="action" value="<?php echo $edit_data ? 'update' : 'create'; ?>">
                <?php if ($edit_data): ?>
                    <input type="hidden" name="bill_type_id" value="<?php echo $edit_data['id']; ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Template Name *</label>
                        <input type="text" name="name" required value="<?php echo $edit_data ? htmlspecialchars($edit_data['name']) : ''; ?>" placeholder="e.g., First Term School Fees">
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <div class="category-toggle">
                            <button type="button" id="selectExistingBtn" class="active">Select Existing</button>
                            <button type="button" id="createNewBtn">Create New</button>
                        </div>
                        <div id="existingCategoryDiv">
                            <select name="category_id" class="form-group">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo ($edit_data && $edit_data['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="newCategoryDiv" style="display: none;">
                            <input type="text" name="new_category_name" class="form-group" placeholder="Enter new category name (e.g., 'Transport Fees')">
                            <div class="info-box">
                                <i class="fas fa-info-circle"></i> New category will be created automatically when you save.
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Applies To Class</label>
                        <select name="applies_to_class">
                            <option value="">-- All Classes --</option>
                            <?php foreach ($available_classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo ($edit_data && $edit_data['applies_to_class'] == $class['class']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Academic Session</label>
                        <select name="session">
                            <option value="2023/2024" <?php echo ($edit_data && $edit_data['session'] == '2023/2024') ? 'selected' : ''; ?>>2023/2024</option>
                            <option value="2024/2025" <?php echo ($edit_data && $edit_data['session'] == '2024/2025') ? 'selected' : ''; ?>>2024/2025</option>
                            <option value="2025/2026" <?php echo ($edit_data && $edit_data['session'] == '2025/2026') ? 'selected' : ''; ?>>2025/2026</option>
                            <option value="<?php echo $current_session; ?>" <?php echo (!$edit_data || $edit_data['session'] == $current_session) ? 'selected' : ''; ?>><?php echo $current_session; ?> (Current)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Term</label>
                        <select name="term">
                            <option value="First" <?php echo ($edit_data && $edit_data['term'] == 'First') ? 'selected' : ''; ?>>First Term</option>
                            <option value="Second" <?php echo ($edit_data && $edit_data['term'] == 'Second') ? 'selected' : ''; ?>>Second Term</option>
                            <option value="Third" <?php echo ($edit_data && $edit_data['term'] == 'Third') ? 'selected' : ''; ?>>Third Term</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Default Amount (₦) *</label>
                        <input type="number" name="default_amount" step="0.01" required value="<?php echo $edit_data ? floatval($edit_data['default_amount']) : ''; ?>" placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" value="<?php echo $edit_data && $edit_data['due_date'] ? date('Y-m-d', strtotime($edit_data['due_date'])) : ''; ?>">
                    </div>

                    <div class="form-group checkbox-group">
                        <input type="checkbox" name="is_recurring" id="is_recurring" <?php echo ($edit_data && $edit_data['is_recurring']) ? 'checked' : ''; ?>>
                        <label for="is_recurring">Recurring (applies every term)</label>
                    </div>

                    <div class="form-group checkbox-group">
                        <input type="checkbox" name="is_active" id="is_active" <?php echo (!$edit_data || $edit_data['is_active']) ? 'checked' : ''; ?>>
                        <label for="is_active">Active</label>
                    </div>

                    <div class="form-group checkbox-group">
                        <input type="checkbox" name="auto_create_account" id="auto_create_account" <?php echo (!$edit_data) ? 'checked' : ''; ?>>
                        <label for="auto_create_account">Auto-create income account</label>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3" placeholder="Additional details about this bill"><?php echo $edit_data ? htmlspecialchars($edit_data['description'] ?? '') : ''; ?></textarea>
                    </div>
                </div>

                <?php if (!$edit_data): ?>
                    <div class="info-box" style="margin: 15px 0;">
                        <i class="fas fa-lightbulb"></i> <strong>Pro Tip:</strong> When you enable "Auto-create income account", a dedicated income account will be created automatically for this bill type. This helps track revenue from different fee categories separately.
                    </div>
                <?php endif; ?>

                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $edit_data ? 'Update Template' : 'Create Template'; ?>
                    </button>
                    <?php if ($edit_data): ?>
                        <a href="finance_bill_types.php" class="btn btn-warning">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <span style="font-size: 0.8rem; color: #666;"><i class="fas fa-filter"></i> Filters:</span>
            <a href="?status=all&term=<?php echo $filter_term; ?>&class=<?php echo $filter_class; ?>" class="filter-btn <?php echo $filter_status === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?status=active&term=<?php echo $filter_term; ?>&class=<?php echo $filter_class; ?>" class="filter-btn <?php echo $filter_status === 'active' ? 'active' : ''; ?>">Active</a>
            <a href="?status=inactive&term=<?php echo $filter_term; ?>&class=<?php echo $filter_class; ?>" class="filter-btn <?php echo $filter_status === 'inactive' ? 'active' : ''; ?>">Inactive</a>

            <span style="margin-left: 10px;"></span>
            <select onchange="window.location.href='?status=<?php echo $filter_status; ?>&term='+this.value+'&class=<?php echo $filter_class; ?>'" style="padding: 5px 10px; border-radius: 20px; border: 1px solid #ddd;">
                <option value="all" <?php echo $filter_term === 'all' ? 'selected' : ''; ?>>All Terms</option>
                <option value="First" <?php echo $filter_term === 'First' ? 'selected' : ''; ?>>First Term</option>
                <option value="Second" <?php echo $filter_term === 'Second' ? 'selected' : ''; ?>>Second Term</option>
                <option value="Third" <?php echo $filter_term === 'Third' ? 'selected' : ''; ?>>Third Term</option>
            </select>

            <select onchange="window.location.href='?status=<?php echo $filter_status; ?>&term=<?php echo $filter_term; ?>&class='+this.value" style="padding: 5px 10px; border-radius: 20px; border: 1px solid #ddd;">
                <option value="all" <?php echo $filter_class === 'all' ? 'selected' : ''; ?>>All Classes</option>
                <?php foreach ($available_classes as $class): ?>
                    <option value="<?php echo htmlspecialchars($class['class']); ?>" <?php echo $filter_class === $class['class'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($class['class']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Bill Types Table -->
        <div class="table-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="color: var(--primary-color); font-size: 1rem;">
                    <i class="fas fa-list"></i> Bill Templates
                    <span style="font-size: 0.75rem; color: #666;">(<?php echo $total_records; ?> total)</span>
                </h3>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Class</th>
                            <th>Session/Term</th>
                            <th>Amount (₦)</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bill_types)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                                    <i class="fas fa-tags" style="font-size: 40px; margin-bottom: 10px; display: block;"></i>
                                    No bill templates found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bill_types as $bill_type): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bill_type['name']); ?></strong>
                                        <?php if ($bill_type['is_recurring']): ?>
                                            <br><small style="color: var(--secondary-color);"><i class="fas fa-sync-alt"></i> Recurring</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($bill_type['category_name'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($bill_type['applies_to_class'] ?? 'All Classes'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($bill_type['session']); ?><br>
                                        <small><?php echo htmlspecialchars($bill_type['term']); ?> Term</small>
                                    </td>
                                    <td style="font-weight: 600;">₦<?php echo number_format($bill_type['default_amount'], 2); ?></td>
                                    <td><?php echo $bill_type['due_date'] ? date('d M Y', strtotime($bill_type['due_date'])) : 'Not set'; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $bill_type['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $bill_type['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?edit=<?php echo $bill_type['id']; ?>" class="action-icon edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="#" onclick="showGenerateModal(<?php echo $bill_type['id']; ?>, '<?php echo htmlspecialchars($bill_type['name']); ?>', '<?php echo htmlspecialchars($bill_type['applies_to_class'] ?? ''); ?>')" class="action-icon generate">
                                                <i class="fas fa-file-invoice-dollar"></i> Generate
                                            </a>
                                            <a href="?action=toggle_status&id=<?php echo $bill_type['id']; ?>" class="action-icon toggle" onclick="return confirm('Toggle status of this template?')">
                                                <i class="fas <?php echo $bill_type['is_active'] ? 'fa-ban' : 'fa-check'; ?>"></i>
                                                <?php echo $bill_type['is_active'] ? 'Disable' : 'Enable'; ?>
                                            </a>
                                            <?php if (!$bill_type['is_recurring']): ?>
                                                <a href="?action=delete&id=<?php echo $bill_type['id']; ?>" class="action-icon delete" onclick="return confirm('Are you sure you want to delete this template? This cannot be undone.')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $filter_status; ?>&term=<?php echo $filter_term; ?>&class=<?php echo $filter_class; ?>" class="page-link">&laquo; Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo $filter_status; ?>&term=<?php echo $filter_term; ?>&class=<?php echo $filter_class; ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $filter_status; ?>&term=<?php echo $filter_term; ?>&class=<?php echo $filter_class; ?>" class="page-link">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo $school_name; ?> - Finance Management System</p>
        </div>
    </div>

    <!-- Generate Bills Modal -->
    <div id="generateModal" class="modal">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="generate_bills">
                <input type="hidden" name="bill_type_id" id="generate_bill_type_id">

                <div class="modal-header">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Generate Bills</h3>
                    <button type="button" onclick="closeModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="generate_template_name" style="margin-bottom: 15px;"></p>
                    <div class="form-group">
                        <label>Select Class to Generate For:</label>
                        <select name="target_class" id="generate_target_class" class="form-group" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($available_classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['class']); ?>">
                                    <?php echo htmlspecialchars($class['class']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666; display: block; margin-top: 5px;">
                            Bills will be generated for all active students in the selected class.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal()" class="btn btn-warning">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate Bills</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const sidebarOverlay = document.getElementById('sidebarOverlay');

            setTimeout(function() {
                const sidebar = document.getElementById('sidebar');

                if (mobileMenuBtn && sidebar) {
                    mobileMenuBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        sidebar.classList.toggle('active');
                        if (sidebarOverlay) sidebarOverlay.classList.toggle('active');
                        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
                    });
                }

                if (sidebarOverlay) {
                    sidebarOverlay.addEventListener('click', function() {
                        if (sidebar) sidebar.classList.remove('active');
                        sidebarOverlay.classList.remove('active');
                        document.body.style.overflow = '';
                    });
                }
            }, 100);
        });

        // Category toggle functionality
        const selectExistingBtn = document.getElementById('selectExistingBtn');
        const createNewBtn = document.getElementById('createNewBtn');
        const existingCategoryDiv = document.getElementById('existingCategoryDiv');
        const newCategoryDiv = document.getElementById('newCategoryDiv');

        if (selectExistingBtn && createNewBtn) {
            selectExistingBtn.addEventListener('click', function() {
                selectExistingBtn.classList.add('active');
                createNewBtn.classList.remove('active');
                existingCategoryDiv.style.display = 'block';
                newCategoryDiv.style.display = 'none';
            });

            createNewBtn.addEventListener('click', function() {
                createNewBtn.classList.add('active');
                selectExistingBtn.classList.remove('active');
                existingCategoryDiv.style.display = 'none';
                newCategoryDiv.style.display = 'block';
            });
        }

        function showGenerateModal(billTypeId, templateName, appliesToClass) {
            document.getElementById('generate_bill_type_id').value = billTypeId;
            document.getElementById('generate_template_name').innerHTML = '<strong>Template:</strong> ' + templateName;

            const classSelect = document.getElementById('generate_target_class');
            if (appliesToClass && appliesToClass !== '') {
                classSelect.value = appliesToClass;
                classSelect.disabled = true;
            } else {
                classSelect.disabled = false;
                classSelect.value = '';
            }

            document.getElementById('generateModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('generateModal').classList.remove('active');
        }

        window.onclick = function(event) {
            const modal = document.getElementById('generateModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>

    <?php require_once 'includes/sidebar.php'; ?>
</body>

</html>