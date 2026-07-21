<?php
// people.php
require_once __DIR__ . '/includes/header.php';

// Validate street_id
$street_id = isset($_GET['street_id']) ? (int)$_GET['street_id'] : 0;

if ($street_id <= 0) {
    $_SESSION['flash_error'] = "معرّف الشارع غير صالح.";
    header("Location: index.php");
    exit;
}

try {
    // Get street & neighborhood info
    $street_stmt = $pdo->prepare("SELECT s.*, n.id as neighborhood_id, n.name as neighborhood_name 
                                  FROM streets s 
                                  JOIN neighborhoods n ON s.neighborhood_id = n.id 
                                  WHERE s.id = :id");
    $street_stmt->execute([':id' => $street_id]);
    $street_info = $street_stmt->fetch();
    
    if (!$street_info) {
        $_SESSION['flash_error'] = "الشارع المطلوب غير موجود.";
        header("Location: index.php");
        exit;
    }
    
    $neighborhood_id = $street_info['neighborhood_id'];
    
    // Security check: check neighborhood access
    if (!has_neighborhood_access($neighborhood_id)) {
        $_SESSION['flash_error'] = "غير مصرح لك بالوصول لبيانات هذا الحي.";
        header("Location: index.php");
        exit;
    }
    
    // Handle Search and Filters
    $search = trim($_GET['search'] ?? '');
    $sort_by = $_GET['sort_by'] ?? 'first_name';
    $sort_order = $_GET['sort_order'] ?? 'ASC';
    
    // Validate sort fields to prevent SQL injection in ORDER BY
    $valid_sorts = ['first_name', 'last_name', 'national_id', 'created_at'];
    if (!in_array($sort_by, $valid_sorts)) {
        $sort_by = 'first_name';
    }
    $sort_order = ($sort_order === 'DESC') ? 'DESC' : 'ASC';
    
    // Build Query
    $query_str = "SELECT * FROM citizens WHERE street_id = :street_id";
    $query_params = [':street_id' => $street_id];
    
    if (!empty($search)) {
        $query_str .= " AND (first_name LIKE :search OR last_name LIKE :search OR national_id LIKE :search OR phone LIKE :search)";
        $query_params[':search'] = '%' . $search . '%';
    }
    
    $query_str .= " ORDER BY " . $sort_by . " " . $sort_order;
    
    $citizens_stmt = $pdo->prepare($query_str);
    $citizens_stmt->execute($query_params);
    $citizens = $citizens_stmt->fetchAll();
    
    $csrf_token = generate_csrf_token();
    
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">خطأ في جلب البيانات: ' . htmlspecialchars($e->getMessage()) . '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
?>

<div class="top-navbar bg-white p-4 mb-4 rounded-4 shadow-sm border-0 d-flex flex-wrap align-items-center justify-content-between">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="index.php" class="text-success text-decoration-none">لوحة التحكم</a></li>
                <li class="breadcrumb-item"><a href="streets.php?neighborhood_id=<?= $neighborhood_id ?>" class="text-success text-decoration-none"><?= sanitize($street_info['neighborhood_name']) ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">زنقة <?= $street_info['street_number'] ?></li>
            </ol>
        </nav>
        <h3 class="fw-bold m-0 text-success">
            <i class="bi bi-road me-1"></i> زنقة <?= $street_info['street_number'] ?> - <?= sanitize($street_info['neighborhood_name']) ?>
        </h3>
        <span class="text-muted fs-7">إجمالي المسجلين في هذا الشارع: <strong><?= count($citizens) ?> شخص</strong></span>
    </div>
    
    <div class="no-print mt-2 mt-sm-0 d-flex gap-2">
        <button class="btn btn-premium rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#addCitizenModal">
            <i class="bi bi-person-plus-fill me-1"></i> إضافة شخص جديد
        </button>
        <a href="streets.php?neighborhood_id=<?= $neighborhood_id ?>" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
            <i class="bi bi-arrow-right-short fs-5 align-middle"></i> العودة للشوارع
        </a>
    </div>
</div>

<!-- Search, Filter, and Export Section -->
<div class="card premium-card p-4 mb-4 border-0 shadow-sm no-print">
    <form method="GET" action="people.php" class="row g-3 align-items-end">
        <input type="hidden" name="street_id" value="<?= $street_id ?>">
        
        <div class="col-12 col-md-5">
            <label for="search" class="form-label fw-bold text-dark"><i class="bi bi-search me-1"></i> البحث عن الأشخاص</label>
            <div class="input-group">
                <input type="text" class="form-control" id="search" name="search" placeholder="ابحث بالاسم الشخصي، العائلي أو بطاقة التعريف الوطنية..." value="<?= sanitize($search) ?>">
                <?php if (!empty($search)): ?>
                    <a href="people.php?street_id=<?= $street_id ?>" class="btn btn-outline-secondary d-flex align-items-center"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="col-6 col-md-3">
            <label for="sort_by" class="form-label fw-bold text-dark"><i class="bi bi-filter me-1"></i> ترتيب حسب</label>
            <select class="form-select" id="sort_by" name="sort_by">
                <option value="first_name" <?= ($sort_by === 'first_name') ? 'selected' : '' ?>>الاسم الشخصي</option>
                <option value="last_name" <?= ($sort_by === 'last_name') ? 'selected' : '' ?>>الاسم العائلي</option>
                <option value="national_id" <?= ($sort_by === 'national_id') ? 'selected' : '' ?>>رقم البطاقة الوطنية</option>
                <option value="created_at" <?= ($sort_by === 'created_at') ? 'selected' : '' ?>>تاريخ التسجيل</option>
            </select>
        </div>
        
        <div class="col-6 col-md-2">
            <label for="sort_order" class="form-label fw-bold text-dark">الاتجاه</label>
            <select class="form-select" id="sort_order" name="sort_order">
                <option value="ASC" <?= ($sort_order === 'ASC') ? 'selected' : '' ?>>تصاعدي</option>
                <option value="DESC" <?= ($sort_order === 'DESC') ? 'selected' : '' ?>>تنازلي</option>
            </select>
        </div>
        
        <div class="col-12 col-md-2 d-grid gap-2">
            <button type="submit" class="btn btn-success rounded-3 fw-bold py-2"><i class="bi bi-funnel-fill"></i> تصفية</button>
        </div>
    </form>
    
    <div class="border-top border-light mt-4 pt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted fs-7">تصدير قوائم البيانات:</div>
        <div class="d-flex gap-2">
            <a href="export.php?street_id=<?= $street_id ?>&format=csv&search=<?= urlencode($search) ?>" class="btn btn-outline-success btn-sm rounded-3 fw-bold">
                <i class="bi bi-file-earmark-excel me-1"></i> تصدير Excel (CSV)
            </a>
            <button onclick="window.print();" class="btn btn-outline-dark btn-sm rounded-3 fw-bold">
                <i class="bi bi-printer me-1"></i> طباعة القائمة
            </button>
        </div>
    </div>
</div>

<!-- Print-only header -->
<div class="d-none d-print-block text-center mb-4">
    <h2>حزب المستقبل - قائمة المسجلين</h2>
    <h5>حي: <?= sanitize($street_info['neighborhood_name']) ?> | زنقة: <?= $street_info['street_number'] ?></h5>
    <p class="text-muted small">تم الاستخراج بتاريخ: <?= date('Y-m-d H:i') ?></p>
</div>

<!-- Citizens Table Card -->
<div class="card custom-table-card animated-fade-in">
    <div class="table-responsive">
        <table class="table custom-table m-0">
            <thead>
                <tr>
                    <th>الاسم الشخصي</th>
                    <th>الاسم العائلي</th>
                    <th>رقم البطاقة الوطنية</th>
                    <th>رقم الهاتف</th>
                    <th>العنوان</th>
                    <th>ملاحظات</th>
                    <th class="text-center no-print">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($citizens)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-person-slash fs-1 d-block mb-3 text-muted"></i>
                            لا يوجد أي أشخاص مسجلين يطابقون خيارات البحث في هذا الشارع حالياً.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($citizens as $citizen): ?>
                        <tr>
                            <td><span class="fw-bold text-dark"><?= sanitize($citizen['first_name']) ?></span></td>
                            <td><span class="fw-bold text-dark"><?= sanitize($citizen['last_name']) ?></span></td>
                            <td><span class="badge bg-secondary-subtle text-dark border border-secondary rounded px-2 font-monospace"><?= sanitize($citizen['national_id']) ?></span></td>
                            <td>
                                <?php if (!empty($citizen['phone'])): ?>
                                    <a href="tel:<?= sanitize($citizen['phone']) ?>" class="text-decoration-none text-success fw-semibold"><i class="bi bi-telephone me-1"></i><?= sanitize($citizen['phone']) ?></a>
                                <?php else: ?>
                                    <span class="text-muted fs-8">غير متوفر</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-muted text-wrap d-inline-block fs-7" style="max-width: 250px;">
                                    <?= !empty($citizen['address']) ? sanitize($citizen['address']) : 'غير محدد' ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-muted text-wrap d-inline-block fs-7" style="max-width: 200px;">
                                    <?= !empty($citizen['notes']) ? sanitize($citizen['notes']) : '-' ?>
                                </span>
                            </td>
                            <td class="text-center no-print">
                                <div class="d-flex justify-content-center gap-2">
                                    <button class="btn btn-sm btn-outline-warning rounded-pill px-3 py-1 fw-bold edit-btn" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editCitizenModal"
                                            data-id="<?= $citizen['id'] ?>"
                                            data-first_name="<?= sanitize($citizen['first_name']) ?>"
                                            data-last_name="<?= sanitize($citizen['last_name']) ?>"
                                            data-national_id="<?= sanitize($citizen['national_id']) ?>"
                                            data-phone="<?= sanitize($citizen['phone']) ?>"
                                            data-address="<?= sanitize($citizen['address']) ?>"
                                            data-notes="<?= sanitize($citizen['notes']) ?>">
                                        <i class="bi bi-pencil-square"></i> تعديل
                                    </button>
                                    <form method="POST" action="person-actions.php" class="d-inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $citizen['id'] ?>">
                                        <input type="hidden" name="street_id" value="<?= $street_id ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 fw-bold confirm-delete" 
                                                data-message="هل أنت متأكد من رغبتك في حذف المواطن (<?= sanitize($citizen['first_name'] . ' ' . $citizen['last_name']) ?>) نهائياً من هذا الشارع؟">
                                            <i class="bi bi-trash"></i> حذف
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= MODAL: ADD CITIZEN ================= -->
<div class="modal fade" id="addCitizenModal" tabindex="-1" aria-labelledby="addCitizenModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-success text-white py-3">
                <h5 class="modal-title fw-bold" id="addCitizenModalLabel"><i class="bi bi-person-plus-fill me-1"></i> إضافة شخص جديد للشارع</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="person-actions.php">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="street_id" value="<?= $street_id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label fw-bold">الاسم الشخصي <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="first_name" name="first_name" required placeholder="أدخل الاسم الشخصي">
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label fw-bold">الاسم العائلي <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required placeholder="أدخل الاسم العائلي">
                        </div>
                        <div class="col-md-6">
                            <label for="national_id" class="form-label fw-bold">رقم البطاقة الوطنية (CNI) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control national-id-input" id="national_id" name="national_id" required placeholder="مثال: AB123456" maxlength="20">
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label fw-bold">رقم الهاتف (اختياري)</label>
                            <input type="tel" class="form-control" id="phone" name="phone" placeholder="مثال: 0612345678">
                        </div>
                        <div class="col-12">
                            <label for="address" class="form-label fw-bold">العنوان التفصيلي (اختياري)</label>
                            <input type="text" class="form-control" id="address" name="address" placeholder="أدخل عنوان السكن بالتفصيل">
                        </div>
                        <div class="col-12">
                            <label for="notes" class="form-label fw-bold">ملاحظات (اختياري)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="أدخل أي ملاحظات إضافية بخصوص الشخص..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">حفظ الشخص</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= MODAL: EDIT CITIZEN ================= -->
<div class="modal fade" id="editCitizenModal" tabindex="-1" aria-labelledby="editCitizenModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-warning text-dark py-3">
                <h5 class="modal-title fw-bold" id="editCitizenModalLabel"><i class="bi bi-pencil-square me-1"></i> تعديل بيانات الشخص</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="person-actions.php">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="street_id" value="<?= $street_id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="edit_first_name" class="form-label fw-bold">الاسم الشخصي <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_last_name" class="form-label fw-bold">الاسم العائلي <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_national_id" class="form-label fw-bold">رقم البطاقة الوطنية (CNI) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control national-id-input" id="edit_national_id" name="national_id" required maxlength="20">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_phone" class="form-label fw-bold">رقم الهاتف (اختياري)</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone">
                        </div>
                        <div class="col-12">
                            <label for="edit_address" class="form-label fw-bold">العنوان التفصيلي (اختياري)</label>
                            <input type="text" class="form-control" id="edit_address" name="address">
                        </div>
                        <div class="col-12">
                            <label for="edit_notes" class="form-label fw-bold">ملاحظات (اختياري)</label>
                            <textarea class="form-control" id="edit_notes" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold">تحديث البيانات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Handle filling the Edit Modal
        const editButtons = document.querySelectorAll('.edit-btn');
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('edit_id').value = this.getAttribute('data-id');
                document.getElementById('edit_first_name').value = this.getAttribute('data-first_name');
                document.getElementById('edit_last_name').value = this.getAttribute('data-last_name');
                document.getElementById('edit_national_id').value = this.getAttribute('data-national_id');
                document.getElementById('edit_phone').value = this.getAttribute('data-phone');
                document.getElementById('edit_address').value = this.getAttribute('data-address');
                document.getElementById('edit_notes').value = this.getAttribute('data-notes');
            });
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
