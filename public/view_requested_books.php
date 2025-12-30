<?php
// View Requested Books page. Shows reservation requests and requester information.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$pdo = DB::conn();

// Fetch reservation requests with associated user info and book title.
// Include the book_id and patron_id on the reservation so that when a
// reservation is approved we can issue a borrow_log entry automatically.
// Allow filtering by reservation status via query parameter.  Only valid
// statuses are accepted; otherwise show all requests.  Approved and
// active statuses are treated equivalently for filtering.
$allowedStatuses = ['pending','approved','active','fulfilled','cancelled','expired','declined'];
$where = '';
if (isset($_GET['status']) && in_array(strtolower($_GET['status']), $allowedStatuses, true)) {
    $filterStatus = strtolower($_GET['status']);
    // Active is the legacy name for approved reservations.  Accept both.
    if ($filterStatus === 'active') $filterStatus = 'approved';
    $where = 'WHERE r.status = ' . $pdo->quote($filterStatus);
}
// Hide fulfilled reservations by default.  Once a reservation has been
// processed (i.e. converted into a borrow log) it should no longer
// clutter the staff view.  Append an additional condition to remove
// rows where the status is "fulfilled" unless a specific status
// filter was supplied above.
if ($where) {
    $where .= ' AND r.status <> "fulfilled"';
} else {
    $where = 'WHERE r.status <> "fulfilled"';
}

// Exclude reservations that have already resulted in a borrow that was subsequently returned.
// Once a book has been borrowed and returned, the associated reservation should no longer
// appear in the requested books list.  To achieve this we exclude any reservation
// where a borrow_log exists for the same book and patron with a status of "returned".
// The correlated subquery checks the borrow_logs table for a matching combination of
// book_id and patron_id with status="returned".  If such a row exists, the reservation
// row is omitted from the query results.
if ($where) {
    $where .= ' AND NOT EXISTS (SELECT 1 FROM borrow_logs bl WHERE bl.book_id = r.book_id AND bl.patron_id = r.patron_id AND bl.status = "returned")';
} else {
    $where = 'WHERE NOT EXISTS (SELECT 1 FROM borrow_logs bl WHERE bl.book_id = r.book_id AND bl.patron_id = r.patron_id AND bl.status = "returned")';
}
// Attempt to query the reservations table including the decline reason.
// The `reason` column was introduced in a later migration.  If the
// column does not exist (SQLSTATE 42S22), we select NULL as the
// reason to maintain backwards compatibility and avoid a fatal
// database error.
try {
    // Fetch reservation requests with associated user info and any active borrow log.  A
    // borrow log is considered active if its status is not "returned".  Including the
    // borrow_log id and due_date enables the UI to offer Edit and Return actions on
    // approved reservations directly from this page.
    $stmt = $pdo->query(
        "SELECT r.id, r.book_id, r.patron_id,
                b.title AS book_name,
                r.status, r.reason,
                u.role AS user_role, u.name AS user_name, u.username, u.email,
                bl.id AS borrow_id,
                bl.due_date AS borrow_due_date,
                bl.status AS borrow_status
         FROM reservations r
         JOIN books b ON r.book_id = b.id
         JOIN patrons p ON r.patron_id = p.id
         LEFT JOIN users u ON u.patron_id = p.id
         LEFT JOIN borrow_logs bl
           ON bl.book_id = r.book_id AND bl.patron_id = r.patron_id AND bl.status <> 'returned'
         " . $where . "
         ORDER BY r.reserved_at DESC"
    );
} catch (PDOException $ex) {
    if ($ex->getCode() === '42S22') {
        // Fallback when the reservations table lacks the `reason` column.  Also join
        // borrow_logs as above for edit/return functionality.
        $stmt = $pdo->query(
            "SELECT r.id, r.book_id, r.patron_id,
                    b.title AS book_name,
                    r.status, NULL AS reason,
                    u.role AS user_role, u.name AS user_name, u.username, u.email,
                    bl.id AS borrow_id,
                    bl.due_date AS borrow_due_date,
                    bl.status AS borrow_status
             FROM reservations r
             JOIN books b ON r.book_id = b.id
             JOIN patrons p ON r.patron_id = p.id
             LEFT JOIN users u ON u.patron_id = p.id
             LEFT JOIN borrow_logs bl
               ON bl.book_id = r.book_id AND bl.patron_id = r.patron_id AND bl.status <> 'returned'
             " . $where . "
             ORDER BY r.reserved_at DESC"
        );
    } else {
        throw $ex;
    }
}
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/_header.php';
?>

<h2>View Requested Books</h2>

<?php if (empty($requests)): ?>
    <div class="empty-state"><i class="fa fa-info-circle"></i><h3>No reservation requests</h3><p>There are currently no book requests.</p></div>
<?php else: ?>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>User Type</th>
                    <th>Email</th>
                    <th>Book Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['user_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['username'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['user_role'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['email'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['book_name'] ?? '') ?></td>
                    <td>
                        <?php
                        $status = strtolower($row['status'] ?? '');
                        $resId = (int)($row['id'] ?? 0);
                        $bookId = (int)($row['book_id'] ?? 0);
                        $patronId = (int)($row['patron_id'] ?? 0);
                        $borrowId = isset($row['borrow_id']) ? (int)$row['borrow_id'] : 0;
                        $borrowDue = isset($row['borrow_due_date']) ? htmlspecialchars($row['borrow_due_date']) : '';
                        // Determine which actions to display based on status.
                        if ($status === 'pending') {
                            // Pending requests can be accepted or declined.
                            echo '<button class="btn accept-btn" data-res-id="' . $resId . '" data-book-id="' . $bookId . '" data-patron-id="' . $patronId . '" style="background:#16a34a; color:#fff; border:none; padding:4px 8px; border-radius:4px; margin-right:4px;">Accept</button>';
                            echo '<button class="btn decline-btn" data-res-id="' . $resId . '" style="background:#dc2626; color:#fff; border:none; padding:4px 8px; border-radius:4px;">Decline</button>';
                        } elseif (in_array($status, ['approved','active'], true)) {
                            // Approved (active) reservations may have an associated borrow log.  When a borrow
                            // exists, display Edit and Return buttons to allow staff to adjust due
                            // dates or mark the book as returned directly from this page.  If no
                            // borrow_log exists (e.g. approval failed), fall back to showing the
                            // status text.
                            if ($borrowId) {
                                echo '<button class="btn edit-btn" data-res-id="' . $resId . '" data-borrow-id="' . $borrowId . '" data-due-date="' . $borrowDue . '" style="background:#06b6d4; color:#fff; border:none; padding:4px 8px; border-radius:4px; margin-right:4px;">Edit</button>';
                                echo '<button class="btn return-btn" data-res-id="' . $resId . '" data-borrow-id="' . $borrowId . '" style="background:#f59e0b; color:#fff; border:none; padding:4px 8px; border-radius:4px;">Return</button>';
                            } else {
                                echo '<span>' . htmlspecialchars(ucfirst($row['status'] ?? '')) . '</span>';
                            }
                        } else {
                            // Declined, fulfilled, cancelled, expired etc.  Show status and reason if applicable.
                            $dispStatus = htmlspecialchars(ucfirst($row['status'] ?? ''));
                            $reason = htmlspecialchars($row['reason'] ?? '');
                            if ($status === 'declined' && $reason) {
                                echo '<span>' . $dispStatus . ' (' . $reason . ')</span>';
                            } else {
                                echo '<span>' . $dispStatus . '</span>';
                            }
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
(function(){
  const acceptBtns = document.querySelectorAll('.accept-btn');
  acceptBtns.forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-res-id');
      const bookId = btn.getAttribute('data-book-id');
      const patronId = btn.getAttribute('data-patron-id');
      if (!confirm('Approve this reservation?')) return;
      const csrf = sessionStorage.getItem('csrf') || '';
      try {
        // Update reservation status to approved.  Approved reservations remain
        // visible in the View Requested Books page so that staff can edit
        // or return the associated borrow.  Setting the status to
        // "approved" indicates the request has been processed and a
        // corresponding borrow record will be created.
        const res = await fetch('../api/dispatch.php?resource=reservations&id=' + id, {
          method:'PUT',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json','X-CSRF-Token': csrf },
          body: JSON.stringify({ status:'approved' })
        });
        const out = await res.json();
        if (!res.ok) throw new Error(out.error || 'Approval failed');
        // After approving, immediately issue the book by creating a borrow_log
        // Set borrowed_at to now and due_date to 7 days from now
        const now = new Date();
        const borrowedAt = now.toISOString().slice(0,19).replace('T',' ');
        const dueDateObj = new Date(now.getTime() + 7*24*60*60*1000);
        const dueDate = dueDateObj.toISOString().slice(0,19).replace('T',' ');
        await fetch('../api/dispatch.php?resource=borrow_logs', {
          method:'POST',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json','X-CSRF-Token': csrf },
          body: JSON.stringify({ book_id: parseInt(bookId), patron_id: parseInt(patronId), borrowed_at: borrowedAt, due_date: dueDate, status:'borrowed' })
        });
        window.location.reload();
      } catch(err){ alert(err.message || err); }
    });
  });
  const declineBtns = document.querySelectorAll('.decline-btn');
  declineBtns.forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-res-id');
      // Prompt the staff for a decline reason; if cancelled, abort.
      const reason = prompt('Enter reason for declining this reservation (e.g. miss information or no availability):','No availability');
      if (reason === null) return;
      if (!confirm('Decline this reservation for: ' + reason + '?')) return;
      const csrf = sessionStorage.getItem('csrf') || '';
      try {
        const res = await fetch('../api/dispatch.php?resource=reservations&id=' + id, {
          method:'PUT',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json','X-CSRF-Token': csrf },
          body: JSON.stringify({ status:'declined', reason: reason })
        });
        const out = await res.json();
        if (!res.ok) throw new Error(out.error || 'Decline failed');
        window.location.reload();
      } catch(err){ alert(err.message || err); }
    });
  });

  // Edit due date for approved reservations.  When the Edit button is clicked
  // prompt the staff for a new due date/time.  The borrow_log is then
  // updated via the API.  If the input is empty or cancelled the
  // operation is aborted.
  const editBtns = document.querySelectorAll('.edit-btn');
  editBtns.forEach(btn => {
    btn.addEventListener('click', async () => {
      const borrowId = btn.getAttribute('data-borrow-id');
      const resId = btn.getAttribute('data-res-id');
      const currentDue = btn.getAttribute('data-due-date') || '';
      const newDue = prompt('Enter new due date and time (YYYY-MM-DD HH:MM:SS):', currentDue);
      if (newDue === null || newDue.trim() === '') return;
      if (!confirm('Set the due date to ' + newDue + '?')) return;
      const csrf = sessionStorage.getItem('csrf') || '';
      try {
        const resp = await fetch('../api/dispatch.php?resource=borrow_logs&id=' + borrowId, {
          method:'PUT',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json','X-CSRF-Token': csrf },
          body: JSON.stringify({ due_date: newDue })
        });
        await resp.json();
        window.location.reload();
      } catch(err) {
        alert(err.message || err);
      }
    });
  });

  // Return borrowed book for approved reservations.  When the Return button
  // is clicked mark the borrow_log as returned and update the associated
  // reservation to fulfilled so that it no longer appears as active.  A
  // timestamp is recorded for returned_at using the current time.
  const returnBtns = document.querySelectorAll('.return-btn');
  returnBtns.forEach(btn => {
    btn.addEventListener('click', async () => {
      const borrowId = btn.getAttribute('data-borrow-id');
      const resId = btn.getAttribute('data-res-id');
      if (!confirm('Mark this book as returned?')) return;
      const csrf = sessionStorage.getItem('csrf') || '';
      const now = new Date();
      const returnedAt = now.toISOString().slice(0,19).replace('T',' ');
      try {
        // Update the borrow log status to returned
        const resp = await fetch('../api/dispatch.php?resource=borrow_logs&id=' + borrowId, {
          method:'PUT',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json','X-CSRF-Token': csrf },
          body: JSON.stringify({ status:'returned', returned_at: returnedAt })
        });
        await resp.json();
        // Also mark the reservation as fulfilled so it disappears from active lists
        if (resId) {
          await fetch('../api/dispatch.php?resource=reservations&id=' + resId, {
            method:'PUT',
            credentials:'same-origin',
            headers:{ 'Content-Type':'application/json','X-CSRF-Token': csrf },
            body: JSON.stringify({ status:'fulfilled' })
          });
        }
        window.location.reload();
      } catch(err) {
        alert(err.message || err);
      }
    });
  });
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>