<?php
require_once 'config.php';

// حذف از لیست آنلاین
if (isset($_SESSION['messenger_user_id'])) {
    $online = read_json('messenger_online.json');
    if (is_array($online)) {
        unset($online[$_SESSION['messenger_user_id']]);
        write_json('messenger_online.json', $online);
    }
}

// پاک کردن سشن
unset($_SESSION['messenger_user_id']);
unset($_SESSION['messenger_user_name']);
unset($_SESSION['messenger_user_photo']);
unset($_SESSION['messenger_user_role']);

header('Location: login.php');
exit;
?>