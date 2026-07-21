<?php
// index.php
require_once __DIR__ . '/includes/header.php';

$user_role = get_user_role();
$user_nh_id = get_user_neighborhood_id();

// Initialize stats
$total_citizens = 0;
$total_neighborhoods = 0;
$total_users = 0;

try {
    if (is_admin()) {
        // Stats for General Admin (All system)
        $total_citizens = $pdo->query("SELECT COUNT(*) FROM citizens")->fetchColumn();
        $total_neighborhoods = $pdo->query("SELECT COUNT(*) FROM neighborhoods")->fetchColumn();
        $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        
        // Fetch neighborhoods and managers list
        $nh_stmt = $pdo->query("SELECT n.*, 
                                      (SELECT COUNT(c.id) 
                                       FROM streets s 
                                       LEFT JOIN citizens c ON s.id = c.street_id 
                                       WHERE s.neighborhood_id = n.id) as citizens_count 
                               FROM neighborhoods n 
                               ORDER BY n.name ASC");
        $neighborhoods = $nh_stmt->fetchAll();
        
        // Fetch recent logs
        $log_stmt = $pdo->query("SELECT a.*, u.username 
                                 FROM activity_logs a 
                                 LEFT JOIN users u ON a.user_id = u.id 
                                 ORDER BY a.created_at DESC 
                                 LIMIT 5");
        $recent_logs = $log_stmt->fetchAll();
        
        // Data for Chart.js
        $chart_labels = [];
        $chart_data = [];
        foreach ($neighborhoods as $nh) {
            $chart_labels[] = $nh['name'];
            $chart_data[] = (int)$nh['citizens_count'];
        }
    } else {
        // Stats for Neighborhood Manager (Only their neighborhood)
        $stmt_nh_citizens = $pdo->prepare("SELECT COUNT(c.id) 
                                           FROM citizens c 
                                           JOIN streets s ON c.street_id = s.id 
                                           WHERE s.neighborhood_id = :nh_id");
        $stmt_nh_citizens->execute([':nh_id' => $user_nh_id]);
        $total_citizens = $stmt_nh_citizens->fetchColumn();
        
        // Fetch only the assigned neighborhood
        $nh_stmt = $pdo->prepare("SELECT n.*, 
                                        (SELECT COUNT(c.id) 
                                         FROM streets s 
                                         LEFT JOIN citizens c ON s.id = c.street_id 
                                         WHERE s.neighborhood_id = n.id) as citizens_count 
                                 FROM neighborhoods n 
                                 WHERE n.id = :nh_id");
        $nh_stmt->execute([':nh_id' => $user_nh_id]);
        $neighborhoods = $nh_stmt->fetchAll();
        
        // Fetch manager's own recent activity logs
        $log_stmt = $pdo->prepare("SELECT a.*, u.username 
                                   FROM activity_logs a 
                                   LEFT JOIN users u ON a.user_id = u.id 
                                   WHERE a.user_id = :user_id 
                                   ORDER BY a.created_at DESC 
                                   LIMIT 5");
        $log_stmt->execute([':user_id' => get_user_id()]);
        $recent_logs = $log_stmt->fetchAll();
    }
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">حدث خطأ في جلب البيانات: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>

<div class="row g-4 mb-4 animated-fade-in">
    <!-- Stat Card 1: Citizens -->
    <div class="col-12 col-sm-6 col-xl-4">
        <div class="card premium-card p-4">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted d-block mb-1 fw-bold">إجمالي الأشخاص المسجلين</span>
                    <h2 class="m-0 fw-extrabold text-success"><?= $total_citizens ?></h2>
                </div>
                <div class="card-icon-wrapper bg-success text-white">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
            <div class="mt-3">
                <small class="text-muted">المسجلين في النظام النشط حالياً</small>
            </div>
        </div>
    </div>

    <!-- Stat Card 2: Neighborhoods / Assigned Area -->
    <div class="col-12 col-sm-6 col-xl-4">
        <div class="card premium-card p-4">
            <div class="d-flex align-items-center justify-content-between">
                <?php if (is_admin()): ?>
                    <div>
                        <span class="text-muted d-block mb-1 fw-bold">عدد الأحياء النشطة</span>
                        <h2 class="m-0 fw-extrabold text-dark"><?= $total_neighborhoods ?></h2>
                    </div>
                    <div class="card-icon-wrapper bg-dark text-white">
                        <i class="bi bi-geo-alt-fill"></i>
                    </div>
                <?php else: ?>
                    <div>
                        <span class="text-muted d-block mb-1 fw-bold">حيّ الإشراف الحالي</span>
                        <h4 class="m-0 fw-bold text-dark text-truncate" style="max-width: 200px;">
                            <?= sanitize($_SESSION['neighborhood_name'] ?? 'غير معين') ?>
                        </h4>
                    </div>
                    <div class="card-icon-wrapper bg-primary text-white">
                        <i class="bi bi-shield-check"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-3">
                <small class="text-muted"><?= is_admin() ? 'الأحياء التابعة للحزب بالمدينة' : 'نطاق صلاحياتك الحالية' ?></small>
            </div>
        </div>
    </div>

    <!-- Stat Card 3: Users / Neighborhood Managers -->
    <div class="col-12 col-sm-6 col-xl-4">
        <div class="card premium-card p-4">
            <div class="d-flex align-items-center justify-content-between">
                <?php if (is_admin()): ?>
                    <div>
                        <span class="text-muted d-block mb-1 fw-bold">مشرفي النظام</span>
                        <h2 class="m-0 fw-extrabold text-info"><?= $total_users ?></h2>
                    </div>
                    <div class="card-icon-wrapper bg-info text-white">
                        <i class="bi bi-person-badge-fill"></i>
                    </div>
                <?php else: ?>
                    <div>
                        <span class="text-muted d-block mb-1 fw-bold">مستوى صلاحياتك</span>
                        <h4 class="m-0 fw-bold text-info">مسؤول حيّ</h4>
                    </div>
                    <div class="card-icon-wrapper bg-info text-white">
                        <i class="bi bi-lock-fill"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-3">
                <small class="text-muted"><?= is_admin() ? 'حسابات إدارة الأحياء والمدراء العامين' : 'يقتصر وصولك لحي الإشراف الخاص بك' ?></small>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Chart and Stats (Only for General Admin) -->
    <?php if (is_admin() && !empty($chart_labels)): ?>
        <div class="col-12 col-lg-8">
            <div class="card premium-card p-4 h-100">
                <h5 class="fw-bold text-success mb-4"><i class="bi bi-bar-chart-line me-2"></i>توزيع المواطنين المسجلين حسب الأحياء</h5>
                <div style="position: relative; height: 300px;">
                    <canvas id="neighborhoodsChart"></canvas>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Recent Logs -->
    <div class="col-12 <?= (is_admin() && !empty($chart_labels)) ? 'col-lg-4' : 'col-lg-12' ?>">
        <div class="card premium-card p-4 h-100">
            <h5 class="fw-bold text-dark mb-4"><i class="bi bi-clock-history me-2"></i>آخر العمليات المنجزة</h5>
            <div class="d-flex flex-column gap-2">
                <?php if (empty($recent_logs)): ?>
                    <p class="text-muted text-center my-4">لا توجد عمليات مسجلة حالياً.</p>
                <?php else: ?>
                    <?php foreach ($recent_logs as $log): 
                        $log_class = '';
                        if (str_contains($log['action'], 'DELETE')) $log_class = 'delete';
                        elseif (str_contains($log['action'], 'UPDATE')) $log_class = 'update';
                        elseif (str_contains($log['action'], 'LOGIN') || str_contains($log['action'], 'LOGOUT')) $log_class = 'auth';
                    ?>
                        <div class="log-item <?= $log_class ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <strong class="fs-7 text-dark"><?= sanitize($log['username'] ?? 'النظام') ?></strong>
                                <small class="text-muted fs-8 font-monospace"><?= date('Y-m-d H:i', strtotime($log['created_at'])) ?></small>
                            </div>
                            <p class="m-0 fs-7 text-muted mt-1"><?= sanitize($log['details']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Neighborhoods Directory (Main Feature) -->
<div class="card custom-table-card animated-fade-in">
    <div class="card-header bg-white border-0 py-3 px-4 d-flex align-items-center justify-content-between">
        <h5 class="fw-bold m-0 text-success"><i class="bi bi-geo-alt me-2"></i>فهرس الأحياء التابعة للنظام</h5>
    </div>
    <div class="table-responsive">
        <table class="table custom-table m-0">
            <thead>
                <tr>
                    <th>اسم الحيّ</th>
                    <th>المسؤول المباشر عن الحيّ</th>
                    <th class="text-center">الأشخاص المسجلين</th>
                    <th class="text-center no-print">خيارات التحكم</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($neighborhoods)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-5">لا توجد أحياء مسجلة في قاعدة البيانات.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($neighborhoods as $nh): ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-dark fs-6"><?= sanitize($nh['name']) ?></span>
                            </td>
                            <td>
                                <span class="text-muted"><i class="bi bi-person me-1"></i><?= sanitize($nh['manager_name']) ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2 fw-semibold">
                                    <?= $nh['citizens_count'] ?> مواطن
                                </span>
                            </td>
                            <td class="text-center no-print">
                                <a href="streets.php?neighborhood_id=<?= $nh['id'] ?>" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">
                                    عرض الشوارع (29 زنقة) <i class="bi bi-arrow-left-short align-middle"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Script to generate dynamic chart if admin -->
<?php if (is_admin() && !empty($chart_labels)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('neighborhoodsChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'عدد المواطنين المسجلين',
                    data: <?= json_encode($chart_data) ?>,
                    backgroundColor: 'rgba(25, 135, 84, 0.75)',
                    borderColor: 'rgb(25, 135, 84)',
                    borderWidth: 1,
                    borderRadius: 8,
                    barPercentage: 0.5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f1f3f5'
                        },
                        ticks: {
                            font: {
                                family: 'Cairo'
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                family: 'Cairo',
                                weight: 'bold'
                            }
                        }
                    }
                }
            }
        });
    });
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
