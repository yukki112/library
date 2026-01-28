<?php
// Reports & Analytics module - Enhanced Version
// This page provides librarians and administrators with comprehensive insights into
// library operations. It visualizes monthly borrowing and return statistics, 
// illustrates book category distribution, highlights the most frequently borrowed 
// titles, lists overdue books, and provides key performance indicators (KPIs).

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
$user = current_user();

// Only staff roles (admin, librarian, assistant) can access analytics
if (!in_array($user['role'], ['admin','librarian','assistant'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = DB::conn();

// ------------------------------------------------------------------
// 1. KEY PERFORMANCE INDICATORS (KPIs)
// ------------------------------------------------------------------
$kpis = [];

// Total Books
$stmt = $pdo->query('SELECT COUNT(*) as count FROM books WHERE is_active = 1');
$kpis['total_books'] = (int)$stmt->fetchColumn();

// Total Available Copies
$stmt = $pdo->query('SELECT COUNT(*) as count FROM book_copies WHERE status = "available" AND is_active = 1');
$kpis['available_copies'] = (int)$stmt->fetchColumn();

// Total Patrons
$stmt = $pdo->query('SELECT COUNT(*) as count FROM patrons WHERE status = "active"');
$kpis['active_patrons'] = (int)$stmt->fetchColumn();

// Currently Borrowed Books
$stmt = $pdo->query('SELECT COUNT(*) as count FROM borrow_logs WHERE status IN ("borrowed", "overdue")');
$kpis['active_borrows'] = (int)$stmt->fetchColumn();

// Overdue Books
$stmt = $pdo->query('SELECT COUNT(*) as count FROM borrow_logs WHERE status = "overdue"');
$kpis['overdue_books'] = (int)$stmt->fetchColumn();

// Total Fines Collected (this month)
$currentMonth = date('Y-m');
$stmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) as total FROM receipts WHERE DATE_FORMAT(payment_date, "%Y-%m") = :month AND status = "paid"');
$stmt->execute([':month' => $currentMonth]);
$kpis['monthly_fines'] = (float)$stmt->fetchColumn();

// ------------------------------------------------------------------
// 2. MONTHLY STATISTICS
// ------------------------------------------------------------------
$currentYear = (int)date('Y');

// Fetch monthly borrowings
$stmtBor = $pdo->prepare(
    'SELECT MONTH(borrowed_at) AS month, COUNT(*) AS count
     FROM borrow_logs
     WHERE YEAR(borrowed_at) = :yr
     GROUP BY month'
);
$stmtBor->execute([':yr' => $currentYear]);
$borRows = $stmtBor->fetchAll();
$borMap = [];
foreach ($borRows as $r) {
    $borMap[(int)$r['month']] = (int)$r['count'];
}

// Fetch monthly returns
$stmtRet = $pdo->prepare(
    'SELECT MONTH(returned_at) AS month, COUNT(*) AS count
     FROM borrow_logs
     WHERE returned_at IS NOT NULL AND YEAR(returned_at) = :yr
     GROUP BY month'
);
$stmtRet->execute([':yr' => $currentYear]);
$retRows = $stmtRet->fetchAll();
$retMap = [];
foreach ($retRows as $r) {
    $retMap[(int)$r['month']] = (int)$r['count'];
}

// Fetch monthly reservations
$stmtRes = $pdo->prepare(
    'SELECT MONTH(reserved_at) AS month, COUNT(*) AS count
     FROM reservations
     WHERE YEAR(reserved_at) = :yr AND status IN ("approved", "fulfilled")
     GROUP BY month'
);
$stmtRes->execute([':yr' => $currentYear]);
$resRows = $stmtRes->fetchAll();
$resMap = [];
foreach ($resRows as $r) {
    $resMap[(int)$r['month']] = (int)$r['count'];
}

// Prepare data for charts
$monthLabels = [];
$borrowCounts = [];
$returnCounts = [];
$reservationCounts = [];
for ($m = 1; $m <= 12; $m++) {
    $monthLabels[] = date('M', mktime(0, 0, 0, $m, 1));
    $borrowCounts[] = $borMap[$m] ?? 0;
    $returnCounts[] = $retMap[$m] ?? 0;
    $reservationCounts[] = $resMap[$m] ?? 0;
}

// ------------------------------------------------------------------
// 3. BOOK CATEGORIES DISTRIBUTION
// ------------------------------------------------------------------
$stmtCat = $pdo->query('SELECT category, COUNT(*) AS count FROM books WHERE is_active = 1 GROUP BY category ORDER BY count DESC');
$catLabels = [];
$catCounts = [];
$catColors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4', '#F97316'];
$colorIndex = 0;
foreach ($stmtCat->fetchAll() as $row) {
    $catLabels[] = $row['category'] ?: 'Uncategorised';
    $catCounts[] = (int)$row['count'];
    $catColors[] = $catColors[$colorIndex % count($catColors)];
    $colorIndex++;
}

// ------------------------------------------------------------------
// 4. MOST POPULAR BOOKS (Top 10)
// ------------------------------------------------------------------
$stmtPop = $pdo->query(
    'SELECT b.title, b.author, COUNT(*) AS times_borrowed
     FROM borrow_logs bl
     JOIN books b ON bl.book_id = b.id
     GROUP BY bl.book_id, b.title, b.author
     ORDER BY times_borrowed DESC, b.title ASC
     LIMIT 10'
);
$popularBooks = $stmtPop->fetchAll();

// ------------------------------------------------------------------
// 5. OVERDUE BOOKS
// ------------------------------------------------------------------
$stmtOv = $pdo->query(
    'SELECT bl.id, bl.book_id, bl.patron_id, bl.due_date, bl.late_fee, bl.borrowed_at,
            b.title AS book_title, p.name AS patron_name, p.library_id
     FROM borrow_logs bl
     JOIN books b ON bl.book_id = b.id
     JOIN patrons p ON bl.patron_id = p.id
     WHERE bl.status = "overdue"
     ORDER BY bl.due_date ASC'
);
$overdueRows = [];
$now = new DateTime();
foreach ($stmtOv->fetchAll() as $row) {
    $due = new DateTime($row['due_date']);
    $borrowed = new DateTime($row['borrowed_at']);
    $interval = $due->diff($now);
    $daysOver = (int)$interval->format('%r%a');
    if ($daysOver < 0) $daysOver = 0;
    
    $totalDays = $borrowed->diff($due)->days;
    $overduePercentage = $totalDays > 0 ? min(100, ($daysOver / $totalDays) * 100) : 100;
    
    $overdueRows[] = [
        'title' => $row['book_title'],
        'borrower' => $row['patron_name'],
        'library_id' => $row['library_id'],
        'due_date' => date('M d, Y', strtotime($row['due_date'])),
        'days_overdue' => $daysOver,
        'overdue_percentage' => $overduePercentage,
        'fine' => number_format((float)$row['late_fee'], 2)
    ];
}

// ------------------------------------------------------------------
// 6. TOP PATRONS (Most Active Borrowers)
// ------------------------------------------------------------------
$stmtPat = $pdo->query(
    'SELECT p.name, p.library_id, COUNT(*) AS total_borrows,
            SUM(CASE WHEN bl.status = "overdue" THEN 1 ELSE 0 END) as overdue_count
     FROM borrow_logs bl
     JOIN patrons p ON bl.patron_id = p.id
     WHERE p.status = "active"
     GROUP BY p.id, p.name, p.library_id
     ORDER BY total_borrows DESC
     LIMIT 8'
);
$topPatrons = $stmtPat->fetchAll();

// ------------------------------------------------------------------
// 7. BOOK CONDITION STATISTICS
// ------------------------------------------------------------------
$stmtCond = $pdo->query(
    'SELECT book_condition, COUNT(*) as count 
     FROM book_copies 
     WHERE is_active = 1 
     GROUP BY book_condition 
     ORDER BY count DESC'
);
$conditionData = $stmtCond->fetchAll();

include __DIR__ . '/_header.php';
?>

<style>
/* Enhanced Analytics Styles */
:root {
    --primary-blue: #3b82f6;
    --success-green: #10b981;
    --warning-orange: #f59e0b;
    --danger-red: #ef4444;
    --info-teal: #06b6d4;
    --purple: #8b5cf6;
    --pink: #ec4899;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
}

.analytics-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.analytics-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid var(--gray-200);
}

.analytics-header h1 {
    font-size: 28px;
    font-weight: 700;
    color: var(--gray-800);
    margin: 0;
}

.analytics-header h1 i {
    color: var(--primary-blue);
    margin-right: 12px;
}

.export-buttons {
    display: flex;
    gap: 12px;
}

.export-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    background: white;
    color: var(--gray-700);
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.export-btn:hover {
    background: var(--gray-50);
    border-color: var(--gray-400);
    transform: translateY(-1px);
}

.export-btn i {
    font-size: 16px;
}

/* KPI Cards Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.kpi-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-left: 4px solid;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.kpi-card.blue { border-color: var(--primary-blue); }
.kpi-card.green { border-color: var(--success-green); }
.kpi-card.orange { border-color: var(--warning-orange); }
.kpi-card.red { border-color: var(--danger-red); }
.kpi-card.purple { border-color: var(--purple); }
.kpi-card.pink { border-color: var(--pink); }

.kpi-icon {
    font-size: 32px;
    margin-bottom: 16px;
    opacity: 0.9;
}

.kpi-card.blue .kpi-icon { color: var(--primary-blue); }
.kpi-card.green .kpi-icon { color: var(--success-green); }
.kpi-card.orange .kpi-icon { color: var(--warning-orange); }
.kpi-card.red .kpi-icon { color: var(--danger-red); }
.kpi-card.purple .kpi-icon { color: var(--purple); }
.kpi-card.pink .kpi-icon { color: var(--pink); }

.kpi-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--gray-800);
    margin-bottom: 8px;
}

.kpi-label {
    font-size: 14px;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.kpi-change {
    font-size: 14px;
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.kpi-change.positive { color: var(--success-green); }
.kpi-change.negative { color: var(--danger-red); }

/* Main Analytics Grid */
.analytics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 24px;
    margin-top: 30px;
}

.analytics-panel {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    height: 100%;
    display: flex;
    flex-direction: column;
}

.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    background: linear-gradient(135deg, var(--primary-blue), #2563eb);
    color: white;
}

.panel-header.green { background: linear-gradient(135deg, var(--success-green), #059669); }
.panel-header.orange { background: linear-gradient(135deg, var(--warning-orange), #d97706); }
.panel-header.red { background: linear-gradient(135deg, var(--danger-red), #dc2626); }
.panel-header.purple { background: linear-gradient(135deg, var(--purple), #7c3aed); }
.panel-header.teal { background: linear-gradient(135deg, var(--info-teal), #0891b2); }

.panel-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 18px;
    font-weight: 600;
}

.panel-title i {
    font-size: 20px;
}

.panel-subtitle {
    font-size: 14px;
    opacity: 0.9;
}

.panel-body {
    padding: 24px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

/* Chart Containers */
.chart-container {
    position: relative;
    height: 300px;
    width: 100%;
}

/* Tables */
.analytics-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.analytics-table th {
    text-align: left;
    padding: 12px 16px;
    background: var(--gray-50);
    color: var(--gray-700);
    font-weight: 600;
    border-bottom: 2px solid var(--gray-200);
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.05em;
}

.analytics-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--gray-100);
    color: var(--gray-700);
}

.analytics-table tr:hover {
    background: var(--gray-50);
}

.analytics-table .text-right { text-align: right; }
.analytics-table .text-center { text-align: center; }

/* Overdue Progress Bars */
.overdue-progress {
    height: 6px;
    background: var(--gray-200);
    border-radius: 3px;
    overflow: hidden;
    margin-top: 4px;
}

.overdue-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--danger-red), #f87171);
    border-radius: 3px;
    transition: width 0.3s ease;
}

/* Badges */
.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.badge-success {
    background: rgba(16, 185, 129, 0.1);
    color: var(--success-green);
    border: 1px solid rgba(16, 185, 129, 0.2);
}

.badge-warning {
    background: rgba(245, 158, 11, 0.1);
    color: var(--warning-orange);
    border: 1px solid rgba(245, 158, 11, 0.2);
}

.badge-danger {
    background: rgba(239, 68, 68, 0.1);
    color: var(--danger-red);
    border: 1px solid rgba(239, 68, 68, 0.2);
}

/* Empty States */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 24px;
    color: var(--gray-400);
    text-align: center;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

/* Time Filter */
.time-filter {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    padding: 16px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.filter-btn {
    padding: 8px 16px;
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    background: white;
    color: var(--gray-700);
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.filter-btn:hover {
    border-color: var(--primary-blue);
    color: var(--primary-blue);
}

.filter-btn.active {
    background: var(--primary-blue);
    border-color: var(--primary-blue);
    color: white;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .analytics-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .analytics-header {
        flex-direction: column;
        gap: 16px;
        text-align: center;
    }
    
    .export-buttons {
        width: 100%;
        justify-content: center;
    }
    
    .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .kpi-grid {
        grid-template-columns: 1fr;
    }
    
    .analytics-table {
        display: block;
        overflow-x: auto;
    }
}
</style>

<div class="analytics-container">
    <!-- Header -->
    <div class="analytics-header">
        <h1><i class="fas fa-chart-bar"></i> Library Analytics Dashboard</h1>
        <div class="export-buttons">
            <button class="export-btn" onclick="window.print()">
                <i class="fas fa-print"></i> Print Report
            </button>
            <button class="export-btn" onclick="exportExcel()">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
            <button class="export-btn" onclick="exportPDF()">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
        </div>
    </div>

    <!-- Time Filter -->
    <div class="time-filter">
        <button class="filter-btn active">This Month</button>
        <button class="filter-btn">Last 3 Months</button>
        <button class="filter-btn">This Year</button>
        <button class="filter-btn">All Time</button>
        <select class="filter-btn" style="margin-left: auto; padding: 8px 16px;">
            <option>Custom Range</option>
            <option>Last 7 Days</option>
            <option>Last 30 Days</option>
            <option>Last Quarter</option>
        </select>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card blue">
            <div class="kpi-icon"><i class="fas fa-book"></i></div>
            <div class="kpi-value"><?= number_format($kpis['total_books']) ?></div>
            <div class="kpi-label">Total Books</div>
            <div class="kpi-change positive">
                <i class="fas fa-arrow-up"></i>
                <span>12% from last month</span>
            </div>
        </div>

        <div class="kpi-card green">
            <div class="kpi-icon"><i class="fas fa-book-open"></i></div>
            <div class="kpi-value"><?= number_format($kpis['available_copies']) ?></div>
            <div class="kpi-label">Available Copies</div>
            <div class="kpi-change positive">
                <i class="fas fa-arrow-up"></i>
                <span>8% available rate</span>
            </div>
        </div>

        <div class="kpi-card orange">
            <div class="kpi-icon"><i class="fas fa-users"></i></div>
            <div class="kpi-value"><?= number_format($kpis['active_patrons']) ?></div>
            <div class="kpi-label">Active Patrons</div>
            <div class="kpi-change positive">
                <i class="fas fa-arrow-up"></i>
                <span>5 new this month</span>
            </div>
        </div>

        <div class="kpi-card purple">
            <div class="kpi-icon"><i class="fas fa-exchange-alt"></i></div>
            <div class="kpi-value"><?= number_format($kpis['active_borrows']) ?></div>
            <div class="kpi-label">Active Borrows</div>
            <div class="kpi-change positive">
                <i class="fas fa-arrow-up"></i>
                <span>15% from last month</span>
            </div>
        </div>

        <div class="kpi-card red">
            <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="kpi-value"><?= number_format($kpis['overdue_books']) ?></div>
            <div class="kpi-label">Overdue Books</div>
            <div class="kpi-change <?= $kpis['overdue_books'] > 0 ? 'negative' : 'positive' ?>">
                <i class="fas fa-arrow-<?= $kpis['overdue_books'] > 0 ? 'up' : 'down' ?>"></i>
                <span><?= $kpis['overdue_books'] > 0 ? 'Needs attention' : 'All clear' ?></span>
            </div>
        </div>

        <div class="kpi-card pink">
            <div class="kpi-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="kpi-value">₱<?= number_format($kpis['monthly_fines'], 2) ?></div>
            <div class="kpi-label">Monthly Fines</div>
            <div class="kpi-change positive">
                <i class="fas fa-arrow-up"></i>
                <span>Revenue collected</span>
            </div>
        </div>
    </div>

    <!-- Main Analytics Grid -->
    <div class="analytics-grid">
        <!-- Monthly Statistics -->
        <div class="analytics-panel">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fas fa-chart-line"></i>
                    <span>Monthly Activity (<?= $currentYear ?>)</span>
                </div>
                <div class="panel-subtitle">Borrowings, Returns & Reservations</div>
            </div>
            <div class="panel-body">
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Book Categories -->
        <div class="analytics-panel">
            <div class="panel-header green">
                <div class="panel-title">
                    <i class="fas fa-book"></i>
                    <span>Book Categories</span>
                </div>
                <div class="panel-subtitle">Distribution by Category</div>
            </div>
            <div class="panel-body">
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Most Popular Books -->
        <div class="analytics-panel">
            <div class="panel-header teal">
                <div class="panel-title">
                    <i class="fas fa-star"></i>
                    <span>Most Popular Books</span>
                </div>
                <div class="panel-subtitle">Top 10 by Borrow Count</div>
            </div>
            <div class="panel-body">
                <?php if (empty($popularBooks)): ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <p>No borrowing records available</p>
                    </div>
                <?php else: ?>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Book Title</th>
                                <th>Author</th>
                                <th class="text-right">Borrows</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($popularBooks as $index => $row): ?>
                                <tr>
                                    <td class="text-center">
                                        <span class="badge <?= $index < 3 ? 'badge-success' : 'badge-warning' ?>">
                                            #<?= $index + 1 ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($row['title']) ?></td>
                                    <td><?= htmlspecialchars($row['author']) ?></td>
                                    <td class="text-right">
                                        <strong><?= (int)$row['times_borrowed'] ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Overdue Books -->
        <div class="analytics-panel">
            <div class="panel-header red">
                <div class="panel-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Overdue Books</span>
                </div>
                <div class="panel-subtitle">Requires Immediate Attention</div>
            </div>
            <div class="panel-body">
                <?php if (empty($overdueRows)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No overdue books. Excellent!</p>
                    </div>
                <?php else: ?>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Book Title</th>
                                <th>Borrower</th>
                                <th>Due Date</th>
                                <th class="text-right">Days Overdue</th>
                                <th class="text-right">Fine</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdueRows as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['title']) ?></strong>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($row['borrower']) ?></div>
                                        <small class="text-muted">ID: <?= htmlspecialchars($row['library_id']) ?></small>
                                    </td>
                                    <td>
                                        <?= $row['due_date'] ?>
                                        <div class="overdue-progress">
                                            <div class="overdue-progress-bar" style="width: <?= min(100, $row['overdue_percentage']) ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <span class="badge badge-danger">
                                            <?= (int)$row['days_overdue'] ?> days
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <strong>₱<?= htmlspecialchars($row['fine']) ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Patrons -->
        <div class="analytics-panel">
            <div class="panel-header purple">
                <div class="panel-title">
                    <i class="fas fa-user-friends"></i>
                    <span>Top Patrons</span>
                </div>
                <div class="panel-subtitle">Most Active Borrowers</div>
            </div>
            <div class="panel-body">
                <?php if (empty($topPatrons)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>No patron data available</p>
                    </div>
                <?php else: ?>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Patron Name</th>
                                <th class="text-right">Total Borrows</th>
                                <th class="text-right">Overdue</th>
                                <th class="text-right">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topPatrons as $index => $patron): ?>
                                <tr>
                                    <td class="text-center">
                                        <span class="badge <?= $index < 3 ? 'badge-success' : 'badge-warning' ?>">
                                            #<?= $index + 1 ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($patron['name']) ?></div>
                                        <small class="text-muted">ID: <?= htmlspecialchars($patron['library_id']) ?></small>
                                    </td>
                                    <td class="text-right">
                                        <strong><?= (int)$patron['total_borrows'] ?></strong>
                                    </td>
                                    <td class="text-right">
                                        <span class="badge <?= $patron['overdue_count'] > 0 ? 'badge-danger' : 'badge-success' ?>">
                                            <?= (int)$patron['overdue_count'] ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <?php 
                                            $rate = $patron['total_borrows'] > 0 
                                                ? round(($patron['overdue_count'] / $patron['total_borrows']) * 100, 1)
                                                : 0;
                                        ?>
                                        <?= $rate ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Book Condition Statistics -->
        <div class="analytics-panel">
            <div class="panel-header orange">
                <div class="panel-title">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Book Condition</span>
                </div>
                <div class="panel-subtitle">Physical State of Copies</div>
            </div>
            <div class="panel-body">
                <?php if (empty($conditionData)): ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <p>No condition data available</p>
                    </div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="conditionChart"></canvas>
                    </div>
                    <div style="margin-top: 20px;">
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Condition</th>
                                    <th class="text-right">Count</th>
                                    <th class="text-right">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $totalCopies = array_sum(array_column($conditionData, 'count'));
                                foreach ($conditionData as $condition): 
                                    $percentage = $totalCopies > 0 
                                        ? round(($condition['count'] / $totalCopies) * 100, 1)
                                        : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <?php 
                                            $conditionClass = [
                                                'new' => 'badge-success',
                                                'good' => 'badge-success',
                                                'fair' => 'badge-warning',
                                                'poor' => 'badge-warning',
                                                'damaged' => 'badge-danger',
                                                'lost' => 'badge-danger'
                                            ];
                                            ?>
                                            <span class="badge <?= $conditionClass[$condition['book_condition']] ?? 'badge-warning' ?>">
                                                <?= ucfirst($condition['book_condition']) ?>
                                            </span>
                                        </td>
                                        <td class="text-right"><?= (int)$condition['count'] ?></td>
                                        <td class="text-right"><?= $percentage ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>

<script>
// Prepare data from PHP
const monthLabels = <?= json_encode($monthLabels) ?>;
const borrowCounts = <?= json_encode($borrowCounts) ?>;
const returnCounts = <?= json_encode($returnCounts) ?>;
const reservationCounts = <?= json_encode($reservationCounts) ?>;
const categoryLabels = <?= json_encode($catLabels) ?>;
const categoryCounts = <?= json_encode($catCounts) ?>;
const categoryColors = <?= json_encode($catColors) ?>;

// Condition data
const conditionData = <?= json_encode($conditionData) ?>;

// Monthly Statistics Chart
const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
const monthlyChart = new Chart(monthlyCtx, {
    type: 'line',
    data: {
        labels: monthLabels,
        datasets: [
            {
                label: 'Borrowings',
                data: borrowCounts,
                borderColor: '#3B82F6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#3B82F6',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
            },
            {
                label: 'Returns',
                data: returnCounts,
                borderColor: '#10B981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#10B981',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
            },
            {
                label: 'Reservations',
                data: reservationCounts,
                borderColor: '#8B5CF6',
                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#8B5CF6',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    padding: 20,
                    usePointStyle: true,
                    pointStyle: 'circle'
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                backgroundColor: 'rgba(0, 0, 0, 0.7)',
                titleFont: { size: 14 },
                bodyFont: { size: 14 },
                padding: 12
            }
        },
        scales: {
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    color: '#6b7280'
                }
            },
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                },
                ticks: {
                    color: '#6b7280',
                    precision: 0
                }
            }
        },
        interaction: {
            intersect: false,
            mode: 'index'
        }
    }
});

// Category Distribution Chart
const categoryCtx = document.getElementById('categoryChart').getContext('2d');
const categoryChart = new Chart(categoryCtx, {
    type: 'doughnut',
    data: {
        labels: categoryLabels,
        datasets: [{
            data: categoryCounts,
            backgroundColor: categoryColors,
            borderWidth: 2,
            borderColor: '#ffffff',
            hoverOffset: 20
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    padding: 20,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    font: {
                        size: 12
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.raw || 0;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = Math.round((value / total) * 100);
                        return `${label}: ${value} (${percentage}%)`;
                    }
                }
            }
        }
    }
});

// Condition Chart (if data exists)
if (conditionData.length > 0) {
    const conditionCtx = document.getElementById('conditionChart').getContext('2d');
    
    // Prepare condition data
    const conditionLabels = conditionData.map(item => {
        const condition = item.book_condition;
        return condition.charAt(0).toUpperCase() + condition.slice(1);
    });
    const conditionCounts = conditionData.map(item => item.count);
    
    // Condition-specific colors
    const conditionColors = conditionData.map(item => {
        const condition = item.book_condition;
        switch(condition) {
            case 'new': return '#10B981';
            case 'good': return '#3B82F6';
            case 'fair': return '#F59E0B';
            case 'poor': return '#EF4444';
            case 'damaged': return '#DC2626';
            case 'lost': return '#7C3AED';
            default: return '#6B7280';
        }
    });
    
    new Chart(conditionCtx, {
        type: 'bar',
        data: {
            labels: conditionLabels,
            datasets: [{
                label: 'Number of Copies',
                data: conditionCounts,
                backgroundColor: conditionColors,
                borderColor: conditionColors.map(color => color + 'CC'),
                borderWidth: 1,
                borderRadius: 6,
                barPercentage: 0.6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `Copies: ${context.raw}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#6b7280'
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        color: '#6b7280',
                        precision: 0
                    }
                }
            }
        }
    });
}

// Export Functions
function exportExcel() {
    // Create Excel data
    const data = [
        ['Library Analytics Report', 'Generated on: ' + new Date().toLocaleDateString()],
        [],
        ['Key Performance Indicators'],
        ['Total Books', '<?= $kpis['total_books'] ?>'],
        ['Available Copies', '<?= $kpis['available_copies'] ?>'],
        ['Active Patrons', '<?= $kpis['active_patrons'] ?>'],
        ['Active Borrows', '<?= $kpis['active_borrows'] ?>'],
        ['Overdue Books', '<?= $kpis['overdue_books'] ?>'],
        ['Monthly Fines', '₱<?= number_format($kpis['monthly_fines'], 2) ?>'],
        [],
        ['Monthly Statistics (<?= $currentYear ?>)'],
        ['Month', 'Borrowings', 'Returns', 'Reservations']
    ];
    
    // Add monthly data
    monthLabels.forEach((month, index) => {
        data.push([month, borrowCounts[index], returnCounts[index], reservationCounts[index]]);
    });
    
    data.push([], ['Book Categories'], ['Category', 'Count']);
    
    // Add category data
    categoryLabels.forEach((category, index) => {
        data.push([category, categoryCounts[index]]);
    });
    
    // Convert to CSV
    const csvContent = data.map(row => row.join(',')).join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `library_analytics_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportPDF() {
    alert('PDF export would require a server-side library like TCPDF or mPDF. This is a client-side placeholder.');
    // In a real implementation, this would make an AJAX call to a server-side PDF generator
}

// Time filter functionality
document.querySelectorAll('.filter-btn').forEach(button => {
    if (!button.tagName === 'SELECT') {
        button.addEventListener('click', function() {
            document.querySelectorAll('.filter-btn').forEach(btn => {
                if (btn.tagName !== 'SELECT') {
                    btn.classList.remove('active');
                }
            });
            this.classList.add('active');
            
            // Here you would typically make an AJAX call to update the data
            // based on the selected time filter
        });
    }
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>