<?php
// export.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

// Check auth
check_auth();

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
        $_SESSION['flash_error'] = "الشارع المطلوبة غير موجود.";
        header("Location: index.php");
        exit;
    }
    
    $neighborhood_id = $street_info['neighborhood_id'];
    
    // Security check: Check neighborhood access
    if (!has_neighborhood_access($neighborhood_id)) {
        $_SESSION['flash_error'] = "غير مصرح لك بتصدير بيانات هذا الحي.";
        header("Location: index.php");
        exit;
    }
    
    // Fetch citizens (applying search if present in URL)
    $search = trim($_GET['search'] ?? '');
    
    $query_str = "SELECT * FROM citizens WHERE street_id = :street_id";
    $query_params = [':street_id' => $street_id];
    
    if (!empty($search)) {
        $query_str .= " AND (first_name LIKE :search OR last_name LIKE :search OR national_id LIKE :search OR phone LIKE :search)";
        $query_params[':search'] = '%' . $search . '%';
    }
    
    $query_str .= " ORDER BY first_name ASC, last_name ASC";
    
    $citizens_stmt = $pdo->prepare($query_str);
    $citizens_stmt->execute($query_params);
    $citizens = $citizens_stmt->fetchAll();
    
    // Log the export event
    log_activity($pdo, 'EXPORT_EXCEL', "قام بتصدير قائمة الأشخاص لزنقة {$street_info['street_number']} بـ {$street_info['neighborhood_name']} إلى Excel (CSV)");
    
    // Generate CSV File
    $filename = "citizens_neighborhood_" . preg_replace('/\s+/', '_', $street_info['neighborhood_name']) . "_street_" . $street_info['street_number'] . "_" . date('Y-m-d') . ".csv";
    
    // Set headers for download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Write UTF-8 BOM so Excel opens Arabic file properly
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write headers
    fputcsv($output, [
        'الاسم الشخصي',
        'الاسم العائلي',
        'رقم البطاقة الوطنية',
        'رقم الهاتف',
        'العنوان',
        'ملاحظات',
        'الحيّ',
        'الشارع (زنقة)'
    ]);
    
    // Write data rows
    foreach ($citizens as $citizen) {
        fputcsv($output, [
            $citizen['first_name'],
            $citizen['last_name'],
            $citizen['national_id'],
            $citizen['phone'] ?? '',
            $citizen['address'] ?? '',
            $citizen['notes'] ?? '',
            $street_info['neighborhood_name'],
            "زنقة " . $street_info['street_number']
        ]);
    }
    
    fclose($output);
    exit;
    
} catch (PDOException $e) {
    $_SESSION['flash_error'] = "خطأ في تصدير البيانات: " . $e->getMessage();
    header("Location: people.php?street_id=" . $street_id);
    exit;
}
