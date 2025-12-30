<?php
// Reports & Analytics module
// This page provides librarians and administrators with insight into
// library operations.  It visualizes monthly borrowing and return
// statistics, illustrates book category distribution, highlights the
// most frequently borrowed titles and lists overdue books with their
// accrued fines and days overdue.  Students and non‑teaching staff may
// be permitted to view this page but do not see fine totals or other
// confidential information.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
$user = current_user();

// Only staff roles (admin, librarian, assistant) can access analytics.  If a
// student or non‑staff user attempts to access this page they are
// redirected to their dashboard.  Remove this block to allow all
// authenticated users to view reports.
if (!in_array($user['role'], ['admin','librarian','assistant'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = DB::conn();

// ------------------------------------------------------------------
// Monthly statistics: compute the number of borrow logs (borrowings)
// and the number of returned items for each month of the current
// calendar year.  We query MySQL for counts grouped by month and
// populate zero counts for months with no activity.

// Determine the current year.  Use the server's date to avoid time
// zone mismatches with MySQL.
$currentYear = (int)date('Y');

// Fetch counts of borrowings per month
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
// Fetch counts of returns per month (using returned_at).  Note that
// returned_at may be NULL if not yet returned.
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
// Prepare ordered arrays for the twelve months of the year.  Use
// abbreviated month names for labels.
$monthLabels = [];
$borrowCounts = [];
$returnCounts = [];
for ($m = 1; $m <= 12; $m++) {
    $monthLabels[] = date('M', mktime(0, 0, 0, $m, 1));
    $borrowCounts[] = $borMap[$m] ?? 0;
    $returnCounts[] = $retMap[$m] ?? 0;
}

// ------------------------------------------------------------------
// Book categories distribution: count active books in each category.
$stmtCat = $pdo->query('SELECT category, COUNT(*) AS count FROM books GROUP BY category ORDER BY category');
$catLabels = [];
$catCounts = [];
foreach ($stmtCat->fetchAll() as $row) {
    $catLabels[] = $row['category'] ?: 'Uncategorised';
    $catCounts[] = (int)$row['count'];
}

// ------------------------------------------------------------------
// Most popular books: top five titles by total borrow count.  We use
// borrow_logs grouped by book_id and join to books to retrieve the
// title.  Only returned or currently borrowed logs are considered.
$stmtPop = $pdo->query(
    'SELECT b.title, COUNT(*) AS times_borrowed
     FROM borrow_logs bl
     JOIN books b ON bl.book_id = b.id
     GROUP BY bl.book_id, b.title
     ORDER BY times_borrowed DESC, b.title ASC
     LIMIT 5'
);
$popularBooks = $stmtPop->fetchAll();

// ------------------------------------------------------------------
// Overdue books: fetch all borrow logs currently marked as overdue.  We
// join to books and patrons to display the book title and borrower name.
// Compute the number of days overdue and include the late fee.  If
// returned_at is NULL, the book is still checked out; otherwise the
// record should not have status overdue.
$stmtOv = $pdo->query(
    'SELECT bl.id, bl.book_id, bl.patron_id, bl.due_date, bl.late_fee, b.title AS book_title, p.name AS patron_name
     FROM borrow_logs bl
     JOIN books b ON bl.book_id = b.id
     JOIN patrons p ON bl.patron_id = p.id
     WHERE bl.status = "overdue"'
);
$overdueRows = [];
$now = new DateTime();
foreach ($stmtOv->fetchAll() as $row) {
    $due = new DateTime($row['due_date']);
    // Compute days overdue: difference in days, minimum of 0 if somehow negative
    $interval = $due->diff($now);
    $daysOver = (int)$interval->format('%r%a');
    if ($daysOver < 0) $daysOver = 0;
    $overdueRows[] = [
        'title' => $row['book_title'],
        'borrower' => $row['patron_name'],
        'days_overdue' => $daysOver,
        'fine' => number_format((float)$row['late_fee'], 2)
    ];
}

include __DIR__ . '/_header.php';
?>

<style>
/* Basic styling to approximate the provided design.  Colours mirror the
   original UI: blue for statistics, green for categories, teal for
   popular books and red for overdue books. */
.analytics-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 16px;
}
.analytics-panel {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px;
    position: relative;
    min-height: 300px;
}
.panel-header {
    display: flex;
    align-items: center;
    margin-bottom: 12px;
    font-weight: 600;
    color: #fff;
    padding: 4px 8px;
    border-radius: 6px 6px 0 0;
}
.panel-body {
    padding: 8px;
}
.panel-blue { background-color: #3b82f6; }
.panel-green { background-color: #10b981; }
.panel-teal { background-color: #06b6d4; }
.panel-red { background-color: #ef4444; }
</style>

<div style="display:flex; justify-content:space-between; align-items:center;">
  <h2>Reports &amp; Analytics</h2>
  <div>
    <button onclick="window.print();" style="margin-right:8px; padding:6px 12px; border:1px solid #d1d5db; border-radius:6px; background:#f3f4f6; cursor:pointer;">
      <i class="fa fa-print"></i> Print Report
    </button>
    <button onclick="exportExcel();" style="padding:6px 12px; border:1px solid #d1d5db; border-radius:6px; background:#f3f4f6; cursor:pointer;">
      <i class="fa fa-file-excel"></i> Export to Excel
    </button>
  </div>
</div>

<div class="analytics-grid">
  <!-- Monthly statistics panel -->
  <div class="analytics-panel">
    <div class="panel-header panel-blue">
      <i class="fa fa-chart-line" style="margin-right:8px;"></i>
      <span>Monthly Statistics (<?= $currentYear ?>)</span>
    </div>
    <div class="panel-body" style="height:240px;">
      <canvas id="monthlyChart" style="max-height:220px;"></canvas>
    </div>
  </div>
  <!-- Book categories panel -->
  <div class="analytics-panel">
    <div class="panel-header panel-green">
      <i class="fa fa-book" style="margin-right:8px;"></i>
      <span>Book Categories</span>
    </div>
    <div class="panel-body" style="height:240px;">
      <canvas id="categoryChart" style="max-height:220px;"></canvas>
    </div>
  </div>
  <!-- Most popular books panel -->
  <div class="analytics-panel">
    <div class="panel-header panel-teal">
      <i class="fa fa-star" style="margin-right:8px;"></i>
      <span>Most Popular Books</span>
    </div>
    <div class="panel-body">
      <?php if (empty($popularBooks)): ?>
        <p>No borrowing records available.</p>
      <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr>
              <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Book Title</th>
              <th style="text-align:right; padding:6px; border-bottom:1px solid #e5e7eb;">Times Borrowed</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($popularBooks as $row): ?>
            <tr>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($row['title']) ?></td>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6; text-align:right;"><?= (int)$row['times_borrowed'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <!-- Overdue books panel -->
  <div class="analytics-panel">
    <div class="panel-header panel-red">
      <i class="fa fa-exclamation-triangle" style="margin-right:8px;"></i>
      <span>Overdue Books</span>
    </div>
    <div class="panel-body" style="max-height:240px; overflow:auto;">
      <?php if (empty($overdueRows)): ?>
        <p>No overdue books.</p>
      <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr>
              <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Book Title</th>
              <th style="text-align:left; padding:6px; border-bottom:1px solid #e5e7eb;">Borrower</th>
              <th style="text-align:right; padding:6px; border-bottom:1px solid #e5e7eb;">Days Overdue</th>
              <th style="text-align:right; padding:6px; border-bottom:1px solid #e5e7eb;">Fine</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($overdueRows as $row): ?>
            <tr>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($row['title']) ?></td>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($row['borrower']) ?></td>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6; text-align:right;"><?= (int)$row['days_overdue'] ?></td>
              <td style="padding:6px; border-bottom:1px solid #f3f4f6; text-align:right;">₱<?= htmlspecialchars($row['fine']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Load Chart.js from CDN for rendering charts.  If the CDN fails, charts
     will not render but the rest of the page remains functional. -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Prepare data passed from PHP for use in Chart.js
const monthLabels = <?= json_encode($monthLabels) ?>;
const borrowCounts = <?= json_encode($borrowCounts) ?>;
const returnCounts = <?= json_encode($returnCounts) ?>;
const categoryLabels = <?= json_encode($catLabels) ?>;
const categoryCounts = <?= json_encode($catCounts) ?>;

// Create monthly statistics line chart
const ctxMonth = document.getElementById('monthlyChart').getContext('2d');
new Chart(ctxMonth, {
  type: 'line',
  data: {
    labels: monthLabels,
    datasets: [
      {
        label: 'Borrowings',
        data: borrowCounts,
        borderWidth: 2,
        tension: 0.3,
        fill: false
      },
      {
        label: 'Returns',
        data: returnCounts,
        borderWidth: 2,
        tension: 0.3,
        fill: false
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      y: {
        beginAtZero: true,
        ticks: { precision: 0 }
      }
    }
  }
});

// Create book category doughnut chart
const ctxCat = document.getElementById('categoryChart').getContext('2d');
new Chart(ctxCat, {
  type: 'doughnut',
  data: {
    labels: categoryLabels,
    datasets: [
      {
        data: categoryCounts,
        borderWidth: 1
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'bottom'
      }
    }
  }
});

// Excel export placeholder.  When clicked, build a CSV and trigger
// download.  Data includes monthly stats, categories and popular books.
function exportExcel() {
  const rows = [];
  // Monthly statistics header
  rows.push(['Month','Borrowings','Returns']);
  for (let i = 0; i < monthLabels.length; i++) {
    rows.push([monthLabels[i], borrowCounts[i], returnCounts[i]]);
  }
  rows.push([]);
  // Category distribution header
  rows.push(['Category','Count']);
  for (let i = 0; i < categoryLabels.length; i++) {
    rows.push([categoryLabels[i], categoryCounts[i]]);
  }
  // Convert to CSV
  const csvContent = rows.map(r => r.join(',')).join('\n');
  const blob = new Blob([csvContent], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'library_report_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  URL.revokeObjectURL(url);
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>