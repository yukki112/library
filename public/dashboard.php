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

$totalBooks = (int)$pdo->query('SELECT COALESCE(SUM(total_copies_cache),0) FROM books')->fetchColumn();
$availableBooks = (int)$pdo->query('SELECT COALESCE(SUM(available_copies_cache),0) FROM books')->fetchColumn();
$totalMembers = quickCount($pdo, 'patrons');

function countByStatus(PDO $pdo, string $table, string $status, ?int $patronId = null): int {
    if ($patronId === null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE status = :status");
        $stmt->execute([':status' => $status]);
        return (int)$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE status = :status AND patron_id = :pid");
    $stmt->execute([':status' => $status, ':pid' => $patronId]);
    return (int)$stmt->fetchColumn();
}

$issuedBooksCount = countByStatus($pdo, 'borrow_logs', 'borrowed');
$totalFines = (float)$pdo->query('SELECT COALESCE(SUM(late_fee),0) FROM borrow_logs')->fetchColumn();
$totalOverdueCount = countByStatus($pdo, 'borrow_logs', 'overdue');
$pendingRequests = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();

$patronId = $isStudent ? (int)($user['patron_id'] ?? 0) : null;
$borrowed = countByStatus($pdo, 'borrow_logs', 'borrowed', $patronId);
$overdue = countByStatus($pdo, 'borrow_logs', 'overdue', $patronId);

if ($patronId === null) {
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

if ($patronId === null) {
    $stmtPend = $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
    $pendingReports = (int)$stmtPend->fetchColumn();
} else {
    $stmtPend = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'pending' AND patron_id = :pid");
    $stmtPend->execute([':pid' => $patronId]);
    $pendingReports = (int)$stmtPend->fetchColumn();
}

if ($patronId === null) {
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

if ($patronId === null) {
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
?>

<div class="dashboard-header">
    <div class="welcome-section">
        <h1>Welcome back, <span class="welcome-name"><?= htmlspecialchars($user['name'] ?? $user['username']) ?></span></h1>
        <p class="welcome-subtitle"><?= date('l, F j, Y') ?></p>
    </div>
    
    <?php if (!$isStudent): ?>
    <div class="stats-banner">
        <div class="stat-item">
            <span class="stat-number"><?= $totalBooks ?></span>
            <span class="stat-label">Total Books</span>
        </div>
        <div class="stat-item">
            <span class="stat-number"><?= $availableBooks ?></span>
            <span class="stat-label">Available</span>
        </div>
        <div class="stat-item">
            <span class="stat-number"><?= $totalMembers ?></span>
            <span class="stat-label">Members</span>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="metric-grid">
    <?php if (!$isStudent): ?>
    <a href="manage_user.php" class="metric-link">
        <div class="metric-card metric-blue">
            <div class="metric-icon-container">
                <i class="fas fa-users metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $totalMembers ?></div>
                <div class="metric-title">Members</div>
            </div>
        </div>
    </a>
    
    <a href="issued_books.php" class="metric-link">
        <div class="metric-card metric-green">
            <div class="metric-icon-container">
                <i class="fas fa-book-reader metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $issuedBooksCount ?></div>
                <div class="metric-title">Borrowed Books</div>
            </div>
        </div>
    </a>
    
    <a href="manage_books.php" class="metric-link">
        <div class="metric-card metric-red">
            <div class="metric-icon-container">
                <i class="fas fa-books metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $totalBooks ?></div>
                <div class="metric-title">Total Books</div>
            </div>
        </div>
    </a>
    
    <a href="issued_books.php?filter=overdue" class="metric-link">
        <div class="metric-card metric-amber">
            <div class="metric-icon-container">
                <i class="fas fa-clock metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $totalOverdueCount ?></div>
                <div class="metric-title">Overdue</div>
            </div>
        </div>
    </a>
    
    <a href="view_requested_books.php?status=approved" class="metric-link">
        <div class="metric-card metric-purple">
            <div class="metric-icon-container">
                <i class="fas fa-calendar-check metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $activeReservations ?></div>
                <div class="metric-title">Active Reservations</div>
            </div>
        </div>
    </a>
    
    <a href="view_requested_books.php?status=pending" class="metric-link">
        <div class="metric-card metric-rose">
            <div class="metric-icon-container">
                <i class="fas fa-hourglass-half metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $pendingRequests ?></div>
                <div class="metric-title">Pending Requests</div>
            </div>
        </div>
    </a>
    
    <?php else: ?>
    <a href="dashboard.php?view=borrowed" class="metric-link">
        <div class="metric-card metric-amber">
            <div class="metric-icon-container">
                <i class="fas fa-book metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $borrowed ?></div>
                <div class="metric-title">Borrowed</div>
            </div>
        </div>
    </a>
    
    <a href="dashboard.php?view=overdue" class="metric-link">
        <div class="metric-card metric-rose">
            <div class="metric-icon-container">
                <i class="fas fa-exclamation-circle metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $overdue ?></div>
                <div class="metric-title">Overdue</div>
            </div>
        </div>
    </a>
    
    <a href="dashboard.php?view=active" class="metric-link">
        <div class="metric-card metric-blue">
            <div class="metric-icon-container">
                <i class="fas fa-calendar-check metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $activeReservations ?></div>
                <div class="metric-title">Active Reservations</div>
            </div>
        </div>
    </a>
    
    <a href="dashboard.php?view=pending" class="metric-link">
        <div class="metric-card metric-purple">
            <div class="metric-icon-container">
                <i class="fas fa-hourglass-start metric-icon"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value"><?= $pendingReports ?></div>
                <div class="metric-title">Pending Reservations</div>
            </div>
        </div>
    </a>
    <?php endif; ?>
</div>

<?php if ($isStudent && isset($_GET['view'])): ?>
    <?php $view = strtolower($_GET['view']); ?>
    <div class="detail-view-section">
        <div class="detail-view-card">
            <?php switch ($view): 
                case 'borrowed': ?>
                    <div class="detail-view-header">
                        <h3><i class="fas fa-book"></i> Borrowed Books</h3>
                        <span class="badge badge-amber"><?= $borrowed ?></span>
                    </div>
                    <?php 
                    $stmtV = $pdo->prepare('SELECT b.title, bl.borrowed_at, bl.due_date, bl.status FROM borrow_logs bl JOIN books b ON bl.book_id = b.id WHERE bl.patron_id = :pid AND bl.status = "borrowed" ORDER BY bl.borrowed_at DESC');
                    $stmtV->execute([':pid' => $patronId]);
                    $rows = $stmtV->fetchAll();
                    if (empty($rows)): ?>
                        <div class="empty-state">
                            <i class="fas fa-book"></i>
                            <h3>No borrowed books</h3>
                            <p>You have no borrowed books at the moment.</p>
                        </div>
                    <?php else: ?>
                        <div class="detail-list">
                            <?php foreach ($rows as $r): ?>
                                <div class="detail-item">
                                    <div class="detail-item-icon">
                                        <i class="fas fa-book-open"></i>
                                    </div>
                                    <div class="detail-item-content">
                                        <h4><?= htmlspecialchars($r['title']) ?></h4>
                                        <div class="detail-item-meta">
                                            <span class="meta-item">
                                                <i class="fas fa-calendar-alt"></i>
                                                Borrowed: <?= htmlspecialchars(date('M d, Y', strtotime($r['borrowed_at']))) ?>
                                            </span>
                                            <?php if (!empty($r['due_date'])): ?>
                                                <span class="meta-item">
                                                    <i class="fas fa-clock"></i>
                                                    Due: <?= htmlspecialchars(date('M d, Y', strtotime($r['due_date']))) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php break; ?>
                
                <?php case 'overdue': ?>
                    <div class="detail-view-header">
                        <h3><i class="fas fa-exclamation-circle"></i> Overdue Books</h3>
                        <span class="badge badge-red"><?= $overdue ?></span>
                    </div>
                    <?php 
                    $stmtV = $pdo->prepare('SELECT b.title, bl.borrowed_at, bl.due_date FROM borrow_logs bl JOIN books b ON bl.book_id = b.id WHERE bl.patron_id = :pid AND bl.status = "overdue" ORDER BY bl.due_date ASC');
                    $stmtV->execute([':pid' => $patronId]);
                    $rows = $stmtV->fetchAll();
                    if (empty($rows)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>No overdue books</h3>
                            <p>Great! You have no overdue books.</p>
                        </div>
                    <?php else: ?>
                        <div class="detail-list">
                            <?php foreach ($rows as $r): ?>
                                <div class="detail-item overdue-item">
                                    <div class="detail-item-icon">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div class="detail-item-content">
                                        <h4><?= htmlspecialchars($r['title']) ?></h4>
                                        <div class="detail-item-meta">
                                            <span class="meta-item">
                                                <i class="fas fa-calendar-alt"></i>
                                                Borrowed: <?= htmlspecialchars(date('M d, Y', strtotime($r['borrowed_at']))) ?>
                                            </span>
                                            <span class="meta-item overdue-badge">
                                                <i class="fas fa-clock"></i>
                                                Due: <?= htmlspecialchars(date('M d, Y', strtotime($r['due_date']))) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php break; ?>
                
                <?php case 'active': ?>
                    <div class="detail-view-header">
                        <h3><i class="fas fa-calendar-check"></i> Active Reservations</h3>
                        <span class="badge badge-blue"><?= $activeReservations ?></span>
                    </div>
                    <?php 
                    $stmtV = $pdo->prepare('SELECT b.title, r.reserved_at FROM reservations r JOIN books b ON r.book_id = b.id WHERE r.patron_id = :pid AND r.status IN ("approved","active") ORDER BY r.reserved_at DESC');
                    $stmtV->execute([':pid' => $patronId]);
                    $rows = $stmtV->fetchAll();
                    if (empty($rows)): ?>
                        <div class="empty-state">
                            <i class="fas fa-info-circle"></i>
                            <h3>No active reservations</h3>
                            <p>No approved reservations found.</p>
                        </div>
                    <?php else: ?>
                        <div class="detail-list">
                            <?php foreach ($rows as $r): ?>
                                <div class="detail-item">
                                    <div class="detail-item-icon">
                                        <i class="fas fa-bookmark"></i>
                                    </div>
                                    <div class="detail-item-content">
                                        <h4><?= htmlspecialchars($r['title']) ?></h4>
                                        <div class="detail-item-meta">
                                            <span class="meta-item">
                                                <i class="fas fa-calendar-check"></i>
                                                Reserved: <?= htmlspecialchars(date('M d, Y', strtotime($r['reserved_at']))) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php break; ?>
                
                <?php case 'pending': ?>
                    <div class="detail-view-header">
                        <h3><i class="fas fa-hourglass-start"></i> Pending Reservations</h3>
                        <span class="badge badge-purple"><?= $pendingReports ?></span>
                    </div>
                    <?php 
                    $stmtV = $pdo->prepare('SELECT b.title, r.reserved_at FROM reservations r JOIN books b ON r.book_id = b.id WHERE r.patron_id = :pid AND r.status = "pending" ORDER BY r.reserved_at DESC');
                    $stmtV->execute([':pid' => $patronId]);
                    $rows = $stmtV->fetchAll();
                    if (empty($rows)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>No pending reservations</h3>
                            <p>Reservation requests awaiting approval appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="detail-list">
                            <?php foreach ($rows as $r): ?>
                                <div class="detail-item">
                                    <div class="detail-item-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="detail-item-content">
                                        <h4><?= htmlspecialchars($r['title']) ?></h4>
                                        <div class="detail-item-meta">
                                            <span class="meta-item">
                                                <i class="fas fa-calendar-alt"></i>
                                                Requested: <?= htmlspecialchars(date('M d, Y', strtotime($r['reserved_at']))) ?>
                                            </span>
                                            <span class="meta-item pending-badge">
                                                <i class="fas fa-hourglass-half"></i>
                                                Awaiting Approval
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php break; ?>
            <?php endswitch; ?>
        </div>
    </div>
<?php endif; ?>

<div class="quick-actions-section">
    <h3>Quick Actions</h3>
    <div class="quick-actions-grid">
        <?php if (isset($user['role']) && in_array($user['role'], ['admin','librarian','assistant'], true)): ?>
        <a class="quick-card qa-green" href="crud.php?resource=books">
            <div class="qa-icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <div class="qa-content">
                <h4>Add Book</h4>
                <p>Add a new book record to the library</p>
            </div>
            <div class="qa-arrow">
                <i class="fas fa-chevron-right"></i>
            </div>
        </a>
        <?php endif; ?>
        
        <?php if (!in_array($user['role'] ?? '', ['student','non_staff'], true)): ?>
        <a class="quick-card qa-pink" href="crud.php?resource=borrow_logs">
            <div class="qa-icon">
                <i class="fas fa-exchange-alt"></i>
            </div>
            <div class="qa-content">
                <h4>Borrow/Return Log</h4>
                <p>Record a new borrowing transaction</p>
            </div>
            <div class="qa-arrow">
                <i class="fas fa-chevron-right"></i>
            </div>
        </a>
        <?php else: ?>
        <a class="quick-card qa-pink" href="my_borrowed_books.php">
            <div class="qa-icon">
                <i class="fas fa-book-open"></i>
            </div>
            <div class="qa-content">
                <h4>My Borrowed Books</h4>
                <p>View & manage your borrowed books</p>
            </div>
            <div class="qa-arrow">
                <i class="fas fa-chevron-right"></i>
            </div>
        </a>
        <?php endif; ?>
        
        <a class="quick-card qa-amber" href="crud.php?resource=lost_damaged_reports">
            <div class="qa-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="qa-content">
                <h4>Lost/Damaged Report</h4>
                <p>Report lost or damaged books</p>
            </div>
            <div class="qa-arrow">
                <i class="fas fa-chevron-right"></i>
            </div>
        </a>
        
        <a class="quick-card qa-blue" href="search.php">
            <div class="qa-icon">
                <i class="fas fa-search"></i>
            </div>
            <div class="qa-content">
                <h4>Search Books</h4>
                <p>Find books in the library catalog</p>
            </div>
            <div class="qa-arrow">
                <i class="fas fa-chevron-right"></i>
            </div>
        </a>
    </div>
</div>

<div class="dashboard-panels">
    <div class="panel-card">
        <div class="panel-header">
            <h3><i class="fas fa-calendar-check"></i> Active Reservations</h3>
            <span class="panel-badge"><?= count($activeReservationsList) ?></span>
        </div>
        <?php if (empty($activeReservationsList)): ?>
            <div class="empty-state">
                <i class="fas fa-info-circle"></i>
                <h3>No active reservations</h3>
                <p>New reservations will appear here</p>
            </div>
        <?php else: ?>
            <div class="panel-list">
                <?php foreach ($activeReservationsList as $r): ?>
                    <?php
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
                    <div class="panel-item">
                        <img src="<?= htmlspecialchars($cover) ?>" alt="Cover" class="panel-cover" />
                        <div class="panel-item-content">
                            <h4><?= htmlspecialchars($r['book']) ?></h4>
                            <div class="panel-item-meta">
                                <?php if (isset($r['patron'])): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-user"></i>
                                        <?= htmlspecialchars($r['patron']) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="meta-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?= htmlspecialchars(date('M d, Y', strtotime($r['reserved_at']))) ?>
                                </span>
                            </div>
                        </div>
                        <div class="panel-item-status status-active">
                            <i class="fas fa-check-circle"></i>
                            Active
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="panel-card">
        <div class="panel-header">
            <h3><i class="fas fa-hourglass-half"></i> Pending Reservations</h3>
            <span class="panel-badge"><?= count($pendingReportsList) ?></span>
        </div>
        <?php if (empty($pendingReportsList)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>No pending reservations</h3>
                <p>Reservation requests awaiting approval</p>
            </div>
        <?php else: ?>
            <div class="panel-list">
                <?php foreach ($pendingReportsList as $r): ?>
                    <?php
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
                    <div class="panel-item">
                        <img src="<?= htmlspecialchars($cover) ?>" alt="Cover" class="panel-cover" />
                        <div class="panel-item-content">
                            <h4><?= htmlspecialchars($r['book']) ?></h4>
                            <div class="panel-item-meta">
                                <?php if (isset($r['patron'])): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-user"></i>
                                        <?= htmlspecialchars($r['patron']) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="meta-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?= htmlspecialchars(date('M d, Y', strtotime($r['reserved_at']))) ?>
                                </span>
                            </div>
                        </div>
                        <div class="panel-item-status status-pending">
                            <i class="fas fa-clock"></i>
                            Pending
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.dashboard-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
}

.welcome-section h1 {
    margin: 0;
    font-size: 2rem;
    font-weight: 600;
}

.welcome-name {
    color: #ffd700;
}

.welcome-subtitle {
    margin: 0.5rem 0 0;
    opacity: 0.9;
    font-size: 1rem;
}

.stats-banner {
    display: flex;
    gap: 3rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid rgba(255,255,255,0.2);
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
}

.stat-label {
    font-size: 0.9rem;
    opacity: 0.8;
}

.metric-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.metric-link {
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s ease;
}

.metric-link:hover {
    transform: translateY(-4px);
}

.metric-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: box-shadow 0.2s ease;
}

.metric-card:hover {
    box-shadow: 0 8px 15px rgba(0,0,0,0.15);
}

.metric-icon-container {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
}

.metric-content {
    flex: 1;
}

.metric-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.metric-title {
    font-size: 0.9rem;
    color: #666;
    font-weight: 500;
}

.metric-blue {
    border-left: 4px solid #3b82f6;
}

.metric-blue .metric-icon-container {
    background: #dbeafe;
    color: #3b82f6;
}

.metric-green {
    border-left: 4px solid #10b981;
}

.metric-green .metric-icon-container {
    background: #d1fae5;
    color: #10b981;
}

.metric-red {
    border-left: 4px solid #ef4444;
}

.metric-red .metric-icon-container {
    background: #fee2e2;
    color: #ef4444;
}

.metric-amber {
    border-left: 4px solid #f59e0b;
}

.metric-amber .metric-icon-container {
    background: #fef3c7;
    color: #f59e0b;
}

.metric-purple {
    border-left: 4px solid #8b5cf6;
}

.metric-purple .metric-icon-container {
    background: #ede9fe;
    color: #8b5cf6;
}

.metric-rose {
    border-left: 4px solid #f43f5e;
}

.metric-rose .metric-icon-container {
    background: #ffe4e6;
    color: #f43f5e;
}

.detail-view-section {
    margin: 2rem 0;
}

.detail-view-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.detail-view-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f3f4f6;
}

.detail-view-header h3 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.badge {
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 600;
}

.badge-amber {
    background: #fef3c7;
    color: #92400e;
}

.badge-red {
    background: #fee2e2;
    color: #991b1b;
}

.badge-blue {
    background: #dbeafe;
    color: #1e40af;
}

.badge-purple {
    background: #ede9fe;
    color: #5b21b6;
}

.detail-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 8px;
    border-left: 4px solid #3b82f6;
}

.detail-item.overdue-item {
    border-left-color: #ef4444;
    background: #fef2f2;
}

.detail-item-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: #e0e7ff;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #3b82f6;
}

.overdue-item .detail-item-icon {
    background: #fee2e2;
    color: #ef4444;
}

.detail-item-content {
    flex: 1;
}

.detail-item-content h4 {
    margin: 0 0 0.5rem;
    font-size: 1rem;
}

.detail-item-meta {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.875rem;
    color: #6b7280;
}

.overdue-badge {
    background: #fee2e2;
    color: #991b1b;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-weight: 500;
}

.pending-badge {
    background: #fef3c7;
    color: #92400e;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-weight: 500;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #6b7280;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state h3 {
    margin: 0 0 0.5rem;
    color: #374151;
}

.quick-actions-section {
    margin: 2rem 0;
}

.quick-actions-section h3 {
    margin-bottom: 1rem;
    font-size: 1.25rem;
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
}

.quick-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    text-decoration: none;
    color: inherit;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
    border: 2px solid transparent;
}

.quick-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.15);
    border-color: #e0e7ff;
}

.qa-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.qa-content {
    flex: 1;
}

.qa-content h4 {
    margin: 0 0 0.25rem;
    font-size: 1.1rem;
}

.qa-content p {
    margin: 0;
    font-size: 0.875rem;
    color: #6b7280;
}

.qa-arrow {
    color: #9ca3af;
    transition: transform 0.2s ease;
}

.quick-card:hover .qa-arrow {
    transform: translateX(4px);
    color: #3b82f6;
}

.qa-green {
    border-left: 4px solid #10b981;
}

.qa-green .qa-icon {
    background: #d1fae5;
    color: #10b981;
}

.qa-pink {
    border-left: 4px solid #ec4899;
}

.qa-pink .qa-icon {
    background: #fce7f3;
    color: #ec4899;
}

.qa-amber {
    border-left: 4px solid #f59e0b;
}

.qa-amber .qa-icon {
    background: #fef3c7;
    color: #f59e0b;
}

.qa-blue {
    border-left: 4px solid #3b82f6;
}

.qa-blue .qa-icon {
    background: #dbeafe;
    color: #3b82f6;
}

.dashboard-panels {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
    margin-top: 2rem;
}

.panel-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f3f4f6;
}

.panel-header h3 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.panel-badge {
    background: #e0e7ff;
    color: #3730a3;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 600;
}

.panel-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.panel-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.panel-cover {
    width: 40px;
    height: 55px;
    object-fit: cover;
    border-radius: 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.panel-item-content {
    flex: 1;
}

.panel-item-content h4 {
    margin: 0 0 0.25rem;
    font-size: 0.95rem;
}

.panel-item-meta {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.panel-item-status {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-active {
    background: #d1fae5;
    color: #065f46;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

@media (max-width: 768px) {
    .dashboard-panels {
        grid-template-columns: 1fr;
    }
    
    .quick-actions-grid {
        grid-template-columns: 1fr;
    }
    
    .metric-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .stats-banner {
        flex-direction: column;
        gap: 1.5rem;
    }
}
</style>

<?php include __DIR__ . '/_footer.php'; ?>