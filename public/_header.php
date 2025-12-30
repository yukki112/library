<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
start_app_session();
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="styles/main.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div style="display:flex;align-items:center;gap:10px;">
                    <!-- Dashboard logo using the custom icon. The container has no gradient and simply shows the logo file -->
                    <div style="width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                        <img src="<?= htmlspecialchars(APP_LOGO_URL) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;" onerror="this.style.display='none'" />
                    </div>
                    <div>
                        <div style="font-weight:800;color:#1e293b;line-height:1;">LMS</div>
                        <div style="color:#6b7280;font-size:12px;">Library Management</div>
                    </div>
                </div>
            </div>
            <?php if ($user): ?>
            <ul class="sidebar-menu">
                <?php
                $role = $user['role'];
                $items = [
                    ['Dashboard','dashboard.php','fa-gauge',['admin','librarian','assistant','student','non_staff']],
                    // Replaced Patrons with Top Users.  This module shows users with highest borrow counts
                    ['Top Users','top_users.php','fa-users',['admin','librarian','assistant']],
                    // Profile module now contains sub-menus for profile details and password change
                    ['Profile','profile_details.php','fa-user',['admin','librarian','assistant','student','non_staff']],
                    // Additional modules for extended functionality
                    ['Student Information','student_information.php','fa-user-graduate',['admin','librarian','assistant']],
                    ['Manage Books','manage_books.php','fa-book',['admin','librarian','assistant']],
                    // Issue Books module removed per requirements
                    // ['Issue Books','issue_books.php','fa-book-open',['admin','librarian','assistant']],
                    ['E‑Books','ebooks.php','fa-book-open-reader',['admin','librarian','assistant','student','non_staff']],
                    ['Analytics','reports_analytics.php','fa-chart-line',['admin','librarian']],
                    ['Manage User','manage_user.php','fa-users',['admin','librarian','assistant']],
                    // Students and non‑teaching staff modules
                    // Rename "My Issued Books" to "My Borrowed Books" and link to the new
                    // page.  Students and non‑staff should see their borrowed
                    // books history here.
                    ['My Borrowed Books','my_borrowed_books.php','fa-user-clock',['student','non_staff']],
                    // Provide a simple catalogue view for students to browse books
                    ['Books','books.php','fa-book',['student','non_staff']],
                    // Allow students to request a book reservation
                    ['Request Book','request_book.php','fa-book-medical',['student','non_staff']],
                    // Staff modules
                    // Rename the "Issued Books" module to "Borrowed Books" for staff.  The
                    // underlying functionality remains the same but the label now
                    // reflects the terminology used throughout the application.
                    ['Borrowed Books','issued_books.php','fa-file-lines',['admin','librarian','assistant']],
                    // All staff can view requested books; replaces Clearances
                    ['View Requested Books','view_requested_books.php','fa-eye',['admin','librarian','assistant']],
                    // Staff can manage e‑book access requests.  This module lists
                    // all pending and processed e‑book access requests from
                    // students and non‑teaching staff.
                    ['E‑Book Requests','ebook_requests.php','fa-book-open',['admin','librarian','assistant']],
                    ['Send Message To User','send_message.php','fa-envelope',['admin','librarian','assistant']],
                    // Students and non‑staff can message an administrator directly via
                    // this new module.  It appears only for these roles.
                    ['Message Admin','send_message_admin.php','fa-comment',['student','non_staff']],
                    ['Audit Logs','audit.php','fa-shield-halved',['admin','librarian','assistant']],
                ];
                // Determine the current request URI (including query) for accurate active highlighting.
                $curr = $_SERVER['REQUEST_URI'] ?? '';
                foreach ($items as [$label,$href,$icon,$roles]) {
                    if (!in_array($role, $roles, true)) continue;
                    // Mark active only if the full href appears in the current URI
                    $active = (strpos($curr, $href) !== false) ? 'active' : '';
                    echo '<li><a class="menu-item '.$active.'" href="'.htmlspecialchars($href).'"><i class="fa '.$icon.'"></i><span>'.htmlspecialchars($label).'</span></a></li>';
                }
                ?>
            </ul>
            <div class="sidebar-footer">
                <div class="user-card">
                    <div class="user-avatar"><i class="fa fa-user"></i></div>
                    <div class="user-meta">
                        <div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
                        <div class="user-role"><?= htmlspecialchars($user['role']) ?></div>
                    </div>
                    <button id="logoutBtn" class="user-logout" title="Logout"><i class="fa fa-right-from-bracket"></i></button>
                </div>
            </div>
            <?php endif; ?>
        </aside>
        <main class="main-content">
            <div class="module-header">
                <div class="page-line">
                    <h1 class="page-heading">Overview</h1>
                </div>
                <div class="header-right">
                    <!-- Notification bell.  When clicked it links to the notifications page.  The badge
                         displays the count of unread notifications and updates periodically via JavaScript. -->
                    <div class="notification-bell" style="position:relative; margin-right:16px;">
                        <a href="notifications.php" style="color:#374151; font-size:18px; position:relative; display:inline-block;">
                            <i class="fa fa-bell"></i>
                            <span id="notifBadge" style="position:absolute; top:-6px; right:-8px; background:#ef4444; color:#fff; font-size:10px; padding:2px 4px; border-radius:9999px; display:none;">0</span>
                        </a>
                    </div>
                    <!-- Global search now redirects to the book search page -->
                    <div class="global-search">
                        <i class="fa fa-search"></i>
                        <input
                            placeholder="Search..."
                            onkeydown="
                                if (event.key === 'Enter') {
                                    const raw = this.value.trim();
                                    if (!raw) return;
                                    const q = raw.toLowerCase();
                                    let target = '';
                                    // Route common module keywords directly to the appropriate page.  This
                                    // mapping allows users to navigate modules such as Profile, Request
                                    // Book, E‑Books and Books without typing the full URL.  Any unknown
                                    // search term will fall back to the online book search.
                                    if (q === 'profile' || q === 'profile details') {
                                        target = 'profile_details.php';
                                    } else if (q === 'request book' || q === 'book request' || q === 'request') {
                                        target = 'request_book.php';
                                    } else if (q === 'books' || q === 'book' || q === 'books catalogue' || q === 'catalogue') {
                                        target = 'books.php';
                                    } else if (q === 'e books' || q === 'ebooks' || q === 'e-books' || q === 'e book') {
                                        target = 'ebooks.php';
                                    } else if (q === 'dashboard' || q === 'home') {
                                        target = 'dashboard.php';
                                    } else if (q === 'my issued books' || q === 'issued books' || q === 'my books' || q === 'my borrowed books' || q === 'borrowed books') {
                                        target = 'my_borrowed_books.php';
                                    } else if (q === 'message admin' || q === 'contact admin' || q === 'send message to admin') {
                                        target = 'send_message_admin.php';
                                    } else {
                                        target = 'online_search.php?q=' + encodeURIComponent(raw);
                                    }
                                    window.location.href = target;
                                }
                            "
                        />
                    </div>
                    <div class="current-period"><i class="fa fa-calendar"></i> <?= date('F Y') ?></div>
                </div>
            </div>
            <!-- Initialize CSRF token for API calls -->
            <script>
              (async function(){
                try {
                  const r = await fetch('../api/auth.php');
                  const d = await r.json();
                  if (d && d.csrf) sessionStorage.setItem('csrf', d.csrf);
                } catch(e) {}
              })();
            </script>
            <!-- Poll for unread notifications every 30 seconds and update the badge. -->
            <script>
              async function refreshNotifications(){
                try {
                  const res = await fetch('../api/notifications.php?all=1');
                  const list = await res.json();
                  if (!Array.isArray(list)) return;
                  // Count unread notifications for the current user (is_read == 0)
                  const unread = list.filter(n => !n.is_read).length;
                  const badge = document.getElementById('notifBadge');
                  if (badge){
                    if (unread > 0){
                      badge.textContent = unread;
                      badge.style.display = 'inline-block';
                    } else {
                      badge.style.display = 'none';
                    }
                  }
                } catch(e){ /* ignore */ }
              }
              // Initial fetch and periodic refresh
              refreshNotifications();
              setInterval(refreshNotifications, 30000);
            </script>
