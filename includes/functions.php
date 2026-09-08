<?php
/**
 * ============================================================================
 *  Odsco — توابع عمومی سایت (لایه سازگار با کد قبلی)
 * ----------------------------------------------------------------------------
 *  نام توابع حفظ شده تا صفحات سایت بدون تغییر کار کنند،
 *  ولی همه از MySQL می‌خوانند.
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// تنظیمات
// ---------------------------------------------------------------------------

function get_settings(): array
{
    return Settings::all();
}

function set_setting(string $key, mixed $value): void
{
    Settings::set($key, $value);
}

// ---------------------------------------------------------------------------
// پروژه‌ها
// ---------------------------------------------------------------------------

/** @return array<int, array<string, mixed>> */
function get_projects(?int $limit = null): array
{
    $filters = $limit ? ['limit' => $limit] : [];
    return Projects::list($filters);
}

function get_project_by_id(string $id): ?array
{
    return Projects::find($id);
}

function get_project_views(string $project_id): int
{
    return (int)(Projects::find($project_id)['views'] ?? 0);
}

function increment_project_view(string $project_id): void
{
    Projects::incrementView($project_id);
}

// ---------------------------------------------------------------------------
// بلاگ
// ---------------------------------------------------------------------------

/** @return array<int, array<string, mixed>> */
function get_blog_posts(?int $limit = null, bool $publishedOnly = true): array
{
    $filters = $publishedOnly ? ['status' => 'published'] : [];
    if ($limit) $filters['limit'] = $limit;
    return Blog::list($filters);
}

function get_blog_post_by_slug(string $slug): ?array
{
    return Blog::findBySlug($slug);
}

function increment_post_view(string $post_id): void
{
    $post = Blog::findBySlug($post_id) ?? Blog::find($post_id);
    if ($post) Blog::incrementView($post['uid']);
}

// ---------------------------------------------------------------------------
// پیام‌های فرم تماس
// ---------------------------------------------------------------------------

/** @return array<int, array<string, mixed>> */
function get_messages(bool $unread_only = false): array
{
    return ContactMessages::list($unread_only ? ['unread' => true] : []);
}

// ---------------------------------------------------------------------------
// تیم / کارفرمایان / دسته‌ها
// ---------------------------------------------------------------------------

function get_team_members(): array
{
    return Team::list(true);
}

function get_clients(): array
{
    return Clients::list(true);
}

function get_categories(): array
{
    return Categories::list();
}

// ---------------------------------------------------------------------------
// تاریخ
// ---------------------------------------------------------------------------

/** @deprecated از jalali_date_long() استفاده کنید */
function format_date(?string $date): string
{
    return jalali_date_long($date);
}

/** @deprecated از date('H:i') استفاده کنید */
function format_time_fa(?string $date): string
{
    return $date ? date('H:i', (int)strtotime($date)) : '';
}

// ---------------------------------------------------------------------------
// آمار بازدید
// ---------------------------------------------------------------------------

function track_daily_view(): void
{
    // فقط یک‌بار در هر نشست برای هر کاربر
    if (!empty($_SESSION['view_tracked_' . date('Ymd')])) return;
    $_SESSION['view_tracked_' . date('Ymd')] = true;

    try {
        Views::track();
    } catch (Throwable $e) {
        error_log('[Odsco] track_daily_view: ' . $e->getMessage());
    }
}

function get_today_views(): int
{
    return Views::today();
}

function get_total_views(): int
{
    return Views::total();
}

/** سری زمانی بازدید برای نمودار داشبورد */
function get_views_series(int $days = 30): array
{
    return Views::series($days);
}
