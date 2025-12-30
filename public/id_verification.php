<?php
// Library ID Verification page
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
start_app_session();
$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}
include '_header.php';
?>

<h2>Library ID Verification</h2>
<p>This module will provide tools to verify library identification cards. Feature under development.</p>

<?php include '_footer.php'; ?>