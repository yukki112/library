<?php
// Student page listing all borrowed books.  This page replaces the
// previous "My Issued Books" page and exposes actions to return or
// extend a borrow.  Only students and non‑staff may access this
// module.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$u = current_user();
// Restrict access to students and non‑staff
if (!in_array($u['role'], ['student','non_staff'], true)) {
    header('Location: dashboard.php');
    exit;
}
$username = $u['username'] ?? '';
include __DIR__ . '/_header.php';
?>

<h2>My Borrowed Books</h2>
<div style="overflow:auto; border:1px solid #e5e7eb; border-radius:8px; background:#fff;">
  <table style="width:100%; border-collapse:collapse;">
    <thead style="background:#f9fafb;">
      <tr>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Username</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Book&nbsp;ID</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Book&nbsp;Name</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Issued&nbsp;At</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Return&nbsp;Date</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Status</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Actions</th>
      </tr>
    </thead>
    <tbody id="issueRows"></tbody>
  </table>
</div>

<script>
// Current user's username is injected from PHP for display purposes.
const userName = <?= json_encode($username) ?>;
let currentLogs = [];
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(m){ return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[m]; }); }

async function loadIssued(){
  try {
    // Fetch borrow logs for the current user.  The API automatically
    // restricts results to the authenticated student's patron_id.
    const resLogs = await fetch('../api/dispatch.php?resource=borrow_logs');
    const logs = await resLogs.json();
    currentLogs = Array.isArray(logs) ? logs : [];
    // Fetch all books to build a mapping of ID to title.
    const resBooks = await fetch('../api/dispatch.php?resource=books');
    const books = await resBooks.json();
    const bookMap = {};
    if (Array.isArray(books)) {
      books.forEach(b => { bookMap[b.id] = b.title; });
    }
    // Fetch reservations for active approvals and merge into display list
    const resRes = await fetch('../api/dispatch.php?resource=reservations');
    const reservations = await resRes.json();
    const activeRes = Array.isArray(reservations) ? reservations.filter(r => ['approved','active'].includes(r.status)) : [];
    const rowsEl = document.getElementById('issueRows');
    // Build a unified array of borrow logs and active reservations.  Each row
    // includes the necessary fields to render the table.  Borrow logs retain
    // their action buttons; reservations do not allow actions at this time.
    const combined = [];
    if (Array.isArray(currentLogs)) {
      currentLogs.forEach(r => {
        combined.push({
          type: 'borrow',
          id: r.id,
          book_id: r.book_id,
          title: bookMap[r.book_id] || r.book_id,
          issued_at: r.borrowed_at || '',
          return_date: r.due_date || '',
          status: r.status || '',
        });
      });
    }
    activeRes.forEach(r => {
      combined.push({
        type: 'reservation',
        id: r.id,
        book_id: r.book_id,
        title: bookMap[r.book_id] || r.book_id,
        issued_at: r.reserved_at || '',
        return_date: '',
        status: r.status || '',
      });
    });
    if (combined.length === 0) {
      rowsEl.innerHTML = '<tr><td colspan="7" style="padding:8px;">No borrowed books or active reservations found.</td></tr>';
      return;
    }
    rowsEl.innerHTML = combined.map(row => {
      const actions = (row.type === 'borrow' && row.status === 'borrowed')
        ? `<button class="return-btn" data-id="${row.id}" style="margin-right:4px; background:#ef4444; color:#fff; border:none; padding:4px 8px; border-radius:4px;">Return</button>` +
          `<button class="extend-btn" data-id="${row.id}" style="background:#3b82f6; color:#fff; border:none; padding:4px 8px; border-radius:4px;">Extend</button>`
        : '';
      return `<tr>
        <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(userName)}</td>
        <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(String(row.book_id || ''))}</td>
        <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(row.title)}</td>
        <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(row.issued_at)}</td>
        <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(row.return_date)}</td>
        <td style="padding:8px; border-bottom:1px solid #f3f4f6; text-transform:capitalize;">${escapeHtml(row.status)}</td>
        <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${actions}</td>
      </tr>`;
    }).join('');
    // Attach handlers for borrow actions
    document.querySelectorAll('.return-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.getAttribute('data-id');
        if (!confirm('Return this book?')) return;
        await borrowAction(id, 'return');
      });
    });
    document.querySelectorAll('.extend-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.getAttribute('data-id');
        if (!confirm('Extend borrowing period by 7 days?')) return;
        await borrowAction(id, 'extend');
      });
    });
  } catch(e){
    console.error(e);
  }
}

async function borrowAction(id, action){
  try {
    const csrf = sessionStorage.getItem('csrf') || '';
    const res = await fetch('../api/borrow_actions.php', {
      method:'POST',
      headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ id: parseInt(id), action: action })
    });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || 'Action failed');
    // Reload the list
    loadIssued();
  } catch(err) {
    alert(err.message);
  }
}

loadIssued();
// Refresh the list every minute
setInterval(loadIssued, 60000);
</script>

<?php include __DIR__ . '/_footer.php'; ?>