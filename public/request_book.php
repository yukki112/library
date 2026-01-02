<?php
// Enhanced Book Request Page with Copy Selection and Library Map
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

require_login();

$u = current_user();
// Only students and non‑staff should access this page
if (!in_array($u['role'], ['student','non_staff'], true)) {
    header('Location: dashboard.php');
    exit;
}

$username = $u['username'] ?? '';
$email = $u['email'] ?? '';
$patron_id = $u['patron_id'] ?? 0;
$roleLabel = ($u['role'] === 'non_staff') ? 'Non‑Teaching Staff' : 'Student';

// Check if we have a book_id parameter from books.php
$preSelectedBookId = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;

// Generate CSRF token for the page
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$csrf_token = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrf_token;
}

include __DIR__ . '/_header.php';
?>

<div class="page-container">
    <div class="page-header">
        <div class="header-content">
            <div class="header-title-row">
                <div>
                    <h1 class="page-title">Request a Book</h1>
                    <p class="page-subtitle">Reserve books from the library collection</p>
                </div>
                <button class="btn-view-reservations" onclick="openMyReservationsModal()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/>
                        <polyline points="7 3 7 8 15 8"/>
                    </svg>
                    View My Reservations
                </button>
            </div>
        </div>
    </div>

    <div class="request-container">
        <div class="request-layout">
            <!-- Left Panel: Search and Available Books -->
            <div class="left-panel">
                <div class="search-section card">
                    <div class="search-header">
                        <h3>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/>
                                <path d="m21 21-4.35-4.35"/>
                            </svg>
                            Search Books
                        </h3>
                    </div>
                    <div class="search-box">
                        <div class="search-input-group">
                            <div class="search-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="m21 21-4.35-4.35"/>
                                </svg>
                            </div>
                            <input type="text" 
                                   id="searchBooks" 
                                   placeholder="Search by title, author, ISBN..." 
                                   class="search-input">
                            <button id="searchBtn" class="btn-search">
                                Search
                            </button>
                        </div>
                        
                        <div class="search-filters">
                            <div class="filter-group">
                                <label for="categoryFilter" class="filter-label">Category</label>
                                <select id="categoryFilter" class="form-select">
                                    <option value="">All Categories</option>
                                    <?php
                                    $pdo = DB::conn();
                                    $categories = $pdo->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();
                                    foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['id']); ?>">
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="availabilityFilter" class="filter-label">Availability</label>
                                <select id="availabilityFilter" class="form-select">
                                    <option value="all">All Books</option>
                                    <option value="available" selected>Available Only</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Available Books Section -->
                <div class="books-section card">
                    <div class="section-header">
                        <h3>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                            </svg>
                            Available Books
                        </h3>
                        <div class="book-count" id="bookCount">0 books found</div>
                    </div>
                    
                    <div id="booksResults" class="books-results">
                        <div class="empty-state">
                            <div class="empty-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="m21 21-4.35-4.35"/>
                                    <path d="M11 8v6"/>
                                    <path d="M8 11h6"/>
                                </svg>
                            </div>
                            <p>Search for books to see available options</p>
                        </div>
                    </div>
                    
                    <div id="booksPagination" class="books-pagination"></div>
                </div>
            </div>

            <!-- Right Panel: Request Form -->
            <div class="right-panel">
                <div class="request-form-section card">
                    <!-- Request Details Header -->
                    <div class="request-details-header">
                        <div class="request-details-title">
                            <div class="request-details-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 5v14M5 12h14"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="request-details-heading">Request Details</h3>
                                <p class="request-details-subtitle">Fill in your borrowing preferences</p>
                            </div>
                        </div>
                        <div class="user-badge">
                            <?php echo htmlspecialchars($roleLabel); ?>
                        </div>
                    </div>

                    <!-- User Info Section - Enhanced -->
                    <div class="user-info-section">
                        <div class="user-info-header">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            <span>Your Information</span>
                        </div>
                        <div class="user-info-grid">
                            <div class="user-info-item">
                                <div class="user-info-label">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                    Username
                                </div>
                                <div class="user-info-value"><?php echo htmlspecialchars($username); ?></div>
                            </div>
                            <div class="user-info-item">
                                <div class="user-info-label">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="4"/>
                                        <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>
                                    </svg>
                                    Email
                                </div>
                                <div class="user-info-value"><?php echo htmlspecialchars($email); ?></div>
                            </div>
                        </div>
                    </div>

                    <div id="requestForm">
                        <!-- Selected Book Details - ALWAYS VISIBLE IF BOOK IS PRESELECTED -->
                        <div id="selectedBookSection" class="selected-book-section" 
                             style="<?php echo $preSelectedBookId > 0 ? '' : 'display: none;'; ?>">
                            <div class="selected-book-header">
                                <h4>Selected Book</h4>
                                <?php if ($preSelectedBookId > 0): ?>
                                <span class="preselected-label">Pre-selected from catalogue</span>
                                <?php else: ?>
                                <button type="button" class="btn-clear" onclick="clearSelection()">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M18 6 6 18"/>
                                        <path d="m6 6 12 12"/>
                                    </svg>
                                    Change Book
                                </button>
                                <?php endif; ?>
                            </div>
                            <div id="selectedBookCard" class="selected-book-card">
                                <?php if ($preSelectedBookId > 0): ?>
                                <div class="loading-state-small">
                                    <div class="spinner-small"></div>
                                    <p>Loading selected book...</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Available Copies Selection -->
                        <div id="copiesSection" class="copies-section" style="display: none;">
                            <div class="section-header">
                                <h4>Select Copy</h4>
                                <span class="hint">Choose a specific copy to borrow</span>
                                <button type="button" class="btn-map" onclick="openLibraryMap()">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                        <circle cx="12" cy="10" r="3"/>
                                    </svg>
                                    View Library Map
                                </button>
                            </div>
                            <div id="copiesList" class="copies-list"></div>
                        </div>

                        <!-- Dates Section -->
                        <div class="dates-section">
                            <div class="section-header">
                                <h4>Borrowing Period</h4>
                                <span class="hint">Select your preferred dates</span>
                            </div>
                            
                            <div class="date-grid">
                                <div class="form-group">
                                    <label for="borrowDate" class="form-label">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                            <line x1="16" y1="2" x2="16" y2="6"/>
                                            <line x1="8" y1="2" x2="8" y2="6"/>
                                            <line x1="3" y1="10" x2="21" y2="10"/>
                                        </svg>
                                        Borrow Date
                                    </label>
                                    <input type="date" 
                                           id="borrowDate" 
                                           class="form-input" 
                                           required>
                                    <small class="form-hint">Date you want to pick up the book</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="returnDate" class="form-label">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                            <line x1="16" y1="2" x2="16" y2="6"/>
                                            <line x1="8" y1="2" x2="8" y2="6"/>
                                            <line x1="3" y1="10" x2="21" y2="10"/>
                                            <path d="m9 16 2 2 4-4"/>
                                        </svg>
                                        Due Date
                                    </label>
                                    <select id="borrowDuration" class="form-select" onchange="updateReturnDate()">
                                        <option value="7">7 days</option>
                                        <option value="14" selected>14 days</option>
                                        <option value="21">21 days</option>
                                        <option value="30">30 days</option>
                                        <option value="custom">Custom date</option>
                                    </select>
                                    <input type="date" 
                                           id="returnDate" 
                                           class="form-input" 
                                           style="display: none;"
                                           required>
                                    <small class="form-hint">When to return the book</small>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Notes -->
                        <div class="form-group">
                            <label for="requestNotes" class="form-label">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 20h9"/>
                                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                                </svg>
                                Special Request (Optional)
                            </label>
                            <textarea id="requestNotes" 
                                      class="form-textarea" 
                                      placeholder="Any special requests or notes..."
                                      rows="3"></textarea>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <div id="formMessage" class="form-message"></div>
                            <button type="button" 
                                    id="submitBtn" 
                                    class="btn-primary" 
                                    onclick="submitRequest()"
                                    disabled>
                                <span class="btn-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                        <polyline points="22 4 12 14.01 9 11.01"/>
                                    </svg>
                                </span>
                                <span class="btn-text">Submit Request</span>
                                <span class="btn-loader"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Library Map Modal -->
<div id="libraryMapModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                Library Map - Book Location
            </h2>
            <button class="modal-close" onclick="closeLibraryMap()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="modal-map-container">
                <div id="modalMapLegend" class="map-legend"></div>
                <div id="modalLibraryMap" class="library-map-container-large"></div>
            </div>
            <div class="current-location-info">
                <h4>Selected Copy Location</h4>
                <div id="locationDetails" class="location-details">
                    <p>Select a copy from the list to see its location</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Submission Success Modal -->
<div id="successModal" class="modal">
    <div class="modal-content success-modal">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                Reservation Successful!
            </h2>
            <button class="modal-close" onclick="closeSuccessModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="success-content">
                <div class="success-icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" stroke="#10b981" stroke-width="2"/>
                        <path d="m9 12 2 2 4-4" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h3 id="successTitle">Your Reservation is Confirmed!</h3>
                <p id="successMessage">Your book request has been submitted successfully. Library staff will review it shortly.</p>
                
                <div id="reservationDetails" class="reservation-details"></div>
                
                <div class="modal-actions">
                    <button class="btn-secondary" onclick="closeSuccessModal()">Close</button>
                    <button class="btn-primary" onclick="makeAnotherReservation()">Make Another Reservation</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Submission Error Modal -->
<div id="errorModal" class="modal">
    <div class="modal-content error-modal">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                Submission Failed
            </h2>
            <button class="modal-close" onclick="closeErrorModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="error-content">
                <div class="error-icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" stroke="#ef4444" stroke-width="2"/>
                        <line x1="12" y1="8" x2="12" y2="12" stroke="#ef4444" stroke-width="2"/>
                        <line x1="12" y1="16" x2="12.01" y2="16" stroke="#ef4444" stroke-width="2"/>
                    </svg>
                </div>
                <h3 id="errorTitle">Unable to Submit Request</h3>
                <p id="errorMessage">There was an error processing your request. Please try again.</p>
                
                <div class="error-details" id="errorDetails"></div>
                
                <div class="modal-actions">
                    <button class="btn-secondary" onclick="closeErrorModal()">Cancel</button>
                    <button class="btn-primary" onclick="retrySubmission()">Try Again</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- My Reservations Modal -->
<div id="myReservationsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                My Reservations
            </h2>
            <button class="modal-close" onclick="closeMyReservationsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="reservationsLoading" class="loading-state">
                <div class="spinner"></div>
                <p>Loading your reservations...</p>
            </div>
            <div id="reservationsList" class="reservations-list" style="display: none;">
                <!-- Reservations will be loaded here -->
            </div>
            <div id="noReservations" class="empty-state" style="display: none;">
                <div class="empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/>
                        <polyline points="7 3 7 8 15 8"/>
                    </svg>
                </div>
                <h3>No Reservations Yet</h3>
                <p>You haven't made any book reservations yet.</p>
                <button onclick="closeMyReservationsModal()" class="btn-primary">Start Requesting Books</button>
            </div>
        </div>
    </div>
</div>

<script>
let selectedBook = null;
let selectedCopy = null;
selectedCopyData = null;
let currentPage = 1;
const booksPerPage = 8;
const csrfToken = '<?php echo $csrf_token; ?>';
let lastSubmissionData = null;
let preSelectedBookId = <?php echo $preSelectedBookId; ?>;

// Initialize date fields
function initializeDates() {
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    
    // Set borrow date to tomorrow (default)
    document.getElementById('borrowDate').value = tomorrow.toISOString().split('T')[0];
    
    // Set min dates
    document.getElementById('borrowDate').min = tomorrow.toISOString().split('T')[0];
    document.getElementById('returnDate').min = tomorrow.toISOString().split('T')[0];
    
    // Update return date based on default duration
    updateReturnDate();
}

function updateReturnDate() {
    const borrowDate = document.getElementById('borrowDate').value;
    const duration = document.getElementById('borrowDuration').value;
    const returnDateInput = document.getElementById('returnDate');
    
    if (!borrowDate) return;
    
    const borrow = new Date(borrowDate);
    
    if (duration === 'custom') {
        // Show custom date input
        document.getElementById('borrowDuration').style.display = 'none';
        returnDateInput.style.display = 'block';
        returnDateInput.value = '';
        returnDateInput.focus();
    } else {
        // Hide custom date input, show duration dropdown
        document.getElementById('borrowDuration').style.display = 'block';
        returnDateInput.style.display = 'none';
        
        // Calculate return date based on duration
        const returnDate = new Date(borrow);
        returnDate.setDate(returnDate.getDate() + parseInt(duration));
        returnDateInput.value = returnDate.toISOString().split('T')[0];
    }
}

// Search for books
async function searchBooks(page = 1) {
    currentPage = page;
    const searchTerm = document.getElementById('searchBooks').value.trim();
    const categoryId = document.getElementById('categoryFilter').value;
    const availabilityFilter = document.getElementById('availabilityFilter').value;
    
    const resultsDiv = document.getElementById('booksResults');
    const paginationDiv = document.getElementById('booksPagination');
    const bookCount = document.getElementById('bookCount');
    
    resultsDiv.innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <p>Searching for books...</p>
        </div>
    `;
    
    try {
        // Build query parameters
        let url = '../api/dispatch.php?resource=books';
        
        // We'll filter client-side for now since the API doesn't support search/filtering
        const res = await fetch(url);
        const data = await res.json();
        
        if (data.error) {
            throw new Error(data.error);
        }
        
        let books = Array.isArray(data) ? data : [];
        
        // Apply filters client-side
        if (searchTerm) {
            const searchLower = searchTerm.toLowerCase();
            books = books.filter(book => 
                (book.title && book.title.toLowerCase().includes(searchLower)) ||
                (book.author && book.author.toLowerCase().includes(searchLower)) ||
                (book.isbn && book.isbn.toLowerCase().includes(searchLower))
            );
        }
        
        if (categoryId) {
            books = books.filter(book => book.category_id == categoryId);
        }
        
        if (availabilityFilter === 'available') {
            books = books.filter(book => {
                const availableCopies = book.available_copies_cache || 0;
                return availableCopies > 0;
            });
        }
        
        const totalBooks = books.length;
        
        // Apply pagination
        const startIndex = (page - 1) * booksPerPage;
        const paginatedBooks = books.slice(startIndex, startIndex + booksPerPage);
        
        // Update book count
        bookCount.textContent = `${totalBooks} ${totalBooks === 1 ? 'book' : 'books'} found`;
        
        if (paginatedBooks.length === 0) {
            resultsDiv.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                            <path d="M11 8v6"/>
                            <path d="M8 11h6"/>
                        </svg>
                    </div>
                    <h3>No Books Found</h3>
                    <p>${searchTerm || categoryId ? 'Try different search terms or filters' : 'No books in the library yet'}</p>
                    <button onclick="resetFilters()" class="btn-retry">Reset Filters</button>
                </div>
            `;
            paginationDiv.innerHTML = '';
            return;
        }
        
        // For each book, ensure available copies cache is accurate
        for (let book of paginatedBooks) {
            if (book.available_copies_cache < 0 || book.available_copies_cache > book.total_copies_cache) {
                // Force a refresh of the cache
                try {
                    await fetch(`../api/dispatch.php?resource=book-details&id=${book.id}`);
                } catch (e) {
                    console.log('Could not refresh book cache:', e);
                }
            }
        }
        
        // Render books
        resultsDiv.innerHTML = paginatedBooks.map(book => {
            // Use correct column names from database
            const totalCopies = book.total_copies_cache || 0;
            const availableCopies = book.available_copies_cache || 0;
            
            let coverImage = '../assets/default-book.jpg';
            if (book.cover_image_cache) {
                coverImage = '../uploads/covers/' + book.cover_image_cache;
            } else if (book.cover_image) {
                coverImage = '../uploads/covers/' + book.cover_image;
            }
            
            return `
                <div class="book-result-card" data-book-id="${book.id}">
                    <div class="book-result-image">
                        <img src="${coverImage}" 
                             alt="${escapeHtml(book.title)}"
                             onerror="this.src='../assets/default-book.jpg'">
                    </div>
                    <div class="book-result-info">
                        <h4 class="book-result-title">${escapeHtml(book.title)}</h4>
                        <p class="book-result-author">by ${escapeHtml(book.author)}</p>
                        
                        <div class="book-result-meta">
                            <span class="meta-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                                </svg>
                                ${escapeHtml(book.category || 'Uncategorized')}
                            </span>
                            <span class="meta-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                ${book.year_published || 'N/A'}
                            </span>
                        </div>
                        
                        <div class="book-result-availability">
                            <div class="availability-badge ${availableCopies > 0 ? 'available' : 'unavailable'}">
                                <span class="availability-count">${availableCopies}/${totalCopies}</span>
                                <span>copies ${availableCopies > 0 ? 'available' : 'unavailable'}</span>
                            </div>
                            <button class="btn-select-book" onclick="selectBook(${book.id})" ${availableCopies === 0 ? 'disabled' : ''}>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 5v14M5 12h14"/>
                                </svg>
                                ${availableCopies > 0 ? 'Select' : 'Unavailable'}
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        
        // Render pagination
        renderPagination(totalBooks, paginationDiv);
        
    } catch (error) {
        console.error('Search error:', error);
        resultsDiv.innerHTML = `
            <div class="empty-state error">
                <div class="empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <h3>Search Failed</h3>
                <p>Unable to load books. Please try again.</p>
                <button onclick="searchBooks(1)" class="btn-retry">Retry Search</button>
            </div>
        `;
        paginationDiv.innerHTML = '';
    }
}

function renderPagination(totalItems, container) {
    const totalPages = Math.ceil(totalItems / booksPerPage);
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let paginationHTML = `
        <div class="pagination">
            <button class="pagination-btn prev ${currentPage === 1 ? 'disabled' : ''}" 
                    onclick="searchBooks(${currentPage - 1})" 
                    ${currentPage === 1 ? 'disabled' : ''}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15 18l-6-6 6-6"/>
                </svg>
                Previous
            </button>
            
            <div class="pagination-pages">
    `;
    
    // Show first page
    if (currentPage > 3) {
        paginationHTML += `
            <button class="page-number" onclick="searchBooks(1)">1</button>
            ${currentPage > 4 ? '<span class="page-dots">...</span>' : ''}
        `;
    }
    
    // Show pages around current page
    for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
        paginationHTML += `
            <button class="page-number ${i === currentPage ? 'active' : ''}" 
                    onclick="searchBooks(${i})">
                ${i}
            </button>
        `;
    }
    
    // Show last page
    if (currentPage < totalPages - 2) {
        paginationHTML += `
            ${currentPage < totalPages - 3 ? '<span class="page-dots">...</span>' : ''}
            <button class="page-number" onclick="searchBooks(${totalPages})">${totalPages}</button>
        `;
    }
    
    paginationHTML += `
            </div>
            
            <button class="pagination-btn next ${currentPage === totalPages ? 'disabled' : ''}" 
                    onclick="searchBooks(${currentPage + 1})" 
                    ${currentPage === totalPages ? 'disabled' : ''}>
                Next
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 18l6-6-6-6"/>
                </svg>
            </button>
        </div>
    `;
    
    container.innerHTML = paginationHTML;
}

// Select a book
async function selectBook(bookId) {
    try {
        // Load book details from books API
        const res = await fetch(`../api/dispatch.php?resource=book-details&id=${bookId}`);
        const book = await res.json();
        
        if (book.error) {
            showMessage('error', 'Unable to load book details.');
            return;
        }
        
        selectedBook = book;
        selectedCopy = null;
        selectedCopyData = null;
        
        // Show selected book section
        document.getElementById('selectedBookSection').style.display = 'block';
        
        // Update selected book card
        const totalCopies = book.total_copies || book.total_copies_cache || 0;
        const availableCopies = book.available_copies || book.available_copies_cache || 0;
        
        let coverImage = '../assets/default-book.jpg';
        if (book.cover_image_cache) {
            coverImage = '../uploads/covers/' + book.cover_image_cache;
        } else if (book.cover_image) {
            coverImage = '../uploads/covers/' + book.cover_image;
        }
        
        document.getElementById('selectedBookCard').innerHTML = `
            <div class="selected-book-info">
                <div class="selected-book-cover">
                    <img src="${coverImage}" 
                         alt="${escapeHtml(book.title)}"
                         onerror="this.src='../assets/default-book.jpg'">
                </div>
                <div class="selected-book-details">
                    <h5>${escapeHtml(book.title)}</h5>
                    <p class="book-author">by ${escapeHtml(book.author)}</p>
                    <div class="book-meta">
                        <span class="meta-item">${escapeHtml(book.category || 'Uncategorized')}</span>
                        <span class="meta-item">${book.year_published || 'N/A'}</span>
                        <span class="meta-item">ISBN: ${escapeHtml(book.isbn || 'N/A')}</span>
                    </div>
                    <div class="availability-display">
                        <div class="availability-status ${availableCopies > 0 ? 'available' : 'unavailable'}">
                            ${availableCopies} of ${totalCopies} copies available
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Load available copies
        await loadAvailableCopies(bookId);
        
        // Enable submit button if copies are available
        document.getElementById('submitBtn').disabled = availableCopies === 0;
        
    } catch (error) {
        console.error('Error selecting book:', error);
        showMessage('error', 'Unable to select book. Please try again.');
    }
}

// Load available copies for the selected book
async function loadAvailableCopies(bookId) {
    try {
        // We need to use a custom endpoint or query book_copies directly
        // For now, let's create a simple endpoint in dispatch.php to handle this
        
        // Try to fetch copies using the book-copies resource
        const res = await fetch(`../api/dispatch.php?resource=book-copies&book_id=${bookId}`);
        let copies = [];
        
        if (res.ok) {
            const data = await res.json();
            copies = Array.isArray(data) ? data : [];
        } else {
            // Fallback: try to get all copies and filter client-side
            const allRes = await fetch('../api/dispatch.php?resource=book_copies');
            const allData = await allRes.json();
            if (Array.isArray(allData)) {
                copies = allData.filter(copy => copy.book_id == bookId);
            }
        }
        
        // Filter available copies
        const availableCopies = copies.filter(copy => copy.status === 'available');
        
        if (availableCopies.length === 0) {
            document.getElementById('copiesSection').style.display = 'none';
            showMessage('warning', 'No copies available for this book.');
            document.getElementById('submitBtn').disabled = true;
            return;
        }
        
        // Show copies section
        document.getElementById('copiesSection').style.display = 'block';
        
        // Render copies list
        const copiesList = document.getElementById('copiesList');
        copiesList.innerHTML = availableCopies.map(copy => {
            const isSelected = selectedCopy === copy.id;
            return `
                <div class="copy-option ${isSelected ? 'selected' : ''}" 
                     onclick="selectCopy(${copy.id}, this, '${escapeHtml(copy.copy_number)}', '${escapeHtml(copy.current_section || 'A')}', ${copy.current_shelf || 1}, ${copy.current_row || 1}, ${copy.current_slot || 1})">
                    <div class="copy-checkbox">
                        ${isSelected ? '✓' : ''}
                    </div>
                    <div class="copy-details">
                        <div class="copy-header">
                            <span class="copy-id">Copy ${escapeHtml(copy.copy_number)}</span>
                            <span class="copy-condition condition-${copy.book_condition || 'good'}">
                                ${escapeHtml(copy.book_condition || 'Good')}
                            </span>
                        </div>
                        <div class="copy-location">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                <circle cx="12" cy="10" r="3"/>
                            </svg>
                            Location: ${escapeHtml(copy.current_section || 'A')}-S${copy.current_shelf || 1}-R${copy.current_row || 1}-P${copy.current_slot || 1}
                        </div>
                        <div class="copy-barcode">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="5" width="18" height="14" rx="2" ry="2"/>
                                <path d="M7 10h10"/>
                                <path d="M7 14h4"/>
                            </svg>
                            ${escapeHtml(copy.barcode || 'No barcode')}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        
        // Store copy data for map display
        window.copyData = availableCopies;
        
        // Auto-select the first copy if only one is available
        if (availableCopies.length === 1) {
            const firstCopy = availableCopies[0];
            selectCopy(firstCopy.id, 
                document.querySelector('.copy-option'),
                firstCopy.copy_number,
                firstCopy.current_section || 'A',
                firstCopy.current_shelf || 1,
                firstCopy.current_row || 1,
                firstCopy.current_slot || 1
            );
        }
        
    } catch (error) {
        console.error('Error loading copies:', error);
        document.getElementById('copiesSection').style.display = 'none';
        showMessage('error', 'Unable to load copy information.');
    }
}

// Select a specific copy
function selectCopy(copyId, element, copyNumber, section, shelf, row, slot) {
    selectedCopy = copyId;
    selectedCopyData = {
        copyNumber: copyNumber,
        section: section,
        shelf: shelf,
        row: row,
        slot: slot
    };
    
    // Update UI
    document.querySelectorAll('.copy-option').forEach(option => {
        option.classList.remove('selected');
    });
    element.classList.add('selected');
    
    showMessage('info', `Selected copy ${copyNumber} (${section}-S${shelf}-R${row}-P${slot})`);
}

// Clear selection
function clearSelection() {
    selectedBook = null;
    selectedCopy = null;
    selectedCopyData = null;
    
    document.getElementById('selectedBookSection').style.display = 'none';
    document.getElementById('copiesSection').style.display = 'none';
    document.getElementById('submitBtn').disabled = true;
    
    // Clear form
    document.getElementById('requestNotes').value = '';
    
    showMessage('info', 'Selection cleared. Choose another book.');
}

// Reset filters
function resetFilters() {
    document.getElementById('searchBooks').value = '';
    document.getElementById('categoryFilter').value = '';
    document.getElementById('availabilityFilter').value = 'available';
    searchBooks(1);
}

// Show message
function showMessage(type, message) {
    const messageDiv = document.getElementById('formMessage');
    messageDiv.className = `form-message ${type}`;
    messageDiv.innerHTML = `
        <div class="message-icon">
            ${type === 'success' ? '✓' : type === 'error' ? '✗' : type === 'warning' ? '⚠' : 'ℹ'}
        </div>
        <div class="message-content">${escapeHtml(message)}</div>
    `;
    
    // Auto-hide success messages after 5 seconds
    if (type === 'success') {
        setTimeout(() => {
            messageDiv.className = 'form-message';
            messageDiv.innerHTML = '';
        }, 5000);
    }
}

// Submit request
async function submitRequest() {
    if (!selectedBook || !selectedCopy) {
        showMessage('error', 'Please select a book and a specific copy.');
        return;
    }
    
    const borrowDate = document.getElementById('borrowDate').value;
    const returnDate = document.getElementById('returnDate').value;
    const notes = document.getElementById('requestNotes').value.trim();
    
    if (!borrowDate || !returnDate) {
        showMessage('error', 'Please select both borrow and return dates.');
        return;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    
    // Show loading state
    submitBtn.disabled = true;
    submitBtn.classList.add('loading');
    
    try {
        // Create reservation payload - FIXED: notes field included
        const payload = {
            book_id: selectedBook.id,
            book_copy_id: selectedCopy,
            patron_id: <?php echo $patron_id; ?>,
            reserved_at: borrowDate + ' 00:00:00',
            expiration_date: returnDate,
            notes: notes || ''  // Send empty string instead of undefined
        };
        
        // Store for retry
        lastSubmissionData = payload;
        
        const res = await fetch('../api/dispatch.php?resource=reservations', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        });
        
        const data = await res.json();
        
        if (data.error) {
            // If CSRF error, refresh token and retry
            if (data.error.includes('CSRF')) {
                showMessage('warning', 'Session expired. Refreshing...');
                // Get new CSRF token
                await fetchCSRFToken();
                // Retry the request
                await submitRequest();
                return;
            }
            throw new Error(data.error);
        }
        
        // Success - show success modal with reservation details
        showSuccessModal(data, selectedBook, borrowDate, returnDate, notes);
        
    } catch (error) {
        showErrorModal(error.message || 'Failed to submit request. Please try again.', selectedBook);
    } finally {
        submitBtn.disabled = false;
        submitBtn.classList.remove('loading');
    }
}

// Show success modal
function showSuccessModal(reservationData, book, borrowDate, returnDate, notes) {
    const modal = document.getElementById('successModal');
    const reservationId = reservationData.id || 'Pending';
    
    // Format dates
    const formattedBorrowDate = new Date(borrowDate).toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    const formattedReturnDate = new Date(returnDate).toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
    
    // Update modal content
    document.getElementById('successTitle').textContent = 'Reservation Confirmed!';
    document.getElementById('successMessage').textContent = 'Your book request has been submitted successfully and is pending approval from library staff.';
    
    // Show reservation details
    const detailsDiv = document.getElementById('reservationDetails');
    detailsDiv.innerHTML = `
        <div class="reservation-summary">
            <div class="reservation-item">
                <strong>Reservation ID:</strong> #${reservationId}
            </div>
            <div class="reservation-item">
                <strong>Book:</strong> ${escapeHtml(book.title)} by ${escapeHtml(book.author)}
            </div>
            <div class="reservation-item">
                <strong>Pickup Date:</strong> ${formattedBorrowDate}
            </div>
            <div class="reservation-item">
                <strong>Due Date:</strong> ${formattedReturnDate}
            </div>
            ${notes ? `<div class="reservation-item">
                <strong>Your Notes:</strong> "${escapeHtml(notes)}"
            </div>` : ''}
            <div class="reservation-item status">
                <strong>Status:</strong> <span class="status-pending">Pending Approval</span>
            </div>
        </div>
        <div class="reservation-instructions">
            <p><strong>Next Steps:</strong></p>
            <ul>
                <li>Library staff will review your reservation</li>
                <li>You will receive a notification when approved</li>
                <li>Pick up the book on your selected date</li>
            </ul>
        </div>
    `;
    
    // Show modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Show error modal
function showErrorModal(errorMessage, book) {
    const modal = document.getElementById('errorModal');
    
    // Update modal content
    document.getElementById('errorTitle').textContent = 'Submission Failed';
    document.getElementById('errorMessage').textContent = 'There was an error processing your request.';
    
    const detailsDiv = document.getElementById('errorDetails');
    detailsDiv.innerHTML = `
        <div class="error-summary">
            <p><strong>Error Details:</strong> ${escapeHtml(errorMessage)}</p>
            ${book ? `<p><strong>Selected Book:</strong> ${escapeHtml(book.title)}</p>` : ''}
        </div>
        <div class="error-suggestions">
            <p><strong>Suggestions:</strong></p>
            <ul>
                <li>Check your internet connection</li>
                <li>Verify all required fields are filled</li>
                <li>Try again in a few moments</li>
                <li>Contact library staff if problem persists</li>
            </ul>
        </div>
    `;
    
    // Show modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close success modal
function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reset form after successful submission
    clearSelection();
    document.getElementById('requestNotes').value = '';
    initializeDates();
    searchBooks(1);
}

// Close error modal
function closeErrorModal() {
    const modal = document.getElementById('errorModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Make another reservation
function makeAnotherReservation() {
    closeSuccessModal();
    // Scroll to top of form
    document.querySelector('.request-form-section').scrollIntoView({ behavior: 'smooth' });
}

// Retry submission
async function retrySubmission() {
    closeErrorModal();
    
    if (lastSubmissionData) {
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.classList.add('loading');
        
        try {
            const res = await fetch('../api/dispatch.php?resource=reservations', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(lastSubmissionData)
            });
            
            const data = await res.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            showSuccessModal(data, selectedBook, 
                lastSubmissionData.reserved_at.split(' ')[0], 
                lastSubmissionData.expiration_date,
                lastSubmissionData.notes || '');
                
        } catch (error) {
            showErrorModal(error.message || 'Failed to submit request. Please try again.', selectedBook);
        } finally {
            submitBtn.disabled = false;
            submitBtn.classList.remove('loading');
        }
    }
}

// Get new CSRF token
async function fetchCSRFToken() {
    try {
        showMessage('warning', 'Refreshing session...');
        setTimeout(() => {
            location.reload();
        }, 1000);
    } catch (error) {
        console.error('Error fetching CSRF token:', error);
    }
}

// My Reservations Modal Functions
function openMyReservationsModal() {
    const modal = document.getElementById('myReservationsModal');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    loadMyReservations();
}

function closeMyReservationsModal() {
    const modal = document.getElementById('myReservationsModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

async function loadMyReservations() {
    const loadingDiv = document.getElementById('reservationsLoading');
    const listDiv = document.getElementById('reservationsList');
    const noReservationsDiv = document.getElementById('noReservations');
    
    loadingDiv.style.display = 'flex';
    listDiv.style.display = 'none';
    noReservationsDiv.style.display = 'none';
    
    try {
        // Use the new user-reservations endpoint
        const res = await fetch('../api/dispatch.php?resource=user-reservations');
        const reservations = await res.json();
        
        if (reservations.error) {
            throw new Error(reservations.error);
        }
        
        loadingDiv.style.display = 'none';
        
        if (!reservations || reservations.length === 0) {
            noReservationsDiv.style.display = 'flex';
            return;
        }
        
        // Format and display reservations
        listDiv.innerHTML = reservations.map(reservation => {
            const reservationDate = new Date(reservation.reserved_at);
            const expirationDate = new Date(reservation.expiration_date);
            
            const formattedReservationDate = reservationDate.toLocaleDateString('en-US', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            const formattedExpirationDate = expirationDate.toLocaleDateString('en-US', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
            
            const statusClass = `status-${reservation.status}`;
            const statusText = reservation.status.charAt(0).toUpperCase() + reservation.status.slice(1);
            
            // Check if reservation can be cancelled (only pending reservations)
            const canCancel = reservation.status === 'pending';
            
            let locationInfo = '';
            if (reservation.current_section) {
                locationInfo = `
                    <div class="reservation-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                            <circle cx="12" cy="10" r="3"/>
                        </svg>
                        ${reservation.current_section}-S${reservation.current_shelf || '?'}-R${reservation.current_row || '?'}-P${reservation.current_slot || '?'}
                    </div>
                `;
            }
            
            return `
                <div class="reservation-item" data-reservation-id="${reservation.id}">
                    <div class="reservation-header">
                        <h4 class="reservation-title">${escapeHtml(reservation.book_title || 'Unknown Book')}</h4>
                        <span class="reservation-id">#${reservation.id}</span>
                    </div>
                    
                    <div class="reservation-meta">
                        <div class="reservation-meta-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            ${escapeHtml(reservation.book_author || 'Unknown Author')}
                        </div>
                        ${reservation.copy_number ? `
                            <div class="reservation-meta-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="5" width="18" height="14" rx="2" ry="2"/>
                                    <path d="M7 10h10"/>
                                    <path d="M7 14h4"/>
                                </svg>
                                Copy: ${escapeHtml(reservation.copy_number)}
                            </div>
                        ` : ''}
                        ${locationInfo}
                    </div>
                    
                    <div class="reservation-dates">
                        <div class="date-card">
                            <div class="date-label">Reserved Date</div>
                            <div class="date-value">${formattedReservationDate}</div>
                        </div>
                        <div class="date-card">
                            <div class="date-label">Expiration Date</div>
                            <div class="date-value">${formattedExpirationDate}</div>
                        </div>
                    </div>
                    
                    ${reservation.notes ? `
                        <div class="reservation-notes">
                            <strong>Notes:</strong> ${escapeHtml(reservation.notes)}
                        </div>
                    ` : ''}
                    
                    <div class="reservation-footer">
                        <span class="reservation-status ${statusClass}">${statusText}</span>
                        <div class="reservation-actions">
                            <button class="btn-cancel-reservation" 
                                    onclick="cancelReservation(${reservation.id}, this)"
                                    ${canCancel ? '' : 'disabled'}>
                                Cancel Reservation
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        
        listDiv.style.display = 'block';
        
    } catch (error) {
        console.error('Error loading reservations:', error);
        loadingDiv.style.display = 'none';
        listDiv.innerHTML = `
            <div class="empty-state error">
                <div class="empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <h3>Unable to Load Reservations</h3>
                <p>There was an error loading your reservations. Please try again.</p>
                <button onclick="loadMyReservations()" class="btn-retry">Retry</button>
            </div>
        `;
        listDiv.style.display = 'block';
    }
}

async function cancelReservation(reservationId, button) {
    if (!confirm('Are you sure you want to cancel this reservation?')) {
        return;
    }
    
    button.disabled = true;
    button.textContent = 'Cancelling...';
    
    try {
        const res = await fetch(`../api/dispatch.php?resource=reservations&id=${reservationId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': csrfToken
            }
        });
        
        const data = await res.json();
        
        if (data.error) {
            throw new Error(data.error);
        }
        
        // Show success message
        showMessage('success', 'Reservation cancelled successfully.');
        
        // Reload reservations list
        setTimeout(() => {
            loadMyReservations();
        }, 1000);
        
    } catch (error) {
        console.error('Error cancelling reservation:', error);
        showMessage('error', 'Unable to cancel reservation. Please try again.');
        button.disabled = false;
        button.textContent = 'Cancel Reservation';
    }
}

// Library Map Functions
function openLibraryMap() {
    if (!selectedBook) {
        showMessage('warning', 'Please select a book first to view its location.');
        return;
    }
    
    const modal = document.getElementById('libraryMapModal');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    renderLibraryMap();
}

function closeLibraryMap() {
    const modal = document.getElementById('libraryMapModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

async function renderLibraryMap() {
    try {
        const mapContainer = document.getElementById('modalLibraryMap');
        const legendContainer = document.getElementById('modalMapLegend');
        const locationDetails = document.getElementById('locationDetails');
        
        // Get library sections configuration from library_map_config table
        // Use default sections if API not available
        let sections = [
            {section: 'A', shelf_count: 5, rows_per_shelf: 6, slots_per_row: 12, color: '#3B82F6'},
            {section: 'B', shelf_count: 5, rows_per_shelf: 6, slots_per_row: 12, color: '#10B981'},
            {section: 'C', shelf_count: 5, rows_per_shelf: 6, slots_per_row: 12, color: '#EF4444'},
            {section: 'D', shelf_count: 5, rows_per_shelf: 6, slots_per_row: 12, color: '#8B5CF6'},
            {section: 'E', shelf_count: 5, rows_per_shelf: 6, slots_per_row: 12, color: '#F59E0B'},
            {section: 'F', shelf_count: 5, rows_per_shelf: 6, slots_per_row: 12, color: '#EC4899'}
        ];
        
        // Try to get from API if available
        try {
            const sectionsRes = await fetch('../api/dispatch.php?resource=library_map_config');
            if (sectionsRes.ok) {
                const apiSections = await sectionsRes.json();
                if (Array.isArray(apiSections) && apiSections.length > 0) {
                    sections = apiSections;
                }
            }
        } catch (e) {
            console.log('Using default sections:', e);
        }
        
        const sectionColors = {
            'A': '#3B82F6', 'B': '#10B981', 'C': '#EF4444',
            'D': '#8B5CF6', 'E': '#F59E0B', 'F': '#EC4899'
        };
        
        // Get current book's copies
        const copies = window.copyData || [];
        
        // Create interactive library map
        let mapHTML = '<div class="library-sections-modal">';
        
        sections.forEach(section => {
            const sectionCode = section.section;
            const containsBook = copies.some(copy => copy.current_section === sectionCode);
            
            // Get current book's copies in this section
            const currentBookInSection = copies.filter(copy => 
                copy.current_section === sectionCode
            );
            
            mapHTML += `
                <div class="map-section-modal" data-section="${sectionCode}">
                    <div class="section-header-modal" style="border-left-color: ${sectionColors[sectionCode] || '#3B82F6'}">
                        <h4>Section ${sectionCode}</h4>
                        <span class="section-info-modal">${containsBook ? 'Contains selected book' : 'Other books'}</span>
                    </div>
                    
                    <div class="shelves-container-modal">
            `;
            
            // Create shelves per section
            const shelfCount = section.shelf_count || 5;
            for (let shelf = 1; shelf <= shelfCount; shelf++) {
                // Get current book's copies on this shelf
                const currentBookOnShelf = currentBookInSection.filter(copy => 
                    parseInt(copy.current_shelf) === shelf
                );
                
                mapHTML += `
                    <div class="shelf-modal" data-shelf="${shelf}">
                        <div class="shelf-label-modal">Shelf ${shelf}</div>
                        <div class="shelf-content-modal">
                `;
                
                // Create rows per shelf
                const rowsPerShelf = section.rows_per_shelf || 6;
                for (let row = 1; row <= rowsPerShelf; row++) {
                    // Get current book's copies in this row
                    const currentBookInRow = currentBookOnShelf.filter(copy => 
                        parseInt(copy.current_row) === row
                    );
                    
                    mapHTML += `
                        <div class="row-modal" data-row="${row}">
                            <div class="row-label-modal">Row ${row}</div>
                            <div class="slots-modal">
                    `;
                    
                    // Create slots per row
                    const slotsPerRow = section.slots_per_row || 12;
                    for (let slot = 1; slot <= slotsPerRow; slot++) {
                        // Check if current book exists in this exact location
                        const currentCopy = currentBookInRow.find(copy => 
                            parseInt(copy.current_slot) === slot
                        );
                        
                        const isCurrentBookLocation = !!currentCopy;
                        const copyNumber = currentCopy ? currentCopy.copy_number : '';
                        const isSelectedCopy = selectedCopyData && 
                                               selectedCopyData.section === sectionCode &&
                                               parseInt(selectedCopyData.shelf) === shelf &&
                                               parseInt(selectedCopyData.row) === row &&
                                               parseInt(selectedCopyData.slot) === slot;
                        
                        const slotClasses = ['slot-modal'];
                        if (isSelectedCopy) {
                            slotClasses.push('selected-copy');
                        } else if (isCurrentBookLocation) {
                            slotClasses.push('current-book');
                        } else if (containsBook) {
                            slotClasses.push('empty');
                        } else {
                            slotClasses.push('occupied');
                        }
                        
                        mapHTML += `
                            <div class="${slotClasses.join(' ')}" 
                                 data-section="${sectionCode}"
                                 data-shelf="${shelf}"
                                 data-row="${row}"
                                 data-slot="${slot}"
                                 data-copy="${copyNumber}"
                                 title="${isSelectedCopy ? 'Selected Copy' : isCurrentBookLocation ? 'Available Copy' : containsBook ? 'Empty' : 'Other Books'}"
                                 onclick="highlightLocation('${sectionCode}', ${shelf}, ${row}, ${slot}, '${copyNumber}')">
                                ${isSelectedCopy ? '★' : isCurrentBookLocation ? '●' : ''}
                            </div>
                        `;
                    }
                    
                    mapHTML += `
                            </div>
                        </div>
                    `;
                }
                
                mapHTML += `
                        </div>
                    </div>
                `;
            }
            
            mapHTML += `
                    </div>
                </div>
            `;
        });
        
        mapHTML += '</div>';
        mapContainer.innerHTML = mapHTML;
        
        // Create legend
        legendContainer.innerHTML = `
            <div class="legend-modal">
                <div class="legend-item-modal">
                    <div class="legend-color-modal selected-copy"></div>
                    <span>Selected Copy (★)</span>
                </div>
                <div class="legend-item-modal">
                    <div class="legend-color-modal current-book"></div>
                    <span>Available Copies (●)</span>
                </div>
                <div class="legend-item-modal">
                    <div class="legend-color-modal occupied"></div>
                    <span>Other Books</span>
                </div>
                <div class="legend-item-modal">
                    <div class="legend-color-modal empty"></div>
                    <span>Empty Slot</span>
                </div>
            </div>
        `;
        
        // Update location details
        if (selectedCopyData) {
            locationDetails.innerHTML = `
                <div class="location-detail-card">
                    <div class="location-detail-header">
                        <h5>Selected Copy: ${selectedCopyData.copyNumber}</h5>
                        <span class="location-code">${selectedCopyData.section}-S${selectedCopyData.shelf}-R${selectedCopyData.row}-P${selectedCopyData.slot}</span>
                    </div>
                    <div class="location-detail-info">
                        <div class="detail-item">
                            <span class="detail-label">Section:</span>
                            <span class="detail-value">${selectedCopyData.section}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Shelf:</span>
                            <span class="detail-value">${selectedCopyData.shelf}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Row:</span>
                            <span class="detail-value">${selectedCopyData.row}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Slot:</span>
                            <span class="detail-value">${selectedCopyData.slot}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value available">Available</span>
                        </div>
                    </div>
                </div>
            `;
            
            // Highlight selected copy on map
            highlightLocation(selectedCopyData.section, selectedCopyData.shelf, selectedCopyData.row, selectedCopyData.slot, selectedCopyData.copyNumber);
        }
        
        // Add hover effects
        document.querySelectorAll('.slot-modal').forEach(slot => {
            slot.addEventListener('mouseenter', function() {
                if (this.classList.contains('selected-copy')) {
                    this.style.boxShadow = '0 0 0 3px #fbbf24, 0 0 20px rgba(251, 191, 36, 0.3)';
                } else if (this.classList.contains('current-book')) {
                    this.style.boxShadow = '0 0 0 2px #10b981, 0 0 15px rgba(16, 185, 129, 0.2)';
                } else if (this.classList.contains('occupied')) {
                    this.style.boxShadow = '0 0 0 2px #ef4444, 0 0 10px rgba(239, 68, 68, 0.1)';
                } else {
                    this.style.boxShadow = '0 0 0 2px #3b82f6, 0 0 10px rgba(59, 130, 246, 0.1)';
                }
            });
            
            slot.addEventListener('mouseleave', function() {
                this.style.boxShadow = '';
            });
        });
        
    } catch (error) {
        console.error('Error rendering library map:', error);
        document.getElementById('modalLibraryMap').innerHTML = `
            <div class="empty-state">
                <p>Library map not available</p>
                <button onclick="renderLibraryMap()" class="btn-retry">Retry</button>
            </div>
        `;
    }
}

function highlightLocation(section, shelf, row, slot, copyNumber) {
    // Remove all highlights first
    document.querySelectorAll('.slot-modal').forEach(s => {
        s.classList.remove('highlighted');
        s.style.animation = '';
    });
    
    // Find and highlight the specific location
    const targetSlot = document.querySelector(
        `.slot-modal[data-section="${section}"][data-shelf="${shelf}"][data-row="${row}"][data-slot="${slot}"]`
    );
    
    if (targetSlot) {
        targetSlot.classList.add('highlighted');
        targetSlot.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'center',
            inline: 'center'
        });
        
        // Add pulsing animation
        targetSlot.style.animation = 'pulse-highlight 2s infinite';
        
        // Update location details
        const locationDetails = document.getElementById('locationDetails');
        locationDetails.innerHTML = `
            <div class="location-detail-card">
                <div class="location-detail-header">
                    <h5>${copyNumber ? 'Copy: ' + copyNumber : 'Selected Location'}</h5>
                    <span class="location-code">${section}-S${shelf}-R${row}-P${slot}</span>
                </div>
                <div class="location-detail-info">
                    <div class="detail-item">
                        <span class="detail-label">Section:</span>
                        <span class="detail-value">${section}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Shelf:</span>
                        <span class="detail-value">${shelf}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Row:</span>
                        <span class="detail-value">${row}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Slot:</span>
                        <span class="detail-value">${slot}</span>
                    </div>
                    ${copyNumber ? `<div class="detail-item">
                        <span class="detail-label">Copy Number:</span>
                        <span class="detail-value">${copyNumber}</span>
                    </div>` : ''}
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value available">Available</span>
                    </div>
                </div>
            </div>
        `;
    }
}

// Escape HTML
function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

// Event listeners
document.getElementById('searchBooks').addEventListener('keyup', (e) => {
    if (e.key === 'Enter') {
        searchBooks(1);
    }
});

document.getElementById('searchBtn').addEventListener('click', () => {
    searchBooks(1);
});

document.getElementById('categoryFilter').addEventListener('change', () => {
    searchBooks(1);
});

document.getElementById('availabilityFilter').addEventListener('change', () => {
    searchBooks(1);
});

document.getElementById('borrowDate').addEventListener('change', () => {
    updateReturnDate();
});

// Close modal when clicking outside
window.onclick = function(event) {
    const libraryModal = document.getElementById('libraryMapModal');
    const successModal = document.getElementById('successModal');
    const errorModal = document.getElementById('errorModal');
    const reservationsModal = document.getElementById('myReservationsModal');
    
    if (event.target === libraryModal) {
        closeLibraryMap();
    }
    if (event.target === successModal) {
        closeSuccessModal();
    }
    if (event.target === errorModal) {
        closeErrorModal();
    }
    if (event.target === reservationsModal) {
        closeMyReservationsModal();
    }
}

// Initialize page
document.addEventListener('DOMContentLoaded', () => {
    initializeDates();
    
    // If we have a pre-selected book ID, load it immediately
    if (preSelectedBookId > 0) {
        selectBook(preSelectedBookId);
    } else {
        // Otherwise, load the book search
        searchBooks(1);
    }
});
</script>

<style>
/* Add new CSS for pre-selected state */
.preselected-label {
    font-size: 0.85rem;
    color: var(--success);
    background: #d1fae5;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 500;
}

.loading-state-small {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    text-align: center;
}

.spinner-small {
    width: 30px;
    height: 30px;
    border: 2px solid var(--gray-200);
    border-top: 2px solid var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 10px;
}

/* Rest of your existing CSS remains exactly the same... */

/* Modern Design System - Updated with Enhanced Request Details */
:root {
    --primary: #4f46e5;
    --primary-dark: #4338ca;
    --primary-light: #eef2ff;
    --secondary: #10b981;
    --secondary-dark: #059669;
    --danger: #ef4444;
    --warning: #f59e0b;
    --success: #10b981;
    --info: #3b82f6;
    
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    
    --radius-sm: 0.375rem;
    --radius: 0.5rem;
    --radius-md: 0.75rem;
    --radius-lg: 1rem;
    --radius-xl: 1.5rem;
    
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    position: relative;
    background-color: white;
    margin: 5% auto;
    padding: 0;
    width: 90%;
    max-width: 800px;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-xl);
    animation: slideIn 0.3s ease;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.success-modal {
    max-width: 600px;
}

.error-modal {
    max-width: 600px;
}

@keyframes slideIn {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    padding: 24px 32px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border-radius: var(--radius-xl) var(--radius-xl) 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.success-modal .modal-header {
    background: linear-gradient(135deg, var(--success), var(--secondary-dark));
}

.error-modal .modal-header {
    background: linear-gradient(135deg, var(--danger), #b91c1c);
}

.modal-title {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
}

.modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 2rem;
    cursor: pointer;
    padding: 0;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: var(--transition);
}

.modal-close:hover {
    background: rgba(255, 255, 255, 0.1);
}

.modal-body {
    padding: 32px;
    overflow-y: auto;
    flex: 1;
}

.success-content,
.error-content {
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
}

.success-icon,
.error-icon {
    margin-bottom: 16px;
}

.success-content h3 {
    margin: 0;
    color: var(--success);
    font-size: 1.75rem;
}

.error-content h3 {
    margin: 0;
    color: var(--danger);
    font-size: 1.75rem;
}

.success-content p,
.error-content p {
    color: var(--gray-600);
    font-size: 1.1rem;
    line-height: 1.6;
    margin: 0;
}

.reservation-details {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 24px;
    border: 1px solid var(--gray-200);
    width: 100%;
    text-align: left;
}

.reservation-summary {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 20px;
}

.reservation-item {
    padding-bottom: 12px;
    border-bottom: 1px solid var(--gray-200);
    color: var(--gray-700);
}

.reservation-item:last-child {
    border-bottom: none;
}

.reservation-item strong {
    color: var(--gray-900);
    min-width: 120px;
    display: inline-block;
}

.status-pending {
    color: var(--warning);
    font-weight: 600;
    background: #fef3c7;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.875rem;
}

.reservation-instructions {
    background: white;
    border-radius: var(--radius);
    padding: 20px;
    border-left: 4px solid var(--info);
}

.reservation-instructions p {
    margin: 0 0 12px 0;
    color: var(--gray-800);
    font-weight: 600;
}

.reservation-instructions ul {
    margin: 0;
    padding-left: 20px;
    color: var(--gray-600);
}

.reservation-instructions li {
    margin-bottom: 8px;
    line-height: 1.5;
}

.error-summary,
.error-suggestions {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 20px;
    width: 100%;
    text-align: left;
}

.error-summary {
    border-left: 4px solid var(--danger);
    margin-bottom: 16px;
}

.error-suggestions {
    border-left: 4px solid var(--warning);
}

.error-summary p,
.error-suggestions p {
    margin: 0 0 12px 0;
    text-align: left;
}

.error-suggestions ul {
    margin: 0;
    padding-left: 20px;
}

.error-suggestions li {
    margin-bottom: 8px;
    color: var(--gray-600);
}

.modal-actions {
    display: flex;
    gap: 16px;
    justify-content: center;
    margin-top: 24px;
    width: 100%;
}

.modal-actions .btn-primary {
    padding: 12px 24px;
    font-size: 1rem;
    width: auto;
    min-width: 180px;
}

.modal-actions .btn-secondary {
    padding: 12px 24px;
    background: var(--gray-200);
    color: var(--gray-700);
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    min-width: 120px;
}

.modal-actions .btn-secondary:hover {
    background: var(--gray-300);
}

/* Header Title Row */
.header-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}

.btn-view-reservations {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: var(--transition);
    white-space: nowrap;
}

.btn-view-reservations:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
}

/* Enhanced Request Details Header */
.request-details-header {
    padding: 28px 24px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--primary-light), white);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.request-details-title {
    display: flex;
    align-items: flex-start;
    gap: 16px;
}

.request-details-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

.request-details-heading {
    margin: 0 0 6px 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--gray-900);
    line-height: 1.2;
}

.request-details-subtitle {
    margin: 0;
    color: var(--gray-600);
    font-size: 0.95rem;
}

/* Enhanced User Info Section */
.user-info-section {
    padding: 24px;
    border-bottom: 1px solid var(--gray-200);
    background: var(--gray-50);
}

.user-info-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
    font-weight: 600;
    color: var(--gray-700);
    font-size: 0.95rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.user-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.user-info-item {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 16px;
    transition: var(--transition);
}

.user-info-item:hover {
    border-color: var(--primary);
    box-shadow: var(--shadow-sm);
}

.user-info-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.875rem;
    color: var(--gray-600);
    margin-bottom: 8px;
    font-weight: 500;
}

.user-info-value {
    font-weight: 600;
    color: var(--gray-900);
    font-size: 1.1rem;
    word-break: break-word;
}

/* Reservations List */
.reservations-list {
    max-height: 60vh;
    overflow-y: auto;
    padding-right: 8px;
}

.reservation-item {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 16px;
    transition: var(--transition);
}

.reservation-item:hover {
    border-color: var(--gray-300);
    box-shadow: var(--shadow);
}

.reservation-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--gray-200);
}

.reservation-title {
    margin: 0;
    font-size: 1.125rem;
    color: var(--gray-900);
    font-weight: 600;
}

.reservation-id {
    font-family: monospace;
    font-size: 0.85rem;
    color: var(--gray-500);
    background: var(--gray-100);
    padding: 2px 8px;
    border-radius: 12px;
}

.reservation-meta {
    display: flex;
    gap: 16px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.reservation-meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9rem;
    color: var(--gray-600);
}

.reservation-dates {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
}

.date-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 12px;
}

.date-label {
    font-size: 0.75rem;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.date-value {
    font-weight: 600;
    color: var(--gray-800);
    font-size: 0.95rem;
}

.reservation-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 12px;
    border-top: 1px solid var(--gray-200);
}

.reservation-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-approved {
    background: #d1fae5;
    color: #065f46;
}

.status-cancelled, .status-declined {
    background: #fee2e2;
    color: #991b1b;
}

.status-expired {
    background: #e5e7eb;
    color: #374151;
}

.reservation-actions {
    display: flex;
    gap: 8px;
}

.btn-cancel-reservation {
    padding: 6px 12px;
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.btn-cancel-reservation:hover {
    background: var(--gray-200);
    border-color: var(--gray-400);
}

.btn-cancel-reservation:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Library Map Specific Styles */
.modal-map-container {
    display: grid;
    grid-template-columns: 250px 1fr;
    gap: 24px;
    height: 60vh;
    margin-bottom: 24px;
}

@media (max-width: 1024px) {
    .modal-map-container {
        grid-template-columns: 1fr;
        height: auto;
    }
}

.library-map-container-large {
    height: 100%;
    overflow: auto;
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 24px;
    border: 1px solid var(--gray-200);
}

.library-sections-modal {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.map-section-modal {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    overflow: hidden;
}

.section-header-modal {
    padding: 16px;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    border-left: 4px solid;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.section-header-modal h4 {
    margin: 0;
    font-size: 1rem;
}

.section-info-modal {
    font-size: 0.75rem;
    padding: 4px 12px;
    background: var(--gray-100);
    color: var(--gray-600);
    border-radius: 12px;
}

.shelves-container-modal {
    padding: 16px;
}

.shelf-modal {
    margin-bottom: 16px;
    background: var(--gray-50);
    border-radius: var(--radius-sm);
    padding: 12px;
}

.shelf-label-modal {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--gray-200);
}

.shelf-content-modal {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.row-modal {
    display: flex;
    align-items: center;
    gap: 12px;
}

.row-label-modal {
    font-size: 0.75rem;
    color: var(--gray-500);
    width: 40px;
    flex-shrink: 0;
}

.slots-modal {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    flex: 1;
}

.slot-modal {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: var(--transition);
    user-select: none;
    border: 2px solid transparent;
}

.slot-modal.empty {
    background: white;
    border-color: var(--gray-200);
    color: var(--gray-400);
}

.slot-modal.occupied {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #991b1b;
}

.slot-modal.current-book {
    background: #dbeafe;
    border-color: #93c5fd;
    color: #1e40af;
}

.slot-modal.selected-copy {
    background: #fef3c7;
    border-color: #fbbf24;
    color: #92400e;
    font-weight: bold;
    font-size: 1rem;
}

.slot-modal.highlighted {
    background: #fbbf24;
    border-color: #f59e0b;
    color: white;
    transform: scale(1.2);
    z-index: 1;
    box-shadow: 0 0 20px rgba(251, 191, 36, 0.5);
}

.slot-modal:hover {
    transform: scale(1.1);
    box-shadow: var(--shadow);
    z-index: 1;
}

.legend-modal {
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: white;
    padding: 20px;
    border-radius: var(--radius);
    border: 1px solid var(--gray-200);
    height: fit-content;
}

.legend-item-modal {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.9rem;
    color: var(--gray-700);
}

.legend-color-modal {
    width: 20px;
    height: 20px;
    border-radius: 4px;
    flex-shrink: 0;
    border: 2px solid transparent;
}

.legend-color-modal.selected-copy {
    background: #fef3c7;
    border-color: #fbbf24;
}

.legend-color-modal.current-book {
    background: #dbeafe;
    border-color: #93c5fd;
}

.legend-color-modal.occupied {
    background: #fee2e2;
    border-color: #fca5a5;
}

.legend-color-modal.empty {
    background: white;
    border-color: var(--gray-200);
}

.current-location-info {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 24px;
    margin-top: 24px;
}

.current-location-info h4 {
    margin: 0 0 16px 0;
    color: var(--gray-800);
    font-size: 1.125rem;
}

.location-details {
    color: var(--gray-600);
}

.location-detail-card {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 16px;
}

.location-detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--gray-200);
}

.location-detail-header h5 {
    margin: 0;
    color: var(--gray-800);
    font-size: 1rem;
}

.location-code {
    font-family: monospace;
    background: white;
    padding: 4px 12px;
    border-radius: 20px;
    border: 1px solid var(--gray-300);
    color: var(--gray-700);
    font-weight: 600;
    font-size: 0.9rem;
}

.location-detail-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.detail-label {
    font-size: 0.75rem;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-weight: 600;
    color: var(--gray-800);
}

.detail-value.available {
    color: var(--success);
}

@keyframes pulse-highlight {
    0%, 100% { 
        box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.7);
        transform: scale(1.2);
    }
    50% { 
        box-shadow: 0 0 0 10px rgba(251, 191, 36, 0);
        transform: scale(1.3);
    }
}

/* Base Styles */
.page-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    margin-bottom: 40px;
}

.page-title {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 8px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.page-subtitle {
    font-size: 1.125rem;
    color: var(--gray-600);
    margin-bottom: 0;
}

/* Request Container */
.request-container {
    margin-top: 20px;
}

.request-layout {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 30px;
}

@media (max-width: 1024px) {
    .request-layout {
        grid-template-columns: 1fr;
    }
}

/* Card Styles */
.card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

/* Left Panel */
.search-section {
    margin-bottom: 24px;
}

.search-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), white);
}

.search-header h3 {
    margin: 0;
    font-size: 1.25rem;
    color: var(--gray-800);
    display: flex;
    align-items: center;
    gap: 10px;
}

.search-box {
    padding: 24px;
}

.search-input-group {
    display: flex;
    gap: 0;
    margin-bottom: 20px;
}

.search-icon {
    padding: 12px 16px;
    background: var(--gray-100);
    border: 1px solid var(--gray-300);
    border-right: none;
    border-radius: var(--radius) 0 0 var(--radius);
    color: var(--gray-500);
}

.search-input {
    flex: 1;
    padding: 12px 16px;
    border: 1px solid var(--gray-300);
    border-left: none;
    border-right: none;
    font-size: 1rem;
    color: var(--gray-800);
    background: white;
}

.search-input:focus {
    outline: none;
    border-color: var(--primary);
}

.btn-search {
    padding: 12px 24px;
    background: var(--primary);
    color: white;
    border: 1px solid var(--primary);
    border-radius: 0 var(--radius) var(--radius) 0;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
}

.btn-search:hover {
    background: var(--primary-dark);
    border-color: var(--primary-dark);
}

.search-filters {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

@media (max-width: 768px) {
    .search-filters {
        grid-template-columns: 1fr;
    }
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-label {
    font-weight: 500;
    color: var(--gray-700);
    font-size: 0.95rem;
}

.form-select {
    padding: 10px 16px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    background: white;
    color: var(--gray-800);
    font-size: 1rem;
    transition: var(--transition);
    width: 100%;
}

.form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

/* Books Section */
.books-section {
    height: calc(100vh - 300px);
    display: flex;
    flex-direction: column;
}

.section-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50), white);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.section-header h3 {
    margin: 0;
    font-size: 1.25rem;
    color: var(--gray-800);
    display: flex;
    align-items: center;
    gap: 10px;
}

.book-count {
    font-size: 0.875rem;
    color: var(--gray-600);
    background: var(--gray-100);
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 500;
}

.books-results {
    flex: 1;
    overflow-y: auto;
    padding: 0;
}

.book-result-card {
    padding: 20px;
    border-bottom: 1px solid var(--gray-200);
    transition: var(--transition);
    cursor: pointer;
    display: flex;
    gap: 16px;
    align-items: flex-start;
}

.book-result-card:hover {
    background: var(--gray-50);
}

.book-result-card:last-child {
    border-bottom: none;
}

.book-result-image {
    width: 80px;
    height: 100px;
    flex-shrink: 0;
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.book-result-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.book-result-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.book-result-title {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--gray-900);
    line-height: 1.3;
}

.book-result-author {
    margin: 0;
    color: var(--gray-600);
    font-size: 0.95rem;
}

.book-result-meta {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.85rem;
    color: var(--gray-500);
    background: var(--gray-100);
    padding: 3px 8px;
    border-radius: 12px;
}

.book-result-availability {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--gray-200);
}

.availability-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9rem;
    color: var(--gray-700);
}

.availability-badge.available .availability-count {
    color: var(--success);
}

.availability-badge.unavailable .availability-count {
    color: var(--danger);
}

.availability-count {
    font-weight: 700;
}

.btn-select-book {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-select-book:hover:not(:disabled) {
    background: var(--primary-dark);
    transform: translateY(-1px);
}

.btn-select-book:disabled {
    background: var(--gray-400);
    cursor: not-allowed;
    transform: none;
}

.books-pagination {
    padding: 20px;
    border-top: 1px solid var(--gray-200);
    background: var(--gray-50);
}

/* Right Panel */
.request-form-section {
    position: sticky;
    top: 20px;
    height: fit-content;
}

/* Form Sections */
#requestForm {
    padding: 24px;
}

.selected-book-section {
    margin-bottom: 24px;
}

.selected-book-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.selected-book-header h4 {
    margin: 0;
    font-size: 1.125rem;
    color: var(--gray-800);
}

.btn-clear {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-clear:hover {
    background: var(--gray-200);
    border-color: var(--gray-400);
}

.selected-book-card {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 16px;
}

.selected-book-info {
    display: flex;
    gap: 16px;
    align-items: flex-start;
}

.selected-book-cover {
    width: 60px;
    height: 80px;
    flex-shrink: 0;
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.selected-book-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.selected-book-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.selected-book-details h5 {
    margin: 0;
    font-size: 1rem;
    color: var(--gray-900);
    line-height: 1.3;
}

.book-meta {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 4px;
}

.availability-display {
    margin-top: 8px;
}

.availability-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.availability-status.available {
    background: #dcfce7;
    color: #166534;
}

.availability-status.unavailable {
    background: #fee2e2;
    color: #991b1b;
}

/* Copies Section */
.copies-section {
    margin-bottom: 24px;
}

.copies-section .section-header {
    background: transparent;
    border-bottom: none;
    padding: 0;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.copies-section .section-header h4 {
    margin: 0;
    font-size: 1.125rem;
    color: var(--gray-800);
}

.btn-map {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--primary-light);
    color: var(--primary);
    border: 1px solid var(--primary);
    border-radius: var(--radius);
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.btn-map:hover {
    background: var(--primary);
    color: white;
}

.hint {
    font-size: 0.875rem;
    color: var(--gray-500);
}

.copies-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: 300px;
    overflow-y: auto;
    padding-right: 8px;
}

.copy-option {
    padding: 12px;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius);
    background: white;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.copy-option:hover {
    border-color: var(--gray-300);
    background: var(--gray-50);
}

.copy-option.selected {
    border-color: var(--primary);
    background: var(--primary-light);
}

.copy-checkbox {
    width: 20px;
    height: 20px;
    border: 2px solid var(--gray-300);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: var(--transition);
}

.copy-option.selected .copy-checkbox {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

.copy-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.copy-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
}

.copy-id {
    font-weight: 600;
    color: var(--gray-800);
    font-size: 0.95rem;
}

.copy-condition {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.condition-new {
    background: #dcfce7;
    color: #166534;
}

.condition-good {
    background: #dbeafe;
    color: #1e40af;
}

.condition-fair {
    background: #fef3c7;
    color: #92400e;
}

.condition-poor {
    background: #fee2e2;
    color: #991b1b;
}

.copy-location,
.copy-barcode {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8rem;
    color: var(--gray-600);
}

/* Dates Section */
.dates-section {
    margin-bottom: 24px;
}

.dates-section .section-header {
    background: transparent;
    border-bottom: none;
    padding: 0;
    margin-bottom: 20px;
}

.date-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

@media (max-width: 768px) {
    .date-grid {
        grid-template-columns: 1fr;
    }
}

/* Form Elements */
.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-label {
    font-weight: 500;
    color: var(--gray-700);
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.form-input,
.form-textarea {
    padding: 10px 16px;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    background: white;
    color: var(--gray-800);
    font-size: 1rem;
    transition: var(--transition);
    width: 100%;
    font-family: inherit;
}

.form-input:focus,
.form-textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.form-hint {
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-top: 2px;
}

.form-textarea {
    resize: vertical;
    min-height: 80px;
}

/* Form Actions */
.form-actions {
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid var(--gray-200);
}

.form-message {
    padding: 12px 16px;
    border-radius: var(--radius);
    margin-bottom: 16px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 0.95rem;
}

.form-message.success {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.form-message.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.form-message.warning {
    background: #fef3c7;
    border: 1px solid #fde68a;
    color: #92400e;
}

.form-message.info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e40af;
}

.message-icon {
    font-weight: bold;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.btn-primary {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    width: 100%;
    justify-content: center;
    position: relative;
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
}

.btn-primary.loading .btn-text {
    opacity: 0;
}

.btn-loader {
    position: absolute;
    width: 20px;
    height: 20px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s linear infinite;
    opacity: 0;
}

.btn-primary.loading .btn-loader {
    opacity: 1;
}

/* Pagination */
.pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.pagination-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: white;
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
}

.pagination-btn:hover:not(.disabled) {
    background: var(--gray-50);
    border-color: var(--gray-400);
}

.pagination-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination-pages {
    display: flex;
    gap: 4px;
}

.page-number {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius);
    border: 1px solid var(--gray-300);
    background: white;
    color: var(--gray-700);
    cursor: pointer;
    font-weight: 500;
    font-size: 0.875rem;
    transition: var(--transition);
}

.page-number:hover:not(.active) {
    background: var(--gray-50);
    border-color: var(--gray-400);
}

.page-number.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.page-dots {
    display: flex;
    align-items: center;
    padding: 0 8px;
    color: var(--gray-400);
}

/* Loading and Empty States */
.loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    text-align: center;
}

.spinner {
    width: 40px;
    height: 40px;
    border: 3px solid var(--gray-200);
    border-top: 3px solid var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 16px;
}

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    text-align: center;
}

.empty-state.error {
    padding: 40px 20px;
}

.empty-icon {
    color: var(--gray-300);
    margin-bottom: 20px;
}

.empty-state h3 {
    margin: 0 0 12px 0;
    color: var(--gray-700);
    font-size: 1.25rem;
}

.empty-state p {
    margin: 0 0 24px 0;
    color: var(--gray-500);
    max-width: 400px;
    line-height: 1.6;
}

.btn-retry {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-retry:hover {
    background: var(--primary-dark);
}

/* Animations */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive Design */
@media (max-width: 1024px) {
    .page-title {
        font-size: 2rem;
    }
    
    .request-layout {
        gap: 20px;
    }
    
    .header-title-row {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
    }
    
    .btn-view-reservations {
        width: 100%;
        justify-content: center;
    }
    
    .reservation-dates {
        grid-template-columns: 1fr;
    }
    
    .reservation-footer {
        flex-direction: column;
        gap: 12px;
        align-items: stretch;
    }
    
    .reservation-actions {
        justify-content: flex-end;
    }
}

@media (max-width: 768px) {
    .page-container {
        padding: 16px;
    }
    
    .page-title {
        font-size: 1.75rem;
    }
    
    .page-subtitle {
        font-size: 1rem;
    }
    
    .request-layout {
        grid-template-columns: 1fr;
    }
    
    .request-details-header {
        padding: 20px 16px;
        flex-direction: column;
        gap: 16px;
    }
    
    .request-details-title {
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 12px;
    }
    
    .user-info-grid {
        grid-template-columns: 1fr;
    }
    
    .selected-book-info {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .book-meta {
        justify-content: center;
    }
    
    .modal-actions {
        flex-direction: column;
    }
    
    .modal-actions .btn-primary,
    .modal-actions .btn-secondary {
        width: 100%;
    }
}
</style>

<?php include __DIR__ . '/_footer.php'; ?>