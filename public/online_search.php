<?php
// Online Book Search page with functional search
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
// Retrieve search query from query string
$q = trim($_GET['q'] ?? '');

// Determine the current user role
$user = current_user();
$role = $user['role'] ?? '';

$books = [];

// If the user is a student or non‑staff and has not entered a search term, present a curated list
// of available books with covers and categories. This helps new users browse popular titles even
// without knowing exactly what to search for.
if ($q === '' && in_array($role, ['student','non_staff'], true)) {
    $books = [
        // Each entry includes an explicit numeric 'id' for reservation reference.
        // History titles
        ['id' => 1,  'title' => 'World History',             'author' => 'John Brown',      'category' => 'History',             'available_copies' => 3, 'cover' => 'assets/book_covers/history.png'],
        ['id' => 2,  'title' => 'Ancient Civilizations',     'author' => 'Mary Johnson',     'category' => 'History',             'available_copies' => 2, 'cover' => 'assets/book_covers/history.png'],
        ['id' => 3,  'title' => 'Modern History Essentials', 'author' => 'David Lee',        'category' => 'History',             'available_copies' => 4, 'cover' => 'assets/book_covers/history.png'],
        ['id' => 4,  'title' => 'Philippine History',        'author' => 'Ana Cruz',         'category' => 'History',             'available_copies' => 1, 'cover' => 'assets/book_covers/history.png'],
        // Physical Education titles
        ['id' => 5,  'title' => 'Sports Science Basics',     'author' => 'Alex Garcia',      'category' => 'Physical Education',  'available_copies' => 5, 'cover' => 'assets/book_covers/pe.png'],
        ['id' => 6,  'title' => 'Health and Fitness',        'author' => 'Emily Martinez',   'category' => 'Physical Education',  'available_copies' => 2, 'cover' => 'assets/book_covers/pe.png'],
        ['id' => 7,  'title' => 'Introduction to PE',        'author' => 'Sam Davis',        'category' => 'Physical Education',  'available_copies' => 1, 'cover' => 'assets/book_covers/pe.png'],
        ['id' => 8,  'title' => 'Team Sports Handbook',      'author' => 'Linda Taylor',     'category' => 'Physical Education',  'available_copies' => 3, 'cover' => 'assets/book_covers/pe.png'],
        // Physics titles
        ['id' => 9,  'title' => 'Physics Fundamentals',      'author' => 'Robert Wilson',    'category' => 'Physics',             'available_copies' => 3, 'cover' => 'assets/book_covers/physics.png'],
        ['id' => 10, 'title' => 'Quantum Mechanics Intro',   'author' => 'Patricia Moore',   'category' => 'Physics',             'available_copies' => 2, 'cover' => 'assets/book_covers/physics.png'],
        ['id' => 11, 'title' => 'Electricity and Magnetism', 'author' => 'James White',      'category' => 'Physics',             'available_copies' => 4, 'cover' => 'assets/book_covers/physics.png'],
        ['id' => 12, 'title' => 'Physics Experiments',       'author' => 'Lisa Martin',      'category' => 'Physics',             'available_copies' => 2, 'cover' => 'assets/book_covers/physics.png'],
        // Mathematics titles
        ['id' => 13, 'title' => 'Calculus I',                'author' => 'Michael Clark',    'category' => 'Mathematics',         'available_copies' => 3, 'cover' => 'assets/book_covers/math.png'],
        ['id' => 14, 'title' => 'Linear Algebra',            'author' => 'Barbara Lewis',     'category' => 'Mathematics',         'available_copies' => 4, 'cover' => 'assets/book_covers/math.png'],
        ['id' => 15, 'title' => 'Probability & Statistics',  'author' => 'William Young',    'category' => 'Mathematics',         'available_copies' => 2, 'cover' => 'assets/book_covers/math.png'],
        ['id' => 16, 'title' => 'Discrete Mathematics',      'author' => 'Nancy Hall',        'category' => 'Mathematics',         'available_copies' => 1, 'cover' => 'assets/book_covers/math.png'],
        // Programming titles
        ['id' => 17, 'title' => 'Learn C Programming',       'author' => 'Andrew Adams',      'category' => 'Programming',         'available_copies' => 3, 'cover' => 'assets/book_covers/programming.png'],
        ['id' => 18, 'title' => 'Python for Beginners',      'author' => 'Grace Nelson',      'category' => 'Programming',         'available_copies' => 4, 'cover' => 'assets/book_covers/programming.png'],
        ['id' => 19, 'title' => 'JavaScript Essentials',     'author' => 'Chris Hernandez',   'category' => 'Programming',         'available_copies' => 2, 'cover' => 'assets/book_covers/programming.png'],
        ['id' => 20, 'title' => 'Java Fundamentals',         'author' => 'Olivia Turner',     'category' => 'Programming',         'available_copies' => 2, 'cover' => 'assets/book_covers/programming.png'],
        // Dictionary & reference titles
        ['id' => 21, 'title' => 'English Dictionary',        'author' => 'Oxford Press',      'category' => 'Dictionary',          'available_copies' => 5, 'cover' => 'assets/book_covers/dictionary.png'],
        ['id' => 22, 'title' => 'Science Dictionary',        'author' => 'Cambridge Press',   'category' => 'Dictionary',          'available_copies' => 3, 'cover' => 'assets/book_covers/dictionary.png'],
        ['id' => 23, 'title' => 'Math Dictionary',           'author' => 'Princeton Press',   'category' => 'Dictionary',          'available_copies' => 2, 'cover' => 'assets/book_covers/dictionary.png'],
        ['id' => 24, 'title' => 'Programming Glossary',      'author' => 'Tech Writers',      'category' => 'Dictionary',          'available_copies' => 1, 'cover' => 'assets/book_covers/dictionary.png'],
    ];
} elseif ($q !== '') {
    // Search books by title, author, ISBN or category from the database
    $pdo = DB::conn();
    $stmt = $pdo->prepare('SELECT * FROM books WHERE is_active = 1 AND (title LIKE :q OR author LIKE :q OR isbn LIKE :q OR category LIKE :q)');
    $like = '%' . $q . '%';
    $stmt->execute([':q' => $like]);
    $results = $stmt->fetchAll();
    // Map results to include a default cover image so the table layout remains consistent
    foreach ($results as $row) {
        $row['cover'] = APP_LOGO_URL; // use the app logo as placeholder for searched books
        $books[] = $row;
    }
}
include '_header.php';
?>

<h2>Book Search</h2>

<form method="get" style="margin-bottom:12px; display:flex; gap:8px; align-items:center;">
    <input type="text" name="q" placeholder="Enter title, author, ISBN or category" value="<?= htmlspecialchars($q) ?>" style="flex:1; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
    <button type="submit" class="btn" style="padding:8px 14px; background:#3b82f6; color:#fff; border:none; border-radius:6px;">Search</button>
</form>

<?php if (!empty($books)): ?>
    <table style="width:100%; border-collapse:collapse; margin-top:12px;">
        <thead>
            <tr style="background:#f9fafb;">
                <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Cover</th>
                <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Book&nbsp;ID</th>
                <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Title</th>
                <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Author</th>
                <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Category</th>
                <th style="text-align:right; padding:8px; border-bottom:1px solid #e5e7eb;">Available</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($books as $b): ?>
            <tr>
                <td style="padding:8px; border-bottom:1px solid #e5e7eb;">
                    <?php if (!empty($b['cover'])): ?>
                        <img src="<?= htmlspecialchars($b['cover']) ?>" alt="Cover" style="width:40px; height:50px; object-fit:cover; border-radius:4px;" />
                    <?php endif; ?>
                </td>
                <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars($b['id'] ?? '') ?></td>
                <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars($b['title']) ?></td>
                <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars($b['author'] ?? '') ?></td>
                <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars($b['category'] ?? '') ?></td>
                <td style="padding:8px; border-bottom:1px solid #e5e7eb; text-align:right;"><?= htmlspecialchars($b['available_copies'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php elseif ($q !== ''): ?>
    <!-- Show this message only when a search term was entered but no results were found -->
    <p>No books found for your search.</p>
    <?php if (in_array($role, ['student','non_staff','teacher'], true)): ?>
        <div style="margin-top:12px; padding:12px; border:1px solid #e5e7eb; border-radius:8px; background:#f9fafb;">
            <h3 style="margin-top:0;">Request this book</h3>
            <form onsubmit="reqBook(event)" style="display:flex; flex-direction:column; gap:8px;">
                <!-- Prepopulate the title with the search term to save the patron typing -->
                <input id="reqTitle" class="input" placeholder="Book title" value="<?= htmlspecialchars($q) ?>" required style="padding:8px; border:1px solid #d1d5db; border-radius:6px;" />
                <input id="reqUrl" class="input" placeholder="URL (optional)" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;" />
                <button type="submit" class="btn" style="padding:8px 12px; background:#111827; color:#fff; border:none; border-radius:6px;">Send Request</button>
            </form>
        </div>
        <script>
        // Submit a book request to the API.  Upon success the form is cleared
        // and a confirmation alert is displayed.  Errors are surfaced to the
        // user via alert boxes.  The API will reject requests from roles
        // other than students, non‑staff and teachers.
        async function reqBook(ev){
            ev.preventDefault();
            const title = document.getElementById('reqTitle').value.trim();
            const url   = document.getElementById('reqUrl').value.trim();
            if (!title) return;
            try {
                const res  = await fetch('../api/book_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ title, url })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Request failed');
                alert('Your request has been sent to the library administrators.');
                document.getElementById('reqTitle').value = '';
                document.getElementById('reqUrl').value = '';
            } catch (e) {
                alert(e.message);
            }
        }
        </script>
    <?php endif; ?>
<?php endif; ?>


<?php if (false && $q !== ''): ?>
    <?php if ($books): ?>
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Title</th>
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Author</th>
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e5e7eb;">Category</th>
                    <th style="text-align:right; padding:8px; border-bottom:1px solid #e5e7eb;">Available</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($books as $b): ?>
                <tr>
                    <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars($b['title']) ?></td>
                    <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars($b['author']) ?></td>
                    <td style="padding:8px; border-bottom:1px solid #e5e7eb;"><?= htmlspecialchars($b['category'] ?? '') ?></td>
                    <td style="padding:8px; border-bottom:1px solid #e5e7eb; text-align:right;"><?= htmlspecialchars($b['available_copies']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No books found for your search.</p>
    <?php endif; ?>
<?php endif; ?>

<?php include '_footer.php'; ?>