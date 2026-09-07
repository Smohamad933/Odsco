<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$message = '';
$error = '';
$is_viewer = ($_SESSION['admin_role'] ?? '') === 'viewer';
$editing_client = null;
$show_form = isset($_GET['add']) || isset($_GET['edit']);

if (isset($_GET['edit'])) {
    $clients = read_json('clients.json');
    foreach ($clients as $client) {
        if ($client['id'] === $_GET['edit']) {
            $editing_client = $client;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        
        // ============ افزودن کارفرما ============
        if ($_POST['action'] === 'add_client') {
            $clients = read_json('clients.json');
            if (!is_array($clients)) $clients = [];
            
            // آپلود لوگو
            $logo = '';
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/clients/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $file_name = 'client_' . time() . '_' . uniqid() . '.' . $ext;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $file_name)) {
                    $logo = 'uploads/clients/' . $file_name;
                }
            }
            
            if (empty($logo)) $logo = 'assets/default-client.png';
            
            $new_client = [
                'id' => 'cl_' . uniqid(),
                'name' => sanitize($_POST['name'] ?? ''),
                'logo' => $logo,
                'website' => sanitize($_POST['website'] ?? ''),
                'order' => (int)($_POST['order'] ?? 1),
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $clients[] = $new_client;
            write_json('clients.json', $clients);
            add_log('add_client', "کارفرما {$new_client['name']} اضافه شد");
            $message = '✅ کارفرما اضافه شد';
            $show_form = false;
        }
        
        // ============ ویرایش کارفرما ============
        if ($_POST['action'] === 'edit_client') {
            $client_id = $_POST['client_id'] ?? '';
            $clients = read_json('clients.json');
            
            foreach ($clients as &$client) {
                if ($client['id'] === $client_id) {
                    // آپلود لوگوی جدید
                    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = '../uploads/clients/';
                        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                        
                        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                        $file_name = 'client_' . time() . '_' . uniqid() . '.' . $ext;
                        
                        if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $file_name)) {
                            $client['logo'] = 'uploads/clients/' . $file_name;
                        }
                    }
                    
                    $client['name'] = sanitize($_POST['name'] ?? '');
                    $client['website'] = sanitize($_POST['website'] ?? '');
                    $client['order'] = (int)($_POST['order'] ?? 1);
                    break;
                }
            }
            
            write_json('clients.json', $clients);
            add_log('edit_client', "کارفرما {$client_id} ویرایش شد");
            $message = '✅ کارفرما ویرایش شد';
            $show_form = false;
            $editing_client = null;
        }
        
        // ============ حذف کارفرما ============
        if ($_POST['action'] === 'delete_client') {
            $client_id = $_POST['client_id'] ?? '';
            $clients = read_json('clients.json');
            
            foreach ($clients as $key => $client) {
                if ($client['id'] === $client_id) {
                    unset($clients[$key]);
                    write_json('clients.json', array_values($clients));
                    add_log('delete_client', "کارفرما {$client['name']} حذف شد");
                    $message = '✅ کارفرما حذف شد';
                    break;
                }
            }
        }
    }
}

$clients = read_json('clients.json');
if (!is_array($clients)) $clients = [];

// مرتب‌سازی
usort($clients, function($a, $b) {
    return ($a['order'] ?? 0) - ($b['order'] ?? 0);
});
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت کارفرمایان | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .clients-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 25px; flex-wrap: wrap; gap: 15px;
        }
        .stat-pill {
            padding: 8px 18px; border-radius: 25px;
            font-size: 13px; font-weight: 700;
            background: #eee; color: #666;
        }
        
        .clients-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .client-card {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
            text-align: center;
        }
        .client-card:hover {
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transform: translateY(-4px);
        }
        
        .client-logo {
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fafafa;
            border-bottom: 1px solid #f0f0f0;
            padding: 15px;
        }
        .client-logo img {
            max-width: 100%;
            max-height: 90px;
            object-fit: contain;
        }
        
        .client-body { padding: 15px; }
        .client-name { font-size: 14px; font-weight: 800; margin-bottom: 5px; }
        .client-website {
            font-size: 10px; color: #999;
            direction: ltr; text-align: center;
        }
        
        .client-footer {
            display: flex; justify-content: center; gap: 6px;
            padding: 12px; border-top: 1px solid #f0f0f0;
        }
        .btn-icon {
            width: 35px; height: 35px; border-radius: 10px;
            border: 1px solid #ddd; background: #fff;
            cursor: pointer; font-size: 14px;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.3s; text-decoration: none;
        }
        .btn-icon.edit:hover { background: #e8f4fd; border-color: #3742fa; }
        .btn-icon.delete:hover { background: #ffebee; border-color: #ff4757; }
        
        .form-modern {
            background: #fff; border-radius: 20px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.05); overflow: hidden;
        }
        .form-modern__header {
            padding: 25px 30px;
            background: linear-gradient(135deg, #1a1a1a, #333);
            color: #fff;
            display: flex; justify-content: space-between; align-items: center;
        }
        .form-modern__header h2 { font-size: 20px; font-weight: 900; margin: 0; }
        .btn-back {
            background: rgba(255,255,255,0.1); color: #fff;
            border: 1px solid rgba(255,255,255,0.2);
            padding: 8px 15px; border-radius: 10px;
            text-decoration: none; font-size: 12px;
        }
        .form-modern__body { padding: 30px; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 15px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 700; color: #555; }
        .form-group input {
            padding: 10px 14px; border: 1px solid #e0e0e0;
            border-radius: 10px; font-family: inherit; font-size: 13px;
            background: #fafafa;
        }
        .form-group input:focus { outline: none; border-color: #1a1a1a; }
        
        .upload-area {
            border: 2px dashed #ddd; border-radius: 15px;
            padding: 25px; text-align: center; cursor: pointer;
            transition: all 0.3s; background: #fafafa;
        }
        .upload-area:hover { border-color: #1a1a1a; }
        .logo-preview {
            width: 120px; height: 60px;
            object-fit: contain; margin: 15px auto 0;
            display: none;
        }
        
        .form-modern__footer {
            padding: 20px 30px; border-top: 1px solid #f0f0f0;
            display: flex; gap: 10px;
        }
        .btn {
            padding: 11px 25px; border: none; border-radius: 10px;
            font-family: inherit; font-size: 13px; font-weight: 700;
            cursor: pointer; transition: all 0.3s; text-decoration: none;
        }
        .btn-primary { background: #1a1a1a; color: #fff; }
        .btn-secondary { background: #f5f5f5; color: #666; }
        
        @media (max-width: 768px) {
            .form-grid-2 { grid-template-columns: 1fr; }
            .form-group.full { grid-column: auto; }
            .clients-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <h1>🏢 مدیریت کارفرمایان</h1>
                <?php if ($is_viewer): ?>
                    <span class="badge" style="background:#fff8e1;color:#e65100;">👁️ حالت مشاهده</span>
                <?php endif; ?>
            </header>
            
            <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            
            <?php if ($show_form): ?>
            <div class="form-modern">
                <div class="form-modern__header">
                    <h2><?php echo $editing_client ? '✏️ ویرایش کارفرما' : '➕ افزودن کارفرما'; ?></h2>
                    <a href="manage-clients.php" class="btn-back">← بازگشت</a>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?php echo $editing_client ? 'edit_client' : 'add_client'; ?>">
                    <?php if ($editing_client): ?>
                        <input type="hidden" name="client_id" value="<?php echo $editing_client['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-modern__body">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>نام کارفرما *</label>
                                <input type="text" name="name" value="<?php echo $editing_client['name'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : 'required'; ?>>
                            </div>
                            
                            <div class="form-group">
                                <label>وب‌سایت (اختیاری)</label>
                                <input type="text" name="website" value="<?php echo $editing_client['website'] ?? ''; ?>" placeholder="https://..." <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            
                            <div class="form-group">
                                <label>ترتیب نمایش</label>
                                <input type="number" name="order" value="<?php echo $editing_client['order'] ?? 1; ?>" min="1" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            
                            <div class="form-group full">
                                <label>لوگو:</label>
                                <?php if (!$is_viewer): ?>
                                <div class="upload-area" onclick="document.getElementById('logoInput').click()">
                                    <span style="font-size:30px;">🏢</span>
                                    <p style="font-size:12px;">کلیک کنید و لوگو را آپلود کنید</p>
                                    <?php if ($editing_client && !empty($editing_client['logo'])): ?>
                                        <img src="../<?php echo $editing_client['logo']; ?>" class="logo-preview" id="logoPreview" style="display:block;">
                                    <?php else: ?>
                                        <img src="" class="logo-preview" id="logoPreview">
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="logoInput" name="logo" accept="image/*" style="display:none;" onchange="previewLogo(this)">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!$is_viewer): ?>
                    <div class="form-modern__footer">
                        <button type="submit" class="btn btn-primary">
                            <?php echo $editing_client ? '💾 ذخیره' : '➕ افزودن'; ?>
                        </button>
                        <a href="manage-clients.php" class="btn btn-secondary">انصراف</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php else: ?>
            
            <div class="clients-header">
                <span class="stat-pill">🏢 <?php echo count($clients); ?> کارفرما</span>
                <?php if (!$is_viewer): ?>
                    <a href="manage-clients.php?add=1" class="btn btn-primary">➕ افزودن کارفرما</a>
                <?php endif; ?>
            </div>
            
            <?php if (empty($clients)): ?>
                <div class="empty-state">
                    <p>🏢</p>
                    <p>کارفرمایی وجود ندارد</p>
                </div>
            <?php else: ?>
                <div class="clients-grid">
                    <?php foreach ($clients as $client): ?>
                    <div class="client-card">
                        <div class="client-logo">
                            <img src="../<?php echo $client['logo'] ?? 'assets/default-client.png'; ?>" alt="<?php echo $client['name']; ?>" onerror="this.src='../assets/default-client.png'">
                        </div>
                        <div class="client-body">
                            <h3 class="client-name"><?php echo $client['name']; ?></h3>
                            <?php if (!empty($client['website'])): ?>
                                <div class="client-website"><?php echo $client['website']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="client-footer">
                            <a href="manage-clients.php?edit=<?php echo $client['id']; ?>" class="btn-icon edit" title="ویرایش"><?php echo $is_viewer ? '👁️' : '✏️'; ?></a>
                            <?php if (!$is_viewer): ?>
                            <form method="POST" onsubmit="return confirm('حذف شود؟');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_client">
                                <input type="hidden" name="client_id" value="<?php echo $client['id']; ?>">
                                <button type="submit" class="btn-icon delete" title="حذف">🗑️</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
    function previewLogo(input) {
        const preview = document.getElementById('logoPreview');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>
</body>
</html>