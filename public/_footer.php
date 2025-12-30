        </main>
    </div>
    <script>
        // Logout confirmation then call API
        const btn = document.getElementById('logoutBtn');
        if (btn) {
            btn.addEventListener('click', async () => {
                if (!confirm('Are you sure you want to log out?')) return;
                const csrf = sessionStorage.getItem('csrf');
                try {
                    const res = await fetch('../api/auth.php?action=logout', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': csrf || '' }
                    });
                } catch (e) {}
                window.location.href = 'login.php';
            });
        }
    </script>
</body>
</html>
