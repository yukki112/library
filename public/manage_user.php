<?php
// Manage User page. Lists all users and basic information.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
// Only admins, librarians and assistants should access this page – enforced via sidebar menu but double-check here.
if (!in_array(current_user()['role'], ['admin','librarian','assistant'], true)) {
    header('Location: dashboard.php');
    exit;
}
$pdo = DB::conn();
$users = $pdo->query("SELECT id, username, email, name, phone, role, status FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/_header.php';
?>

<h2>Manage User</h2>

<?php if (empty($users)): ?>
    <div class="empty-state"><i class="fa fa-info-circle"></i><h3>No users found</h3><p>The system currently has no user accounts.</p></div>
<?php else: ?>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['username'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['phone'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['role'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['status'] ?? '') ?></td>
                    <td class="table-actions">
                        <button class="action-btn edit-user-btn" data-user-id="<?= (int)$u['id'] ?>" title="Edit"><i class="fa fa-pen"></i></button>
                        <button class="action-btn delete-user-btn" data-user-id="<?= (int)$u['id'] ?>" title="Delete"><i class="fa fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Edit User Modal -->
<div id="editUserModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; z-index:1000;">
  <div style="background:#fff; padding:20px; border-radius:8px; width:420px; max-width:90%; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
    <h3 style="margin-top:0;">Edit User</h3>
    <form id="editUserForm">
      <input type="hidden" id="userEditId" />
      <div style="margin-bottom:8px;">
        <label>Name</label>
        <input type="text" id="userEditName" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Username</label>
        <input type="text" id="userEditUsername" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Email</label>
        <input type="email" id="userEditEmail" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Phone</label>
        <input type="text" id="userEditPhone" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
      </div>
      <div style="margin-bottom:8px;">
        <label>Role</label>
        <select id="userEditRole" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
          <option value="admin">admin</option>
          <option value="librarian">librarian</option>
          <option value="assistant">assistant</option>
          <option value="teacher">teacher</option>
          <option value="student">student</option>
          <option value="non_staff">non_staff</option>
        </select>
      </div>
      <div style="margin-bottom:8px;">
        <label>Status</label>
        <select id="userEditStatus" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;">
          <option value="active">active</option>
          <option value="disabled">disabled</option>
        </select>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
        <button type="button" id="cancelEditUser" class="btn" style="background:#e5e7eb; color:#374151;">Back</button>
        <button type="button" id="saveEditUser" class="btn" style="background:#3b82f6; color:#fff;">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const userModal = document.getElementById('editUserModal');
  let currentId = null;
  document.querySelectorAll('.edit-user-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      currentId = btn.getAttribute('data-user-id');
      document.getElementById('userEditId').value = currentId;
      try {
        const res = await fetch('../api/dispatch.php?resource=users&id=' + currentId, {credentials:'same-origin'});
        const u = await res.json();
        document.getElementById('userEditName').value = u.name || '';
        document.getElementById('userEditUsername').value = u.username || '';
        document.getElementById('userEditEmail').value = u.email || '';
        document.getElementById('userEditPhone').value = u.phone || '';
        document.getElementById('userEditRole').value = u.role || '';
        document.getElementById('userEditStatus').value = u.status || '';
      } catch(e){ console.error(e); }
      userModal.style.display = 'flex';
    });
  });
  document.querySelectorAll('.delete-user-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-user-id');
      if (!confirm('Delete this user?')) return;
      const csrf = sessionStorage.getItem('csrf') || '';
      try {
        const res = await fetch('../api/dispatch.php?resource=users&id=' + id, {
          method:'DELETE',
          credentials:'same-origin',
          headers:{ 'X-CSRF-Token': csrf }
        });
        const out = await res.json();
        if (!res.ok) throw new Error(out.error || 'Delete failed');
        window.location.reload();
      } catch(err){ alert(err); }
    });
  });
  document.getElementById('cancelEditUser').addEventListener('click', () => {
    userModal.style.display = 'none';
  });
  document.getElementById('saveEditUser').addEventListener('click', async () => {
    const csrf = sessionStorage.getItem('csrf') || '';
    const payload = {
      name: document.getElementById('userEditName').value.trim(),
      username: document.getElementById('userEditUsername').value.trim(),
      email: document.getElementById('userEditEmail').value.trim(),
      phone: document.getElementById('userEditPhone').value.trim(),
      role: document.getElementById('userEditRole').value,
      status: document.getElementById('userEditStatus').value
    };
    try {
      const res = await fetch('../api/dispatch.php?resource=users&id=' + currentId, {
        method:'PUT',
        credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(payload)
      });
      const out = await res.json();
      if (!res.ok) throw new Error(out.error || 'Update failed');
      window.location.reload();
    } catch(err){ alert(err); }
  });
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>