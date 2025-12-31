<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$pdo = DB::conn();

// Get library sections
$stmt = $pdo->query("
    SELECT lmc.*, 
           COUNT(DISTINCT bc.book_id) as book_count
    FROM library_map_config lmc
    LEFT JOIN book_copies bc ON lmc.section = bc.current_section AND bc.is_active = 1
    WHERE lmc.is_active = 1
    GROUP BY lmc.id, lmc.section
    ORDER BY lmc.section
");
$sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get books needing locations
$stmt = $pdo->query("
    SELECT 
        b.id as book_id,
        b.title,
        b.author,
        c.name as category_name,
        c.default_section,
        c.shelf_recommendation,
        c.row_recommendation,
        c.slot_recommendation,
        COUNT(bc.id) as total_copies,
        SUM(CASE WHEN bc.current_section IS NULL THEN 1 ELSE 0 END) as needs_location
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.id
    LEFT JOIN book_copies bc ON b.id = bc.book_id AND bc.is_active = 1
    WHERE b.is_active = 1
    GROUP BY b.id, b.title, b.author, c.name, c.default_section, 
             c.shelf_recommendation, c.row_recommendation, c.slot_recommendation
    HAVING needs_location > 0 OR total_copies = 0
    ORDER BY c.default_section, c.shelf_recommendation
");
$recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get occupancy data for map
$occupancy = [];
foreach ($sections as $section) {
    $stmt = $pdo->prepare("
        SELECT 
            current_shelf as shelf,
            current_row as row,
            current_slot as slot,
            COUNT(*) as count
        FROM book_copies 
        WHERE current_section = ? AND is_active = 1
        GROUP BY current_shelf, current_row, current_slot
    ");
    $stmt->execute([$section['section']]);
    $section_occupancy = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $occupancy[$section['section']] = [];
    foreach ($section_occupancy as $occ) {
        $key = "{$occ['shelf']}-{$occ['row']}-{$occ['slot']}";
        $occupancy[$section['section']][$key] = true;
    }
}

include __DIR__ . '/_header.php';
?>

<div class="page-header">
    <div class="header-content">
        <div class="header-icon">
            <i class="fas fa-map-marked-alt"></i>
        </div>
        <div class="header-text">
            <h1>Library Map & AI Recommendations</h1>
            <p class="subtitle">Visualize book locations and apply intelligent placement suggestions</p>
        </div>
        <div class="header-stats">
            <div class="stat-card">
                <span class="stat-number"><?= count($sections) ?></span>
                <span class="stat-label">Sections</span>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?= count($recommendations) ?></span>
                <span class="stat-label">Pending</span>
            </div>
        </div>
    </div>
</div>

<div class="grid-container">
    <!-- Left Column: Library Map -->
    <div class="card elevation-1">
        <div class="card-header">
            <div class="flex-space-between">
                <div class="title-with-icon">
                    <i class="fas fa-sitemap icon-primary"></i>
                    <h3>Library Floor Plan</h3>
                </div>
                <div class="map-controls">
                    <div class="control-group">
                        <label for="mapScale"><i class="fas fa-search"></i> Zoom</label>
                        <select id="mapScale" class="form-control-sm select-minimal">
                            <option value="1">100%</option>
                            <option value="1.2">120%</option>
                            <option value="1.5">150%</option>
                            <option value="2">200%</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <button class="btn btn-sm btn-soft" id="centerMap">
                            <i class="fas fa-crosshairs"></i> Center View
                        </button>
                        <button class="btn btn-sm btn-soft" id="toggleLegend">
                            <i class="fas fa-layer-group"></i> Legend
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="map-container">
                <div class="map-grid" id="libraryMap">
                    <?php foreach ($sections as $section): ?>
                        <div class="section-container" data-section="<?= $section['section'] ?>">
                            <div class="section-header" style="background: <?= $section['color'] ?>">
                                <div class="section-title">
                                    <h4>Section <?= $section['section'] ?></h4>
                                    <span class="section-subtitle"><?= $section['shelf_count'] ?> shelves</span>
                                </div>
                                <div class="section-stats">
                                    <span class="book-count">
                                        <i class="fas fa-book"></i>
                                        <?= $section['book_count'] ?>
                                    </span>
                                </div>
                            </div>
                            <div class="section-body">
                                <?php for ($shelf = 1; $shelf <= $section['shelf_count']; $shelf++): ?>
                                    <div class="shelf-container">
                                        <div class="shelf-header">
                                            <span class="shelf-label">Shelf <?= $shelf ?></span>
                                            <span class="shelf-dimensions">6 rows × 12 slots</span>
                                        </div>
                                        <div class="shelf-grid">
                                            <?php for ($row = 1; $row <= $section['rows_per_shelf']; $row++): ?>
                                                <div class="row-container">
                                                    <div class="row-label">R<?= $row ?></div>
                                                    <div class="slots-grid">
                                                        <?php for ($slot = 1; $slot <= $section['slots_per_row']; $slot++): ?>
                                                            <?php 
                                                            $occupied = isset($occupancy[$section['section']]["$shelf-$row-$slot"]);
                                                            $slot_class = $occupied ? 'occupied' : 'available';
                                                            $slot_id = "{$section['section']}-S{$shelf}-R{$row}-P{$slot}";
                                                            ?>
                                                            <div class="slot <?= $slot_class ?>"
                                                                 data-section="<?= $section['section'] ?>"
                                                                 data-shelf="<?= $shelf ?>"
                                                                 data-row="<?= $row ?>"
                                                                 data-slot="<?= $slot ?>"
                                                                 title="<?= $slot_id ?>">
                                                                <span class="slot-number"><?= $slot ?></span>
                                                                <?php if ($occupied): ?>
                                                                    <div class="slot-indicator">
                                                                        <i class="fas fa-book"></i>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <div class="slot-indicator empty">
                                                                        <i class="fas fa-square"></i>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="map-legend" id="mapLegend">
                <div class="legend-header">
                    <h5><i class="fas fa-key"></i> Map Legend</h5>
                    <button class="btn-close-legend" onclick="toggleLegend()">&times;</button>
                </div>
                <div class="legend-content">
                    <div class="legend-item">
                        <div class="legend-symbol available"></div>
                        <div class="legend-text">
                            <span class="legend-title">Available Slot</span>
                            <span class="legend-desc">Ready for book placement</span>
                        </div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-symbol occupied"></div>
                        <div class="legend-text">
                            <span class="legend-title">Occupied Slot</span>
                            <span class="legend-desc">Contains a book</span>
                        </div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-symbol recommended"></div>
                        <div class="legend-text">
                            <span class="legend-title">AI Recommended</span>
                            <span class="legend-desc">Suggested placement</span>
                        </div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-symbol section-a"></div>
                        <div class="legend-text">
                            <span class="legend-title">Section A</span>
                            <span class="legend-desc">Information Technology</span>
                        </div>
                    </div>
                    <div class="legend-item">
                        <div class="legend-symbol section-b"></div>
                        <div class="legend-text">
                            <span class="legend-title">Section B</span>
                            <span class="legend-desc">Psychology</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right Column: AI Recommendations -->
    <div class="card elevation-1">
        <div class="card-header">
            <div class="flex-space-between">
                <div class="title-with-icon">
                    <i class="fas fa-robot icon-ai"></i>
                    <div>
                        <h3>AI Placement Suggestions</h3>
                        <p class="subtitle-sm">Books needing physical location</p>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary btn-sm btn-icon" id="applyAllAI">
                        <i class="fas fa-magic"></i>
                        <span>Apply All</span>
                    </button>
                    <button class="btn btn-soft btn-sm btn-icon" id="refreshAI">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($recommendations)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-check-circle text-success"></i>
                    </div>
                    <h4>Perfect Organization!</h4>
                    <p>All books are properly placed in their designated locations.</p>
                    <div class="empty-actions">
                        <button class="btn btn-outline" onclick="scanForIssues()">
                            <i class="fas fa-search"></i> Scan for Issues
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="recommendations-header">
                    <div class="filter-controls">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchRecs" placeholder="Search recommendations...">
                        </div>
                        <select id="filterSection" class="form-control-sm">
                            <option value="">All Sections</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= $section['section'] ?>">Section <?= $section['section'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="recommendations-list" id="recommendationsList">
                    <?php foreach ($recommendations as $rec): ?>
                        <div class="recommendation-item" data-book-id="<?= $rec['book_id'] ?>" data-section="<?= $rec['default_section'] ?>">
                            <div class="recommendation-content">
                                <div class="recommendation-header">
                                    <div class="book-info">
                                        <h5 class="book-title"><?= htmlspecialchars($rec['title']) ?></h5>
                                        <p class="book-author"><?= htmlspecialchars($rec['author']) ?></p>
                                        <div class="book-meta">
                                            <span class="meta-item">
                                                <i class="fas fa-copy"></i>
                                                <?= $rec['total_copies'] ?> copies
                                            </span>
                                            <?php if ($rec['needs_location'] > 0): ?>
                                                <span class="meta-item warning">
                                                    <i class="fas fa-exclamation-circle"></i>
                                                    <?= $rec['needs_location'] ?> need placement
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="category-tag" style="background: <?= getCategoryColor($rec['category_name']) ?>">
                                        <?= htmlspecialchars($rec['category_name']) ?>
                                    </div>
                                </div>
                                
                                <div class="recommendation-body">
                                    <div class="ai-suggestion">
                                        <div class="suggestion-header">
                                            <i class="fas fa-lightbulb"></i>
                                            <span>AI Placement Suggestion</span>
                                        </div>
                                        <div class="location-card">
                                            <div class="location-icon">
                                                <i class="fas fa-map-pin"></i>
                                            </div>
                                            <div class="location-details">
                                                <div class="location-code">
                                                    <?= $rec['default_section'] ?>-
                                                    S<?= str_pad($rec['shelf_recommendation'], 2, '0', STR_PAD_LEFT) ?>-
                                                    R<?= str_pad($rec['row_recommendation'], 2, '0', STR_PAD_LEFT) ?>-
                                                    P<?= str_pad($rec['slot_recommendation'], 2, '0', STR_PAD_LEFT) ?>
                                                </div>
                                                <div class="location-path">
                                                    Section <?= $rec['default_section'] ?> → Shelf <?= $rec['shelf_recommendation'] ?> → Row <?= $rec['row_recommendation'] ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="recommendation-actions">
                                    <div class="action-group">
                                        <button class="btn btn-sm btn-ghost view-book-btn" 
                                                data-book-id="<?= $rec['book_id'] ?>"
                                                title="View book details">
                                            <i class="fas fa-eye"></i>
                                            <span>View</span>
                                        </button>
                                        <button class="btn btn-sm btn-ghost locate-btn"
                                                data-book-id="<?= $rec['book_id'] ?>"
                                                title="Show on map">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span>Locate</span>
                                        </button>
                                    </div>
                                    <div class="action-group">
                                        <button class="btn btn-sm btn-outline find-alt-btn"
                                                data-book-id="<?= $rec['book_id'] ?>"
                                                title="Find alternative location">
                                            <i class="fas fa-search"></i>
                                            <span>Alternative</span>
                                        </button>
                                        <button class="btn btn-sm btn-primary apply-ai-btn"
                                                data-book-id="<?= $rec['book_id'] ?>"
                                                data-section="<?= $rec['default_section'] ?>"
                                                data-shelf="<?= $rec['shelf_recommendation'] ?>"
                                                data-row="<?= $rec['row_recommendation'] ?>"
                                                data-slot="<?= $rec['slot_recommendation'] ?>"
                                                title="Apply this recommendation">
                                            <i class="fas fa-check"></i>
                                            <span>Apply</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="recommendations-footer">
                    <div class="summary">
                        Showing <strong><?= count($recommendations) ?></strong> books needing placement
                    </div>
                    <div class="bulk-actions">
                        <button class="btn btn-sm btn-soft" onclick="selectAllRecs()">
                            <i class="fas fa-check-square"></i> Select All
                        </button>
                        <button class="btn btn-sm btn-soft" onclick="clearSelection()">
                            <i class="fas fa-square"></i> Clear
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Slot Details Modal -->
<div class="modal" id="slotModal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-map-marker-alt"></i>
                <h3 id="slotModalTitle">Location Details</h3>
            </div>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="slotModalContent">
            <!-- Content loaded dynamically -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-soft" onclick="closeModal()">Close</button>
            <button class="btn btn-primary" id="assignBookBtn" style="display:none;">
                <i class="fas fa-plus"></i> Assign Book
            </button>
        </div>
    </div>
</div>

<?php
function getCategoryColor($category) {
    $colors = [
        'Information Technology' => '#3B82F6',
        'Psychology' => '#10B981',
        'Science' => '#EF4444',
        'Arts' => '#8B5CF6',
        'Business' => '#F59E0B',
        'Engineering' => '#EC4899'
    ];
    return $colors[$category] ?? '#6B7280';
}
?>

<style>
/* Main Layout */
:root {
    --primary: #4361ee;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --info: #3b82f6;
    --light: #f8fafc;
    --dark: #1e293b;
    --border: #e2e8f0;
    --shadow: 0 1px 3px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
    --radius: 12px;
    --radius-sm: 8px;
}

.grid-container {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

@media (max-width: 1400px) {
    .grid-container {
        grid-template-columns: 1.5fr 1fr;
    }
}

@media (max-width: 1200px) {
    .grid-container {
        grid-template-columns: 1fr;
    }
}

/* Header Enhancement */
.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: var(--radius);
    margin-bottom: 2rem;
    box-shadow: var(--shadow-lg);
}

.header-content {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.header-icon {
    background: rgba(255,255,255,0.2);
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.header-text h1 {
    margin: 0 0 0.5rem 0;
    font-size: 1.75rem;
    font-weight: 700;
}

.subtitle {
    opacity: 0.9;
    margin: 0;
}

.header-stats {
    display: flex;
    gap: 1rem;
    margin-left: auto;
}

.stat-card {
    background: rgba(255,255,255,0.15);
    padding: 0.75rem 1.5rem;
    border-radius: var(--radius-sm);
    text-align: center;
    backdrop-filter: blur(10px);
}

.stat-number {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1;
}

.stat-label {
    font-size: 0.875rem;
    opacity: 0.9;
}

/* Card Styling */
.card {
    background: white;
    border-radius: var(--radius);
    overflow: hidden;
    transition: transform 0.3s, box-shadow 0.3s;
}

.card:hover {
    transform: translateY(-2px);
}

.elevation-1 {
    box-shadow: var(--shadow);
}

.elevation-1:hover {
    box-shadow: var(--shadow-lg);
}

.card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    background: var(--light);
}

.card-body {
    padding: 1.5rem;
}

.title-with-icon {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.title-with-icon i {
    font-size: 1.25rem;
}

.icon-primary {
    color: var(--primary);
}

.icon-ai {
    color: var(--warning);
}

.subtitle-sm {
    font-size: 0.875rem;
    color: #6b7280;
    margin: 0.25rem 0 0 0;
}

/* Map Container */
.map-container {
    position: relative;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: auto;
    max-height: 650px;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1rem;
}

.map-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
    min-width: max-content;
}

/* Section Styling */
.section-container {
    background: white;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow);
    overflow: hidden;
    border: 1px solid var(--border);
    transition: all 0.3s;
}

.section-container:hover {
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
}

.section-header {
    padding: 1rem;
    color: white;
    position: relative;
    overflow: hidden;
}

.section-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
}

.section-title {
    position: relative;
    z-index: 1;
}

.section-title h4 {
    margin: 0 0 0.25rem 0;
    font-size: 1.1rem;
    font-weight: 600;
}

.section-subtitle {
    font-size: 0.75rem;
    opacity: 0.9;
}

.section-stats {
    position: absolute;
    top: 1rem;
    right: 1rem;
    z-index: 1;
}

.book-count {
    background: rgba(0,0,0,0.2);
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

/* Shelf Styling */
.shelf-container {
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: #f8fafc;
    border-radius: var(--radius-sm);
}

.shelf-container:last-child {
    margin-bottom: 0;
}

.shelf-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.shelf-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: #4b5563;
}

.shelf-dimensions {
    font-size: 0.75rem;
    color: #9ca3af;
    background: #e5e7eb;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
}

.shelf-grid {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.row-container {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem;
    background: white;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}

.row-label {
    font-size: 0.7rem;
    font-weight: 600;
    color: #6b7280;
    width: 32px;
    text-align: center;
    background: #f3f4f6;
    padding: 0.25rem;
    border-radius: 4px;
}

.slots-grid {
    display: flex;
    gap: 0.375rem;
    flex-wrap: wrap;
    flex: 1;
}

/* Slot Styling */
.slot {
    width: 36px;
    height: 36px;
    border: 2px solid transparent;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    background: white;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

.slot:hover {
    transform: scale(1.15);
    z-index: 10;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.slot .slot-number {
    position: absolute;
    top: 2px;
    left: 3px;
    font-size: 0.6rem;
    font-weight: 600;
    color: #6b7280;
}

.slot-indicator {
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}

.slot-indicator i {
    font-size: 0.75rem;
}

/* Slot States */
.slot.available {
    border-color: var(--success);
}

.slot.available .slot-indicator {
    background: var(--success);
    color: white;
}

.slot.available .slot-indicator.empty {
    background: #f0fdf4;
    color: var(--success);
}

.slot.occupied {
    border-color: var(--danger);
}

.slot.occupied .slot-indicator {
    background: var(--danger);
    color: white;
}

.slot.recommended {
    border-color: var(--warning);
    animation: pulse 2s infinite;
    box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.3);
}

.slot.recommended .slot-indicator {
    background: var(--warning);
    color: white;
}

/* Map Legend */
.map-legend {
    position: absolute;
    bottom: 1rem;
    right: 1rem;
    background: white;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-lg);
    width: 280px;
    z-index: 100;
    border: 1px solid var(--border);
    display: none;
}

.map-legend.show {
    display: block;
    animation: slideIn 0.3s ease-out;
}

.legend-header {
    padding: 1rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--light);
}

.legend-header h5 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.btn-close-legend {
    background: none;
    border: none;
    font-size: 1.25rem;
    color: #6b7280;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}

.btn-close-legend:hover {
    background: #f3f4f6;
    color: #374151;
}

.legend-content {
    padding: 1rem;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f3f4f6;
}

.legend-item:last-child {
    border-bottom: none;
}

.legend-symbol {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    flex-shrink: 0;
}

.legend-symbol.available { background: var(--success); }
.legend-symbol.occupied { background: var(--danger); }
.legend-symbol.recommended { background: var(--warning); }
.legend-symbol.section-a { background: #3B82F6; }
.legend-symbol.section-b { background: #10B981; }

.legend-text {
    flex: 1;
}

.legend-title {
    display: block;
    font-weight: 600;
    font-size: 0.875rem;
    color: #1f2937;
}

.legend-desc {
    display: block;
    font-size: 0.75rem;
    color: #6b7280;
    margin-top: 0.125rem;
}

/* Map Controls */
.map-controls {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.control-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.control-group label {
    font-size: 0.75rem;
    color: #6b7280;
    white-space: nowrap;
}

.select-minimal {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 0.375rem 2rem 0.375rem 0.75rem;
    font-size: 0.875rem;
    background: white url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e") no-repeat right 0.5rem center/1.5em 1.5em;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
}

.btn-soft {
    background: white;
    border: 1px solid #d1d5db;
    color: #374151;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.btn-soft:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

/* Recommendations Styling */
.recommendations-header {
    margin-bottom: 1rem;
}

.filter-controls {
    display: flex;
    gap: 0.75rem;
    align-items: center;
}

.search-box {
    flex: 1;
    position: relative;
}

.search-box i {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 0.875rem;
}

.search-box input {
    width: 100%;
    padding: 0.5rem 0.75rem 0.5rem 2.25rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.search-box input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Recommendation Items */
.recommendations-list {
    max-height: 650px;
    overflow-y: auto;
    padding-right: 0.5rem;
}

.recommendation-item {
    background: white;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    margin-bottom: 0.75rem;
    overflow: hidden;
    transition: all 0.3s;
    position: relative;
}

.recommendation-item:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
}

.recommendation-item.selected {
    border-color: var(--primary);
    background: #f0f7ff;
}

.recommendation-content {
    padding: 1.25rem;
}

.recommendation-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.book-info {
    flex: 1;
}

.book-title {
    margin: 0 0 0.25rem 0;
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
    line-height: 1.3;
}

.book-author {
    margin: 0 0 0.75rem 0;
    font-size: 0.875rem;
    color: #6b7280;
}

.book-meta {
    display: flex;
    gap: 1rem;
    font-size: 0.75rem;
}

.meta-item {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    color: #6b7280;
}

.meta-item.warning {
    color: var(--warning);
}

.category-tag {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    color: white;
    white-space: nowrap;
}

/* AI Suggestion Styling */
.ai-suggestion {
    background: linear-gradient(135deg, #fef3c7 0%, #fef9c3 100%);
    border-radius: var(--radius-sm);
    padding: 1rem;
    margin-bottom: 1rem;
    border: 1px solid #fde68a;
}

.suggestion-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: #92400e;
}

.suggestion-header i {
    color: var(--warning);
}

.location-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem;
    background: white;
    border-radius: 8px;
    border: 1px solid #f3f4f6;
}

.location-icon {
    width: 40px;
    height: 40px;
    background: var(--primary);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1rem;
}

.location-details {
    flex: 1;
}

.location-code {
    font-family: 'SF Mono', 'Cascadia Code', 'Roboto Mono', monospace;
    font-weight: 600;
    color: #1f2937;
    font-size: 0.95rem;
    margin-bottom: 0.125rem;
}

.location-path {
    font-size: 0.75rem;
    color: #6b7280;
}

/* Recommendation Actions */
.recommendation-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #f3f4f6;
}

.action-group {
    display: flex;
    gap: 0.5rem;
}

.btn-ghost {
    background: transparent;
    border: 1px solid transparent;
    color: #6b7280;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.btn-ghost:hover {
    background: #f3f4f6;
    color: #374151;
}

.btn-outline {
    background: transparent;
    border: 1px solid #d1d5db;
    color: #374151;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.btn-outline:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.btn-primary {
    background: var(--primary);
    border: 1px solid var(--primary);
    color: white;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.btn-primary:hover {
    background: #3a56d4;
    border-color: #3a56d4;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.btn-icon {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}

.empty-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state h4 {
    color: #374151;
    margin-bottom: 0.5rem;
    font-size: 1.25rem;
}

.empty-state p {
    color: #6b7280;
    max-width: 24rem;
    margin: 0 auto 1.5rem;
}

.empty-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
}

/* Modal Styling */
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
    z-index: 9999;
    padding: 20px;
    animation: fadeIn 0.3s ease-out;
}

.modal-content {
    background: white;
    border-radius: var(--radius);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: var(--shadow-lg);
    animation: slideUp 0.3s ease-out;
}

.modal-lg {
    max-width: 800px;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--light);
}

.modal-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.modal-title h3 {
    margin: 0;
    font-size: 1.25rem;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #6b7280;
    cursor: pointer;
    line-height: 1;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
}

.modal-close:hover {
    background: #f3f4f6;
    color: #374151;
}

.modal-body {
    padding: 1.5rem;
    max-height: 60vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

/* Animations */
@keyframes pulse {
    0% { 
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7);
    }
    70% { 
        box-shadow: 0 0 0 6px rgba(245, 158, 11, 0);
    }
    100% { 
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0);
    }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

/* Footer */
.recommendations-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border);
    font-size: 0.875rem;
    color: #6b7280;
}

.bulk-actions {
    display: flex;
    gap: 0.5rem;
}

.header-actions {
    display: flex;
    gap: 0.5rem;
}

/* Scrollbar Styling */
.recommendations-list::-webkit-scrollbar,
.modal-body::-webkit-scrollbar,
.map-container::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.recommendations-list::-webkit-scrollbar-track,
.modal-body::-webkit-scrollbar-track,
.map-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.recommendations-list::-webkit-scrollbar-thumb,
.modal-body::-webkit-scrollbar-thumb,
.map-container::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}

.recommendations-list::-webkit-scrollbar-thumb:hover,
.modal-body::-webkit-scrollbar-thumb:hover,
.map-container::-webkit-scrollbar-thumb:hover {
    background: #a1a1a1;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize map controls
    initMapControls();
    
    // Initialize recommendations
    initRecommendations();
    
    // Initialize event listeners
    initEventListeners();
});

function initMapControls() {
    // Slot click handler
    document.querySelectorAll('.slot').forEach(slot => {
        slot.addEventListener('click', function() {
            const section = this.getAttribute('data-section');
            const shelf = this.getAttribute('data-shelf');
            const row = this.getAttribute('data-row');
            const slotNum = this.getAttribute('data-slot');
            showSlotDetails(section, shelf, row, slotNum);
        });
    });
    
    // Map scale
    document.getElementById('mapScale').addEventListener('change', function() {
        const scale = this.value;
        const map = document.getElementById('libraryMap');
        map.style.transform = `scale(${scale})`;
        map.style.transformOrigin = 'top left';
        map.style.transition = 'transform 0.3s ease';
    });
    
    // Center map
    document.getElementById('centerMap').addEventListener('click', function() {
        const map = document.getElementById('libraryMap');
        const mapContainer = map.parentElement;
        mapContainer.scrollTo({
            left: (map.scrollWidth - mapContainer.clientWidth) / 2,
            top: (map.scrollHeight - mapContainer.clientHeight) / 2,
            behavior: 'smooth'
        });
    });
    
    // Toggle legend
    document.getElementById('toggleLegend').addEventListener('click', function() {
        const legend = document.getElementById('mapLegend');
        legend.classList.toggle('show');
    });
}

function initRecommendations() {
    // Search functionality
    const searchInput = document.getElementById('searchRecs');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const filterSection = document.getElementById('filterSection').value;
            
            filterRecommendations(searchTerm, filterSection);
        });
    }
    
    // Section filter
    const sectionFilter = document.getElementById('filterSection');
    if (sectionFilter) {
        sectionFilter.addEventListener('change', function() {
            const searchTerm = document.getElementById('searchRecs').value.toLowerCase();
            filterRecommendations(searchTerm, this.value);
        });
    }
    
    // Apply AI recommendation
    document.querySelectorAll('.apply-ai-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const bookId = this.getAttribute('data-book-id');
            const section = this.getAttribute('data-section');
            const shelf = this.getAttribute('data-shelf');
            const row = this.getAttribute('data-row');
            const slot = this.getAttribute('data-slot');
            
            await applyBookRecommendation(bookId, section, shelf, row, slot);
        });
    });
    
    // Find alternative
    document.querySelectorAll('.find-alt-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const bookId = this.getAttribute('data-book-id');
            await findAlternativeLocation(bookId);
        });
    });
    
    // View book
    document.querySelectorAll('.view-book-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const bookId = this.getAttribute('data-book-id');
            window.open(`manage_books.php?view=${bookId}`, '_blank');
        });
    });
    
    // Locate on map
    document.querySelectorAll('.locate-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const bookId = this.getAttribute('data-book-id');
            locateBookOnMap(bookId);
        });
    });
}

function initEventListeners() {
    // Apply all AI recommendations
    document.getElementById('applyAllAI').addEventListener('click', async function() {
        if (!confirm('Apply all AI recommendations? This will assign locations to all books without physical locations.')) {
            return;
        }
        
        const originalText = this.innerHTML;
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        
        try {
            const recommendations = document.querySelectorAll('.recommendation-item');
            let processed = 0;
            let errors = 0;
            
            for (const rec of recommendations) {
                const bookId = rec.getAttribute('data-book-id');
                const applyBtn = rec.querySelector('.apply-ai-btn');
                
                if (applyBtn) {
                    const section = applyBtn.getAttribute('data-section');
                    const shelf = applyBtn.getAttribute('data-shelf');
                    const row = applyBtn.getAttribute('data-row');
                    const slot = applyBtn.getAttribute('data-slot');
                    
                    try {
                        await applyBookRecommendation(bookId, section, shelf, row, slot, false);
                        processed++;
                        
                        // Add success animation
                        rec.style.backgroundColor = '#f0fdf4';
                        setTimeout(() => {
                            rec.style.backgroundColor = '';
                        }, 500);
                        
                    } catch (error) {
                        errors++;
                        console.error(`Error processing book ${bookId}:`, error);
                    }
                }
            }
            
            showNotification(`Processed ${processed} books${errors > 0 ? ` with ${errors} errors` : ''}.`, errors > 0 ? 'warning' : 'success');
            if (processed > 0) {
                setTimeout(() => location.reload(), 1500);
            }
            
        } catch (error) {
            showNotification('Error: ' + error.message, 'error');
        } finally {
            this.disabled = false;
            this.innerHTML = originalText;
        }
    });
    
    // Refresh recommendations
    document.getElementById('refreshAI').addEventListener('click', function() {
        this.classList.add('fa-spin');
        setTimeout(() => {
            location.reload();
        }, 500);
    });
    
    // Modal close handlers
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.modal').style.display = 'none';
        });
    });
    
    // Close modal on outside click
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    });
}

function filterRecommendations(searchTerm, sectionFilter) {
    const items = document.querySelectorAll('.recommendation-item');
    let visibleCount = 0;
    
    items.forEach(item => {
        const title = item.querySelector('.book-title').textContent.toLowerCase();
        const author = item.querySelector('.book-author').textContent.toLowerCase();
        const section = item.getAttribute('data-section');
        
        const matchesSearch = !searchTerm || 
            title.includes(searchTerm) || 
            author.includes(searchTerm);
        
        const matchesSection = !sectionFilter || section === sectionFilter;
        
        if (matchesSearch && matchesSection) {
            item.style.display = 'block';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Update summary
    const summary = document.querySelector('.summary');
    if (summary) {
        summary.innerHTML = `Showing <strong>${visibleCount}</strong> of ${items.length} books`;
    }
}

function selectAllRecs() {
    document.querySelectorAll('.recommendation-item').forEach(item => {
        item.classList.add('selected');
    });
}

function clearSelection() {
    document.querySelectorAll('.recommendation-item').forEach(item => {
        item.classList.remove('selected');
    });
}

async function showSlotDetails(section, shelf, row, slot) {
    try {
        const response = await fetch(`../api/ai_recommendations.php?action=search_location&section=${section}`);
        if (!response.ok) throw new Error('Failed to load slot details');
        
        const locations = await response.json();
        const location = locations.find(loc => 
            loc.section === section && 
            loc.shelf == shelf && 
            loc.row_number == row && 
            loc.slot == slot
        );
        
        let html = `
            <div class="slot-details">
                <div class="detail-header">
                    <div class="location-badge">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>${section}-S${shelf}-R${row}-P${slot}</span>
                    </div>
                </div>
        `;
        
        if (location && location.title) {
            html += `
                <div class="book-detail-card">
                    <div class="card-header">
                        <h4><i class="fas fa-book"></i> Book Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="book-info-grid">
                            <div class="info-item">
                                <label>Title</label>
                                <p class="info-value">${escapeHtml(location.title)}</p>
                            </div>
                            <div class="info-item">
                                <label>Author</label>
                                <p class="info-value">${escapeHtml(location.author)}</p>
                            </div>
                            <div class="info-item">
                                <label>Category</label>
                                <span class="category-badge">${escapeHtml(location.category_name)}</span>
                            </div>
                            <div class="info-item">
                                <label>Copy Number</label>
                                <p class="info-value">${escapeHtml(location.copy_number)}</p>
                            </div>
                            <div class="info-item">
                                <label>Status</label>
                                <span class="status-badge status-${location.status}">${location.status}</span>
                            </div>
                            <div class="info-item">
                                <label>Condition</label>
                                <span class="condition-badge">${location.book_condition}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-outline" onclick="viewBookFromLocation(${location.book_id})">
                        <i class="fas fa-eye"></i> View Book Details
                    </button>
                </div>
            `;
        } else {
            html += `
                <div class="empty-slot-card">
                    <div class="empty-icon">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <h4>Available Slot</h4>
                    <p>This location is currently empty and ready for book placement.</p>
                    <div class="empty-actions">
                        <button class="btn btn-primary" onclick="assignBookToLocation('${section}', ${shelf}, ${row}, ${slot})">
                            <i class="fas fa-plus"></i> Assign a Book
                        </button>
                        <button class="btn btn-soft" onclick="suggestBookForSlot('${section}', ${shelf}, ${row}, ${slot})">
                            <i class="fas fa-lightbulb"></i> Get Suggestion
                        </button>
                    </div>
                </div>
            `;
            
            // Show assign button in modal footer
            document.getElementById('assignBookBtn').style.display = 'block';
            document.getElementById('assignBookBtn').onclick = () => 
                assignBookToLocation(section, shelf, row, slot);
        }
        
        html += `</div>`;
        
        document.getElementById('slotModalContent').innerHTML = html;
        document.getElementById('slotModalTitle').innerHTML = 
            `${section}-S${shelf}-R${row}-P${slot}`;
        document.getElementById('slotModal').style.display = 'flex';
        
    } catch (error) {
        showNotification('Error loading slot details: ' + error.message, 'error');
    }
}

async function applyBookRecommendation(bookId, section, shelf, row, slot, showAlert = true) {
    try {
        // Get all copies of this book
        const copiesResponse = await fetch(`../api/book_copies.php?book_id=${bookId}`);
        if (!copiesResponse.ok) throw new Error('Failed to fetch copies');
        
        const copies = await copiesResponse.json();
        
        if (!copies || copies.length === 0) {
            if (showAlert) showNotification('This book has no copies to place.', 'warning');
            return;
        }
        
        const csrf = sessionStorage.getItem('csrf') || '';
        let placed = 0;
        
        // Place each copy in sequential slots
        for (const copy of copies) {
            if (!copy.current_section) {
                const response = await fetch('../api/ai_recommendations.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({
                        copy_id: copy.id,
                        section: section,
                        shelf: shelf,
                        row: row,
                        slot: slot
                    })
                });
                
                if (response.ok) {
                    placed++;
                    
                    // Move to next slot
                    slot++;
                    if (slot > 12) {
                        slot = 1;
                        row++;
                        if (row > 6) {
                            row = 1;
                            shelf++;
                        }
                    }
                }
            }
        }
        
        if (showAlert) {
            showNotification(`Successfully placed ${placed} copy/copies!`, 'success');
            setTimeout(() => location.reload(), 1500);
        }
        
        return placed;
        
    } catch (error) {
        if (showAlert) showNotification('Error applying recommendation: ' + error.message, 'error');
        throw error;
    }
}

async function findAlternativeLocation(bookId) {
    try {
        const response = await fetch(`../api/ai_recommendations.php?action=recommend&book_id=${bookId}`);
        if (!response.ok) throw new Error('Failed to find alternative');
        
        const recommendation = await response.json();
        
        // Highlight the location on the map
        const slot = document.querySelector(`.slot[data-section="${recommendation.default_section}"][data-shelf="${recommendation.shelf_recommendation}"][data-row="${recommendation.row_recommendation}"][data-slot="${recommendation.slot_recommendation}"]`);
        
        if (slot) {
            slot.classList.add('recommended');
            
            // Scroll to the slot
            slot.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Remove highlight after 3 seconds
            setTimeout(() => {
                slot.classList.remove('recommended');
            }, 3000);
        }
        
        showNotification(`AI suggests: ${recommendation.default_section}-S${recommendation.shelf_recommendation}-R${recommendation.row_recommendation}-P${recommendation.slot_recommendation}`, 'info');
        
    } catch (error) {
        showNotification('Error finding alternative: ' + error.message, 'error');
    }
}

async function locateBookOnMap(bookId) {
    try {
        const response = await fetch(`../api/ai_recommendations.php?action=get_location&book_id=${bookId}`);
        if (!response.ok) throw new Error('Failed to locate book');
        
        const location = await response.json();
        
        if (location && location.current_section) {
            const slot = document.querySelector(`.slot[data-section="${location.current_section}"][data-shelf="${location.current_shelf}"][data-row="${location.current_row}"][data-slot="${location.current_slot}"]`);
            
            if (slot) {
                // Add highlight
                slot.classList.add('recommended');
                
                // Scroll to the slot
                slot.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // Remove highlight after 3 seconds
                setTimeout(() => {
                    slot.classList.remove('recommended');
                }, 3000);
                
                showNotification(`Book located at: ${location.current_section}-S${location.current_shelf}-R${location.current_row}-P${location.current_slot}`, 'info');
            } else {
                showNotification('Book location not found on map', 'warning');
            }
        } else {
            showNotification('This book has no physical location assigned', 'warning');
        }
        
    } catch (error) {
        showNotification('Error locating book: ' + error.message, 'error');
    }
}

function viewBookFromLocation(bookId) {
    window.open(`manage_books.php?view=${bookId}`, '_blank');
    closeModal();
}

function assignBookToLocation(section, shelf, row, slot) {
    window.location.href = `manage_books.php?search=&filter_location=empty&assign_section=${section}&assign_shelf=${shelf}&assign_row=${row}&assign_slot=${slot}`;
}

function suggestBookForSlot(section, shelf, row, slot) {
    // This would call an API to get AI suggestions for this specific slot
    showNotification('AI suggestion feature coming soon!', 'info');
}

function toggleLegend() {
    document.getElementById('mapLegend').classList.toggle('show');
}

function closeModal() {
    document.getElementById('slotModal').style.display = 'none';
    document.getElementById('assignBookBtn').style.display = 'none';
}

function scanForIssues() {
    showNotification('Scanning for organizational issues...', 'info');
    // Implementation for scanning issues would go here
}

function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
        <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    
    // Add styles
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
        color: white;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 9999;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 300px;
        max-width: 400px;
        animation: slideInRight 0.3s ease-out;
    `;
    
    // Add to document
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.animation = 'slideOutRight 0.3s ease-out forwards';
            setTimeout(() => notification.remove(), 300);
        }
    }, 5000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Add notification styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(100%);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    @keyframes slideOutRight {
        from {
            opacity: 1;
            transform: translateX(0);
        }
        to {
            opacity: 0;
            transform: translateX(100%);
        }
    }
    
    .notification-content {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .notification-close {
        background: none;
        border: none;
        color: white;
        font-size: 1.25rem;
        cursor: pointer;
        padding: 0;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0.8;
    }
    
    .notification-close:hover {
        opacity: 1;
    }
`;
document.head.appendChild(style);
</script>

<?php include __DIR__ . '/_footer.php'; ?>