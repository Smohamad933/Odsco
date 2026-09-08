<?php
/**
 * ============================================================================
 *  Odsco — تبدیل تاریخ میلادی ↔ شمسی
 * ----------------------------------------------------------------------------
 *  ⚠️ تابع قدیمی format_date_fa() روز میلادی را با نام ماه شمسی چاپ می‌کرد
 *  (مثلاً «19 فروردین 2026») که کاملاً اشتباه بود. این فایل آن را اصلاح می‌کند.
 * ============================================================================
 */

declare(strict_types=1);

/** تبدیل میلادی به شمسی — الگوریتم استاندارد jdf */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
          + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/** تبدیل شمسی به میلادی */
function jalali_to_gregorian(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv((($jy % 33) + 3), 4)
          + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, ((($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28),
              31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    while ($gm < 13 && $gd > $sal_a[$gm]) {
        $gd -= $sal_a[$gm++];
    }
    return [$gy, $gm, $gd];
}

const ODSO_J_MONTHS = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                       'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
const ODSO_J_WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

/** @return array{0:int,1:int,2:int} [y,m,d] شمسی */
function jalali_today(): array
{
    return gregorian_to_jalali((int)date('Y'), (int)date('n'), (int)date('j'));
}

/** «۱۴۰۵/۰۶/۱۶» */
function jalali_date(?string $date = null, string $sep = '/'): string
{
    $ts = $date ? strtotime($date) : time();
    if (!$ts) return '';
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return sprintf('%04d%s%02d%s%02d', $jy, $sep, $jm, $sep, $jd);
}

/** «۱۶ شهریور ۱۴۰۵» */
function jalali_date_long(?string $date = null): string
{
    $ts = $date ? strtotime($date) : time();
    if (!$ts) return '';
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return $jd . ' ' . (ODSO_J_MONTHS[$jm] ?? '') . ' ' . $jy;
}

/** «دوشنبه ۱۶ شهریور ۱۴۰۵ — ۱۴:۳۲» */
function jalali_datetime(?string $date = null): string
{
    $ts = $date ? strtotime($date) : time();
    if (!$ts) return '';
    $wd = ODSO_J_WEEKDAYS[(int)date('N', $ts) - 1] ?? '';
    return $wd . ' ' . jalali_date_long($date) . ' — ' . date('H:i', $ts);
}

/** روز هفته شمسی: شنبه=۰ ... جمعه=۶ */
function jalali_weekday_index(?string $date = null): int
{
    $ts = $date ? (int)strtotime($date) : time();
    return ((int)date('N', $ts) - 1) % 7;   // ISO: Mon=1..Sun=7  →  شنبه=0
}

/** آیا این روز، تعطیل کاری است؟ (پیش‌فرض: جمعه) */
function is_weekend_day(?string $date = null): bool
{
    return jalali_weekday_index($date) === 6;
}

/** «۳ روز پیش» / «همین حالا» */
function time_ago_fa(?string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    if (!$ts) return '';

    $diff = time() - $ts;
    if ($diff < 5)    return 'همین حالا';
    if ($diff < 60)   return $diff . ' ثانیه پیش';
    if ($diff < 3600) return intdiv($diff, 60) . ' دقیقه پیش';
    if ($diff < 86400) return intdiv($diff, 3600) . ' ساعت پیش';
    if ($diff < 604800) return intdiv($diff, 86400) . ' روز پیش';
    return jalali_date_long($date);
}

/** زمان چت: امروز → ساعت، دیروز → «دیروز»، وگرنه تاریخ شمسی */
function chat_time_fa(?string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'دیروز';
    return jalali_date($date);
}

/** جداکننده‌ی روزهای چت (مثل تلگرام) */
function chat_day_label(string $date): string
{
    $ts = strtotime($date);
    if (!$ts) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return 'امروز';
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'دیروز';
    return jalali_date_long($date);
}

/** آخرین بازدید: «آخرین بازدید ۲ ساعت پیش» */
function last_seen_fa(?int $ts): string
{
    if (!$ts) return 'مدت‌ها پیش';
    $diff = time() - $ts;
    if ($diff < 60)    return 'همین حالا آنلاین بود';
    if ($diff < 3600)  return 'آخرین بازدید ' . intdiv($diff, 60) . ' دقیقه پیش';
    if ($diff < 86400) return 'آخرین بازدید ' . date('H:i', $ts);
    return 'آخرین بازدید ' . jalali_date($ts === null ? null : '@' . $ts);
}

/** ارقام فارسی */
function fa_digits(string|int|float $value): string
{
    return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

/** جداکننده هزارگان + ارقام فارسی */
function fa_number(int|float|string $value): string
{
    return fa_digits(number_format((float)$value));
}

/** «۱۴۰۵-۰۶-۰۱» شمسی → «2026-08-23» میلادی (برای ذخیره در DATE) */
function jalali_to_mysql_date(string $jdate): string
{
    $jdate = strtr($jdate, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
    if (!preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', trim($jdate), $m)) {
        return '';
    }
    [$gy, $gm, $gd] = jalali_to_gregorian((int)$m[1], (int)$m[2], (int)$m[3]);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}
