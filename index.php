<?php
// ============================================================
//  نظام إدارة الموارد البشرية – فالنسيا للرخام V3 (Enterprise)
//  جميع الحقوق محفوظة © 2026
// ============================================================

// ======================= بدء الجلسة =======================
session_start();
date_default_timezone_set('Asia/Dubai');

// ======================= إعدادات التليجرام =======================
define('TELEGRAM_BOT_TOKEN', '8459387658:AAFTjYHSoDM3CBYOr2DPb6iMSG_Jk9lh6c0');
define('TELEGRAM_CHAT_ID', '-1003552031590');

// ======================= إعدادات قاعدة البيانات =======================
$dbFile = __DIR__ . '/database/hr.db';
if (!file_exists(__DIR__ . '/database')) mkdir(__DIR__ . '/database', 0777, true);
if (!file_exists(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0777, true);

try {
  $pdo = new PDO("sqlite:$dbFile");
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  die("❌ خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage());
}

// ======================= إنشاء الجداول =======================
$pdo->exec("
CREATE TABLE IF NOT EXISTS employees (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  emp_code TEXT UNIQUE NOT NULL,
  name TEXT NOT NULL,
  id_number TEXT NOT NULL,
  department TEXT NOT NULL,
  position TEXT NOT NULL,
  phone TEXT,
  email TEXT,
  password TEXT NOT NULL,
  role TEXT DEFAULT 'employee',
  contract_end DATE,
  id_expiry DATE,
  leave_balance INTEGER DEFAULT 30,
  profile_pic TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL,
  type TEXT NOT NULL,
  title TEXT NOT NULL,
  details TEXT,
  file_path TEXT,
  status TEXT DEFAULT 'pending',
  admin_notes TEXT,
  approved_by INTEGER,
  approved_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id)
);

CREATE TABLE IF NOT EXISTS logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER,
  action TEXT NOT NULL,
  details TEXT,
  ip_address TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER,
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  is_read INTEGER DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id)
);

CREATE INDEX IF NOT EXISTS idx_requests_employee ON requests(employee_id);
CREATE INDEX IF NOT EXISTS idx_requests_status ON requests(status);
CREATE INDEX IF NOT EXISTS idx_notifications_employee ON notifications(employee_id);
");

// ======================= إدراج المستخدمين التجريبيين =======================
$check = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
if ($check == 0) {
  $hashed = password_hash('123456', PASSWORD_BCRYPT);
  $pdo->exec("INSERT INTO employees (emp_code, name, id_number, department, position, password, role, contract_end, leave_balance) VALUES
  ('EMP001', 'أحمد المدير', '784-1980-1234567', 'الإدارة العامة', 'مدير عام', '$hashed', 'admin', '2027-12-31', 30),
             ('EMP002', 'سارة الموارد', '784-1985-7654321', 'الموارد البشرية', 'أخصائي موارد بشرية', '$hashed', 'hr', '2026-12-31', 30),
             ('EMP003', 'خالد الموظف', '784-1990-9876543', 'المبيعات', 'مندوب مبيعات', '$hashed', 'employee', '2026-10-15', 25)");
}

// ======================= دوال مساعدة =======================
function logAction($employee_id, $action, $details) {
  global $pdo;
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  $stmt = $pdo->prepare("INSERT INTO logs (employee_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
  $stmt->execute([$employee_id, $action, $details, $ip]);
}

function sendTelegram($message) {
  if (TELEGRAM_BOT_TOKEN == '8459387658:AAFTjYHSoDM3CBYOr2DPb6iMSG_Jk9lh6c0') return false;
  $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
  $data = ['chat_id' => TELEGRAM_CHAT_ID, 'text' => $message, 'parse_mode' => 'HTML'];
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res;
}

function isLoggedIn() { return isset($_SESSION['user_id']); }
function isHR() { return in_array($_SESSION['role'] ?? '', ['hr', 'admin']); }
function isAdmin() { return ($_SESSION['role'] ?? '') == 'admin'; }

// ======================= معالجة مسارات API =======================
$path = $_SERVER['PATH_INFO'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'];

// ---- تسجيل الدخول ----
if ($method == 'POST' && $path == '/api/login') {
  $code = $_POST['emp_code'] ?? '';
  $password = $_POST['password'] ?? '';
  $stmt = $pdo->prepare("SELECT * FROM employees WHERE emp_code = ?");
  $stmt->execute([$code]);
  $user = $stmt->fetch();
  if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['name'];
    logAction($user['id'], 'login', 'تسجيل دخول ناجح');
    echo json_encode(['success' => true, 'role' => $user['role'], 'name' => $user['name']]);
    exit;
  }
  echo json_encode(['success' => false, 'message' => '⚠️ رقم الموظف أو كلمة المرور غير صحيحة']);
  exit;
}

// ---- تسجيل الخروج ----
if ($method == 'GET' && $path == '/api/logout') {
  session_destroy();
  echo json_encode(['success' => true]);
  exit;
}

// ---- جلب الملف الشخصي ----
if ($method == 'GET' && $path == '/api/profile' && isLoggedIn()) {
  $stmt = $pdo->prepare("SELECT id, emp_code, name, id_number, department, position, phone, email, contract_end, id_expiry, leave_balance, role FROM employees WHERE id = ?");
  $stmt->execute([$_SESSION['user_id']]);
  $user = $stmt->fetch();
  if ($user) {
    echo json_encode($user);
  } else {
    http_response_code(404);
    echo json_encode(['error' => 'المستخدم غير موجود']);
  }
  exit;
}

// ---- جلب طلباتي ----
if ($method == 'GET' && $path == '/api/my-requests' && isLoggedIn()) {
  $stmt = $pdo->prepare("SELECT * FROM requests WHERE employee_id = ? ORDER BY created_at DESC");
  $stmt->execute([$_SESSION['user_id']]);
  echo json_encode($stmt->fetchAll());
  exit;
}

// ---- جلب جميع الطلبات (لـ HR) ----
if ($method == 'GET' && $path == '/api/all-requests' && isHR()) {
  $stmt = $pdo->query("SELECT r.*, e.name as emp_name, e.emp_code, e.department FROM requests r LEFT JOIN employees e ON r.employee_id = e.id ORDER BY r.created_at DESC");
  echo json_encode($stmt->fetchAll());
  exit;
}

// ---- جلب إحصائيات الطلبات ----
if ($method == 'GET' && $path == '/api/stats' && isLoggedIn()) {
  $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM requests WHERE employee_id = ? GROUP BY status");
  $stmt->execute([$_SESSION['user_id']]);
  $myStats = $stmt->fetchAll();
  $total = array_sum(array_column($myStats, 'count'));
  $pending = $approved = $rejected = 0;
  foreach ($myStats as $s) { if ($s['status'] == 'pending') $pending = $s['count']; if ($s['status'] == 'approved') $approved = $s['count']; if ($s['status'] == 'rejected') $rejected = $s['count']; }
  echo json_encode(['total' => $total, 'pending' => $pending, 'approved' => $approved, 'rejected' => $rejected]);
  exit;
}

// ---- جلب إحصائيات جميع الموظفين (للمدير) ----
if ($method == 'GET' && $path == '/api/admin-stats' && isAdmin()) {
  $totalEmps = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
  $totalReqs = $pdo->query("SELECT COUNT(*) FROM requests")->fetchColumn();
  $pendingReqs = $pdo->query("SELECT COUNT(*) FROM requests WHERE status='pending'")->fetchColumn();
  $deptCount = $pdo->query("SELECT department, COUNT(*) as count FROM employees GROUP BY department")->fetchAll();
  echo json_encode(['total_employees' => $totalEmps, 'total_requests' => $totalReqs, 'pending_requests' => $pendingReqs, 'departments' => $deptCount]);
  exit;
}

// ---- جلب التنبيهات غير المقروءة ----
if ($method == 'GET' && $path == '/api/notifications' && isLoggedIn()) {
  $stmt = $pdo->prepare("SELECT * FROM notifications WHERE employee_id = ? AND is_read = 0 ORDER BY created_at DESC");
  $stmt->execute([$_SESSION['user_id']]);
  echo json_encode($stmt->fetchAll());
  exit;
}

// ---- تحديث حالة التنبيهات (قراءة) ----
if ($method == 'POST' && $path == '/api/mark-notification-read' && isLoggedIn()) {
  $id = $_POST['id'] ?? 0;
  $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND employee_id = ?");
  $stmt->execute([$id, $_SESSION['user_id']]);
  echo json_encode(['success' => true]);
  exit;
}

// ---- إضافة طلب جديد ----
if ($method == 'POST' && $path == '/api/request' && isLoggedIn()) {
  $type = $_POST['type'] ?? '';
  $title = $_POST['title'] ?? '';
  $details = $_POST['details'] ?? '';
  $filePath = null;

  if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $allowed)) {
      $newName = 'req_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
      $target = __DIR__ . '/uploads/' . $newName;
      if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        $filePath = 'uploads/' . $newName;
      }
    }
  }

  $stmt = $pdo->prepare("INSERT INTO requests (employee_id, type, title, details, file_path) VALUES (?, ?, ?, ?, ?)");
  $stmt->execute([$_SESSION['user_id'], $type, $title, $details, $filePath]);
  $reqId = $pdo->lastInsertId();
  logAction($_SESSION['user_id'], 'submit_request', "نوع: $type, العنوان: $title");

  // إشعار تليجرام (إذا كان البوت مفعلاً)
  if (TELEGRAM_BOT_TOKEN != '8459387658:AAFTjYHSoDM3CBYOr2DPb6iMSG_Jk9lh6c0') {
    $msg = "📢 <b>طلب جديد في بوابة فالنسيا</b>\n👤 الموظف: {$_SESSION['name']}\n📌 النوع: $type\n📝 العنوان: $title\n🆔 رقم الطلب: $reqId";
    sendTelegram($msg);
  }

  // إضافة إشعار داخلي للموظف
  $pdo->prepare("INSERT INTO notifications (employee_id, title, message) VALUES (?, ?, ?)")->execute([
    $_SESSION['user_id'],
    'تم تقديم طلبك بنجاح',
    "طلب \"$title\" رقم $reqId قيد المراجعة"
  ]);

  echo json_encode(['success' => true, 'id' => $reqId]);
  exit;
}

// ---- تحديث حالة الطلب (HR) ----
if ($method == 'POST' && $path == '/api/update-request' && isHR()) {
  $id = $_POST['id'] ?? 0;
  $status = $_POST['status'] ?? '';
  $notes = $_POST['notes'] ?? '';
  if (!in_array($status, ['approved', 'rejected', 'archived'])) {
    echo json_encode(['success' => false, 'message' => 'حالة غير صالحة']);
    exit;
  }
  $stmt = $pdo->prepare("UPDATE requests SET status = ?, admin_notes = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?");
  $stmt->execute([$status, $notes, $_SESSION['user_id'], $id]);
  logAction($_SESSION['user_id'], 'update_request', "الطلب #$id -> $status");

  // إضافة تنبيه للموظف
  $req = $pdo->prepare("SELECT employee_id FROM requests WHERE id = ?");
  $req->execute([$id]);
  $req = $req->fetch();
  if ($req) {
    $pdo->prepare("INSERT INTO notifications (employee_id, title, message) VALUES (?, ?, ?)")->execute([
      $req['employee_id'],
      "تحديث حالة الطلب #$id",
      "تم تغيير حالة طلبك إلى: $status" . ($notes ? " | ملاحظات: $notes" : "")
    ]);
  }

  echo json_encode(['success' => true]);
  exit;
}

// ---- حذف طلب (للمدير فقط) ----
if ($method == 'POST' && $path == '/api/delete-request' && isAdmin()) {
  $id = $_POST['id'] ?? 0;
  $stmt = $pdo->prepare("DELETE FROM requests WHERE id = ?");
  $stmt->execute([$id]);
  logAction($_SESSION['user_id'], 'delete_request', "حذف الطلب #$id");
  echo json_encode(['success' => true]);
  exit;
}

// ---- جلب جميع الموظفين (للمدير) ----
if ($method == 'GET' && $path == '/api/all-employees' && isAdmin()) {
  $stmt = $pdo->query("SELECT id, emp_code, name, department, position, role, contract_end FROM employees ORDER BY name");
  echo json_encode($stmt->fetchAll());
  exit;
}

// ---- إذا لم يجد المسار، عرض الصفحة الرئيسية ----
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>فالنسيا للرخام – النظام المتكامل للموارد البشرية</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Cairo',sans-serif; }
body { background:#f0f4f9; min-height:100vh; display:flex; justify-content:center; align-items:center; padding:12px; }
.app { width:100%; max-width:1500px; background:#fff; border-radius:32px; box-shadow:0 30px 60px rgba(0,0,0,0.10); overflow:hidden; min-height:96vh; display:flex; flex-direction:column; }
.header { background:linear-gradient(135deg, #1e2a3a, #0f1a24); padding:16px 28px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; color:#fff; border-bottom:3px solid #c8902d; }
.header h1 { font-size:22px; font-weight:800; } .header h1 span { color:#c8902d; }
.user-badge { display:flex; align-items:center; gap:12px; background:rgba(255,255,255,0.08); padding:5px 16px 5px 8px; border-radius:50px; }
.user-badge .avatar { width:36px; height:36px; border-radius:50%; background:#c8902d; display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; }
.btn-logout { background:#c0392b; border:none; color:#fff; padding:5px 16px; border-radius:40px; cursor:pointer; font-weight:600; font-size:14px; }
.btn-logout:hover { opacity:0.8; }
.notif-badge { background:#e74c3c; color:#fff; border-radius:50%; padding:2px 8px; font-size:12px; margin-right:5px; }
.nav { display:flex; gap:4px; background:#f8fafc; padding:10px 16px; border-bottom:1px solid #e9ecf0; flex-wrap:wrap; }
.nav-btn { padding:10px 20px; border-radius:40px; border:none; background:transparent; font-weight:600; cursor:pointer; transition:0.2s; font-size:14px; }
.nav-btn i { margin-left:8px; }
.nav-btn.active { background:#c8902d; color:#fff; box-shadow:0 4px 12px rgba(200,144,45,0.3); }
.nav-btn.hidden { display:none; }
.content { padding:20px; flex:1; overflow-y:auto; background:#fafcff; max-height:72vh; }
.stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:22px; }
.stat-card { background:#fff; padding:18px; border-radius:18px; text-align:center; box-shadow:0 4px 12px rgba(0,0,0,0.04); border:1px solid #edf2f7; }
.stat-card .num { font-size:28px; font-weight:800; color:#1e2a3a; }
.stat-card .label { font-size:13px; color:#7a8a9e; }
.stat-card .icon { font-size:24px; color:#c8902d; margin-bottom:4px; }
.card { background:#fff; border-radius:20px; padding:20px; box-shadow:0 4px 12px rgba(0,0,0,0.04); border:1px solid #edf2f7; margin-bottom:20px; }
.card-title { font-size:18px; font-weight:700; color:#1e2a3a; margin-bottom:16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.service-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:12px; }
.service-item { background:#f8fafc; border:1px solid #e9ecf0; border-radius:16px; padding:16px 8px; text-align:center; cursor:pointer; transition:0.2s; }
.service-item:hover { background:#c8902d; color:#fff; border-color:#c8902d; transform:translateY(-3px); }
.service-item i { font-size:26px; display:block; margin-bottom:8px; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { background:#1e2a3a; color:#fff; padding:10px 8px; white-space:nowrap; text-align:center; }
td { padding:10px 8px; border-bottom:1px solid #edf2f7; vertical-align:middle; text-align:center; }
.badge { padding:3px 12px; border-radius:50px; font-size:11px; font-weight:600; }
.badge-pending { background:#fff3cd; color:#856404; }
.badge-approved { background:#d4edda; color:#155724; }
.badge-rejected { background:#f8d7da; color:#721c24; }
.badge-archived { background:#e2e3e5; color:#383d41; }
.btn-sm { padding:3px 10px; border-radius:30px; border:none; font-weight:600; cursor:pointer; font-size:11px; margin:2px; }
.btn-approve { background:#28a745; color:#fff; }
.btn-reject { background:#dc3545; color:#fff; }
.btn-archive { background:#6c757d; color:#fff; }
.btn-pdf { background:#17a2b8; color:#fff; }
.btn-delete { background:#343a40; color:#fff; }
.file-link { color:#c8902d; text-decoration:underline; cursor:pointer; }
.modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:999; padding:15px; }
.modal-box { background:#fff; border-radius:28px; padding:25px; width:100%; max-width:500px; max-height:85vh; overflow-y:auto; }
.modal-box input, .modal-box select, .modal-box textarea { width:100%; padding:10px 14px; border:1px solid #ddd; border-radius:14px; margin-bottom:12px; font-size:14px; }
.modal-box label { font-weight:600; display:block; margin-bottom:4px; font-size:14px; }
.btn-primary { background:#c8902d; color:#fff; border:none; padding:10px 28px; border-radius:40px; font-weight:700; cursor:pointer; font-size:15px; }
.btn-primary:hover { opacity:0.9; }
.flex-between { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.login-box { max-width:380px; margin:40px auto; text-align:center; padding:35px; background:#fff; border-radius:40px; box-shadow:0 20px 40px rgba(0,0,0,0.08); }
.login-box input { width:100%; padding:12px; border-radius:14px; border:1px solid #ddd; margin-bottom:12px; font-size:15px; }
.login-box .logo-icon { font-size:60px; color:#c8902d; margin-bottom:15px; }
.text-muted { color:#8a9baa; font-size:13px; }
.notif-dropdown { position:relative; display:inline-block; }
.notif-dropdown-content { display:none; position:absolute; left:0; top:40px; background:#fff; min-width:280px; box-shadow:0 8px 20px rgba(0,0,0,0.15); border-radius:16px; padding:10px 0; z-index:100; max-height:300px; overflow-y:auto; }
.notif-dropdown-content.show { display:block; }
.notif-item { padding:10px 16px; border-bottom:1px solid #f0f2f5; cursor:pointer; }
.notif-item:hover { background:#f8fafc; }
.notif-item .title { font-weight:600; font-size:14px; }
.notif-item .msg { font-size:13px; color:#6c757d; }
.notif-item .time { font-size:11px; color:#adb5bd; }
.field-group { margin-bottom:12px; }
.field-group label { font-weight:600; display:block; margin-bottom:4px; font-size:14px; }
@media (max-width:700px) {
  .header { flex-direction:column; gap:10px; align-items:stretch; }
  .header h1 { text-align:center; font-size:18px; }
  .user-badge { justify-content:center; flex-wrap:wrap; }
  .nav-btn { flex:1; text-align:center; padding:8px 10px; font-size:12px; }
  .content { padding:12px; max-height:65vh; }
  .stat-grid { grid-template-columns:repeat(2,1fr); }
  .service-grid { grid-template-columns:repeat(2,1fr); }
  .modal-box { padding:18px; }
  table { font-size:12px; }
  th, td { padding:6px 4px; }
}
</style>
</head>
<body>
<div class="app" id="app">

<!-- Header -->
<div class="header">
<h1><i class="fas fa-building"></i> فالنسيا للرخام <span>|</span> بوابة الموظفين</h1>
<div class="user-badge" id="userInfo">
<div class="avatar" id="userAvatar">ز</div>
<span id="userNameDisplay">زائر</span>
<div class="notif-dropdown">
<span id="notifBell" style="cursor:pointer;font-size:20px;position:relative;">
<i class="fas fa-bell"></i>
<span id="notifCount" class="notif-badge" style="display:none;">0</span>
</span>
<div class="notif-dropdown-content" id="notifDropdown">
<div style="padding:10px 16px;font-weight:700;border-bottom:1px solid #eee;">التنبيهات</div>
<div id="notifList"></div>
</div>
</div>
<button class="btn-logout" onclick="logout()"><i class="fas fa-sign-out-alt"></i> خروج</button>
</div>
</div>

<!-- Navigation -->
<div class="nav" id="navTabs">
<button class="nav-btn active" data-page="dashboard"><i class="fas fa-home"></i> الرئيسية</button>
<button class="nav-btn" data-page="services"><i class="fas fa-concierge-bell"></i> الخدمات</button>
<button class="nav-btn" data-page="my-requests"><i class="fas fa-list"></i> طلباتي</button>
<button class="nav-btn hidden" data-page="hr-panel" id="hrTab"><i class="fas fa-users-cog"></i> لوحة HR</button>
<button class="nav-btn hidden" data-page="admin-panel" id="adminTab"><i class="fas fa-chart-line"></i> لوحة المدير</button>
</div>

<!-- Content -->
<div class="content" id="mainContent">
<div id="pageContent"></div>
</div>
</div>

<!-- Modal للطلبات -->
<div class="modal" id="serviceModal">
<div class="modal-box">
<div class="flex-between"><h2 id="modalTitle">طلب جديد</h2><span onclick="closeModal()" style="font-size:32px;cursor:pointer;">&times;</span></div>
<form id="serviceForm" enctype="multipart/form-data">
<input type="hidden" id="reqType" name="type">
<div id="dynamicFields"></div>
<label>المرفق (اختياري)</label>
<input type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx" id="fileInput">
<button type="submit" class="btn-primary" style="width:100%;"><i class="fas fa-paper-plane"></i> إرسال الطلب</button>
</form>
</div>
</div>

<!-- Modal لعرض المرفقات -->
<div class="modal" id="fileModal">
<div class="modal-box" style="max-width:600px;">
<div class="flex-between"><h2>📎 المرفق</h2><span onclick="closeFileModal()" style="font-size:32px;cursor:pointer;">&times;</span></div>
<div id="filePreview" style="text-align:center;margin-top:15px;"></div>
</div>
</div>

<script>
// ============================================================
//  نسخة الواجهة متصلة بـ API الخادم (PHP)
// ============================================================

const BASE_URL = window.location.origin + window.location.pathname.replace(/\/$/, '');

// ======================= المتغيرات العامة =======================
let currentUser = null;

// ======================= دوال API =======================
async function apiFetch(endpoint, options = {}) {
  const url = BASE_URL + '/api' + endpoint;
  const res = await fetch(url, options);
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}

// ======================= تسجيل الدخول =======================
async function login() {
  const code = document.getElementById('loginCode').value;
  const pass = document.getElementById('loginPass').value;
  if (!code || !pass) { alert('يرجى إدخال رقم الموظف وكلمة المرور'); return; }

  try {
    const form = new FormData();
    form.append('emp_code', code);
    form.append('password', pass);
    const data = await apiFetch('/login', { method: 'POST', body: form });
    if (data.success) {
      alert('✅ تم تسجيل الدخول بنجاح!');
      location.reload();
    } else {
      alert('❌ ' + data.message);
    }
  } catch(e) {
    alert('❌ حدث خطأ في الاتصال بالخادم');
  }
}

async function logout() {
  if (!confirm('هل أنت متأكد من الخروج؟')) return;
  await apiFetch('/logout');
  location.reload();
}

// ======================= تحميل البيانات الأولية =======================
async function loadApp() {
  try {
    const profile = await apiFetch('/profile');
    if (!profile || !profile.id) {
      // لم يتم تسجيل الدخول
      showLoginScreen();
      return;
    }
    currentUser = profile;
    document.getElementById('userNameDisplay').innerText = profile.name;
    document.getElementById('userAvatar').innerText = profile.name.charAt(0);

    if (profile.role == 'hr' || profile.role == 'admin') {
      document.getElementById('hrTab').classList.remove('hidden');
    }
    if (profile.role == 'admin') {
      document.getElementById('adminTab').classList.remove('hidden');
    }

    await loadNotifications();
    navigate('dashboard');
  } catch(e) {
    console.error(e);
    showLoginScreen();
  }
}

function showLoginScreen() {
  document.getElementById('mainContent').innerHTML = `
  <div style="text-align:center;padding:40px 20px;">
  <div class="login-box" id="loginScreen">
  <div class="logo-icon"><i class="fas fa-user-lock"></i></div>
  <h2 style="margin:15px 0;">مرحباً بك في بوابة الموظفين</h2>
  <p style="color:#7a8a9e;margin-bottom:15px;">الرجاء إدخال بيانات الدخول</p>
  <input type="text" id="loginCode" placeholder="رقم الموظف (مثال: EMP001)">
  <input type="password" id="loginPass" placeholder="كلمة المرور (123456)">
  <button class="btn-primary" onclick="login()" style="width:100%;"><i class="fas fa-sign-in-alt"></i> تسجيل الدخول</button>
  <div style="margin-top:10px;font-size:12px;color:#8a9baa;">
  <p>المدير: EMP001 | الموارد البشرية: EMP002 | موظف: EMP003</p>
  <p>كلمة المرور للجميع: 123456</p>
  </div>
  </div>
  </div>
  `;
}

// ======================= التنبيهات =======================
async function loadNotifications() {
  try {
    const data = await apiFetch('/notifications');
    const count = data.length;
    const badge = document.getElementById('notifCount');
    if (count > 0) { badge.style.display = 'inline'; badge.innerText = count; }
    else { badge.style.display = 'none'; }

    const list = document.getElementById('notifList');
    list.innerHTML = data.map(n => `
    <div class="notif-item" onclick="markRead(${n.id})">
    <div class="title">${n.title}</div>
    <div class="msg">${n.message}</div>
    <div class="time">${n.created_at}</div>
    </div>
    `).join('') || '<div style="padding:10px 16px;color:#6c757d;">لا توجد تنبيهات</div>';
  } catch(e) {
    console.error('خطأ في تحميل التنبيهات', e);
  }
}

async function markRead(id) {
  try {
    const form = new FormData();
    form.append('id', id);
    await apiFetch('/mark-notification-read', { method: 'POST', body: form });
    loadNotifications();
  } catch(e) {
    console.error(e);
  }
}

// ======================= التنقل =======================
function navigate(page) {
  document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
  document.querySelector(`.nav-btn[data-page="${page}"]`)?.classList.add('active');
  if (page === 'dashboard') renderDashboard();
  else if (page === 'services') renderServices();
  else if (page === 'my-requests') renderMyRequests();
  else if (page === 'hr-panel') renderHRPanel();
  else if (page === 'admin-panel') renderAdminPanel();
}

// ======================= الصفحة الرئيسية =======================
async function renderDashboard() {
  if (!currentUser) return;
  try {
    const stats = await apiFetch('/stats');
    const myReqs = await apiFetch('/my-requests');
    const user = currentUser;

    let html = `
    <div class="stat-grid">
    <div class="stat-card"><div class="icon"><i class="fas fa-file-alt"></i></div><div class="num">${stats.total || 0}</div><div class="label">إجمالي طلباتي</div></div>
    <div class="stat-card" style="border-color:#ffc107;"><div class="icon"><i class="fas fa-clock"></i></div><div class="num">${stats.pending || 0}</div><div class="label">قيد المراجعة</div></div>
    <div class="stat-card" style="border-color:#28a745;"><div class="icon"><i class="fas fa-check-circle"></i></div><div class="num">${stats.approved || 0}</div><div class="label">تمت الموافقة</div></div>
    <div class="stat-card" style="border-color:#dc3545;"><div class="icon"><i class="fas fa-times-circle"></i></div><div class="num">${stats.rejected || 0}</div><div class="label">مرفوض</div></div>
    </div>
    <div class="card">
    <div class="card-title"><i class="fas fa-user-circle"></i> بياناتي الشخصية</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
    <div><label class="text-muted">الاسم</label><div style="font-weight:700;">${user.name}</div></div>
    <div><label class="text-muted">رقم الموظف</label><div style="font-weight:700;">${user.emp_code}</div></div>
    <div><label class="text-muted">رقم الهوية</label><div style="font-weight:700;">${user.id_number || '-'}</div></div>
    <div><label class="text-muted">القسم</label><div style="font-weight:700;">${user.department}</div></div>
    <div><label class="text-muted">الوظيفة</label><div style="font-weight:700;">${user.position}</div></div>
    <div><label class="text-muted">رصيد الإجازات</label><div style="font-weight:700;">${user.leave_balance} يوم</div></div>
    <div><label class="text-muted">انتهاء العقد</label><div style="font-weight:700;">${user.contract_end || 'غير محدد'}</div></div>
    </div>
    </div>
    <div class="card">
    <div class="card-title"><i class="fas fa-history"></i> آخر طلباتي</div>
    <div class="table-wrap">
    <table><thead><tr><th>النوع</th><th>العنوان</th><th>التاريخ</th><th>الحالة</th></tr></thead><tbody>
    ${myReqs.slice(0,5).map(r => `<tr><td>${r.type}</td><td>${r.title}</td><td>${r.created_at}</td><td><span class="badge badge-${r.status}">${r.status}</span></td></tr>`).join('')}
    ${myReqs.length===0 ? '<tr><td colspan="4">لا توجد طلبات</td></tr>' : ''}
    </tbody></table>
    </div>
    </div>
    `;
    document.getElementById('pageContent').innerHTML = html;
  } catch(e) {
    console.error(e);
    document.getElementById('pageContent').innerHTML = '<div class="card">حدث خطأ في تحميل البيانات</div>';
  }
}

// ======================= الخدمات =======================
function renderServices() {
  const services = [
    { id:'leave', icon:'fa-calendar-check', label:'طلب إجازة' },
    { id:'renewal', icon:'fa-file-contract', label:'تجديد العقد والإقامة' },
    { id:'loan', icon:'fa-hand-holding-usd', label:'طلب سلفة مالية' },
    { id:'salary_cert', icon:'fa-file-invoice', label:'شهادة راتب' },
    { id:'exp_cert', icon:'fa-certificate', label:'شهادة خبرة' },
    { id:'phone_change', icon:'fa-phone-alt', label:'تغيير رقم الهاتف' },
    { id:'data_update', icon:'fa-user-edit', label:'تحديث البيانات الشخصية' },
    { id:'exit_permit', icon:'fa-door-open', label:'إذن خروج' },
    { id:'overtime', icon:'fa-clock', label:'تسجيل ساعات إضافية' },
    { id:'report_issue', icon:'fa-exclamation-triangle', label:'الإبلاغ عن مشكلة' },
    { id:'doc_request', icon:'fa-file-pdf', label:'طلب مستند' },
    { id:'resignation', icon:'fa-user-minus', label:'تقديم استقالة' },
    { id:'training', icon:'fa-chalkboard-teacher', label:'طلب تدريب' },
    { id:'travel', icon:'fa-plane', label:'طلب سفر / مهمة' },
    { id:'purchase', icon:'fa-shopping-cart', label:'طلب شراء/مشتريات' }
  ];
  let html = `<div class="card"><div class="card-title"><i class="fas fa-concierge-bell"></i> اختر الخدمة المطلوبة</div><div class="service-grid">`;
  services.forEach(s => {
    html += `<div class="service-item" onclick="openService('${s.id}','${s.label}')"><i class="fas ${s.icon}"></i> ${s.label}</div>`;
  });
  html += `</div></div>`;
  document.getElementById('pageContent').innerHTML = html;
}

// ======================= نماذج الخدمات (نفس الكود السابق) =======================
function getDynamicFields(type) {
  let fields = '';
  switch(type) {
    case 'leave': fields = `
      <div class="field-group"><label>نوع الإجازة</label>
      <select name="leave_type">
      <option value="سنوية">سنوية</option>
      <option value="مرضية">مرضية</option>
      <option value="طارئة">طارئة</option>
      <option value="بدون راتب">بدون راتب</option>
      </select></div>
      <div class="field-group"><label>تاريخ البداية</label><input type="date" name="start_date"></div>
      <div class="field-group"><label>تاريخ النهاية</label><input type="date" name="end_date"></div>
      <div class="field-group"><label>عدد الأيام</label><input type="number" name="days" min="0.5" step="0.5"></div>
      <div class="field-group"><label>السبب</label><textarea name="reason" rows="2"></textarea></div>`; break;
    case 'loan': fields = `
      <div class="field-group"><label>المبلغ المطلوب</label><input type="number" name="amount" min="1"></div>
      <div class="field-group"><label>سبب السلفة</label><textarea name="reason" rows="2"></textarea></div>`; break;
    case 'renewal': fields = `
      <div class="field-group"><label>نوع التجديد</label>
      <select name="renewal_type">
      <option value="عقد عمل">عقد عمل</option>
      <option value="إقامة">إقامة</option>
      <option value="كلاهما">كلاهما</option>
      </select></div>
      <div class="field-group"><label>ملاحظات إضافية</label><textarea name="notes" rows="2"></textarea></div>`; break;
    case 'salary_cert': case 'exp_cert': fields = `
      <div class="field-group"><label>الجهة الموجه إليها</label><input type="text" name="recipient"></div>
      <div class="field-group"><label>الغرض</label><textarea name="purpose" rows="2"></textarea></div>`; break;
    case 'exit_permit': fields = `
      <div class="field-group"><label>تاريخ الخروج</label><input type="date" name="exit_date"></div>
      <div class="field-group"><label>وقت الخروج</label><input type="time" name="exit_time"></div>
      <div class="field-group"><label>مدة الخروج (ساعات)</label><input type="number" name="duration" min="0.5" step="0.5"></div>
      <div class="field-group"><label>السبب</label><textarea name="reason" rows="2"></textarea></div>`; break;
    case 'overtime': fields = `
      <div class="field-group"><label>التاريخ</label><input type="date" name="ot_date"></div>
      <div class="field-group"><label>عدد الساعات</label><input type="number" name="hours" min="0.5" step="0.5"></div>
      <div class="field-group"><label>السبب</label><textarea name="reason" rows="2"></textarea></div>`; break;
    case 'resignation': fields = `
      <div class="field-group"><label>تاريخ تقديم الاستقالة</label><input type="date" name="resign_date"></div>
      <div class="field-group"><label>تاريخ آخر يوم عمل</label><input type="date" name="last_day"></div>
      <div class="field-group"><label>سبب الاستقالة</label><textarea name="reason" rows="3"></textarea></div>`; break;
    case 'training': fields = `
      <div class="field-group"><label>عنوان الدورة / التدريب</label><input type="text" name="training_title"></div>
      <div class="field-group"><label>تاريخ البداية</label><input type="date" name="start_date"></div>
      <div class="field-group"><label>تاريخ النهاية</label><input type="date" name="end_date"></div>
      <div class="field-group"><label>الجهة المنظمة</label><input type="text" name="organizer"></div>
      <div class="field-group"><label>التكلفة المتوقعة</label><input type="number" name="cost" min="0" step="0.01"></div>`; break;
    case 'travel': fields = `
      <div class="field-group"><label>جهة السفر</label><input type="text" name="destination"></div>
      <div class="field-group"><label>تاريخ المغادرة</label><input type="date" name="departure"></div>
      <div class="field-group"><label>تاريخ العودة</label><input type="date" name="return"></div>
      <div class="field-group"><label>الغرض من السفر</label><textarea name="purpose" rows="2"></textarea></div>`; break;
    case 'purchase': fields = `
      <div class="field-group"><label>المادة / المنتج المطلوب</label><input type="text" name="item"></div>
      <div class="field-group"><label>الكمية</label><input type="number" name="quantity" min="1"></div>
      <div class="field-group"><label>السعر التقديري للوحدة</label><input type="number" name="unit_price" min="0" step="0.01"></div>
      <div class="field-group"><label>السبب / الغرض</label><textarea name="reason" rows="2"></textarea></div>`; break;
    case 'report_issue': fields = `
      <div class="field-group"><label>نوع المشكلة</label>
      <select name="issue_type">
      <option value="تقنية">تقنية</option>
      <option value="إدارية">إدارية</option>
      <option value="أمن وسلامة">أمن وسلامة</option>
      <option value="أخرى">أخرى</option>
      </select></div>
      <div class="field-group"><label>وصف المشكلة</label><textarea name="description" rows="3"></textarea></div>`; break;
    case 'doc_request': fields = `
      <div class="field-group"><label>نوع المستند</label>
      <select name="doc_type">
      <option value="تعميم">تعميم</option>
      <option value="عقد">عقد</option>
      <option value="خطاب">خطاب</option>
      <option value="كشف حساب">كشف حساب</option>
      <option value="أخرى">أخرى</option>
      </select></div>
      <div class="field-group"><label>الغرض من الاستلام</label><textarea name="purpose" rows="2"></textarea></div>`; break;
    case 'phone_change': case 'data_update': fields = `
      <div class="field-group"><label>البيانات الجديدة</label><textarea name="new_data" rows="3"></textarea></div>
      <div class="field-group"><label>السبب / ملاحظات</label><textarea name="reason" rows="2"></textarea></div>`; break;
    default: fields = `
      <div class="field-group"><label>تفاصيل الطلب</label><textarea name="details" rows="3" required></textarea></div>`;
  }
  return fields;
}

function openService(type, title) {
  document.getElementById('reqType').value = type;
  document.getElementById('modalTitle').innerText = title;
  document.getElementById('dynamicFields').innerHTML = getDynamicFields(type);
  document.getElementById('serviceForm').reset();
  document.getElementById('serviceModal').style.display = 'flex';
}

// ======================= إرسال الطلب =======================
document.getElementById('serviceForm').onsubmit = async function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  const btn = this.querySelector('button[type="submit"]');
  btn.disabled = true; btn.innerText = 'جاري الإرسال...';

  try {
    const data = await apiFetch('/request', { method: 'POST', body: formData });
    if (data.success) {
      alert('✅ تم إرسال الطلب بنجاح!');
      closeModal();
      navigate('my-requests');
      loadNotifications();
    } else {
      alert('❌ حدث خطأ: ' + (data.message || 'حاول مرة أخرى'));
    }
  } catch(e) {
    alert('❌ حدث خطأ في الاتصال بالخادم');
  }
  btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال الطلب';
};

function closeModal() { document.getElementById('serviceModal').style.display = 'none'; }

// ======================= طلباتي =======================
async function renderMyRequests() {
  try {
    const data = await apiFetch('/my-requests');
    let html = `<div class="card"><div class="card-title"><i class="fas fa-list-ul"></i> جميع طلباتي (${data.length})</div><div class="table-wrap"><table><thead><tr><th>رقم</th><th>النوع</th><th>العنوان</th><th>التاريخ</th><th>الحالة</th><th>ملاحظات</th></tr></thead><tbody>`;
    data.forEach(r => {
      html += `<tr><td>${r.id}</td><td>${r.type}</td><td>${r.title}</td><td>${r.created_at}</td><td><span class="badge badge-${r.status}">${r.status}</span></td><td>${r.admin_notes || '-'}</td></tr>`;
    });
    if(data.length===0) html += '<tr><td colspan="6">لا توجد طلبات</td></tr>';
    html += '</tbody></table></div></div>';
    document.getElementById('pageContent').innerHTML = html;
  } catch(e) {
    console.error(e);
    document.getElementById('pageContent').innerHTML = '<div class="card">حدث خطأ في تحميل الطلبات</div>';
  }
}

// ======================= لوحة HR =======================
async function renderHRPanel() {
  try {
    const data = await apiFetch('/all-requests');
    let html = `
    <div class="card">
    <div class="card-title"><i class="fas fa-tasks"></i> إدارة الطلبات (${data.length})</div>
    <div class="table-wrap">
    <table><thead><tr><th>رقم</th><th>الموظف</th><th>القسم</th><th>النوع</th><th>العنوان</th><th>المرفق</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
    `;
    data.forEach(r => {
      const fileHtml = r.file_path ? `<span class="file-link" onclick="viewFile('${r.file_path}')"><i class="fas fa-paperclip"></i> عرض</span>` : '-';
    let actions = '';
    if (r.status == 'pending') {
      actions = `<button class="btn-sm btn-approve" onclick="updateReq(${r.id},'approved','تمت الموافقة')">موافقة</button>
      <button class="btn-sm btn-reject" onclick="updateReq(${r.id},'rejected','تم الرفض')">رفض</button>`;
    } else if (r.status == 'approved' || r.status == 'rejected') {
      actions = `<button class="btn-sm btn-archive" onclick="updateReq(${r.id},'archived','تمت الأرشفة')">أرشفة</button>`;
    }
    actions += ` <button class="btn-sm btn-pdf" onclick="printReq(${r.id})">PDF</button>`;
    html += `<tr>
    <td>${r.id}</td><td>${r.emp_name} (${r.emp_code})</td><td>${r.department}</td>
    <td>${r.type}</td><td>${r.title}</td><td>${fileHtml}</td>
    <td><span class="badge badge-${r.status}">${r.status}</span></td>
    <td style="white-space:nowrap;">${actions}</td>
    </tr>`;
    });
    if(data.length===0) html += '<tr><td colspan="8">لا توجد طلبات</td></tr>';
    html += '</tbody></table></div></div>';
    document.getElementById('pageContent').innerHTML = html;
  } catch(e) {
    console.error(e);
    document.getElementById('pageContent').innerHTML = '<div class="card">حدث خطأ في تحميل الطلبات</div>';
  }
}

async function updateReq(id, status, notes) {
  if (!confirm(`تغيير الحالة إلى "${status}"؟`)) return;
  try {
    const form = new FormData();
    form.append('id', id);
    form.append('status', status);
    form.append('notes', notes);
    const data = await apiFetch('/update-request', { method: 'POST', body: form });
    if (data.success) {
      alert('✅ تم التحديث!');
      renderHRPanel();
      loadNotifications();
    } else {
      alert('❌ فشل التحديث');
    }
  } catch(e) {
    alert('❌ حدث خطأ');
  }
}

function viewFile(filePath) {
  const url = BASE_URL + '/' + filePath;
  document.getElementById('filePreview').innerHTML = `<iframe src="${url}" style="width:100%;height:400px;border:none;"></iframe>`;
  document.getElementById('fileModal').style.display = 'flex';
}
function closeFileModal() { document.getElementById('fileModal').style.display = 'none'; }

function printReq(id) {
  // سيتم تطويرها لاحقاً
  alert('سيتم طباعة الطلب رقم ' + id);
}

// ======================= لوحة المدير =======================
async function renderAdminPanel() {
  try {
    const stats = await apiFetch('/admin-stats');
    const emps = await apiFetch('/all-employees');
    let html = `
    <div class="stat-grid">
    <div class="stat-card"><div class="icon"><i class="fas fa-users"></i></div><div class="num">${stats.total_employees}</div><div class="label">إجمالي الموظفين</div></div>
    <div class="stat-card"><div class="icon"><i class="fas fa-file-alt"></i></div><div class="num">${stats.total_requests}</div><div class="label">إجمالي الطلبات</div></div>
    <div class="stat-card" style="border-color:#ffc107;"><div class="icon"><i class="fas fa-clock"></i></div><div class="num">${stats.pending_requests}</div><div class="label">طلبات قيد المراجعة</div></div>
    </div>
    <div class="card">
    <div class="card-title"><i class="fas fa-building"></i> توزيع الموظفين حسب الأقسام</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;">
    ${stats.departments.map(d => `<div class="stat-card" style="background:#f8fafc;"><div class="num">${d.count}</div><div class="label">${d.department}</div></div>`).join('')}
    </div>
    </div>
    <div class="card">
    <div class="card-title"><i class="fas fa-user-tie"></i> جميع الموظفين</div>
    <div class="table-wrap"><table><thead><tr><th>الرقم</th><th>الاسم</th><th>القسم</th><th>الوظيفة</th><th>الدور</th><th>انتهاء العقد</th></tr></thead><tbody>
    ${emps.map(e => `<tr><td>${e.emp_code}</td><td>${e.name}</td><td>${e.department}</td><td>${e.position}</td><td>${e.role}</td><td>${e.contract_end || '-'}</td></tr>`).join('')}
    </tbody></table></div>
    </div>
    `;
    document.getElementById('pageContent').innerHTML = html;
  } catch(e) {
    console.error(e);
    document.getElementById('pageContent').innerHTML = '<div class="card">حدث خطأ في تحميل البيانات</div>';
  }
}

// ======================= التنبيهات =======================
document.getElementById('notifBell').addEventListener('click', function(e) {
  e.stopPropagation();
  document.getElementById('notifDropdown').classList.toggle('show');
});
document.addEventListener('click', function(e) {
  if (!e.target.closest('.notif-dropdown')) {
    document.getElementById('notifDropdown').classList.remove('show');
  }
});

// ======================= ربط الأحداث =======================
document.querySelectorAll('.nav-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    if (!currentUser) return;
    navigate(btn.dataset.page);
    document.getElementById('notifDropdown').classList.remove('show');
  });
});

// ======================= بدء التطبيق =======================
window.onload = loadApp;
</script>
</body>
</html>
