<?php
/**
 * ============================================================================
 *  Odsco — اسکریپت زمان‌بندی (cron)
 * ----------------------------------------------------------------------------
 *  کارها:
 *    • علامت‌گذاری غیبت روز قبل
 *    • پاک‌سازی پیام‌های منقضی‌شده از سرور (نسخه روی دستگاه کاربر می‌ماند)
 *    • هشدار تسک‌های عقب‌افتاده / نزدیک به سررسید
 *    • هشدار پروژه‌های نزدیک به پایان
 *
 *  نمونه cron (هر روز ساعت ۷ صبح):
 *    0 7 * * * php /path/to/site/tools/cron.php >> /dev/null 2>&1
 *
 *  اجرای دستی از طریق وب هم ممکن است (نیاز به کلید امنیتی):
 *    https://yoursite.com/tools/cron.php?key=YOUR_KEY
 * ============================================================================
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/../includes/config.php';
    $expected = (string)Settings::get('cron_key', '');
    $given = (string)($_GET['key'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("⛔ کلید نامعتبر.\nکلید را از پنل مدیریت ← تنظیمات ← اتوماسیون بردارید.\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
} else {
    require_once __DIR__ . '/../includes/config.php';
}

require_once __DIR__ . '/../includes/automation.php';

echo "Odsco cron — " . date('Y-m-d H:i:s') . PHP_EOL;
echo str_repeat('-', 50) . PHP_EOL;

try {
    $report = Automation::runDaily();

    echo "غیبت‌های ثبت‌شده روز قبل : " . $report['absent'] . PHP_EOL;
    echo "پیام‌های پاک‌شده از سرور  : " . $report['purged'] . PHP_EOL;
    echo "تسک‌های عقب‌افتاده       : " . $report['overdue'] . PHP_EOL;
    echo "تسک‌های نزدیک سررسید     : " . $report['due_soon'] . PHP_EOL;
    echo "پروژه‌های نزدیک پایان    : " . $report['deadline'] . PHP_EOL;
    echo str_repeat('-', 50) . PHP_EOL;
    echo "✅ پایان" . PHP_EOL;
} catch (Throwable $e) {
    echo '❌ خطا: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
