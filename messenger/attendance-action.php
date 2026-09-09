<?php
/**
 * ============================================================================
 *  Odsco Messenger — پردازش عملیات حضور و غیاب کارمند
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/attendance.php';

m_check_login();

$uid = current_messenger_uid();
$user = Users::find($uid);

if (!$user || empty($user['attendance_enabled'])) {
    m_set_flash('⛔ حضور و غیاب برای شما فعال نیست');
    redirect('attendance.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('attendance.php');
}

csrf_guard();

$act = (string)($_POST['act'] ?? '');

try {
    if ($act === 'checkin') {
        $r = Attendance::checkIn($uid);
        $label = $r['status'] === 'late' ? '⏰ ورود با تاخیر ثبت شد' : '🟢 ورود ثبت شد';
        m_set_flash($label . ' در ' . date('H:i'));
        Attendance::notifyManagers($uid, 'ثبت ورود', $user['full_name'] . ' ورود خود را در ' . date('H:i') . ' ثبت کرد.');

    } elseif ($act === 'checkout') {
        $r = Attendance::checkOut($uid);
        m_set_flash('🔴 خروج ثبت شد — کارکرد امروز: ' . fa_number(number_format(Attendance::workedHours($r), 1)) . ' ساعت');

    } elseif ($act === 'scan') {
        $code = trim((string)($_POST['code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('کد QR را وارد کنید');
        }
        $r = Attendance::scan($code, $uid);
        $which = $r['check_out'] && $r['check_in'] ? 'خروج' : 'ورود';
        m_set_flash('✅ ' . $which . ' با QR ثبت شد (' . date('H:i') . ')');

    } elseif ($act === 'request') {
        $day = (string)($_POST['day'] ?? '');
        if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new RuntimeException('تاریخ نامعتبر است');
        }
        Attendance::createRequest([
            'user_uid'  => $uid,
            'day'       => $day,
            'check_in'  => (string)($_POST['check_in'] ?? '08:00'),
            'check_out' => (string)($_POST['check_out'] ?? '16:00'),
            'reason'    => (string)($_POST['reason'] ?? ''),
        ]);
        m_set_flash('📝 درخواست شما ثبت شد و برای تایید مدیر ارسال گردید.');
        Attendance::notifyManagers($uid, 'درخواست اصلاح حضور', $user['full_name'] . ' برای ' . jalali_date($day) . ' درخواست دارد.');

    } else {
        throw new RuntimeException('عملیات نامعتبر');
    }
} catch (Throwable $e) {
    m_set_flash('⚠️ ' . $e->getMessage());
}

redirect('attendance.php');
