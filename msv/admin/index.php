<?php
// admin/index.php - Admin Dashboard
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../includes/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /gsa/login.php");
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
$page_title = "Dashboard";

// Get subscription status
$subscription_active = false;
$subscription_end_date = '';
$subscription_days_remaining = 0;

try {
    $stmt = $pdo->prepare("SELECT subscription_status, subscription_expiry FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $school_sub = $stmt->fetch();

    if ($school_sub) {
        $subscription_active = ($school_sub['subscription_status'] === 'active');
        $subscription_end_date = $school_sub['subscription_expiry'];

        if ($subscription_end_date && $subscription_end_date !== '0000-00-00') {
            $expiry_timestamp = strtotime($subscription_end_date);
            $current_timestamp = time();
            $subscription_days_remaining = ceil(($expiry_timestamp - $current_timestamp) / (60 * 60 * 24));
            $subscription_days_remaining = max(0, $subscription_days_remaining);

            if ($expiry_timestamp < $current_timestamp) {
                $subscription_active = false;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error checking subscription: " . $e->getMessage());
}

// Get statistics
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE school_id = ? AND status = 'active'");
    $stmt->execute([$school_id]);
    $total_students = $stmt->fetch()['total'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM staff WHERE school_id = ? AND is_active = 1");
    $stmt->execute([$school_id]);
    $total_staff = $stmt->fetch()['total'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM exams WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $total_exams = $stmt->fetch()['total'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM subjects WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $total_subjects = $stmt->fetch()['total'];

    // Recent activities
    $stmt = $pdo->prepare("
        SELECT al.*, 
               CASE 
                   WHEN al.user_type = 'student' THEN s.full_name
                   WHEN al.user_type = 'staff' THEN st.full_name
                   WHEN al.user_type = 'admin' THEN a.full_name
                   ELSE 'Unknown'
               END as user_name
        FROM activity_logs al
        LEFT JOIN students s ON al.user_id = s.id AND al.user_type = 'student' AND s.school_id = ?
        LEFT JOIN staff st ON al.user_id = st.id AND al.user_type = 'staff' AND st.school_id = ?
        LEFT JOIN admin_users a ON al.user_id = a.id AND al.user_type = 'admin' AND a.school_id = ?
        WHERE al.school_id = ?
        ORDER BY al.created_at DESC LIMIT 10
    ");
    $stmt->execute([$school_id, $school_id, $school_id, $school_id]);
    $recent_activities = $stmt->fetchAll();

    // Recent exams
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name, c.class_name 
        FROM exams e
        LEFT JOIN subjects s ON e.subject_id = s.id
        LEFT JOIN classes c ON e.class_id = c.id
        WHERE e.school_id = ?
        ORDER BY e.created_at DESC LIMIT 5
    ");
    $stmt->execute([$school_id]);
    $recent_exams = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    $recent_exams = [];
}

// Include sidebar (this now outputs the sidebar AND the top header)
$standalone_sidebar = false;
require_once 'includes/sidebar.php';
?>

<style>
    /* Dashboard specific styles */
    .dashboard-container {
        padding: 30px;
        max-width: 1400px;
        margin: 0 auto;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 16px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        border-top: 4px solid;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        cursor: pointer;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
    }

    .stat-card.students {
        border-top-color: <?php echo $secondary_color; ?>;
    }

    .stat-card.staff {
        border-top-color: #f39c12;
    }

    .stat-card.exams {
        border-top-color: #27ae60;
    }

    .stat-card.subjects {
        border-top-color: #e74c3c;
    }

    .stat-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .stat-icon {
        width: 55px;
        height: 55px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: white;
    }

    .stat-card.students .stat-icon {
        background: <?php echo $secondary_color; ?>;
    }

    .stat-card.staff .stat-icon {
        background: #f39c12;
    }

    .stat-card.exams .stat-icon {
        background: #27ae60;
    }

    .stat-card.subjects .stat-icon {
        background: #e74c3c;
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: #1f2937;
    }

    .stat-label {
        font-size: 0.85rem;
        color: #6b7280;
        margin-top: 5px;
    }

    .content-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }

    .content-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e5e7eb;
    }

    .card-header h3 {
        font-size: 1.1rem;
        font-weight: 600;
        color: #1f2937;
    }

    .card-header h3 i {
        color: <?php echo $primary_color; ?>;
        margin-right: 8px;
    }

    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 15px;
    }

    .action-btn {
        background: #f9fafb;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        padding: 15px;
        text-align: center;
        text-decoration: none;
        color: <?php echo $primary_color; ?>;
        transition: all 0.3s ease;
    }

    .action-btn:hover {
        border-color: <?php echo $secondary_color; ?>;
        background: white;
        transform: translateY(-3px);
    }

    .action-icon {
        font-size: 28px;
        margin-bottom: 10px;
    }

    .activity-list {
        list-style: none;
        max-height: 350px;
        overflow-y: auto;
    }

    .activity-item {
        padding: 12px 0;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        gap: 12px;
    }

    .activity-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f3f4f6;
        color: <?php echo $primary_color; ?>;
    }

    .activity-content {
        flex: 1;
    }

    .activity-content h4 {
        font-size: 0.85rem;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .activity-content p {
        font-size: 0.75rem;
        color: #6b7280;
    }

    .activity-time {
        font-size: 0.7rem;
        color: #9ca3af;
        margin-top: 4px;
    }

    .table-container {
        overflow-x: auto;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
    }

    .data-table th,
    .data-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
        font-size: 0.85rem;
    }

    .data-table th {
        background: #f9fafb;
        font-weight: 600;
    }

    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-block;
    }

    .status-active {
        background: #d1fae5;
        color: #065f46;
    }

    .dashboard-footer {
        text-align: center;
        padding: 25px;
        color: #6b7280;
        font-size: 0.8rem;
        border-top: 1px solid #e5e7eb;
        margin-top: 30px;
    }

    .welcome-banner {
        background: linear-gradient(135deg, <?php echo $primary_color; ?> 0%, <?php echo $secondary_color; ?> 100%);
        border-radius: 16px;
        padding: 25px 30px;
        margin-bottom: 30px;
        color: white;
    }

    .welcome-banner h1 {
        font-size: 1.5rem;
        margin-bottom: 8px;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .stat-card,
    .content-card {
        animation: fadeInUp 0.5s ease forwards;
    }
</style>

<div class="dashboard-container">
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <h1>Welcome back, <?php echo htmlspecialchars($admin_name); ?>! 👋</h1>
        <p>Here's what's happening with <?php echo htmlspecialchars($school_name); ?> today.</p>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card students" onclick="window.location.href='manage-students.php'">
            <div class="stat-card-header">
                <div>
                    <div class="stat-value"><?php echo number_format($total_students ?? 0); ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
            </div>
        </div>
        <div class="stat-card staff" onclick="window.location.href='manage-staff.php'">
            <div class="stat-card-header">
                <div>
                    <div class="stat-value"><?php echo number_format($total_staff ?? 0); ?></div>
                    <div class="stat-label">Total Staff</div>
                </div>
                <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            </div>
        </div>
        <div class="stat-card exams" onclick="window.location.href='manage-exams.php'">
            <div class="stat-card-header">
                <div>
                    <div class="stat-value"><?php echo number_format($total_exams ?? 0); ?></div>
                    <div class="stat-label">Total Exams</div>
                </div>
                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
            </div>
        </div>
        <div class="stat-card subjects" onclick="window.location.href='manage-subjects.php'">
            <div class="stat-card-header">
                <div>
                    <div class="stat-value"><?php echo number_format($total_subjects ?? 0); ?></div>
                    <div class="stat-label">Total Subjects</div>
                </div>
                <div class="stat-icon"><i class="fas fa-book"></i></div>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="content-grid">
        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            </div>
            <div class="quick-actions">
                <a href="manage-students.php?action=add" class="action-btn">
                    <div class="action-icon"><i class="fas fa-user-plus"></i></div>
                    <div class="action-text">Add Student</div>
                </a>
                <a href="manage-staff.php?action=add" class="action-btn">
                    <div class="action-icon"><i class="fas fa-user-tie"></i></div>
                    <div class="action-text">Add Staff</div>
                </a>
                <a href="manage-exams.php?action=create" class="action-btn">
                    <div class="action-icon"><i class="fas fa-plus-circle"></i></div>
                    <div class="action-text">Create Exam</div>
                </a>
                <a href="attendance.php" class="action-btn">
                    <div class="action-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="action-text">Take Attendance</div>
                </a>
            </div>
        </div>

        <div class="content-card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Activities</h3>
                <a href="reports.php">View All</a>
            </div>
            <ul class="activity-list">
                <?php if (!empty($recent_activities)): ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <li class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="activity-content">
                                <h4><?php echo htmlspecialchars($activity['user_name'] ?? 'Unknown'); ?></h4>
                                <p><?php echo htmlspecialchars($activity['activity']); ?></p>
                                <div class="activity-time">
                                    <?php echo date('M d, Y • h:i A', strtotime($activity['created_at'])); ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li style="text-align:center; padding:20px;">No recent activities</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- Recent Exams -->
    <div class="content-card">
        <div class="card-header">
            <h3><i class="fas fa-file-alt"></i> Recent Exams</h3>
            <a href="manage-exams.php">View All</a>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Exam Name</th>
                        <th>Subject</th>
                        <th>Class</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($recent_exams)): ?>
                        <?php foreach ($recent_exams as $exam): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                                <td><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($exam['class_name'] ?? 'N/A'); ?></td>
                                <td><span class="status-badge status-active">Active</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center;">No exams found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Footer -->
    <div class="dashboard-footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school_name); ?> - All Rights Reserved</p>
    </div>
</div>

<script>
    // Add animation delays
    document.querySelectorAll('.stat-card').forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
    });
    document.querySelectorAll('.content-card').forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1 + 0.3}s`;
    });
</script>

<?php
// Close the main content wrapper and body (opened in sidebar.php)
?>
</div>
</body>

</html>