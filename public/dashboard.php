    <?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$pdo = DB::conn();
$user = current_user();
$isStudent = in_array($user['role'] ?? '', ['student','non_staff'], true);

function quickCount(PDO $pdo, string $table): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}

// Count overall book inventory and availability.  These counts are shown on
// staff dashboards to give librarians and administrators a quick view of
// their collection.  Students do not see system‑wide numbers.  See the
// conditional rendering below when generating the metric cards.
$totalBooks = (int)$pdo->query('SELECT COALESCE(SUM(total_copies),0) FROM books')->fetchColumn();
$availableBooks = (int)$pdo->query('SELECT COALESCE(SUM(available_copies),0) FROM books')->fetchColumn();

// Additional metrics used by administrative dashboards.  The number of
// registered patrons reflects total library members.  Issued books counts
// how many borrow logs are currently marked as borrowed (i.e. not yet
// returned).  The total fines represent the sum of late fees collected so
// far across all borrow logs.  Pending reservation requests give staff
// insight into outstanding book requests awaiting approval.
$totalMembers    = quickCount($pdo, 'patrons');
$issuedBooksCount = countByStatus($pdo, 'borrow_logs', 'borrowed');
// Sum of late fees on all borrow logs; default to 0 if none exist
$totalFines      = (float)$pdo->query('SELECT COALESCE(SUM(late_fee),0) FROM borrow_logs')->fetchColumn();

// Count of all overdue borrow logs.  This metric replaces the total fines on
// the administrative dashboard.  It shows how many active borrow logs are
// currently overdue across all patrons.  Students see only their own
// overdue count via the $overdue variable computed below.
$totalOverdueCount = countByStatus($pdo, 'borrow_logs', 'overdue');
$pendingRequests = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();

$patronId = $isStudent ? (int)($user['patron_id'] ?? 0) : null;

// Helper to fetch a single count with optional patron filter
function countByStatus(PDO $pdo, string $table, string $status, ?int $patronId = null): int {
    if ($patronId === null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE status = :status");
        $stmt->execute([':status' => $status]);
        return (int)$stmt->fetchColumn();
    }
    // All tables here have a patron_id column when the caller passes a non‑null patronId.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE status = :status AND patron_id = :pid");
    $stmt->execute([':status' => $status, ':pid' => $patronId]);
    return (int)$stmt->fetchColumn();
}

// Borrowed books currently checked out
$borrowed = countByStatus($pdo, 'borrow_logs', 'borrowed', $patronId);
// Overdue borrow logs
$overdue = countByStatus($pdo, 'borrow_logs', 'overdue', $patronId);

// Active reservations are those that have been approved or remain marked
// as `active` but have not yet been fulfilled and have no corresponding
// borrow record marked as returned.  To avoid counting stale
// reservations that were already processed (for example, a borrow_log
// exists and the book was returned), exclude any reservation for which
// a borrow_log exists with a returned status.  This ensures that the
// dashboard accurately reflects only outstanding reservations awaiting
// pickup or processing.
if ($patronId === null) {
    // Count all approved/active reservations without a matching returned
    // borrow log for the same book and patron.  The NOT EXISTS clause
    // filters out reservations that have already resulted in a returned
    // loan.
    $stmtActive = $pdo->query(
        "SELECT COUNT(*) FROM reservations r
         WHERE r.status IN ('approved','active')
           AND NOT EXISTS (SELECT 1 FROM borrow_logs bl
                           WHERE bl.book_id = r.book_id
                             AND bl.patron_id = r.patron_id
                             AND bl.status = 'returned')"
    );
    $activeReservations = (int)$stmtActive->fetchColumn();
} else {
    // Count active reservations for the logged‑in patron only.  Apply the
    // same NOT EXISTS filter to avoid counting reservations tied to
    // completed borrow logs.
    $stmtActive = $pdo->prepare(
        "SELECT COUNT(*) FROM reservations r
         WHERE r.status IN ('approved','active')
           AND r.patron_id = :pid
           AND NOT EXISTS (SELECT 1 FROM borrow_logs bl
                           WHERE bl.book_id = r.book_id
                             AND bl.patron_id = r.patron_id
                             AND bl.status = 'returned')"
    );
    $stmtActive->execute([':pid' => $patronId]);
    $activeReservations = (int)$stmtActive->fetchColumn();
}

// Pending reservations awaiting staff approval.  Lost/damaged reports are
// managed separately (e.g. via the Reports page) and are not included here.
if ($patronId === null) {
    $stmtPend = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
    $pendingReports = (int)$stmtPend->fetchColumn();
} else {
    $stmtPend = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'pending' AND patron_id = :pid");
    $stmtPend->execute([':pid' => $patronId]);
    $pendingReports = (int)$stmtPend->fetchColumn();
}

// Small lists for bottom panels
// Active reservations list: show up to eight of the newest approved (or
// legacy active) reservations.  Staff see the patron name; students only
// see their own reservations and therefore do not include patron names in
// the listing.
if ($patronId === null) {
    // Include the book category so that a representative cover image can be displayed in the dashboard lists.
    // Exclude reservations that already have a returned borrow log to avoid
    // showing stale records.  See the logic above for counting active
    // reservations.  Staff see the patron name in this list.
    $activeReservationsList = $pdo->query(
        "SELECT r.id, p.name AS patron, b.title AS book, b.category AS category, r.reserved_at " .
        "FROM reservations r " .
        "JOIN patrons p ON r.patron_id = p.id " .
        "JOIN books b ON r.book_id = b.id " .
        "WHERE r.status IN ('approved','active') " .
        "  AND NOT EXISTS (SELECT 1 FROM borrow_logs bl " .
        "                  WHERE bl.book_id = r.book_id " .
        "                    AND bl.patron_id = r.patron_id " .
        "                    AND bl.status = 'returned') " .
        "ORDER BY r.reserved_at DESC LIMIT 8"
    )->fetchAll();
} else {
    $stmtAR = $pdo->prepare(
        "SELECT r.id, b.title AS book, b.category AS category, r.reserved_at " .
        "FROM reservations r " .
        "JOIN books b ON r.book_id = b.id " .
        "WHERE r.status IN ('approved','active') AND r.patron_id = :pid " .
        "  AND NOT EXISTS (SELECT 1 FROM borrow_logs bl " .
        "                  WHERE bl.book_id = r.book_id " .
        "                    AND bl.patron_id = r.patron_id " .
        "                    AND bl.status = 'returned') " .
        "ORDER BY r.reserved_at DESC LIMIT 8"
    );
    $stmtAR->execute([':pid' => $patronId]);
    $activeReservationsList = $stmtAR->fetchAll();
}

// Pending reservations list: newest pending reservation requests.  Staff see
// patron names; students see only their own pending reservations without
// patron names.
if ($patronId === null) {
    // Also select the book category for pending reservations to enable cover images in the list.
    $pendingReportsList = $pdo->query(
        "SELECT r.id, p.name AS patron, b.title AS book, b.category AS category, r.reserved_at " .
        "FROM reservations r " .
        "JOIN patrons p ON r.patron_id = p.id " .
        "JOIN books b ON r.book_id = b.id " .
        "WHERE r.status = 'pending' " .
        "ORDER BY r.reserved_at DESC LIMIT 8"
    )->fetchAll();
} else {
    $stmtPR = $pdo->prepare(
        "SELECT r.id, b.title AS book, b.category AS category, r.reserved_at " .
        "FROM reservations r " .
        "JOIN books b ON r.book_id = b.id " .
        "WHERE r.status = 'pending' AND r.patron_id = :pid " .
        "ORDER BY r.reserved_at DESC LIMIT 8"
    );
    $stmtPR->execute([':pid' => $patronId]);
    $pendingReportsList = $stmtPR->fetchAll();
}

include __DIR__ . '/_header.php';

// Determine if the current user is a student or non‑staff. Students and non‑staff should not see
// system‑wide metrics such as total books or available copies on the dashboard.
// `$isStudent` is computed at the top of this file based on the logged in
// user's role.
?>

<h2 style="margin-top:0;">Welcome back, <?= htmlspecialchars($user['name'] ?? $user['username']) ?></h2>

<div class="metric-grid" style="margin-top:12px;">
    <?php if (!$isStudent): ?>
    <!-- Administrative dashboard: show high‑level statistics and outstanding requests. -->
    <!-- Wrap administrative metric cards in links so that clicking a card navigates
         to a detailed view.  Members links to the Manage User page, Issued Books
         links to the issued_books page, Total Books links to manage_books, Overdue
         links to issued_books with overdue filter, Active Reservations links to
         view_requested_books, and Pending Requests links to the same page for
         approval.  These anchors have no additional styling so the cards retain
         their original appearance. -->
    <a href="manage_user.php" style="text-decoration:none; color:inherit;">
      <div class="metric-card metric-blue">
          <div class="metric-head">
              <div class="metric-icon icon-ab1" aria-hidden="true"></div>
              <div class="metric-title">Members</div>
          </div>
          <div class="metric-value"><?= $totalMembers ?></div>
      </div>
    </a>
    <a href="issued_books.php" style="text-decoration:none; color:inherit;">
      <!-- Rename the Issued Books metric to Borrowed Books for clarity.  The underlying
           data still counts borrow_logs with status "borrowed" but the UI now
           reflects the common terminology used elsewhere in the system. -->
      <div class="metric-card metric-green">
          <div class="metric-head">
              <div class="metric-icon icon-bb" aria-hidden="true"></div>
              <div class="metric-title">Borrowed Books</div>
          </div>
          <div class="metric-value"><?= $issuedBooksCount ?></div>
      </div>
    </a>
    <a href="manage_books.php" style="text-decoration:none; color:inherit;">
      <div class="metric-card metric-red">
          <div class="metric-head">
              <div class="metric-icon icon-tb" aria-hidden="true"></div>
              <div class="metric-title">Total Books</div>
          </div>
          <div class="metric-value"><?= $totalBooks ?></div>
      </div>
    </a>
    <a href="issued_books.php?filter=overdue" style="text-decoration:none; color:inherit;">
      <div class="metric-card metric-amber">
          <div class="metric-head">
              <div class="metric-icon icon-ob" aria-hidden="true"></div>
              <div class="metric-title">Overdue</div>
          </div>
          <div class="metric-value">
              <?= $totalOverdueCount ?>
          </div>
      </div>
    </a>
    <a href="view_requested_books.php?status=approved" style="text-decoration:none; color:inherit;">
      <div class="metric-card metric-amber">
          <div class="metric-head">
              <div class="metric-icon icon-ab1" aria-hidden="true"></div>
              <div class="metric-title">Active Reservations</div>
          </div>
          <div class="metric-value"><?= $activeReservations ?></div>
      </div>
    </a>
    <a href="view_requested_books.php?status=pending" style="text-decoration:none; color:inherit;">
      <div class="metric-card metric-rose">
          <div class="metric-head">
              <div class="metric-icon icon-rld" aria-hidden="true"></div>
              <div class="metric-title">Pending Requests</div>
          </div>
          <div class="metric-value"><?= $pendingRequests ?></div>
      </div>
    </a>
    <?php else: ?>
    <!-- Student and non‑staff dashboard: show personal stats only.  Each card is
         clickable and navigates to a detailed view of the associated
         records via the `view` query parameter. -->
    <a href="dashboard.php?view=borrowed" style="text-decoration:none; color:inherit;">
      <div class="metric-card metric-amber">
          <div class="metric-head">
              <div class="metric-icon icon-bb" aria-hidden="true"></div>
              <div class="metric-title">Borrowed</div>
          </div>
          <div class="metric-value"><?= $borrowed ?></div>
      </div>
    </a>
    <a href="dashboard.php?view=overdue" style="text-decoration:none; color:inherit;">
      <div class="metric-card metric-rose">
          <div class="metric-head">
              <div class="metric-icon icon-ob" aria-hidden="true"></div>
              <div class="metric-title">Overdue</div>
          </div>
          <div class="metric-value"><?= $overdue ?></div>
      </div>
    </a>
    <a href="dashboard.php?view=active" style="text-decoration:none; color:inherit;">
      <div class="metric-card metric-blue">
          <div class="metric-head">
              <div class="metric-icon icon-ab1" aria-hidden="true"></div>
              <div class="metric-title">Active Reservations</div>
          </div>
          <div class="metric-value"><?= $activeReservations ?></div>
      </div>
    </a>
    <a href="dashboard.php?view=pending" style="text-decoration:none; color:inherit;">
      <div class="metric-card metric-rose">
          <div class="metric-head">
              <div class="metric-icon icon-rld" aria-hidden="true"></div>
              <div class="metric-title">Pending Reservations</div>
          </div>
          <div class="metric-value"><?= $pendingReports ?></div>
      </div>
    </a>
    <?php endif; ?>
</div>

<?php
// -----------------------------------------------------------------------------
// Detailed views for student metrics.  When a student clicks on a metric
// card the `view` query parameter is set (borrowed, overdue, active, pending).
// This block renders a panel listing the corresponding records.  Staff and
// administrators do not see this section.  The lists include the book title
// and relevant dates.  Empty states display a friendly message when there
// are no records.
if ($isStudent && isset($_GET['view'])):
    $view = strtolower($_GET['view']);
    echo '<div style="margin-top:24px;">';
    echo '<div class="panel-card">';
    switch ($view) {
        case 'borrowed':
            echo '<h3>Borrowed Books</h3>';
            $stmtV = $pdo->prepare('SELECT b.title, bl.borrowed_at, bl.due_date, bl.status FROM borrow_logs bl JOIN books b ON bl.book_id = b.id WHERE bl.patron_id = :pid AND bl.status = "borrowed" ORDER BY bl.borrowed_at DESC');
            $stmtV->execute([':pid' => $patronId]);
            $rows = $stmtV->fetchAll();
            if (empty($rows)) {
                echo '<div class="empty-state"><i class="fa fa-book"></i><h3>No borrowed books</h3><p>You have no borrowed books.</p></div>';
            } else {
                echo '<ul class="panel-list">';
                foreach ($rows as $r) {
                    echo '<li style="margin-bottom:8px;">';
                    echo '<div class="pl-title">' . htmlspecialchars($r['title']) . '</div>';
                    echo '<div class="pl-sub">' . htmlspecialchars(date('M d, Y', strtotime($r['borrowed_at'])));
                    if (!empty($r['due_date'])) {
                        echo ' • Due ' . htmlspecialchars(date('M d, Y', strtotime($r['due_date'])));
                    }
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            break;
        case 'overdue':
            echo '<h3>Overdue Books</h3>';
            $stmtV = $pdo->prepare('SELECT b.title, bl.borrowed_at, bl.due_date FROM borrow_logs bl JOIN books b ON bl.book_id = b.id WHERE bl.patron_id = :pid AND bl.status = "overdue" ORDER BY bl.due_date ASC');
            $stmtV->execute([':pid' => $patronId]);
            $rows = $stmtV->fetchAll();
            if (empty($rows)) {
                echo '<div class="empty-state"><i class="fa fa-check-circle"></i><h3>No overdue books</h3><p>You have no overdue books.</p></div>';
            } else {
                echo '<ul class="panel-list">';
                foreach ($rows as $r) {
                    echo '<li style="margin-bottom:8px;">';
                    echo '<div class="pl-title">' . htmlspecialchars($r['title']) . '</div>';
                    echo '<div class="pl-sub">Borrowed ' . htmlspecialchars(date('M d, Y', strtotime($r['borrowed_at']))) . ' • Due ' . htmlspecialchars(date('M d, Y', strtotime($r['due_date']))) . '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            break;
        case 'active':
            echo '<h3>Active Reservations</h3>';
            $stmtV = $pdo->prepare('SELECT b.title, r.reserved_at FROM reservations r JOIN books b ON r.book_id = b.id WHERE r.patron_id = :pid AND r.status IN ("approved","active") ORDER BY r.reserved_at DESC');
            $stmtV->execute([':pid' => $patronId]);
            $rows = $stmtV->fetchAll();
            if (empty($rows)) {
                echo '<div class="empty-state"><i class="fa fa-info-circle"></i><h3>No active reservations</h3><p>No approved reservations found.</p></div>';
            } else {
                echo '<ul class="panel-list">';
                foreach ($rows as $r) {
                    echo '<li style="margin-bottom:8px;">';
                    echo '<div class="pl-title">' . htmlspecialchars($r['title']) . '</div>';
                    echo '<div class="pl-sub">Reserved ' . htmlspecialchars(date('M d, Y', strtotime($r['reserved_at']))) . '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            break;
        case 'pending':
            echo '<h3>Pending Reservations</h3>';
            $stmtV = $pdo->prepare('SELECT b.title, r.reserved_at FROM reservations r JOIN books b ON r.book_id = b.id WHERE r.patron_id = :pid AND r.status = "pending" ORDER BY r.reserved_at DESC');
            $stmtV->execute([':pid' => $patronId]);
            $rows = $stmtV->fetchAll();
            if (empty($rows)) {
                echo '<div class="empty-state"><i class="fa fa-check-circle"></i><h3>No pending reservations</h3><p>Reservation requests awaiting approval appear here.</p></div>';
            } else {
                echo '<ul class="panel-list">';
                foreach ($rows as $r) {
                    echo '<li style="margin-bottom:8px;">';
                    echo '<div class="pl-title">' . htmlspecialchars($r['title']) . '</div>';
                    echo '<div class="pl-sub">Requested ' . htmlspecialchars(date('M d, Y', strtotime($r['reserved_at']))) . '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            break;
        default:
            // Unknown view parameter; do nothing
            break;
    }
    echo '</div>';
    echo '</div>';
endif;
?>

<div style="margin-top:24px;">
    <h3 style="margin-bottom:8px;">Quick Actions</h3>
    <div class="quick-actions">
        <?php if (isset($user['role']) && in_array($user['role'], ['admin','librarian','assistant'], true)): ?>
        <a class="quick-card qa-green" href="crud.php?resource=books">
            <div class="qa-icon icon-ab2" aria-hidden="true"></div>
            <div class="qa-text">
                <span class="qa-title">Add Book</span>
                <p>Add a new book record</p>
            </div>
        </a>
        <?php endif; ?>
        <!-- Borrow/Return log is available to staff via CRUD.  Students
             should instead access their own borrow history via My
             Borrowed Books. -->
        <?php if (!in_array($user['role'] ?? '', ['student','non_staff'], true)): ?>
        <a class="quick-card qa-pink" href="crud.php?resource=borrow_logs">
            <div class="qa-icon icon-abl" aria-hidden="true"></div>
            <div class="qa-text">
                <span class="qa-title">Borrow/Return Log</span>
                <p>Record a new borrowing</p>
            </div>
        </a>
        <?php else: ?>
        <a class="quick-card qa-pink" href="my_borrowed_books.php">
            <div class="qa-icon icon-abl" aria-hidden="true"></div>
            <div class="qa-text">
                <span class="qa-title">My Borrowed Books</span>
                <p>View & manage your borrows</p>
            </div>
        </a>
        <?php endif; ?>
        <a class="quick-card qa-amber" href="crud.php?resource=lost_damaged_reports">
            <div class="qa-icon icon-rld" aria-hidden="true"></div>
            <div class="qa-text">
                <span class="qa-title">Lost/Damaged Report</span>
                <p>Log lost or damaged book</p>
            </div>
        </a>
        <!-- The "Manage Clearance" quick action has been removed per user request. -->
    </div>
</div>

<div class="dashboard-panels">
    <div class="panel-card">
        <h3>Active Reservations</h3>
        <?php if (empty($activeReservationsList)): ?>
            <div class="empty-state"><i class="fa fa-info-circle"></i><h3>No active reservations</h3><p>New reservations will appear here.</p></div>
        <?php else: ?>
            <ul class="panel-list">
                <?php foreach ($activeReservationsList as $r): ?>
                    <?php
                    // Determine a representative cover image based on the book category.  If the
                    // category is unknown or does not have a predefined cover, fall back to
                    // using the application logo.  Categories correspond to the curated covers
                    // in the assets/book_covers folder.
                    $cat = strtolower($r['category'] ?? '');
                    switch ($cat) {
                        case 'history':
                            $cover = 'assets/book_covers/history.png';
                            break;
                        case 'physical education':
                        case 'physical education ':
                        case 'physical education/pe':
                        case 'pe':
                            $cover = 'assets/book_covers/pe.png';
                            break;
                        case 'physics':
                            $cover = 'assets/book_covers/physics.png';
                            break;
                        case 'mathematics':
                            $cover = 'assets/book_covers/math.png';
                            break;
                        case 'programming':
                            $cover = 'assets/book_covers/programming.png';
                            break;
                        case 'dictionary':
                        case 'reference':
                            $cover = 'assets/book_covers/dictionary.png';
                            break;
                        default:
                            // Use the configured application logo as a fallback placeholder.
                            $cover = APP_LOGO_URL;
                            break;
                    }
                    ?>
                    <li style="display:flex; align-items:center; gap:8px;">
                        <img src="<?= htmlspecialchars($cover) ?>" alt="Cover" style="width:30px; height:40px; object-fit:cover; border-radius:4px;" />
                        <div>
                            <div class="pl-title"><?= htmlspecialchars($r['book']) ?></div>
                            <div class="pl-sub">
                                <?php if (isset($r['patron'])): ?>
                                    by <?= htmlspecialchars($r['patron']) ?> •
                                <?php endif; ?>
                                <?= htmlspecialchars(date('M d, Y', strtotime($r['reserved_at']))) ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="panel-card">
        <h3>Pending Reservations</h3>
        <?php if (empty($pendingReportsList)): ?>
            <div class="empty-state"><i class="fa fa-check-circle"></i><h3>No pending reservations</h3><p>Reservation requests awaiting approval appear here.</p></div>
        <?php else: ?>
            <ul class="panel-list">
                <?php foreach ($pendingReportsList as $r): ?>
                    <?php
                    // Map the category to a cover image for pending reservations.  Use the same
                    // logic as active reservations for consistency.
                    $cat = strtolower($r['category'] ?? '');
                    switch ($cat) {
                        case 'history':
                            $cover = 'assets/book_covers/history.png';
                            break;
                        case 'physical education':
                        case 'physical education ':
                        case 'physical education/pe':
                        case 'pe':
                            $cover = 'assets/book_covers/pe.png';
                            break;
                        case 'physics':
                            $cover = 'assets/book_covers/physics.png';
                            break;
                        case 'mathematics':
                            $cover = 'assets/book_covers/math.png';
                            break;
                        case 'programming':
                            $cover = 'assets/book_covers/programming.png';
                            break;
                        case 'dictionary':
                        case 'reference':
                            $cover = 'assets/book_covers/dictionary.png';
                            break;
                        default:
                            $cover = APP_LOGO_URL;
                            break;
                    }
                    ?>
                    <li style="display:flex; align-items:center; gap:8px;">
                        <img src="<?= htmlspecialchars($cover) ?>" alt="Cover" style="width:30px; height:40px; object-fit:cover; border-radius:4px;" />
                        <div>
                            <div class="pl-title"><?= htmlspecialchars($r['book']) ?></div>
                            <div class="pl-sub">
                                <?php if (isset($r['patron'])): ?>
                                    <?= htmlspecialchars($r['patron']) ?> •
                                <?php endif; ?>
                                <?= htmlspecialchars(date('M d, Y', strtotime($r['reserved_at']))) ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
