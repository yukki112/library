<?php
// Return Due Dates & Fines page
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

<h2>Return Due Dates &amp; Fines</h2>
<p>This module will help track due dates for borrowed materials and calculate any associated fines. Functionality coming soon.</p>

<?php include '_footer.php'; ?>