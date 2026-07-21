<?php
// install.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config_file = __DIR__ . '/config/db_config.php';
$is_installed = file_exists($config_file);

$error = '';
$success = '';

if ($is_installed && !isset($_GET['reinstall'])) {
    // System already installed view
    echo '<!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>النظام مثبت بالفعل</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: "Cairo", sans-serif; background: linear-gradient(135deg, #198754 0%, #0f5132 100%); min-height: 100vh; display: flex; align-items: center; }
            .card { border-radius: 20px; border: none; box-shadow: 0 15px 35px rgba(0,0,0,0.2); }
        </style>
    </head>
    <body>
        <div class="container text-center">
            <div class="card p-5 mx-auto bg-white" style="max-width: 550px;">
                <div class="text-success mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor" class="bi bi-check2-circle" viewBox="0 0 16 16">
                      <path d="M2.5 8a5.5 5.5 0 0 1 8.25-4.764.5.5 0 0 0 .5-.866A6.5 6.5 0 1 0 14.5 8a.5.5 0 0 0-1 0 5.5 5.5 0 1 1-11 0z"/>
                      <path d="M15.354 3.354a.5.5 0 0 0-.708-.708L8 9.293 5.354 6.646a.5.5 0 1 0-.708.708l3 3a.5.5 0 0 0 .708 0l7-7z"/>
                    </svg>
                </div>
                <h3 class="fw-bold mb-3 text-dark">النظام مثبت ويعمل بنجاح!</h3>
                <p class="text-muted mb-4">لقد تم إعداد قاعدة البيانات والاتصال مسبقًا. يمكنك الانتقال مباشرةً لصفحة تسجيل الدخول.</p>
                <div class="d-grid gap-2">
                    <a href="login.php" class="btn btn-success btn-lg rounded-pill fw-bold">الانتقال لتسجيل الدخول</a>
                    <a href="install.php?reinstall=1" class="btn btn-outline-danger btn-sm rounded-pill mt-3">إعادة التثبيت (سيتم حذف البيانات الحالية!)</a>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? 'political_party_db');
    $db_user = trim($_POST['db_user'] ?? 'root');
    $db_pass = trim($_POST['db_pass'] ?? '');
    
    $admin_username = trim($_POST['admin_username'] ?? 'admin');
    $admin_password = trim($_POST['admin_password'] ?? '');
    
    if (empty($admin_password)) {
        $error = "يرجى تعيين كلمة مرور لحساب المدير العام.";
    } else {
        try {
            // 1. Establish connection to MySQL server (without DB name first)
            $dsn_no_db = "mysql:host=" . $db_host . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn_no_db, $db_user, $db_pass, $options);
            
            // 2. Create database if it does not exist
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db_name`");
            
            // 3. Import schema.sql
            $schema_path = __DIR__ . '/database/schema.sql';
            if (!file_exists($schema_path)) {
                throw new Exception("ملف قاعدة البيانات database/schema.sql غير موجود!");
            }
            
            $sql = file_get_contents($schema_path);
            // Remove single line SQL comments
            $sql = preg_replace('/--.*\n/', '', $sql);
            // Remove multi-line SQL comments
            $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
            
            // Split queries by semicolon
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query)) {
                    $pdo->exec($query);
                }
            }
            
            // 4. Seed streets dynamically from PHP (1 to 29 for each neighborhood)
            $stmt = $pdo->query("SELECT id FROM neighborhoods");
            $neighborhoods = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $street_stmt = $pdo->prepare("INSERT IGNORE INTO streets (neighborhood_id, street_number) VALUES (?, ?)");
            foreach ($neighborhoods as $nid) {
                for ($i = 1; $i <= 29; $i++) {
                    $street_stmt->execute([$nid, $i]);
                }
            }
            
            // 5. Create initial Admin Account
            $admin_hash = password_hash($admin_password, PASSWORD_BCRYPT);
            // Check if user already exists
            $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check_stmt->execute([$admin_username]);
            if ($check_stmt->rowCount() > 0) {
                $update_stmt = $pdo->prepare("UPDATE users SET password = ?, role = 'admin', neighborhood_id = NULL WHERE username = ?");
                $update_stmt->execute([$admin_hash, $admin_username]);
            } else {
                $insert_stmt = $pdo->prepare("INSERT INTO users (username, password, role, neighborhood_id) VALUES (?, ?, 'admin', NULL)");
                $insert_stmt->execute([$admin_username, $admin_hash]);
            }
            
            // 6. Write db_config.php file
            $config_dir = __DIR__ . '/config';
            if (!is_dir($config_dir)) {
                mkdir($config_dir, 0755, true);
            }
            
            $config_content = "<?php\n// config/db_config.php\nreturn [\n    'host' => '" . addslashes($db_host) . "',\n    'dbname' => '" . addslashes($db_name) . "',\n    'user' => '" . addslashes($db_user) . "',\n    'pass' => '" . addslashes($db_pass) . "',\n];\n";
            file_put_contents($config_file, $config_content);
            
            // 7. Log installation event
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES ((SELECT id FROM users WHERE username = ?), 'INSTALL', 'تم تثبيت النظام وتهيئة قاعدة البيانات بنجاح من PHP', ?)");
            $log_stmt->execute([$admin_username, $ip]);
            
            $_SESSION['flash_success'] = "تم تثبيت النظام وتهيئة قاعدة البيانات بنجاح! يمكنك الآن تسجيل الدخول بالحساب الذي أنشأته.";
            header("Location: login.php");
            exit;
            
        } catch (Exception $e) {
            $error = "حدث خطأ أثناء التثبيت: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>معالج التثبيت - نظام حزب المستقبل</title>
    <!-- Bootstrap 5 RTL -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #157347 0%, #0f5132 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }
        .installer-card {
            background-color: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.25);
            border: none;
            overflow: hidden;
            width: 100%;
            max-width: 650px;
        }
        .installer-header {
            background-color: #0c3e27;
            padding: 2.5rem 2rem;
            color: #ffffff;
            text-align: center;
        }
        .form-label {
            font-weight: 600;
            color: #2b3a4a;
        }
        .form-control {
            border-radius: 12px;
            padding: 0.75rem 1rem;
            border: 1px solid #ced4da;
        }
        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.15);
        }
        .btn-install {
            background-color: #198754;
            color: #ffffff;
            border-radius: 12px;
            padding: 0.8rem 2rem;
            font-weight: 700;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-install:hover {
            background-color: #146c43;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(25, 135, 84, 0.3);
        }
    </style>
</head>
<body>

    <div class="container d-flex justify-content-center">
        <div class="installer-card card">
            <div class="installer-header">
                <i class="bi bi-bank2 fs-1 text-success mb-2 d-inline-block"></i>
                <h2 class="fw-bold m-0">معالج تثبيت نظام الحزب السياسي</h2>
                <p class="text-white-50 m-0 mt-1">تجهيز قاعدة البيانات وضبط الاتصال بالنظام</p>
            </div>
            
            <div class="card-body p-4 p-md-5">
                <?php if ($error): ?>
                    <div class="alert alert-danger rounded-4 d-flex align-items-center mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div><?= htmlspecialchars($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="install.php<?= isset($_GET['reinstall']) ? '?reinstall=1' : '' ?>">
                    
                    <h5 class="fw-bold text-success mb-3 border-bottom pb-2">
                        <i class="bi bi-database-gear me-1"></i> إعدادات قاعدة البيانات (MySQL)
                    </h5>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="db_host" class="form-label">خادم قاعدة البيانات (Host)</label>
                            <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" required>
                        </div>
                        <div class="col-md-6">
                            <label for="db_name" class="form-label">اسم قاعدة البيانات (DB Name)</label>
                            <input type="text" class="form-control" id="db_name" name="db_name" value="political_party_db" required>
                        </div>
                        <div class="col-md-6">
                            <label for="db_user" class="form-label">اسم مستخدم قاعدة البيانات</label>
                            <input type="text" class="form-control" id="db_user" name="db_user" value="root" required>
                        </div>
                        <div class="col-md-6">
                            <label for="db_pass" class="form-label">كلمة مرور قاعدة البيانات</label>
                            <input type="password" class="form-control" id="db_pass" name="db_pass" placeholder="اتركها فارغة في XAMPP الافتراضي">
                        </div>
                    </div>
                    
                    <h5 class="fw-bold text-success mb-3 border-bottom pb-2">
                        <i class="bi bi-person-lock me-1"></i> إعداد حساب المدير العام (Administrator)
                    </h5>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="admin_username" class="form-label">اسم مستخدم المدير</label>
                            <input type="text" class="form-control" id="admin_username" name="admin_username" value="admin" required>
                        </div>
                        <div class="col-md-6">
                            <label for="admin_password" class="form-label">كلمة مرور المدير العام</label>
                            <input type="password" class="form-control" id="admin_password" name="admin_password" placeholder="أدخل كلمة مرور قوية" required>
                        </div>
                    </div>
                    
                    <div class="alert alert-info rounded-4 text-start p-3 mb-4 fs-7">
                        <i class="bi bi-info-circle-fill me-2 fs-6"></i>
                        <span>سيقوم المعالج بإنشاء قاعدة البيانات تلقائيًا، وإدخال الجداول الأساسية، وتهيئة 4 أحياء افتراضية وبداخل كل حي 29 شارعًا مرقمًا (زنقة 1 إلى 29).</span>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-install btn-lg">تهيئة قاعدة البيانات وتثبيت النظام</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
</html>
