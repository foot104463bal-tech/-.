<?php
// logs.php
require_once __DIR__ . '/includes/header.php';

// Restrict to General Admin
require_admin();

try {
    // Pagination parameters
    $limit = 30;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;
    
    // Filter variables
    $action_filter = trim($_GET['action_filter'] ?? '');
    $user_filter = trim($_GET['user_filter'] ?? '');
    
    // Build SQL query
    $where_clauses = [];
    $params = [];
    
    if (!empty($action_filter)) {
        $where_clauses[] = "a.action = :action_filter";
        $params[':action_filter'] = $action_filter;
    }
    
    if (!empty($user_filter)) {
        $where_clauses[] = "u.username LIKE :user_filter";
        $params[':user_filter'] = '%' . $user_filter . '%';
    }
    
    $where_sql = "";
    if (!empty($where_clauses)) {
        $where_sql = "WHERE " . implode(" AND ", $where_clauses);
    }
    
    // Count total logs for pagination
    $count_sql = "SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id " . $where_sql;
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_logs = $count_stmt->fetchColumn();
    $total_pages = ceil($total_logs / $limit);
    
    // Fetch logs
    $select_sql = "SELECT a.*, u.username 
                   FROM activity_logs a 
                   LEFT JOIN users u ON a.user_id = u.id 
                   " . $where_sql . " 
                   ORDER BY a.created_at DESC 
                   LIMIT :limit OFFSET :offset";
                   
    $stmt = $pdo->prepare($select_sql);
    
    // Bind limit & offset as integers for safety (since emulate_prepares is disabled)
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
    // Fetch list of distinct actions for the filter select
    $actions_stmt = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC");
    $distinct_actions = $actions_stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">خطأ في استعلام السجلات: ' . htmlspecialchars($e->getMessage()) . '</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
?>

<div class="top-navbar bg-white p-4 mb-4 rounded-4 shadow-sm border-0">
    <h3 class="fw-bold m-0 text-success">
        <i class="bi bi-journal-text me-1"></i> سجل العمليات والأنشطة
    </h3>
    <p class="text-muted m-0 fs-7">مراقبة الأنشطة، عمليات الإضافة، التعديل، الحذف وتسجيل الدخول بالنظام</p>
</div>

<!-- Filters Panel -->
<div class="card premium-card p-4 mb-4 border-0 shadow-sm no-print">
    <form method="GET" action="logs.php" class="row g-3 align-items-end">
        <div class="col-12 col-md-4">
            <label for="user_filter" class="form-label fw-bold text-dark"><i class="bi bi-person me-1"></i> تصفية بالمستخدم</label>
            <input type="text" class="form-control" id="user_filter" name="user_filter" placeholder="ابحث باسم المستخدم..." value="<?= sanitize($user_filter) ?>">
        </div>
        
        <div class="col-12 col-md-4">
            <label for="action_filter" class="form-label fw-bold text-dark"><i class="bi bi-activity me-1"></i> نوع العملية</label>
            <select class="form-select" id="action_filter" name="action_filter">
                <option value="">كل العمليات</option>
                <?php foreach ($distinct_actions as $act): ?>
                    <option value="<?= sanitize($act) ?>" <?= ($action_filter === $act) ? 'selected' : '' ?>><?= sanitize($act) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-12 col-md-4 d-grid gap-2">
            <button type="submit" class="btn btn-success rounded-3 fw-bold py-2"><i class="bi bi-funnel-fill"></i> تصفية السجلات</button>
        </div>
    </form>
</div>

<!-- Logs Table Card -->
<div class="card custom-table-card animated-fade-in">
    <div class="table-responsive">
        <table class="table custom-table m-0">
            <thead>
                <tr>
                    <th>المسؤول</th>
                    <th>نوع العملية</th>
                    <th>تفاصيل النشاط</th>
                    <th>عنوان الـ IP</th>
                    <th>التاريخ والتوقيت</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">لا توجد عمليات مسجلة تطابق معايير التصفية.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        $badge_class = 'bg-secondary';
                        if (str_contains($log['action'], 'DELETE')) $badge_class = 'bg-danger';
                        elseif (str_contains($log['action'], 'UPDATE')) $badge_class = 'bg-warning text-dark';
                        elseif (str_contains($log['action'], 'ADD') || str_contains($log['action'], 'CREATE')) $badge_class = 'bg-success';
                        elseif (str_contains($log['action'], 'LOGIN')) $badge_class = 'bg-info text-dark';
                        elseif (str_contains($log['action'], 'LOGOUT')) $badge_class = 'bg-dark';
                    ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-dark"><?= sanitize($log['username'] ?? 'النظام / تلقائي') ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $badge_class ?> rounded-pill px-3 py-1 font-monospace fs-7">
                                    <?= sanitize($log['action']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-dark fs-7 text-wrap d-inline-block" style="max-width: 400px;"><?= sanitize($log['details']) ?></span>
                            </td>
                            <td>
                                <span class="text-muted font-monospace fs-7"><?= sanitize($log['ip_address'] ?? '-') ?></span>
                            </td>
                            <td>
                                <span class="text-muted font-monospace fs-7"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination Footer -->
    <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-0 py-3 no-print d-flex justify-content-center">
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-success mb-0 gap-1">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link rounded-3 border-0 bg-light text-success fw-bold" href="logs.php?page=<?= $page - 1 ?>&action_filter=<?= urlencode($action_filter) ?>&user_filter=<?= urlencode($user_filter) ?>">
                            <i class="bi bi-chevron-right align-middle"></i> السابق
                        </a>
                    </li>
                    
                    <?php 
                    $start_p = max(1, $page - 3);
                    $end_p = min($total_pages, $page + 3);
                    for ($i = $start_p; $i <= $end_p; $i++): 
                    ?>
                        <li class="page-item <?= ($page === $i) ? 'active' : '' ?>">
                            <a class="page-link rounded-3 border-0 fw-bold <?= ($page === $i) ? 'bg-success text-white' : 'bg-light text-dark' ?>" href="logs.php?page=<?= $i ?>&action_filter=<?= urlencode($action_filter) ?>&user_filter=<?= urlencode($user_filter) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link rounded-3 border-0 bg-light text-success fw-bold" href="logs.php?page=<?= $page + 1 ?>&action_filter=<?= urlencode($action_filter) ?>&user_filter=<?= urlencode($user_filter) ?>">
                            التالي <i class="bi bi-chevron-left align-middle"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
