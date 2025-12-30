<?php
// E‑Book Access Requests management page.
// This module allows administrators and librarians to view all e‑book
// access requests submitted by students and non‑teaching staff.  Staff may
// approve or decline requests via the actions column.  When a request is
// approved, the requesting patron will be allowed to view and download
// e‑books.  Declined requests remain in the list but are no longer
// actionable.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$u = current_user();
// Only staff roles should access this module
if (!in_array($u['role'], ['admin','librarian','assistant'], true)) {
    header('Location: dashboard.php');
    exit;
}
include __DIR__ . '/_header.php';
?>

<h2>E‑Book Access Requests</h2>

<div style="overflow:auto; border:1px solid #e5e7eb; border-radius:8px; background:#fff; margin-top:12px;">
  <table style="width:100%; border-collapse:collapse;">
    <thead style="background:#f9fafb;">
      <tr>
        <!-- Replace Book ID and Username with a single Requester column.  The
             API returns the requester username; future enhancements may
             include the full name on the server side. -->
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Requester</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Request&nbsp;Date</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Status</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Actions</th>
      </tr>
    </thead>
    <tbody id="reqRows"></tbody>
  </table>
</div>

<script>
// Escape HTML to prevent XSS when inserting user‑provided values.
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(m){ return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[m]; }); }

async function loadRequests(){
  try {
    const res = await fetch('../api/dispatch.php?resource=ebook_requests');
    const data = await res.json();
    const rowsEl = document.getElementById('reqRows');
    if (!Array.isArray(data) || data.length === 0) {
      rowsEl.innerHTML = '<tr><td colspan="5" style="padding:8px;">No access requests found.</td></tr>';
      return;
    }
    // Data may include a `user` field (mapped from patron_id) if available.
    // We also attempt to display the associated username by fetching the
    // patrons/user mapping via a separate call.  However, dispatch.php
    // automatically injects a `user` property when a patron_id column is
    // present.
    rowsEl.innerHTML = data.map(function(r){
      // Build each row: display the requester username in the first column.
      const username = escapeHtml(r.username || '');
      const reqDate = escapeHtml(r.request_date || '');
      const status = escapeHtml(r.status || '');
      let actions = '';
      if (status === 'pending') {
        actions = '<button class="approve-btn" data-id="'+r.id+'" style="margin-right:4px; background:#16a34a; color:#fff; border:none; padding:4px 8px; border-radius:4px;">Approve</button>' +
                  '<button class="decline-btn" data-id="'+r.id+'" style="background:#dc2626; color:#fff; border:none; padding:4px 8px; border-radius:4px;">Decline</button>';
      }
      return '<tr>' +
        '<td style="padding:8px; border-bottom:1px solid #f3f4f6;">' + username + '</td>' +
        '<td style="padding:8px; border-bottom:1px solid #f3f4f6;">' + reqDate + '</td>' +
        '<td style="padding:8px; border-bottom:1px solid #f3f4f6;">' + (status ? status.charAt(0).toUpperCase() + status.slice(1) : '') + '</td>' +
        '<td style="padding:8px; border-bottom:1px solid #f3f4f6;">' + actions + '</td>' +
      '</tr>';
    }).join('');
    // Attach click handlers for approve/decline actions
    document.querySelectorAll('.approve-btn').forEach(function(btn){
      btn.addEventListener('click', async function(){
        const id = btn.getAttribute('data-id');
        if (!confirm('Approve this access request?')) return;
        const csrf = sessionStorage.getItem('csrf') || '';
        try {
          const resp = await fetch('../api/dispatch.php?resource=ebook_requests&id='+id, {
            method:'PUT',
            headers:{ 'Content-Type':'application/json','X-CSRF-Token': csrf },
            body: JSON.stringify({ status:'approved' })
          });
          const out = await resp.json();
          if (!resp.ok) throw new Error(out.error || 'Approval failed');
          loadRequests();
        } catch(err){ alert(err.message || err); }
      });
    });
    document.querySelectorAll('.decline-btn').forEach(function(btn){
      btn.addEventListener('click', async function(){
        const id = btn.getAttribute('data-id');
        // Prompt the administrator for a decline reason.  Only proceed if a reason
        // is provided.  Provide a default value for convenience.
        const reason = prompt('Enter reason for declining this request (e.g. miss information or no availability):','No availability');
        if (reason === null) return;
        const confirmMsg = 'Decline this access request for: ' + reason + '?';
        if (!confirm(confirmMsg)) return;
        const csrf = sessionStorage.getItem('csrf') || '';
        try {
          const resp = await fetch('../api/dispatch.php?resource=ebook_requests&id='+id, {
            method:'PUT',
            headers:{ 'Content-Type':'application/json','X-CSRF-Token': csrf },
            body: JSON.stringify({ status:'declined', action: reason })
          });
          const out = await resp.json();
          if (!resp.ok) throw new Error(out.error || 'Decline failed');
          loadRequests();
        } catch(err){ alert(err.message || err); }
      });
    });
  } catch(e){ console.error(e); }
}
// Initial load
loadRequests();
// Periodically refresh the list every 30 seconds
setInterval(loadRequests, 30000);
</script>

<?php include __DIR__ . '/_footer.php'; ?>