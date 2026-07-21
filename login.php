<?php
// login.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

// If already logged in, redirect to index
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "يرجى إدخال اسم المستخدم وكلمة المرور.";
    } else {
        try {
            // Find user
            $stmt = $pdo->prepare("SELECT u.*, n.name as neighborhood_name 
                                  FROM users u 
                                  LEFT JOIN neighborhoods n ON u.neighborhood_id = n.id 
                                  WHERE u.username = :username");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Regenerate session ID for security against Session Fixation
                session_regenerate_id(true);

                // Set session details
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['neighborhood_id'] = $user['neighborhood_id'];
                $_SESSION['neighborhood_name'] = $user['neighborhood_name'];

                // Log successful login
                log_activity($pdo, 'LOGIN', 'تسجيل دخول ناجح للمستخدم: ' . $user['username']);

                $_SESSION['flash_success'] = "مرحباً بك مجدداً، " . $user['username'] . "!";
                header("Location: index.php");
                exit;
            } else {
                // Log failed attempt
                log_activity($pdo, 'LOGIN_FAILED', 'محاولة تسجيل دخول فاشلة للمستخدم: ' . $username);
                $error = "اسم المستخدم أو كلمة المرور غير صحيحة.";
            }
        } catch (PDOException $e) {
            $error = "خطأ في النظام: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - نظام حزب المستقبل</title>
    <!-- Bootstrap 5 RTL -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #f4f6f9 0%, #e9ecef 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            border: none;
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            background-color: #ffffff;
            width: 100%;
            max-width: 450px;
        }
        .login-brand-header {
            background: linear-gradient(135deg, #0f5132 0%, #198754 100%);
            padding: 2.5rem 2rem;
            text-align: center;
            color: #ffffff;
        }
        .form-control {
            border-radius: 12px;
            padding: 0.75rem 1rem;
            border: 1px solid #ced4da;
            transition: all 0.2s ease;
        }
        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.15);
        }
        .btn-login {
            background-color: #198754;
            color: #ffffff;
            border-radius: 12px;
            padding: 0.8rem;
            font-weight: 700;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-login:hover {
            background-color: #0f5132;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(25, 135, 84, 0.2);
        }
    </style>
</head>
<body>

    <div class="container d-flex justify-content-center p-3">
        <div class="login-card card">
            <div class="login-brand-header">
                <i class="bi bi-bank2 fs-1 text-success mb-2 d-inline-block"></i>
                <h3 class="fw-bold m-0">بوابة الدخول الآمنة</h3>
                <p class="text-white-50 m-0 mt-1">نظام إدارة بيانات حزب المستقبل</p>
            </div>
            
            <div class="card-body p-4 p-md-5">
                <?php if (isset($_SESSION['flash_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show rounded-4 mb-4" role="alert">
                        <?= htmlspecialchars($_SESSION['flash_success']) ?>
                        <?php unset($_SESSION['flash_success']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger rounded-4 d-flex align-items-center mb-4" role="alert">
                        <i class="bi bi-exclamation-circle-fill me-2"></i>
                        <div><?= htmlspecialchars($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php">
                    <div class="mb-4">
                        <label for="username" class="form-label fw-semibold text-dark">اسم المستخدم</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 rounded-start-4 text-muted"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control border-start-0 rounded-end-4" id="username" name="username" placeholder="أدخل اسم المستخدم" required>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold text-dark">كلمة المرور</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 rounded-start-4 text-muted"><i class="bi bi-key"></i></span>
                            <input type="password" class="form-control border-start-0 rounded-end-4" id="password" name="password" placeholder="أدخل كلمة المرور" required>
                        </div>
                    </div>
                    
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-login">تسجيل الدخول</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
</html>
