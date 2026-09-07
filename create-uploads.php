<?php
// create-uploads.php
$dirs = [
    'uploads',
    'uploads/projects',
    'uploads/team'
];

foreach ($dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
        echo "✅ پوشه $dir ساخته شد<br>";
    } else {
        echo "⚠️ پوشه $dir از قبل وجود دارد<br>";
    }
}

// ساخت فایل team.json اگر وجود ندارد
if (!file_exists('data/team.json')) {
    $default_team = [
        [
            'id' => 'team_001',
            'name' => 'سید مصطفی شیخ الاسلامی',
            'role' => 'مدیرعامل و رئیس هیئت مدیره',
            'bio' => 'دارای دکترای مدیریت کسب و کار و کارشناس ارشد سازه‌های هیدرولیکی.',
            'photo' => 'assets/1-prf.png',
            'order' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 'team_002',
            'name' => 'علیرضا مومنی',
            'role' => 'عضو هیئت مدیره',
            'bio' => 'کارشناس ارشد معماری',
            'photo' => 'assets/2-prf.jpg',
            'order' => 2,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    file_put_contents('data/team.json', json_encode($default_team, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "✅ فایل team.json ساخته شد<br>";
} else {
    echo "⚠️ فایل team.json از قبل وجود دارد<br>";
}

echo "<br>🎉 همه چیز آماده است!";
?>