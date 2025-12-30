<?php
// Student Information page. Lists students and basic details.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$pdo = DB::conn();
// Dynamically determine if optional academic fields exist on the patrons table.
// Older installations may not have added the semester, department or address
// columns on the patrons table. Referencing non‑existent columns in the
// SELECT clause will cause SQLSTATE[42S22] unknown column errors.  To
// maintain backward compatibility we inspect information_schema.columns and
// conditionally include these fields in the query, otherwise substitute
// NULLs.  See migrations/schema.sql for current schema definitions.
$hasSemester = false;
$hasDepartment = false;
$hasAddress = false;
try {
    $checkStmt = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns " .
        "WHERE table_schema = DATABASE() AND table_name = 'patrons' " .
        "AND column_name IN ('semester','department','address')"
    );
    $checkStmt->execute();
    $cols = $checkStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasSemester = in_array('semester', $cols, true);
    $hasDepartment = in_array('department', $cols, true);
    $hasAddress = in_array('address', $cols, true);
} catch (PDOException $e) {
    // If information_schema lookup fails, assume columns do not exist.
    $hasSemester = $hasDepartment = $hasAddress = false;
}
// Build the select list from users and patrons. Always include basic
// user fields; optionally include semester, department and address from
// patrons or substitute NULL aliases if unavailable.  Membership date
// exists in both old and new schemas so it is selected unconditionally.
$selectFields = "u.id, u.username, u.email, u.name, u.phone, u.role, u.patron_id";
$selectFields .= $hasSemester ? ", p.semester" : ", NULL AS semester";
$selectFields .= $hasDepartment ? ", p.department" : ", NULL AS department";
$selectFields .= $hasAddress ? ", p.address AS address" : ", NULL AS address";
$selectFields .= ", p.membership_date";
// Build and execute the main query.  Only students and non‑staff should
// appear in this list.  Order by username for a stable display.
$stmt = $pdo->prepare(
    "SELECT " . $selectFields .
    " FROM users u" .
    " LEFT JOIN patrons p ON u.patron_id = p.id" .
    " WHERE u.role IN ('student','non_staff')" .
    " ORDER BY u.username"
);
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/_header.php';
?>

<h2>Student Information</h2>

<?php if (empty($students)): ?>
    <div class="empty-state"><i class="fa fa-info-circle"></i><h3>No students found</h3><p>There are currently no student records to display.</p></div>
<?php else: ?>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Semester</th>
                    <th>Department</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Membership&nbsp;Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($s['username'] ?? '') ?></td>
                    <td><?= htmlspecialchars($s['role'] ?? '') ?></td>
                    <td><?= htmlspecialchars($s['semester'] ?? '') ?: 'N/A' ?></td>
                    <td><?= htmlspecialchars($s['department'] ?? '') ?: 'N/A' ?></td>
                    <td><?= htmlspecialchars($s['email'] ?? '') ?></td>
                    <td><?= htmlspecialchars($s['phone'] ?? '') ?></td>
                    <td>
                        <?php
                        // For backwards compatibility, use the address field selected
                        // from the patrons table.  If empty, display N/A.  Older
                        // schemas that lacked a users.address column will still
                        // populate this address field via the join.  See the
                        // SELECT statement above.
                        $addr = $s['address'] ?? '';
                        echo htmlspecialchars($addr ?: 'N/A');
                        ?>
                    </td>
                    <td><?= htmlspecialchars($s['membership_date'] ?? '') ?: 'N/A' ?></td>
                    <td>
                        <button class="btn edit-student-btn" data-user-id="<?= (int)$s['id'] ?>" data-patron-id="<?= (int)($s['patron_id'] ?? 0) ?>">Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Edit Student Modal -->
<div id="editStudentModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; z-index:1000;">
  <div style="background:#fff; padding:20px; border-radius:8px; width:420px; max-width:90%; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
    <h3 style="margin-top:0;">Edit Student</h3>
    <form id="editStudentForm">
      <input type="hidden" id="editUserId" />
      <input type="hidden" id="editPatronId" />
      <div style="margin-bottom:8px;">
        <label>Name</label>
        <input type="text" id="editName" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Username</label>
        <input type="text" id="editUsername" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Semester</label>
        <input type="text" id="editSemester" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Department</label>
        <input type="text" id="editDepartment" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Email</label>
        <input type="email" id="editEmail" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Phone</label>
        <input type="text" id="editPhone" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Address</label>
        <input type="text" id="editAddress" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
        <button type="button" id="cancelEditStudent" class="btn" style="background:#e5e7eb; color:#374151;">Back</button>
        <button type="button" id="saveEditStudent" class="btn" style="background:#3b82f6; color:#fff;">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('editStudentModal');
  const editButtons = document.querySelectorAll('.edit-student-btn');
  let currentUserId = null;
  let currentPatronId = null;
  editButtons.forEach(btn => {
    btn.addEventListener('click', async () => {
      currentUserId = btn.getAttribute('data-user-id');
      currentPatronId = btn.getAttribute('data-patron-id');
      document.getElementById('editUserId').value = currentUserId;
      document.getElementById('editPatronId').value = currentPatronId;
      try {
        const resU = await fetch('../api/dispatch.php?resource=users&id=' + currentUserId, {credentials:'same-origin'});
        const user = await resU.json();
        document.getElementById('editName').value = user.name || '';
        document.getElementById('editUsername').value = user.username || '';
        document.getElementById('editEmail').value = user.email || '';
        document.getElementById('editPhone').value = user.phone || '';
      } catch(e) { console.error(e); }
      if (currentPatronId && currentPatronId !== '0') {
        try {
          const resP = await fetch('../api/dispatch.php?resource=patrons&id=' + currentPatronId, {credentials:'same-origin'});
          const patron = await resP.json();
          document.getElementById('editSemester').value = patron.semester || '';
          document.getElementById('editDepartment').value = patron.department || '';
          document.getElementById('editAddress').value = patron.address || '';
        } catch(e) { console.error(e); }
      } else {
        document.getElementById('editSemester').value = '';
        document.getElementById('editDepartment').value = '';
        document.getElementById('editAddress').value = '';
      }
      modal.style.display = 'flex';
    });
  });
  document.getElementById('cancelEditStudent').addEventListener('click', () => {
    modal.style.display = 'none';
  });
  document.getElementById('saveEditStudent').addEventListener('click', async () => {
    const csrf = sessionStorage.getItem('csrf') || '';
    const name = document.getElementById('editName').value.trim();
    const username = document.getElementById('editUsername').value.trim();
    const email = document.getElementById('editEmail').value.trim();
    const phone = document.getElementById('editPhone').value.trim();
    const semester = document.getElementById('editSemester').value.trim();
    const department = document.getElementById('editDepartment').value.trim();
    const address = document.getElementById('editAddress').value.trim();
    try {
      await fetch('../api/dispatch.php?resource=users&id=' + currentUserId, {
        method:'PUT',
        credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ name: name, username: username, email: email, phone: phone })
      });
      if (currentPatronId && currentPatronId !== '0') {
        await fetch('../api/dispatch.php?resource=patrons&id=' + currentPatronId, {
          method:'PUT',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ semester: semester, department: department, address: address })
        });
      }
      window.location.reload();
    } catch(err) {
      alert('Update failed: ' + err);
    }
  });
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>