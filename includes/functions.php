<?php
require_once 'config.php';

function get_settings() {
    $settings = read_json('settings.json');
    if (empty($settings)) {
        $settings = [
            'site_name' => 'مشاوران افق دانش ثریا',
            'phone_1' => '09161148583',
            'phone_2' => '09123357794',
            'email' => 'info@ofogh-danesh.ir',
            'address' => 'تهران، خیابان ولیعصر',
            'working_hours' => 'شنبه تا پنجشنبه ۸ صبح تا ۶ عصر'
        ];
        write_json('settings.json', $settings);
    }
    return $settings;
}

function get_projects($limit = null) {
    $projects = read_json('projects.json');
    if ($limit) return array_slice($projects, 0, $limit);
    return $projects;
}

function get_project_by_id($id) {
    $projects = read_json('projects.json');
    foreach ($projects as $project) {
        if ($project['id'] === $id) return $project;
    }
    return null;
}

function get_blog_posts($limit = null) {
    $posts = read_json('blog_posts.json');
    usort($posts, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    if ($limit) return array_slice($posts, 0, $limit);
    return $posts;
}

function get_blog_post_by_slug($slug) {
    $posts = read_json('blog_posts.json');
    foreach ($posts as $post) {
        if ($post['slug'] === $slug) return $post;
    }
    return null;
}

function get_messages($unread_only = false) {
    $messages = read_json('messages.json');
    if ($unread_only) {
        return array_filter($messages, function($msg) {
            return !isset($msg['is_read']) || $msg['is_read'] === false;
        });
    }
    return $messages;
}

function get_team_members() {
    return read_json('team.json');
}

function format_date($date) {
    $timestamp = strtotime($date);
    $months = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    $day = date('d', $timestamp);
    $month = $months[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp);
    return $day . ' ' . $month . ' ' . $year;
}

// ==============================================
// سیستم بازدید
// ==============================================

// افزایش بازدید پروژه
function increment_project_view($project_id) {
    $projects = read_json('projects.json');
    foreach ($projects as &$project) {
        if ($project['id'] === $project_id) {
            $project['views'] = ($project['views'] ?? 0) + 1;
            break;
        }
    }
    write_json('projects.json', $projects);
}

// افزایش بازدید مقاله
function increment_post_view($post_slug) {
    $posts = read_json('blog_posts.json');
    foreach ($posts as &$post) {
        if ($post['slug'] === $post_slug) {
            $post['views'] = ($post['views'] ?? 0) + 1;
            break;
        }
    }
    write_json('blog_posts.json', $posts);
}

// ثبت بازدید روزانه
function track_daily_view() {
    $views_file = 'views.json';
    $views_data = read_json($views_file);
    $today = date('Y-m-d');
    
    $found = false;
    foreach ($views_data as &$view) {
        if (isset($view['date']) && $view['date'] === $today) {
            $view['count'] = ($view['count'] ?? 0) + 1;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $views_data[] = ['date' => $today, 'count' => 1];
        // فقط 30 روز اخیر
        if (count($views_data) > 30) {
            $views_data = array_slice($views_data, -30);
        }
    }
    
    write_json($views_file, $views_data);
}

// دریافت بازدید امروز
function get_today_views() {
    $views_data = read_json('views.json');
    $today = date('Y-m-d');
    
    foreach ($views_data as $view) {
        if (isset($view['date']) && $view['date'] === $today) {
            return $view['count'] ?? 0;
        }
    }
    return 0;
}

// دریافت بازدید کل
function get_total_views() {
    $total = 0;
    
    // بازدید پروژه‌ها
    $projects = read_json('projects.json');
    foreach ($projects as $project) {
        $total += $project['views'] ?? 0;
    }
    
    // بازدید مقالات
    $posts = read_json('blog_posts.json');
    foreach ($posts as $post) {
        $total += $post['views'] ?? 0;
    }
    
    return $total;
}

// دریافت بازدید پروژه
function get_project_views($project_id) {
    $project = get_project_by_id($project_id);
    return $project['views'] ?? 0;
}
?>