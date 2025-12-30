<?php
// Page for students and non‑teaching staff to request a book reservation.  The
// form captures the user's name, email and role (for display only) and
// requires the user to enter a book ID and optionally a book title.  Upon
// submission the form sends a POST request to the reservations API which
// creates a pending reservation for staff approval.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$u = current_user();
// Only students and non‑staff should access this page.  Staff can reserve
// books directly through the Issue Books module.
if (!in_array($u['role'], ['student','non_staff'], true)) {
    header('Location: dashboard.php');
    exit;
}
$username = $u['username'] ?? '';
$email = $u['email'] ?? '';
$roleLabel = ($u['role'] === 'non_staff') ? 'Non‑Teaching Staff' : 'Student';
include __DIR__ . '/_header.php';
?>

<!-- Centre the heading for a more consistent look with the Send Message page -->
<h2 style="text-align:center;">Request Book</h2>

<!-- Wrap the form in a constrained container and centre it on the page.  This matches the layout used on the message pages. -->
<form id="reqForm" style="max-width:600px; margin:0 auto; background:#fff; padding:16px; border-radius:8px; border:1px solid #e5e7eb;">
  <label style="display:block; font-weight:600; margin-top:10px;">Username</label>
  <input type="text" value="<?= htmlspecialchars($username) ?>" readonly style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;" />
  <label style="display:block; font-weight:600; margin-top:10px;">Email</label>
  <input type="text" value="<?= htmlspecialchars($email) ?>" readonly style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;" />
  <label style="display:block; font-weight:600; margin-top:10px;">Role</label>
  <input type="text" value="<?= htmlspecialchars($roleLabel) ?>" readonly style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;" />
  <label style="display:block; font-weight:600; margin-top:10px;">Request Book Name</label>
  <input id="reqBookName" type="text" placeholder="Enter book name" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
  <label style="display:block; font-weight:600; margin-top:10px;">Book Number (ID)</label>
  <input id="reqBookId" type="number" placeholder="Enter book ID" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />

  <!-- Reservation date selector.  Students specify the date they wish to start
       their reservation.  When omitted, the current date will be used. -->
  <label style="display:block; font-weight:600; margin-top:10px;">Reservation Date</label>
  <input id="reqResDate" type="date" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />

  <!-- Return date selector.  This date indicates when the reservation expires
       (i.e. the latest date by which the book should be borrowed). -->
  <label style="display:block; font-weight:600; margin-top:10px;">Return Date</label>
  <input id="reqReturnDate" type="date" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
  <button type="submit" class="btn" style="margin-top:16px; background:#3b82f6; color:#fff; border:none; padding:10px 14px; border-radius:8px;">Submit Request</button>
  <p id="reqMsg" style="margin-top:8px; font-size:12px; color:#6b7280; text-align:center;"></p>
</form>

<script>
function getCSRF(){ return sessionStorage.getItem('csrf') || ''; }
document.getElementById('reqForm').addEventListener('submit', async function(ev){
  ev.preventDefault();
  const bookId = document.getElementById('reqBookId').value.trim();
  const resDate = document.getElementById('reqResDate').value;
  const returnDate = document.getElementById('reqReturnDate').value;
  const msgEl = document.getElementById('reqMsg');
  msgEl.style.color = '#6b7280';
  msgEl.textContent = 'Submitting...';
  if (!bookId){
    msgEl.style.color = '#dc2626';
    msgEl.textContent = 'Book ID is required.';
    return;
  }
  try {
    const payload = { book_id: parseInt(bookId, 10) };
    // If the user selected a reservation date, convert it to a full
    // datetime string (midnight) accepted by MySQL.  The API will
    // normalize this value before storage.
    if (resDate) {
      payload.reserved_at = resDate + ' 00:00:00';
    }
    // If the user selected a return date, send it as the expiration_date.
    if (returnDate) {
      payload.expiration_date = returnDate;
    }
    const res = await fetch('../api/dispatch.php?resource=reservations', {
      method:'POST',
      headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': getCSRF() },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Request failed');
    msgEl.style.color = '#16a34a';
    msgEl.textContent = 'Your reservation request has been submitted. Staff will review it shortly.';
    document.getElementById('reqBookName').value = '';
    document.getElementById('reqBookId').value = '';
    document.getElementById('reqResDate').value = '';
    document.getElementById('reqReturnDate').value = '';
  } catch(e){
    msgEl.style.color = '#dc2626';
    msgEl.textContent = e.message;
  }
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>