<?php
// Top Users page displays patrons along with aggregate borrow statistics.
// It shows how many books each user has borrowed, how many they have returned,
// and a list of titles they have borrowed.  Only admin, librarian, and assistant
// roles can view this page.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

require_role(['admin','librarian','assistant']);

// Fetch aggregated borrow stats per patron
$pdo = DB::conn();
// Aggregate borrow statistics per patron with additional user information
$stmt = $pdo->query(
    'SELECT p.id, p.name, p.library_id, p.email, p.phone, p.semester, p.department, p.status,
            COUNT(bl.id) AS total_borrowed,
            SUM(CASE WHEN bl.returned_at IS NOT NULL THEN 1 ELSE 0 END) AS total_returned,
            GROUP_CONCAT(DISTINCT CASE WHEN bl.returned_at IS NULL THEN b.title END SEPARATOR ", ") AS current_books
     FROM patrons p
     LEFT JOIN borrow_logs bl ON bl.patron_id = p.id
     LEFT JOIN books b ON b.id = bl.book_id
     GROUP BY p.id
     ORDER BY total_borrowed DESC, p.name ASC'
);
$rows = $stmt->fetchAll();

include __DIR__ . '/_header.php';
?>

<style>
    .top-users-container {
        margin-top: 20px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        overflow: hidden;
        border: 1px solid #e5e7eb;
    }

    .top-users-header {
        padding: 20px 24px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .top-users-header h2 {
        margin: 0;
        font-size: 24px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .stats-summary {
        display: flex;
        gap: 24px;
        margin-top: 16px;
        flex-wrap: wrap;
    }

    .stat-card {
        background: rgba(255,255,255,0.15);
        padding: 12px 20px;
        border-radius: 10px;
        backdrop-filter: blur(10px);
        min-width: 180px;
    }

    .stat-value {
        font-size: 28px;
        font-weight: 700;
        line-height: 1;
    }

    .stat-label {
        font-size: 14px;
        opacity: 0.9;
        margin-top: 4px;
    }

    .table-container {
        overflow-x: auto;
        padding: 20px;
    }

    .users-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1200px;
    }

    .users-table thead {
        background: #f8fafc;
        border-bottom: 2px solid #e2e8f0;
    }

    .users-table th {
        padding: 16px;
        text-align: left;
        font-weight: 600;
        color: #475569;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        white-space: nowrap;
    }

    .users-table tbody tr {
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.2s;
    }

    .users-table tbody tr:hover {
        background-color: #f8fafc;
    }

    .users-table td {
        padding: 16px;
        color: #334155;
        vertical-align: top;
    }

    .user-info-row {
        background: #f8fafc;
    }

    .borrow-stats-row {
        background: #fff;
    }

    .user-name {
        font-weight: 600;
        color: #1e293b;
        font-size: 16px;
    }

    .user-id {
        font-size: 13px;
        color: #64748b;
        margin-top: 4px;
    }

    .user-contact {
        font-size: 14px;
        line-height: 1.5;
    }

    .user-contact .email {
        color: #3b82f6;
        word-break: break-all;
    }

    .user-contact .phone {
        color: #64748b;
        margin-top: 4px;
    }

    .user-academic {
        font-size: 14px;
        line-height: 1.5;
    }

    .user-academic .department {
        font-weight: 500;
        color: #1e293b;
    }

    .user-academic .semester {
        color: #64748b;
        margin-top: 4px;
        font-size: 13px;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .status-active {
        background: #d1fae5;
        color: #065f46;
    }

    .status-inactive {
        background: #fee2e2;
        color: #991b1b;
    }

    .stat-numbers {
        display: flex;
        gap: 20px;
        align-items: center;
    }

    .borrow-stat {
        text-align: center;
        min-width: 80px;
    }

    .stat-count {
        font-size: 24px;
        font-weight: 700;
        line-height: 1;
    }

    .stat-count.total {
        color: #3b82f6;
    }

    .stat-count.returned {
        color: #10b981;
    }

    .stat-label-small {
        font-size: 12px;
        color: #64748b;
        margin-top: 4px;
    }

    .current-books {
        max-width: 300px;
        font-size: 14px;
        line-height: 1.5;
    }

    .book-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }

    .book-tag {
        background: #e0e7ff;
        color: #3730a3;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .book-tag::before {
        content: "📚";
        font-size: 11px;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #64748b;
    }

    .empty-state i {
        font-size: 48px;
        margin-bottom: 16px;
        color: #cbd5e1;
    }

    .empty-state h3 {
        font-size: 20px;
        color: #475569;
        margin-bottom: 8px;
    }

    @media (max-width: 768px) {
        .top-users-header {
            padding: 16px;
        }
        
        .top-users-header h2 {
            font-size: 20px;
        }
        
        .stat-card {
            min-width: 140px;
            padding: 10px 16px;
        }
        
        .stat-value {
            font-size: 24px;
        }
        
        .table-container {
            padding: 12px;
        }
        
        .users-table th,
        .users-table td {
            padding: 12px;
        }
    }
</style>

<div class="top-users-container">
    <div class="top-users-header">
        <h2><i class="fa fa-users"></i>Top Users & Borrowing Statistics</h2>
        
        <div class="stats-summary">
            <div class="stat-card">
                <div class="stat-value"><?= count($rows) ?></div>
                <div class="stat-label">Total Patrons</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($rows, 'total_borrowed')) ?></div>
                <div class="stat-label">Total Books Borrowed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($rows, 'total_returned')) ?></div>
                <div class="stat-label">Total Books Returned</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?= count(array_filter($rows, function($row) { 
                        return !empty(trim($row['current_books'] ?? '')); 
                    })) ?>
                </div>
                <div class="stat-label">Active Borrowers</div>
            </div>
        </div>
    </div>

    <div class="table-container">
        <?php if ($rows): ?>
            <table class="users-table">
                <thead>
                    <tr>
                        <th>User Information</th>
                        <th>Contact Details</th>
                        <th>Academic Info</th>
                        <th>Status</th>
                        <th>Borrow Statistics</th>
                        <th>Currently Borrowed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $currentBooks = array_filter(explode(', ', $r['current_books'] ?? ''), function($book) {
                            return !empty(trim($book));
                        });
                        ?>
                        <tr>
                            <!-- User Information -->
                            <td>
                                <div class="user-name"><?= htmlspecialchars($r['name']) ?></div>
                                <div class="user-id">ID: <?= htmlspecialchars($r['library_id']) ?></div>
                            </td>
                            
                            <!-- Contact Details -->
                            <td>
                                <div class="user-contact">
                                    <div class="email"><?= htmlspecialchars($r['email'] ?? 'N/A') ?></div>
                                    <div class="phone"><?= htmlspecialchars($r['phone'] ?? 'N/A') ?></div>
                                </div>
                            </td>
                            
                            <!-- Academic Info -->
                            <td>
                                <div class="user-academic">
                                    <div class="department"><?= htmlspecialchars($r['department'] ?? 'N/A') ?></div>
                                    <div class="semester"><?= htmlspecialchars($r['semester'] ?? 'N/A') ?></div>
                                </div>
                            </td>
                            
                            <!-- Status -->
                            <td>
                                <?php if ($r['status'] == 'active'): ?>
                                    <span class="status-badge status-active">
                                        <i class="fa fa-check-circle" style="margin-right:4px;"></i>
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">
                                        <i class="fa fa-times-circle" style="margin-right:4px;"></i>
                                        Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Borrow Statistics -->
                            <td>
                                <div class="stat-numbers">
                                    <div class="borrow-stat">
                                        <div class="stat-count total"><?= (int)$r['total_borrowed'] ?></div>
                                        <div class="stat-label-small">Borrowed</div>
                                    </div>
                                    <div class="borrow-stat">
                                        <div class="stat-count returned"><?= (int)$r['total_returned'] ?></div>
                                        <div class="stat-label-small">Returned</div>
                                    </div>
                                    <div class="borrow-stat">
                                        <div class="stat-count" style="color:#f59e0b;">
                                            <?= (int)$r['total_borrowed'] - (int)$r['total_returned'] ?>
                                        </div>
                                        <div class="stat-label-small">Active</div>
                                    </div>
                                </div>
                            </td>
                            
                            <!-- Currently Borrowed Books -->
                            <td>
                                <div class="current-books">
                                    <?php if (!empty($currentBooks)): ?>
                                        <div class="book-list">
                                            <?php foreach ($currentBooks as $book): ?>
                                                <span class="book-tag"><?= htmlspecialchars($book) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-style:italic;">No active loans</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa fa-users fa-3x"></i>
                <h3>No Users Found</h3>
                <p>There are no patrons registered in the system yet.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>