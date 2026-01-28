<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_role(['admin','librarian','assistant']);

$pdo = DB::conn();

// Get filter parameters
$search = $_GET['search'] ?? '';
$action = $_GET['action'] ?? '';
$entity = $_GET['entity'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query with filters
$sql = "SELECT a.*, u.username, u.name as user_name, u.role as user_role 
        FROM audit_logs a 
        LEFT JOIN users u ON u.id = a.user_id 
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (a.action LIKE ? OR a.entity LIKE ? OR a.details LIKE ? OR u.username LIKE ? OR u.name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, array_fill(0, 5, $searchTerm));
}

if ($action && $action !== 'all') {
    $sql .= " AND a.action = ?";
    $params[] = $action;
}

if ($entity && $entity !== 'all') {
    $sql .= " AND a.entity = ?";
    $params[] = $entity;
}

if ($user_id && $user_id !== 'all') {
    $sql .= " AND a.user_id = ?";
    $params[] = $user_id;
}

if ($date_from) {
    $sql .= " AND DATE(a.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $sql .= " AND DATE(a.created_at) <= ?";
    $params[] = $date_to;
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE 1=1";
$count_params = [];
$count_conditions = [];

if ($search) {
    $count_conditions[] = "(a.action LIKE ? OR a.entity LIKE ? OR a.details LIKE ? OR u.username LIKE ? OR u.name LIKE ?)";
    $count_params = array_merge($count_params, array_fill(0, 5, "%$search%"));
}

if ($action && $action !== 'all') {
    $count_conditions[] = "a.action = ?";
    $count_params[] = $action;
}

if ($entity && $entity !== 'all') {
    $count_conditions[] = "a.entity = ?";
    $count_params[] = $entity;
}

if ($user_id && $user_id !== 'all') {
    $count_conditions[] = "a.user_id = ?";
    $count_params[] = $user_id;
}

if ($date_from) {
    $count_conditions[] = "DATE(a.created_at) >= ?";
    $count_params[] = $date_from;
}

if ($date_to) {
    $count_conditions[] = "DATE(a.created_at) <= ?";
    $count_params[] = $date_to;
}

if (!empty($count_conditions)) {
    $count_sql .= " AND " . implode(" AND ", $count_conditions);
}

$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($count_params);
$total_count = $count_stmt->fetchColumn();
$total_pages = ceil($total_count / $per_page);

// Ensure page is within valid range
if ($page < 1) $page = 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

// Get paginated data
$sql .= " ORDER BY a.id DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);

// Bind parameters
$param_index = 0;
foreach ($params as $param) {
    $stmt->bindValue(++$param_index, $param);
}
$stmt->bindValue(++$param_index, $per_page, PDO::PARAM_INT);
$stmt->bindValue(++$param_index, $offset, PDO::PARAM_INT);

$stmt->execute();
$logs = $stmt->fetchAll();

// Get unique values for filter dropdowns
$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll();
$entities = $pdo->query("SELECT DISTINCT entity FROM audit_logs ORDER BY entity")->fetchAll();
$users = $pdo->query("SELECT DISTINCT u.id, u.username, u.name, u.role FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY u.username")->fetchAll();

// Get action icons mapping
$actionIcons = [
    'login' => 'fa-sign-in-alt',
    'logout' => 'fa-sign-out-alt',
    'create' => 'fa-plus-circle',
    'update' => 'fa-edit',
    'delete' => 'fa-trash-alt',
    'borrow' => 'fa-book-open',
    'return' => 'fa-book',
    'reserve' => 'fa-calendar-check',
    'extend' => 'fa-calendar-plus',
    'report' => 'fa-flag',
    'approve' => 'fa-check-circle',
    'reject' => 'fa-times-circle',
    'damage' => 'fa-exclamation-triangle',
    'lost' => 'fa-exclamation-circle'
];

// Get entity icons mapping
$entityIcons = [
    'books' => 'fa-book',
    'book_copies' => 'fa-copy',
    'borrow_logs' => 'fa-exchange-alt',
    'reservations' => 'fa-calendar-alt',
    'users' => 'fa-user',
    'patrons' => 'fa-users',
    'categories' => 'fa-tags',
    'settings' => 'fa-cog',
    'extension_requests' => 'fa-clock',
    'lost_damaged_reports' => 'fa-exclamation',
    'receipts' => 'fa-receipt',
    'notifications' => 'fa-bell',
    'audit_logs' => 'fa-shield-alt',
    'auth' => 'fa-key'
];

// Get role colors
$roleColors = [
    'admin' => 'danger',
    'librarian' => 'warning',
    'assistant' => 'info',
    'student' => 'success',
    'non_staff' => 'secondary'
];

include __DIR__ . '/_header.php';
?>

<style>
.audit-container {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    overflow: hidden;
}

.audit-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.audit-filters {
    background: #f9fafb;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.audit-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.audit-table thead th {
    background: #f8f9fa;
    padding: 12px 16px;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    text-align: left;
    white-space: nowrap;
}

.audit-table tbody tr {
    border-bottom: 1px solid #f3f4f6;
    transition: background-color 0.2s;
}

.audit-table tbody tr:hover {
    background-color: #f9fafb;
}

.audit-table tbody td {
    padding: 12px 16px;
    vertical-align: top;
}

.audit-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    gap: 4px;
}

.badge-login { background: #d1fae5; color: #065f46; }
.badge-logout { background: #fee2e2; color: #991b1b; }
.badge-create { background: #dbeafe; color: #1e40af; }
.badge-update { background: #fef3c7; color: #92400e; }
.badge-delete { background: #fce7f3; color: #9d174d; }
.badge-borrow { background: #dcfce7; color: #166534; }
.badge-return { background: #e0e7ff; color: #3730a3; }

.user-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
}

.badge-admin { background: #fee2e2; color: #991b1b; }
.badge-librarian { background: #fef3c7; color: #92400e; }
.badge-assistant { background: #dbeafe; color: #1e40af; }
.badge-student { background: #d1fae5; color: #065f46; }
.badge-non_staff { background: #f3f4f6; color: #374151; }

.time-cell {
    white-space: nowrap;
    color: #6b7280;
    font-size: 13px;
}

.entity-cell {
    display: flex;
    align-items: center;
    gap: 8px;
}

.details-cell {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 12px;
    color: #4b5563;
    background: #f9fafb;
    padding: 8px 12px;
    border-radius: 6px;
    word-break: break-all;
    max-width: 400px;
}

.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}

.pagination-info {
    font-size: 14px;
    color: #6b7280;
}

.pagination-buttons {
    display: flex;
    gap: 8px;
}

.pagination-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid #d1d5db;
    background: white;
    color: #374151;
    text-decoration: none;
    transition: all 0.2s;
}

.pagination-btn:hover {
    background: #f3f4f6;
    color: #111827;
    border-color: #9ca3af;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: #f9fafb;
}

.pagination-btn.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.page-numbers {
    display: flex;
    gap: 4px;
    margin: 0 12px;
}

.page-link {
    min-width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    border: 1px solid #d1d5db;
    background: white;
    color: #374151;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
}

.page-link:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
}

.page-link.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.empty-state {
    padding: 3rem;
    text-align: center;
    color: #9ca3af;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 1rem;
    color: #d1d5db;
}

.filter-group {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.filter-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 150px;
}

.filter-item label {
    font-size: 12px;
    font-weight: 500;
    color: #6b7280;
}

.filter-item select, 
.filter-item input {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
}

.filter-item input[type="date"] {
    min-width: 150px;
}

.search-box {
    position: relative;
    flex: 1;
    max-width: 300px;
}

.search-box input {
    width: 100%;
    padding: 8px 12px 8px 36px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
}

.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
}

.action-buttons {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

.action-buttons button {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: none;
}

.btn-apply {
    background: #3b82f6;
    color: white;
}

.btn-clear {
    background: #f3f4f6;
    color: #374151;
}

.btn-apply:hover {
    background: #2563eb;
}

.btn-clear:hover {
    background: #e5e7eb;
}

.stats-bar {
    display: flex;
    gap: 1rem;
    padding: 1rem 1.5rem;
    background: #f0f9ff;
    border-bottom: 1px solid #e5e7eb;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.stat-item i {
    color: #3b82f6;
}

.stat-label {
    font-size: 12px;
    color: #6b7280;
}

.stat-value {
    font-weight: 600;
    color: #111827;
}

.export-buttons {
    display: flex;
    gap: 8px;
    margin-left: auto;
}

.btn-export {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    background: #10b981;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
}

.btn-export:hover {
    background: #059669;
    color: white;
}

.btn-export.pdf {
    background: #ef4444;
}

.btn-export.pdf:hover {
    background: #dc2626;
}

@media (max-width: 1024px) {
    .audit-table {
        display: block;
        overflow-x: auto;
    }
    
    .filter-group {
        flex-direction: column;
    }
    
    .filter-item {
        width: 100%;
    }
    
    .search-box {
        max-width: 100%;
    }
    
    .pagination-container {
        flex-direction: column;
        gap: 1rem;
        align-items: stretch;
    }
    
    .pagination-buttons {
        justify-content: center;
    }
}
</style>

<div class="audit-container">
    <div class="audit-header">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <img src="<?= htmlspecialchars(APP_LOGO_URL) ?>" alt="Logo" style="height: 40px; border-radius: 8px;" />
                <div>
                    <h2 style="margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fa fa-shield-alt"></i>
                        Audit Trail
                    </h2>
                    <p style="margin: 4px 0 0; opacity: 0.9; font-size: 14px;">
                        Track all system activities and user actions
                    </p>
                </div>
            </div>
            <div class="export-buttons">
                <a href="../api/export.php?resource=audit_logs&format=csv" class="btn-export">
                    <i class="fa fa-file-csv"></i> CSV
                </a>
                <a href="../api/export.php?resource=audit_logs&format=pdf" class="btn-export pdf">
                    <i class="fa fa-file-pdf"></i> PDF
                </a>
            </div>
        </div>
    </div>

    <div class="audit-filters">
        <form method="GET" action="" id="auditFilterForm">
            <input type="hidden" name="page" value="1">
            <div class="filter-group">
                <div class="search-box">
                    <i class="fa fa-search"></i>
                    <input type="text" name="search" placeholder="Search logs..." value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="filter-item">
                    <label><i class="fa fa-tasks"></i> Action</label>
                    <select name="action">
                        <option value="all">All Actions</option>
                        <?php foreach ($actions as $a): ?>
                            <option value="<?= htmlspecialchars($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($a['action'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label><i class="fa fa-cube"></i> Entity</label>
                    <select name="entity">
                        <option value="all">All Entities</option>
                        <?php foreach ($entities as $e): ?>
                            <option value="<?= htmlspecialchars($e['entity']) ?>" <?= $entity === $e['entity'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $e['entity']))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label><i class="fa fa-user"></i> User</label>
                    <select name="user_id">
                        <option value="all">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= htmlspecialchars($u['id']) ?>" <?= $user_id == $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['name'] ?: $u['username']) ?> (<?= htmlspecialchars($u['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label><i class="fa fa-calendar"></i> From Date</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                
                <div class="filter-item">
                    <label><i class="fa fa-calendar"></i> To Date</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn-apply">
                        <i class="fa fa-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn-clear" onclick="clearFilters()">
                        <i class="fa fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php if (count($logs) > 0): ?>
        <div class="stats-bar">
            <div class="stat-item">
                <i class="fa fa-history"></i>
                <div>
                    <div class="stat-label">Total Logs</div>
                    <div class="stat-value"><?= number_format($total_count) ?></div>
                </div>
            </div>
            <div class="stat-item">
                <i class="fa fa-user-clock"></i>
                <div>
                    <div class="stat-label">Showing</div>
                    <div class="stat-value">
                        <?= number_format(min($per_page, count($logs))) ?> of <?= number_format($total_count) ?>
                    </div>
                </div>
            </div>
            <div class="stat-item">
                <i class="fa fa-calendar-day"></i>
                <div>
                    <div class="stat-label">Page</div>
                    <div class="stat-value">
                        <?= $page ?> of <?= $total_pages ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="logrows">
                    <?php foreach ($logs as $l): 
                        $actionClass = 'badge-' . $l['action'];
                        $iconClass = $actionIcons[$l['action']] ?? 'fa-circle';
                        $entityIcon = $entityIcons[$l['entity']] ?? 'fa-cube';
                        $roleClass = 'badge-' . ($l['user_role'] ?? 'non_staff');
                        $details = json_decode($l['details'] ?? '', true) ?: $l['details'];
                        $isJson = is_array($details);
                    ?>
                        <tr>
                            <td class="time-cell">
                                <div style="font-weight: 500;"><?= date('M d, Y', strtotime($l['created_at'])) ?></div>
                                <div style="color: #9ca3af; font-size: 12px;"><?= date('H:i:s', strtotime($l['created_at'])) ?></div>
                            </td>
                            <td>
                                <?php if ($l['username']): ?>
                                    <div class="user-badge <?= $roleClass ?>">
                                        <i class="fa fa-user"></i>
                                        <div>
                                            <div style="font-weight: 500;"><?= htmlspecialchars($l['user_name'] ?: $l['username']) ?></div>
                                            <div style="font-size: 11px; opacity: 0.8;">@<?= htmlspecialchars($l['username']) ?></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #9ca3af; font-style: italic;">System</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="audit-badge <?= $actionClass ?>">
                                    <i class="fa <?= $iconClass ?>"></i>
                                    <?= htmlspecialchars(ucfirst($l['action'])) ?>
                                </span>
                            </td>
                            <td class="entity-cell">
                                <i class="fa <?= $entityIcon ?>" style="color: #6b7280;"></i>
                                <span style="font-weight: 500;"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $l['entity']))) ?></span>
                                <?php if ($l['entity_id']): ?>
                                    <span style="color: #9ca3af; font-size: 12px;">#<?= $l['entity_id'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isJson): ?>
                                    <div class="details-cell">
                                        <?php foreach ($details as $key => $value): ?>
                                            <?php if (!empty($value)): ?>
                                                <div style="margin-bottom: 4px;">
                                                    <span style="color: #3b82f6; font-weight: 500;"><?= htmlspecialchars($key) ?>:</span>
                                                    <span style="color: #111827;"><?= htmlspecialchars(is_array($value) ? json_encode($value) : $value) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="details-cell">
                                        <?= htmlspecialchars($details ?: 'No details') ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div class="pagination-container">
            <div class="pagination-info">
                Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total_count) ?> of <?= number_format($total_count) ?> entries
            </div>
            
            <div class="pagination-buttons">
                <!-- Previous Button -->
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="pagination-btn">
                        <i class="fa fa-chevron-left"></i> Previous
                    </a>
                <?php else: ?>
                    <button class="pagination-btn" disabled>
                        <i class="fa fa-chevron-left"></i> Previous
                    </button>
                <?php endif; ?>
                
                <!-- Page Numbers -->
                <div class="page-numbers">
                    <?php
                    // Show first page
                    if ($page > 3): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="page-link">1</a>
                        <?php if ($page > 4): ?>
                            <span style="padding: 0 8px; color: #9ca3af;">...</span>
                        <?php endif;
                    endif;
                    
                    // Show pages around current page
                    for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                           class="page-link <?= $i == $page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor;
                    
                    // Show last page
                    if ($page < $total_pages - 2): 
                        if ($page < $total_pages - 3): ?>
                            <span style="padding: 0 8px; color: #9ca3af;">...</span>
                        <?php endif; ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>" class="page-link">
                            <?= $total_pages ?>
                        </a>
                    <?php endif; ?>
                </div>
                
                <!-- Next Button -->
                <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="pagination-btn">
                        Next <i class="fa fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <button class="pagination-btn" disabled>
                        Next <i class="fa fa-chevron-right"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fa fa-inbox"></i>
            <h3>No Audit Logs Found</h3>
            <p>No activity logs match your current filters.</p>
            <button class="btn-apply" onclick="clearFilters()">
                <i class="fa fa-times"></i> Clear Filters
            </button>
        </div>
    <?php endif; ?>
</div>

<script>
let lastSeen = 0;
const tbody = document.getElementById('logrows');
let isLoading = false;

// Get latest log ID on page load
<?php if (count($logs) > 0): ?>
lastSeen = <?= $logs[0]['id'] ?>;
<?php endif; ?>

function escapeHtml(s) { 
    return String(s).replace(/[&<>"']/g, m => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); 
}

function formatDateTime(datetime) {
    const date = new Date(datetime);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return `${diffDays}d ago`;
    
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function addRow(log) {
    const tr = document.createElement('tr');
    const actionClass = 'badge-' + log.action;
    const iconClass = getActionIcon(log.action);
    const entityIcon = getEntityIcon(log.entity);
    const roleClass = 'badge-' + (log.user_role || 'non_staff');
    
    let detailsHtml = '';
    try {
        const details = JSON.parse(log.details || '{}');
        if (Object.keys(details).length > 0) {
            detailsHtml = '<div class="details-cell">';
            for (const [key, value] of Object.entries(details)) {
                if (value) {
                    detailsHtml += `<div style="margin-bottom: 4px;">
                        <span style="color: #3b82f6; font-weight: 500;">${escapeHtml(key)}:</span>
                        <span style="color: #111827;">${escapeHtml(typeof value === 'object' ? JSON.stringify(value) : value)}</span>
                    </div>`;
                }
            }
            detailsHtml += '</div>';
        } else {
            detailsHtml = `<div class="details-cell">${escapeHtml(log.details || 'No details')}</div>`;
        }
    } catch {
        detailsHtml = `<div class="details-cell">${escapeHtml(log.details || 'No details')}</div>`;
    }
    
    tr.innerHTML = `
        <td class="time-cell">
            <div style="font-weight: 500;">${formatDateTime(log.created_at)}</div>
            <div style="color: #9ca3af; font-size: 12px;">${new Date(log.created_at).toLocaleTimeString('en-US', {hour12: false})}</div>
        </td>
        <td>
            ${log.username ? `
                <div class="user-badge ${roleClass}">
                    <i class="fa fa-user"></i>
                    <div>
                        <div style="font-weight: 500;">${escapeHtml(log.user_name || log.username)}</div>
                        <div style="font-size: 11px; opacity: 0.8;">@${escapeHtml(log.username)}</div>
                    </div>
                </div>
            ` : `<span style="color: #9ca3af; font-style: italic;">System</span>`}
        </td>
        <td>
            <span class="audit-badge ${actionClass}">
                <i class="fa ${iconClass}"></i>
                ${escapeHtml(log.action.charAt(0).toUpperCase() + log.action.slice(1))}
            </span>
        </td>
        <td class="entity-cell">
            <i class="fa ${entityIcon}" style="color: #6b7280;"></i>
            <span style="font-weight: 500;">${escapeHtml(log.entity.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()))}</span>
            ${log.entity_id ? `<span style="color: #9ca3af; font-size: 12px;">#${log.entity_id}</span>` : ''}
        </td>
        <td>${detailsHtml}</td>
    `;
    tbody.prepend(tr);
}

function getActionIcon(action) {
    const icons = {
        'login': 'fa-sign-in-alt',
        'logout': 'fa-sign-out-alt',
        'create': 'fa-plus-circle',
        'update': 'fa-edit',
        'delete': 'fa-trash-alt',
        'borrow': 'fa-book-open',
        'return': 'fa-book',
        'reserve': 'fa-calendar-check',
        'extend': 'fa-calendar-plus',
        'report': 'fa-flag',
        'approve': 'fa-check-circle',
        'reject': 'fa-times-circle',
        'damage': 'fa-exclamation-triangle',
        'lost': 'fa-exclamation-circle'
    };
    return icons[action] || 'fa-circle';
}

function getEntityIcon(entity) {
    const icons = {
        'books': 'fa-book',
        'book_copies': 'fa-copy',
        'borrow_logs': 'fa-exchange-alt',
        'reservations': 'fa-calendar-alt',
        'users': 'fa-user',
        'patrons': 'fa-users',
        'categories': 'fa-tags',
        'settings': 'fa-cog',
        'extension_requests': 'fa-clock',
        'lost_damaged_reports': 'fa-exclamation',
        'receipts': 'fa-receipt',
        'notifications': 'fa-bell',
        'audit_logs': 'fa-shield-alt',
        'auth': 'fa-key'
    };
    return icons[entity] || 'fa-cube';
}

async function pollNewLogs() {
    try {
        const res = await fetch('../api/audit_logs.php?last_id=' + lastSeen);
        if (!res.ok) return;
        const newLogs = await res.json();
        
        if (newLogs.length > 0) {
            newLogs.forEach(log => {
                if (log.id > lastSeen) {
                    addRow(log);
                    lastSeen = Math.max(lastSeen, log.id);
                }
            });
            
            // Show notification for new logs
            if (newLogs.length === 1) {
                showNotification('New audit log recorded');
            } else if (newLogs.length > 1) {
                showNotification(newLogs.length + ' new audit logs');
            }
        }
    } catch(e) {
        console.error('Polling error:', e);
    }
}

function showNotification(message) {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #10b981;
        color: white;
        padding: 12px 16px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 1000;
        animation: slideIn 0.3s ease-out;
        display: flex;
        align-items: center;
        gap: 8px;
    `;
    notification.innerHTML = `
        <i class="fa fa-bell"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-in';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

function clearFilters() {
    window.location.href = window.location.pathname;
}

// Start polling for new logs
setInterval(pollNewLogs, 5000);

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
    
    .highlight-new {
        animation: highlight 2s ease-out;
    }
    
    @keyframes highlight {
        0% { background-color: rgba(59, 130, 246, 0.1); }
        100% { background-color: transparent; }
    }
`;
document.head.appendChild(style);
</script>

<?php include __DIR__ . '/_footer.php'; ?>