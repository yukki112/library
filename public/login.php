<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
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
    <script>
        let currentTab = (new URLSearchParams(location.search).get('tab')) || 'staff';
        function setTab(tab){
            currentTab = tab;
            document.getElementById('tab-staff').classList.toggle('active', tab==='staff');
            document.getElementById('tab-student').classList.toggle('active', tab==='student');
            document.getElementById('rememberRow').style.display = (tab==='student') ? 'flex' : 'none';
            document.getElementById('registerRow').style.display = (tab==='student') ? 'block' : 'none';
        }
        async function login(ev){
            ev.preventDefault();
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const remember = (currentTab==='student') && document.getElementById('remember').checked;
            const err = document.getElementById('error');
            const step2 = document.getElementById('twofa');
            err.style.display = 'none';
            step2.style.display = 'none';
            try {
                const res = await fetch('../api/auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password, remember })
                });
                const data = await res.json();
                if (data && data.status === '2fa_required') {
                    step2.dataset.userId = data.user_id;
                    step2.style.display = 'block';
                    return;
                }
                if (!res.ok) throw new Error(data.error || 'Login failed');
                if (data.csrf) sessionStorage.setItem('csrf', data.csrf);
                window.location.href = 'dashboard.php';
            } catch(e) {
                err.textContent = e.message;
                err.style.display = 'block';
            }
        }
        async function verify2fa(ev){
            ev.preventDefault();
            const code = document.getElementById('code').value.trim();
            const remember = (currentTab==='student') && document.getElementById('remember').checked;
            const step2 = document.getElementById('twofa');
            const user_id = step2.dataset.userId;
            const err = document.getElementById('error');
            try {
                const res = await fetch('../api/auth.php?action=verify2fa', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ code, user_id, remember }) });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || '2FA failed');
                if (data.csrf) sessionStorage.setItem('csrf', data.csrf);
                window.location.href = 'dashboard.php';
            } catch(e){ err.textContent = e.message; err.style.display='block'; }
        }
        function togglePassword(id, btn){
            const el = document.getElementById(id);
            const show = el.type === 'password';
            el.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        }
        window.addEventListener('DOMContentLoaded',()=> setTab(currentTab));
    </script>
</head>
<body class="login-container">
    <form class="login-card login-form" onsubmit="login(event)">
        <div class="login-header">
            <div class="login-icon">
                <img src="<?= htmlspecialchars(APP_LOGO_URL) ?>" alt="logo" onerror="this.style.display='none'" />
            </div>
            <h1><?= htmlspecialchars(APP_NAME) ?></h1>
        </div>

        <div class="login-tabs">
            <button type="button" id="tab-staff" class="login-tab" onclick="setTab('staff')">Staff</button>
            <button type="button" id="tab-student" class="login-tab" onclick="setTab('student')">Student / Non‑Staff</button>
        </div>

        <div id="error" class="login-error"></div>

        <div class="form-group">
            <label for="username">Username</label>
            <input id="username" autocomplete="username" required />
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <div class="password-field">
                <input id="password" type="password" autocomplete="current-password" required />
                <button type="button" class="toggle-eye" aria-label="Toggle password" onclick="togglePassword('password', this)" title="Show/Hide">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
        </div>

        <div class="checkbox-item" id="rememberRow" style="display:none;">
            <input id="remember" type="checkbox" />
            <label for="remember">Remember me for 30 days</label>
        </div>
        <div id="registerRow" class="text-center" style="display:none; font-size:12px; color:#6b7280;">
            Don’t have an account? <a href="register.php">Register</a>
        </div>

        <button class="btn btn-primary btn-full" type="submit">Sign In</button>

        <div id="twofa" class="form-group" style="display:none; margin-top:8px;">
            <label for="code">Enter 2FA code sent to your email</label>
            <input id="code" placeholder="123456" />
            <div class="mt-4">
                <button class="btn btn-primary btn-full" onclick="verify2fa(event)">Verify</button>
            </div>
        </div>

        <!-- The demo accounts section has been removed to avoid exposing sample credentials -->
    </form>
</body>
</html>
