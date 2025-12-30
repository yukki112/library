<?php
// Send Message To User landing page. Provides sub-menus for sending messages to different user types.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
// Determine the current user's role and immediately delegate to the
// appropriate messaging interface.  Students and non‑staff users are
// directed to the admin chat, whereas staff roles (admin, librarian,
// assistant) are sent to the student messaging UI.  This file no
// longer presents a menu; it acts as a router for message pages.
$user = current_user();
$role = $user['role'] ?? '';
if (in_array($role, ['student','non_staff'], true)) {
    header('Location: send_message_admin.php');
    exit;
}
if (in_array($role, ['admin','librarian','assistant'], true)) {
    header('Location: send_to_student.php');
    exit;
}
// For other roles, include a simple informational page.
include __DIR__ . '/_header.php';
?>

<h2 style="text-align:center;">Send Message</h2>
<p style="text-align:center;">Messaging is not available for your role.</p>

<?php include __DIR__ . '/_footer.php'; ?>