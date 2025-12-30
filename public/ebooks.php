<?php
// E‑Books listing page
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
// Determine whether the current user is a student or non‑teaching staff.  If
// so, they must have an approved e‑book access request in order to view the
// list of available e‑books.  Administrators, librarians and other staff
// bypass this check and can always view the e‑books.
$pdo = DB::conn();
$user = current_user();
$isStudent = in_array($user['role'] ?? '', ['student','non_staff'], true);
$hasEbookAccess = true;
if ($isStudent) {
    $uname = $user['username'] ?? '';
    // Attempt to look up the most recent e-book access request for this user.  If the
    // ebook_requests table does not exist (for example, if migrations have not been
    // applied yet) or any error occurs, fall back to treating the user as having no
    // access and allow them to request access.  Wrapping the lookup in a try/catch
    // prevents fatal errors due to missing tables.
    try {
        $stmtChk = $pdo->prepare('SELECT status FROM ebook_requests WHERE username = :uname ORDER BY id DESC LIMIT 1');
        $stmtChk->execute([':uname' => $uname]);
        $row = $stmtChk->fetch();
        $hasEbookAccess = ($row && $row['status'] === 'approved');
    } catch (Throwable $e) {
        // Suppress the error and set hasEbookAccess to false; students will be prompted to request access
        $hasEbookAccess = false;
    }
}
// Always fetch the e‑books so that staff can view them and so that we
// avoid re‑running this query in the template.  Students will see the
// listing only if $hasEbookAccess is true.
$stmt = $pdo->query('SELECT e.id, e.file_path, e.file_format, b.title, b.available_copies FROM ebooks e JOIN books b ON e.book_id = b.id WHERE e.is_active = 1');
$ebooks = $stmt->fetchAll();
include '_header.php';
?>

<!-- Centre the heading similarly to the Request Book and Send Message pages -->
<h2 style="text-align:center;">E‑Books</h2>

<?php if ($isStudent && !$hasEbookAccess): ?>
    <div style="padding:16px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; max-width:600px; margin:12px auto 0 auto; text-align:center;">
        <p style="margin-bottom:12px;">You do not currently have access to the e‑books collection.  You must request permission from a librarian or administrator before you can view and download e‑books.</p>
        <div style="text-align:center;">
            <button id="requestEbookAccessBtn" style="background:#3b82f6; color:#fff; border:none; padding:8px 12px; border-radius:6px;">Request Access</button>
        </div>
        <p id="ebookReqMsg" style="margin-top:8px; font-size:12px; color:#6b7280; text-align:center;"></p>
    </div>
    <script>
    (function(){
      const btn = document.getElementById('requestEbookAccessBtn');
      const msg = document.getElementById('ebookReqMsg');
      if (btn) {
        btn.addEventListener('click', async function(){
          btn.disabled = true;
          msg.style.color = '#6b7280';
          msg.textContent = 'Submitting request...';
          try {
            const csrf = sessionStorage.getItem('csrf') || '';
            const res = await fetch('../api/dispatch.php?resource=ebook_requests', {
              method:'POST',
              headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({})
            });
            const out = await res.json();
            if (!res.ok) throw new Error(out.error || 'Request failed');
            msg.style.color = '#16a34a';
            msg.textContent = 'Your access request has been submitted.  Please wait for approval.';
          } catch(err) {
            msg.style.color = '#dc2626';
            msg.textContent = err.message;
          }
        });
      }
    })();
    </script>
<?php else: ?>
    <?php if ($ebooks): ?>
        <table style="width:100%; border-collapse:collapse; margin-top:12px;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Title</th>
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Format</th>
                    <th style="text-align:right; padding:8px; border-bottom:1px solid #e5e7eb;">Available</th>
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Download</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ebooks as $e): ?>
                <tr>
                    <td style="padding:8px; border-bottom:1px solid #e5e7eb;">
                        <?= htmlspecialchars($e['title']) ?>
                    </td>
                    <td style="padding:8px; border-bottom:1px solid #e5e7eb;">
                        <?= htmlspecialchars(strtoupper($e['file_format'])) ?>
                    </td>
                    <td style="padding:8px; border-bottom:1px solid #e5e7eb; text-align:right;">
                        <?= htmlspecialchars($e['available_copies'] ?? '') ?>
                    </td>
                    <td style="padding:8px; border-bottom:1px solid #e5e7eb;">
                        <a href="<?= htmlspecialchars(APP_BASE_URL . '/' . ltrim($e['file_path'], '/')) ?>" target="_blank" style="color:#3b82f6; text-decoration:underline;">Download</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No e‑books are currently available.</p>
    <?php endif; ?>
<?php endif; ?>

<?php include '_footer.php'; ?>