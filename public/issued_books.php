<?php
// Borrowed Books page. Shows a list of books that have been issued (borrowed) with details.  This
// page was previously labeled "Issued Books" and still lists all active borrow logs.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$pdo = DB::conn();

// Borrow logs joined with books and users (via patrons). Only include rows where the book has been borrowed (i.e. status not returned or overdue included as well)
// Allow filtering by status via the "filter" query parameter.  When
// filter=overdue, show only overdue borrow logs.  Otherwise exclude
// returned logs so that only active borrowings appear.
$filterWhere = "WHERE bl.status <> 'returned'";
if (isset($_GET['filter']) && $_GET['filter'] === 'overdue') {
    $filterWhere = "WHERE bl.status = 'overdue'";
}
$stmt = $pdo->query(
    "SELECT bl.id, b.title AS book_name, bl.borrowed_at, bl.due_date, bl.status, u.role AS user_role, u.name AS user_name, u.username, u.email
     FROM borrow_logs bl
     JOIN books b ON bl.book_id = b.id
     JOIN patrons p ON bl.patron_id = p.id
     LEFT JOIN users u ON u.patron_id = p.id
     " . $filterWhere . "
     ORDER BY bl.borrowed_at DESC"
);
$issued = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/_header.php';
?>

<h2>Borrowed Books</h2>

<?php if (empty($issued)): ?>
    <div class="empty-state"><i class="fa fa-info-circle"></i><h3>No borrowed books</h3><p>There are currently no borrowed books to display.</p></div>
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
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($issued as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['book_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['borrowed_at']))) ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['due_date']))) ?></td>
                    <td><?= htmlspecialchars($row['user_role'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['user_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['username'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['email'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars(ucfirst($row['status'] ?? '')) ?></td>
                    <td class="table-actions" data-id="<?= (int)$row['id'] ?>" data-status="<?= htmlspecialchars($row['status'] ?? '') ?>">
                        <?php if (($row['status'] ?? '') === 'borrowed' || ($row['status'] ?? '') === 'overdue'): ?>
                        <button class="action-btn return" title="Mark Returned" style="margin-right:4px;"><i class="fa fa-undo"></i></button>
                        <?php endif; ?>
                        <button class="action-btn delete" title="Delete"><i class="fa fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>

<!-- Add interactivity for returning and deleting issued books.  Each row has
     data attributes for the borrow_log ID and current status.  When the
     Return button is clicked the borrow_logs record is updated with a
     returned status and timestamp.  When the Delete button is clicked
     the record is removed from the database after confirmation. -->
<script>
(function(){
  const rows = document.querySelectorAll('.table-actions');
  rows.forEach(function(td){
    const id = td.getAttribute('data-id');
    if (!id) return;
    // Handle mark as returned
    const retBtn = td.querySelector('.return');
    if (retBtn) {
      retBtn.addEventListener('click', async function(){
        if (!confirm('Mark this book as returned?')) return;
        const csrf = sessionStorage.getItem('csrf') || '';
        const now = new Date();
        const returnedAt = now.toISOString().slice(0,19).replace('T',' ');
        try {
          const resp = await fetch('../api/dispatch.php?resource=borrow_logs&id='+id, {
            method:'PUT',
            credentials:'same-origin',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ status:'returned', returned_at: returnedAt })
          });
          await resp.json();
          // Reload the page to reflect changes
          window.location.reload();
        } catch(e) {
          alert(e.message || e);
        }
      });
    }
    // Handle delete
    const delBtn = td.querySelector('.delete');
    if (delBtn) {
      delBtn.addEventListener('click', async function(){
        if (!confirm('Delete this borrow record?')) return;
        const csrf = sessionStorage.getItem('csrf') || '';
        try {
          const resp = await fetch('../api/dispatch.php?resource=borrow_logs&id='+id, {
            method:'DELETE',
            credentials:'same-origin',
            headers: { 'X-CSRF-Token': csrf }
          });
          const out = await resp.json();
          if (!resp.ok) throw new Error(out.error || 'Delete failed');
          window.location.reload();
        } catch(err) {
          alert(err.message || err);
        }
      });
    }
  });
})();
</script>