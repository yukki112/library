<?php
// Issue Books page. Displays a list of current borrow/issue records.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$pdo = DB::conn();

// Join borrow_logs with books and users (via patrons) to gather display information.
$stmt = $pdo->query(
    "SELECT bl.id, b.title AS book_name, bl.borrowed_at, bl.due_date, bl.status, u.role AS user_role, u.name AS user_name, u.username, u.email
     FROM borrow_logs bl
     JOIN books b ON bl.book_id = b.id
     JOIN patrons p ON bl.patron_id = p.id
     LEFT JOIN users u ON u.patron_id = p.id
     ORDER BY bl.borrowed_at DESC"
);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/_header.php';
?>

<h2>Issue Books</h2>

<?php if (empty($issues)): ?>
    <div class="empty-state"><i class="fa fa-info-circle"></i><h3>No issue records</h3><p>There are currently no issued books.</p></div>
<?php else: ?>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Book Name</th>
                    <th>Issue Date</th>
                    <th>Return Date</th>
                    <th>User Type</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($issues as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['book_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['borrowed_at']))) ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['due_date']))) ?></td>
                    <td><?= htmlspecialchars($row['user_role'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['user_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['username'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['email'] ?? 'N/A') ?></td>
                    <td class="table-actions">
                        <button class="action-btn approve" title="Return Book"><i class="fa fa-rotate-left"></i></button>
                        <button class="action-btn delete" title="Delete"><i class="fa fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>