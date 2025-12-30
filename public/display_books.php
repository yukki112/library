<?php
// Display Books page. Lists all books in the catalog with basic information.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$pdo = DB::conn();
$books = $pdo->query("SELECT id, title, author, publisher, total_copies, available_copies FROM books ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/_header.php';
?>

<h2>Book Catalog</h2>

<?php if (empty($books)): ?>
    <div class="empty-state"><i class="fa fa-info-circle"></i><h3>No books found</h3><p>The library catalog is empty.</p></div>
<?php else: ?>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Book No.</th>
                    <th>Image</th>
                    <th>Book Name</th>
                    <th>Author</th>
                    <th>Publication Name</th>
                    <th>Quantity</th>
                    <th>Availability</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($books as $b): ?>
                <tr>
                    <td><?= (int)$b['id'] ?></td>
                    <td><!-- Placeholder for book image -->
                        <div style="width:40px;height:40px;background:#f3f4f6;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:12px;">
                            <i class="fa fa-book"></i>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($b['title'] ?? '') ?></td>
                    <td><?= htmlspecialchars($b['author'] ?? '') ?></td>
                    <td><?= htmlspecialchars($b['publisher'] ?? '') ?></td>
                    <td><?= (int)($b['total_copies'] ?? 0) ?></td>
                    <td><?= (int)($b['available_copies'] ?? 0) ?></td>
                    <td class="table-actions">
                        <button class="action-btn delete" title="Delete"><i class="fa fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>