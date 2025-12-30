<?php
// Simple catalogue page listing all books with their available copies.  A
// search bar allows students to filter by book name on the client side.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$u = current_user();
// Restrict access to students and non‑teaching staff only.  Staff have a
// dedicated Manage Books module for inventory tasks.
if (!in_array($u['role'], ['student','non_staff'], true)) {
    header('Location: dashboard.php');
    exit;
}
include __DIR__ . '/_header.php';
?>

<h2>Books Catalogue</h2>

<!-- Search bar for filtering books by name. -->
<div style="margin-bottom:12px; display:flex; gap:8px; align-items:center; max-width:600px;">
  <input id="bookSearch" type="text" placeholder="Enter book name" style="flex:1; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
  <button id="btnBookSearch" class="btn" style="padding:8px 14px; background:#3b82f6; color:#fff; border:none; border-radius:6px;">Search</button>
</div>

<div style="overflow:auto; border:1px solid #e5e7eb; border-radius:8px; background:#fff;">
  <table style="width:100%; border-collapse:collapse;">
    <thead style="background:#f9fafb;">
      <tr>
        <!-- Include a Book ID column so students can easily reference specific titles -->
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Book&nbsp;ID</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Book&nbsp;Name</th>
        <th style="text-align:right; padding:10px; border-bottom:1px solid #e5e7eb;">Available</th>
      </tr>
    </thead>
    <tbody id="bookRows"></tbody>
  </table>
</div>

<script>
let allBooks = [];

async function loadBooks(){
  try {
    const res = await fetch('../api/dispatch.php?resource=books');
    const data = await res.json();
    if (Array.isArray(data)) {
      allBooks = data;
      renderBooks(allBooks);
    }
  } catch(e){ console.error(e); }
}

function renderBooks(list){
  const rowsEl = document.getElementById('bookRows');
  if (!Array.isArray(list) || list.length === 0){
    // Adjust the colspan to reflect the additional ID column
    rowsEl.innerHTML = '<tr><td colspan="3" style="padding:8px;">No books found.</td></tr>';
    return;
  }
  rowsEl.innerHTML = list.map(b => {
    return `<tr>
      <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(String(b.id))}</td>
      <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(b.title)}</td>
      <td style="padding:8px; border-bottom:1px solid #f3f4f6; text-align:right;">${escapeHtml(String(b.available_copies))}</td>
    </tr>`;
  }).join('');
}

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, function(m){ return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[m]; });
}

document.getElementById('btnBookSearch').addEventListener('click', ()=>{
  const q = document.getElementById('bookSearch').value.trim().toLowerCase();
  if (!q) { renderBooks(allBooks); return; }
  const filtered = allBooks.filter(b => (b.title || '').toLowerCase().includes(q));
  renderBooks(filtered);
});

// Load books on page load
loadBooks();
</script>

<?php include __DIR__ . '/_footer.php'; ?>