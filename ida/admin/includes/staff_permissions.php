<?php
// admin/includes/staff_permissions.php
// Staff role-based access control helper functions

/**
 * Get staff's assigned subject IDs
 * @param PDO $pdo Database connection
 * @param int $staff_id Staff auto-increment ID from session
 * @param int $school_id School ID
 * @return array Array of subject IDs assigned to this staff member
 */
function getStaffAssignedSubjectIds($pdo, $staff_id, $school_id) {
    try {
        // First get the staff_id_string from staff table
        $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
        $stmt->execute([$staff_id, $school_id]);
        $staff_id_string = $stmt->fetchColumn();
        
        if (!$staff_id_string) {
            return [];
        }
        
        // Get assigned subject IDs from staff_subjects
        $stmt = $pdo->prepare("
            SELECT DISTINCT ss.subject_id 
            FROM staff_subjects ss
            WHERE ss.staff_id = ? AND ss.school_id = ?
        ");
        $stmt->execute([$staff_id_string, $school_id]);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return $results;
    } catch (Exception $e) {
        error_log("Error getting staff assigned subjects: " . $e->getMessage());
        return [];
    }
}

/**
 * Get staff's assigned subject IDs with subject names
 * @param PDO $pdo Database connection
 * @param int $staff_id Staff auto-increment ID from session
 * @param int $school_id School ID
 * @return array Array of subjects with id and subject_name
 */
function getStaffAssignedSubjects($pdo, $staff_id, $school_id) {
    try {
        $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
        $stmt->execute([$staff_id, $school_id]);
        $staff_id_string = $stmt->fetchColumn();
        
        if (!$staff_id_string) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id, s.subject_name, s.description
            FROM subjects s
            JOIN staff_subjects ss ON s.id = ss.subject_id
            WHERE ss.staff_id = ? AND ss.school_id = ? AND s.school_id = ?
            ORDER BY s.subject_name
        ");
        $stmt->execute([$staff_id_string, $school_id, $school_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting staff assigned subjects: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if a subject is assigned to the staff member
 * @param PDO $pdo Database connection
 * @param int $staff_id Staff auto-increment ID from session
 * @param int $school_id School ID
 * @param int $subject_id Subject ID to check
 * @return bool True if assigned, false otherwise
 */
function isSubjectAssignedToStaff($pdo, $staff_id, $school_id, $subject_id) {
    $assigned_ids = getStaffAssignedSubjectIds($pdo, $staff_id, $school_id);
    return in_array($subject_id, $assigned_ids);
}

/**
 * Get topics for a subject, but only if subject is assigned to staff
 * @param PDO $pdo Database connection
 * @param int $staff_id Staff auto-increment ID from session
 * @param int $school_id School ID
 * @param int $subject_id Subject ID
 * @return array Array of topics or empty array if not authorized
 */
function getStaffTopicsForSubject($pdo, $staff_id, $school_id, $subject_id) {
    if (!isSubjectAssignedToStaff($pdo, $staff_id, $school_id, $subject_id)) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT t.* 
            FROM topics t
            WHERE t.subject_id = ? AND t.school_id = ?
            ORDER BY 
                CASE 
                    WHEN t.term = 'First' THEN 1
                    WHEN t.term = 'Second' THEN 2
                    WHEN t.term = 'Third' THEN 3
                    ELSE 4
                END,
                t.topic_name
        ");
        $stmt->execute([$subject_id, $school_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting staff topics: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if a topic belongs to a subject assigned to staff
 * @param PDO $pdo Database connection
 * @param int $staff_id Staff auto-increment ID from session
 * @param int $school_id School ID
 * @param int $topic_id Topic ID to check
 * @return bool True if topic's subject is assigned to staff, false otherwise
 */
function isTopicAccessibleByStaff($pdo, $staff_id, $school_id, $topic_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.subject_id 
            FROM topics t
            WHERE t.id = ? AND t.school_id = ?
        ");
        $stmt->execute([$topic_id, $school_id]);
        $subject_id = $stmt->fetchColumn();
        
        if (!$subject_id) {
            return false;
        }
        
        return isSubjectAssignedToStaff($pdo, $staff_id, $school_id, $subject_id);
    } catch (Exception $e) {
        error_log("Error checking topic access: " . $e->getMessage());
        return false;
    }
}

/**
 * Get the WHERE clause condition for filtering by staff's assigned subjects
 * @param PDO $pdo Database connection
 * @param int $staff_id Staff auto-increment ID from session
 * @param int $school_id School ID
 * @param string $table_alias Table alias for the subjects table (e.g., 's.')
 * @return string SQL WHERE condition or '1=0' if no subjects assigned
 */
function getStaffSubjectFilterCondition($pdo, $staff_id, $school_id, $table_alias = '') {
    $assigned_ids = getStaffAssignedSubjectIds($pdo, $staff_id, $school_id);
    
    if (empty($assigned_ids)) {
        return '1=0'; // No subjects assigned - return false condition
    }
    
    $placeholders = implode(',', array_fill(0, count($assigned_ids), '?'));
    return $table_alias . "subject_id IN ($placeholders)";
}

/**
 * Get staff's staff_id_string (the one used in staff_subjects table)
 * @param PDO $pdo Database connection
 * @param int $staff_id Staff auto-increment ID from session
 * @param int $school_id School ID
 * @return string|null The staff_id_string or null if not found
 */
function getStaffIdString($pdo, $staff_id, $school_id) {
    try {
        $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
        $stmt->execute([$staff_id, $school_id]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error getting staff_id_string: " . $e->getMessage());
        return null;
    }
}

/**
 * Get staff's assigned class IDs
 * @param PDO $pdo Database connection
 * @param int $staff_id Staff auto-increment ID from session
 * @param int $school_id School ID
 * @return array Array of class IDs assigned to this staff member
 */
function getStaffAssignedClassIds($pdo, $staff_id, $school_id) {
    try {
        $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ? AND school_id = ?");
        $stmt->execute([$staff_id, $school_id]);
        $staff_id_string = $stmt->fetchColumn();
        
        if (!$staff_id_string) {
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT sc.class_id 
            FROM staff_classes sc
            WHERE sc.staff_id = ? AND sc.school_id = ?
        ");
        $stmt->execute([$staff_id_string, $school_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log("Error getting staff assigned classes: " . $e->getMessage());
        return [];
    }
}
?>