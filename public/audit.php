<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_role(['admin','librarian','assistant']);
$pdo = DB::conn();
$logs = $pdo->query('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
include __DIR__ . '/_header.php';
?>
<h2>
  <!-- Display the application logo next to the audit logs heading for branding -->
  <img src="<?= htmlspecialchars(APP_LOGO_URL) ?>" alt="Logo" style="height:32px; margin-right:10px; vertical-align:middle;" />
  <i class="fa fa-shield-halved" style="margin-right:8px; vertical-align:middle;"></i>
  Audit Logs
</h2>
<div style="overflow:auto; border:1px solid #e5e7eb; border-radius:8px; background:#fff;">
  <table style="width:100%; border-collapse:collapse;">
    <thead style="background:#f9fafb;">
      <tr>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Time</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">User</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Action</th>
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Entity</th>
        <!-- Device column replaces the Entity ID and Details columns -->
        <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb;">Device</th>
      </tr>
    </thead>
    <tbody id="logrows">
      <?php foreach ($logs as $l): ?>
        <tr>
          <td style="padding:8px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($l['created_at']) ?></td>
          <td style="padding:8px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($l['username'] ?: '-') ?></td>
          <td style="padding:8px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($l['action']) ?></td>
          <td style="padding:8px; border-bottom:1px solid #f3f4f6;"><?= htmlspecialchars($l['entity']) ?></td>
          <td style="padding:8px; border-bottom:1px solid #f3f4f6; font-family:monospace; font-size:12px; overflow-wrap:anywhere;"><?= htmlspecialchars($l['details']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
let lastSeen = 0;
const tbody = document.getElementById('logrows');
function addRow(l){
  const tr = document.createElement('tr');
  tr.innerHTML = `<td style="padding:8px; border-bottom:1px solid #f3f4f6;">${l.created_at}</td>
    <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(l.username || '-')}</td>
    <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(l.action)}</td>
    <td style="padding:8px; border-bottom:1px solid #f3f4f6;">${escapeHtml(l.entity)}</td>
    <td style="padding:8px; border-bottom:1px solid #f3f4f6; font-family:monospace; font-size:12px; overflow-wrap:anywhere;">${escapeHtml(l.details||'')}</td>`;
  tbody.prepend(tr);
}
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
async function poll(){
  try {
    const res = await fetch('../api/export.php?resource=audit_logs&format=json');
    if (!res.ok) return;
    const list = await res.json();
    list.forEach(l => { lastSeen = Math.max(lastSeen, l.id); });
    // Show newest first by prepending only newer than lastSeen already in DOM (basic approach omitted for brevity)
  } catch(e) {}
}
setInterval(poll, 8000);
</script>
<?php include __DIR__ . '/_footer.php'; ?>

