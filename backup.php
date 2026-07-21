<?php
// backup.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

// Restrict to General Admin
require_admin();

$csrf_token = generate_csrf_token();
$error = '';
$success = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = "خطأ في التحقق من أمان الطلب (CSRF).";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'backup') {
            try {
                // Emulate MySQL Dump in PHP for maximum portability
                $tables = ['neighborhoods', 'users', 'streets', 'citizens', 'activity_logs'];
                
                $sql_dump = "-- حزب المستقبل - نظام إدارة البيانات\n";
                $sql_dump .= "-- نسخة احتياطية لقاعدة البيانات\n";
                $sql_dump .= "-- تاريخ الاستخراج: " . date('Y-m-d H:i:s') . "\n\n";
                $sql_dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
                
                foreach ($tables as $table) {
                    // Show Create Table
                    $create_stmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
                    $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
                    $sql_dump .= $create_stmt['Create Table'] . ";\n\n";
                    
                    // Fetch all rows
                    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                    if (count($rows) > 0) {
                        $sql_dump .= "INSERT INTO `$table` VALUES \n";
                        $row_lines = [];
                        foreach ($rows as $row) {
                            $escaped_vals = [];
                            foreach ($row as $val) {
                                if ($val === null) {
                                    $escaped_vals[] = "NULL";
                                } else {
                                    $escaped_vals[] = $pdo->quote($val);
                                }
                            }
                            $row_lines[] = "(" . implode(", ", $escaped_vals) . ")";
                        }
                        $sql_dump .= implode(",\n", $row_lines) . ";\n\n";
                    }
                }
                
                $sql_dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";
                
                // Log this backup event
                log_activity($pdo, 'BACKUP', 'تم إنشاء نسخة احتياطية من قاعدة البيانات وتحميلها كملف SQL.');
                
                // Force Download SQL File
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="backup_political_party_' . date('Y-m-d_H-i-s') . '.sql"');
                header('Content-Length: ' . strlen($sql_dump));
                echo $sql_dump;
                exit;
                
            } catch (PDOException $e) {
                $error = "فشل توليد النسخة الاحتياطية: " . $e->getMessage();
            }
        } elseif ($action === 'restore') {
            if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['backup_file']['tmp_name'];
                $file_name = $_FILES['backup_file']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if ($file_ext !== 'sql') {
                    $error = "نوع الملف غير صالح. يرجى رفع ملف بصيغة .sql فقط.";
                } else {
                    try {
                        $sql_content = file_get_contents($file_tmp);
                        
                        // Parse SQL Content
                        // Remove SQL comments
                        $sql_content = preg_replace('/--.*\n/', '', $sql_content);
                        $sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content);
                        
                        // Split into separate queries
                        $queries = explode(';', $sql_content);
                        
                        $pdo->beginTransaction();
                        $executed_count = 0;
                        
                        foreach ($queries as $query) {
                            $query = trim($query);
                            if (!empty($query)) {
                                $pdo->exec($query);
                                $executed_count++;
                            }
                        }
                        
                        $pdo->commit();
                        
                        // Log restore
                        log_activity($pdo, 'RESTORE', "تمت استعادة قاعدة البيانات بنجاح من الملف المرفوع: $file_name");
                        
                        $_SESSION['flash_success'] = "تمت استعادة قاعدة البيانات بنجاح وتنفيذ $executed_count استعلاماً.";
                        header("Location: backup.php");
                        exit;
                        
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = "فشلت عملية الاستعادة: " . $e->getMessage();
                    }
                }
            } else {
                $error = "يرجى اختيار ملف النسخة الاحتياطية (.sql) لرفعه أولاً.";
            }
        }
    }
}
require_once __DIR__ . '/includes/header.php';
?>

<div class="top-navbar bg-white p-4 mb-4 rounded-4 shadow-sm border-0">
    <h3 class="fw-bold m-0 text-success">
        <i class="bi bi-database-down me-1"></i> النسخ الاحتياطي والاستعادة لقاعدة البيانات
    </h3>
    <p class="text-muted m-0 fs-7">قم بتحميل نسخة احتياطية دورياً أو استعادتها لحماية البيانات من الضياع</p>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger rounded-4 d-flex align-items-center mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div><?= htmlspecialchars($error) ?></div>
    </div>
<?php endif; ?>

<div class="row g-4 animated-fade-in">
    <!-- Backup Card -->
    <div class="col-12 col-md-6">
        <div class="card premium-card p-4 h-100 border-0 shadow-sm d-flex flex-column justify-content-between">
            <div>
                <div class="card-icon-wrapper bg-success text-white mb-3">
                    <i class="bi bi-cloud-arrow-down-fill"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2">توليد ونسخ البيانات احتياطياً</h5>
                <p class="text-muted fs-7 mb-4">
                    سيقوم هذا الخيار بتجميع كافة البيانات المخزنة بالنظام (الأشخاص، الأحياء، الشوارع، المستخدمين وسجلات النظام) وتصديرها كملف بصيغة <strong>.sql</strong>. يمكنك تحميله وحفظه على جهازك بأمان.
                </p>
            </div>
            
            <form method="POST" action="backup.php" class="d-grid mt-3">
                <input type="hidden" name="action" value="backup">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <button type="submit" class="btn btn-premium btn-lg w-100 py-3">
                    <i class="bi bi-download me-1"></i> بدء تحميل النسخة الاحتياطية
                </button>
            </form>
        </div>
    </div>

    <!-- Restore Card -->
    <div class="col-12 col-md-6">
        <div class="card premium-card p-4 h-100 border-0 shadow-sm d-flex flex-column justify-content-between">
            <div>
                <div class="card-icon-wrapper bg-warning text-dark mb-3">
                    <i class="bi bi-cloud-arrow-up-fill"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2">استعادة قاعدة البيانات</h5>
                <p class="text-muted fs-7 mb-3">
                    قم برفع ملف النسخة الاحتياطية السابق (.sql) لاسترجاع البيانات بالكامل.
                </p>
                <div class="alert alert-warning rounded-3 fs-8 p-3 mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-6 text-danger"></i>
                    <strong class="text-danger">تحذير هام جداً:</strong> سيؤدي رفع ملف النسخ الاحتياطي واستعادته إلى <strong>مسح كافة البيانات الحالية</strong> المخزنة في النظام واستبدالها بالبيانات الموجودة في الملف.
                </div>
            </div>
            
            <form method="POST" action="backup.php" enctype="multipart/form-data" class="mt-3">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="mb-3">
                    <input class="form-control" type="file" id="backup_file" name="backup_file" accept=".sql" required>
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-warning btn-lg w-100 py-3 fw-bold confirm-delete"
                            data-message="تحذير! هل أنت متأكد تماماً من رغبتك في استعادة قاعدة البيانات؟ سيتم استبدال وحذف كافة البيانات والملفات المسجلة حالياً بالكامل!">
                        <i class="bi bi-upload me-1"></i> استعادة البيانات الآن
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
