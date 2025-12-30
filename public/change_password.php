<?php
// Separate page for changing the logged-in user's password.  This page
// mirrors the change password section of profile.php but provides a
// dedicated view per the requested profile sub-menu structure.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

// Set the active tab so that profile_details.php and other shared UI
// components can highlight the appropriate navigation link.  The
// variable is read in profile_details.php via $activeTab.
$activeTab = 'password';

include __DIR__ . '/_header.php';
?>

<h2>Change Password</h2>

<!-- Navigation tabs.  Replicate the profile navigation for consistency. -->
<div style="margin-bottom:16px; display:flex; gap:16px;">
    <a href="profile_details.php" style="text-decoration:none; padding:6px 12px; border-radius:6px;<?= $activeTab==='profile' ? 'background:#e5e7eb; font-weight:600;' : 'color:#2563eb;' ?>">Profile</a>
    <a href="change_password.php" style="text-decoration:none; padding:6px 12px; border-radius:6px;<?= $activeTab==='password' ? 'background:#e5e7eb; font-weight:600;' : 'color:#2563eb;' ?>">Change Password</a>
</div>

<div style="max-width:600px; background:#fff; padding:16px; border-radius:8px; border:1px solid #e5e7eb;">
    <form id="pwdForm">
        <label style="display:block; font-weight:600; margin-top:10px;">Current Password</label>
        <input id="curPwd" type="password" placeholder="Current password" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
        <label style="display:block; font-weight:600; margin-top:10px;">New Password</label>
        <input id="newPwd" type="password" placeholder="At least 8 characters" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
        <label style="display:block; font-weight:600; margin-top:10px;">Confirm Password</label>
        <input id="cnfPwd" type="password" placeholder="Confirm new password" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
        <button id="btnChangePwd" type="button" class="btn" style="margin-top:14px; background:#3b82f6;color:#fff;border:none;padding:10px 14px;border-radius:8px;">Update Password</button>
        <p id="pwdMsg" style="margin:6px 0 0; font-size:12px; color:#6b7280;">Enter your current password and your desired new password.</p>
    </form>
</div>

<script>
function getCSRF(){ return sessionStorage.getItem('csrf') || ''; }
document.getElementById('btnChangePwd').addEventListener('click', async ()=>{
  const cur = document.getElementById('curPwd').value;
  const neu = document.getElementById('newPwd').value;
  const cnf = document.getElementById('cnfPwd').value;
  const msg = document.getElementById('pwdMsg');
  msg.style.color = '#6b7280';
  msg.textContent = 'Updating...';
  try {
    const res = await fetch('../api/auth.php?action=change_password', {
      method:'POST',
      headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': getCSRF() },
      body: JSON.stringify({ current_password:cur, new_password:neu, confirm_password:cnf })
    });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || 'Update failed');
    msg.style.color = '#16a34a';
    msg.textContent = 'Password updated successfully.';
    document.getElementById('curPwd').value = '';
    document.getElementById('newPwd').value = '';
    document.getElementById('cnfPwd').value = '';
  } catch(err){
    msg.style.color = '#dc2626';
    msg.textContent = err.message;
  }
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>