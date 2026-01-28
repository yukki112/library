<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
include __DIR__ . '/_header.php';

$__cu = current_user();
?>
<h2>Notifications</h2>

<!-- Bulk Actions and Filters -->
<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:16px; padding:16px; background:#f8fafc; border-radius:8px;">
  <div style="display:flex; gap:8px; flex:1; flex-wrap:wrap;">
    <select id="filterType" class="input" style="padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; background:#fff; min-width:140px;">
      <option value="">All types</option>
      <option value="borrowed">Borrowed</option>
      <option value="returned">Returned</option>
      <option value="report">Report</option>
      <option value="report_update">Report Update</option>
      <option value="info">Info</option>
      <option value="message">Message</option>
      <option value="reservation">Reservation</option>
      <option value="reservation_approved">Reservation Approved</option>
      <option value="reservation_declined">Reservation Declined</option>
    </select>
    <input id="filterFrom" type="datetime-local" class="input" style="padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; background:#fff;" />
    <input id="filterTo" type="datetime-local" class="input" style="padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; background:#fff;" />
    <select id="filterRead" class="input" style="padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; background:#fff; min-width:120px;">
      <option value="">Any status</option>
      <option value="0">Unread</option>
      <option value="1">Read</option>
    </select>
  </div>
  <div style="display:flex; gap:8px;">
    <button id="btnApply" class="btn" style="background:#4f46e5;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-weight:500;cursor:pointer;">Apply Filters</button>
    <button id="btnMarkAll" class="btn" style="background:#10b981;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-weight:500;cursor:pointer;">Mark All Read</button>
    <button id="btnDeleteAll" class="btn" style="background:#ef4444;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-weight:500;cursor:pointer;">Delete All</button>
  </div>
</div>

<!-- Notifications Container -->
<div id="notificationsContainer">
  <!-- Table will be loaded here via JavaScript -->
</div>

<!-- Pagination Controls (hidden initially) -->
<div id="paginationControls" style="display:none; margin-top:20px; padding:16px; background:#f8fafc; border-radius:8px;">
  <div style="display:flex; justify-content:space-between; align-items:center;">
    <div style="color:#6b7280; font-size:14px;">
      Showing <span id="startRow">0</span> to <span id="endRow">0</span> of <span id="totalRows">0</span> notifications
    </div>
    <div style="display:flex; gap:8px;">
      <button id="btnFirst" class="pagination-btn" style="padding:6px 12px; border:1px solid #d1d5db; background:#fff; border-radius:4px; cursor:pointer;" disabled>First</button>
      <button id="btnPrev" class="pagination-btn" style="padding:6px 12px; border:1px solid #d1d5db; background:#fff; border-radius:4px; cursor:pointer;" disabled>Previous</button>
      <span style="padding:6px 12px; color:#374151;">Page <span id="currentPage">1</span> of <span id="totalPages">1</span></span>
      <button id="btnNext" class="pagination-btn" style="padding:6px 12px; border:1px solid #d1d5db; background:#fff; border-radius:4px; cursor:pointer;" disabled>Next</button>
      <button id="btnLast" class="pagination-btn" style="padding:6px 12px; border:1px solid #d1d5db; background:#fff; border-radius:4px; cursor:pointer;" disabled>Last</button>
    </div>
  </div>
</div>

<script>
const itemsPerPage = 20;
let currentPage = 1;
let totalNotifications = 0;
let totalPages = 1;
let allNotifications = []; // Store all fetched notifications

function getCSRF() { 
  // Get CSRF from meta tag if available
  const metaTag = document.querySelector('meta[name="csrf-token"]');
  return metaTag ? metaTag.getAttribute('content') : sessionStorage.getItem('csrf') || ''; 
}

function escapeHtml(s) { 
  return String(s).replace(/[&<>"']/g, m => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); 
}

const userRole = '<?= htmlspecialchars($__cu['role'] ?? '', ENT_QUOTES) ?>';
const userId = <?= isset($__cu['id']) ? (int)$__cu['id'] : 'null' ?>;

async function loadAllNotifications() {
  try {
    // Build query parameters
    const params = new URLSearchParams({ all: '1' });
    
    const type = document.getElementById('filterType').value;
    const from = document.getElementById('filterFrom').value;
    const to = document.getElementById('filterTo').value;
    const read = document.getElementById('filterRead').value;
    
    if (type) params.set('type', type);
    if (from) params.set('from', from.replace('T', ' '));
    if (to) params.set('to', to.replace('T', ' '));
    if (read !== '') params.set('is_read', read);
    
    const res = await fetch('../api/notifications.php?' + params.toString());
    const data = await res.json();
    
    allNotifications = Array.isArray(data) ? data : [];
    totalNotifications = allNotifications.length;
    totalPages = Math.ceil(totalNotifications / itemsPerPage);
    
    renderCurrentPage();
    updatePaginationDisplay();
    
  } catch (error) {
    console.error('Error loading notifications:', error);
    showMessage('Failed to load notifications', 'error');
  }
}

function renderCurrentPage() {
  const container = document.getElementById('notificationsContainer');
  
  // Calculate slice for current page
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = Math.min(startIndex + itemsPerPage, totalNotifications);
  const currentPageNotifications = allNotifications.slice(startIndex, endIndex);
  
  if (currentPageNotifications.length === 0) {
    container.innerHTML = `
      <div style="text-align:center; padding:40px; color:#6b7280; background:#fff; border:1px solid #e5e7eb; border-radius:8px;">
        <div style="margin-bottom:12px; font-size:18px;">No notifications found</div>
        <div style="font-size:14px;">Try adjusting your filters or check back later</div>
      </div>
    `;
    document.getElementById('paginationControls').style.display = 'none';
    return;
  }
  
  let html = `
    <div style="overflow:hidden; border:1px solid #e5e7eb; border-radius:8px; background:#fff;">
      <table style="width:100%; border-collapse:collapse;">
        <thead style="background:#f9fafb;">
          <tr>
            <th style="text-align:left; padding:12px 16px; border-bottom:2px solid #e5e7eb; font-weight:600; color:#374151;">Time</th>
            <th style="text-align:left; padding:12px 16px; border-bottom:2px solid #e5e7eb; font-weight:600; color:#374151;">Type</th>
            <th style="text-align:left; padding:12px 16px; border-bottom:2px solid #e5e7eb; font-weight:600; color:#374151;">Message</th>
            <th style="text-align:left; padding:12px 16px; border-bottom:2px solid #e5e7eb; font-weight:600; color:#374151;">Status</th>
            <th style="text-align:left; padding:12px 16px; border-bottom:2px solid #e5e7eb; font-weight:600; color:#374151;">Actions</th>
          </tr>
        </thead>
        <tbody>`;
  
  currentPageNotifications.forEach((n, idx) => {
    const created = escapeHtml(n.created_at || '');
    const type = escapeHtml(n.type || '');
    const msg = escapeHtml(n.message || '');
    
    // Type badge styling
    let typeBadge = '';
    switch(n.type) {
      case 'borrowed': typeBadge = '<span style="background:#dbeafe; color:#1e40af; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">Borrowed</span>'; break;
      case 'returned': typeBadge = '<span style="background:#dcfce7; color:#166534; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">Returned</span>'; break;
      case 'message': typeBadge = '<span style="background:#f3e8ff; color:#6b21a8; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">Message</span>'; break;
      case 'report': typeBadge = '<span style="background:#fef3c7; color:#92400e; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">Report</span>'; break;
      case 'report_update': typeBadge = '<span style="background:#fef9c3; color:#854d0e; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">Report Update</span>'; break;
      case 'reservation': typeBadge = '<span style="background:#fce7f3; color:#9d174d; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">Reservation</span>'; break;
      case 'reservation_approved': typeBadge = '<span style="background:#dcfce7; color:#166534; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">Approved</span>'; break;
      case 'reservation_declined': typeBadge = '<span style="background:#fee2e2; color:#991b1b; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">Declined</span>'; break;
      case 'info': typeBadge = '<span style="background:#e0e7ff; color:#3730a3; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">Info</span>'; break;
      default: typeBadge = '<span style="background:#f3f4f6; color:#374151; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500;">' + type + '</span>';
    }
    
    // Status styling with icon
    const status = n.is_read ? 
      '<div style="display:flex; align-items:center; gap:4px; color:#10b981; font-weight:500;"><span style="font-size:12px;">●</span> Read</div>' : 
      '<div style="display:flex; align-items:center; gap:4px; color:#ef4444; font-weight:500;"><span style="font-size:12px;">●</span> Unread</div>';
    
    // Row styling with hover effect
    const rowStyle = n.is_read ? 
      'background:#fff; cursor:pointer;' : 
      'background:#fefce8; cursor:pointer;';
    
    html += `
      <tr data-id="${n.id}" style="${rowStyle}" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='${n.is_read ? '#fff' : '#fefce8'}'">
        <td style="padding:12px 16px; border-bottom:1px solid #f3f4f6; font-size:14px; color:#4b5563;">${created}</td>
        <td style="padding:12px 16px; border-bottom:1px solid #f3f4f6;">${typeBadge}</td>
        <td style="padding:12px 16px; border-bottom:1px solid #f3f4f6; font-size:14px; color:#1f2937;">${msg}</td>
        <td style="padding:12px 16px; border-bottom:1px solid #f3f4f6;">${status}</td>
        <td style="padding:12px 16px; border-bottom:1px solid #f3f4f6;">
          <div style="display:flex; gap:4px;">
            ${!n.is_read ? `<button onclick="event.stopPropagation(); markAsRead(${n.id})" style="background:#10b981;color:#fff;border:none;padding:6px 12px;border-radius:4px;font-size:12px;cursor:pointer;margin-right:4px;">Mark Read</button>` : ''}
            <button onclick="event.stopPropagation(); deleteNotification(${n.id})" style="background:#ef4444;color:#fff;border:none;padding:6px 12px;border-radius:4px;font-size:12px;cursor:pointer;">Delete</button>
          </div>
        </td>
      </tr>`;
  });
  
  html += `</tbody></table></div>`;
  container.innerHTML = html;
  
  // Show/hide pagination controls
  const paginationDiv = document.getElementById('paginationControls');
  if (totalNotifications > itemsPerPage) {
    paginationDiv.style.display = 'block';
  } else {
    paginationDiv.style.display = 'none';
  }
  
  // Attach click handlers to rows
  Array.from(document.querySelectorAll('tr[data-id]')).forEach(tr => {
    tr.addEventListener('click', (e) => {
      if (!e.target.closest('button')) {
        const id = tr.getAttribute('data-id');
        const notif = allNotifications.find(n => n.id == id);
        if (notif) openNotification(notif);
      }
    });
  });
}

function updatePaginationDisplay() {
  const start = ((currentPage - 1) * itemsPerPage) + 1;
  const end = Math.min(currentPage * itemsPerPage, totalNotifications);
  
  document.getElementById('startRow').textContent = start;
  document.getElementById('endRow').textContent = end;
  document.getElementById('totalRows').textContent = totalNotifications;
  document.getElementById('currentPage').textContent = currentPage;
  document.getElementById('totalPages').textContent = totalPages;
  
  // Enable/disable buttons
  document.getElementById('btnFirst').disabled = currentPage === 1;
  document.getElementById('btnPrev').disabled = currentPage === 1;
  document.getElementById('btnNext').disabled = currentPage === totalPages;
  document.getElementById('btnLast').disabled = currentPage === totalPages;
  
  // Update button styles
  const buttons = document.querySelectorAll('.pagination-btn');
  buttons.forEach(btn => {
    if (btn.disabled) {
      btn.style.opacity = '0.5';
      btn.style.cursor = 'not-allowed';
    } else {
      btn.style.opacity = '1';
      btn.style.cursor = 'pointer';
    }
  });
}

// Message helper function
function showMessage(message, type = 'success') {
  const color = type === 'success' ? '#10b981' : '#ef4444';
  const bgColor = type === 'success' ? '#d1fae5' : '#fee2e2';
  
  // Remove existing message
  const existingMsg = document.getElementById('notificationMessage');
  if (existingMsg) existingMsg.remove();
  
  const msgDiv = document.createElement('div');
  msgDiv.id = 'notificationMessage';
  msgDiv.innerHTML = `
    <div style="position:fixed; top:20px; right:20px; z-index:1000; padding:12px 16px; background:${bgColor}; color:${color}; border-radius:6px; font-weight:500; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
      ${message}
    </div>
  `;
  document.body.appendChild(msgDiv);
  
  setTimeout(() => {
    if (msgDiv.parentNode) msgDiv.remove();
  }, 3000);
}

// Event Listeners
document.getElementById('btnApply').addEventListener('click', () => {
  currentPage = 1;
  loadAllNotifications();
});

document.getElementById('btnMarkAll').addEventListener('click', async () => {
  if (!confirm('Mark ALL notifications as read? This cannot be undone.')) return;
  try {
    const res = await fetch('../api/notifications.php?action=mark_all', { 
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' }
    });
    const data = await res.json();
    if (data.ok) {
      showMessage('All notifications marked as read');
      loadAllNotifications();
    } else {
      showMessage('Failed to mark all as read', 'error');
    }
  } catch (error) {
    showMessage('Failed to mark all as read', 'error');
  }
});

document.getElementById('btnDeleteAll').addEventListener('click', async () => {
  if (!confirm('Delete ALL your notifications? This action cannot be undone.')) return;
  
  try {
    // Since your API doesn't have delete functionality, we'll simulate it
    // by marking all as read and clearing the local array
    // In a real implementation, you'd need to add DELETE to your API
    showMessage('Delete all feature requires API update', 'error');
    
    // For now, we'll mark all as read instead
    const res = await fetch('../api/notifications.php?action=mark_all', { 
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' }
    });
    if (res.ok) {
      showMessage('All notifications marked as read (delete requires API update)', 'success');
      loadAllNotifications();
    }
  } catch (error) {
    showMessage('Failed to process request', 'error');
  }
});

// Pagination event listeners
document.getElementById('btnFirst').addEventListener('click', () => {
  if (currentPage > 1) {
    currentPage = 1;
    renderCurrentPage();
    updatePaginationDisplay();
  }
});

document.getElementById('btnPrev').addEventListener('click', () => {
  if (currentPage > 1) {
    currentPage--;
    renderCurrentPage();
    updatePaginationDisplay();
  }
});

document.getElementById('btnNext').addEventListener('click', () => {
  if (currentPage < totalPages) {
    currentPage++;
    renderCurrentPage();
    updatePaginationDisplay();
  }
});

document.getElementById('btnLast').addEventListener('click', () => {
  if (currentPage < totalPages) {
    currentPage = totalPages;
    renderCurrentPage();
    updatePaginationDisplay();
  }
});

// Individual notification functions
async function markAsRead(id) {
  try {
    const res = await fetch('../api/notifications.php', { 
      method: 'PUT', 
      headers: { 
        'Content-Type': 'application/json'
      }, 
      body: JSON.stringify({ id }) 
    });
    const data = await res.json();
    if (data.ok) {
      // Update local data
      const notifIndex = allNotifications.findIndex(n => n.id === id);
      if (notifIndex !== -1) {
        allNotifications[notifIndex].is_read = 1;
      }
      renderCurrentPage();
      showMessage('Notification marked as read');
    }
  } catch (error) {
    showMessage('Failed to mark as read', 'error');
  }
}

async function deleteNotification(id) {
  if (!confirm('Delete this notification?')) return;
  
  try {
    // Since your API doesn't support DELETE, we'll mark it as read
    // In a real implementation, you'd need to add DELETE to your API
    const res = await fetch('../api/notifications.php', { 
      method: 'PUT', 
      headers: { 
        'Content-Type': 'application/json'
      }, 
      body: JSON.stringify({ id }) 
    });
    
    if (res.ok) {
      // Remove from local array
      allNotifications = allNotifications.filter(n => n.id !== id);
      totalNotifications = allNotifications.length;
      totalPages = Math.ceil(totalNotifications / itemsPerPage);
      
      // Adjust current page if needed
      if (currentPage > totalPages && totalPages > 0) {
        currentPage = totalPages;
      } else if (totalPages === 0) {
        currentPage = 1;
      }
      
      renderCurrentPage();
      updatePaginationDisplay();
      showMessage('Notification deleted (marked as read in system)');
    }
  } catch (error) {
    showMessage('Failed to delete notification', 'error');
  }
}

// Open notification (same as before)
async function openNotification(notif) {
  if (!notif.is_read) {
    try {
      await fetch('../api/notifications.php', { 
        method: 'PUT', 
        headers: { 
          'Content-Type': 'application/json'
        }, 
        body: JSON.stringify({ id: notif.id }) 
      });
      // Update local data
      const notifIndex = allNotifications.findIndex(n => n.id === notif.id);
      if (notifIndex !== -1) {
        allNotifications[notifIndex].is_read = 1;
      }
    } catch(e) { console.error(e); }
  }
  
  if (notif.type === 'message') {
    let metaObj = {};
    try { metaObj = notif.meta ? JSON.parse(notif.meta) : {}; } catch(e){ metaObj = {}; }
    const mid = metaObj.message_id || null;
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
  } else {
    // For non-message notifications, just refresh to show read status
    renderCurrentPage();
  }
}

// Auto-refresh every 30 seconds
setInterval(() => loadAllNotifications(), 30000);

// Initial load
loadAllNotifications();
</script>

<?php include __DIR__ . '/_footer.php'; ?>