<?php
require_once 'config.php';
function add_log($action, $details = '') {
    $logs = read_json('logs.json');
    array_unshift($logs, [
        'id' => uniqid(),
        'action' => $action,
        'details' => $details,
        'user' => $_SESSION['admin_user'] ?? 'guest',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    write_json('logs.json', array_slice($logs, 0, 1000));
}
function get_logs($limit = 100) {
    return array_slice(read_json('logs.json'), 0, $limit);
}
?>
