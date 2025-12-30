<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_role(['admin']);
include __DIR__ . '/_header.php';
?>
<h2>System Settings</h2>
<div style="max-width:600px; background:#fff; padding:16px; border-radius:8px; border:1px solid #e5e7eb;">
  <form id="settingsForm">
    <label style="display:block;font-weight:600;">Borrow period (days)</label>
    <input class="input" id="borrow_period_days" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" type="number" min="1" />

    <label style="display:block;font-weight:600; margin-top:10px;">Late fee per day</label>
    <input class="input" id="late_fee_per_day" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" type="number" step="0.01" />

    <label style="display:block;font-weight:600; margin-top:10px;">Damage fee (minor)</label>
    <input class="input" id="fee_minor" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" type="number" step="0.01" />

    <label style="display:block;font-weight:600; margin-top:10px;">Damage fee (moderate)</label>
    <input class="input" id="fee_moderate" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" type="number" step="0.01" />

    <label style="display:block;font-weight:600; margin-top:10px;">Damage fee (severe)</label>
    <input class="input" id="fee_severe" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" type="number" step="0.01" />

    <div style="margin-top:12px;">
      <button class="btn" style="background:#111827;color:#fff;border:none;padding:10px 14px;border-radius:8px;">Save</button>
    </div>
  </form>
</div>

<script>
function getCSRF(){ return sessionStorage.getItem('csrf') || ''; }
async function load(){
  const res = await fetch('../api/settings.php');
  const s = await res.json();
  ['borrow_period_days','late_fee_per_day','fee_minor','fee_moderate','fee_severe'].forEach(k=>{
    const el = document.getElementById(k); if (el && s[k] != null) el.value = s[k];
  });
}
document.getElementById('settingsForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const body = {};
  ['borrow_period_days','late_fee_per_day','fee_minor','fee_moderate','fee_severe'].forEach(k=>{ const el = document.getElementById(k); if (el) body[k]=el.value; });
  const res = await fetch('../api/settings.php', { method:'PUT', headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': getCSRF() }, body: JSON.stringify(body) });
  const out = await res.json();
  if (!res.ok) { alert(out.error || 'Save failed'); return; }
  alert('Settings saved');
});
load();
</script>

<?php include __DIR__ . '/_footer.php'; ?>

