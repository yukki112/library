<?php
// Enhanced catalogue page with modern design, pagination, and improved UX
// Includes hidden Elasticsearch AI-powered search capabilities

// Load required files - use absolute paths to be safe
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php'; // Add this line
require_login();

// Load Elasticsearch AI Mock
require_once __DIR__ . '/../includes/elasticsearch_ai_mock.php';

$u = current_user();
if (!in_array($u['role'], ['student','non_staff'], true)) {
    header('Location: dashboard.php');
    exit;
}

// Start session if not already started for CSRF
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize Elasticsearch AI
$elasticAI = ElasticsearchAIMock::getInstance();

include __DIR__ . '/_header.php';

// Get database connection
$pdo = DB::conn();

// Get all categories for filtering
$categories = [];
$stmt = $pdo->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name");
if ($stmt) {
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Pagination configuration - CHANGED TO 4 PER PAGE
$books_per_page = 4; // Changed from 5 to 4
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $books_per_page;

// Check if viewing specific book details
$book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;

// Handle AI search if enabled
$ai_search_enabled = true; // Always enabled but hidden from users
?>

<div class="page-container">
    <div class="page-header">
        <div class="header-content">
            <h1 class="page-title">Book Catalogue</h1>
            <p class="page-subtitle">Explore our extensive collection of literary works</p>
            <?php if ($ai_search_enabled): ?>
            <div class="ai-search-indicator" style="display: none; margin-top: 10px; font-size: 0.85rem; color: #6b7280;">
                <span class="ai-icon" style="display: inline-flex; align-items: center; gap: 4px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2a10 10 0 1 0 10 10"/>
                        <path d="m9 12 2 2 4-4"/>
                    </svg>
                    Intelligent search active
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php if ($book_id > 0): ?>
    <!-- Book Detail View -->
    <div id="bookDetailView" class="detail-view-container">
        <button onclick="window.location.href='books.php'" class="btn-back">
            <span class="btn-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg></span>
            Back to Catalogue
        </button>
        
        <div id="bookDetailContent" class="detail-content">
            <div class="loading-state">
                <div class="spinner-large"></div>
                <p>Loading book details...</p>
            </div>
        </div>
    </div>
    
    <!-- Map Modal -->
    <div id="mapModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    Library Map
                </h2>
                <button class="modal-close" onclick="closeMapModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-map-container">
                    <div id="modalMapLegend" class="map-legend"></div>
                    <div id="modalLibraryMap" class="library-map-container-large"></div>
                </div>
                <div class="current-location-info">
                    <h4>Selected Location</h4>
                    <div id="locationDetails" class="location-details">
                        <p>Click on any slot to view details</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    let currentBookCopies = [];
    let currentBookId = <?php echo $book_id; ?>;
    
    async function loadBookDetails(bookId) {
        try {
            const container = document.getElementById('bookDetailContent');
            container.innerHTML = `
                <div class="loading-state">
                    <div class="spinner-large"></div>
                    <p>Loading book details...</p>
                </div>
            `;
            
            const res = await fetch(`../api/dispatch.php?resource=book-details&id=${bookId}`);
            const data = await res.json();
            
            if (data.error) {
                container.innerHTML = `
                    <div class="alert alert-error">
                        <div class="alert-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                        </div>
                        <div>
                            <strong>Error:</strong> ${escapeHtml(data.error)}
                        </div>
                    </div>
                `;
                return;
            }
            
            // Calculate availability
            const totalCopies = data.total_copies || data.total_copies_cache || 0;
            const availableCopies = data.available_copies || data.available_copies_cache || 0;
            
            // Get cover image URL
            let coverImage = '../assets/default-book.jpg';
            if (data.cover_image_cache) {
                coverImage = '../uploads/covers/' + data.cover_image_cache;
            } else if (data.cover_image) {
                coverImage = '../uploads/covers/' + data.cover_image;
            }
            
            // Render book details
            container.innerHTML = `
                <div class="book-detail-card">
                    <div class="book-cover-section">
                        <div class="book-cover-frame">
                            <img src="${coverImage}" 
                                 alt="${escapeHtml(data.title)}" 
                                 class="book-cover-image"
                                 onerror="this.src='../assets/default-book.jpg'">
                            <div class="cover-status ${availableCopies > 0 ? 'available' : 'unavailable'}">
                                ${availableCopies > 0 ? 'Available' : 'Out of Stock'}
                            </div>
                        </div>
                        
                        <div class="quick-actions">
                            <?php if ($u['role'] === 'student'): ?>
                            <a href="request_book.php?book_id=${bookId}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>" 
                               id="btnRequestBook" class="btn-action-primary">
                                <span class="btn-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                                    </svg>
                                </span>
                                <span class="btn-text">Request Book</span>
                            </a>
                            <?php endif; ?>
                            
                            <button onclick="openMapModal()" class="btn-action-secondary">
                                <span class="btn-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                        <circle cx="12" cy="10" r="3"/>
                                    </svg>
                                </span>
                                View Library Map
                            </button>
                        </div>
                    </div>
                    
                    <div class="book-info-section">
                        <div class="book-header">
                            <h1 class="book-title">${escapeHtml(data.title)}</h1>
                            <p class="book-author">by ${escapeHtml(data.author)}</p>
                        </div>
                        
                        <div class="book-meta-grid">
                            <div class="meta-item">
                                <div class="meta-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                                    </svg>
                                </div>
                                <div class="meta-content">
                                    <span class="meta-label">ISBN</span>
                                    <span class="meta-value">${escapeHtml(data.isbn || 'N/A')}</span>
                                </div>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M16 16v3a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3m15-3v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2Z"/>
                                    </svg>
                                </div>
                                <div class="meta-content">
                                    <span class="meta-label">Publisher</span>
                                    <span class="meta-value">${escapeHtml(data.publisher || 'N/A')}</span>
                                </div>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                </div>
                                <div class="meta-content">
                                    <span class="meta-label">Year</span>
                                    <span class="meta-value">${escapeHtml(data.year_published || 'N/A')}</span>
                                </div>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                </div>
                                <div class="meta-content">
                                    <span class="meta-label">Category</span>
                                    <span class="category-badge">${escapeHtml(data.category || 'Uncategorized')}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="availability-section">
                            <div class="availability-card stock">
                                <div class="availability-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 6H9l-7 7 7 7h11a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2Z"/>
                                        <path d="m9 10 4 4 8-8"/>
                                    </svg>
                                </div>
                                <div class="availability-content">
                                    <span class="availability-label">Available Copies</span>
                                    <span class="availability-count">${availableCopies}</span>
                                </div>
                            </div>
                            
                            <div class="availability-card total">
                                <div class="availability-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M8 21h8a2 2 0 0 0 2-2v-2H6v2a2 2 0 0 0 2 2Z"/>
                                        <path d="M19 4H5a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Z"/>
                                        <path d="M3 10h18"/>
                                        <path d="M10 16h4"/>
                                    </svg>
                                </div>
                                <div class="availability-content">
                                    <span class="availability-label">Total Copies</span>
                                    <span class="availability-count">${totalCopies}</span>
                                </div>
                            </div>
                            
                            <div class="availability-card status ${availableCopies > 0 ? 'in-stock' : 'out-of-stock'}">
                                <div class="availability-icon">
                                    ${availableCopies > 0 ? 
                                        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' :
                                        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>'
                                    }
                                </div>
                                <div class="availability-content">
                                    <span class="availability-label">Status</span>
                                    <span class="availability-status">${availableCopies > 0 ? 'In Stock' : 'Out of Stock'}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="description-section">
                            <h3 class="section-title">
                                <span class="section-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                                        <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                                    </svg>
                                </span>
                                Description
                            </h3>
                            <div class="description-content">
                                <p>${escapeHtml(data.description || 'No description available for this book.')}</p>
                            </div>
                        </div>
                        
                        <!-- Combined location and request sections side by side -->
                        <div class="combined-section">
                            <div class="location-section">
                                <div class="section-header">
                                    <h3 class="section-title">
                                        <span class="section-icon">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                                <circle cx="12" cy="10" r="3"/>
                                            </svg>
                                        </span>
                                        Available Copies
                                    </h3>
                                    <button onclick="openMapModal()" class="btn-map-preview">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                            <circle cx="12" cy="10" r="3"/>
                                        </svg>
                                        View Map
                                    </button>
                                </div>
                                
                                <div class="location-content">
                                    <div class="copies-section">
                                        <div id="copiesList" class="copies-list"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($u['role'] === 'student'): ?>
                            <div class="request-section">
                                <div class="section-header">
                                    <h3 class="section-title">
                                        <span class="section-icon">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 5v14M5 12h14"/>
                                            </svg>
                                        </span>
                                        Request This Book
                                    </h3>
                                </div>
                                <div class="quick-request-form">
                                    <div class="form-group">
                                        <label class="form-label">Quick Action</label>
                                        <div class="quick-action-buttons">
                                            <a href="request_book.php?book_id=${bookId}&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>" 
                                               class="btn-action-primary">
                                                <span class="btn-icon">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                                                    </svg>
                                                </span>
                                                Request Book
                                            </a>
                                        </div>
                                        <small class="form-hint">This will take you to the request page where you can select a copy and choose dates</small>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            `;
            
            // Load copy locations
            await loadBookCopies(bookId);
            
        } catch (error) {
            console.error('Error loading book details:', error);
            document.getElementById('bookDetailContent').innerHTML = `
                <div class="alert alert-error">
                    <div class="alert-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    </div>
                    <div>
                        <strong>Error:</strong> Unable to load book details. Please try again.
                    </div>
                </div>
            `;
        }
    }
    
    async function loadBookCopies(bookId) {
        try {
            const res = await fetch(`../api/dispatch.php?resource=book-copies&book_id=${bookId}`);
            const data = await res.json();
            
            const copiesList = document.getElementById('copiesList');
            currentBookCopies = Array.isArray(data) ? data : [];
            
            if (data.error || !Array.isArray(data) || data.length === 0) {
                copiesList.innerHTML = '<div class="empty-state"><p>No copy location information available</p></div>';
                return;
            }
            
            // Filter available copies only for the list
            const availableCopies = currentBookCopies.filter(copy => copy.status === 'available');
            
            if (availableCopies.length === 0) {
                copiesList.innerHTML = '<div class="empty-state warning"><p>No copies currently available</p></div>';
                return;
            }
            
            // Populate copies list with only available copies
            copiesList.innerHTML = availableCopies.map(copy => `
                <div class="copy-item" onclick="showCopyOnMap('${escapeHtml(copy.current_section || 'A')}', ${escapeHtml(copy.current_shelf || '1')}, ${escapeHtml(copy.current_row || '1')}, ${escapeHtml(copy.current_slot || '1')}, '${escapeHtml(copy.copy_number)}', '${escapeHtml(copy.status)}')">
                    <div class="copy-header">
                        <span class="copy-id">${escapeHtml(copy.copy_number)}</span>
                        <span class="copy-badge condition-${copy.book_condition || 'good'}">
                            ${escapeHtml(copy.book_condition || 'Good')}
                        </span>
                        <span class="copy-status-badge status-${copy.status}">
                            ${escapeHtml(copy.status)}
                        </span>
                    </div>
                    <div class="copy-info">
                        <div class="location-display">
                            <span class="location-label">Location:</span>
                            <span class="location-value">
                                ${escapeHtml(copy.current_section || 'A')}-S${escapeHtml(copy.current_shelf || '1')}-R${escapeHtml(copy.current_row || '1')}-P${escapeHtml(copy.current_slot || '1')}
                            </span>
                        </div>
                        <div class="copy-barcode">${escapeHtml(copy.barcode || 'N/A')}</div>
                    </div>
                    <button class="btn-show-on-map" onclick="event.stopPropagation(); showCopyOnMap('${escapeHtml(copy.current_section || 'A')}', ${escapeHtml(copy.current_shelf || '1')}, ${escapeHtml(copy.current_row || '1')}, ${escapeHtml(copy.current_slot || '1')}, '${escapeHtml(copy.copy_number)}', '${escapeHtml(copy.status)}')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                            <circle cx="12" cy="10" r="3"/>
                        </svg>
                        Show on Map
                    </button>
                </div>
            `).join('');
            
        } catch (error) {
            console.error('Error loading book copies:', error);
            document.getElementById('copiesList').innerHTML = '<div class="empty-state"><p>Error loading copy information</p></div>';
        }
    }
    
    function openMapModal() {
        const modal = document.getElementById('mapModal');
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        renderModalMap();
    }
    
    function closeMapModal() {
        const modal = document.getElementById('mapModal');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    async function renderModalMap() {
        try {
            const mapContainer = document.getElementById('modalLibraryMap');
            const legendContainer = document.getElementById('modalMapLegend');
            
            // Get all library sections configuration
            const sectionsRes = await fetch('../api/dispatch.php?resource=book-locations&book_id=' + currentBookId);
            const sectionsData = await sectionsRes.json();
            
            if (sectionsData.error) {
                mapContainer.innerHTML = `
                    <div class="empty-state">
                        <p>${escapeHtml(sectionsData.error)}</p>
                    </div>
                `;
                return;
            }
            
            const sectionColors = {
                'A': '#3B82F6', 'B': '#10B981', 'C': '#EF4444',
                'D': '#8B5CF6', 'E': '#F59E0B', 'F': '#EC4899'
            };
            
            // Get sections where this book has copies
            const bookSections = [];
            currentBookCopies.forEach(copy => {
                if (copy.current_section && !bookSections.includes(copy.current_section)) {
                    bookSections.push(copy.current_section);
                }
            });
            
            // Create interactive library map
            let mapHTML = '<div class="library-sections-modal">';
            
            if (sectionsData.sections && Array.isArray(sectionsData.sections)) {
                sectionsData.sections.forEach(section => {
                    const sectionCode = section.section;
                    const containsBook = bookSections.includes(sectionCode);
                    
                    // Get current book's copies in this section
                    const currentBookInSection = currentBookCopies.filter(copy => 
                        copy.current_section === sectionCode
                    );
                    
                    mapHTML += `
                        <div class="map-section-modal" data-section="${sectionCode}">
                            <div class="section-header-modal" style="border-left-color: ${sectionColors[sectionCode] || '#3B82F6'}">
                                <h4>Section ${sectionCode}</h4>
                                <span class="section-info-modal">${containsBook ? 'Contains this book' : 'Other books'}</span>
                            </div>
                            
                            <div class="shelves-container-modal">
                    `;
                    
                    // Create 5 shelves per section
                    for (let shelf = 1; shelf <= 5; shelf++) {
                        // Get current book's copies on this shelf
                        const currentBookOnShelf = currentBookInSection.filter(copy => 
                            parseInt(copy.current_shelf) === shelf
                        );
                        
                        mapHTML += `
                            <div class="shelf-modal" data-shelf="${shelf}">
                                <div class="shelf-label-modal">Shelf ${shelf}</div>
                                <div class="shelf-content-modal">
                        `;
                        
                        // Create 6 rows per shelf
                        for (let row = 1; row <= 6; row++) {
                            // Get current book's copies in this row
                            const currentBookInRow = currentBookOnShelf.filter(copy => 
                                parseInt(copy.current_row) === row
                            );
                            
                            mapHTML += `
                                <div class="row-modal" data-row="${row}">
                                    <div class="row-label-modal">Row ${row}</div>
                                    <div class="slots-modal">
                            `;
                            
                            // Create 12 slots per row
                            for (let slot = 1; slot <= 12; slot++) {
                                // Check if current book exists in this exact location
                                const currentCopy = currentBookInRow.find(copy => 
                                    parseInt(copy.current_slot) === slot
                                );
                                
                                const isCurrentBookLocation = !!currentCopy;
                                const copyNumber = currentCopy ? currentCopy.copy_number : '';
                                const copyStatus = currentCopy ? currentCopy.status : '';
                                
                                const slotClasses = ['slot-modal'];
                                if (isCurrentBookLocation) {
                                    slotClasses.push('current-book');
                                    
                                    // Add status-specific classes
                                    if (copyStatus === 'available') {
                                        slotClasses.push('status-available');
                                    } else if (copyStatus === 'borrowed') {
                                        slotClasses.push('status-borrowed');
                                    } else if (copyStatus === 'reserved') {
                                        slotClasses.push('status-reserved');
                                    }
                                } else if (containsBook) {
                                    slotClasses.push('empty');
                                } else {
                                    slotClasses.push('occupied');
                                }
                                
                                // Determine symbol based on status
                                let slotSymbol = '';
                                let slotTitle = '';
                                
                                if (isCurrentBookLocation) {
                                    switch(copyStatus) {
                                        case 'available':
                                            slotSymbol = '✓';
                                            slotTitle = 'Available - ' + copyNumber;
                                            break;
                                        case 'borrowed':
                                            slotSymbol = '⇪';
                                            slotTitle = 'Borrowed - ' + copyNumber;
                                            break;
                                        case 'reserved':
                                            slotSymbol = '⏳';
                                            slotTitle = 'Reserved - ' + copyNumber;
                                            break;
                                        default:
                                            slotSymbol = '★';
                                            slotTitle = 'Current Book - ' + copyNumber;
                                    }
                                } else {
                                    slotTitle = containsBook ? 'Empty' : 'Other Books';
                                }
                                
                                mapHTML += `
                                    <div class="${slotClasses.join(' ')}" 
                                         data-section="${sectionCode}"
                                         data-shelf="${shelf}"
                                         data-row="${row}"
                                         data-slot="${slot}"
                                         data-copy="${copyNumber}"
                                         data-status="${copyStatus}"
                                         title="${slotTitle}"
                                         onclick="handleModalSlotClick('${sectionCode}', ${shelf}, ${row}, ${slot}, '${copyNumber}', '${copyStatus}')">
                                        ${slotSymbol}
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
            } else {
                mapHTML = '<div class="empty-state"><p>Library map configuration not available</p></div>';
            }
            
            mapHTML += '</div>';
            mapContainer.innerHTML = mapHTML;
            
            // Create enhanced legend
            legendContainer.innerHTML = `
                <div class="legend-modal">
                    <div class="legend-item-modal">
                        <div class="legend-color-modal current-book status-available"></div>
                        <span>Available (✓)</span>
                    </div>
                    <div class="legend-item-modal">
                        <div class="legend-color-modal current-book status-borrowed"></div>
                        <span>Borrowed (⇪)</span>
                    </div>
                    <div class="legend-item-modal">
                        <div class="legend-color-modal current-book status-reserved"></div>
                        <span>Reserved (⏳)</span>
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
            
            // Add hover effects
            document.querySelectorAll('.slot-modal').forEach(slot => {
                slot.addEventListener('mouseenter', function() {
                    if (this.classList.contains('status-available')) {
                        this.style.boxShadow = '0 0 0 3px #10b981, 0 0 20px rgba(16, 185, 129, 0.3)';
                    } else if (this.classList.contains('status-borrowed')) {
                        this.style.boxShadow = '0 0 0 3px #ef4444, 0 0 20px rgba(239, 68, 68, 0.3)';
                    } else if (this.classList.contains('status-reserved')) {
                        this.style.boxShadow = '0 0 0 3px #f59e0b, 0 0 20px rgba(245, 158, 11, 0.3)';
                    } else if (this.classList.contains('occupied')) {
                        this.style.boxShadow = '0 0 0 2px #6b7280, 0 0 15px rgba(107, 114, 128, 0.2)';
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
                    <button onclick="renderModalMap()" class="btn-retry">Retry</button>
                </div>
            `;
        }
    }
    
    function showCopyOnMap(section, shelf, row, slot, copyNumber, copyStatus) {
        openMapModal();
        setTimeout(() => {
            highlightLocation(section, shelf, row, slot, copyNumber, copyStatus);
        }, 300);
    }
    
    function highlightLocation(section, shelf, row, slot, copyNumber, copyStatus) {
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
            
            // Add appropriate pulsing animation based on status
            let animationColor = '#fbbf24'; // Default yellow
            if (copyStatus === 'available') {
                animationColor = '#10b981'; // Green
            } else if (copyStatus === 'borrowed') {
                animationColor = '#ef4444'; // Red
            } else if (copyStatus === 'reserved') {
                animationColor = '#f59e0b'; // Orange
            }
            
            targetSlot.style.animation = `pulse-highlight-${copyStatus || 'default'} 2s infinite`;
            
            // Create style for different status animations
            if (!document.getElementById('statusAnimations')) {
                const style = document.createElement('style');
                style.id = 'statusAnimations';
                style.textContent = `
                    @keyframes pulse-highlight-available {
                        0%, 100% { 
                            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
                            transform: scale(1.2);
                        }
                        50% { 
                            box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
                            transform: scale(1.3);
                        }
                    }
                    @keyframes pulse-highlight-borrowed {
                        0%, 100% { 
                            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
                            transform: scale(1.2);
                        }
                        50% { 
                            box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
                            transform: scale(1.3);
                        }
                    }
                    @keyframes pulse-highlight-reserved {
                        0%, 100% { 
                            box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7);
                            transform: scale(1.2);
                        }
                        50% { 
                            box-shadow: 0 0 0 10px rgba(245, 158, 11, 0);
                            transform: scale(1.3);
                        }
                    }
                    @keyframes pulse-highlight-default {
                        0%, 100% { 
                            box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.7);
                            transform: scale(1.2);
                        }
                        50% { 
                            box-shadow: 0 0 0 10px rgba(251, 191, 36, 0);
                            transform: scale(1.3);
                        }
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Show location details
            const locationDetails = document.getElementById('locationDetails');
            
            // Get status display text and color
            let statusText = copyStatus || 'Unknown';
            let statusColor = '#6b7280'; // Default gray
            switch(copyStatus) {
                case 'available':
                    statusText = 'Available';
                    statusColor = '#10b981';
                    break;
                case 'borrowed':
                    statusText = 'Borrowed';
                    statusColor = '#ef4444';
                    break;
                case 'reserved':
                    statusText = 'Reserved';
                    statusColor = '#f59e0b';
                    break;
            }
            
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
                            <span class="detail-value" style="color: ${statusColor}; font-weight: bold;">
                                ${statusText}
                            </span>
                        </div>
                        ${copyStatus === 'borrowed' ? `<div class="detail-item">
                            <span class="detail-label">Note:</span>
                            <span class="detail-value" style="color: #ef4444; font-size: 0.9rem;">
                                This copy is currently borrowed and unavailable
                            </span>
                        </div>` : ''}
                        ${copyStatus === 'reserved' ? `<div class="detail-item">
                            <span class="detail-label">Note:</span>
                            <span class="detail-value" style="color: #f59e0b; font-size: 0.9rem;">
                                This copy is reserved and unavailable
                            </span>
                        </div>` : ''}
                    </div>
                </div>
            `;
        }
    }
    
    function handleModalSlotClick(section, shelf, row, slot, copyNumber, copyStatus) {
        highlightLocation(section, shelf, row, slot, copyNumber, copyStatus);
    }
    
    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('mapModal');
        if (event.target === modal) {
            closeMapModal();
        }
    }
    
    // Load book details on page load
    loadBookDetails(currentBookId);
    
    </script>

<?php else: ?>
    <!-- Main Catalogue View -->
    <div class="catalogue-container">
        <div class="catalogue-controls card">
            <div class="search-section">
                <div class="search-box" id="searchBox">
                    <div class="search-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                    </div>
                    <input id="bookSearch" type="text" 
                           placeholder="Search books by title, author, ISBN, or category..." 
                           class="search-input"
                           autocomplete="off">
                    <button id="btnBookSearch" class="btn-search">
                        Search
                    </button>
                </div>
                <div id="aiSuggestions" class="ai-suggestions" style="display: none;"></div>
                <div id="spellingCorrection" class="spelling-correction" style="display: none;"></div>
            </div>
            
            <div class="filters-section">
                <div class="filter-group">
                    <label for="categoryFilter" class="filter-label">Category</label>
                    <select id="categoryFilter" class="form-select">
                        <option value="all">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['id']); ?>">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="sortFilter" class="filter-label">Sort by</label>
                    <select id="sortFilter" class="form-select">
                        <option value="newest">Newest First</option>
                        <option value="title">Title A-Z</option>
                        <option value="available">Available Now</option>
                        <option value="popular">Most Popular</option>
                        <option value="relevance" selected>Relevance</option>
                    </select>
                </div>
                
                <div class="view-toggle">
                    <button class="view-btn active" data-view="grid" title="Grid View">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="7" height="7"/>
                            <rect x="14" y="3" width="7" height="7"/>
                            <rect x="14" y="14" width="7" height="7"/>
                            <rect x="3" y="14" width="7" height="7"/>
                        </svg>
                    </button>
                    <button class="view-btn" data-view="list" title="List View">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="8" y1="6" x2="21" y2="6"/>
                            <line x1="8" y1="12" x2="21" y2="12"/>
                            <line x1="8" y1="18" x2="21" y2="18"/>
                            <line x1="3" y1="6" x2="3.01" y2="6"/>
                            <line x1="3" y1="12" x2="3.01" y2="12"/>
                            <line x1="3" y1="18" x2="3.01" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="stats-bar">
            <div class="stat-item">
                <span class="stat-label">Total Books</span>
                <span class="stat-value" id="totalBooks">0</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Available Now</span>
                <span class="stat-value" id="availableBooks">0</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Categories</span>
                <span class="stat-value"><?php echo count($categories); ?></span>
            </div>
            <div class="stat-item ai-stat" style="display: none;">
                <span class="stat-label">AI Insights</span>
                <span class="stat-value" id="aiInsights">0</span>
            </div>
        </div>

        <!-- Books Container -->
        <div id="booksContainer" class="books-container">
            <div class="loading-state">
                <div class="spinner"></div>
                <p>Loading books...</p>
            </div>
        </div>

        <!-- Pagination -->
        <div id="paginationContainer" class="pagination-container">
            <!-- Pagination will be loaded here -->
        </div>
    </div>

    <script>
    let allBooks = [];
    let currentView = 'grid';
    let currentPage = <?php echo $current_page; ?>;
    const booksPerPage = <?php echo $books_per_page; ?>; // This is now 4
    let aiSearchEnabled = <?php echo $ai_search_enabled ? 'true' : 'false'; ?>;
    let searchTimeout = null;
    let lastSearchQuery = '';
    let lastCorrectedQuery = '';
    let isMisspellingCorrected = false;

    // AI Search Functions
    async function performAISearch(query, books) {
        if (!aiSearchEnabled || !query.trim()) {
            return books;
        }

        try {
            // Show AI indicator
            const indicator = document.querySelector('.ai-search-indicator');
            if (indicator) {
                indicator.style.display = 'block';
            }

            console.log('AI Search processing:', query);
            
            // First check for spelling corrections
            const correctedQuery = await checkSpellingCorrection(query);
            
            // Show spelling correction if needed
            if (correctedQuery && correctedQuery !== query) {
                isMisspellingCorrected = true;
                showSpellingCorrection(query, correctedQuery);
                query = correctedQuery;
                lastCorrectedQuery = correctedQuery;
            } else {
                isMisspellingCorrected = false;
                hideSpellingCorrection();
            }
            
            // Simulate AI processing delay
            await new Promise(resolve => setTimeout(resolve, 50));
            
            // Create a copy of books for AI processing
            let aiProcessedBooks = [...books];
            
            // Apply AI-powered filtering and ranking
            const queryLower = query.toLowerCase();
            
            // Score each book based on multiple factors
            const scoredBooks = books.map(book => {
                let score = 0;
                
                // Factor 1: Exact matches (highest priority)
                const title = (book.title || '').toLowerCase();
                const author = (book.author || '').toLowerCase();
                const category = (book.category || '').toLowerCase();
                const description = (book.description || '').toLowerCase();
                
                if (title.includes(queryLower)) score += 100;
                if (author.includes(queryLower)) score += 80;
                if (book.isbn && book.isbn.toLowerCase().includes(queryLower)) score += 100;
                if (category.includes(queryLower)) score += 60;
                if (description.includes(queryLower)) score += 30;
                
                // Factor 2: Semantic understanding
                const semanticBonus = calculateSemanticBonus(queryLower, book);
                score += semanticBonus;
                
                // Factor 3: Availability boost
                const availableCopies = book.available_copies || book.available_copies_cache || 0;
                if (availableCopies > 0) {
                    score += 25;
                    if (availableCopies > 3) score += 15;
                }
                
                // Factor 4: Recency boost
                const year = book.year_published || 0;
                const currentYear = new Date().getFullYear();
                if (year >= (currentYear - 2)) score += 30;
                else if (year >= (currentYear - 5)) score += 20;
                else if (year >= (currentYear - 10)) score += 10;
                
                // Factor 5: Popularity boost
                const totalCopies = book.total_copies || book.total_copies_cache || 0;
                if (totalCopies > 10) score += 20;
                else if (totalCopies > 5) score += 10;
                
                // Factor 6: Conceptual matches
                if (isConceptualMatch(queryLower, book)) {
                    score += 40;
                }
                
                // Factor 7: Misspelling tolerance (if user typed misspelling)
                if (isMisspellingCorrected) {
                    const originalQuery = lastSearchQuery.toLowerCase();
                    if (title.includes(originalQuery)) score += 15;
                    if (author.includes(originalQuery)) score += 10;
                    if (description.includes(originalQuery)) score += 5;
                }
                
                return {
                    book: book,
                    score: score,
                    aiExplanation: generateAIExplanation(queryLower, book, score, isMisspellingCorrected)
                };
            });
            
            // Filter out books with zero score and sort by score
            const filteredBooks = scoredBooks
                .filter(item => item.score > 0)
                .sort((a, b) => b.score - a.score)
                .map(item => item.book);
            
            // Update AI insights
            updateAIInsights({
                total_matches: filteredBooks.length,
                corrected_spelling: isMisspellingCorrected,
                semantic_matches: filteredBooks.length > 0
            });
            
            return filteredBooks;
            
        } catch (error) {
            console.error('AI Search failed:', error);
            return books; // Fallback to regular search
        }
    }
    
    function calculateSemanticBonus(query, book) {
        let bonus = 0;
        const title = (book.title || '').toLowerCase();
        const description = (book.description || '').toLowerCase();
        
        // Programming related
        if (query.includes('programming') || query.includes('coding') || query.includes('software')) {
            if (title.includes('code') || title.includes('software') || title.includes('developer') ||
                description.includes('programming') || description.includes('coding')) {
                bonus += 50;
            }
        }
        
        // Arts related
        if (query.includes('art') || query.includes('painting') || query.includes('drawing')) {
            if (title.includes('art') || title.includes('painting') || title.includes('drawing') ||
                description.includes('art') || description.includes('creative')) {
                bonus += 50;
            }
        }
        
        // Business related
        if (query.includes('business') || query.includes('management') || query.includes('finance')) {
            if (title.includes('business') || title.includes('management') || title.includes('finance') ||
                description.includes('business') || description.includes('strategy')) {
                bonus += 50;
            }
        }
        
        // Data related
        if (query.includes('data') || query.includes('database') || query.includes('storage')) {
            if (title.includes('data') || title.includes('database') || title.includes('sql') ||
                description.includes('data') || description.includes('storage')) {
                bonus += 50;
            }
        }
        
        // Check for related terms in semantic patterns
        const relatedTerms = getRelatedTerms(query);
        relatedTerms.forEach(term => {
            if (title.includes(term)) bonus += 20;
            if (description.includes(term)) bonus += 10;
        });
        
        return bonus;
    }
    
    function getRelatedTerms(query) {
        const terms = [];
        
        // Programming related
        if (query.includes('programming') || query.includes('code')) {
            terms.push('software', 'developer', 'algorithm', 'web', 'app', 'framework');
        }
        
        // Arts related
        if (query.includes('art') || query.includes('painting')) {
            terms.push('design', 'creative', 'drawing', 'sketch', 'illustration', 'visual');
        }
        
        // Business related
        if (query.includes('business') || query.includes('management')) {
            terms.push('finance', 'marketing', 'strategy', 'leadership', 'organization');
        }
        
        // Data related
        if (query.includes('data') || query.includes('database')) {
            terms.push('sql', 'storage', 'mysql', 'mongodb', 'analysis', 'analytics');
        }
        
        // Learning related
        if (query.includes('learn') || query.includes('study')) {
            terms.push('education', 'knowledge', 'skill', 'tutorial', 'guide');
        }
        
        return terms.slice(0, 10); // Limit to 10 terms
    }
    
    function isConceptualMatch(query, book) {
        const title = (book.title || '').toLowerCase();
        const description = (book.description || '').toLowerCase();
        
        // Conceptual pairs
        const conceptualPairs = {
            'data storage': ['database', 'sql', 'mongodb', 'redis'],
            'web development': ['html', 'css', 'javascript', 'react', 'vue'],
            'mobile app': ['android', 'ios', 'react native', 'flutter'],
            'machine learning': ['ai', 'artificial intelligence', 'neural network'],
            'cloud computing': ['aws', 'azure', 'google cloud', 'serverless'],
            'art design': ['painting', 'drawing', 'sketching', 'illustration'],
            'business management': ['leadership', 'strategy', 'administration'],
            'health fitness': ['exercise', 'workout', 'nutrition', 'wellness'],
        };
        
        for (const [concept, terms] of Object.entries(conceptualPairs)) {
            if (query.includes(concept)) {
                for (const term of terms) {
                    if (title.includes(term) || description.includes(term)) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    function generateAIExplanation(query, book, score, wasCorrected) {
        const reasons = [];
        
        if (wasCorrected) {
            reasons.push("Showing results for corrected spelling");
        }
        
        const title = (book.title || '').toLowerCase();
        if (title.includes(query)) {
            reasons.push("Title contains your search term");
        }
        
        const availableCopies = book.available_copies || book.available_copies_cache || 0;
        if (availableCopies > 0) {
            reasons.push("Available for immediate borrowing");
        }
        
        const year = book.year_published || 0;
        if (year >= 2020) {
            reasons.push("Recent publication");
        }
        
        const totalCopies = book.total_copies || book.total_copies_cache || 0;
        if (totalCopies > 5) {
            reasons.push("Popular title in our collection");
        }
        
        // Check for semantic match
        if (query.includes('programming') && 
            (title.includes('code') || title.includes('software') || 
             (book.description || '').toLowerCase().includes('programming'))) {
            reasons.push("Matches programming category");
        }
        
        if (query.includes('art') && 
            (title.includes('painting') || title.includes('drawing') || 
             (book.description || '').toLowerCase().includes('art'))) {
            reasons.push("Matches arts category");
        }
        
        if (query.includes('business') && 
            (title.includes('management') || title.includes('finance') || 
             (book.description || '').toLowerCase().includes('business'))) {
            reasons.push("Matches business category");
        }
        
        if (query.includes('data') && 
            (title.includes('database') || title.includes('sql') || 
             (book.description || '').toLowerCase().includes('data'))) {
            reasons.push("Matches data-related books");
        }
        
        return reasons.length > 0 ? reasons.join('; ') : "Relevant based on multiple factors";
    }

    async function checkSpellingCorrection(query) {
        if (!query || query.length < 2) {
            return query;
        }

        try {
            // Send request to check spelling
            const response = await fetch('../api/ai-search.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'check_spelling',
                    query: query
                })
            });

            if (response.ok) {
                const result = await response.json();
                if (result.success && result.corrected_query && result.corrected_query !== query) {
                    return result.corrected_query;
                }
            }
        } catch (error) {
            console.error('Spelling check failed:', error);
        }

        // Fallback to local spelling correction
        return performLocalSpellingCorrection(query);
    }
    
    function performLocalSpellingCorrection(query) {
        // Simple local spelling correction
        const misspellings = {
            // Arts related
            'arys': 'arts',
            'arrt': 'art',
            'artt': 'art',
            'aart': 'art',
            'panting': 'painting',
            'drawin': 'drawing',
            'draing': 'drawing',
            'scketch': 'sketch',
            'ilustration': 'illustration',
            
            // Programming related
            'programing': 'programming',
            'programmig': 'programming',
            'progamming': 'programming',
            'sofware': 'software',
            'develper': 'developer',
            'algoritm': 'algorithm',
            'databse': 'database',
            'javscript': 'javascript',
            'pyton': 'python',
            
            // Data related
            'dataa': 'data',
            'databaze': 'database',
            'data-base': 'database',
            'data base': 'database',
            
            // Business related
            'bussiness': 'business',
            'buisness': 'business',
            'managment': 'management',
            'finace': 'finance',
            'marketting': 'marketing',
            
            // General
            'intelijence': 'intelligence',
            'knowlege': 'knowledge',
            'fotball': 'football',
            'baskeball': 'basketball',
            'eduction': 'education',
            'teching': 'teaching'
        };
        
        const words = query.toLowerCase().split(' ');
        const correctedWords = words.map(word => {
            // Check for exact misspelling
            if (misspellings[word]) {
                return misspellings[word];
            }
            
            // Check for close matches using Levenshtein distance
            for (const [correct, variations] of Object.entries({
                'art': ['arys', 'arrt', 'artt', 'aart'],
                'data': ['dataa', 'date', 'datta'],
                'programming': ['programing', 'programmig', 'progamming'],
                'database': ['databse', 'databaze', 'data-base'],
                'business': ['bussiness', 'buisness', 'busness']
            })) {
                if (variations.includes(word)) {
                    return correct;
                }
                
                // Check similarity
                if (word.length > 2 && levenshteinDistance(word, correct) <= 2) {
                    return correct;
                }
            }
            
            return word;
        });
        
        const correctedQuery = correctedWords.join(' ');
        return correctedQuery !== query ? correctedQuery : query;
    }
    
    function levenshteinDistance(a, b) {
        if (a.length === 0) return b.length;
        if (b.length === 0) return a.length;
        
        const matrix = [];
        for (let i = 0; i <= b.length; i++) {
            matrix[i] = [i];
        }
        for (let j = 0; j <= a.length; j++) {
            matrix[0][j] = j;
        }
        
        for (let i = 1; i <= b.length; i++) {
            for (let j = 1; j <= a.length; j++) {
                if (b.charAt(i - 1) === a.charAt(j - 1)) {
                    matrix[i][j] = matrix[i - 1][j - 1];
                } else {
                    matrix[i][j] = Math.min(
                        matrix[i - 1][j - 1] + 1,
                        matrix[i][j - 1] + 1,
                        matrix[i - 1][j] + 1
                    );
                }
            }
        }
        
        return matrix[b.length][a.length];
    }

    function showSpellingCorrection(original, corrected) {
        const correctionContainer = document.getElementById('spellingCorrection');
        if (correctionContainer) {
            correctionContainer.innerHTML = `
                <div class="spelling-correction-card">
                    <span class="correction-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2a10 10 0 1 0 10 10"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                    </span>
                    <span class="correction-text">
                        Showing results for "<strong>${escapeHtml(corrected)}</strong>"
                        <span class="original-query">Search instead for "${escapeHtml(original)}"</span>
                    </span>
                    <button class="correction-close" onclick="hideSpellingCorrection()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6 6 18"/>
                            <path d="m6 6 12 12"/>
                        </svg>
                    </button>
                </div>
            `;
            correctionContainer.style.display = 'block';
        }
    }
    
    function hideSpellingCorrection() {
        const correctionContainer = document.getElementById('spellingCorrection');
        if (correctionContainer) {
            correctionContainer.style.display = 'none';
        }
    }

    function updateAIInsights(insights) {
        const aiInsightsEl = document.getElementById('aiInsights');
        const aiStatEl = document.querySelector('.ai-stat');
        
        if (aiInsightsEl) {
            let insightText = '';
            if (insights.total_matches) {
                insightText = insights.total_matches + ' matches';
                if (insights.corrected_spelling) {
                    insightText += ' (spelling corrected)';
                }
                if (insights.semantic_matches) {
                    insightText += ' (semantic)';
                }
            }
            aiInsightsEl.textContent = insightText;
            aiStatEl.style.display = 'flex';
            
            // Hide after 10 seconds
            setTimeout(() => {
                aiStatEl.style.display = 'none';
            }, 10000);
        }
    }

    function showAISuggestions(query) {
        const suggestionsContainer = document.getElementById('aiSuggestions');
        if (!suggestionsContainer || query.length < 2) {
            suggestionsContainer.style.display = 'none';
            return;
        }

        // Generate intelligent suggestions based on query
        const suggestions = generateAISuggestions(query);
        
        if (suggestions.length > 0) {
            suggestionsContainer.innerHTML = suggestions.map(suggestion => `
                <div class="ai-suggestion-item" onclick="applyAISuggestion('${escapeHtml(suggestion)}')">
                    <span class="ai-suggestion-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="m19 12-7-7-7 7"/>
                            <path d="M5 5v14"/>
                        </svg>
                    </span>
                    <span class="ai-suggestion-text">${escapeHtml(suggestion)}</span>
                </div>
            `).join('');
            suggestionsContainer.style.display = 'block';
        } else {
            suggestionsContainer.style.display = 'none';
        }
    }

    function generateAISuggestions(query) {
        const queryLower = query.toLowerCase();
        const suggestions = [];
        
        // Add spelling suggestions first
        const spellingSuggestions = getSpellingSuggestions(query);
        if (spellingSuggestions.length > 0) {
            suggestions.push(...spellingSuggestions);
        }
        
        // Common search patterns
        const patterns = {
            'programming': ['programming books', 'coding guides', 'software development', 'web development'],
            'art': ['art books', 'painting guides', 'drawing tutorials', 'creative arts'],
            'business': ['business management', 'finance books', 'marketing strategy', 'entrepreneurship'],
            'data': ['database guides', 'SQL books', 'data analysis', 'big data'],
            'database': ['SQL guides', 'database management', 'data storage', 'MySQL books'],
            'learn': ['learning guides', 'tutorial books', 'beginner guides', 'how-to books'],
            'advanced': ['expert guides', 'advanced topics', 'professional books', 'master level']
        };
        
        // Check for pattern matches
        for (const [pattern, suggestionList] of Object.entries(patterns)) {
            if (queryLower.includes(pattern)) {
                suggestions.push(...suggestionList);
            }
        }
        
        // Add generic suggestions if none found
        if (suggestions.length === 0 && query.length > 2) {
            suggestions.push(
                `Search for "${query}" in titles`,
                `Find books by author containing "${query}"`,
                `Browse ${query} category`,
                `Available ${query} books`
            );
        }
        
        return suggestions.slice(0, 6); // Limit to 6 suggestions
    }
    
    function getSpellingSuggestions(query) {
        const suggestions = [];
        const queryLower = query.toLowerCase();
        
        // Common misspellings and corrections
        const commonCorrections = {
            'arys': 'arts',
            'programing': 'programming',
            'databse': 'database',
            'bussiness': 'business',
            'managment': 'management',
            'sofware': 'software',
            'teching': 'teaching',
            'eduction': 'education',
            'intelijence': 'intelligence'
        };
        
        // Check if query matches any common misspellings
        for (const [misspelling, correction] of Object.entries(commonCorrections)) {
            if (queryLower === misspelling) {
                suggestions.push(`${correction}`);
            }
        }
        
        // Check for close matches
        if (suggestions.length === 0) {
            for (const correction of Object.values(commonCorrections)) {
                if (levenshteinDistance(queryLower, correction) <= 2 && queryLower !== correction) {
                    suggestions.push(`${correction}`);
                    break;
                }
            }
        }
        
        return suggestions;
    }

    function applyAISuggestion(suggestion) {
        document.getElementById('bookSearch').value = suggestion;
        document.getElementById('aiSuggestions').style.display = 'none';
        document.getElementById('spellingCorrection').style.display = 'none';
        currentPage = 1;
        updateDisplay();
    }

    async function loadBooks() {
        try {
            const container = document.getElementById('booksContainer');
            container.innerHTML = `
                <div class="loading-state">
                    <div class="spinner"></div>
                    <p>Loading books...</p>
                </div>
            `;
            
            const res = await fetch('../api/dispatch.php?resource=books');
            const data = await res.json();
            
            if (Array.isArray(data)) {
                allBooks = data;
                updateStats();
                updateDisplay();
            } else {
                throw new Error('Invalid data format');
            }
        } catch(e) { 
            console.error('Error loading books:', e);
            document.getElementById('booksContainer').innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
                            <path d="M9 10h6"/>
                            <path d="M12 7v6"/>
                        </svg>
                    </div>
                    <h3>Unable to Load Books</h3>
                    <p>Please try again later or contact support if the problem persists.</p>
                    <button onclick="loadBooks()" class="btn-retry">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                        </svg>
                        Retry
                    </button>
                </div>
            `;
        }
    }
    
    function updateStats() {
        const totalBooks = allBooks.length;
        const availableBooks = allBooks.reduce((sum, book) => {
            // Get accurate available copies from cache or calculate from data
            const available = book.available_copies || book.available_copies_cache || 0;
            return sum + available;
        }, 0);
        
        document.getElementById('totalBooks').textContent = totalBooks;
        document.getElementById('availableBooks').textContent = availableBooks;
    }

    async function updateDisplay() {
        const searchTerm = document.getElementById('bookSearch').value.trim();
        const categoryId = document.getElementById('categoryFilter').value;
        const sortFilter = document.getElementById('sortFilter').value;
        
        // Apply filters
        let filtered = allBooks;
        
        // Apply category filter
        if (categoryId !== 'all') {
            filtered = filtered.filter(b => String(b.category_id) === categoryId);
        }
        
        // Apply search filter
        if (searchTerm) {
            filtered = filtered.filter(b => 
                (b.title || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                (b.author || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                (b.isbn || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                (b.publisher || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                (b.category || '').toLowerCase().includes(searchTerm.toLowerCase())
            );
        }
        
        // Apply AI-powered search if enabled and query has changed
        if (aiSearchEnabled && searchTerm && searchTerm !== lastSearchQuery) {
            lastSearchQuery = searchTerm;
            filtered = await performAISearch(searchTerm, filtered);
        } else if (!searchTerm) {
            // Reset spelling correction when search is cleared
            hideSpellingCorrection();
            isMisspellingCorrected = false;
        }
        
        // Apply sorting
        filtered = sortBooks(filtered, sortFilter);
        
        // Paginate - SHOWING 4 BOOKS PER PAGE
        const totalPages = Math.ceil(filtered.length / booksPerPage);
        currentPage = Math.min(currentPage, totalPages || 1);
        const startIdx = (currentPage - 1) * booksPerPage;
        const endIdx = startIdx + booksPerPage;
        const paginatedBooks = filtered.slice(startIdx, endIdx);
        
        // Render books
        if (currentView === 'grid') {
            renderBooksGrid(paginatedBooks);
        } else {
            renderBooksList(paginatedBooks);
        }
        
        // Render pagination
        renderPagination(filtered.length);
        
        // Show AI suggestions
        if (aiSearchEnabled && searchTerm) {
            showAISuggestions(searchTerm);
        }
    }

    function sortBooks(books, sortBy) {
        const sorted = [...books];
        
        switch(sortBy) {
            case 'newest':
                sorted.sort((a, b) => (b.year_published || 0) - (a.year_published || 0));
                break;
            case 'title':
                sorted.sort((a, b) => (a.title || '').localeCompare(b.title || ''));
                break;
            case 'available':
                sorted.sort((a, b) => {
                    const availA = a.available_copies || a.available_copies_cache || 0;
                    const availB = b.available_copies || b.available_copies_cache || 0;
                    return availB - availA;
                });
                break;
            case 'popular':
                sorted.sort((a, b) => {
                    const totalA = a.total_copies || a.total_copies_cache || 0;
                    const totalB = b.total_copies || b.total_copies_cache || 0;
                    return totalB - totalA;
                });
                break;
            case 'relevance':
                // Already sorted by AI relevance
                break;
        }
        
        return sorted;
    }

    function renderBooksGrid(books) {
        const container = document.getElementById('booksContainer');
        container.className = 'books-grid';
        
        if (!Array.isArray(books) || books.length === 0) {
            container.innerHTML = `
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
                    <p>Try adjusting your search or filter criteria</p>
                    <button onclick="resetFilters()" class="btn-reset">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                        </svg>
                        Reset Filters
                    </button>
                </div>
            `;
            return;
        }
        
        container.innerHTML = books.map(book => {
            // Get accurate copy counts
            const totalCopies = book.total_copies || book.total_copies_cache || 0;
            const availableCopies = book.available_copies || book.available_copies_cache || 0;
            
            let coverImage = '../assets/default-book.jpg';
            if (book.cover_image_cache) {
                coverImage = '../uploads/covers/' + book.cover_image_cache;
            } else if (book.cover_image) {
                coverImage = '../uploads/covers/' + book.cover_image;
            }
            
            return `
                <div class="book-card">
                    <div class="book-card-inner">
                        <div class="book-cover">
                            <img src="${coverImage}" 
                                 alt="${escapeHtml(book.title)}"
                                 class="book-image"
                                 onerror="this.src='../assets/default-book.jpg'">
                            <div class="book-status ${availableCopies > 0 ? 'available' : 'unavailable'}">
                                ${availableCopies > 0 ? 'Available' : 'Out of Stock'}
                            </div>
                            <div class="book-overlay">
                                <a href="?book_id=${book.id}" class="btn-view">
                                    View Details
                                </a>
                            </div>
                        </div>
                        
                        <div class="book-content">
                            <h3 class="book-title">
                                <a href="?book_id=${book.id}">${escapeHtml(book.title)}</a>
                            </h3>
                            <p class="book-author">${escapeHtml(book.author)}</p>
                            
                            <div class="book-meta">
                                <span class="meta-category">${escapeHtml(book.category || 'Uncategorized')}</span>
                                <span class="meta-year">${book.year_published || 'N/A'}</span>
                            </div>
                            
                            <div class="book-footer">
                                <div class="availability">
                                    <span class="availability-count">${availableCopies}/${totalCopies}</span>
                                    <span class="availability-text">copies</span>
                                </div>
                                <a href="?book_id=${book.id}" class="btn-borrow">
                                    ${availableCopies > 0 ? 'Borrow' : 'View'}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    function renderBooksList(books) {
        const container = document.getElementById('booksContainer');
        container.className = 'books-list';
        
        if (!Array.isArray(books) || books.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>No books found</p></div>';
            return;
        }
        
        container.innerHTML = `
            <table class="books-table">
                <thead>
                    <tr>
                        <th>Book</th>
                        <th>Author</th>
                        <th>Category</th>
                        <th>Year</th>
                        <th>Availability</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    ${books.map(book => {
                        // Get accurate copy counts
                        const totalCopies = book.total_copies || book.total_copies_cache || 0;
                        const availableCopies = book.available_copies || book.available_copies_cache || 0;
                        
                        let coverImage = '../assets/default-book.jpg';
                        if (book.cover_image_cache) {
                            coverImage = '../uploads/covers/' + book.cover_image_cache;
                        } else if (book.cover_image) {
                            coverImage = '../uploads/covers/' + book.cover_image;
                        }
                        
                        return `
                            <tr>
                                <td>
                                    <div class="table-book-info">
                                        <img src="${coverImage}" 
                                             alt="${escapeHtml(book.title)}"
                                             class="table-cover"
                                             onerror="this.src='../assets/default-book.jpg'">
                                        <div>
                                            <strong>${escapeHtml(book.title)}</strong>
                                            <div class="isbn">ISBN: ${escapeHtml(book.isbn || 'N/A')}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>${escapeHtml(book.author)}</td>
                                <td>
                                    <span class="category-tag">${escapeHtml(book.category || 'Uncategorized')}</span>
                                </td>
                                <td>${book.year_published || 'N/A'}</td>
                                <td>
                                    <div class="table-availability ${availableCopies > 0 ? 'available' : 'unavailable'}">
                                        <span class="availability-dot"></span>
                                        ${availableCopies}/${totalCopies}
                                    </div>
                                </td>
                                <td>
                                    <a href="?book_id=${book.id}" class="btn-table-action">
                                        ${availableCopies > 0 ? 'Borrow' : 'View'}
                                    </a>
                                </td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        `;
    }

    function renderPagination(totalItems) {
        const container = document.getElementById('paginationContainer');
        const totalPages = Math.ceil(totalItems / booksPerPage);
        
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let paginationHTML = `
            <div class="pagination">
                <button class="pagination-btn prev ${currentPage === 1 ? 'disabled' : ''}" 
                        onclick="changePage(${currentPage - 1})" 
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
                <button class="page-number" onclick="changePage(1)">1</button>
                ${currentPage > 4 ? '<span class="page-dots">...</span>' : ''}
            `;
        }
        
        // Show pages around current page
        for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
            paginationHTML += `
                <button class="page-number ${i === currentPage ? 'active' : ''}" 
                        onclick="changePage(${i})">
                    ${i}
                </button>
            `;
        }
        
        // Show last page
        if (currentPage < totalPages - 2) {
            paginationHTML += `
                ${currentPage < totalPages - 3 ? '<span class="page-dots">...</span>' : ''}
                <button class="page-number" onclick="changePage(${totalPages})">${totalPages}</button>
            `;
        }
        
        paginationHTML += `
                </div>
                
                <button class="pagination-btn next ${currentPage === totalPages ? 'disabled' : ''}" 
                        onclick="changePage(${currentPage + 1})" 
                        ${currentPage === totalPages ? 'disabled' : ''}>
                    Next
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 18l6-6-6-6"/>
                    </svg>
                </button>
            </div>
            
            <div class="pagination-info">
                Showing ${Math.min((currentPage - 1) * booksPerPage + 1, totalItems)}-${Math.min(currentPage * booksPerPage, totalItems)} of ${totalItems} books
            </div>
        `;
        
        container.innerHTML = paginationHTML;
    }

    function changePage(page) {
        if (page < 1) return;
        currentPage = page;
        updateDisplay();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function resetFilters() {
        document.getElementById('bookSearch').value = '';
        document.getElementById('categoryFilter').value = 'all';
        document.getElementById('sortFilter').value = 'relevance';
        currentPage = 1;
        lastSearchQuery = '';
        hideSpellingCorrection();
        isMisspellingCorrected = false;
        updateDisplay();
    }

    // Event Listeners
    document.getElementById('btnBookSearch').addEventListener('click', updateDisplay);
    
    document.getElementById('bookSearch').addEventListener('input', (e) => {
        // Clear previous timeout
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        // Set new timeout for debounced search
        searchTimeout = setTimeout(() => {
            currentPage = 1;
            updateDisplay();
        }, 300);
    });
    
    document.getElementById('bookSearch').addEventListener('keyup', (e) => {
        if (e.key === 'Enter') {
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            currentPage = 1;
            updateDisplay();
        }
    });
    
    document.getElementById('categoryFilter').addEventListener('change', () => {
        currentPage = 1;
        updateDisplay();
    });
    
    document.getElementById('sortFilter').addEventListener('change', updateDisplay);

    // View toggle
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentView = this.dataset.view;
            updateDisplay();
        });
    });

    // Close AI suggestions when clicking outside
    document.addEventListener('click', (e) => {
        const suggestions = document.getElementById('aiSuggestions');
        const searchBox = document.getElementById('searchBox');
        
        if (suggestions && searchBox && 
            !suggestions.contains(e.target) && 
            !searchBox.contains(e.target)) {
            suggestions.style.display = 'none';
        }
    });

    // Load books on page load
    loadBooks();
    </script>
<?php endif; ?>
</div>

<style>
/* Add new CSS for copy status badges and map symbols */
.copy-status-badge {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.copy-status-badge.status-available {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.copy-status-badge.status-borrowed {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.copy-status-badge.status-reserved {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}

.copy-status-badge.status-lost {
    background: #e5e7eb;
    color: #374151;
    border: 1px solid #d1d5db;
}

.copy-status-badge.status-damaged {
    background: #f3f4f6;
    color: #6b7280;
    border: 1px solid #e5e7eb;
}

/* Map slot status styles */
.slot-modal.status-available {
    background: #dcfce7;
    border-color: #86efac;
    color: #166534;
    font-weight: bold;
}

.slot-modal.status-borrowed {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #991b1b;
    font-weight: bold;
}

.slot-modal.status-reserved {
    background: #fef3c7;
    border-color: #fde68a;
    color: #92400e;
    font-weight: bold;
}

.legend-color-modal.current-book.status-available {
    background: #dcfce7;
    border-color: #86efac;
}

.legend-color-modal.current-book.status-borrowed {
    background: #fee2e2;
    border-color: #fca5a5;
}

.legend-color-modal.current-book.status-reserved {
    background: #fef3c7;
    border-color: #fde68a;
}

/* AI Suggestions Styles */
.ai-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    margin-top: 8px;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    max-height: 300px;
    overflow-y: auto;
}

.ai-suggestion-item {
    padding: 12px 16px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background-color 0.2s;
}

.ai-suggestion-item:hover {
    background-color: #f9fafb;
}

.ai-suggestion-item:last-child {
    border-bottom: none;
}

.ai-suggestion-icon {
    color: #6b7280;
    flex-shrink: 0;
}

.ai-suggestion-text {
    color: #374151;
    font-size: 0.9rem;
}

/* Spelling Correction Styles */
.spelling-correction {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 0.5rem;
    margin-top: 8px;
    z-index: 999;
}

.spelling-correction-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
}

.correction-icon {
    color: #d97706;
    flex-shrink: 0;
}

.correction-text {
    flex: 1;
    font-size: 0.9rem;
    color: #92400e;
}

.original-query {
    display: block;
    font-size: 0.8rem;
    color: #b45309;
    margin-top: 4px;
    cursor: pointer;
    text-decoration: underline;
}

.original-query:hover {
    color: #92400e;
}

.correction-close {
    background: none;
    border: none;
    color: #92400e;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: background-color 0.2s;
}

.correction-close:hover {
    background: rgba(146, 64, 14, 0.1);
}

/* AI Search Indicator */
.ai-search-indicator {
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.ai-icon {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

/* Add new CSS for quick request form */
.quick-request-form {
    display: flex;
    flex-direction: column;
    gap: 15px;
    padding: 15px;
}

.quick-action-buttons {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.quick-request-form .btn-action-primary {
    width: 100%;
    justify-content: center;
}

/* Modern Design System */
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
    max-width: 1200px;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-xl);
    animation: slideIn 0.3s ease;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
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

.modal-map-container {
    display: grid;
    grid-template-columns: 300px 1fr;
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
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    font-size: 0.9rem;
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
    background: var(--gray-100);
    border-color: var(--gray-300);
    color: var(--gray-600);
}

.slot-modal.current-book {
    background: #fef3c7;
    border-color: #fbbf24;
    color: #92400e;
    font-weight: bold;
    font-size: 1.1rem;
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

.legend-color-modal.current-book {
    background: #fef3c7;
    border-color: #fbbf24;
}

.legend-color-modal.occupied {
    background: var(--gray-100);
    border-color: var(--gray-300);
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

/* Enhanced Copy Item Styles */
.copy-item {
    position: relative;
    padding: 16px;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    cursor: pointer;
    transition: var(--transition);
    margin-bottom: 12px;
}

.copy-item:hover {
    background: var(--gray-50);
    border-color: var(--primary-light);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.btn-show-on-map {
    position: absolute;
    top: 16px;
    right: 16px;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: var(--primary-light);
    color: var(--primary);
    border: none;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.btn-show-on-map:hover {
    background: var(--primary);
    color: white;
}

.btn-map-preview {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: var(--primary-light);
    color: var(--primary);
    border: none;
    border-radius: var(--radius);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.btn-map-preview:hover {
    background: var(--primary);
    color: white;
}

/* Rest of your existing CSS remains the same... */

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

/* Buttons */
.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
    margin-bottom: 30px;
}

.btn-back:hover {
    background: var(--gray-200);
    transform: translateX(-2px);
}

.btn-icon {
    display: inline-flex;
    align-items: center;
}

.btn-action-primary {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    width: 100%;
    justify-content: center;
    text-decoration: none;
}

.btn-action-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-action-secondary {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: white;
    color: var(--gray-700);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
    width: 100%;
    justify-content: center;
}

.btn-action-secondary:hover {
    background: var(--gray-50);
    border-color: var(--gray-300);
}

/* Book Detail View */
.detail-view-container {
    background: white;
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-xl);
}

.detail-content {
    padding: 0;
}

.book-detail-card {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 40px;
    padding: 40px;
}

@media (max-width: 1024px) {
    .book-detail-card {
        grid-template-columns: 1fr;
        gap: 30px;
    }
}

.book-cover-section {
    position: sticky;
    top: 20px;
    height: fit-content;
}

.book-cover-frame {
    position: relative;
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: var(--shadow-xl);
}

.book-cover-image {
    width: 100%;
    height: 450px;
    object-fit: cover;
    display: block;
}

.cover-status {
    position: absolute;
    top: 20px;
    left: 20px;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: white;
}

.cover-status.available {
    background: var(--success);
}

.cover-status.unavailable {
    background: var(--danger);
}

.quick-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* Book Info Section */
.book-info-section {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

.book-header {
    border-bottom: 1px solid var(--gray-200);
    padding-bottom: 24px;
}

.book-title {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 8px;
    line-height: 1.2;
}

.book-author {
    font-size: 1.125rem;
    color: var(--gray-600);
    margin-bottom: 20px;
}

/* Combined Section for side-by-side layout */
.combined-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-top: 20px;
}

@media (max-width: 1200px) {
    .combined-section {
        grid-template-columns: 1fr;
    }
}

/* Book Meta Grid */
.book-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    background: var(--gray-50);
    padding: 24px;
    border-radius: var(--radius);
}

.meta-item {
    display: flex;
    align-items: flex-start;
    gap: 16px;
}

.meta-icon {
    color: var(--primary);
    flex-shrink: 0;
}

.meta-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.meta-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-500);
}

.meta-value {
    font-weight: 600;
    color: var(--gray-800);
}

.category-badge {
    display: inline-block;
    padding: 4px 12px;
    background: var(--primary-light);
    color: var(--primary);
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 500;
}

/* Availability Section */
.availability-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.availability-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    border-radius: var(--radius);
    background: white;
    border: 1px solid var(--gray-200);
}

.availability-card.stock {
    border-left: 4px solid var(--success);
}

.availability-card.total {
    border-left: 4px solid var(--info);
}

.availability-card.status.in-stock {
    border-left: 4px solid var(--success);
}

.availability-card.status.out-of-stock {
    border-left: 4px solid var(--danger);
}

.availability-icon {
    color: var(--gray-400);
}

.availability-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.availability-label {
    font-size: 0.875rem;
    color: var(--gray-500);
}

.availability-count,
.availability-status {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--gray-900);
}

/* Description Section */
.description-section {
    background: var(--gray-50);
    padding: 24px;
    border-radius: var(--radius);
}

.section-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-top: 0;
    margin-bottom: 16px;
}

.section-icon {
    color: var(--primary);
}

.description-content {
    color: var(--gray-700);
    line-height: 1.8;
}

/* Location Section */
.location-section {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    overflow: hidden;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.location-section .section-header {
    padding: 20px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.location-content {
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding: 20px;
    flex: 1;
    overflow: auto;
}

.copies-section {
    display: flex;
    flex-direction: column;
    gap: 15px;
    flex: 1;
}

.subsection-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--gray-800);
    margin: 0;
}

/* Request Section */
.request-section {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    overflow: hidden;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.request-section .section-header {
    padding: 20px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.quick-request-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding: 20px;
    flex: 1;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: 15px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-label {
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

.form-actions {
    margin-top: auto;
    padding-top: 20px;
    border-top: 1px solid var(--gray-200);
}

.message-container {
    margin-top: 10px;
}

/* Copies List */
.copies-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 400px;
    overflow-y: auto;
    padding-right: 8px;
}

.copy-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.copy-id {
    font-weight: 600;
    color: var(--gray-800);
    font-size: 0.95rem;
}

.copy-badge {
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.copy-badge.condition-new {
    background: #dcfce7;
    color: #166534;
}

.copy-badge.condition-good {
    background: #dbeafe;
    color: #1e40af;
}

.copy-badge.condition-fair {
    background: #fef3c7;
    color: #92400e;
}

.copy-badge.condition-poor {
    background: #fee2e2;
    color: #991b1b;
}

.copy-info {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.location-display {
    display: flex;
    align-items: center;
    gap: 8px;
}

.location-label {
    font-size: 0.8rem;
    color: var(--gray-500);
}

.location-value {
    font-family: monospace;
    font-weight: 600;
    color: var(--gray-800);
    background: white;
    padding: 3px 6px;
    border-radius: 4px;
    border: 1px solid var(--gray-200);
    font-size: 0.85rem;
}

.copy-barcode {
    font-family: monospace;
    font-size: 0.8rem;
    color: var(--gray-500);
    background: white;
    padding: 3px 6px;
    border-radius: 4px;
    border: 1px solid var(--gray-200);
}

/* Catalogue View */
.catalogue-container {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

.catalogue-controls.card {
    background: white;
    border-radius: var(--radius-lg);
    padding: 30px;
    box-shadow: var(--shadow-lg);
}

.search-section {
    margin-bottom: 24px;
    position: relative;
}

.search-box {
    display: flex;
    align-items: center;
    background: white;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius);
    overflow: hidden;
    transition: var(--transition);
}

.search-box:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.search-icon {
    padding: 0 16px;
    color: var(--gray-400);
}

.search-input {
    flex: 1;
    padding: 16px 0;
    border: none;
    font-size: 1rem;
    color: var(--gray-800);
    background: transparent;
}

.search-input:focus {
    outline: none;
}

.btn-search {
    padding: 16px 32px;
    background: var(--primary);
    color: white;
    border: none;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
}

.btn-search:hover {
    background: var(--primary-dark);
}

.filters-section {
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.filter-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--gray-700);
}

.view-toggle {
    display: flex;
    gap: 4px;
    background: var(--gray-100);
    padding: 4px;
    border-radius: var(--radius);
}

.view-btn {
    padding: 8px 12px;
    background: transparent;
    border: none;
    border-radius: calc(var(--radius) - 2px);
    cursor: pointer;
    color: var(--gray-500);
    transition: var(--transition);
}

.view-btn.active {
    background: white;
    color: var(--primary);
    box-shadow: var(--shadow-sm);
}

.view-btn:hover:not(.active) {
    color: var(--gray-700);
}

/* Stats Bar */
.stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.stat-item {
    background: white;
    padding: 20px;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.stat-label {
    font-size: 0.875rem;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--gray-900);
}

/* Books Grid */
.books-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 30px;
}

.book-card {
    background: white;
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: var(--transition);
}

.book-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

.book-card-inner {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.book-cover {
    position: relative;
    height: 250px;
    overflow: hidden;
}

.book-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}

.book-card:hover .book-image {
    transform: scale(1.05);
}

.book-status {
    position: absolute;
    top: 16px;
    left: 16px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: white;
}

.book-status.available {
    background: var(--success);
}

.book-status.unavailable {
    background: var(--danger);
}

.book-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: var(--transition);
}

.book-card:hover .book-overlay {
    opacity: 1;
}

.btn-view {
    padding: 10px 20px;
    background: white;
    color: var(--gray-800);
    border-radius: var(--radius);
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
}

.btn-view:hover {
    background: var(--primary);
    color: white;
}

.book-content {
    padding: 24px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.book-title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-900);
}

.book-title a {
    color: inherit;
    text-decoration: none;
    transition: var(--transition);
}

.book-title a:hover {
    color: var(--primary);
}

.book-author {
    margin: 0;
    color: var(--gray-600);
    font-size: 0.95rem;
}

.book-meta {
    display: flex;
    gap: 12px;
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid var(--gray-100);
}

.meta-category,
.meta-year {
    font-size: 0.875rem;
    color: var(--gray-500);
}

.book-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid var(--gray-100);
}

.availability {
    display: flex;
    align-items: baseline;
    gap: 4px;
}

.availability-count {
    font-weight: 700;
    color: var(--gray-900);
}

.availability-text {
    font-size: 0.875rem;
    color: var(--gray-500);
}

.btn-borrow {
    padding: 8px 20px;
    background: var(--primary);
    color: white;
    border-radius: var(--radius);
    text-decoration: none;
    font-weight: 500;
    font-size: 0.875rem;
    transition: var(--transition);
}

.btn-borrow:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
}

/* Books List View */
.books-list {
    background: white;
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow);
}

.books-table {
    width: 100%;
    border-collapse: collapse;
}

.books-table thead {
    background: var(--gray-50);
    border-bottom: 2px solid var(--gray-200);
}

.books-table th {
    padding: 16px 24px;
    text-align: left;
    font-weight: 600;
    color: var(--gray-700);
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
}

.books-table tbody tr {
    border-bottom: 1px solid var(--gray-100);
    transition: var(--transition);
}

.books-table tbody tr:hover {
    background: var(--gray-50);
}

.books-table td {
    padding: 20px 24px;
    vertical-align: middle;
}

.table-book-info {
    display: flex;
    align-items: center;
    gap: 16px;
}

.table-cover {
    width: 60px;
    height: 80px;
    object-fit: cover;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow);
}

.isbn {
    font-size: 0.875rem;
    color: var(--gray-500);
    margin-top: 4px;
}

.category-tag {
    display: inline-block;
    padding: 4px 12px;
    background: var(--gray-100);
    color: var(--gray-700);
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 500;
}

.table-availability {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}

.table-availability.available .availability-dot {
    background: var(--success);
}

.table-availability.unavailable .availability-dot {
    background: var(--danger);
}

.availability-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.btn-table-action {
    padding: 6px 16px;
    background: var(--primary);
    color: white;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: var(--transition);
}

.btn-table-action:hover {
    background: var(--primary-dark);
}

/* Pagination */
.pagination-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    margin-top: 40px;
}

.pagination {
    display: flex;
    align-items: center;
    gap: 16px;
}

.pagination-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: white;
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    font-weight: 500;
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
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius);
    border: 1px solid var(--gray-300);
    background: white;
    color: var(--gray-700);
    cursor: pointer;
    font-weight: 500;
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
    padding: 0 10px;
    color: var(--gray-400);
}

.pagination-info {
    font-size: 0.875rem;
    color: var(--gray-600);
}

/* Loading and Empty States */
.loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 80px 40px;
    text-align: center;
}

.spinner {
    width: 50px;
    height: 50px;
    border: 3px solid var(--gray-200);
    border-top: 3px solid var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 20px;
}

.spinner-large {
    width: 70px;
    height: 70px;
    border: 4px solid var(--gray-200);
    border-top: 4px solid var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 24px;
}

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 80px 40px;
    text-align: center;
}

.empty-icon {
    color: var(--gray-300);
    margin-bottom: 24px;
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

.empty-state.warning {
    background: #fef3c7;
    border-radius: var(--radius);
    padding: 20px;
}

.btn-retry,
.btn-reset {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius);
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
}

.btn-retry:hover,
.btn-reset:hover {
    background: var(--primary-dark);
}

/* Alerts */
.alert {
    padding: 16px 20px;
    border-radius: var(--radius);
    margin: 0;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.alert-icon {
    flex-shrink: 0;
}

.alert-success {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.alert-warning {
    background: #fef3c7;
    border: 1px solid #fde68a;
    color: #92400e;
}

.reference {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid rgba(22, 101, 52, 0.2);
}

/* Animations */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
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

/* Responsive Design */
@media (max-width: 1024px) {
    .page-title {
        font-size: 2rem;
    }
    
    .book-detail-card {
        padding: 30px;
    }
    
    .book-title {
        font-size: 2rem;
    }
    
    .modal-content {
        width: 95%;
        margin: 2% auto;
    }
}

@media (max-width: 768px) {
    .page-container {
        padding: 16px;
    }
    
    .book-detail-card {
        padding: 24px;
        gap: 24px;
    }
    
    .combined-section {
        gap: 20px;
    }
    
    .catalogue-controls.card {
        padding: 24px;
    }
    
    .filters-section {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        min-width: 100%;
    }
    
    .view-toggle {
        align-self: flex-start;
    }
    
    .books-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
    }
    
    .stats-bar {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
    
    .stat-value {
        font-size: 1.75rem;
    }
    
    .modal-map-container {
        grid-template-columns: 1fr;
    }
    
    .library-sections-modal {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .page-title {
        font-size: 1.75rem;
    }
    
    .book-detail-card {
        padding: 20px;
    }
    
    .book-title {
        font-size: 1.5rem;
    }
    
    .book-meta-grid {
        grid-template-columns: 1fr;
    }
    
    .availability-section {
        grid-template-columns: 1fr;
    }
    
    .combined-section {
        grid-template-columns: 1fr;
    }
    
    .books-grid {
        grid-template-columns: 1fr;
    }
    
    .pagination {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .pagination-pages {
        order: 3;
        width: 100%;
        justify-content: center;
        margin-top: 10px;
    }
}
</style>

<?php include __DIR__ . '/_footer.php'; ?>