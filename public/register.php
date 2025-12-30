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
    <title>Register - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="styles/main.css" />
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:#f8fafc; }
        .card { background:#fff; padding:24px; border-radius:12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); width: 100%; max-width: 420px; }
        .card h1 { margin-top:0; font-size:20px; }
        .input { width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:12px; }
        .btn { width:100%; padding:10px; background:#111827; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:700; }
        .error { color:#b91c1c; font-size:13px; margin-bottom:8px; display:none; }
    </style>
    <script>
        async function register(ev){
            ev.preventDefault();
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const email = document.getElementById('email').value.trim();
            const name = document.getElementById('name').value.trim();
            const role = document.getElementById('role').value;
    const phone = document.getElementById('phone').value.trim();
    const semester = document.getElementById('semester').value;
    const department = document.getElementById('department').value.trim();
    const address = document.getElementById('address').value.trim();
            const err = document.getElementById('error');
            err.style.display = 'none';
            try {
                const res = await fetch('../api/register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password, email, name, role, phone, semester, department, address })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Registration failed');
                alert('Registration successful. Please log in.');
                window.location.href = 'login.php?tab=student';
            } catch(e) {
                err.textContent = e.message;
                err.style.display = 'block';
            }
        }
    </script>
</head>
<body>
    <form class="card" onsubmit="register(event)">
        <h1>Create Account</h1>
        <p class="error" id="error"></p>
        <input class="input" id="name" placeholder="Full Name" />
        <input class="input" id="email" placeholder="Email" type="email" required />
        <!-- Collect a phone number for contact purposes -->
        <input class="input" id="phone" placeholder="Phone Number" type="tel" />
        <input class="input" id="username" placeholder="Username" autocomplete="username" required />
        <input class="input" id="password" placeholder="Password" type="password" autocomplete="new-password" required />
        <!-- Optional academic information.  Students should select a semester and specify their department. -->
        <label style="font-size:12px; color:#6b7280;">Semester</label>
        <select class="input" id="semester">
            <option value="">Select Semester</option>
            <option value="1st Semester">1st Semester</option>
            <option value="2nd Semester">2nd Semester</option>
            <option value="Summer">Summer</option>
        </select>
        <input class="input" id="department" placeholder="Department" />
        <!-- Physical address for mailing or verification -->
        <input class="input" id="address" placeholder="Address" />
        <label style="font-size:12px; color:#6b7280;">Account Type</label>
        <select class="input" id="role">
            <option value="student">Student</option>
            <option value="non_staff">Non-Teaching Staff</option>
        </select>
        <button class="btn" type="submit">Register</button>
        <p style="margin-top:8px; font-size:12px; color:#6b7280;">Already have an account? <a href="login.php?tab=student">Login</a></p>
    </form>
</body>
</html>
