-- Odsco — اسکیمای MySQL
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  username VARCHAR(64) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'viewer',
  full_name VARCHAR(160) NOT NULL DEFAULT '',
  email VARCHAR(160) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL DEFAULT '',
  photo VARCHAR(255) NOT NULL DEFAULT '',
  bio TEXT,
  job_title VARCHAR(160) NOT NULL DEFAULT '',
  department VARCHAR(160) NOT NULL DEFAULT '',
  national_code VARCHAR(32) NOT NULL DEFAULT '',
  hire_date DATE NULL,
  monthly_salary DECIMAL(14,2) NULL,
  work_start TIME NULL,
  work_end TIME NULL,
  attendance_enabled TINYINT(1) NOT NULL DEFAULT 0,
  messenger_enabled TINYINT(1) NOT NULL DEFAULT 0,
  client_uid VARCHAR(40) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE (uid),
  UNIQUE (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  setting_key VARCHAR(80) NOT NULL,
  setting_value LONGTEXT,
  updated_at DATETIME NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  name VARCHAR(200) NOT NULL,
  logo VARCHAR(255) NOT NULL DEFAULT '',
  website VARCHAR(255) NOT NULL DEFAULT '',
  contact_name VARCHAR(160) NOT NULL DEFAULT '',
  contact_phone VARCHAR(32) NOT NULL DEFAULT '',
  contact_email VARCHAR(160) NOT NULL DEFAULT '',
  address VARCHAR(255) NOT NULL DEFAULT '',
  notes TEXT,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(200) NOT NULL DEFAULT '',
  description TEXT,
  icon VARCHAR(16) NOT NULL DEFAULT '',
  color VARCHAR(16) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `projects`;
CREATE TABLE `projects` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL DEFAULT '',
  category VARCHAR(120) NOT NULL DEFAULT '',
  description LONGTEXT,
  images JSON,
  cover_image VARCHAR(255) NOT NULL DEFAULT '',
  location VARCHAR(255) NOT NULL DEFAULT '',
  client_name VARCHAR(200) NOT NULL DEFAULT '',
  client_uid VARCHAR(40) NOT NULL DEFAULT '',
  manager_uid VARCHAR(40) NOT NULL DEFAULT '',
  project_year VARCHAR(16) NOT NULL DEFAULT '',
  area VARCHAR(80) NOT NULL DEFAULT '',
  budget VARCHAR(80) NOT NULL DEFAULT '',
  specs JSON,
  progress INT NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  start_date DATE NULL,
  end_date DATE NULL,
  show_on_home TINYINT(1) NOT NULL DEFAULT 0,
  client_visible TINYINT(1) NOT NULL DEFAULT 0,
  views INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_members`;
CREATE TABLE `project_members` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  role_in_project VARCHAR(80) NOT NULL DEFAULT 'عضو تیم',
  percent INT NOT NULL DEFAULT 0,
  added_by VARCHAR(40) NOT NULL DEFAULT '',
  added_at DATETIME NOT NULL,
  UNIQUE (project_uid,
  user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_tasks`;
CREATE TABLE `project_tasks` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  project_uid VARCHAR(40) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description LONGTEXT,
  assignee_uid VARCHAR(40) NOT NULL DEFAULT '',
  status VARCHAR(24) NOT NULL DEFAULT 'todo',
  priority VARCHAR(16) NOT NULL DEFAULT 'normal',
  progress INT NOT NULL DEFAULT 0,
  due_date DATE NULL,
  created_by VARCHAR(40) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  completed_at DATETIME NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_task_comments`;
CREATE TABLE `project_task_comments` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  task_uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  body TEXT,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_updates`;
CREATE TABLE `project_updates` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  project_uid VARCHAR(40) NOT NULL,
  author_uid VARCHAR(40) NOT NULL DEFAULT '',
  title VARCHAR(255) NOT NULL,
  body LONGTEXT,
  progress INT NULL,
  level VARCHAR(16) NOT NULL DEFAULT 'info',
  visibility VARCHAR(16) NOT NULL DEFAULT 'both',
  attachment VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT,
  type VARCHAR(40) NOT NULL DEFAULT 'general',
  level VARCHAR(16) NOT NULL DEFAULT 'info',
  link VARCHAR(255) NOT NULL DEFAULT '',
  icon VARCHAR(16) NOT NULL DEFAULT '',
  source_uid VARCHAR(40) NOT NULL DEFAULT '',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `blog_posts`;
CREATE TABLE `blog_posts` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL DEFAULT '',
  excerpt TEXT,
  content LONGTEXT,
  image VARCHAR(255) NOT NULL DEFAULT '',
  category VARCHAR(120) NOT NULL DEFAULT '',
  author VARCHAR(120) NOT NULL DEFAULT '',
  tags JSON,
  status VARCHAR(24) NOT NULL DEFAULT 'published',
  views INT NOT NULL DEFAULT 0,
  post_date DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `team_members`;
CREATE TABLE `team_members` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  name VARCHAR(160) NOT NULL,
  role VARCHAR(160) NOT NULL DEFAULT '',
  bio TEXT,
  photo VARCHAR(255) NOT NULL DEFAULT '',
  email VARCHAR(160) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL DEFAULT '',
  linkedin VARCHAR(255) NOT NULL DEFAULT '',
  instagram VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `contact_messages`;
CREATE TABLE `contact_messages` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  name VARCHAR(160) NOT NULL DEFAULT '',
  email VARCHAR(160) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL DEFAULT '',
  subject VARCHAR(255) NOT NULL DEFAULT '',
  body TEXT,
  attachment JSON,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  is_starred TINYINT(1) NOT NULL DEFAULT 0,
  is_trashed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `media_files`;
CREATE TABLE `media_files` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  path VARCHAR(500) NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  mime VARCHAR(120) NOT NULL DEFAULT '',
  size_bytes BIGINT NOT NULL DEFAULT 0,
  folder VARCHAR(80) NOT NULL DEFAULT '',
  uploaded_by VARCHAR(64) NOT NULL DEFAULT '',
  is_trashed TINYINT(1) NOT NULL DEFAULT 0,
  trashed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `site_views`;
CREATE TABLE `site_views` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  day DATE NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  UNIQUE (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  action VARCHAR(64) NOT NULL DEFAULT '',
  details TEXT,
  actor VARCHAR(64) NOT NULL DEFAULT 'guest',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_conversations`;
CREATE TABLE `messenger_conversations` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  user_1 VARCHAR(40) NOT NULL,
  user_2 VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_groups`;
CREATE TABLE `messenger_groups` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  name VARCHAR(160) NOT NULL,
  avatar VARCHAR(255) NOT NULL DEFAULT '',
  about TEXT,
  type VARCHAR(24) NOT NULL DEFAULT 'private',
  project_uid VARCHAR(40) NOT NULL DEFAULT '',
  creator_uid VARCHAR(40) NOT NULL DEFAULT '',
  only_admins_post TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_group_members`;
CREATE TABLE `messenger_group_members` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'member',
  muted_until DATETIME NULL,
  joined_at DATETIME NOT NULL,
  UNIQUE (group_uid,
  user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_messages`;
CREATE TABLE `messenger_messages` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  chat_type VARCHAR(12) NOT NULL DEFAULT 'private',
  conversation_uid VARCHAR(40) NOT NULL DEFAULT '',
  group_uid VARCHAR(40) NOT NULL DEFAULT '',
  sender_uid VARCHAR(40) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'text',
  content LONGTEXT,
  file_path VARCHAR(500) NOT NULL DEFAULT '',
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  file_size DECIMAL(12,2) NOT NULL DEFAULT 0,
  mime VARCHAR(120) NOT NULL DEFAULT '',
  meta JSON,
  reply_to VARCHAR(40) NOT NULL DEFAULT '',
  forward_uid VARCHAR(40) NOT NULL DEFAULT '',
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  edited_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  deleted_for_all TINYINT(1) NOT NULL DEFAULT 0,
  purge_at DATETIME NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_message_reads`;
CREATE TABLE `messenger_message_reads` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  delivered_at DATETIME NOT NULL,
  read_at DATETIME NULL,
  UNIQUE (message_uid,
  user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_message_deletes`;
CREATE TABLE `messenger_message_deletes` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE (message_uid,
  user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_reactions`;
CREATE TABLE `messenger_reactions` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  emoji VARCHAR(16) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE (message_uid,
  user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_chat_state`;
CREATE TABLE `messenger_chat_state` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_key VARCHAR(120) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  last_read_id BIGINT NOT NULL DEFAULT 0,
  last_read_at DATETIME NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  is_muted TINYINT(1) NOT NULL DEFAULT 0,
  draft TEXT,
  updated_at DATETIME NULL,
  UNIQUE (chat_key,
  user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_presence`;
CREATE TABLE `messenger_presence` (
  user_uid VARCHAR(40) NOT NULL,
  last_seen BIGINT NOT NULL DEFAULT 0,
  last_seen_at DATETIME NULL,
  PRIMARY KEY (user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_typing`;
CREATE TABLE `messenger_typing` (
  chat_key VARCHAR(120) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  expires_at BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_key,
  user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messenger_devices`;
CREATE TABLE `messenger_devices` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  device_name VARCHAR(160) NOT NULL DEFAULT '',
  platform VARCHAR(40) NOT NULL DEFAULT '',
  browser VARCHAR(80) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  last_active DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `attendance`;
CREATE TABLE `attendance` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  day DATE NOT NULL,
  check_in TIME NULL,
  check_out TIME NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'present',
  source VARCHAR(16) NOT NULL DEFAULT 'admin',
  note TEXT,
  approved_by VARCHAR(40) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE (uid),
  UNIQUE (user_uid,
  day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `attendance_requests`;
CREATE TABLE `attendance_requests` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  user_uid VARCHAR(40) NOT NULL,
  day DATE NOT NULL,
  check_in TIME NULL,
  check_out TIME NULL,
  reason TEXT,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  reviewed_by VARCHAR(40) NOT NULL DEFAULT '',
  reviewed_at DATETIME NULL,
  review_note VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `attendance_qr`;
CREATE TABLE `attendance_qr` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL,
  generated_by VARCHAR(40) NOT NULL DEFAULT '',
  valid_from DATETIME NOT NULL,
  valid_until DATETIME NOT NULL,
  ip_lock VARCHAR(45) NOT NULL DEFAULT '',
  scans INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `attendance_scans`;
CREATE TABLE `attendance_scans` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  qr_id INT NOT NULL DEFAULT 0,
  user_uid VARCHAR(40) NOT NULL,
  day DATE NOT NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'in',
  scanned_at DATETIME NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `work_holidays`;
CREATE TABLE `work_holidays` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  day DATE NOT NULL,
  title VARCHAR(160) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `automation_rules`;
CREATE TABLE `automation_rules` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  title VARCHAR(200) NOT NULL,
  event VARCHAR(64) NOT NULL,
  conditions JSON,
  actions JSON,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  run_count INT NOT NULL DEFAULT 0,
  last_run DATETIME NULL,
  created_by VARCHAR(40) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  UNIQUE (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `automation_logs`;
CREATE TABLE `automation_logs` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rule_uid VARCHAR(40) NOT NULL DEFAULT '',
  event VARCHAR(64) NOT NULL DEFAULT '',
  message VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'ok',
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `idx_users_role` ON `users` (`role`);
CREATE INDEX `idx_users_client` ON `users` (`client_uid`);
CREATE INDEX `idx_projects_client` ON `projects` (`client_uid`);
CREATE INDEX `idx_projects_manager` ON `projects` (`manager_uid`);
CREATE INDEX `idx_projects_status` ON `projects` (`status`);
CREATE INDEX `idx_pm_project` ON `project_members` (`project_uid`);
CREATE INDEX `idx_pm_user` ON `project_members` (`user_uid`);
CREATE INDEX `idx_tasks_project` ON `project_tasks` (`project_uid`);
CREATE INDEX `idx_tasks_assignee` ON `project_tasks` (`assignee_uid`);
CREATE INDEX `idx_tasks_status` ON `project_tasks` (`status`);
CREATE INDEX `idx_taskc_task` ON `project_task_comments` (`task_uid`);
CREATE INDEX `idx_updates_project` ON `project_updates` (`project_uid`);
CREATE INDEX `idx_notif_user` ON `notifications` (`user_uid`, `is_read`);
CREATE INDEX `idx_blog_slug` ON `blog_posts` (`slug`);
CREATE INDEX `idx_blog_status` ON `blog_posts` (`status`);
CREATE INDEX `idx_media_folder` ON `media_files` (`folder`);
CREATE INDEX `idx_logs_actor` ON `activity_logs` (`actor`);
CREATE INDEX `idx_logs_created` ON `activity_logs` (`created_at`);
CREATE INDEX `idx_conv_u1` ON `messenger_conversations` (`user_1`);
CREATE INDEX `idx_conv_u2` ON `messenger_conversations` (`user_2`);
CREATE INDEX `idx_gm_group` ON `messenger_group_members` (`group_uid`);
CREATE INDEX `idx_gm_user` ON `messenger_group_members` (`user_uid`);
CREATE INDEX `idx_msg_conv` ON `messenger_messages` (`conversation_uid`, `created_at`);
CREATE INDEX `idx_msg_group` ON `messenger_messages` (`group_uid`, `created_at`);
CREATE INDEX `idx_msg_sender` ON `messenger_messages` (`sender_uid`);
CREATE INDEX `idx_msg_purge` ON `messenger_messages` (`purge_at`);
CREATE INDEX `idx_reads_user` ON `messenger_message_reads` (`user_uid`);
CREATE INDEX `idx_react_msg` ON `messenger_reactions` (`message_uid`);
CREATE INDEX `idx_devices_user` ON `messenger_devices` (`user_uid`);
CREATE INDEX `idx_att_user_day` ON `attendance` (`user_uid`, `day`);
CREATE INDEX `idx_att_day` ON `attendance` (`day`);
CREATE INDEX `idx_attreq_user` ON `attendance_requests` (`user_uid`);
CREATE INDEX `idx_attreq_status` ON `attendance_requests` (`status`);
CREATE INDEX `idx_attscan_user` ON `attendance_scans` (`user_uid`, `day`);
CREATE INDEX `idx_auto_event` ON `automation_rules` (`event`);
CREATE INDEX `idx_autolog_rule` ON `automation_logs` (`rule_uid`);

SET FOREIGN_KEY_CHECKS = 1;
