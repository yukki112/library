<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
include __DIR__ . '/_header.php';
// Capture the current user details for use in the client script.
$__cu = current_user();
?>
<h2>Notifications</h2>
<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:8px;">
  <select id="filterType" class="input" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
    <option value="">All types</option>
    <option value="borrowed">Borrowed</option>
    <option value="returned">Returned</option>
    <option value="report">Report</option>
    <option value="report_update">Report Update</option>
    <option value="info">Info</option>
    <!-- Support reservation notifications -->
    <option value="reservation">Reservation</option>
    <option value="reservation_approved">Reservation Approved</option>
    <option value="reservation_declined">Reservation Declined</option>
  </select>
  <input id="filterFrom" type="datetime-local" class="input" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
  <input id="filterTo" type="datetime-local" class="input" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
  <select id="filterRead" class="input" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
    <option value="">Any</option>
    <option value="0">Unread</option>
    <option value="1">Read</option>
  </select>
  <button id="btnApply" class="btn" style="background:#111827;color:#fff;border:none;padding:8px 12px;border-radius:8px;">Apply</button>
  <button id="btnMarkAll" class="btn" style="background:#10b981;color:#fff;border:none;padding:8px 12px;border-radius:8px;">Mark all read</button>
</div>
<div style="overflow:auto; border:1px solid #e5e7eb; border-radius:8px; background:#fff;">
  <table style="width:100%; border-collapse:collapse;">
    <thead style="background:#f9fafb;">
      <tr>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Time</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Type</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Message</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Status</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Actions</th>
      </tr>
    </thead>
    <tbody id="notifRows"></tbody>
  </table>
 </div>

<script>
const rows = document.getElementById('notifRows');
function getCSRF(){ return sessionStorage.getItem('csrf') || ''; }
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }

// Embed current user role and id from PHP into JS.  These values
// determine how to route message notifications when clicked.  The
// server escapes them for safety.
const userRole = '<?= htmlspecialchars($__cu['role'] ?? '', ENT_QUOTES) ?>';
const userId = <?= isset($__cu['id']) ? (int)$__cu['id'] : 'null' ?>;
let currentNotifs = [];

async function load(){
  const params = new URLSearchParams({ all: '1' });
  const t = document.getElementById('filterType').value; if (t) params.set('type', t);
  const f = document.getElementById('filterFrom').value; if (f) params.set('from', f.replace('T',' '));
  const to = document.getElementById('filterTo').value; if (to) params.set('to', to.replace('T',' '));
  const r = document.getElementById('filterRead').value; if (r !== '') params.set('is_read', r);
  const res = await fetch('../api/notifications.php?' + params.toString());
  const list = await res.json();
  currentNotifs = Array.isArray(list) ? list : [];
  rows.innerHTML = currentNotifs.map((n, idx) => {
    const created = escapeHtml(n.created_at || '');
    const type = escapeHtml(n.type || '');
    const msg = escapeHtml(n.message || '');
    const status = n.is_read ? 'Read' : 'Unread';
    const actionBtn = n.is_read ? '' : `<button onclick="event.stopPropagation(); markRead(${n.id})" style="background:#10b981;color:#fff;border:none;padding:4px 8px;border-radius:4px;">Mark Read</button>`;
    return `<tr data-index="${idx}" style="cursor:pointer;">
      <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${created}</td>
      <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${type}</td>
      <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${msg}</td>
      <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${status}</td>
      <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${actionBtn}</td>
    </tr>`;
  }).join('');
  // Attach click handlers for each row to open details when clicked.
  Array.from(rows.children).forEach((tr) => {
    tr.addEventListener('click', () => {
      const idx = tr.getAttribute('data-index');
      const notif = currentNotifs[idx];
      if (!notif) return;
      openNotification(notif);
    });
  });
}
document.getElementById('btnApply').addEventListener('click', load);
document.getElementById('btnMarkAll').addEventListener('click', async ()=>{
  if (!confirm('Mark all visible notifications as read?')) return;
  await fetch('../api/notifications.php?action=mark_all', { method:'PUT', headers:{ 'X-CSRF-Token': getCSRF() } });
  load();
});

// Live auto-refresh every 10 seconds
setInterval(load, 10000);

async function markRead(id){
  const res = await fetch('../api/notifications.php', { method:'PUT', headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': getCSRF() }, body: JSON.stringify({ id })});
  await res.json();
  load();
}

// Handle opening a notification.  For message notifications, redirect to the
// appropriate chat page.  For other types, simply mark as read.
async function openNotification(notif){
  // Always mark the notification as read when opened
  if (!notif.is_read) {
    try {
      await fetch('../api/notifications.php', { method:'PUT', headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': getCSRF() }, body: JSON.stringify({ id: notif.id }) });
    } catch(e) { console.error(e); }
  }
  if (notif.type === 'message') {
    // Attempt to parse meta to obtain message_id
    let metaObj = {};
    try { metaObj = notif.meta ? JSON.parse(notif.meta) : {}; } catch(e){ metaObj = {}; }
    const mid = metaObj.message_id || null;
    // Determine where to send the user.  Admin/staff see send_to_student,
    // students see send_message_admin.  If we can resolve the peer id via
    // message_id, include it as `with` parameter for staff chat.
    let target = '';
    let withParam = '';
    try {
      if (mid) {
        const res = await fetch('../api/messages.php?id=' + encodeURIComponent(mid));
        const msg = await res.json();
        if (msg && msg.id) {
          let peerId = null;
          if (userId !== null) {
            if (Number(userId) === Number(msg.sender_id)) peerId = msg.receiver_id;
            else if (Number(userId) === Number(msg.receiver_id)) peerId = msg.sender_id;
          }
          if (peerId) {
            withParam = '?with=' + peerId;
          }
        }
      }
    } catch(err) { console.error(err); }
    if (['admin','librarian','assistant'].includes(userRole)) {
      target = 'send_to_student.php' + (withParam || '');
    } else {
      target = 'send_message_admin.php';
    }
    window.location.href = target;
  }
}

load();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
