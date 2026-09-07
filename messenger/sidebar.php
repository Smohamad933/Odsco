<?php
// messenger/sidebar.php
// سایدبار مشترک همه صفحات

m_check_login();

$me = m_current_user();
$my_id = $me['id'];
$my_name = $me['name'];
$my_photo = $me['photo'];
$my_role = $me['role'];

$chat_data = m_build_chat_list($my_id, $my_role);
$sidebar_chats = $chat_data['chats'];
$sidebar_total_unread = $chat_data['total_unread'];

$sidebar_users = m_available_users($my_id);
?>