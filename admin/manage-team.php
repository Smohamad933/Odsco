<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$message = '';
$error = '';
$editing_member = null;
$show_form = isset($_GET['add']) || isset($_GET['edit']);
$is_viewer = ($_SESSION['admin_role'] ?? '') === 'viewer';

if (isset($_GET['edit'])) {
    $members = read_json('team.json');
    foreach ($members as $member) {
        if ($member['id'] === $_GET['edit']) {
            $editing_member = $member;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        
        // ============ افزودن عضو ============
        if ($_POST['action'] === 'add_member') {
            $members = read_json('team.json');
            
            // آپلود عکس
            $photo = '';
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/team/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_ext, $allowed)) {
                    $file_name = 'team_' . time() . '_' . uniqid() . '.' . $file_ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
                        $photo = 'uploads/team/' . $file_name;
                    } else {
                        $error = '❌ خطا در آپلود عکس';
                    }
                } else {
                    $error = '❌ فرمت عکس مجاز نیست (JPG, PNG, GIF, WEBP)';
                }
            }
            
            if (empty($photo)) {
                $photo = 'assets/default-user.png';
            }
            
            $new_member = [
                'id' => 'team_' . uniqid(),
                'name' => sanitize($_POST['name']),
                'role' => sanitize($_POST['role']),
                'bio' => sanitize($_POST['bio'] ?? ''),
                'photo' => $photo,
                'email' => sanitize($_POST['email'] ?? ''),
                'phone' => sanitize($_POST['phone'] ?? ''),
                'linkedin' => sanitize($_POST['linkedin'] ?? ''),
                'instagram' => sanitize($_POST['instagram'] ?? ''),
                'order' => (int)($_POST['order'] ?? 1),
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $members[] = $new_member;
            write_json('team.json', $members);
            add_log('add_team_member', "عضو {$new_member['name']} اضافه شد");
            $message = '✅ عضو اضافه شد';
            $show_form = false;
        }
        
        // ============ ویرایش عضو ============
        if ($_POST['action'] === 'edit_member') {
            $member_id = $_POST['member_id'];
            $members = read_json('team.json');
            
            foreach ($members as &$member) {
                if ($member['id'] === $member_id) {
                    
                    // آپلود عکس جدید
                    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = '../uploads/team/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                        
                        if (in_array($file_ext, $allowed)) {
                            $file_name = 'team_' . time() . '_' . uniqid() . '.' . $file_ext;
                            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
                                $member['photo'] = 'uploads/team/' . $file_name;
                            }
                        }
                    }
                    
                    $member['name'] = sanitize($_POST['name']);
                    $member['role'] = sanitize($_POST['role']);
                    $member['bio'] = sanitize($_POST['bio'] ?? '');
                    $member['email'] = sanitize($_POST['email'] ?? '');
                    $member['phone'] = sanitize($_POST['phone'] ?? '');
                    $member['linkedin'] = sanitize($_POST['linkedin'] ?? '');
                    $member['instagram'] = sanitize($_POST['instagram'] ?? '');
                    $member['order'] = (int)($_POST['order'] ?? 1);
                    $member['updated_at'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            
            write_json('team.json', $members);
            add_log('edit_team_member', "عضو {$member_id} ویرایش شد");
            $message = '✅ عضو ویرایش شد';
            $show_form = false;
            $editing_member = null;
        }
        
        // ============ حذف عضو ============
        if ($_POST['action'] === 'delete_member') {
            $member_id = $_POST['member_id'];
            $members = read_json('team.json');
            
            foreach ($members as $key => $member) {
                if ($member['id'] === $member_id) {
                    unset($members[$key]);
                    write_json('team.json', array_values($members));
                    add_log('delete_team_member', "عضو {$member['name']} حذف شد");
                    $message = '✅ عضو حذف شد';
                    break;
                }
            }
        }
    }
}

$team_members = get_team_members();
usort($team_members, function($a, $b) {
    return ($a['order'] ?? 0) - ($b['order'] ?? 0);
});
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت اعضای تیم | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .team-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .stat-pill { padding: 8px 18px; border-radius: 25px; font-size: 13px; font-weight: 700; background: #eee; color: #666; }
        .team-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }
        
        .member-card { background: #fff; border-radius: 16px; overflow: hidden; border: 1px solid #e9ecef; transition: all 0.3s; text-align: center; }
        .member-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); transform: translateY(-4px); }
        .member-photo { position: relative; height: 200px; overflow: hidden; background: #f5f5f5; }
        .member-photo img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s; }
        .member-card:hover .member-photo img { transform: scale(1.05); }
        .member-order { position: absolute; top: 10px; right: 10px; width: 30px; height: 30px; background: rgba(0,0,0,0.7); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 900; }
        .member-body { padding: 20px; }
        .member-name { font-size: 15px; font-weight: 800; margin-bottom: 5px; }
        .member-role { display: inline-block; padding: 4px 12px; border-radius: 15px; font-size: 11px; font-weight: 700; background: #1a1a1a; color: #fff; margin-bottom: 10px; }
        .member-bio { font-size: 12px; color: #666; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .member-contact { display: flex; justify-content: center; gap: 8px; font-size: 10px; color: #999; flex-wrap: wrap; margin-top: 8px; }
        .member-footer { display: flex; justify-content: center; gap: 6px; padding: 15px; border-top: 1px solid #f0f0f0; }
        .btn-icon { width: 35px; height: 35px; border-radius: 10px; border: 1px solid #ddd; background: #fff; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: all 0.3s; text-decoration: none; }
        .btn-icon:hover { background: #f5f5f5; }
        
        .viewer-banner { background: #fff8e1; border: 1px solid #ffa502; color: #e65100; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; font-size: 13px; font-weight: 700; }
        
        .form-modern { background: #fff; border-radius: 20px; box-shadow: 0 5px 30px rgba(0,0,0,0.05); overflow: hidden; }
        .form-modern__header { padding: 25px 30px; background: linear-gradient(135deg, #1a1a1a, #333); color: #fff; display: flex; justify-content: space-between; align-items: center; }
        .form-modern__header h2 { font-size: 20px; font-weight: 900; margin: 0; }
        .btn-back { background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 10px; text-decoration: none; font-size: 12px; }
        .form-modern__body { padding: 30px; }
        .form-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 700; color: #555; }
        .form-group input, .form-group textarea { padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 10px; font-family: inherit; font-size: 13px; background: #fafafa; }
        .form-group input:disabled, .form-group textarea:disabled { background: #f5f5f5; color: #999; }
        
        .photo-upload-area {
            border: 2px dashed #ddd; border-radius: 15px; padding: 25px;
            text-align: center; cursor: pointer; transition: all 0.3s; background: #fafafa;
        }
        .photo-upload-area:hover { border-color: #1a1a1a; background: #f5f5f5; }
        .photo-preview { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; margin: 15px auto 0; border: 3px solid #e0e0e0; display: none; }
        
        .form-modern__footer { padding: 20px 30px; border-top: 1px solid #f0f0f0; display: flex; gap: 10px; }
        .btn { padding: 11px 25px; border: none; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-primary { background: #1a1a1a; color: #fff; }
        .btn-secondary { background: #f5f5f5; color: #666; }
        
        @media (max-width: 768px) {
            .form-grid-3 { grid-template-columns: 1fr; }
            .form-group.full { grid-column: auto; }
            .team-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <h1>👥 مدیریت اعضای تیم</h1>
                <?php if ($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100;">👁️ حالت مشاهده</span><?php endif; ?>
            </header>
            
            <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <?php if ($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید!</div><?php endif; ?>
            
            <?php if ($show_form): ?>
            <div class="form-modern">
                <div class="form-modern__header">
                    <h2><?php echo $editing_member ? '✏️ ویرایش عضو' : '➕ افزودن عضو'; ?></h2>
                    <a href="manage-team.php" class="btn-back">← بازگشت</a>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?php echo $editing_member ? 'edit_member' : 'add_member'; ?>">
                    <?php if ($editing_member): ?><input type="hidden" name="member_id" value="<?php echo $editing_member['id']; ?>"><?php endif; ?>
                    
                    <div class="form-modern__body">
                        <div class="form-grid-3">
                            <div class="form-group">
                                <label>نام و نام خانوادگی *</label>
                                <input type="text" name="name" value="<?php echo $editing_member['name'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : 'required'; ?>>
                            </div>
                            <div class="form-group">
                                <label>سمت *</label>
                                <input type="text" name="role" value="<?php echo $editing_member['role'] ?? ''; ?>" placeholder="مثلاً: مدیرعامل" <?php echo $is_viewer ? 'disabled' : 'required'; ?>>
                            </div>
                            <div class="form-group">
                                <label>ترتیب نمایش</label>
                                <input type="number" name="order" value="<?php echo $editing_member['order'] ?? 1; ?>" min="1" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            
                            <div class="form-group full">
                                <label>توضیحات</label>
                                <textarea name="bio" rows="3" <?php echo $is_viewer ? 'disabled' : ''; ?>><?php echo $editing_member['bio'] ?? ''; ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>ایمیل</label>
                                <input type="email" name="email" value="<?php echo $editing_member['email'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>تلفن</label>
                                <input type="text" name="phone" value="<?php echo $editing_member['phone'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>اینستاگرام</label>
                                <input type="text" name="instagram" value="<?php echo $editing_member['instagram'] ?? ''; ?>" placeholder="@username" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            
                            <!-- آپلود عکس -->
                            <div class="form-group full">
                                <label>عکس عضو:</label>
                                <?php if (!$is_viewer): ?>
                                <div class="photo-upload-area" onclick="document.getElementById('photoInput').click()">
                                    <span style="font-size:30px;">📸</span>
                                    <p style="font-size:12px;">کلیک کنید و عکس را انتخاب کنید</p>
                                    <p style="font-size:10px;color:#999;">JPG, PNG, GIF, WEBP</p>
                                    <?php if ($editing_member && !empty($editing_member['photo'])): ?>
                                        <img src="../<?php echo $editing_member['photo']; ?>" class="photo-preview" id="photoPreview" style="display:block;">
                                    <?php else: ?>
                                        <img src="" class="photo-preview" id="photoPreview">
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="photoInput" name="photo" accept="image/*" style="display:none;" onchange="previewPhoto(this)">
                                <?php else: ?>
                                    <?php if (!empty($editing_member['photo'])): ?>
                                        <img src="../<?php echo $editing_member['photo']; ?>" style="width:100px;height:100px;border-radius:50%;object-fit:cover;">
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!$is_viewer): ?>
                    <div class="form-modern__footer">
                        <button type="submit" class="btn btn-primary"><?php echo $editing_member ? '💾 ذخیره' : '➕ افزودن'; ?></button>
                        <a href="manage-team.php" class="btn btn-secondary">انصراف</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php else: ?>
            
            <div class="team-header">
                <span class="stat-pill">👥 <?php echo count($team_members); ?> عضو</span>
                <?php if (!$is_viewer): ?><a href="manage-team.php?add=1" class="btn btn-primary">➕ افزودن عضو</a><?php endif; ?>
            </div>
            
            <?php if (empty($team_members)): ?>
                <div class="empty-state">
                    <p>👥</p>
                    <p>هنوز عضوی اضافه نشده</p>
                </div>
            <?php else: ?>
                <div class="team-grid">
                    <?php foreach ($team_members as $member): ?>
                    <div class="member-card">
                        <div class="member-photo">
                            <img src="../<?php echo $member['photo'] ?? 'assets/default-user.png'; ?>" alt="<?php echo $member['name']; ?>" onerror="this.src='../assets/default-user.png'">
                            <span class="member-order"><?php echo $member['order'] ?? 1; ?></span>
                        </div>
                        <div class="member-body">
                            <h3 class="member-name"><?php echo $member['name']; ?></h3>
                            <span class="member-role"><?php echo $member['role']; ?></span>
                            <?php if (!empty($member['bio'])): ?><p class="member-bio"><?php echo $member['bio']; ?></p><?php endif; ?>
                            <?php if (!empty($member['email']) || !empty($member['phone'])): ?>
                            <div class="member-contact">
                                <?php if (!empty($member['email'])): ?><span>📧 <?php echo $member['email']; ?></span><?php endif; ?>
                                <?php if (!empty($member['phone'])): ?><span>📞 <?php echo $member['phone']; ?></span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="member-footer">
                            <a href="manage-team.php?edit=<?php echo $member['id']; ?>" class="btn-icon" title="ویرایش"><?php echo $is_viewer ? '👁️' : '✏️'; ?></a>
                            <?php if (!$is_viewer): ?>
                            <form method="POST" onsubmit="return confirm('حذف شود؟');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_member">
                                <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                <button type="submit" class="btn-icon" title="حذف">🗑️</button>
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
    function previewPhoto(input) {
        const preview = document.getElementById('photoPreview');
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