<?php
// borrowed_books.php - Comprehensive borrowed books management with copy tracking and penalty calculation
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$pdo = DB::conn();

// Get fee settings
$stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('overdue_fee_per_day', 'damage_fee_paper_torn', 'damage_fee_general')");
$feeSettings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $feeSettings[$row['key']] = $row['value'];
}

$overdueFeePerDay = floatval($feeSettings['overdue_fee_per_day'] ?? 30);
$paperTornFee = floatval($feeSettings['damage_fee_paper_torn'] ?? 500);
$generalDamageFee = floatval($feeSettings['damage_fee_general'] ?? 300);

// Handle return with penalty calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_book'])) {
    $borrowId = (int)$_POST['borrow_id'];
    $actualReturnDate = $_POST['actual_return_date'];
    $damageType = $_POST['damage_type'] ?? 'none';
    $damageDescription = $_POST['damage_description'] ?? '';
    $bookCopyId = (int)$_POST['book_copy_id'];
    
    // Get borrow details
    $stmt = $pdo->prepare("SELECT bl.*, bc.copy_number FROM borrow_logs bl 
                          JOIN book_copies bc ON bl.book_copy_id = bc.id 
                          WHERE bl.id = ?");
    $stmt->execute([$borrowId]);
    $borrow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($borrow) {
        // Calculate overdue days
        $dueDate = new DateTime($borrow['due_date']);
        $returnDate = new DateTime($actualReturnDate);
        $overdueDays = max(0, $dueDate->diff($returnDate)->days);
        
        // Calculate overdue fee
        $overdueFee = $overdueDays * $overdueFeePerDay;
        
        // Calculate damage fee
        $damageFee = 0;
        if ($damageType === 'paper_torn') {
            $damageFee = $paperTornFee;
        } elseif ($damageType === 'general_damage') {
            $damageFee = $generalDamageFee;
        }
        
        $totalPenalty = $overdueFee + $damageFee;
        
        // Update borrow log
        $updateStmt = $pdo->prepare("UPDATE borrow_logs 
                                    SET status = 'returned', 
                                        returned_at = NOW(),
                                        actual_return_date = ?,
                                        late_fee = ?,
                                        penalty_fee = ?,
                                        damage_type = ?,
                                        damage_description = ?,
                                        notes = CONCAT(IFNULL(notes, ''), ' Returned with penalty: ? PHP')
                                    WHERE id = ?");
        $updateStmt->execute([
            $actualReturnDate,
            $overdueFee,
            $totalPenalty,
            $damageType,
            $damageDescription,
            $totalPenalty,
            $borrowId
        ]);
        
        // Update book copy status
        $copyStmt = $pdo->prepare("UPDATE book_copies SET status = 'available' WHERE id = ?");
        $copyStmt->execute([$bookCopyId]);
        
        // Log transaction
        $transactionStmt = $pdo->prepare("INSERT INTO copy_transactions 
                                         (book_copy_id, transaction_type, from_status, to_status, notes) 
                                         VALUES (?, 'returned', 'borrowed', 'available', ?)");
        $transactionStmt->execute([
            $bookCopyId,
            "Book returned with penalty: PHP $totalPenalty (Overdue: $overdueFee, Damage: $damageFee)"
        ]);
        
        $_SESSION['success_message'] = "Book returned successfully. Total penalty: PHP $totalPenalty";
        header("Location: borrowed_books.php");
        exit;
    }
}

// Handle search
$searchQuery = '';
$whereConditions = ["bl.status IN ('borrowed', 'overdue')"];
$params = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = trim($_GET['search']);
    $searchQuery = $search;
    
    // Check if search is student ID (starts with numbers)
    if (preg_match('/^\d+$/', $search)) {
        $whereConditions[] = "u.student_id LIKE ?";
        $params[] = "%$search%";
    } else {
        // Search by name, username, or book title
        $whereConditions[] = "(u.name LIKE ? OR u.username LIKE ? OR b.title LIKE ? OR bc.copy_number LIKE ?)";
        $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
    }
}

// Filter by status
if (isset($_GET['filter'])) {
    if ($_GET['filter'] === 'overdue') {
        $whereConditions[] = "bl.status = 'overdue'";
    } elseif ($_GET['filter'] === 'borrowed') {
        $whereConditions[] = "bl.status = 'borrowed'";
    }
}

// Build query
$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
$sql = "SELECT 
            bl.id,
            bl.book_id,
            bl.book_copy_id,
            bl.borrowed_at,
            bl.due_date,
            bl.status,
            bl.late_fee,
            bl.penalty_fee,
            bl.damage_type,
            b.title,
            b.author,
            b.cover_image_cache,
            bc.copy_number,
            bc.barcode,
            bc.book_condition,
            u.id as user_id,
            u.name as user_name,
            u.username,
            u.email,
            u.student_id,
            u.role,
            p.library_id
        FROM borrow_logs bl
        JOIN books b ON bl.book_id = b.id
        JOIN book_copies bc ON bl.book_copy_id = bc.id
        JOIN patrons p ON bl.patron_id = p.id
        LEFT JOIN users u ON u.patron_id = p.id
        $whereClause
        ORDER BY bl.due_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$borrowedBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate overdue status for each book
foreach ($borrowedBooks as &$book) {
    $dueDate = new DateTime($book['due_date']);
    $now = new DateTime();
    
    if ($now > $dueDate && $book['status'] === 'borrowed') {
        $book['is_overdue'] = true;
        $book['overdue_days'] = $dueDate->diff($now)->days;
        $book['estimated_penalty'] = $book['overdue_days'] * $overdueFeePerDay;
    } else {
        $book['is_overdue'] = false;
        $book['overdue_days'] = 0;
        $book['estimated_penalty'] = 0;
    }
}
unset($book);

include __DIR__ . '/_header.php';
?>

<div class="container-fluid">
    <h2>📚 Borrowed Books Management</h2>
    
    <!-- Success Message -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($_SESSION['success_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <!-- Search and Filter Section -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <input type="text" 
                               class="form-control" 
                               name="search" 
                               placeholder="Search by Student ID, Name, Username, or Book Title..."
                               value="<?= htmlspecialchars($searchQuery) ?>">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="filter" class="form-select" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="borrowed" <?= (isset($_GET['filter']) && $_GET['filter'] === 'borrowed') ? 'selected' : '' ?>>Borrowed</option>
                        <option value="overdue" <?= (isset($_GET['filter']) && $_GET['filter'] === 'overdue') ? 'selected' : '' ?>>Overdue</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <a href="borrowed_books.php" class="btn btn-secondary">Clear Filters</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Books Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Borrowed Books List</h5>
            <span class="badge bg-primary">Total: <?= count($borrowedBooks) ?> books</span>
        </div>
        <div class="card-body">
            <?php if (empty($borrowedBooks)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                    <h4>No borrowed books found</h4>
                    <p class="text-muted">There are currently no borrowed books matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Cover</th>
                                <th>Book Details</th>
                                <th>Copy Info</th>
                                <th>Borrower Details</th>
                                <th>Borrow Dates</th>
                                <th>Penalty Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowedBooks as $book): ?>
                            <tr class="<?= $book['is_overdue'] ? 'table-warning' : '' ?>">
                                <!-- Cover Image -->
                                <td style="width: 80px;">
                                    <?php if ($book['cover_image_cache']): ?>
                                        <img src="../uploads/covers/<?= htmlspecialchars($book['cover_image_cache']) ?>" 
                                             alt="Cover" 
                                             class="img-thumbnail" 
                                             style="width: 60px; height: 80px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="bg-light d-flex align-items-center justify-content-center" 
                                             style="width: 60px; height: 80px;">
                                            <i class="fas fa-book text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Book Details -->
                                <td>
                                    <strong><?= htmlspecialchars($book['title']) ?></strong><br>
                                    <small class="text-muted">by <?= htmlspecialchars($book['author']) ?></small><br>
                                    <span class="badge bg-info">ID: <?= $book['book_id'] ?></span>
                                </td>
                                
                                <!-- Copy Info -->
                                <td>
                                    <strong>Copy #: <?= htmlspecialchars($book['copy_number']) ?></strong><br>
                                    <small>Barcode: <?= htmlspecialchars($book['barcode']) ?></small><br>
                                    <span class="badge bg-secondary">Condition: <?= ucfirst($book['book_condition']) ?></span>
                                </td>
                                
                                <!-- Borrower Details -->
                                <td>
                                    <strong><?= htmlspecialchars($book['user_name']) ?></strong><br>
                                    <small>Student ID: <?= htmlspecialchars($book['student_id'] ?? $book['library_id']) ?></small><br>
                                    <small>Email: <?= htmlspecialchars($book['email']) ?></small><br>
                                    <span class="badge bg-dark"><?= ucfirst($book['role']) ?></span>
                                </td>
                                
                                <!-- Borrow Dates -->
                                <td>
                                    <div><strong>Borrowed:</strong><br><?= date('M d, Y', strtotime($book['borrowed_at'])) ?></div>
                                    <div class="mt-2">
                                        <strong>Due Date:</strong><br>
                                        <span class="<?= $book['is_overdue'] ? 'text-danger fw-bold' : '' ?>">
                                            <?= date('M d, Y', strtotime($book['due_date'])) ?>
                                        </span>
                                    </div>
                                </td>
                                
                                <!-- Penalty Status -->
                                <td>
                                    <?php if ($book['is_overdue']): ?>
                                        <div class="alert alert-danger py-1 px-2 mb-1">
                                            <strong>OVERDUE!</strong><br>
                                            <?= $book['overdue_days'] ?> day(s) late<br>
                                            <small>Penalty: PHP <?= number_format($book['estimated_penalty'], 2) ?></small>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-success py-1 px-2 mb-1">
                                            <i class="fas fa-check-circle"></i> On Time
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($book['late_fee'] > 0): ?>
                                        <div class="mt-1">
                                            <small>Late Fee: PHP <?= number_format($book['late_fee'], 2) ?></small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($book['penalty_fee'] > 0): ?>
                                        <div class="mt-1">
                                            <small>Penalty: PHP <?= number_format($book['penalty_fee'], 2) ?></small>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Actions -->
                                <td style="min-width: 150px;">
                                    <button type="button" 
                                            class="btn btn-sm btn-success mb-1 w-100" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#returnModal"
                                            data-book-id="<?= $book['id'] ?>"
                                            data-copy-id="<?= $book['book_copy_id'] ?>"
                                            data-book-title="<?= htmlspecialchars($book['title']) ?>"
                                            data-copy-number="<?= htmlspecialchars($book['copy_number']) ?>"
                                            data-student-name="<?= htmlspecialchars($book['user_name']) ?>"
                                            data-student-id="<?= htmlspecialchars($book['student_id'] ?? $book['library_id']) ?>"
                                            data-due-date="<?= date('Y-m-d', strtotime($book['due_date'])) ?>"
                                            data-estimated-penalty="<?= $book['estimated_penalty'] ?>">
                                        <i class="fas fa-undo"></i> Return
                                    </button>
                                    
                                    <button type="button" 
                                            class="btn btn-sm btn-info mb-1 w-100"
                                            onclick="viewDetails(<?= $book['id'] ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    
                                    <?php if ($book['penalty_fee'] > 0 && $book['status'] === 'returned'): ?>
                                        <span class="badge bg-warning text-dark">Fee: PHP <?= number_format($book['penalty_fee'], 2) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Summary Stats -->
                <div class="row mt-4">
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">Total Borrowed</h6>
                                <h3><?= count($borrowedBooks) ?></h3>
                                <p class="text-muted mb-0">Active borrows</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <h6 class="card-title">Overdue Books</h6>
                                <h3><?= count(array_filter($borrowedBooks, fn($b) => $b['is_overdue'])) ?></h3>
                                <p class="mb-0">Need attention</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <h6 class="card-title">Estimated Penalties</h6>
                                <h3>PHP <?= number_format(array_sum(array_column($borrowedBooks, 'estimated_penalty')), 2) ?></h3>
                                <p class="mb-0">Potential income</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Return Book Modal -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-labelledby="returnModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title" id="returnModalLabel">Return Book</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="borrow_id" id="borrow_id">
                    <input type="hidden" name="book_copy_id" id="book_copy_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6>Book Information</h6>
                            <p><strong>Title:</strong> <span id="modal_book_title"></span></p>
                            <p><strong>Copy Number:</strong> <span id="modal_copy_number"></span></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Borrower Information</h6>
                            <p><strong>Name:</strong> <span id="modal_student_name"></span></p>
                            <p><strong>Student ID:</strong> <span id="modal_student_id"></span></p>
                            <p><strong>Due Date:</strong> <span id="modal_due_date" class="text-danger"></span></p>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="actual_return_date" class="form-label">Actual Return Date *</label>
                            <input type="date" 
                                   class="form-control" 
                                   id="actual_return_date" 
                                   name="actual_return_date" 
                                   value="<?= date('Y-m-d') ?>" 
                                   required
                                   onchange="calculatePenalty()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Penalty Summary</label>
                            <div class="border p-2 bg-light">
                                <p class="mb-1">Overdue Penalty: PHP <span id="overdue_penalty">0.00</span></p>
                                <p class="mb-1">Damage Penalty: PHP <span id="damage_penalty">0.00</span></p>
                                <p class="mb-0 fw-bold">Total Penalty: PHP <span id="total_penalty">0.00</span></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Book Condition</label>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="damage_type" id="damage_none" value="none" checked onchange="calculatePenalty()">
                                        <label class="form-check-label" for="damage_none">
                                            Good Condition
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="damage_type" id="damage_general" value="general_damage" onchange="calculatePenalty()">
                                        <label class="form-check-label" for="damage_general">
                                            General Damage
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="damage_type" id="damage_paper" value="paper_torn" onchange="calculatePenalty()">
                                        <label class="form-check-label" for="damage_paper">
                                            Paper Torn
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="damage_type" id="damage_pages" value="pages_missing" onchange="calculatePenalty()">
                                        <label class="form-check-label" for="damage_pages">
                                            Pages Missing
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="damage_description" class="form-label">Damage Description (if any)</label>
                        <textarea class="form-control" id="damage_description" name="damage_description" rows="2" placeholder="Describe any damages found..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Fee Structure:</strong><br>
                        • Overdue: PHP <?= $overdueFeePerDay ?> per day<br>
                        • General Damage: PHP <?= $generalDamageFee ?><br>
                        • Paper Torn: PHP <?= $paperTornFee ?><br>
                        • Pages Missing: PHP <?= $paperTornFee ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="return_book" class="btn btn-success">
                        <i class="fas fa-check"></i> Confirm Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailsModalLabel">Borrow Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>

<script>
// Fee constants from PHP
const OVERDUE_FEE_PER_DAY = <?= $overdueFeePerDay ?>;
const PAPER_TORN_FEE = <?= $paperTornFee ?>;
const GENERAL_DAMAGE_FEE = <?= $generalDamageFee ?>;

// Modal setup
const returnModal = document.getElementById('returnModal');
if (returnModal) {
    returnModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        document.getElementById('borrow_id').value = button.getAttribute('data-book-id');
        document.getElementById('book_copy_id').value = button.getAttribute('data-copy-id');
        document.getElementById('modal_book_title').textContent = button.getAttribute('data-book-title');
        document.getElementById('modal_copy_number').textContent = button.getAttribute('data-copy-number');
        document.getElementById('modal_student_name').textContent = button.getAttribute('data-student-name');
        document.getElementById('modal_student_id').textContent = button.getAttribute('data-student-id');
        document.getElementById('modal_due_date').textContent = button.getAttribute('data-due-date');
        
        // Calculate initial penalty
        setTimeout(calculatePenalty, 100);
    });
}

function calculatePenalty() {
    const dueDate = new Date(document.getElementById('modal_due_date').textContent);
    const returnDate = new Date(document.getElementById('actual_return_date').value);
    const damageType = document.querySelector('input[name="damage_type"]:checked').value;
    
    // Calculate overdue days
    const diffTime = Math.max(0, returnDate - dueDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    // Calculate fees
    let overduePenalty = diffDays * OVERDUE_FEE_PER_DAY;
    let damagePenalty = 0;
    
    switch(damageType) {
        case 'general_damage':
            damagePenalty = GENERAL_DAMAGE_FEE;
            break;
        case 'paper_torn':
        case 'pages_missing':
            damagePenalty = PAPER_TORN_FEE;
            break;
        default:
            damagePenalty = 0;
    }
    
    const totalPenalty = overduePenalty + damagePenalty;
    
    // Update display
    document.getElementById('overdue_penalty').textContent = overduePenalty.toFixed(2);
    document.getElementById('damage_penalty').textContent = damagePenalty.toFixed(2);
    document.getElementById('total_penalty').textContent = totalPenalty.toFixed(2);
}

function viewDetails(borrowId) {
    fetch(`../api/get_borrow_details.php?id=${borrowId}`)
        .then(response => response.json())
        .then(data => {
            let html = `
                <h6>Book Information</h6>
                <p><strong>Title:</strong> ${data.book_title}</p>
                <p><strong>Author:</strong> ${data.book_author}</p>
                <p><strong>Copy Number:</strong> ${data.copy_number}</p>
                
                <h6 class="mt-3">Borrow Information</h6>
                <p><strong>Borrowed:</strong> ${data.borrowed_at}</p>
                <p><strong>Due Date:</strong> ${data.due_date}</p>
                <p><strong>Status:</strong> <span class="badge bg-${data.status === 'overdue' ? 'danger' : 'primary'}">${data.status}</span></p>
                
                <h6 class="mt-3">Borrower Information</h6>
                <p><strong>Name:</strong> ${data.user_name}</p>
                <p><strong>Student ID:</strong> ${data.student_id}</p>
                <p><strong>Email:</strong> ${data.email}</p>
            `;
            
            if (data.penalty_fee > 0) {
                html += `
                    <h6 class="mt-3">Penalty Information</h6>
                    <p><strong>Late Fee:</strong> PHP ${parseFloat(data.late_fee).toFixed(2)}</p>
                    <p><strong>Damage Fee:</strong> PHP ${(parseFloat(data.penalty_fee) - parseFloat(data.late_fee)).toFixed(2)}</p>
                    <p><strong>Total Penalty:</strong> PHP ${parseFloat(data.penalty_fee).toFixed(2)}</p>
                `;
            }
            
            document.getElementById('detailsContent').innerHTML = html;
            
            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            modal.show();
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('detailsContent').innerHTML = '<div class="alert alert-danger">Error loading details</div>';
            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            modal.show();
        });
}

// Auto-update overdue status every minute
setInterval(() => {
    const overdueRows = document.querySelectorAll('tr.table-warning');
    overdueRows.forEach(row => {
        const penaltyCell = row.querySelector('.alert-danger');
        if (penaltyCell) {
            const currentText = penaltyCell.textContent;
            const daysMatch = currentText.match(/(\d+) day/);
            if (daysMatch) {
                const newDays = parseInt(daysMatch[1]) + 1;
                const newPenalty = newDays * OVERDUE_FEE_PER_DAY;
                penaltyCell.innerHTML = `<strong>OVERDUE!</strong><br>${newDays} day(s) late<br><small>Penalty: PHP ${newPenalty.toFixed(2)}</small>`;
            }
        }
    });
}, 60000); // Update every minute
</script>