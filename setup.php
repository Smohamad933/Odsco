<?php
require_once 'includes/config.php';
if (!file_exists(DATA_PATH)) mkdir(DATA_PATH, 0755, true);
$users_data = ['users' => [[
    'id' => 'usr_001',
    'username' => 'mohusyn',
    'password' => password_hash('Smosh1387', PASSWORD_BCRYPT),
    'role' => 'admin',
    'full_name' => 'محمد حسین شیخ الاسلامی',
    'email' => 'mohusyn@ofogh-danesh.ir',
    'created_at' => date('Y-m-d H:i:s'),
    'is_active' => true
]]];
write_json('users.json', $users_data);
$settings = [
    'site_name' => 'مشاوران افق دانش ثریا',
    'phone_1' => '09161148583',
    'phone_2' => '09123357794',
    'email' => 'info@ofogh-danesh.ir',
    'address' => 'تهران، خیابان ولیعصر'
];
write_json('settings.json', $settings);
write_json('projects.json', []);
write_json('blog_posts.json', []);
write_json('messages.json', []);
write_json('logs.json', []);
echo "✅ راه‌اندازی انجام شد! حالا وارد پنل شوید";
echo "<br><a href='admin/login.php'>ورود به پنل مدیریت</a>";
?>
