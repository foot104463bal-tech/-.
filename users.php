<?php
// users.php
require_once __DIR__ . '/includes/header.php';

// Restrict to General Admin only
require_admin();

$csrf_token = generate_csrf_token();
$error = '';
$success = '';

// Handle POST actions (Create or Delete user)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = "خطأ في التحقق من أمان الطلب (CSRF).";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $role = $_POST['role'] ?? 'manager';
            $neighborhood_id = isset($_POST['neighborhood_id']) ? (int)$_POST['neighborhood_id'] : 0;
            
            if ($role === 'admin') {
                $neighborhood_id = null; // Admins are not linked to neighborhoods
            }
            
            if (empty($username) || empty($password)) {
                $error = "يرجى ملء جميع الحقول المطلوبة (اسم المستخدم وكلمة المرور).";
            } elseif (strlen($password) < 6) {
                $error = "يجب أن تتكون كلمة المرور من 6 أحرف على الأقل.";
            } else {
                try {
                    // Check if username exists
                    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $check_stmt->execute([$username]);
                    if ($check_stmt->fetchColumn() > 0) {
                        $error = "اسم المستخدم ($username) مسجل بالفعل في النظام.";
                    } else {
                        // Create User
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $insert_stmt = $pdo->prepare("INSERT INTO users (username, password, role, neighborhood_id) VALUES (?, ?, ?, ?)");
                        $insert_stmt->execute([$username, $hash, $role, $neighborhood_id ? $neighborhood_id : null]);
                        
                        log_activity($pdo, 'ADD_USER', "أنشأ حساباً جديداً للمستخدم: $username بصلاحية: " . ($role === 'admin' ? 'مدير عام' : 'مسؤول حي'));
                        
                        $_SESSION['flash_success'] = "تم إنشاء المستخدم الجديد ($username) بنجاح.";
                        header("Location: users.php");
                        exit;
                    }
                } catch (PDOException $e) {
                    $error = "خطأ في قاعدة البيانات: " . $e->getMessage();
                }
            }
        } elseif ($action === 'delete') {
            $delete_id = (int)($_POST['id'] ?? 0);
            
            if ($delete_id === (int)get_user_id()) {
                $error = "لا يمكنك حذف حسابك الحالي الذي تستخدمه لتسجيل الدخول.";
            } elseif ($delete_id <= 0) {
                $error = "معرّف المستخدم غير صالح.";
            } else {
                try {
                    // Get username before delete for logging
                    $u_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                    $u_stmt->execute([$delete_id]);
                    $del_username = $u_stmt->fetchColumn();
                    
                    if ($del_username) {
                        $delete_stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $delete_stmt->execute([$delete_id]);
                        
                        log_activity($pdo, 'DELETE_USER', "حذف حساب المستخدم: $del_username من النظام");
                        
                        $_SESSION['flash_success'] = "تم حذف حساب المستخدم ($del_username) نهائياً.";
                        header("Location: users.php");
                        exit;
                    } else {
                        $error = "المستخدم المطلوب حذفه غير موجود.";
                    }
                } catch (PDOException $e) {
                    $error = "خطأ في قاعدة البيانات: " . $e->getMessage();
                }
            }
        }
    }
}

try {
    // Fetch all users
    $users_stmt = $pdo->query("SELECT u.*, n.name as neighborhood_name 
                               FROM users u 
                               LEFT JOIN neighborhoods n ON u.neighborhood_id = n.id 
                               ORDER BY u.role ASC, u.username ASC");
    $users = $users_stmt->fetchAll();
    
    // Fetch all neighborhoods for the select dropdown
    $nh_stmt = $pdo->query("SELECT id, name FROM neighborhoods ORDER BY name ASC");
    $neighborhoods = $nh_stmt->fetchAll();
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
?>

<div class="top-navbar bg-white p-4 mb-4 rounded-4 shadow-sm border-0 d-flex flex-wrap align-items-center justify-content-between">
    <div>
        <h3 class="fw-bold m-0 text-success">
            <i class="bi bi-people-fill me-1"></i> إدارة المستخدمين وصلاحياتهم
        </h3>
        <p class="text-muted m-0 fs-7">إضافة وتعديل وحذف المسؤولين المباشرين عن الأحياء</p>
    </div>
    
    <div>
        <button class="btn btn-premium rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus-fill me-1"></i> إضافة مسؤول جديد
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger rounded-4 d-flex align-items-center mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div><?= htmlspecialchars($error) ?></div>
    </div>
<?php endif; ?>

<!-- Users List -->
<div class="card custom-table-card animated-fade-in">
    <div class="table-responsive">
        <table class="table custom-table m-0">
            <thead>
                <tr>
                    <th>اسم المستخدم</th>
                    <th>مستوى الصلاحيات</th>
                    <th>الحيّ المشرف عليه</th>
                    <th>تاريخ التسجيل</th>
                    <th class="text-center">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-light p-2 rounded-circle text-success"><i class="bi bi-person-fill fs-5"></i></div>
                                <span class="fw-bold text-dark fs-6"><?= sanitize($user['username']) ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= ($user['role'] === 'admin') ? 'bg-success' : 'bg-secondary' ?> px-3 py-2 rounded-pill fs-7">
                                <?= ($user['role'] === 'admin') ? 'مدير عام (Admin)' : 'مسؤول حيّ (Manager)' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($user['role'] === 'admin'): ?>
                                <span class="text-muted fs-7"><i class="bi bi-globe me-1"></i>كامل النظام</span>
                            <?php else: ?>
                                <span class="fw-bold text-success fs-7">
                                    <i class="bi bi-geo-alt me-1"></i><?= sanitize($user['neighborhood_name'] ?? 'غير معين') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="text-muted fs-7 font-monospace"><?= date('Y-m-d H:i', strtotime($user['created_at'])) ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($user['id'] === (int)get_user_id()): ?>
                                <span class="text-muted fs-8"><i class="bi bi-shield-lock me-1"></i>حسابك النشط</span>
                            <?php else: ?>
                                <form method="POST" action="users.php" class="d-inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 fw-bold confirm-delete"
                                            data-message="هل أنت متأكد من رغبتك في حذف حساب المستخدم (<?= sanitize($user['username']) ?>) نهائياً من النظام؟">
                                        <i class="bi bi-person-x-fill"></i> حذف الحساب
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= MODAL: ADD USER ================= -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-success text-white py-3">
                <h5 class="modal-title fw-bold" id="addUserModalLabel"><i class="bi bi-person-plus-fill me-1"></i> إضافة مسؤول جديد للنظام</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="users.php" id="addUserForm">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="username" class="form-label fw-bold">اسم المستخدم (Username) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="username" name="username" required placeholder="مثال: ahmad_riad">
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label fw-bold">كلمة المرور <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required placeholder="أدخل كلمة مرور (6 أحرف كحد أدنى)" minlength="6">
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label fw-bold">نوع الصلاحية <span class="text-danger">*</span></label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="manager" selected>مسؤول حي (يقيد بنطاق محدد)</option>
                            <option value="admin">مدير عام للنظام (كامل الصلاحيات)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="neighborhood_select_wrapper">
                        <label for="neighborhood_id" class="form-label fw-bold">الحيّ المسؤول عنه <span class="text-danger">*</span></label>
                        <select class="form-select" id="neighborhood_id" name="neighborhood_id">
                            <option value="" disabled selected>اختر الحي...</option>
                            <?php foreach ($neighborhoods as $nh): ?>
                                <option value="<?= $nh['id'] ?>"><?= sanitize($nh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">إنشاء الحساب</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const roleSelect = document.getElementById('role');
        const nhWrapper = document.getElementById('neighborhood_select_wrapper');
        const nhSelect = document.getElementById('neighborhood_id');
        
        roleSelect.addEventListener('change', function() {
            if (this.value === 'admin') {
                nhWrapper.style.display = 'none';
                nhSelect.removeAttribute('required');
            } else {
                nhWrapper.style.display = 'block';
                nhSelect.setAttribute('required', 'required');
            }
        });
        
        // Form validations
        document.getElementById('addUserForm').addEventListener('submit', function(e) {
            if (roleSelect.value === 'manager' && !nhSelect.value) {
                e.preventDefault();
                alert('يرجى تحديد الحي الذي سيشرف عليه المسؤول الجديد.');
            }
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
