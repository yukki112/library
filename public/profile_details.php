<?php
// Profile details page. Displays the current logged-in user's basic information.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

// Fetch the current user and any extended profile details.
$user = current_user();
$pdo = DB::conn();
$details = [];
if ($user) {
    // Load extended profile details for the current user.
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
        $hasSemester = $hasDepartment = $hasAddress = false;
    }
    
    $fields = 'u.phone AS user_phone';
    $fields .= $hasSemester ? ', p.semester' : ', NULL AS semester';
    $fields .= $hasDepartment ? ', p.department' : ', NULL AS department';
    $fields .= $hasAddress ? ', p.address AS patron_address' : ', NULL AS patron_address';
    
    $sql = 'SELECT ' . $fields . ' FROM users u LEFT JOIN patrons p ON u.patron_id = p.id WHERE u.id = :uid';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $user['id']]);
    $details = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Determine active tab
$activeTab = $activeTab ?? 'profile';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Library System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .profile-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 24px;
        }

        .profile-header {
            margin-bottom: 32px;
        }

        .profile-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }

        .profile-header p {
            color: #6b7280;
            font-size: 16px;
        }

        .profile-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 32px;
        }

        @media (max-width: 768px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
        }

        .profile-sidebar {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            height: fit-content;
        }

        .profile-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .profile-nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            text-decoration: none;
            color: #4b5563;
            border-radius: 8px;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .profile-nav-item:hover {
            background-color: #f3f4f6;
            color: #111827;
        }

        .profile-nav-item.active {
            background-color: #eff6ff;
            color: #2563eb;
            border-left: 3px solid #2563eb;
        }

        .profile-nav-item i {
            width: 20px;
            text-align: center;
            font-style: normal;
            font-weight: 600;
        }

        .profile-content {
            background: #fff;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        .content-header h2 {
            font-size: 24px;
            font-weight: 600;
            color: #1f2937;
        }

        .edit-btn {
            background: #111827;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .edit-btn:hover {
            background: #374151;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        }

        .profile-card {
            background: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #e5e7eb;
        }

        .profile-card h3 {
            font-size: 14px;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.05em;
            margin-bottom: 12px;
        }

        .profile-field {
            margin-bottom: 16px;
        }

        .profile-field:last-child {
            margin-bottom: 0;
        }

        .field-label {
            display: block;
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .field-value {
            font-size: 16px;
            color: #1f2937;
            font-weight: 500;
            word-break: break-word;
        }

        .field-value.empty {
            color: #9ca3af;
            font-style: italic;
        }

        .edit-form {
            background: #f9fafb;
            border-radius: 12px;
            padding: 32px;
            margin-top: 32px;
            border: 1px solid #e5e7eb;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #6b7280;
        }

        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }

        .message.success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .message.error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/_header.php'; ?>

    <div class="profile-container">
        <div class="profile-header">
            <h1>User Profile</h1>
            <p>Manage your account information and settings</p>
        </div>

        <div class="profile-layout">
            <div class="profile-sidebar">
                <div class="profile-nav">
                    <a href="profile_details.php" class="profile-nav-item <?= $activeTab === 'profile' ? 'active' : '' ?>">
                        <i>👤</i>
                        <span>Profile Information</span>
                    </a>
                    <a href="change_password.php" class="profile-nav-item <?= $activeTab === 'password' ? 'active' : '' ?>">
                        <i>🔒</i>
                        <span>Change Password</span>
                    </a>
                </div>
            </div>

            <div class="profile-content">
                <div class="content-header">
                    <h2>Personal Information</h2>
                    <button id="editProfileBtn" class="edit-btn">Edit Profile</button>
                </div>

                <div class="message" id="message"></div>

                <div class="profile-grid">
                    <div class="profile-card">
                        <h3>Basic Information</h3>
                        <div class="profile-field">
                            <span class="field-label">Full Name</span>
                            <div class="field-value"><?= htmlspecialchars($user['name'] ?? 'Not set') ?></div>
                        </div>
                        <div class="profile-field">
                            <span class="field-label">Username</span>
                            <div class="field-value"><?= htmlspecialchars($user['username'] ?? 'Not set') ?></div>
                        </div>
                        <div class="profile-field">
                            <span class="field-label">Email Address</span>
                            <div class="field-value"><?= htmlspecialchars($user['email'] ?? 'Not set') ?></div>
                        </div>
                    </div>

                    <div class="profile-card">
                        <h3>Contact Details</h3>
                        <div class="profile-field">
                            <span class="field-label">Phone Number</span>
                            <div class="field-value <?= empty(($details['user_phone'] ?? '') ?: ($user['phone'] ?? '')) ? 'empty' : '' ?>">
                                <?= htmlspecialchars(($details['user_phone'] ?? '') ?: ($user['phone'] ?? '') ?: 'Not provided') ?>
                            </div>
                        </div>
                        <div class="profile-field">
                            <span class="field-label">Address</span>
                            <div class="field-value <?= empty($details['patron_address'] ?? '') ? 'empty' : '' ?>">
                                <?= htmlspecialchars($details['patron_address'] ?? 'Not provided') ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($details['semester']) || !empty($details['department'])): ?>
                    <div class="profile-card">
                        <h3>Academic Information</h3>
                        <?php if (!empty($details['semester'])): ?>
                        <div class="profile-field">
                            <span class="field-label">Semester</span>
                            <div class="field-value"><?= htmlspecialchars($details['semester']) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($details['department'])): ?>
                        <div class="profile-field">
                            <span class="field-label">Department</span>
                            <div class="field-value"><?= htmlspecialchars($details['department']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <form id="editProfileForm" class="edit-form" style="display: none;">
                    <h3 style="margin-bottom: 24px; color: #1f2937; font-size: 18px;">Edit Profile Information</h3>
                    
                    <input type="hidden" id="editUserId" value="<?= (int)($user['id'] ?? 0) ?>" />
                    <input type="hidden" id="editPatronId" value="<?= (int)($user['patron_id'] ?? 0) ?>" />
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="editName">Full Name *</label>
                            <input type="text" id="editName" class="form-input" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="editEmail">Email Address *</label>
                            <input type="email" id="editEmail" class="form-input" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="editPhone">Phone Number</label>
                            <input type="text" id="editPhone" class="form-input" value="<?= htmlspecialchars(($details['user_phone'] ?? '') ?: ($user['phone'] ?? '')) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="editAddress">Address</label>
                            <input type="text" id="editAddress" class="form-input" value="<?= htmlspecialchars($details['patron_address'] ?? '') ?>">
                        </div>
                        
                        <?php if ($hasSemester): ?>
                        <div class="form-group">
                            <label for="editSemester">Semester</label>
                            <input type="text" id="editSemester" class="form-input" value="<?= htmlspecialchars($details['semester'] ?? '') ?>">
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($hasDepartment): ?>
                        <div class="form-group">
                            <label for="editDepartment">Department</label>
                            <input type="text" id="editDepartment" class="form-input" value="<?= htmlspecialchars($details['department'] ?? '') ?>">
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" id="cancelEdit" class="btn-secondary">Cancel</button>
                        <button type="button" id="saveEdit" class="btn-primary">Save Changes</button>
                    </div>
                </form>

                <div class="loading" id="loading">
                    Updating profile information...
                </div>
            </div>
        </div>
    </div>

    <script>
    // Form handling
    document.getElementById('editProfileBtn').addEventListener('click', () => {
        document.getElementById('editProfileForm').style.display = 'block';
        document.getElementById('editProfileBtn').style.display = 'none';
    });

    document.getElementById('cancelEdit').addEventListener('click', () => {
        document.getElementById('editProfileForm').style.display = 'none';
        document.getElementById('editProfileBtn').style.display = 'block';
        document.getElementById('message').style.display = 'none';
    });

    function showMessage(type, text) {
        const messageEl = document.getElementById('message');
        messageEl.className = `message ${type}`;
        messageEl.textContent = text;
        messageEl.style.display = 'block';
        
        setTimeout(() => {
            if (type === 'success') {
                messageEl.style.display = 'none';
            }
        }, 3000);
    }

    async function updateProfile() {
        const uid = document.getElementById('editUserId').value;
        const pid = document.getElementById('editPatronId').value;
        const name = document.getElementById('editName').value.trim();
        const email = document.getElementById('editEmail').value.trim();
        const phone = document.getElementById('editPhone').value.trim();
        const address = document.getElementById('editAddress').value.trim();
        const semester = document.getElementById('editSemester') ? document.getElementById('editSemester').value.trim() : '';
        const department = document.getElementById('editDepartment') ? document.getElementById('editDepartment').value.trim() : '';
        const csrf = sessionStorage.getItem('csrf') || '';

        // Basic validation
        if (!name || !email) {
            showMessage('error', 'Name and email are required');
            return;
        }

        const loadingEl = document.getElementById('loading');
        const saveBtn = document.getElementById('saveEdit');
        
        loadingEl.style.display = 'block';
        saveBtn.disabled = true;

        try {
            // Update users table
            const userResponse = await fetch('../api/dispatch.php?resource=users&id=' + uid, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 
                    'Content-Type': 'application/json', 
                    'X-CSRF-Token': csrf 
                },
                body: JSON.stringify({ 
                    name: name, 
                    email: email, 
                    phone: phone 
                })
            });

            if (!userResponse.ok) {
                throw new Error('Failed to update user information');
            }

            // Update patrons table if patronId exists
            if (pid && pid !== '0') {
                const patronData = { address: address };
                if (semester) patronData.semester = semester;
                if (department) patronData.department = department;

                const patronResponse = await fetch('../api/dispatch.php?resource=patrons&id=' + pid, {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: { 
                        'Content-Type': 'application/json', 
                        'X-CSRF-Token': csrf 
                    },
                    body: JSON.stringify(patronData)
                });

                if (!patronResponse.ok) {
                    throw new Error('Failed to update patron information');
                }
            }

            showMessage('success', 'Profile updated successfully');
            
            // Refresh page after a short delay
            setTimeout(() => {
                location.reload();
            }, 1500);

        } catch (err) {
            showMessage('error', err.message || 'Failed to update profile');
            console.error('Update error:', err);
        } finally {
            loadingEl.style.display = 'none';
            saveBtn.disabled = false;
        }
    }

    document.getElementById('saveEdit').addEventListener('click', updateProfile);

    // Allow form submission with Enter key
    document.getElementById('editProfileForm').addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            updateProfile();
        }
    });
    </script>

    <?php include __DIR__ . '/_footer.php'; ?>
</body>
</html>