<?php
/**
 * ============================================================================
 *  Odsco — ساخت کاربران تست (برای تست‌های HTTP و DOM)
 * ----------------------------------------------------------------------------
 *  تست‌های مرحله ۷ و ۸ با این حساب‌ها وارد سایت می‌شوند:
 *      qa_admin / qa_manager / qa_emp / qa_client   رمز: QaPass1234
 *
 *  عمداً بعد از تست یکپارچه اجرا می‌شود چون آن تست داده‌های خودش را پاک می‌کند.
 *
 *  اجرا:  php tools/make-test-users.php
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

if (!Db::ready()) {
    fwrite(STDERR, "❌ دیتابیس آماده نیست\n");
    exit(1);
}

const QA_PASSWORD = 'QaPass1234';

$made = 0;
foreach ([
    ['qa_admin',   'admin',    'ادمین تست'],
    ['qa_manager', 'manager',  'مدیر تست'],
    ['qa_emp',     'employee', 'کارمند تست'],
] as [$username, $role, $fullName]) {
    if (Users::exists($username)) {
        $u = Users::findByUsername($username);
        $uid = (string)$u['uid'];
        Users::setPassword($uid, QA_PASSWORD);
        Users::update($uid, [
            'is_active' => 1, 'messenger_enabled' => 1,
            'attendance_enabled' => 1, 'role' => $role,
        ]);
    } else {
        Users::create([
            'username' => $username, 'password' => QA_PASSWORD, 'role' => $role,
            'full_name' => $fullName, 'messenger_enabled' => true, 'attendance_enabled' => true,
        ]);
        $made++;
    }
}

// کارفرمای تست، وصل به اولین کارفرما و اولین پروژه (تا پورتال کارفرما داده داشته باشد)
$clients = Clients::list();
$c0 = $clients[0] ?? null;
if ($c0) {
    $clientUid = (string)$c0['uid'];
    if (Users::exists('qa_client')) {
        $u = Users::findByUsername('qa_client');
        Users::setPassword((string)$u['uid'], QA_PASSWORD);
        Users::update((string)$u['uid'], ['is_active' => 1, 'client_uid' => $clientUid, 'role' => 'client']);
    } else {
        Users::create([
            'username' => 'qa_client', 'password' => QA_PASSWORD, 'role' => 'client',
            'full_name' => 'کارفرمای تست', 'client_uid' => $clientUid, 'messenger_enabled' => true,
        ]);
        $made++;
    }

    $projects = Projects::list();
    if ($projects) {
        Projects::update((string)$projects[0]['uid'], ['client_uid' => $clientUid]);
    }
} else {
    echo "⚠️  کارفرمایی در دیتابیس نیست — پورتال کارفرما خالی می‌ماند\n";
}

echo '✅ کاربران تست آماده شدند' . ($made > 0 ? ' (' . $made . ' حساب تازه)' : '') . "\n";
