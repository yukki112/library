<?php
// Basic configuration for XAMPP MySQL
// Update these values in your environment if needed.

define('DB_HOST', getenv('LMS_DB_HOST') ?: 'localhost:3307');
define('DB_PORT', getenv('LMS_DB_PORT') ?: '3306');
define('DB_NAME', getenv('LMS_DB_NAME') ?: 'libraryfinal');
define('DB_USER', getenv('LMS_DB_USER') ?: 'root');
define('DB_PASS', getenv('LMS_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// App settings
define('APP_NAME', 'Library Management System');
define('APP_BASE_URL', '/LMS=PHP/public');
// Path to logo served from public. Place your logo at public/assets/logo.png
define('APP_LOGO_URL', 'assets/logo.png');

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_name('LMSPHPSESSID');
?>
