<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$activeTab = 'password';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Library System</title>
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
            margin-bottom: 32px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        .content-header h2 {
            font-size: 24px;
            font-weight: 600;
            color: #1f2937;
        }

        .password-form {
            max-width: 500px;
        }

        .form-group {
            margin-bottom: 24px;
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
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .password-strength {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .strength-meter {
            height: 100%;
            width: 0%;
            background: #dc2626;
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .form-actions {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s ease;
            font-size: 14px;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-primary:disabled {
            background: #93c5fd;
            cursor: not-allowed;
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

        .hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/_header.php'; ?>

    <div class="profile-container">
        <div class="profile-header">
            <h1>Change Password</h1>
            <p>Update your account security settings</p>
        </div>

        <div class="profile-layout">
            <div class="profile-sidebar">
                <div class="profile-nav">
                    <a href="profile_details.php" class="profile-nav-item ">
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
                    <h2>Update Password</h2>
                </div>

                <div class="message" id="message"></div>

                <div class="password-form">
                    <form id="pwdForm">
                        <div class="form-group">
                            <label for="curPwd">Current Password</label>
                            <input id="curPwd" type="password" class="form-input" 
                                   placeholder="Enter your current password" required>
                        </div>

                        <div class="form-group">
                            <label for="newPwd">New Password</label>
                            <input id="newPwd" type="password" class="form-input" 
                                   placeholder="At least 8 characters" required
                                   oninput="updatePasswordStrength(this.value)">
                            <div class="password-strength">
                                <div class="strength-meter" id="strengthMeter"></div>
                            </div>
                            <div class="hint">
                                Password must be at least 8 characters long
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="cnfPwd">Confirm New Password</label>
                            <input id="cnfPwd" type="password" class="form-input" 
                                   placeholder="Re-enter new password" required>
                        </div>

                        <div class="form-actions">
                            <button id="btnChangePwd" type="button" class="btn-primary">
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    function getCSRF() { 
        return sessionStorage.getItem('csrf') || ''; 
    }

    function updatePasswordStrength(password) {
        const meter = document.getElementById('strengthMeter');
        if (!password) {
            meter.style.width = '0%';
            meter.style.background = '#dc2626';
            return;
        }

        let strength = 0;
        if (password.length >= 8) strength += 25;
        if (/[A-Z]/.test(password)) strength += 25;
        if (/[0-9]/.test(password)) strength += 25;
        if (/[^A-Za-z0-9]/.test(password)) strength += 25;

        meter.style.width = strength + '%';
        
        if (strength < 50) {
            meter.style.background = '#dc2626';
        } else if (strength < 75) {
            meter.style.background = '#f59e0b';
        } else {
            meter.style.background = '#10b981';
        }
    }

    function showMessage(type, text) {
        const messageEl = document.getElementById('message');
        messageEl.className = `message ${type}`;
        messageEl.textContent = text;
        messageEl.style.display = 'block';
        
        setTimeout(() => {
            if (type === 'success') {
                messageEl.style.display = 'none';
            }
        }, 5000);
    }

    document.getElementById('btnChangePwd').addEventListener('click', async () => {
        const cur = document.getElementById('curPwd').value;
        const neu = document.getElementById('newPwd').value;
        const cnf = document.getElementById('cnfPwd').value;
        const button = document.getElementById('btnChangePwd');

        // Validation
        if (!cur || !neu || !cnf) {
            showMessage('error', 'Please fill in all password fields');
            return;
        }

        if (neu !== cnf) {
            showMessage('error', 'New passwords do not match');
            return;
        }

        if (neu.length < 8) {
            showMessage('error', 'New password must be at least 8 characters long');
            return;
        }

        if (cur === neu) {
            showMessage('error', 'New password must be different from current password');
            return;
        }

        // Disable button and show loading state
        const originalText = button.textContent;
        button.textContent = 'Updating...';
        button.disabled = true;

        try {
            const res = await fetch('../api/auth.php?action=change_password', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json', 
                    'X-CSRF-Token': getCSRF() 
                },
                body: JSON.stringify({ 
                    current_password: cur, 
                    new_password: neu, 
                    confirm_password: cnf 
                })
            });

            const out = await res.json();
            
            if (!res.ok) {
                throw new Error(out.error || 'Password update failed');
            }

            showMessage('success', 'Password updated successfully');
            
            // Clear form
            document.getElementById('curPwd').value = '';
            document.getElementById('newPwd').value = '';
            document.getElementById('cnfPwd').value = '';
            document.getElementById('strengthMeter').style.width = '0%';

        } catch (err) {
            showMessage('error', err.message || 'Failed to update password. Please try again.');
            console.error('Password change error:', err);
        } finally {
            // Restore button state
            button.textContent = originalText;
            button.disabled = false;
        }
    });

    // Allow form submission with Enter key
    document.getElementById('pwdForm').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('btnChangePwd').click();
        }
    });
    </script>

    <?php include __DIR__ . '/_footer.php'; ?>
</body>
</html>