<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$u = current_user();
$pdo = DB::conn();
$twofa = (int)$pdo->prepare('SELECT twofa_enabled FROM users WHERE id = :id')->execute([':id'=>$u['id']]);
$stmt = $pdo->prepare('SELECT twofa_enabled FROM users WHERE id = :id');
$stmt->execute([':id'=>$u['id']]);
$twofaRow = $stmt->fetch();
$twofaEnabled = !empty($twofaRow['twofa_enabled']);

// Compute top user status and borrowing history for students/non-staff
$patronId = $u['patron_id'] ?? null;
$isTopUser = false;
$borrowHistory = [];
if ($patronId) {
    // Determine which patron has the highest number of borrow logs
    $stmtTop = $pdo->query('SELECT patron_id FROM borrow_logs GROUP BY patron_id ORDER BY COUNT(*) DESC LIMIT 1');
    $topPatronId = $stmtTop ? $stmtTop->fetchColumn() : null;
    if ($topPatronId && (int)$topPatronId === (int)$patronId) {
        $isTopUser = true;
    }
    // Fetch borrow history for this patron
    $stmtHist = $pdo->prepare('SELECT b.title, bl.borrowed_at, bl.due_date, bl.returned_at FROM borrow_logs bl JOIN books b ON bl.book_id = b.id WHERE bl.patron_id = :pat ORDER BY bl.borrowed_at DESC');
    $stmtHist->execute([':pat' => $patronId]);
    $borrowHistory = $stmtHist->fetchAll();
}
include __DIR__ . '/_header.php';
?>

<h2>My Profile</h2>

<div style="max-width:600px; background:#fff; padding:16px; border-radius:8px; border:1px solid #e5e7eb;">
    <form id="profileForm">
        <label style="display:block; font-weight:600; margin-top:10px;">Username</label>
        <input value="<?= htmlspecialchars($u['username']) ?>" disabled style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;" />

        <label style="display:block; font-weight:600; margin-top:10px;">Name</label>
        <input name="name" value="<?= htmlspecialchars($u['name'] ?? '') ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />

        <label style="display:block; font-weight:600; margin-top:10px;">Email</label>
        <input name="email" type="email" value="<?= htmlspecialchars($u['email'] ?? '') ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />

        <!-- Allow the user to edit their phone number.  Previously the profile
             page only displayed phone information in the read‑only section.
             Including this field ensures that when a user updates their
             phone number it is persisted to the users table and reflected
             immediately in their profile. -->
        <label style="display:block; font-weight:600; margin-top:10px;">Phone</label>
        <input name="phone" type="text" value="<?= htmlspecialchars($u['phone'] ?? '') ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />

        <!-- Address -->
        <!-- The profile form previously did not expose an address field.  As a result
             administrators and other users could not view or update their
             mailing address.  Include an address input bound to the
             `address` column on the users table so it persists across
             sessions. -->
        <label style="display:block; font-weight:600; margin-top:10px;">Address</label>
        <input name="address" type="text" value="<?= htmlspecialchars($u['address'] ?? '') ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />

        <div style="margin-top:16px; padding-top:12px; border-top:1px dashed #e5e7eb;">
            <label style="display:block; font-weight:800; margin-bottom:8px;">Change Password</label>
            <label style="display:block; font-weight:600; margin-top:10px;">Current Password</label>
            <div class="password-field">
                <input id="curPwd" type="password" placeholder="Current password" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
                <button type="button" class="toggle-eye" aria-label="Toggle current" onclick="togglePwd('curPwd', this)">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <label style="display:block; font-weight:600; margin-top:10px;">New Password</label>
            <div class="password-field">
                <input id="newPwd" type="password" placeholder="At least 8 characters" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
                <button type="button" class="toggle-eye" aria-label="Toggle new" onclick="togglePwd('newPwd', this)">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <label style="display:block; font-weight:600; margin-top:10px;">Confirm Password</label>
            <div class="password-field">
                <input id="cnfPwd" type="password" placeholder="Confirm new password" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
                <button type="button" class="toggle-eye" aria-label="Toggle confirm" onclick="togglePwd('cnfPwd', this)">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <button id="btnChangePwd" type="button" class="btn" style="margin-top:10px; background:#3b82f6;color:#fff;border:none;padding:10px 14px;border-radius:8px;">Update Password</button>
            <p id="pwdMsg" style="margin:6px 0 0; font-size:12px; color:#6b7280;">For security, enter your current password.</p>
        </div>

        <div style="margin-top:12px;">
            <button class="btn" style="background:#111827;color:#fff;border:none;padding:10px 14px;border-radius:8px;">Save</button>
        </div>
    </form>
</div>

<script>
function getCSRF(){ return sessionStorage.getItem('csrf') || ''; }
document.getElementById('profileForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const data = {};
  new FormData(e.target).forEach((v,k)=> data[k]=v);
  try {
    const meRes = await fetch('../api/auth.php');
    const me = await meRes.json();
    if (!me.user) throw new Error('Not authenticated');
    const id = me.user.id;
    const res = await fetch(`../api/dispatch.php?resource=users&id=${id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCSRF() },
      body: JSON.stringify(data)
    });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || 'Update failed');
    alert('Profile updated');
    // refresh session info
    location.reload();
  } catch(err) {
    alert(err.message);
  }
});

// Password change flow
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

function togglePwd(id, btn){
  const el = document.getElementById(id);
  const show = el.type === 'password';
  el.type = show ? 'text' : 'password';
  btn.setAttribute('aria-pressed', show ? 'true' : 'false');
}
</script>

<div style="height:16px"></div>

<div style="max-width:600px; background:#fff; padding:16px; border-radius:8px; border:1px solid #e5e7eb;">
  <h3 style="margin-top:0;">Two-Factor Authentication (2FA)</h3>
  <p>Status: <strong id="twofaStatus"><?= $twofaEnabled ? 'Enabled' : 'Disabled' ?></strong></p>
  <div style="display:flex; gap:8px; align-items:center;">
    <button id="btnTwofaEnable" class="btn" style="display:<?= $twofaEnabled ? 'none' : 'inline-block' ?>; background:#111827;color:#fff;border:none;padding:10px 14px;border-radius:8px;">Enable 2FA</button>
    <button id="btnTwofaDisable" class="btn" style="display:<?= $twofaEnabled ? 'inline-block' : 'none' ?>; background:#ef4444;color:#fff;border:none;padding:10px 14px;border-radius:8px;">Disable 2FA</button>
  </div>
  <div id="twofaVerify" style="display:none; margin-top:12px;">
    <label style="display:block; font-weight:600;">Enter verification code sent to your email</label>
    <input id="twofaCode" class="input" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" placeholder="123456" />
    <div style="margin-top:8px; display:flex; gap:8px;">
      <button id="btnTwofaConfirm" class="btn" style="background:#10b981;color:#fff;border:none;padding:10px 14px;border-radius:8px;">Confirm</button>
      <button id="btnTwofaCancel" class="btn" style="background:#e5e7eb;color:#111827;border:none;padding:10px 14px;border-radius:8px;">Cancel</button>
    </div>
  </div>
</script>

<script>
const twofaStatus = document.getElementById('twofaStatus');
const btnEnable = document.getElementById('btnTwofaEnable');
const btnDisable = document.getElementById('btnTwofaDisable');
const box = document.getElementById('twofaVerify');
const codeEl = document.getElementById('twofaCode');
let mode = null;

async function requestSetup(m){
  await fetch(`../api/twofa.php?action=request_setup&mode=${m}`, { method:'POST', headers:{ 'X-CSRF-Token': getCSRF() } });
}

btnEnable && btnEnable.addEventListener('click', async ()=>{
  mode = 'enable';
  await requestSetup('enable');
  box.style.display='block';
});
btnDisable && btnDisable.addEventListener('click', async ()=>{
  mode = 'disable';
  await requestSetup('disable');
  box.style.display='block';
});
document.getElementById('btnTwofaConfirm').addEventListener('click', async ()=>{
  const code = codeEl.value.trim();
  if (!code) return;
  const res = await fetch(`../api/twofa.php?action=${mode}`, { method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': getCSRF() }, body: JSON.stringify({ code })});
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Failed'); return; }
  if (mode==='enable') { twofaStatus.textContent='Enabled'; btnEnable.style.display='none'; btnDisable.style.display='inline-block'; }
  else { twofaStatus.textContent='Disabled'; btnDisable.style.display='none'; btnEnable.style.display='inline-block'; }
  codeEl.value=''; box.style.display='none';
});
document.getElementById('btnTwofaCancel').addEventListener('click', ()=>{ codeEl.value=''; box.style.display='none'; });
</script>

<!-- Usage summary and borrowing history -->
<div style="height:16px"></div>
<div style="max-width:600px; background:#fff; padding:16px; border-radius:8px; border:1px solid #e5e7eb; margin-bottom:16px;">
  <h3 style="margin-top:0;">Usage Summary</h3>
  <p>Status: <strong><?= $isTopUser ? 'Top User' : 'Regular User' ?></strong></p>
  <?php if ($borrowHistory): ?>
    <h4 style="margin-top:12px;">Borrow History</h4>
    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="background:#f9fafb;">
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Title</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Borrowed At</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Due Date</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Returned At</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($borrowHistory as $h): ?>
          <tr>
            <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars($h['title']) ?></td>
            <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars(date('Y-m-d', strtotime($h['borrowed_at']))) ?></td>
            <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars(date('Y-m-d', strtotime($h['due_date']))) ?></td>
            <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= $h['returned_at'] ? htmlspecialchars(date('Y-m-d', strtotime($h['returned_at']))) : '-' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p>No borrowing history.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
