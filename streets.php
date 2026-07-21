<?php
// streets.php
require_once __DIR__ . '/includes/header.php';

// Validate neighborhood_id
$neighborhood_id = isset($_GET['neighborhood_id']) ? (int)$_GET['neighborhood_id'] : 0;

if ($neighborhood_id <= 0) {
    $_SESSION['flash_error'] = "معرّف الحي غير صالح.";
    header("Location: index.php");
    exit;
}

// Security check: Check if user has access to this neighborhood
if (!has_neighborhood_access($neighborhood_id)) {
    $_SESSION['flash_error'] = "غير مصرح لك بالوصول لبيانات هذا الحي.";
    header("Location: index.php");
    exit;
}

try {
    // Get neighborhood details
    $nh_stmt = $pdo->prepare("SELECT * FROM neighborhoods WHERE id = :id");
    $nh_stmt->execute([':id' => $neighborhood_id]);
    $neighborhood = $nh_stmt->fetch();
    
    if (!$neighborhood) {
        $_SESSION['flash_error'] = "الحي المطلوب غير موجود في النظام.";
        header("Location: index.php");
        exit;
    }
    
    // Fetch all 29 streets and their citizen counts
    $streets_stmt = $pdo->prepare("SELECT s.*, 
                                          (SELECT COUNT(*) FROM citizens c WHERE c.street_id = s.id) as citizens_count 
                                   FROM streets s 
                                   WHERE s.neighborhood_id = :nh_id 
                                   ORDER BY s.street_number ASC");
    $streets_stmt->execute([':nh_id' => $neighborhood_id]);
    $streets = $streets_stmt->fetchAll();
    
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
                <li class="breadcrumb-item active" aria-current="page"><?= sanitize($neighborhood['name']) ?></li>
            </ol>
        </nav>
        <h3 class="fw-bold m-0 text-success">
            <i class="bi bi-geo-alt-fill me-1"></i> شوارع: <?= sanitize($neighborhood['name']) ?>
        </h3>
        <span class="text-muted fs-7"><i class="bi bi-person-badge me-1"></i>المسؤول عن الحي: <strong><?= sanitize($neighborhood['manager_name']) ?></strong></span>
    </div>
    
    <div class="no-print mt-2 mt-sm-0">
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
            <i class="bi bi-arrow-right-short fs-5 align-middle"></i> العودة للرئيسية
        </a>
    </div>
</div>

<!-- Streets Grid (29 Streets) -->
<h5 class="fw-bold text-dark mb-3"><i class="bi bi-grid-3x3-gap me-2"></i>قائمة شوارع الحي (29 زنقة مرقمة)</h5>

<div class="row g-3 animated-fade-in">
    <?php if (empty($streets)): ?>
        <div class="col-12">
            <div class="alert alert-warning text-center rounded-4 py-4">
                لم يتم إيجاد شوارع مرتبطة بهذا الحي. يرجى مراجعة مسؤول قاعدة البيانات.
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($streets as $street): ?>
            <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                <a href="people.php?street_id=<?= $street['id'] ?>" class="text-decoration-none text-dark">
                    <div class="card premium-card text-center p-3 h-100 d-flex flex-column justify-content-between border-0 shadow-sm">
                        <div class="mb-2">
                            <span class="badge bg-success-subtle text-success fs-7 mb-2 rounded-pill px-2">
                                <i class="bi bi-road"></i> زنقة <?= $street['street_number'] ?>
                            </span>
                            <h6 class="fw-bold text-dark m-0">زنقة <?= $street['street_number'] ?></h6>
                        </div>
                        
                        <div class="pt-2 border-top border-light">
                            <span class="fs-8 text-muted">
                                <i class="bi bi-people-fill text-success-subtle"></i> <?= $street['citizens_count'] ?> مسجل
                            </span>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
