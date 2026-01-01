<?php
// Manage Books page with enhanced design and functionality
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

// Only staff roles (admin, librarian, assistant) should access this page.
if (!in_array(current_user()['role'], ['admin','librarian','assistant'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = DB::conn();

// Handle filtering
$filter_category = $_GET['category'] ?? '';
$filter_search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Pagination parameters
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$books_per_page = 6;
$offset = ($current_page - 1) * $books_per_page;

// Get summary statistics FIRST
$stats_query = "SELECT 
    COUNT(DISTINCT b.id) as total_titles,
    COUNT(bc.id) as total_copies,
    SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END) as available_copies,
    COUNT(DISTINCT b.category) as total_categories
    FROM books b
    LEFT JOIN book_copies bc ON b.id = bc.book_id
    WHERE b.is_active = 1";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Build query with filters for total count
$count_query = "SELECT COUNT(DISTINCT b.id) as total 
                FROM books b
                WHERE b.is_active = 1";
$count_params = [];

if ($filter_category) {
    $count_query .= " AND (b.category_id = ? OR b.category LIKE ?)";
    $count_params[] = $filter_category;
    $count_params[] = "%$filter_category%";
}

if ($filter_search) {
    $count_query .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
    $searchTerm = "%$filter_search%";
    $count_params[] = $searchTerm;
    $count_params[] = $searchTerm;
    $count_params[] = $searchTerm;
}

// Execute count query
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($count_params);
$total_books = $count_stmt->fetchColumn();
$total_pages = ceil($total_books / $books_per_page);

// Build query with filters for paginated results
$query = "SELECT b.*, 
                 COUNT(DISTINCT bc.id) as total_copies,
                 SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END) as available_copies
          FROM books b
          LEFT JOIN book_copies bc ON b.id = bc.book_id AND bc.is_active = 1
          WHERE b.is_active = 1";
$params = [];

if ($filter_category) {
    $query .= " AND (b.category_id = ? OR b.category LIKE ?)";
    $params[] = $filter_category;
    $params[] = "%$filter_category%";
}

if ($filter_search) {
    $query .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
    $searchTerm = "%$filter_search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$query .= " GROUP BY b.id";

// Apply status filter
if ($filter_status === 'available') {
    $query .= " HAVING available_copies > 0";
} elseif ($filter_status === 'low_stock') {
    $query .= " HAVING available_copies > 0 AND available_copies < 3";
} elseif ($filter_status === 'no_copies') {
    $query .= " HAVING total_copies = 0 OR total_copies IS NULL";
}

$query .= " ORDER BY b.title ASC LIMIT ? OFFSET ?";
$params[] = $books_per_page;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter dropdown (use both id and name for compatibility)
$categories_stmt = $pdo->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Also get unique category names from books table for backward compatibility
$book_categories_stmt = $pdo->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL AND category != '' ORDER BY category");
$book_categories = $book_categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get library map configuration for slot validation
$library_map_stmt = $pdo->query("SELECT * FROM library_map_config WHERE is_active = 1 ORDER BY section");
$library_map = $library_map_stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/_header.php';
?>

<div class="page-header">
    <h1>
        <i class="fa fa-book"></i>
        Book Inventory
    </h1>
    <p class="subtitle">Manage the library catalogue, add new books, and update existing records</p>
</div>

<!-- Summary Stats at the Top -->
<div class="summary-stats">
    <div class="stat-item">
        <i class="fa fa-book"></i>
        <div>
            <h4><?= $stats['total_titles'] ?? 0 ?></h4>
            <span>Total Titles</span>
        </div>
    </div>
    <div class="stat-item">
        <i class="fa fa-copy"></i>
        <div>
            <h4><?= $stats['total_copies'] ?? 0 ?></h4>
            <span>Total Copies</span>
        </div>
    </div>
    <div class="stat-item">
        <i class="fa fa-check-circle"></i>
        <div>
            <h4><?= $stats['available_copies'] ?? 0 ?></h4>
            <span>Available Copies</span>
        </div>
    </div>
    <div class="stat-item">
        <i class="fa fa-tags"></i>
        <div>
            <h4><?= $stats['total_categories'] ?? 0 ?></h4>
            <span>Categories</span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="flex-space-between">
            <h3>
                <i class="fa fa-list"></i>
                Book Catalogue
                <span class="badge"><?= $total_books ?> books</span>
            </h3>
            <div>
                <button id="btnAddBook" class="btn btn-primary">
                    <i class="fa fa-plus"></i>
                    Add New Book
                </button>
            </div>
        </div>
    </div>


    <!-- AI Recommendations Section -->
    <div class="card">
        <div class="card-header">
            <h3>
                <i class="fa fa-robot"></i>
                AI Location Recommendations
                <span class="badge" id="aiRecommendationCount">0</span>
            </h3>
        </div>
        <div class="card-body">
            <div id="aiRecommendations">
                <div class="loading">Loading AI recommendations...</div>
            </div>
        </div>
    </div>
    

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" class="filter-form">
            <input type="hidden" name="page" value="1">
            
            <div class="filter-row">
                <div class="filter-group">
                    <label for="search">
                        <i class="fa fa-search"></i>
                        Search
                    </label>
                    <input type="text" 
                           id="search" 
                           name="search" 
                           value="<?= htmlspecialchars($filter_search) ?>"
                           placeholder="Title, Author, or ISBN..."
                           class="filter-input">
                </div>
                
                <div class="filter-group">
                    <label for="category">
                        <i class="fa fa-tag"></i>
                        Category
                    </label>
                    <select id="category" name="category" class="filter-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" 
                                <?= $filter_category == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php foreach ($book_categories as $cat): ?>
                            <?php if ($cat['category']): ?>
                                <option value="<?= htmlspecialchars($cat['category']) ?>" 
                                    <?= $filter_category == $cat['category'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category']) ?> (from books)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="status">
                        <i class="fa fa-filter"></i>
                        Filter
                    </label>
                    <select id="status" name="status" class="filter-select">
                        <option value="">All Books</option>
                        <option value="available" <?= $filter_status == 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="low_stock" <?= $filter_status == 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="no_copies" <?= $filter_status == 'no_copies' ? 'selected' : '' ?>>No Copies</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <button type="submit" class="btn btn-secondary">
                        <i class="fa fa-filter"></i>
                        Apply Filters
                    </button>
                    <a href="manage_books.php" class="btn btn-outline">
                        <i class="fa fa-refresh"></i>
                        Clear
                    </a>
                </div>
            </div>
        </form>
    </div>

    <div class="card-body">
        <?php if (empty($books)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fa fa-book-open"></i>
                </div>
                <h3>No Books Found</h3>
                <p>No books match your search criteria. Try different filters or add a new book.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Cover</th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>ISBN</th>
                            <th>Category</th>
                            <th>Year</th>
                            <th class="text-center">
                                <i class="fa fa-copy" title="Total Copies"></i>
                            </th>
                            <th class="text-center">
                                <i class="fa fa-check-circle" title="Available Copies"></i>
                            </th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($books as $book): 
                            // Determine availability status
                            $available_copies = $book['available_copies'] ?? 0;
                            $total_copies = $book['total_copies'] ?? 0;
                            $availability_class = $available_copies == 0 ? 'status-unavailable' : 
                                                ($available_copies < 3 ? 'status-low' : 'status-available');
                            $availability_text = $available_copies == 0 ? 'Out of Stock' : 
                                                ($available_copies < 3 ? 'Low Stock' : 'Available');
                            
                            // Check for cover image
                            $cover_image = $book['cover_image_cache'] ?? $book['cover_image'] ?? null;
                            $cover_url = $cover_image ? '../uploads/covers/' . htmlspecialchars($cover_image) : '../assets/images/default-book-cover.jpg';
                        ?>
                            <tr data-id="<?= (int)$book['id'] ?>"
                                data-title="<?= htmlspecialchars($book['title'] ?? '') ?>"
                                data-author="<?= htmlspecialchars($book['author'] ?? '') ?>"
                                data-isbn="<?= htmlspecialchars($book['isbn'] ?? '') ?>"
                                data-category-id="<?= (int)$book['category_id'] ?? '' ?>"
                                data-category="<?= htmlspecialchars($book['category'] ?? '') ?>"
                                data-publisher="<?= htmlspecialchars($book['publisher'] ?? '') ?>"
                                data-year="<?= (int)($book['year_published'] ?? 0) ?>"
                                data-description="<?= htmlspecialchars($book['description'] ?? '') ?>"
                                data-cover-image="<?= htmlspecialchars($cover_image ?? '') ?>">
                                <td class="text-center">
                                    <div class="book-cover-container">
                                        <img src="<?= $cover_url ?>" 
                                             alt="Book Cover" 
                                             class="book-cover-preview"
                                             onerror="this.src='../assets/images/default-book-cover.jpg'">
                                    </div>
                                </td>
                                <td>
                                    <div class="book-title">
                                        <strong><?= htmlspecialchars($book['title'] ?? '') ?></strong>
                                        <?php if ($book['publisher']): ?>
                                            <small class="text-muted"><?= htmlspecialchars($book['publisher']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($book['author'] ?? '') ?></td>
                                <td>
                                    <?php if ($book['isbn']): ?>
                                        <span class="isbn-badge"><?= htmlspecialchars($book['isbn']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">No ISBN</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($book['category']): ?>
                                        <span class="category-badge"><?= htmlspecialchars($book['category']) ?></span>
                                    <?php elseif ($book['category_id']): 
                                        // Try to find category name from categories table
                                        foreach ($categories as $cat) {
                                            if ($cat['id'] == $book['category_id']) {
                                                echo '<span class="category-badge">' . htmlspecialchars($cat['name']) . '</span>';
                                                break;
                                            }
                                        }
                                    ?>
                                    <?php else: ?>
                                        <span class="text-muted">Uncategorized</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $book['year_published'] ?: '-' ?></td>
                                <td class="text-center">
                                    <a href="#" onclick="showCopies(<?= $book['id'] ?>); return false;" title="View all copies">
                                        <span class="badge badge-outline"><?= $total_copies ?></span>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $availability_class ?>">
                                        <?= $available_copies ?>
                                    </span>
                                    <small class="text-muted d-block"><?= $availability_text ?></small>
                                </td>
                                <td>
                                    <?php if ($total_copies == 0): ?>
                                        <span class="text-warning">
                                            <i class="fa fa-exclamation-triangle"></i>
                                            No copies
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="action-buttons">
                                        <button class="btn-icon btn-view" 
                                                onclick="viewBook(<?= $book['id'] ?>)"
                                                title="View Details">
                                            <i class="fa fa-eye"></i>
                                        </button>
                                        <button class="btn-icon btn-edit edit-book-btn" 
                                                data-id="<?= (int)$book['id'] ?>"
                                                title="Edit Book">
                                            <i class="fa fa-pencil"></i>
                                        </button>
                                        <button class="btn-icon btn-copy add-copies-btn" 
                                                data-id="<?= (int)$book['id'] ?>"
                                                data-title="<?= htmlspecialchars($book['title'] ?? '') ?>"
                                                title="Add Copies">
                                            <i class="fa fa-copy"></i>
                                        </button>
                                        <button class="btn-icon btn-delete delete-book-btn" 
                                                data-id="<?= (int)$book['id'] ?>"
                                                title="Delete Book">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div class="pagination">
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-info">
                        Showing <?= min($books_per_page, count($books)) ?> of <?= $total_books ?> books
                    </div>
                    <div class="pagination-controls">
                        <?php if ($current_page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>" 
                               class="btn btn-outline">
                                <i class="fa fa-chevron-left"></i>
                                Previous
                            </a>
                        <?php endif; ?>
                        
                        <div class="page-numbers">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <?php if ($i == 1 || $i == $total_pages || ($i >= $current_page - 2 && $i <= $current_page + 2)): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                                       class="page-number <?= $i == $current_page ? 'active' : '' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php elseif ($i == $current_page - 3 || $i == $current_page + 3): ?>
                                    <span class="page-dots">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                        
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>"
                               class="btn btn-outline">
                                Next
                                <i class="fa fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Book Modal -->
<div id="bookModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="bookModalTitle">
                <i class="fa fa-book"></i>
                <span>Add New Book</span>
            </h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="bookForm" enctype="multipart/form-data">
                <input type="hidden" id="bookId" />
                <input type="hidden" id="existingCoverImage" />
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <div class="cover-image-section">
                            <div class="cover-preview-container">
                                <img id="coverPreview" src="assets/asd.jpg" 
                                     alt="Cover Preview" 
                                     class="cover-preview">
                                <div class="cover-preview-overlay">
                                    <label for="coverImage" class="btn btn-sm btn-outline">
                                        <i class="fa fa-camera"></i>
                                        Change Cover
                                    </label>
                                </div>
                            </div>
                            <input type="file" 
                                   id="coverImage" 
                                   name="cover_image"
                                   accept="image/*"
                                   style="display: none;"
                                   onchange="previewCover(event)">
                            <div class="cover-info">
                                <label for="coverImage">
                                    <i class="fa fa-image"></i>
                                    Book Cover Image
                                </label>
                                <p class="text-muted small">
                                    Recommended: 300x450px, JPG/PNG/WebP<br>
                                    Max size: 2MB
                                </p>
                                <div id="coverError" class="text-danger small" style="display: none;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="bookTitle">
                            <i class="fa fa-font"></i>
                            Title *
                        </label>
                        <input type="text" 
                               id="bookTitle" 
                               required 
                               placeholder="Enter book title"
                               class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="bookAuthor">
                            <i class="fa fa-user"></i>
                            Author *
                        </label>
                        <input type="text" 
                               id="bookAuthor" 
                               required 
                               placeholder="Enter author name"
                               class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="bookISBN">
                            <i class="fa fa-barcode"></i>
                            ISBN
                        </label>
                        <input type="text" 
                               id="bookISBN" 
                               placeholder="Enter ISBN"
                               class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="bookCategory">
                            <i class="fa fa-tag"></i>
                            Category
                        </label>
                        <select id="bookCategory" class="form-control">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Or enter custom category:</small>
                        <input type="text" 
                               id="bookCategoryCustom" 
                               placeholder="Enter custom category"
                               class="form-control" style="margin-top: 5px;">
                    </div>
                    
                    <div class="form-group">
                        <label for="bookPublisher">
                            <i class="fa fa-building"></i>
                            Publisher
                        </label>
                        <input type="text" 
                               id="bookPublisher" 
                               placeholder="Enter publisher"
                               class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="bookYear">
                            <i class="fa fa-calendar"></i>
                            Year Published
                        </label>
                        <input type="number" 
                               id="bookYear" 
                               min="0" 
                               max="<?= date('Y') ?>"
                               placeholder="YYYY"
                               class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="bookDescription">
                        <i class="fa fa-align-left"></i>
                        Description
                    </label>
                    <textarea id="bookDescription" 
                              rows="3" 
                              placeholder="Enter book description"
                              class="form-control"></textarea>
                </div>
                
                <!-- Initial Copies - Only for new books -->
                <div class="form-section" id="initialCopiesSection">
                    <h4>
                        <i class="fa fa-copy"></i>
                        Initial Copies
                    </h4>
                    <p class="text-muted">How many copies do you want to add initially?</p>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="initialCopies">
                                <i class="fa fa-box"></i>
                                Number of Copies *
                            </label>
                            <input type="number" 
                                   id="initialCopies" 
                                   min="1" 
                                   max="50"
                                   value="5"
                                   required
                                   class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="copyCondition">
                                <i class="fa fa-clipboard-check"></i>
                                Condition *
                            </label>
                            <select id="copyCondition" class="form-control" required>
                                <option value="new">New</option>
                                <option value="good" selected>Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="copyNotes">
                            <i class="fa fa-sticky-note"></i>
                            Notes (Optional)
                        </label>
                        <textarea id="copyNotes" 
                                  rows="2" 
                                  placeholder="Any notes about these copies..."
                                  class="form-control"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">
                <i class="fa fa-times"></i>
                Cancel
            </button>
            <button type="button" class="btn btn-primary" id="saveBook">
                <i class="fa fa-save"></i>
                Save Book
            </button>
        </div>
    </div>
</div>

<!-- Add Copies Modal -->
<div id="copiesModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="copiesModalTitle">
                <i class="fa fa-copy"></i>
                Add Copies
            </h3>
            <button class="modal-close" onclick="closeCopiesModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="copiesForm">
                <input type="hidden" id="copyBookId">
                <div class="form-group">
                    <label>
                        <i class="fa fa-book"></i>
                        Book
                    </label>
                    <input type="text" id="copyBookTitle" class="form-control" readonly>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="copiesCount">
                            <i class="fa fa-box"></i>
                            Number of Copies *
                        </label>
                        <input type="number" 
                               id="copiesCount" 
                               min="1" 
                               max="50"
                               value="1"
                               required
                               class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="copiesCondition">
                            <i class="fa fa-clipboard-check"></i>
                            Condition *
                        </label>
                        <select id="copiesCondition" class="form-control" required>
                            <option value="new">New</option>
                            <option value="good" selected>Good</option>
                            <option value="fair">Fair</option>
                            <option value="poor">Poor</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="copiesNotes">
                        <i class="fa fa-sticky-note"></i>
                        Notes (Optional)
                    </label>
                    <textarea id="copiesNotes" 
                              rows="2" 
                              placeholder="Any notes about these copies..."
                              class="form-control"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeCopiesModal()">
                <i class="fa fa-times"></i>
                Cancel
            </button>
            <button type="button" class="btn btn-primary" id="saveCopies">
                <i class="fa fa-save"></i>
                Add Copies
            </button>
        </div>
    </div>
</div>

<!-- View Copies Modal -->
<div id="viewCopiesModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="viewCopiesModalTitle">
                <i class="fa fa-copy"></i>
                Book Copies
            </h3>
            <button class="modal-close" onclick="closeViewCopiesModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div id="copiesContent">
                <div class="loading">Loading copies...</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeViewCopiesModal()">
                <i class="fa fa-times"></i>
                Close
            </button>
        </div>
    </div>
</div>

<!-- View Book Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="viewModalTitle">
                <i class="fa fa-book"></i>
                Book Details
            </h3>
            <button class="modal-close" onclick="closeViewModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div id="bookDetailsContent"></div>
        </div>
    </div>
</div>

<!-- Copy Details Modal -->
<div id="copyDetailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="copyDetailsModalTitle">
                <i class="fa fa-copy"></i>
                Copy Details
            </h3>
            <button class="modal-close" onclick="closeCopyDetailsModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div id="copyDetailsContent"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeCopyDetailsModal()">
                <i class="fa fa-times"></i>
                Close
            </button>
        </div>
    </div>
</div>

<!-- Edit Copy Modal -->
<div id="editCopyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="editCopyModalTitle">
                <i class="fa fa-edit"></i>
                Edit Copy
            </h3>
            <button class="modal-close" onclick="closeEditCopyModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="editCopyForm">
                <input type="hidden" id="editCopyId">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="editCopyNumber">
                            <i class="fa fa-hashtag"></i>
                            Copy Number
                        </label>
                        <input type="text" 
                               id="editCopyNumber" 
                               required 
                               class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="editBarcode">
                            <i class="fa fa-barcode"></i>
                            Barcode
                        </label>
                        <input type="text" 
                               id="editBarcode" 
                               class="form-control">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="editStatus">
                            <i class="fa fa-circle"></i>
                            Status
                        </label>
                        <select id="editStatus" class="form-control">
                            <option value="available">Available</option>
                            <option value="borrowed">Borrowed</option>
                            <option value="reserved">Reserved</option>
                            <option value="lost">Lost</option>
                            <option value="damaged">Damaged</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="editCondition">
                            <i class="fa fa-clipboard-check"></i>
                            Condition
                        </label>
                        <select id="editCondition" class="form-control">
                            <option value="new">New</option>
                            <option value="good">Good</option>
                            <option value="fair">Fair</option>
                            <option value="poor">Poor</option>
                            <option value="damaged">Damaged</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="editSection">
                            <i class="fa fa-map-marker"></i>
                            Section
                        </label>
                        <select id="editSection" class="form-control" onchange="checkSlotAvailability()">
                            <option value="">Select Section</option>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                            <option value="E">E</option>
                            <option value="F">F</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="editShelf">
                            <i class="fa fa-layer-group"></i>
                            Shelf (1-5)
                        </label>
                        <select id="editShelf" class="form-control" onchange="checkSlotAvailability()">
                            <option value="">Select Shelf</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="editRow">
                            <i class="fa fa-grip-lines"></i>
                            Row (1-6)
                        </label>
                        <select id="editRow" class="form-control" onchange="checkSlotAvailability()">
                            <option value="">Select Row</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="editSlot">
                            <i class="fa fa-box-open"></i>
                            Slot (1-12)
                        </label>
                        <select id="editSlot" class="form-control" onchange="checkSlotAvailability()">
                            <option value="">Select Slot</option>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>"><?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                        <div id="slotStatus" class="small mt-1" style="display: none;"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="editNotes">
                        <i class="fa fa-sticky-note"></i>
                        Notes
                    </label>
                    <textarea id="editNotes" 
                              rows="3" 
                              placeholder="Enter notes about this copy..."
                              class="form-control"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeEditCopyModal()">
                <i class="fa fa-times"></i>
                Cancel
            </button>
            <button type="button" class="btn btn-primary" id="saveEditCopy">
                <i class="fa fa-save"></i>
                Save Changes
            </button>
        </div>
    </div>
</div>

<!-- Slot Occupancy Modal -->
<div id="slotOccupancyModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>
                <i class="fa fa-map-marker"></i>
                Slot Occupancy Check
            </h3>
            <button class="modal-close" onclick="closeSlotOccupancyModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div id="slotOccupancyContent">
                <div class="loading">Checking slot occupancy...</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeSlotOccupancyModal()">
                <i class="fa fa-times"></i>
                Close
            </button>
        </div>
    </div>
</div>

<style>
    /* Add slot status indicators */
    .slot-available {
        color: #10b981;
    }
    
    .slot-occupied {
        color: #ef4444;
    }
    
    .slot-checking {
        color: #f59e0b;
    }
    
    .mt-1 {
        margin-top: 0.25rem;
    }
    
    /* Slot occupancy table styles */
    .slot-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
    }
    
    .slot-table th {
        background: #f8fafc;
        padding: 0.5rem;
        text-align: center;
        border: 1px solid #e5e7eb;
    }
    
    .slot-table td {
        padding: 0.5rem;
        text-align: center;
        border: 1px solid #e5e7eb;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .slot-table td:hover {
        background: #f9fafb;
    }
    
    .slot-empty {
        background: #dcfce7;
        color: #166534;
    }
    
    .slot-occupied-book {
        background: #fee2e2;
        color: #991b1b;
    }
    
    .slot-selected {
        background: #3b82f6;
        color: white;
    }
    
    .slot-info {
        margin-top: 1rem;
        padding: 1rem;
        background: #f8fafc;
        border-radius: 0.375rem;
        border-left: 4px solid #3b82f6;
    }
    
    .slot-info h5 {
        margin: 0 0 0.5rem 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .slot-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }
    
    .slot-details-item {
        padding: 0.5rem;
        background: white;
        border-radius: 0.25rem;
    }
    
    .slot-details-item label {
        font-weight: 500;
        color: #374151;
        font-size: 0.75rem;
    }
    
    .slot-details-item p {
        margin: 0.25rem 0 0 0;
        font-size: 0.875rem;
    }
    
    /* Existing styles remain the same */
    .book-cover-container {
        width: 50px;
        height: 70px;
        margin: 0 auto;
        position: relative;
        overflow: hidden;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        background: #f5f5f5;
    }
    
    .book-cover-preview {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }
    
    .book-cover-preview:hover {
        transform: scale(1.05);
    }
    
    .cover-image-section {
        display: flex;
        gap: 1.5rem;
        align-items: flex-start;
        margin-bottom: 1.5rem;
        padding: 1rem;
        background: #f8fafc;
        border-radius: 0.5rem;
        border: 2px dashed #d1d5db;
    }
    
    .cover-preview-container {
        position: relative;
        width: 120px;
        height: 180px;
        flex-shrink: 0;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    
    .cover-preview {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .cover-preview-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .cover-preview-container:hover .cover-preview-overlay {
        opacity: 1;
    }
    
    .cover-info {
        flex: 1;
    }
    
    .cover-info label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: #374151;
    }
    
    .page-header {
        margin-bottom: 2rem;
    }
    
    .page-header h1 {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.5rem;
    }
    
    .subtitle {
        color: #6b7280;
        font-size: 0.95rem;
    }
    
    .card {
        background: white;
        border-radius: 0.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        margin-bottom: 2rem;
        overflow: hidden;
    }
    
    .card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        background: #f9fafb;
    }
    
    .card-header .badge {
        font-size: 0.75rem;
        margin-left: 0.5rem;
        background: #3b82f6;
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
    }
    
    .card-body {
        padding: 1.5rem;
    }
    
    .filter-section {
        padding: 1rem 1.5rem;
        background: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .filter-form {
        width: 100%;
    }
    
    .filter-row {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr auto;
        gap: 1rem;
        align-items: end;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .filter-group label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #374151;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .filter-input, .filter-select {
        padding: 0.5rem 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 0.375rem;
        font-size: 0.875rem;
    }
    
    .filter-input:focus, .filter-select:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .book-title {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .isbn-badge, .category-badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        background: #e0f2fe;
        color: #0369a1;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-family: monospace;
    }
    
    .category-badge {
        background: #f3e8ff;
        color: #7c3aed;
    }
    
    .status-available {
        background: #dcfce7;
        color: #166534;
    }
    
    .status-low {
        background: #fef3c7;
        color: #92400e;
    }
    
    .status-unavailable {
        background: #fee2e2;
        color: #991b1b;
    }
    
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        justify-content: center;
    }
    
    .btn-icon {
        width: 2rem;
        height: 2rem;
        border: none;
        border-radius: 0.375rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .btn-view {
        background: #dbeafe;
        color: #1d4ed8;
    }
    
    .btn-view:hover {
        background: #bfdbfe;
    }
    
    .btn-edit {
        background: #fef3c7;
        color: #92400e;
    }
    
    .btn-edit:hover {
        background: #fde68a;
    }
    
    .btn-copy {
        background: #d1fae5;
        color: #065f46;
    }
    
    .btn-copy:hover {
        background: #a7f3d0;
    }
    
    .btn-delete {
        background: #fee2e2;
        color: #dc2626;
    }
    
    .btn-delete:hover {
        background: #fecaca;
    }
    
    .pagination {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1rem;
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .pagination-info {
        color: #6b7280;
        font-size: 0.875rem;
    }
    
    .pagination-controls {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .page-numbers {
        display: flex;
        gap: 0.25rem;
    }
    
    .page-number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2rem;
        height: 2rem;
        padding: 0 0.5rem;
        border: 1px solid #d1d5db;
        border-radius: 0.375rem;
        color: #374151;
        text-decoration: none;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    
    .page-number:hover {
        background: #f9fafb;
    }
    
    .page-number.active {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    
    .page-dots {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2rem;
        height: 2rem;
        color: #6b7280;
    }
    
    .summary-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        margin-bottom: 2rem;
    }
    
    .stat-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem;
        background: white;
        border-radius: 0.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .stat-item i {
        font-size: 1.5rem;
        color: #3b82f6;
    }
    
    .stat-item h4 {
        margin: 0;
        font-size: 1.5rem;
        color: #1f2937;
    }
    
    .stat-item span {
        font-size: 0.875rem;
        color: #6b7280;
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
    }
    
    .empty-icon {
        font-size: 3rem;
        color: #9ca3af;
        margin-bottom: 1rem;
    }
    
    .empty-state h3 {
        color: #374151;
        margin-bottom: 0.5rem;
    }
    
    .empty-state p {
        color: #6b7280;
        max-width: 24rem;
        margin: 0 auto;
    }
    
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 20px;
    }
    
    .modal-content {
        background: white;
        border-radius: 0.5rem;
        width: 100%;
        max-width: 600px;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        display: flex;
        flex-direction: column;
    }
    
    .modal-lg {
        max-width: 800px;
    }
    
    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-shrink: 0;
    }
    
    .modal-header h3 {
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 1.25rem;
        color: #6b7280;
        cursor: pointer;
        padding: 0.25rem;
    }
    
    .modal-close:hover {
        color: #374151;
    }
    
    .modal-body {
        padding: 1.5rem;
        overflow-y: auto;
        flex: 1;
    }
    
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        flex-shrink: 0;
        background: #f9fafb;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .form-group {
        margin-bottom: 1rem;
    }
    
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    
    .form-group label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: #374151;
    }
    
    .form-control {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 0.375rem;
        font-size: 0.875rem;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .form-section {
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .form-section h4 {
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn {
        padding: 0.625rem 1.25rem;
        border: none;
        border-radius: 0.375rem;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        min-height: 40px;
    }
    
    .btn-primary {
        background: #3b82f6;
        color: white;
    }
    
    .btn-primary:hover {
        background: #2563eb;
    }
    
    .btn-secondary {
        background: #6b7280;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #4b5563;
    }
    
    .btn-outline {
        background: white;
        color: #374151;
        border: 1px solid #d1d5db;
    }
    
    .btn-outline:hover {
        background: #f9fafb;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
    }
    
    .text-center {
        text-align: center;
    }
    
    .text-muted {
        color: #6b7280;
    }
    
    .text-warning {
        color: #f59e0b;
    }
    
    .text-danger {
        color: #ef4444;
    }
    
    .small {
        font-size: 0.75rem;
    }
    
    .d-block {
        display: block;
    }
    
    .flex-space-between {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .data-table th {
        background: #f9fafb;
        padding: 0.75rem 1rem;
        text-align: left;
        font-weight: 600;
        color: #374151;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .data-table td {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .data-table tr:hover {
        background: #f9fafb;
    }
    
    .text-warning {
        color: #f59e0b;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .copy-details {
        padding: 1rem;
    }
    
    .copy-details-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1.5rem;
    }
    
    .copy-details-header h4 {
        margin: 0;
        flex: 1;
    }
    
    .copy-details-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .copy-details-item {
        padding: 0.75rem;
        background: #f9fafb;
        border-radius: 0.375rem;
    }
    
    .copy-details-item label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 500;
        color: #374151;
        margin-bottom: 0.25rem;
    }
    
    .copy-details-item p {
        margin: 0;
        color: #6b7280;
    }
    
    .copy-details-section {
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .copy-details-section h5 {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }
    
    .badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 9999px;
    }
    
    .badge-outline {
        background: transparent;
        border: 1px solid #d1d5db;
        color: #374151;
    }
    
    .badge-outline:hover {
        background: #f9fafb;
    }
    
    .copies-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
    }
    
    .copies-table th {
        background: #f8fafc;
        padding: 0.75rem;
        text-align: left;
        font-weight: 600;
        color: #374151;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .copies-table td {
        padding: 0.75rem;
        border-bottom: 1px solid #e5e7eb;
        vertical-align: top;
    }
    
    .copy-status {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .status-available { background: #dcfce7; color: #166534; }
    .status-borrowed { background: #fef3c7; color: #92400e; }
    .status-reserved { background: #e0e7ff; color: #3730a3; }
    .status-lost { background: #fee2e2; color: #991b1b; }
    .status-damaged { background: #f5f5f4; color: #57534e; }
    .status-maintenance { background: #f3e8ff; color: #7c3aed; }
    
    .copy-condition {
        display: inline-block;
        padding: 0.125rem 0.375rem;
        border-radius: 0.125rem;
        font-size: 0.75rem;
    }
    
    .condition-new { background: #f0f9ff; color: #0369a1; }
    .condition-good { background: #f0fdf4; color: #166534; }
    .condition-fair { background: #fefce8; color: #854d0e; }
    .condition-poor { background: #fef2f2; color: #991b1b; }
    .condition-damaged { background: #f5f5f4; color: #57534e; }
    
    .location-badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        background: #f8fafc;
        color: #374151;
        border: 1px solid #e5e7eb;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-family: monospace;
    }
    
    .loading {
        text-align: center;
        padding: 2rem;
        color: #6b7280;
    }
    
    .book-details {
        padding: 1rem;
    }
    
    .book-details-header {
        display: flex;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .book-details-cover {
        flex-shrink: 0;
        width: 160px;
        height: 240px;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    
    .book-details-cover img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .book-details-info {
        flex: 1;
    }
    
    .book-details-info h4 {
        margin: 0 0 0.5rem 0;
        font-size: 1.5rem;
        color: #1f2937;
    }
    
    .book-details-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .book-details-meta-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #6b7280;
        font-size: 0.875rem;
    }
    
    .book-details-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .book-details-item {
        padding: 0.75rem;
        background: #f9fafb;
        border-radius: 0.375rem;
    }
    
    .book-details-item label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 500;
        color: #374151;
        margin-bottom: 0.25rem;
    }
    
    .book-details-item p {
        margin: 0;
        color: #6b7280;
    }
    
    .book-details-section {
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .book-details-section h5 {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }
    
    .book-details-footer {
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .modal-footer {
        position: relative;
        z-index: 10;
    }
    
    .modal-footer .btn {
        position: relative;
        z-index: 11;
    }
    
    @media (max-width: 1024px) {
        .filter-row {
            grid-template-columns: 1fr 1fr;
        }
        
        .summary-stats {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .book-details-grid {
            grid-template-columns: 1fr;
        }
        
        .book-details-header {
            flex-direction: column;
        }
        
        .book-details-cover {
            width: 100%;
            height: 300px;
        }
        
        .slot-details {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 768px) {
        .filter-row {
            grid-template-columns: 1fr;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
        
        .modal-content {
            width: 95%;
            margin: 1rem;
        }
        
        .pagination-controls {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .summary-stats {
            grid-template-columns: 1fr;
        }
        
        .action-buttons {
            flex-wrap: wrap;
        }
        
        .cover-image-section {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        
        .book-details-cover {
            width: 100%;
            max-width: 200px;
            margin: 0 auto;
        }
    }
</style>

<script>
// Modal Functions
function showModal() {
    document.getElementById('bookModal').style.display = 'flex';
    document.getElementById('initialCopiesSection').style.display = 'block';
    document.getElementById('copyNotes').value = '';
    resetForm();
}

function closeModal() {
    document.getElementById('bookModal').style.display = 'none';
    resetForm();
}

function showCopiesModal(bookId, bookTitle) {
    document.getElementById('copyBookId').value = bookId;
    document.getElementById('copyBookTitle').value = bookTitle;
    document.getElementById('copiesModalTitle').innerHTML = `<i class="fa fa-copy"></i> Add Copies to "${escapeHtml(bookTitle)}"`;
    document.getElementById('copiesModal').style.display = 'flex';
}

function closeCopiesModal() {
    document.getElementById('copiesModal').style.display = 'none';
    document.getElementById('copiesForm').reset();
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

function closeViewCopiesModal() {
    document.getElementById('viewCopiesModal').style.display = 'none';
}

function showCopyDetailsModal() {
    document.getElementById('copyDetailsModal').style.display = 'flex';
}

function closeCopyDetailsModal() {
    document.getElementById('copyDetailsModal').style.display = 'none';
}

function showEditCopyModal() {
    document.getElementById('editCopyModal').style.display = 'flex';
}

function closeEditCopyModal() {
    document.getElementById('editCopyModal').style.display = 'none';
    // Reset slot status
    document.getElementById('slotStatus').style.display = 'none';
    document.getElementById('slotStatus').textContent = '';
}

function showSlotOccupancyModal() {
    document.getElementById('slotOccupancyModal').style.display = 'flex';
}

function closeSlotOccupancyModal() {
    document.getElementById('slotOccupancyModal').style.display = 'none';
}

// Check slot availability in edit copy modal
async function checkSlotAvailability() {
    const section = document.getElementById('editSection').value;
    const shelf = document.getElementById('editShelf').value;
    const row = document.getElementById('editRow').value;
    const slot = document.getElementById('editSlot').value;
    const slotStatus = document.getElementById('slotStatus');
    
    if (!section || !shelf || !row || !slot) {
        slotStatus.style.display = 'none';
        return;
    }
    
    // Show checking status
    slotStatus.style.display = 'block';
    slotStatus.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Checking slot availability...';
    slotStatus.className = 'small mt-1 slot-checking';
    
    try {
        // Check if slot is occupied
        const response = await fetch(`../api/ai_recommendations.php?action=check_slot&section=${section}&shelf=${shelf}&row=${row}&slot=${slot}`);
        if (!response.ok) throw new Error('Failed to check slot');
        
        const result = await response.json();
        
        if (result.occupied) {
            slotStatus.innerHTML = `<i class="fa fa-times-circle"></i> Slot occupied by: <strong>${escapeHtml(result.book_title)}</strong>`;
            slotStatus.className = 'small mt-1 slot-occupied';
        } else {
            slotStatus.innerHTML = '<i class="fa fa-check-circle"></i> Slot available';
            slotStatus.className = 'small mt-1 slot-available';
        }
        
    } catch (error) {
        slotStatus.innerHTML = `<i class="fa fa-exclamation-circle"></i> Unable to check slot: ${escapeHtml(error.message)}`;
        slotStatus.className = 'small mt-1 slot-checking';
    }
}

// Cover image preview
function previewCover(event) {
    const input = event.target;
    const preview = document.getElementById('coverPreview');
    const errorDiv = document.getElementById('coverError');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const maxSize = 2 * 1024 * 1024; // 2MB
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
        
        // Validate file type
        if (!allowedTypes.includes(file.type)) {
            errorDiv.textContent = 'Invalid file type. Please upload JPG, PNG, WebP or GIF.';
            errorDiv.style.display = 'block';
            input.value = '';
            preview.src = '../assets/images/default-book-cover.jpg';
            return;
        }
        
        // Validate file size
        if (file.size > maxSize) {
            errorDiv.textContent = 'File size too large. Maximum size is 2MB.';
            errorDiv.style.display = 'block';
            input.value = '';
            preview.src = '../assets/images/default-book-cover.jpg';
            return;
        }
        
        errorDiv.style.display = 'none';
        
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }
}

function resetForm() {
    document.getElementById('bookForm').reset();
    document.getElementById('bookId').value = '';
    document.getElementById('existingCoverImage').value = '';
    document.getElementById('bookCategoryCustom').value = '';
    document.getElementById('initialCopies').value = '5';
    document.getElementById('copyNotes').value = '';
    document.getElementById('coverPreview').src = '../assets/images/default-book-cover.jpg';
    document.getElementById('coverError').style.display = 'none';
    document.getElementById('bookModalTitle').innerHTML = '<i class="fa fa-book"></i><span>Add New Book</span>';
}

// Load AI recommendations
async function loadAIRecommendations() {
    try {
        const response = await fetch('../api/ai_recommendations.php?action=recommend');
        if (!response.ok) throw new Error('Failed to load AI recommendations');
        
        const recommendations = await response.json();
        
        let html = '';
        if (!recommendations || recommendations.length === 0) {
            html = '<div class="empty-state"><p>No AI recommendations needed - all books have locations!</p></div>';
        } else {
            html = `
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Book</th>
                                <th>Recommended Location</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            recommendations.forEach(rec => {
                const locationOccupied = rec.location_occupied || false;
                const locationStatus = locationOccupied ? 
                    '<span class="badge badge-warning">Occupied - AI will find alternative</span>' :
                    '<span class="badge badge-success">Available</span>';
                
                html += `
                    <tr>
                        <td>
                            <strong>${escapeHtml(rec.title)}</strong><br>
                            <small>${escapeHtml(rec.author)}</small>
                        </td>
                        <td>
                            <span class="location-badge">
                                ${rec.ai_location || 'Not set'}
                            </span><br>
                            ${locationStatus}
                        </td>
                        <td>${rec.category_name || 'Uncategorized'}</td>
                        <td>
                            <span class="badge badge-warning">
                                <i class="fa fa-robot"></i> AI Recommended
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="applyBookLocation(${rec.book_id})">
                                <i class="fa fa-check"></i> Apply AI
                            </button>
                            <button class="btn btn-sm btn-outline" onclick="showSlotOccupancyForBook(${rec.book_id})">
                                <i class="fa fa-map"></i> Check Slots
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += `</tbody></table></div>`;
        }
        
        document.getElementById('aiRecommendations').innerHTML = html;
        document.getElementById('aiRecommendationCount').textContent = recommendations?.length || 0;
        
    } catch (error) {
        console.error('Error loading AI recommendations:', error);
        document.getElementById('aiRecommendations').innerHTML = 
            `<div class="error">Error loading recommendations: ${escapeHtml(error.message)}</div>`;
    }
}

// Show slot occupancy for a specific book
async function showSlotOccupancyForBook(bookId) {
    try {
        // Get book details
        const bookResponse = await fetch(`../api/dispatch.php?resource=books&id=${bookId}`);
        if (!bookResponse.ok) throw new Error('Failed to fetch book details');
        const book = await bookResponse.json();
        
        // Get AI recommendation
        const aiResponse = await fetch(`../api/ai_recommendations.php?action=recommend&book_id=${bookId}`);
        if (!aiResponse.ok) throw new Error('Failed to fetch AI recommendation');
        const aiRec = await aiResponse.json();
        
        // Get all occupied slots in the recommended section
        const section = aiRec.default_section || 'A';
        const slotsResponse = await fetch(`../api/ai_recommendations.php?action=search_location&section=${section}`);
        if (!slotsResponse.ok) throw new Error('Failed to fetch slot data');
        const occupiedSlots = await slotsResponse.json();
        
        // Get library map config for this section
        const config = <?= json_encode($library_map) ?>.find(c => c.section === section) || {
            shelf_count: 5,
            rows_per_shelf: 6,
            slots_per_row: 12
        };
        
        // Create slot table
        let html = `
            <div class="slot-info">
                <h5><i class="fa fa-book"></i> ${escapeHtml(book.title)}</h5>
                <p>AI Recommended Location: <strong>${aiRec.ai_location || 'Not set'}</strong></p>
                <div class="slot-details">
                    <div class="slot-details-item">
                        <label><i class="fa fa-map-marker"></i> Section:</label>
                        <p>${section}</p>
                    </div>
                    <div class="slot-details-item">
                        <label><i class="fa fa-layer-group"></i> Shelves:</label>
                        <p>${config.shelf_count}</p>
                    </div>
                    <div class="slot-details-item">
                        <label><i class="fa fa-grip-lines"></i> Rows per Shelf:</label>
                        <p>${config.rows_per_shelf}</p>
                    </div>
                    <div class="slot-details-item">
                        <label><i class="fa fa-box-open"></i> Slots per Row:</label>
                        <p>${config.slots_per_row}</p>
                    </div>
                </div>
            </div>
            
            <div class="mt-2">
                <h5>Slot Occupancy Map</h5>
                <p class="text-muted small">Green = Available, Red = Occupied, Blue = AI Recommended Slot</p>
            </div>
            
            <div class="table-responsive">
                <table class="slot-table">
                    <thead>
                        <tr>
                            <th>Shelf</th>
                            <th>Row</th>
        `;
        
        // Create slot headers (1-12)
        for (let slot = 1; slot <= config.slots_per_row; slot++) {
            html += `<th>${slot}</th>`;
        }
        
        html += `</tr></thead><tbody>`;
        
        // Create table rows for each shelf and row
        for (let shelf = 1; shelf <= config.shelf_count; shelf++) {
            for (let row = 1; row <= config.rows_per_shelf; row++) {
                html += `<tr>`;
                if (row === 1) {
                    html += `<td rowspan="${config.rows_per_shelf}" style="vertical-align: middle;">Shelf ${shelf}</td>`;
                }
                html += `<td>Row ${row}</td>`;
                
                for (let slot = 1; slot <= config.slots_per_row; slot++) {
                    const isRecommended = (shelf == aiRec.shelf_recommendation && 
                                          row == aiRec.row_recommendation && 
                                          slot == aiRec.slot_recommendation);
                    
                    // Check if slot is occupied
                    const occupiedSlot = occupiedSlots.find(s => 
                        s.section === section && 
                        s.shelf == shelf && 
                        s.row_number == row && 
                        s.slot == slot
                    );
                    
                    let slotClass = 'slot-empty';
                    let slotTitle = `Shelf ${shelf}, Row ${row}, Slot ${slot}: Available`;
                    
                    if (occupiedSlot) {
                        slotClass = 'slot-occupied-book';
                        slotTitle = `Shelf ${shelf}, Row ${row}, Slot ${slot}: Occupied by ${occupiedSlot.title}`;
                    }
                    
                    if (isRecommended) {
                        slotClass = 'slot-selected';
                        slotTitle = `Shelf ${shelf}, Row ${row}, Slot ${slot}: AI Recommended Location`;
                    }
                    
                    html += `<td class="${slotClass}" title="${slotTitle}">`;
                    
                    if (occupiedSlot) {
                        html += `<i class="fa fa-book"></i>`;
                    } else if (isRecommended) {
                        html += `<i class="fa fa-robot"></i>`;
                    } else {
                        html += `&nbsp;`;
                    }
                    
                    html += `</td>`;
                }
                
                html += `</tr>`;
            }
        }
        
        html += `</tbody></table></div>`;
        
        // Add occupied slots list
        const occupiedInSection = occupiedSlots.filter(s => s.section === section);
        if (occupiedInSection.length > 0) {
            html += `
                <div class="slot-info mt-2">
                    <h5><i class="fa fa-exclamation-triangle"></i> Occupied Slots in Section ${section}</h5>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Location</th>
                                    <th>Book</th>
                                    <th>Author</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            occupiedInSection.forEach(slot => {
                html += `
                    <tr>
                        <td>${slot.section}-${slot.shelf}-${slot.row_number}-${slot.slot}</td>
                        <td>${escapeHtml(slot.title)}</td>
                        <td>${escapeHtml(slot.author)}</td>
                        <td><span class="copy-status status-${slot.status}">${slot.status}</span></td>
                    </tr>
                `;
            });
            
            html += `</tbody></table></div></div>`;
        }
        
        document.getElementById('slotOccupancyContent').innerHTML = html;
        showSlotOccupancyModal();
        
    } catch (error) {
        document.getElementById('slotOccupancyContent').innerHTML = 
            `<div class="error">Error loading slot occupancy: ${escapeHtml(error.message)}</div>`;
        showSlotOccupancyModal();
    }
}

// Apply AI location to a book
async function applyBookLocation(bookId) {
    try {
        // Get AI recommendation for this book
        const response = await fetch(`../api/ai_recommendations.php?action=recommend&book_id=${bookId}`);
        if (!response.ok) throw new Error('Failed to get recommendation');
        
        const recommendation = await response.json();
        
        if (!recommendation.ai_location) {
            alert('No AI recommendation available for this book');
            return;
        }
        
        if (confirm(`Apply AI recommendation: ${recommendation.ai_location}?\n\nThis will place the book in the recommended location or find a nearby available slot if occupied.`)) {
            // Get all copies of this book
            const copiesResponse = await fetch(`../api/book_copies.php?book_id=${bookId}`);
            if (!copiesResponse.ok) throw new Error('Failed to fetch copies');
            
            const copies = await copiesResponse.json();
            
            const csrf = sessionStorage.getItem('csrf') || '';
            let applied = 0;
            let errors = 0;
            
            for (const copy of copies) {
                if (!copy.current_section && copy.status === 'available') {
                    try {
                        const locationResponse = await fetch('../api/ai_recommendations.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': csrf
                            },
                            body: JSON.stringify({
                                copy_id: copy.id,
                                section: recommendation.default_section || 'A',
                                shelf: recommendation.shelf_recommendation || 1,
                                row: recommendation.row_recommendation || 1,
                                slot: recommendation.slot_recommendation || 1
                            })
                        });
                        
                        if (locationResponse.ok) {
                            applied++;
                        } else {
                            errors++;
                            console.error('Failed to apply location for copy', copy.id);
                        }
                    } catch (error) {
                        errors++;
                        console.error('Error applying location:', error);
                    }
                }
            }
            
            if (applied > 0) {
                alert(`Successfully applied locations to ${applied} copies${errors > 0 ? ` (${errors} failed)` : ''}`);
                loadAIRecommendations(); // Refresh the list
            } else {
                alert(`No copies were updated. Make sure the book has available copies without locations.`);
            }
        }
        
    } catch (error) {
        alert('Error applying location: ' + error.message);
    }
}

// Show book copies
async function showCopies(bookId) {
    try {
        // Show loading
        document.getElementById('copiesContent').innerHTML = '<div class="loading">Loading copies...</div>';
        document.getElementById('viewCopiesModalTitle').innerHTML = '<i class="fa fa-copy"></i> Loading copies...';
        document.getElementById('viewCopiesModal').style.display = 'flex';
        
        // Fetch book details
        const bookResponse = await fetch(`../api/dispatch.php?resource=books&id=${bookId}`);
        if (!bookResponse.ok) throw new Error('Failed to fetch book details');
        const book = await bookResponse.json();
        
        // Fetch copies
        const copiesResponse = await fetch(`../api/book_copies.php?book_id=${bookId}`);
        if (!copiesResponse.ok) throw new Error('Failed to fetch copies');
        const copies = await copiesResponse.json();
        
        // Create HTML content
        let html = `
            <div class="book-header">
                <h4>${escapeHtml(book.title)}</h4>
                <p class="text-muted">by ${escapeHtml(book.author)}</p>
            </div>
        `;
        
        if (!copies || copies.length === 0) {
            html += `<div class="empty-state">
                        <p>No copies found for this book.</p>
                     </div>`;
        } else {
            html += `
                <div class="table-responsive">
                    <table class="copies-table">
                        <thead>
                            <tr>
                                <th>Copy #</th>
                                <th>Barcode</th>
                                <th>Status</th>
                                <th>Condition</th>
                                <th>Location</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>`;
            
            copies.forEach(copy => {
                const statusClass = `status-${copy.status}`;
                const conditionClass = `condition-${copy.book_condition}`;
                const location = copy.current_section ? 
                    `${copy.current_section}-${copy.current_shelf}-${copy.current_row}-${copy.current_slot}` : 
                    'Not set';
                
                html += `
                    <tr>
                        <td><strong>${escapeHtml(copy.copy_number)}</strong></td>
                        <td>${copy.barcode ? escapeHtml(copy.barcode) : '-'}</td>
                        <td><span class="copy-status ${statusClass}">${copy.status}</span></td>
                        <td><span class="copy-condition ${conditionClass}">${copy.book_condition}</span></td>
                        <td><span class="location-badge">${location}</span></td>
                        <td>${copy.notes ? escapeHtml(copy.notes.substring(0, 30) + (copy.notes.length > 30 ? '...' : '')) : '-'}</td>
                        <td>
                            <button class="btn-icon btn-view" onclick="viewCopyDetails(${copy.id})" title="View Details">
                                <i class="fa fa-eye"></i>
                            </button>
                            <button class="btn-icon btn-edit" onclick="editCopy(${copy.id})" title="Edit Copy">
                                <i class="fa fa-pencil"></i>
                            </button>
                            <button class="btn-icon btn-delete" onclick="deleteCopy(${copy.id}, '${escapeHtml(copy.copy_number)}')" title="Delete Copy">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>`;
            });
            
            html += `</tbody></table></div>`;
            
            // Add AI location button if book has no location
            const hasNoLocation = copies.some(copy => !copy.current_section);
            if (hasNoLocation) {
                html += `
                    <div class="mt-2">
                        <button class="btn btn-sm btn-primary" onclick="applyBookLocation(${bookId})">
                            <i class="fa fa-robot"></i> Apply AI Location Recommendation
                        </button>
                    </div>
                `;
            }
        }
        
        document.getElementById('copiesContent').innerHTML = html;
        document.getElementById('viewCopiesModalTitle').innerHTML = `<i class="fa fa-copy"></i> Copies of "${escapeHtml(book.title)}"`;
        
    } catch (error) {
        document.getElementById('copiesContent').innerHTML = `
            <div class="error-state">
                <p>Error loading copies: ${escapeHtml(error.message)}</p>
            </div>`;
    }
}

// View copy details
async function viewCopyDetails(copyId) {
    try {
        const response = await fetch(`../api/book_copies_single.php?copy_id=${copyId}`);
        if (!response.ok) throw new Error('Failed to fetch copy details');
        
        const copy = await response.json();
        
        // Fetch book details
        const bookResponse = await fetch(`../api/dispatch.php?resource=books&id=${copy.book_id}`);
        const book = await bookResponse.json();
        
        const location = copy.current_section ? 
            `${copy.current_section}-${copy.current_shelf}-${copy.current_row}-${copy.current_slot}` : 
            'Not set';
        
        const html = `
            <div class="copy-details">
                <div class="copy-details-header">
                    <h4>Copy: ${escapeHtml(copy.copy_number)}</h4>
                    <span class="category-badge">${escapeHtml(book.title)}</span>
                </div>
                
                <div class="copy-details-grid">
                    <div class="copy-details-item">
                        <label><i class="fa fa-barcode"></i> Barcode</label>
                        <p>${copy.barcode ? escapeHtml(copy.barcode) : 'Not set'}</p>
                    </div>
                    
                    <div class="copy-details-item">
                        <label><i class="fa fa-circle"></i> Status</label>
                        <p><span class="copy-status status-${copy.status}">${copy.status}</span></p>
                    </div>
                    
                    <div class="copy-details-item">
                        <label><i class="fa fa-clipboard-check"></i> Condition</label>
                        <p><span class="copy-condition condition-${copy.book_condition}">${copy.book_condition}</span></p>
                    </div>
                    
                    <div class="copy-details-item">
                        <label><i class="fa fa-map-marker"></i> Location</label>
                        <p>${location}</p>
                    </div>
                </div>
                
                ${copy.acquisition_date ? `
                    <div class="copy-details-grid">
                        <div class="copy-details-item">
                            <label><i class="fa fa-calendar"></i> Acquisition Date</label>
                            <p>${copy.acquisition_date}</p>
                        </div>
                        
                        ${copy.purchase_price ? `
                            <div class="copy-details-item">
                                <label><i class="fa fa-money"></i> Purchase Price</label>
                                <p>$${parseFloat(copy.purchase_price).toFixed(2)}</p>
                            </div>
                        ` : ''}
                    </div>
                ` : ''}
                
                ${copy.notes ? `
                    <div class="copy-details-section">
                        <h5><i class="fa fa-sticky-note"></i> Notes</h5>
                        <p>${escapeHtml(copy.notes)}</p>
                    </div>
                ` : ''}
                
                <div class="copy-details-footer">
                    <small class="text-muted">
                        <i class="fa fa-clock"></i>
                        Created: ${new Date(copy.created_at).toLocaleDateString()}
                        ${copy.updated_at ? ` | Updated: ${new Date(copy.updated_at).toLocaleDateString()}` : ''}
                    </small>
                </div>
            </div>
        `;
        
        document.getElementById('copyDetailsModalTitle').innerHTML = `<i class="fa fa-copy"></i> Copy: ${escapeHtml(copy.copy_number)}`;
        document.getElementById('copyDetailsContent').innerHTML = html;
        showCopyDetailsModal();
        
    } catch (error) {
        alert('Error loading copy details: ' + error.message);
    }
}

// Edit copy
async function editCopy(copyId) {
    try {
        const response = await fetch(`../api/book_copies_single.php?copy_id=${copyId}`);
        if (!response.ok) throw new Error('Failed to fetch copy details');
        
        const copy = await response.json();
        
        // Populate form
        document.getElementById('editCopyId').value = copy.id;
        document.getElementById('editCopyNumber').value = copy.copy_number;
        document.getElementById('editBarcode').value = copy.barcode || '';
        document.getElementById('editStatus').value = copy.status;
        document.getElementById('editCondition').value = copy.book_condition;
        document.getElementById('editSection').value = copy.current_section || '';
        document.getElementById('editShelf').value = copy.current_shelf || '';
        document.getElementById('editRow').value = copy.current_row || '';
        document.getElementById('editSlot').value = copy.current_slot || '';
        document.getElementById('editNotes').value = copy.notes || '';
        
        document.getElementById('editCopyModalTitle').innerHTML = `<i class="fa fa-edit"></i> Edit Copy: ${escapeHtml(copy.copy_number)}`;
        showEditCopyModal();
        
        // Check slot availability if location is set
        if (copy.current_section && copy.current_shelf && copy.current_row && copy.current_slot) {
            setTimeout(checkSlotAvailability, 100);
        }
        
    } catch (error) {
        alert('Error loading copy for editing: ' + error.message);
    }
}

// Delete copy
async function deleteCopy(copyId, copyNumber) {
    if (!confirm(`Are you sure you want to delete copy ${copyNumber}? This action cannot be undone.`)) {
        return;
    }
    
    try {
        const csrf = sessionStorage.getItem('csrf') || '';
        const response = await fetch(`../api/book_copies_single.php?copy_id=${copyId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': csrf
            }
        });
        
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.error || 'Delete failed');
        }
        
        alert('Copy deleted successfully');
        // Close the copies modal and refresh it
        closeViewCopiesModal();
        // You can also reload the page to update the counts
        window.location.reload();
        
    } catch (error) {
        alert('Error deleting copy: ' + error.message);
    }
}

// View Book Details with cover image
async function viewBook(id) {
    try {
        const response = await fetch(`../api/dispatch.php?resource=books&id=${id}`);
        if (!response.ok) throw new Error('Failed to fetch book details');
        
        const book = await response.json();
        
        // Check for cover image
        const cover_image = book.cover_image_cache || book.cover_image || null;
        const cover_url = cover_image ? '../uploads/covers/' + escapeHtml(cover_image) : '../assets/images/default-book-cover.jpg';
        
        // Create HTML content
        const html = `
            <div class="book-details">
                <div class="book-details-header">
                    <div class="book-details-cover">
                        <img src="${cover_url}" 
                             alt="${escapeHtml(book.title)}" 
                             onerror="this.src='../assets/images/default-book-cover.jpg'">
                    </div>
                    <div class="book-details-info">
                        <h4>${escapeHtml(book.title)}</h4>
                        <div class="book-details-meta">
                            <div class="book-details-meta-item">
                                <i class="fa fa-user"></i>
                                <span>${escapeHtml(book.author)}</span>
                            </div>
                            ${book.year_published ? `
                                <div class="book-details-meta-item">
                                    <i class="fa fa-calendar"></i>
                                    <span>${book.year_published}</span>
                                </div>
                            ` : ''}
                            ${book.isbn ? `
                                <div class="book-details-meta-item">
                                    <i class="fa fa-barcode"></i>
                                    <span>${escapeHtml(book.isbn)}</span>
                                </div>
                            ` : ''}
                        </div>
                        ${book.publisher ? `
                            <div class="book-details-meta-item">
                                <i class="fa fa-building"></i>
                                <span>${escapeHtml(book.publisher)}</span>
                            </div>
                        ` : ''}
                        ${book.category ? `
                            <div class="book-details-meta-item">
                                <i class="fa fa-tag"></i>
                                <span class="category-badge">${escapeHtml(book.category)}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>
                
                <div class="book-details-grid">
                    <div class="book-details-item">
                        <label><i class="fa fa-copy"></i> Total Copies</label>
                        <p>${book.total_copies_cache || 0}</p>
                    </div>
                    
                    <div class="book-details-item">
                        <label><i class="fa fa-check-circle"></i> Available Copies</label>
                        <p>${book.available_copies_cache || 0}</p>
                    </div>
                    
                    ${book.category_id ? `
                        <div class="book-details-item">
                            <label><i class="fa fa-sitemap"></i> Category ID</label>
                            <p>${book.category_id}</p>
                        </div>
                    ` : ''}
                </div>
                
                ${book.description ? `
                    <div class="book-details-section">
                        <h5><i class="fa fa-align-left"></i> Description</h5>
                        <p>${escapeHtml(book.description)}</p>
                    </div>
                ` : ''}
                
                <div class="book-details-footer">
                    <small class="text-muted">
                        <i class="fa fa-clock"></i>
                        Created: ${new Date(book.created_at).toLocaleDateString()}
                        ${book.updated_at ? ` | Updated: ${new Date(book.updated_at).toLocaleDateString()}` : ''}
                    </small>
                </div>
            </div>
        `;
        
        document.getElementById('viewModalTitle').innerHTML = `<i class="fa fa-book"></i> ${escapeHtml(book.title)}`;
        document.getElementById('bookDetailsContent').innerHTML = html;
        document.getElementById('viewModal').style.display = 'flex';
        
    } catch (error) {
        alert('Error loading book details: ' + error.message);
    }
}

// Utility function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Add Book Button
    document.getElementById('btnAddBook').addEventListener('click', showModal);
    
    // Load AI recommendations
    loadAIRecommendations();
    
    // Add Copies buttons on table rows
    document.querySelectorAll('.add-copies-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const bookId = this.getAttribute('data-id');
            const bookTitle = this.getAttribute('data-title');
            showCopiesModal(bookId, bookTitle);
        });
    });
    
    // Edit Book Buttons
    document.querySelectorAll('.edit-book-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.getAttribute('data-id');
            try {
                const response = await fetch(`../api/dispatch.php?resource=books&id=${id}`);
                if (!response.ok) throw new Error('Failed to fetch book data');
                
                const book = await response.json();
                
                // Populate form
                document.getElementById('bookId').value = book.id;
                document.getElementById('bookTitle').value = book.title || '';
                document.getElementById('bookAuthor').value = book.author || '';
                document.getElementById('bookISBN').value = book.isbn || '';
                document.getElementById('bookCategory').value = book.category_id || '';
                document.getElementById('bookCategoryCustom').value = book.category || '';
                document.getElementById('bookPublisher').value = book.publisher || '';
                document.getElementById('bookYear').value = book.year_published || '';
                document.getElementById('bookDescription').value = book.description || '';
                document.getElementById('existingCoverImage').value = book.cover_image || '';
                
                // Set cover preview
                const cover_image = book.cover_image_cache || book.cover_image;
                if (cover_image) {
                    document.getElementById('coverPreview').src = '../uploads/covers/' + escapeHtml(cover_image);
                } else {
                    document.getElementById('coverPreview').src = '../assets/images/default-book-cover.jpg';
                }
                
                // Hide initial copies section for editing
                document.getElementById('initialCopiesSection').style.display = 'none';
                
                // Set modal title
                document.getElementById('bookModalTitle').innerHTML = 
                    `<i class="fa fa-edit"></i><span>Edit: ${escapeHtml(book.title)}</span>`;
                
                document.getElementById('bookModal').style.display = 'flex';
                
            } catch (error) {
                alert('Error loading book for editing: ' + error.message);
            }
        });
    });
    
    // Delete Book Buttons
    document.querySelectorAll('.delete-book-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.getAttribute('data-id');
            if (!confirm('Are you sure you want to delete this book? This will also delete all copies and the cover image. This action cannot be undone.')) {
                return;
            }
            
            try {
                const csrf = sessionStorage.getItem('csrf') || '';
                const response = await fetch(`../api/dispatch.php?resource=books&id=${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-Token': csrf
                    }
                });
                
                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.error || 'Delete failed');
                }
                
                alert('Book deleted successfully');
                window.location.reload();
                
            } catch (error) {
                alert('Error deleting book: ' + error.message);
            }
        });
    });
    
    // Save Book
    document.getElementById('saveBook').addEventListener('click', async function() {
        const form = document.getElementById('bookForm');
        if (!form.checkValidity()) {
            alert('Please fill in all required fields');
            return;
        }
        
        // Prepare book data
        const bookData = new FormData();
        bookData.append('title', document.getElementById('bookTitle').value.trim());
        bookData.append('author', document.getElementById('bookAuthor').value.trim());
        bookData.append('isbn', document.getElementById('bookISBN').value.trim() || '');
        bookData.append('publisher', document.getElementById('bookPublisher').value.trim() || '');
        bookData.append('year_published', document.getElementById('bookYear').value || '');
        bookData.append('description', document.getElementById('bookDescription').value.trim() || '');
        
        // Handle category (either category_id or custom category)
        const categoryId = document.getElementById('bookCategory').value;
        const customCategory = document.getElementById('bookCategoryCustom').value.trim();
        
        if (categoryId) {
            bookData.append('category_id', categoryId);
        } else if (customCategory) {
            bookData.append('category', customCategory);
        }
        
        const id = document.getElementById('bookId').value;
        const isEdit = !!id;
        
        // Handle cover image upload
        const coverFile = document.getElementById('coverImage').files[0];
        if (coverFile) {
            bookData.append('cover_image', coverFile);
        }
        
        // Only include initial copies for new books
        if (!isEdit) {
            const initialCopies = parseInt(document.getElementById('initialCopies').value) || 5;
            const copyCondition = document.getElementById('copyCondition').value;
            const copyNotes = document.getElementById('copyNotes').value.trim();
            
            if (initialCopies > 0) {
                bookData.append('initial_copies[count]', initialCopies);
                bookData.append('initial_copies[condition]', copyCondition);
                if (copyNotes) {
                    bookData.append('initial_copies[notes]', copyNotes);
                }
            }
        }
        
        try {
            const csrf = sessionStorage.getItem('csrf') || '';
            const url = isEdit ? 
                `../api/books.php?action=update&id=${id}` : 
                '../api/books.php?action=create';
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrf
                },
                body: bookData
            });
            
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.error || 'Save failed');
            }
            
            alert(`Book ${isEdit ? 'updated' : 'added'} successfully!`);
            closeModal();
            window.location.reload();
            
        } catch (error) {
            alert('Error saving book: ' + error.message);
        }
    });
    
    // Save Copies
    document.getElementById('saveCopies').addEventListener('click', async function() {
        const form = document.getElementById('copiesForm');
        if (!form.checkValidity()) {
            alert('Please fill in all required fields');
            return;
        }
        
        const bookId = document.getElementById('copyBookId').value;
        const copiesCount = parseInt(document.getElementById('copiesCount').value);
        const condition = document.getElementById('copiesCondition').value;
        const notes = document.getElementById('copiesNotes').value.trim();
        
        if (copiesCount < 1) {
            alert('Please enter at least 1 copy');
            return;
        }
        
        try {
            const csrf = sessionStorage.getItem('csrf') || '';
            const response = await fetch('../api/book_copies.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({
                    book_id: bookId,
                    count: copiesCount,
                    condition: condition,
                    notes: notes
                })
            });
            
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.error || 'Failed to add copies');
            }
            
            alert(`Successfully added ${copiesCount} copy/copies!`);
            closeCopiesModal();
            
            // Refresh the page to update counts
            window.location.reload();
            
        } catch (error) {
            alert('Error adding copies: ' + error.message);
        }
    });
    
    // Save Edit Copy
    document.getElementById('saveEditCopy').addEventListener('click', async function() {
        const copyId = document.getElementById('editCopyId').value;
        const section = document.getElementById('editSection').value;
        const shelf = document.getElementById('editShelf').value;
        const row = document.getElementById('editRow').value;
        const slot = document.getElementById('editSlot').value;
        
        // Check if trying to place in occupied slot
        if (section && shelf && row && slot) {
            try {
                const checkResponse = await fetch(`../api/ai_recommendations.php?action=check_slot&section=${section}&shelf=${shelf}&row=${row}&slot=${slot}`);
                if (checkResponse.ok) {
                    const checkResult = await checkResponse.json();
                    if (checkResult.occupied && !confirm(`This slot is occupied by "${checkResult.book_title}". Are you sure you want to place another book here?`)) {
                        return;
                    }
                }
            } catch (error) {
                console.warn('Could not check slot occupancy:', error);
            }
        }
        
        const copyData = {
            copy_number: document.getElementById('editCopyNumber').value.trim(),
            barcode: document.getElementById('editBarcode').value.trim() || null,
            status: document.getElementById('editStatus').value,
            book_condition: document.getElementById('editCondition').value,
            current_section: section || null,
            current_shelf: shelf || null,
            current_row: row || null,
            current_slot: slot || null,
            notes: document.getElementById('editNotes').value.trim() || null
        };
        
        try {
            const csrf = sessionStorage.getItem('csrf') || '';
            const response = await fetch(`../api/book_copies_single.php?copy_id=${copyId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify(copyData)
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Update failed');
            }
            
            alert('Copy updated successfully!');
            closeEditCopyModal();
            
            // If the copies modal is open, refresh it
            if (document.getElementById('viewCopiesModal').style.display === 'flex') {
                // Find which book we're viewing copies for
                const bookIdMatch = document.getElementById('viewCopiesModalTitle').textContent.match(/id: (\d+)/i);
                if (bookIdMatch) {
                    showCopies(bookIdMatch[1]);
                }
            } else {
                // Otherwise reload the page
                window.location.reload();
            }
            
        } catch (error) {
            alert('Error updating copy: ' + error.message);
        }
    });
    
    // Category selection logic
    document.getElementById('bookCategory').addEventListener('change', function() {
        if (this.value) {
            document.getElementById('bookCategoryCustom').value = '';
        }
    });
    
    document.getElementById('bookCategoryCustom').addEventListener('input', function() {
        if (this.value.trim()) {
            document.getElementById('bookCategory').value = '';
        }
    });
    
    // Click on cover image to trigger file input
    document.getElementById('coverPreview').addEventListener('click', function() {
        document.getElementById('coverImage').click();
    });
    
    // Close modals on outside click
    window.addEventListener('click', function(event) {
        const modals = ['bookModal', 'viewModal', 'copiesModal', 'viewCopiesModal', 'copyDetailsModal', 'editCopyModal', 'slotOccupancyModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target === modal) {
                if (modalId === 'bookModal') closeModal();
                if (modalId === 'viewModal') closeViewModal();
                if (modalId === 'copiesModal') closeCopiesModal();
                if (modalId === 'viewCopiesModal') closeViewCopiesModal();
                if (modalId === 'copyDetailsModal') closeCopyDetailsModal();
                if (modalId === 'editCopyModal') closeEditCopyModal();
                if (modalId === 'slotOccupancyModal') closeSlotOccupancyModal();
            }
        });
    });
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>