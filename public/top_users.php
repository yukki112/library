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
// Aggregate borrow statistics per patron.  Replace the library ID
// column with the email so staff can easily contact patrons.  The
// books column lists only titles that are currently borrowed (i.e. not
// yet returned) to reflect active loans.
$stmt = $pdo->query(
    'SELECT p.id, p.name, p.email,
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

<h2><i class="fa fa-users" style="margin-right:8px;"></i>Top Users</h2>

<?php if ($rows): ?>
    <div style="overflow:auto; border:1px solid #e5e7eb; border-radius:8px; background:#fff; margin-top:12px;">
        <table style="width:100%; border-collapse:collapse;">
            <thead style="background:#f9fafb;">
                <tr>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb; text-align:left;">Name</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb; text-align:left;">Email</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb; text-align:left;">Total Borrowed</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb; text-align:left;">Total Returned</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb; text-align:left;">Currently Borrowed</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($r['name']) ?></td>
                        <td style="padding:8px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($r['email'] ?? '') ?></td>
                        <td style="padding:8px; border-bottom:1px solid #f3f4f6;"><?= (int)$r['total_borrowed'] ?></td>
                        <td style="padding:8px; border-bottom:1px solid #f3f4f6;"><?= (int)$r['total_returned'] ?></td>
                        <td style="padding:8px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($r['current_books'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <p>No users have borrowed any books yet.</p>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>