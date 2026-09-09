<?php
/**
 * ============================================================================
 *  Odsco — لاگ فعالیت (ذخیره در جدول activity_logs)
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function add_log(string $action, string $details = ''): void
{
    try {
        ActivityLog::add($action, $details);
    } catch (Throwable $e) {
        error_log('[Odsco] add_log failed: ' . $e->getMessage());
    }
}

/** @return array<int, array<string, mixed>> */
function get_logs(int $limit = 100): array
{
    try {
        return ActivityLog::list($limit);
    } catch (Throwable) {
        return [];
    }
}

function clear_logs(): void
{
    ActivityLog::clear();
}
