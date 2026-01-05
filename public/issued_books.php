<?php
// Borrowed Books page with copy-based tracking, damage assessment, and penalty calculations
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$pdo = DB::conn();

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$student_id = $_GET['student_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query with filters
$params = [];
$whereClauses = ["bl.status <> 'returned'"];

if ($filter === 'overdue') {
    $whereClauses[] = "bl.status = 'overdue'";
} elseif ($filter === 'active') {
    $whereClauses[] = "bl.status = 'borrowed'";
}

if (!empty($student_id)) {
    $whereClauses[] = "(u.student_id = :student_id OR p.library_id = :student_id2)";
    $params[':student_id'] = $student_id;
    $params[':student_id2'] = $student_id;
}

if (!empty($status_filter) && in_array($status_filter, ['borrowed', 'overdue', 'returned'])) {
    $whereClauses[] = "bl.status = :status";
    $params[':status'] = $status_filter;
}

$whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Get borrow logs with detailed information including book copies
$sql = "SELECT 
            bl.id, 
            bl.book_id,
            bl.book_copy_id,
            bl.patron_id,
            b.title AS book_name,
            b.author,
            b.cover_image_cache,
            bc.copy_number,
            bc.barcode,
            bc.book_condition,
            bl.borrowed_at, 
            bl.due_date,
            bl.returned_at,
            bl.status,
            bl.late_fee,
            bl.penalty_fee,
            bl.damage_type,
            bl.damage_description,
            bl.return_condition,
            bl.return_status,
            bl.return_book_condition,
            bl.damage_types,
            u.role AS user_role, 
            u.name AS user_name, 
            u.username,
            u.email,
            u.student_id,
            p.name AS patron_name,
            p.library_id,
            p.department,
            p.semester,
            c.name AS category_name,
            bc.current_section,
            bc.current_shelf,
            bc.current_row,
            bc.current_slot
        FROM borrow_logs bl
        JOIN books b ON bl.book_id = b.id
        LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
        JOIN patrons p ON bl.patron_id = p.id
        LEFT JOIN users u ON u.patron_id = p.id
        LEFT JOIN categories c ON b.category_id = c.id
        $whereSQL
        ORDER BY bl.due_date ASC, bl.borrowed_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$issued = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get damage types for the form
$damageTypes = $pdo->query("SELECT * FROM damage_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/_header.php';
?>

<style>
/* Enhanced styling */
.card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
    padding: 20px;
}

.book-cover-container {
    width: 100px;
    height: 140px;
    border-radius: 8px;
    overflow: hidden;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
}

.book-cover {
    max-width: 100%;
    max-height: 100%;
    object-fit: cover;
}

.fee-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.fee-overdue {
    background: #fee2e2;
    color: #dc2626;
}

.fee-damage {
    background: #fef3c7;
    color: #d97706;
}

.fee-pending {
    background: #dbeafe;
    color: #1d4ed8;
}

.damage-tag {
    display: inline-block;
    padding: 2px 8px;
    background: #fee2e2;
    color: #dc2626;
    border-radius: 12px;
    font-size: 12px;
    margin: 2px;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.status-borrowed {
    background: #dbeafe;
    color: #1d4ed8;
}

.status-overdue {
    background: #fee2e2;
    color: #dc2626;
}

.status-returned {
    background: #d1fae5;
    color: #059669;
}

.filter-container {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    min-width: 200px;
}

.filter-group label {
    font-weight: 500;
    margin-bottom: 5px;
    color: #4b5563;
}

.search-box {
    display: flex;
    gap: 10px;
}

.search-box input {
    flex: 1;
    min-width: 250px;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 30px;
    border-radius: 10px;
    width: 90%;
    max-width: 700px;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 {
    margin: 0;
    color: #1f2937;
}

.close-modal {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #6b7280;
}

.damage-checkboxes {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
    margin: 20px 0;
}

.damage-checkbox {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
}

.damage-checkbox:hover {
    border-color: #3b82f6;
    background: #f8fafc;
}

.damage-checkbox input:checked + .damage-label {
    color: #3b82f6;
    font-weight: bold;
}

.damage-label {
    cursor: pointer;
    flex: 1;
}

.damage-fee {
    font-weight: bold;
    color: #059669;
}

.fee-summary {
    background: #f8fafc;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
}

.fee-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e5e7eb;
}

.fee-total {
    font-size: 18px;
    font-weight: bold;
    color: #1f2937;
    border-top: 2px solid #1f2937;
    padding-top: 10px;
    margin-top: 10px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.book-info-card {
    display: flex;
    gap: 20px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 8px;
    margin-bottom: 20px;
}

.book-details {
    flex: 1;
}

.copy-info {
    background: #fff;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.overdue-warning {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border-left: 4px solid #dc2626;
    padding: 15px;
    margin: 10px 0;
    border-radius: 8px;
}

.location-badge {
    display: inline-block;
    padding: 4px 8px;
    background: #e0e7ff;
    color: #4f46e5;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-state i {
    font-size: 48px;
    color: #9ca3af;
    margin-bottom: 20px;
}

.empty-state h3 {
    color: #4b5563;
    margin-bottom: 10px;
}

.return-form-container {
    background: #f8fafc;
    padding: 20px;
    border-radius: 10px;
    margin-top: 20px;
}
</style>

<div class="container">
    <div class="page-header">
        <h2>📚 Borrowed Books Management</h2>
        <p class="text-muted">Manage borrowed books, track overdue items, and process returns with penalty calculations</p>
    </div>

    <!-- Filters Card -->
    <div class="card">
        <h3 style="margin-bottom: 20px;">🔍 Search & Filter</h3>
        <div class="filter-container">
            <div class="filter-group">
                <label for="student_id">Search by Student/Library ID</label>
                <div class="search-box">
                    <input type="text" 
                           id="student_id" 
                           class="form-control" 
                           placeholder="Enter Student ID or Library ID"
                           value="<?= htmlspecialchars($student_id) ?>">
                    <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                    <?php if (!empty($student_id)): ?>
                        <button class="btn btn-secondary" onclick="clearFilters()">Clear</button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="filter-group">
                <label for="status_filter">Filter by Status</label>
                <select id="status_filter" class="form-control" onchange="applyFilters()">
                    <option value="">All Active</option>
                    <option value="borrowed" <?= $status_filter === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                    <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Summary Card -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3>📋 Borrowed Books List</h3>
            <div>
                <span class="badge bg-primary">Total: <?= count($issued) ?></span>
                <?php 
                $overdueCount = array_reduce($issued, function($carry, $item) {
                    return $carry + ($item['status'] === 'overdue' ? 1 : 0);
                }, 0);
                if ($overdueCount > 0): ?>
                    <span class="badge bg-danger">Overdue: <?= $overdueCount ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($issued)): ?>
            <div class="empty-state">
                <i class="fa fa-book-open"></i>
                <h3>No borrowed books found</h3>
                <p>There are currently no borrowed books matching your criteria.</p>
                <?php if (!empty($student_id)): ?>
                    <button class="btn btn-primary" onclick="clearFilters()">Clear Search</button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Book Details</th>
                            <th>Patron Info</th>
                            <th>Borrow Details</th>
                            <th>Fees</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issued as $row): 
                            $isOverdue = $row['status'] === 'overdue';
                            $daysOverdue = $isOverdue ? max(0, floor((time() - strtotime($row['due_date'])) / (60 * 60 * 24))) : 0;
                            $coverImage = !empty($row['cover_image_cache']) ? 
                                '../uploads/book_covers/' . $row['cover_image_cache'] : 
                                '../assets/default-book.png';
                            
                            // Parse damage types if available
                            $damages = [];
                            if (!empty($row['damage_types'])) {
                                $damages = json_decode($row['damage_types'], true);
                                if (!is_array($damages)) {
                                    $damages = [];
                                }
                            }
                        ?>
                        <tr id="row-<?= $row['id'] ?>" class="<?= $isOverdue ? 'table-danger' : '' ?>">
                            <td>
                                <div class="book-info-card">
                                    <div class="book-cover-container">
                                        <img src="<?= $coverImage ?>" 
                                             alt="<?= htmlspecialchars($row['book_name']) ?>" 
                                             class="book-cover"
                                             onerror="this.src='../assets/default-book.png'">
                                    </div>
                                    <div class="book-details">
                                        <h5><?= htmlspecialchars($row['book_name']) ?></h5>
                                        <p class="text-muted mb-1"><?= htmlspecialchars($row['author']) ?></p>
                                        <p class="text-muted mb-1">Category: <?= htmlspecialchars($row['category_name'] ?? 'N/A') ?></p>
                                        <?php if (!empty($row['copy_number'])): ?>
                                            <div class="copy-info">
                                                <small>
                                                    <strong>Copy:</strong> <?= htmlspecialchars($row['copy_number']) ?><br>
                                                    <strong>Barcode:</strong> <?= htmlspecialchars($row['barcode']) ?><br>
                                                    <?php if ($row['current_section']): ?>
                                                        <span class="location-badge">
                                                            Location: <?= htmlspecialchars($row['current_section']) ?>-
                                                            S<?= htmlspecialchars($row['current_shelf']) ?>-
                                                            R<?= htmlspecialchars($row['current_row']) ?>-
                                                            P<?= htmlspecialchars($row['current_slot']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($row['patron_name'] ?? $row['user_name']) ?></strong><br>
                                <small class="text-muted">
                                    ID: <?= htmlspecialchars($row['student_id'] ?? $row['library_id']) ?><br>
                                    <?= htmlspecialchars($row['department'] ?? '') ?><br>
                                    <?= htmlspecialchars($row['semester'] ?? '') ?>
                                </small>
                            </td>
                            <td>
                                <div>
                                    <small class="text-muted">Borrowed:</small><br>
                                    <?= date('M d, Y', strtotime($row['borrowed_at'])) ?><br>
                                    
                                    <small class="text-muted">Due:</small><br>
                                    <strong class="<?= $isOverdue ? 'text-danger' : 'text-primary' ?>">
                                        <?= date('M d, Y', strtotime($row['due_date'])) ?>
                                    </strong><br>
                                    
                                    <span class="status-badge status-<?= $row['status'] ?>">
                                        <?= ucfirst($row['status']) ?>
                                        <?php if ($isOverdue): ?>
                                            (<?= $daysOverdue ?> days)
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php if ($isOverdue): ?>
                                    <span class="fee-badge fee-overdue">
                                        Overdue: ₱<?= $daysOverdue * 30 ?>
                                    </span><br>
                                <?php endif; ?>
                                
                                <?php if (!empty($row['late_fee']) && $row['late_fee'] > 0): ?>
                                    <span class="fee-badge fee-overdue">
                                        Late Fee: ₱<?= number_format($row['late_fee'], 2) ?>
                                    </span><br>
                                <?php endif; ?>
                                
                                <?php if (!empty($row['penalty_fee']) && $row['penalty_fee'] > 0): ?>
                                    <span class="fee-badge fee-damage">
                                        Damage: ₱<?= number_format($row['penalty_fee'], 2) ?>
                                    </span><br>
                                <?php endif; ?>
                                
                                <?php if (!empty($damages)): ?>
                                    <small class="text-muted">Damages:</small><br>
                                    <?php foreach ($damages as $damage): ?>
                                        <span class="damage-tag"><?= htmlspecialchars($damage) ?></span>
                                    <?php endforeach; ?>
                                <?php elseif (!empty($row['damage_type']) && $row['damage_type'] !== 'none'): ?>
                                    <small class="text-muted">Damage:</small><br>
                                    <span class="damage-tag"><?= htmlspecialchars($row['damage_type']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($row['status'] !== 'returned'): ?>
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="showReturnModal(<?= $row['id'] ?>)">
                                            <i class="fa fa-undo"></i> Return
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-info" 
                                            onclick="viewDetails(<?= $row['id'] ?>)">
                                        <i class="fa fa-eye"></i> Details
                                    </button>
                                    <?php if ($row['status'] === 'returned'): ?>
                                        <button class="btn btn-sm btn-success" 
                                                onclick="generateReceipt(<?= $row['id'] ?>)">
                                            <i class="fa fa-receipt"></i> Receipt
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-danger" 
                                            onclick="deleteRecord(<?= $row['id'] ?>)">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Return Modal -->
<div id="returnModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📖 Return Book</h3>
            <button class="close-modal" onclick="closeModal()">&times;</button>
        </div>
        
        <div id="modalContent">
            <!-- Dynamic content loaded via JavaScript -->
        </div>
    </div>
</div>

<!-- Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📋 Borrow Details</h3>
            <button class="close-modal" onclick="closeModal()">&times;</button>
        </div>
        <div id="detailsContent">
            <!-- Dynamic content loaded via JavaScript -->
        </div>
    </div>
</div>

<script>
let currentBorrowId = null;
const overdueFeePerDay = 30;

function applyFilters() {
    const studentId = document.getElementById('student_id').value;
    const statusFilter = document.getElementById('status_filter').value;
    
    let url = 'issued_books.php?';
    if (studentId) url += `student_id=${encodeURIComponent(studentId)}&`;
    if (statusFilter) url += `status=${encodeURIComponent(statusFilter)}&`;
    
    window.location.href = url;
}

function clearFilters() {
    window.location.href = 'issued_books.php';
}

function showReturnModal(borrowId) {
    currentBorrowId = borrowId;
    
    fetch(`../api/get_borrow_details.php?id=${borrowId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Error loading data');
                return;
            }
            
            const borrow = data.data;
            const dueDate = new Date(borrow.due_date);
            const today = new Date();
            const daysOverdue = Math.max(0, Math.floor((today - dueDate) / (1000 * 60 * 60 * 24)));
            const overdueFee = daysOverdue * overdueFeePerDay;
            
            let modalHTML = `
                <div class="return-form-container">
                    <div class="book-info-card">
                        <div class="book-cover-container">
                            <img src="${borrow.cover_image || '../assets/default-book.png'}" 
                                 alt="${borrow.book_name}" 
                                 class="book-cover">
                        </div>
                        <div class="book-details">
                            <h4>${borrow.book_name}</h4>
                            <p><strong>Author:</strong> ${borrow.author}</p>
                            <p><strong>Patron:</strong> ${borrow.patron_name} (${borrow.library_id})</p>
                            <p><strong>Due Date:</strong> ${new Date(borrow.due_date).toLocaleDateString()}</p>
                            ${daysOverdue > 0 ? `
                                <div class="overdue-warning">
                                    <i class="fa fa-exclamation-triangle"></i>
                                    <strong>${daysOverdue} days overdue</strong>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                    
                    <h4>🛠️ Damage Assessment</h4>
                    <div class="damage-checkboxes" id="damageCheckboxes">
            `;
            
            // Add damage checkboxes
            <?php foreach ($damageTypes as $type): ?>
                modalHTML += `
                    <div class="damage-checkbox">
                        <input type="checkbox" 
                               id="damage_${borrowId}_<?= $type['id'] ?>" 
                               name="damage_types[]" 
                               value="<?= $type['name'] ?>"
                               data-fee="<?= $type['fee_amount'] ?>"
                               class="damage-checkbox-input">
                        <label for="damage_${borrowId}_<?= $type['id'] ?>" class="damage-label">
                            <?= htmlspecialchars($type['name']) ?>
                        </label>
                        <span class="damage-fee">₱<?= number_format($type['fee_amount'], 2) ?></span>
                    </div>
                `;
            <?php endforeach; ?>
            
            modalHTML += `
                    </div>
                    
                    <div class="form-group">
                        <label for="damageDescription">Damage Description</label>
                        <textarea id="damageDescription" class="form-control" rows="3" 
                                  placeholder="Describe any damage to the book..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="returnCondition">Return Condition</label>
                        <select id="returnCondition" class="form-control">
                            <option value="good">Good</option>
                            <option value="fair">Fair</option>
                            <option value="poor">Poor</option>
                            <option value="damaged">Damaged</option>
                            <option value="lost">Lost</option>
                        </select>
                    </div>
                    
                    <div class="fee-summary">
                        <h5>💰 Fee Summary</h5>
                        <div class="fee-item">
                            <span>Overdue Days:</span>
                            <span id="overdueDays">${daysOverdue}</span>
                        </div>
                        <div class="fee-item">
                            <span>Overdue Fee (₱${overdueFeePerDay}/day):</span>
                            <span id="overdueFee">₱${overdueFee.toFixed(2)}</span>
                        </div>
                        <div class="fee-item">
                            <span>Damage Fees:</span>
                            <span id="damageFee">₱0.00</span>
                        </div>
                        <div class="fee-item fee-total">
                            <span>Total Amount:</span>
                            <span id="totalFee">₱${overdueFee.toFixed(2)}</span>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="processReturn()">
                            <i class="fa fa-check"></i> Process Return & Generate Receipt
                        </button>
                        <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    </div>
                </div>
            `;
            
            document.getElementById('modalContent').innerHTML = modalHTML;
            document.getElementById('returnModal').style.display = 'block';
            
            // Add event listeners for damage checkboxes
            document.querySelectorAll('.damage-checkbox-input').forEach(checkbox => {
                checkbox.addEventListener('change', updateFeeSummary);
            });
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading borrow details');
        });
}

function updateFeeSummary() {
    const overdueFee = parseFloat(document.getElementById('overdueFee').textContent.replace('₱', '')) || 0;
    let damageFee = 0;
    
    document.querySelectorAll('.damage-checkbox-input:checked').forEach(checkbox => {
        damageFee += parseFloat(checkbox.dataset.fee);
    });
    
    document.getElementById('damageFee').textContent = `₱${damageFee.toFixed(2)}`;
    
    const totalFee = overdueFee + damageFee;
    document.getElementById('totalFee').textContent = `₱${totalFee.toFixed(2)}`;
}

function processReturn() {
    if (!currentBorrowId) return;
    
    const damageTypes = [];
    const damageFees = [];
    let totalDamageFee = 0;
    
    document.querySelectorAll('.damage-checkbox-input:checked').forEach(checkbox => {
        damageTypes.push(checkbox.value);
        damageFees.push({
            type: checkbox.value,
            fee: parseFloat(checkbox.dataset.fee)
        });
        totalDamageFee += parseFloat(checkbox.dataset.fee);
    });
    
    const damageDescription = document.getElementById('damageDescription').value;
    const returnCondition = document.getElementById('returnCondition').value;
    const overdueFee = parseFloat(document.getElementById('overdueFee').textContent.replace('₱', '')) || 0;
    const totalFee = parseFloat(document.getElementById('totalFee').textContent.replace('₱', '')) || 0;
    
    const formData = new FormData();
    formData.append('borrow_id', currentBorrowId);
    formData.append('damage_types', JSON.stringify(damageTypes));
    formData.append('damage_description', damageDescription);
    formData.append('return_condition', returnCondition);
    formData.append('late_fee', overdueFee);
    formData.append('damage_fee', totalDamageFee);
    formData.append('total_fee', totalFee);
    
    fetch('../api/process_return.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Book returned successfully! Receipt generated.');
            if (data.receipt_pdf) {
                // Open receipt in new tab
                window.open(data.receipt_pdf, '_blank');
            }
            closeModal();
            location.reload();
        } else {
            alert(data.message || 'Error processing return');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error processing return');
    });
}

function viewDetails(borrowId) {
    fetch(`../api/get_borrow_details.php?id=${borrowId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Error loading details');
                return;
            }
            
            const borrow = data.data;
            let detailsHTML = `
                <div class="book-info-card">
                    <div class="book-cover-container">
                        <img src="${borrow.cover_image || '../assets/default-book.png'}" 
                             alt="${borrow.book_name}" 
                             class="book-cover">
                    </div>
                    <div class="book-details">
                        <h4>${borrow.book_name}</h4>
                        <p><strong>Author:</strong> ${borrow.author}</p>
                        <p><strong>ISBN:</strong> ${borrow.isbn || 'N/A'}</p>
                        <p><strong>Category:</strong> ${borrow.category_name || 'N/A'}</p>
                    </div>
                </div>
                
                <div class="row" style="margin-top: 20px;">
                    <div class="col-md-6">
                        <h5>📋 Borrow Information</h5>
                        <table class="table table-sm">
                            <tr>
                                <th>Borrowed Date:</th>
                                <td>${new Date(borrow.borrowed_at).toLocaleString()}</td>
                            </tr>
                            <tr>
                                <th>Due Date:</th>
                                <td class="${borrow.status === 'overdue' ? 'text-danger' : 'text-primary'}">
                                    ${new Date(borrow.due_date).toLocaleDateString()}
                                </td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <span class="status-badge status-${borrow.status}">
                                        ${borrow.status.charAt(0).toUpperCase() + borrow.status.slice(1)}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Returned Date:</th>
                                <td>${borrow.returned_at ? new Date(borrow.returned_at).toLocaleString() : 'Not returned yet'}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5>👤 Patron Information</h5>
                        <table class="table table-sm">
                            <tr>
                                <th>Name:</th>
                                <td>${borrow.patron_name}</td>
                            </tr>
                            <tr>
                                <th>Library ID:</th>
                                <td>${borrow.library_id}</td>
                            </tr>
                            <tr>
                                <th>Department:</th>
                                <td>${borrow.department || 'N/A'}</td>
                            </tr>
                            <tr>
                                <th>Semester:</th>
                                <td>${borrow.semester || 'N/A'}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            `;
            
            if (borrow.copy_number) {
                detailsHTML += `
                    <div class="copy-info" style="margin-top: 20px;">
                        <h5>📄 Copy Information</h5>
                        <table class="table table-sm">
                            <tr>
                                <th>Copy Number:</th>
                                <td>${borrow.copy_number}</td>
                            </tr>
                            <tr>
                                <th>Barcode:</th>
                                <td>${borrow.barcode}</td>
                            </tr>
                            <tr>
                                <th>Condition:</th>
                                <td>${borrow.book_condition}</td>
                            </tr>
                        </table>
                    </div>
                `;
            }
            
            if (borrow.late_fee > 0 || borrow.penalty_fee > 0) {
                detailsHTML += `
                    <div class="fee-summary" style="margin-top: 20px;">
                        <h5>💰 Fee Information</h5>
                        ${borrow.late_fee > 0 ? `
                            <div class="fee-item">
                                <span>Late Fee:</span>
                                <span class="text-danger">₱${parseFloat(borrow.late_fee).toFixed(2)}</span>
                            </div>
                        ` : ''}
                        ${borrow.penalty_fee > 0 ? `
                            <div class="fee-item">
                                <span>Damage Fee:</span>
                                <span class="text-warning">₱${parseFloat(borrow.penalty_fee).toFixed(2)}</span>
                            </div>
                        ` : ''}
                    </div>
                `;
            }
            
            document.getElementById('detailsContent').innerHTML = detailsHTML;
            document.getElementById('detailsModal').style.display = 'block';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading details');
        });
}

function generateReceipt(borrowId) {
    fetch(`../api/generate_receipt.php?borrow_id=${borrowId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.receipt_pdf) {
                window.open(data.receipt_pdf, '_blank');
            } else {
                alert(data.message || 'Error generating receipt');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error generating receipt');
        });
}

function deleteRecord(borrowId) {
    if (!confirm('Are you sure you want to delete this borrow record? This action cannot be undone.')) {
        return;
    }
    
    fetch(`../api/dispatch.php?resource=borrow_logs&id=${borrowId}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-Token': sessionStorage.getItem('csrf') || ''
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById(`row-${borrowId}`).remove();
            showToast('Record deleted successfully', 'success');
        } else {
            alert(data.message || 'Error deleting record');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error deleting record');
    });
}

function closeModal() {
    document.getElementById('returnModal').style.display = 'none';
    document.getElementById('detailsModal').style.display = 'none';
    currentBorrowId = null;
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = document.getElementsByClassName('modal');
    for (let modal of modals) {
        if (event.target === modal) {
            closeModal();
        }
    }
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
        color: white;
        border-radius: 8px;
        z-index: 9999;
        animation: slideIn 0.3s ease-out;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .table tr:hover {
        background-color: #f8fafc;
    }
    
    .btn-sm {
        padding: 5px 10px;
        font-size: 12px;
    }
`;
document.head.appendChild(style);
</script>