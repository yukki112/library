<?php
// Profile details page. Displays the current logged-in user's basic information.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
// Fetch the current user and any extended profile details.  For students
// and non‑staff, the phone, address, semester and department may be stored
// across both the users and patrons tables.  This logic retrieves those
// values with a single query.  We fallback to the patron address if the
// user address is not set.
$user = current_user();
$pdo = DB::conn();
$details = [];
if ($user) {
    // Load extended profile details for the current user.  Older database
    // schemas may lack the semester, department or address columns on the
    // patrons table.  Referencing such columns unconditionally leads to
    // SQLSTATE[42S22] unknown column errors.  Inspect the schema at runtime
    // and build the SELECT clause accordingly.  When a column is absent,
    // substitute a NULL alias to maintain consistent array keys.
    $hasSemester = $hasDepartment = $hasAddress = false;
    try {
        $colStmt = $pdo->prepare(
            "SELECT column_name FROM information_schema.columns " .
            "WHERE table_schema = DATABASE() AND table_name = 'patrons' " .
            "AND column_name IN ('semester','department','address')"
        );
        $colStmt->execute();
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $hasSemester = in_array('semester', $cols, true);
        $hasDepartment = in_array('department', $cols, true);
        $hasAddress = in_array('address', $cols, true);
    } catch (PDOException $e) {
        // On failure assume columns are missing.
        $hasSemester = $hasDepartment = $hasAddress = false;
    }
    // Build select fields.  Always include user phone.  Include semester,
    // department and address from patrons when available; otherwise select
    // NULL with appropriate alias.
    $fields = 'u.phone AS user_phone';
    $fields .= $hasSemester ? ', p.semester' : ', NULL AS semester';
    $fields .= $hasDepartment ? ', p.department' : ', NULL AS department';
    $fields .= $hasAddress ? ', p.address AS patron_address' : ', NULL AS patron_address';
    $sql = 'SELECT ' . $fields . ' FROM users u LEFT JOIN patrons p ON u.patron_id = p.id WHERE u.id = :uid';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $user['id']]);
    $details = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
include __DIR__ . '/_header.php';

// Determine active tab for highlighting.  When this page is loaded the
// active tab is "profile".  change_password.php sets this variable to
// "password" to highlight accordingly.
$activeTab = $activeTab ?? 'profile';
?>

<h2>Profile</h2>

<!-- Profile navigation tabs.  Provide links to the profile details and change
     password pages.  The active tab is highlighted via a simple inline
     style; additional styling can be applied in the main stylesheet. -->
<div style="margin-bottom:16px; display:flex; gap:16px;">
    <a href="profile_details.php" style="text-decoration:none; padding:6px 12px; border-radius:6px;<?= $activeTab==='profile' ? 'background:#e5e7eb; font-weight:600;' : 'color:#2563eb;' ?>">Profile</a>
    <a href="change_password.php" style="text-decoration:none; padding:6px 12px; border-radius:6px;<?= $activeTab==='password' ? 'background:#e5e7eb; font-weight:600;' : 'color:#2563eb;' ?>">Change Password</a>
</div>

<div class="table-container" style="max-width:600px;">
    <table class="data-table">
        <tbody>
            <tr>
                <th style="width:30%;">Name</th>
                <td><?= htmlspecialchars($user['name'] ?? '') ?></td>
            </tr>
            <tr>
                <th>Username</th>
                <td><?= htmlspecialchars($user['username'] ?? '') ?></td>
            </tr>
            <tr>
                <th>Email</th>
                <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
            </tr>
            <tr>
                <th>Phone Number</th>
                <td><?= htmlspecialchars(($details['user_phone'] ?? '') ?: ($user['phone'] ?? '')) ?></td>
            </tr>
            <tr>
                <th>Address</th>
                <?php
                // Display the address associated with this user.  Because the users table may
                // not contain an address column in some deployments, the value loaded from
                // the patrons table (`patron_address`) is used exclusively.  If no address
                // exists on the patron record, show "N/A" to the user.
                $displayAddress = $details['patron_address'] ?? null;
                ?>
                <td><?= htmlspecialchars($displayAddress ?: 'N/A') ?></td>
            </tr>
            <?php if (!empty($details['semester'])): ?>
            <tr>
                <th>Semester</th>
                <td><?= htmlspecialchars($details['semester']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($details['department'])): ?>
            <tr>
                <th>Department</th>
                <td><?= htmlspecialchars($details['department']) ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Edit button and form.  The form remains hidden until the user
     selects Edit.  It allows updating of basic profile fields (name,
     email, phone, address, semester, department). -->
<div style="max-width:600px; margin-top:16px;">
    <button id="editProfileBtn" class="btn" style="background:#111827; color:#fff; border:none; padding:10px 14px; border-radius:8px;">Edit Profile</button>
    <form id="editProfileForm" style="display:none; margin-top:12px; background:#fff; padding:16px; border:1px solid #e5e7eb; border-radius:8px;">
        <input type="hidden" id="editUserId" value="<?= (int)($user['id'] ?? 0) ?>" />
        <input type="hidden" id="editPatronId" value="<?= (int)($user['patron_id'] ?? 0) ?>" />
        <div style="margin-bottom:8px;">
            <label style="display:block; font-weight:600;">Name</label>
            <input type="text" id="editName" class="input" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" value="<?= htmlspecialchars($user['name'] ?? '') ?>" />
        </div>
        <div style="margin-bottom:8px;">
            <label style="display:block; font-weight:600;">Email</label>
            <input type="email" id="editEmail" class="input" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" value="<?= htmlspecialchars($user['email'] ?? '') ?>" />
        </div>
        <div style="margin-bottom:8px;">
            <label style="display:block; font-weight:600;">Phone</label>
            <input type="text" id="editPhone" class="input" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" value="<?= htmlspecialchars(($details['user_phone'] ?? '') ?: ($user['phone'] ?? '')) ?>" />
        </div>
        <div style="margin-bottom:8px;">
            <label style="display:block; font-weight:600;">Address</label>
            <input type="text" id="editAddress" class="input" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" value="<?= htmlspecialchars($details['patron_address'] ?? '') ?>" />
        </div>
        <div style="margin-bottom:8px;">
            <label style="display:block; font-weight:600;">Semester</label>
            <input type="text" id="editSemester" class="input" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" value="<?= htmlspecialchars($details['semester'] ?? '') ?>" />
        </div>
        <div style="margin-bottom:8px;">
            <label style="display:block; font-weight:600;">Department</label>
            <input type="text" id="editDepartment" class="input" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" value="<?= htmlspecialchars($details['department'] ?? '') ?>" />
        </div>
        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
            <button type="button" id="cancelEdit" class="btn" style="background:#e5e7eb; color:#374151; border:none; padding:8px 12px; border-radius:8px;">Back</button>
            <button type="button" id="saveEdit" class="btn" style="background:#3b82f6; color:#fff; border:none; padding:8px 12px; border-radius:8px;">Save</button>
        </div>
    </form>
</div>

<script>
// Toggle edit form visibility
document.getElementById('editProfileBtn').addEventListener('click', () => {
    document.getElementById('editProfileForm').style.display = 'block';
    document.getElementById('editProfileBtn').style.display = 'none';
});
document.getElementById('cancelEdit').addEventListener('click', () => {
    document.getElementById('editProfileForm').style.display = 'none';
    document.getElementById('editProfileBtn').style.display = 'inline-block';
});

async function updateProfile(){
    const uid = document.getElementById('editUserId').value;
    const pid = document.getElementById('editPatronId').value;
    const name = document.getElementById('editName').value.trim();
    const email = document.getElementById('editEmail').value.trim();
    const phone = document.getElementById('editPhone').value.trim();
    const address = document.getElementById('editAddress').value.trim();
    const semester = document.getElementById('editSemester').value.trim();
    const department = document.getElementById('editDepartment').value.trim();
    const csrf = sessionStorage.getItem('csrf') || '';
    try {
        // Update users table
        await fetch('../api/dispatch.php?resource=users&id=' + uid, {
            method:'PUT',
            credentials:'same-origin',
            headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ name:name, email:email, phone:phone })
        });
        // Update patrons table if patronId exists
        if (pid && pid !== '0') {
            await fetch('../api/dispatch.php?resource=patrons&id=' + pid, {
                method:'PUT',
                credentials:'same-origin',
                headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ address: address, semester: semester, department: department })
            });
        }
        alert('Profile updated successfully');
        location.reload();
    } catch(err) {
        alert(err.message || 'Failed to update profile');
    }
}

document.getElementById('saveEdit').addEventListener('click', updateProfile);
</script>

<?php include __DIR__ . '/_footer.php'; ?>