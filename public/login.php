<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

start_app_session();
if (is_logged_in()) {
    header('Location: ' . APP_BASE_URL . '/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="styles/main.css" />
    <style>
        /* Additional inline styles for any immediate improvements */
        .login-form {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-group input::placeholder {
            color: #9ca3af;
            transition: color 0.3s ease;
        }
        
        .form-group input:focus::placeholder {
            color: #6b7280;
        }
        
        .password-hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
            display: block;
        }
        
        .student-only {
            background-color: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 12px;
            margin: 16px 0;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #fff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="login-container">
    <form class="login-card login-form" onsubmit="return false;">
        <div class="login-header">
            <div class="login-icon">
                <img src="<?= htmlspecialchars(APP_LOGO_URL) ?>" alt="logo" onerror="this.style.display='none'" />
            </div>
            <h1><?= htmlspecialchars(APP_NAME) ?></h1>
            <p class="login-subtitle">Secure Access Portal</p>
        </div>

        <div class="login-tabs">
            <button type="button" id="tab-staff" class="login-tab" onclick="setTab('staff')">
                <span>Staff Login</span>
            </button>
            <button type="button" id="tab-student" class="login-tab" onclick="setTab('student')">
                <span>Student / Non‑Staff</span>
            </button>
        </div>

        <div id="error" class="login-error"></div>

        <div class="form-group">
            <label for="username" id="username-label">Username</label>
            <input id="username" autocomplete="username" required placeholder="Enter your username" />
            <span id="username-hint" class="password-hint" style="display: none;">Use your Student ID for student login</span>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <div class="password-field">
                <input id="password" type="password" autocomplete="current-password" required placeholder="Enter your password" />
                <button type="button" class="toggle-eye" aria-label="Toggle password" onclick="togglePassword('password', this)" title="Show/Hide">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <span id="password-hint" class="password-hint" style="display: none;">Default password: 0000 (Change after first login)</span>
        </div>

        

        <div class="checkbox-item" id="rememberRow" style="display:none;">
            <input id="remember" type="checkbox" />
            <label for="remember">Remember me for 30 days</label>
        </div>

        <button class="btn btn-primary btn-full" type="button" onclick="handleLogin()" id="loginBtn">
            <span id="btnText">Sign In</span>
            <span id="btnLoader" style="display:none;" class="loading"></span>
        </button>

        <div id="twofa" class="form-group" style="display:none; margin-top:8px;">
            <label for="code">Enter 2FA code sent to your email</label>
            <input id="code" placeholder="123456" maxlength="6" pattern="[0-9]*" inputmode="numeric" />
            <div class="mt-4">
                <button class="btn btn-primary btn-full" type="button" onclick="verify2fa()" id="verifyBtn">
                    <span id="verifyText">Verify & Continue</span>
                    <span id="verifyLoader" style="display:none;" class="loading"></span>
                </button>
            </div>
        </div>

        <div class="login-footer">
            <span>Need help? <a href="mailto:support@<?= htmlspecialchars(strtolower(APP_NAME)) ?>.com">Contact Support</a></span>
        </div>
    </form>

    <script>
        let currentTab = (new URLSearchParams(location.search).get('tab')) || 'staff';
        let pendingUserId = null;
        
        function setTab(tab){
            currentTab = tab;
            document.getElementById('tab-staff').classList.toggle('active', tab==='staff');
            document.getElementById('tab-student').classList.toggle('active', tab==='student');
            document.getElementById('rememberRow').style.display = (tab==='student') ? 'flex' : 'none';
            document.getElementById('student-info').style.display = (tab==='student') ? 'block' : 'none';
            document.getElementById('username-hint').style.display = (tab==='student') ? 'block' : 'none';
            document.getElementById('password-hint').style.display = (tab==='student') ? 'block' : 'none';
            
            // Update label
            const label = document.getElementById('username-label');
            label.textContent = (tab==='student') ? 'Student ID' : 'Username';
            
            // Update placeholder
            const input = document.getElementById('username');
            input.placeholder = (tab==='student') ? 'Enter your Student ID' : 'Enter your username';
        }
        
        async function handleLogin() {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const remember = (currentTab==='student') && document.getElementById('remember').checked;
            const err = document.getElementById('error');
            const loginBtn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btnText');
            const btnLoader = document.getElementById('btnLoader');
            const twofaSection = document.getElementById('twofa');
            
            err.style.display = 'none';
            twofaSection.style.display = 'none';
            
            if (!username || !password) {
                showError('Please enter both username and password');
                return;
            }
            
            // Show loading
            btnText.style.display = 'none';
            btnLoader.style.display = 'inline-block';
            loginBtn.disabled = true;
            
            try {
                if (currentTab === 'student') {
                    // For students, first try to sync/check from HR API
                    await handleStudentLogin(username, password, remember);
                } else {
                    // For staff, direct login
                    await handleStaffLogin(username, password, remember);
                }
            } catch (error) {
                showError(error.message);
            } finally {
                // Reset button
                btnText.style.display = 'inline';
                btnLoader.style.display = 'none';
                loginBtn.disabled = false;
            }
        }
        
        async function handleStudentLogin(username, password, remember) {
            try {
                // First, try direct login
                const loginResponse = await fetch('../api/auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password, remember })
                });
                
                const loginData = await loginResponse.json();
                
                if (loginData.status === '2fa_required') {
                    pendingUserId = loginData.user_id;
                    show2FASection();
                    return;
                }
                
                if (loginResponse.ok) {
                    handleSuccessfulLogin(loginData);
                    return;
                }
                
                await syncAndLoginStudent(username, password, remember);
                
            } catch (error) {
                throw new Error('Login failed: ' + error.message);
            }
        }
        
        async function syncAndLoginStudent(username, password, remember) {
        
            let studentData = null;
            
            try {
                const apiResponse = await fetch('https://ttm.qcprotektado.com/api/students.php');
                if (apiResponse.ok) {
                    const apiData = await apiResponse.json();
                    if (apiData.records) {
                        studentData = apiData.records.find(s => s.student_id == username);
                    }
                }
            } catch (apiError) {
                console.log('API not available, trying local sync');
            }
            
            if (!studentData) {
                throw new Error('Student ID not found. Please check your ID or contact administration.');
            }
            
       
            const syncResponse = await fetch('../api/auth.php?action=sync_student', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    student_id: username,
                    student_data: studentData
                })
            });
            
            const syncData = await syncResponse.json();
            
            if (!syncResponse.ok) {
                throw new Error(syncData.error || 'Failed to sync student account');
            }
            
           
            const retryResponse = await fetch('../api/auth.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password, remember })
            });
            
            const retryData = await retryResponse.json();
            
            if (retryData.status === '2fa_required') {
                pendingUserId = retryData.user_id;
                show2FASection();
                return;
            }
            
            if (!retryResponse.ok) {
                throw new Error(retryData.error || 'Login failed after sync');
            }
            
            handleSuccessfulLogin(retryData);
        }
        
        async function handleStaffLogin(username, password, remember) {
            const response = await fetch('../api/auth.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password, remember })
            });
            
            const data = await response.json();
            
            if (data.status === '2fa_required') {
                pendingUserId = data.user_id;
                show2FASection();
                return;
            }
            
            if (!response.ok) {
                throw new Error(data.error || 'Login failed');
            }
            
            handleSuccessfulLogin(data);
        }
        
        async function verify2fa() {
            const code = document.getElementById('code').value.trim();
            const remember = (currentTab==='student') && document.getElementById('remember').checked;
            const verifyBtn = document.getElementById('verifyBtn');
            const verifyText = document.getElementById('verifyText');
            const verifyLoader = document.getElementById('verifyLoader');
            const err = document.getElementById('error');
            
            err.style.display = 'none';
            
            if (!code || code.length !== 6) {
                showError('Please enter a valid 6-digit code');
                return;
            }
            
            // Show loading
            verifyText.style.display = 'none';
            verifyLoader.style.display = 'inline-block';
            verifyBtn.disabled = true;
            
            try {
                const response = await fetch('../api/auth.php?action=verify2fa', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        code, 
                        user_id: pendingUserId, 
                        remember 
                    })
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.error || '2FA verification failed');
                }
                
                handleSuccessfulLogin(data);
                
            } catch (error) {
                showError(error.message);
            } finally {
                // Reset button
                verifyText.style.display = 'inline';
                verifyLoader.style.display = 'none';
                verifyBtn.disabled = false;
            }
        }
        
        function handleSuccessfulLogin(data) {
            if (data.csrf) {
                sessionStorage.setItem('csrf', data.csrf);
            }
            window.location.href = 'dashboard.php';
        }
        
        function show2FASection() {
            document.getElementById('twofa').style.display = 'block';
            document.getElementById('loginBtn').style.display = 'none';
            document.getElementById('code').focus();
        }
        
        function showError(message) {
            const err = document.getElementById('error');
            err.textContent = message;
            err.style.display = 'block';
            err.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        function togglePassword(id, btn){
            const el = document.getElementById(id);
            const show = el.type === 'password';
            el.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            // Add visual feedback
            btn.style.transform = show ? 'translateY(-50%) scale(1.2)' : 'translateY(-50%) scale(1)';
            setTimeout(() => {
                btn.style.transform = 'translateY(-50%)';
            }, 200);
        }
        
        window.addEventListener('DOMContentLoaded',()=> {
            setTab(currentTab);
            
            // Add enter key support
            document.getElementById('username').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('password').focus();
                }
            });
            
            document.getElementById('password').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handleLogin();
                }
            });
            
            document.getElementById('code')?.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    verify2fa();
                }
            });
            
            // Add focus animation to inputs
            const inputs = document.querySelectorAll('input');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('focused');
                });
            });
        });
    </script>
</body>
</html>