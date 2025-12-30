<?php
// Manage Books page.
// This page replaces the previous two‑link landing (add vs display) with a
// unified interface.  Administrators and librarians can view the current
// catalogue, add new titles and edit or delete existing books.  The
// interface lists all books in a table and provides a modal form for
// creating or updating records.  Interaction with the backend uses the
// generic API exposed in api/dispatch.php for the "books" resource.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
// Only staff roles (admin, librarian, assistant) should access this page.
if (!in_array(current_user()['role'], ['admin','librarian','assistant'], true)) {
    header('Location: dashboard.php');
    exit;
}
// Fetch books from the database.  Selecting all columns here allows the
// edit modal to prepopulate fields such as ISBN, category and year
// published.  Ordering by ID ensures deterministic ordering.
$pdo = DB::conn();
$stmt = $pdo->query('SELECT * FROM books ORDER BY id ASC');
$books = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

include __DIR__ . '/_header.php';
?>

<h2>Manage Books</h2>

<p>View and manage the library catalogue.  Use the button below to add
new titles or click the pencil icon next to a record to edit its
details.</p>

<!-- Top toolbar with Add Book button -->
<div style="margin-bottom:12px; display:flex; justify-content:flex-end;">
    <button id="btnAddBook" class="btn" style="background:#3b82f6; color:#fff; border:none; padding:8px 14px; border-radius:6px;">Add Book</button>
</div>

<?php if (empty($books)): ?>
    <div class="empty-state"><i class="fa fa-info-circle"></i><h3>No books in catalogue</h3><p>Add a new book to get started.</p></div>
<?php else: ?>
<div class="table-container">
  <table class="data-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Title</th>
        <th>Author</th>
        <th>ISBN</th>
        <th>Category</th>
        <th>Publisher</th>
        <th>Year</th>
        <th>Total</th>
        <th>Available</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($books as $b): ?>
        <tr
          data-id="<?= (int)$b['id'] ?>"
          data-title="<?= htmlspecialchars($b['title'] ?? '') ?>"
          data-author="<?= htmlspecialchars($b['author'] ?? '') ?>"
          data-isbn="<?= htmlspecialchars($b['isbn'] ?? '') ?>"
          data-category="<?= htmlspecialchars($b['category'] ?? '') ?>"
          data-publisher="<?= htmlspecialchars($b['publisher'] ?? '') ?>"
          data-year="<?= (int)($b['year_published'] ?? 0) ?>"
          data-total="<?= (int)($b['total_copies'] ?? 0) ?>"
          data-available="<?= (int)($b['available_copies'] ?? 0) ?>"
        >
          <td><?= (int)$b['id'] ?></td>
          <td><?= htmlspecialchars($b['title'] ?? '') ?></td>
          <td><?= htmlspecialchars($b['author'] ?? '') ?></td>
          <td><?= htmlspecialchars($b['isbn'] ?? '') ?></td>
          <td><?= htmlspecialchars($b['category'] ?? '') ?></td>
          <td><?= htmlspecialchars($b['publisher'] ?? '') ?></td>
          <td><?= htmlspecialchars($b['year_published'] ?? '') ?></td>
          <td><?= (int)$b['total_copies'] ?></td>
          <td><?= (int)$b['available_copies'] ?></td>
          <td class="table-actions">
            <button class="action-btn edit-book-btn" data-id="<?= (int)$b['id'] ?>" title="Edit"><i class="fa fa-pen"></i></button>
            <button class="action-btn delete-book-btn" data-id="<?= (int)$b['id'] ?>" title="Delete"><i class="fa fa-trash"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Modal for adding/editing a book -->
<div id="bookModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:1000;">
  <div style="background:#fff; padding:20px; border-radius:8px; width:480px; max-width:90%; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
    <h3 id="bookModalTitle" style="margin-top:0;">Add Book</h3>
    <form id="bookForm">
      <input type="hidden" id="bookId" />
      <div style="margin-bottom:8px;">
        <label>Title</label>
        <input type="text" id="bookTitle" required style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;" />
      </div>
      <div style="margin-bottom:8px;">
        <label>Author</label>
        <input type="text" id="bookAuthor" required style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;" />
      </div>
      <div style="margin-bottom:8px;">
        <label>ISBN</label>
        <input type="text" id="bookISBN" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;" />
      </div>
      <div style="margin-bottom:8px;">
        <label>Category</label>
        <input type="text" id="bookCategory" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;" />
      </div>
      <div style="margin-bottom:8px;">
        <label>Publisher</label>
        <input type="text" id="bookPublisher" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;" />
      </div>
      <div style="margin-bottom:8px;">
        <label>Year Published</label>
        <input type="number" id="bookYear" min="0" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;" />
      </div>
      <div style="margin-bottom:8px;">
        <label>Total Copies</label>
        <input type="number" id="bookTotal" min="0" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;" />
      </div>
      <div style="margin-bottom:8px;">
        <label>Available Copies</label>
        <input type="number" id="bookAvail" min="0" style="width:100%; padding:6px; border:1px solid #e5e7eb; border-radius:4px;" />
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
        <button type="button" id="cancelBook" class="btn" style="background:#e5e7eb; color:#374151; border:none; padding:8px 12px; border-radius:6px;">Back</button>
        <button type="submit" id="saveBook" class="btn" style="background:#3b82f6; color:#fff; border:none; padding:8px 12px; border-radius:6px;">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
// Immediately invoked function to encapsulate variables and avoid polluting the global scope
(function(){
  const modal = document.getElementById('bookModal');
  const bookForm = document.getElementById('bookForm');
  const titleInput = document.getElementById('bookTitle');
  const authorInput = document.getElementById('bookAuthor');
  const isbnInput = document.getElementById('bookISBN');
  const categoryInput = document.getElementById('bookCategory');
  const publisherInput = document.getElementById('bookPublisher');
  const yearInput = document.getElementById('bookYear');
  const totalInput = document.getElementById('bookTotal');
  const availInput = document.getElementById('bookAvail');
  const bookIdInput = document.getElementById('bookId');
  const modalTitle = document.getElementById('bookModalTitle');

  // Open modal for creating a new book
  document.getElementById('btnAddBook').addEventListener('click', () => {
    modalTitle.textContent = 'Add Book';
    bookIdInput.value = '';
    // Reset form fields
    titleInput.value = '';
    authorInput.value = '';
    isbnInput.value = '';
    categoryInput.value = '';
    publisherInput.value = '';
    yearInput.value = '';
    totalInput.value = '';
    availInput.value = '';
    modal.style.display = 'flex';
  });

  // Hide modal when cancelling
  document.getElementById('cancelBook').addEventListener('click', () => {
    modal.style.display = 'none';
  });

  // Save book record (create or update)
  bookForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = bookIdInput.value;
    // Build payload: convert empty strings to null for optional fields
    const payload = {
      title: titleInput.value.trim(),
      author: authorInput.value.trim(),
      isbn: isbnInput.value.trim() || null,
      category: categoryInput.value.trim() || null,
      publisher: publisherInput.value.trim() || null,
      year_published: yearInput.value ? parseInt(yearInput.value) : null,
      total_copies: totalInput.value ? parseInt(totalInput.value) : 0,
      available_copies: availInput.value ? parseInt(availInput.value) : 0
    };
    const csrf = sessionStorage.getItem('csrf') || '';
    try {
      if (id) {
        // Update existing record
        const res = await fetch('../api/dispatch.php?resource=books&id=' + id, {
          method:'PUT',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify(payload)
        });
        const out = await res.json();
        if (!res.ok) throw new Error(out.error || 'Update failed');
      } else {
        // Create new record
        const res = await fetch('../api/dispatch.php?resource=books', {
          method:'POST',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify(payload)
        });
        const out = await res.json();
        if (!res.ok) throw new Error(out.error || 'Create failed');
      }
      modal.style.display = 'none';
      window.location.reload();
    } catch(err) {
      alert(err.message || err);
    }
  });

  // Populate modal with data when clicking edit button
  document.querySelectorAll('.edit-book-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const row = btn.closest('tr');
      modalTitle.textContent = 'Edit Book';
      bookIdInput.value = btn.getAttribute('data-id');
      titleInput.value = row.getAttribute('data-title') || '';
      authorInput.value = row.getAttribute('data-author') || '';
      isbnInput.value = row.getAttribute('data-isbn') || '';
      categoryInput.value = row.getAttribute('data-category') || '';
      publisherInput.value = row.getAttribute('data-publisher') || '';
      yearInput.value = row.getAttribute('data-year') || '';
      totalInput.value = row.getAttribute('data-total') || '';
      availInput.value = row.getAttribute('data-available') || '';
      modal.style.display = 'flex';
    });
  });

  // Delete a book record
  document.querySelectorAll('.delete-book-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-id');
      if (!confirm('Delete this book?')) return;
      const csrf = sessionStorage.getItem('csrf') || '';
      try {
        const res = await fetch('../api/dispatch.php?resource=books&id=' + id, {
          method:'DELETE',
          credentials:'same-origin',
          headers:{ 'X-CSRF-Token': csrf }
        });
        const out = await res.json();
        if (!res.ok) throw new Error(out.error || 'Delete failed');
        window.location.reload();
      } catch(err) {
        alert(err.message || err);
      }
    });
  });
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>