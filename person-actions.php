<?php
// person-actions.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

// Check auth
check_auth();

// Verify CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['flash_error'] = "خطأ في التحقق من أمان الطلب (CSRF). يرجى المحاولة مجدداً.";
    header("Location: index.php");
    exit;
}

$action = $_POST['action'] ?? '';
$street_id = (int)($_POST['street_id'] ?? 0);

if ($street_id <= 0) {
    $_SESSION['flash_error'] = "خطأ: الشارع المستهدف غير صالح.";
    header("Location: index.php");
    exit;
}

try {
    // Retrieve neighborhood ID for the street
    $st_stmt = $pdo->prepare("SELECT neighborhood_id FROM streets WHERE id = ?");
    $st_stmt->execute([$street_id]);
    $neighborhood_id = $st_stmt->fetchColumn();
    
    if (!$neighborhood_id) {
        $_SESSION['flash_error'] = "خطأ: الشارع المستهدف غير موجود في النظام.";
        header("Location: index.php");
        exit;
    }
    
    // Security check: Check neighborhood access
    if (!has_neighborhood_access($neighborhood_id)) {
        $_SESSION['flash_error'] = "غير مصرح لك بإجراء تعديلات في هذا الحي.";
        header("Location: index.php");
        exit;
    }
    
    switch ($action) {
        case 'create':
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $national_id = strtoupper(preg_replace('/\s+/', '', $_POST['national_id'] ?? ''));
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($first_name) || empty($last_name) || empty($national_id)) {
                $_SESSION['flash_error'] = "الاسم الشخصي، العائلي ورقم البطاقة الوطنية هي حقول مطلوبة.";
                header("Location: people.php?street_id=" . $street_id);
                exit;
            }
            
            // Check for unique National ID
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM citizens WHERE national_id = ?");
            $check_stmt->execute([$national_id]);
            if ($check_stmt->fetchColumn() > 0) {
                $_SESSION['flash_error'] = "خطأ: رقم البطاقة الوطنية ($national_id) مسجل بالفعل في النظام لشخص آخر.";
                header("Location: people.php?street_id=" . $street_id);
                exit;
            }
            
            // Insert
            $insert_stmt = $pdo->prepare("INSERT INTO citizens (street_id, first_name, last_name, national_id, phone, address, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->execute([$street_id, $first_name, $last_name, $national_id, $phone, $address, $notes]);
            
            // Log
            log_activity($pdo, 'ADD_CITIZEN', "أضاف مواطناً جديداً: $first_name $last_name (بطاقة: $national_id)");
            
            $_SESSION['flash_success'] = "تمت إضافة المواطن ($first_name $last_name) بنجاح.";
            break;
            
        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $national_id = strtoupper(preg_replace('/\s+/', '', $_POST['national_id'] ?? ''));
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if ($id <= 0 || empty($first_name) || empty($last_name) || empty($national_id)) {
                $_SESSION['flash_error'] = "بيانات غير صالحة للتعديل.";
                header("Location: people.php?street_id=" . $street_id);
                exit;
            }
            
            // Ensure CNI is unique except for current citizen
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM citizens WHERE national_id = ? AND id != ?");
            $check_stmt->execute([$national_id, $id]);
            if ($check_stmt->fetchColumn() > 0) {
                $_SESSION['flash_error'] = "خطأ: رقم البطاقة الوطنية ($national_id) مسجل لشخص آخر.";
                header("Location: people.php?street_id=" . $street_id);
                exit;
            }
            
            // Fetch old info for logging
            $old_stmt = $pdo->prepare("SELECT first_name, last_name FROM citizens WHERE id = ?");
            $old_stmt->execute([$id]);
            $old_citizen = $old_stmt->fetch();
            $old_name = $old_citizen ? ($old_citizen['first_name'] . ' ' . $old_citizen['last_name']) : 'غير معروف';
            
            // Update
            $update_stmt = $pdo->prepare("UPDATE citizens SET first_name = ?, last_name = ?, national_id = ?, phone = ?, address = ?, notes = ? WHERE id = ? AND street_id = ?");
            $update_stmt->execute([$first_name, $last_name, $national_id, $phone, $address, $notes, $id, $street_id]);
            
            // Log
            log_activity($pdo, 'UPDATE_CITIZEN', "عدل بيانات المواطن: $old_name إلى $first_name $last_name (بطاقة: $national_id)");
            
            $_SESSION['flash_success'] = "تم تحديث بيانات المواطن ($first_name $last_name) بنجاح.";
            break;
            
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                $_SESSION['flash_error'] = "معرف المواطن غير صالح.";
                header("Location: people.php?street_id=" . $street_id);
                exit;
            }
            
            // Fetch info before delete for logging
            $old_stmt = $pdo->prepare("SELECT first_name, last_name, national_id FROM citizens WHERE id = ?");
            $old_stmt->execute([$id]);
            $old_citizen = $old_stmt->fetch();
            
            if ($old_citizen) {
                $name = $old_citizen['first_name'] . ' ' . $old_citizen['last_name'];
                $national_id = $old_citizen['national_id'];
                
                // Delete
                $delete_stmt = $pdo->prepare("DELETE FROM citizens WHERE id = ? AND street_id = ?");
                $delete_stmt->execute([$id, $street_id]);
                
                // Log
                log_activity($pdo, 'DELETE_CITIZEN', "حذف المواطن: $name (بطاقة: $national_id) من النظام");
                
                $_SESSION['flash_success'] = "تم حذف المواطن ($name) نهائياً من الشارع.";
            } else {
                $_SESSION['flash_error'] = "المواطن المطلوب حذفه غير موجود.";
            }
            break;
            
        default:
            $_SESSION['flash_error'] = "عملية غير معروفة.";
            break;
    }
} catch (PDOException $e) {
    $_SESSION['flash_error'] = "حدث خطأ في قاعدة البيانات: " . $e->getMessage();
}

header("Location: people.php?street_id=" . $street_id);
exit;
