<?php
// Combined User Management page with tabs for both user management and top users statistics.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

// Only admins, librarians and assistants should access this page
if (!in_array(current_user()['role'], ['admin','librarian','assistant'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = DB::conn();

// Fetch users for management tab
$users = $pdo->query("SELECT id, username, email, name, phone, role, status, created_at FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Fetch aggregated borrow stats for top users tab
$topUsers = $pdo->query(
    'SELECT p.id, p.name, p.library_id, p.email, p.phone, p.semester, p.department, p.status,
            COUNT(bl.id) AS total_borrowed,
            SUM(CASE WHEN bl.returned_at IS NOT NULL THEN 1 ELSE 0 END) AS total_returned,
            GROUP_CONCAT(DISTINCT CASE WHEN bl.returned_at IS NULL THEN b.title END SEPARATOR ", ") AS current_books
     FROM patrons p
     LEFT JOIN borrow_logs bl ON bl.patron_id = p.id
     LEFT JOIN books b ON b.id = bl.book_id
     GROUP BY p.id
     ORDER BY total_borrowed DESC, p.name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/_header.php';
?>

<div class="page-header">
    <h2><i class="fa fa-users"></i> User Management</h2>
    <div class="header-actions">
        
    </div>
</div>

<div class="tabs-container">
    <div class="tabs">
        <button class="tab-btn active" data-tab="manage-users">
            <i class="fa fa-user-cog"></i> Manage Users
        </button>
        <button class="tab-btn" data-tab="top-users">
            <i class="fa fa-chart-line"></i> Top Users
        </button>
    </div>
    
    <div class="tab-content active" id="manage-users">
        <div class="card">
            <div class="card-header">
                <h3><i class="fa fa-user-friends"></i> User Accounts</h3>
                <div class="card-search">
                    <input type="text" id="userSearch" placeholder="Search users..." class="search-input">
                    <i class="fa fa-search"></i>
                </div>
            </div>
            
            <?php if (empty($users)): ?>
                <div class="empty-state">
                    <i class="fa fa-user-slash"></i>
                    <h3>No Users Found</h3>
                    <p>There are no user accounts in the system yet.</p>
                    <button class="btn btn-primary" onclick="showAddUserModal()">
                        <i class="fa fa-plus"></i> Add First User
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="usersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): 
                                $roleClass = 'role-' . $u['role'];
                                $statusClass = $u['status'] === 'active' ? 'status-active' : 'status-disabled';
                            ?>
                            <tr>
                                <td class="user-id-cell">#<?= htmlspecialchars($u['id']) ?></td>
                                <td>
                                    <div class="user-info">
                                        <div class="user-avatar">
                                            <?= strtoupper(substr($u['name'] ?? 'U', 0, 1)) ?>
                                        </div>
                                        <div class="user-details">
                                            <strong><?= htmlspecialchars($u['name'] ?? 'N/A') ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($u['username']) ?></td>
                                <td class="email-cell"><?= htmlspecialchars($u['email']) ?></td>
                                <td><?= htmlspecialchars($u['phone'] ?: '-') ?></td>
                                <td>
                                    <span class="role-badge <?= $roleClass ?>">
                                        <?= htmlspecialchars($u['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?= $statusClass ?>">
                                        <i class="fa fa-circle"></i>
                                        <?= htmlspecialchars($u['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                <td class="table-actions">
                                    <button class="action-btn btn-view view-user-btn" data-user-id="<?= (int)$u['id'] ?>" title="View Details">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                    <button class="action-btn btn-edit edit-user-btn" data-user-id="<?= (int)$u['id'] ?>" title="Edit User">
                                        <i class="fa fa-pen"></i>
                                    </button>
                                    <button class="action-btn btn-delete delete-user-btn" data-user-id="<?= (int)$u['id'] ?>" title="Delete User">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">
                    <div class="table-info">
                        <i class="fa fa-users"></i>
                        Showing <?= count($users) ?> user<?= count($users) !== 1 ? 's' : '' ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="tab-content" id="top-users">
        <div class="card">
            <div class="card-header">
                <h3><i class="fa fa-chart-line"></i> Top Users & Borrowing Statistics</h3>
                <div class="card-search">
                    <input type="text" id="topUserSearch" placeholder="Search top users..." class="search-input">
                    <i class="fa fa-search"></i>
                </div>
            </div>
            
            <div class="stats-summary">
                <div class="stat-card stat-primary">
                    <div class="stat-icon">
                        <i class="fa fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= count($topUsers) ?></div>
                        <div class="stat-label">Total Patrons</div>
                    </div>
                </div>
                <div class="stat-card stat-info">
                    <div class="stat-icon">
                        <i class="fa fa-book"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= array_sum(array_column($topUsers, 'total_borrowed')) ?></div>
                        <div class="stat-label">Total Books Borrowed</div>
                    </div>
                </div>
                <div class="stat-card stat-success">
                    <div class="stat-icon">
                        <i class="fa fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= array_sum(array_column($topUsers, 'total_returned')) ?></div>
                        <div class="stat-label">Total Books Returned</div>
                    </div>
                </div>
                <div class="stat-card stat-warning">
                    <div class="stat-icon">
                        <i class="fa fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value">
                            <?= count(array_filter($topUsers, function($row) { 
                                return !empty(trim($row['current_books'] ?? '')); 
                            })) ?>
                        </div>
                        <div class="stat-label">Active Borrowers</div>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <?php if ($topUsers): ?>
                    <div class="table-responsive">
                        <table class="data-table" id="topUsersTable">
                            <thead>
                                <tr>
                                    <th>User Information</th>
                                    <th>Contact Details</th>
                                    <th>Academic Info</th>
                                    <th>Status</th>
                                    <th>Borrow Statistics</th>
                                    <th>Currently Borrowed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topUsers as $r): ?>
                                    <?php
                                    $currentBooks = array_filter(explode(', ', $r['current_books'] ?? ''), function($book) {
                                        return !empty(trim($book));
                                    });
                                    ?>
                                    <tr>
                                        <!-- User Information -->
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar user-avatar-top">
                                                    <?= strtoupper(substr($r['name'] ?? 'U', 0, 1)) ?>
                                                </div>
                                                <div class="user-details">
                                                    <div class="user-name"><?= htmlspecialchars($r['name']) ?></div>
                                                    <div class="user-id">ID: <?= htmlspecialchars($r['library_id']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- Contact Details -->
                                        <td>
                                            <div class="user-contact">
                                                <div class="email"><?= htmlspecialchars($r['email'] ?? 'N/A') ?></div>
                                                <div class="phone"><?= htmlspecialchars($r['phone'] ?? 'N/A') ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Academic Info -->
                                        <td>
                                            <div class="user-academic">
                                                <div class="department"><?= htmlspecialchars($r['department'] ?? 'N/A') ?></div>
                                                <div class="semester"><?= htmlspecialchars($r['semester'] ?? 'N/A') ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Status -->
                                        <td>
                                            <?php if ($r['status'] == 'active'): ?>
                                                <span class="status-badge status-active">
                                                    <i class="fa fa-check-circle"></i>
                                                    Active
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-disabled">
                                                    <i class="fa fa-times-circle"></i>
                                                    Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Borrow Statistics -->
                                        <td>
                                            <div class="stat-numbers">
                                                <div class="borrow-stat">
                                                    <div class="stat-count total"><?= (int)$r['total_borrowed'] ?></div>
                                                    <div class="stat-label-small">Borrowed</div>
                                                </div>
                                                <div class="borrow-stat">
                                                    <div class="stat-count returned"><?= (int)$r['total_returned'] ?></div>
                                                    <div class="stat-label-small">Returned</div>
                                                </div>
                                                <div class="borrow-stat">
                                                    <div class="stat-count pending">
                                                        <?= (int)$r['total_borrowed'] - (int)$r['total_returned'] ?>
                                                    </div>
                                                    <div class="stat-label-small">Active</div>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- Currently Borrowed Books -->
                                        <td>
                                            <div class="current-books">
                                                <?php if (!empty($currentBooks)): ?>
                                                    <div class="book-list">
                                                        <?php foreach (array_slice($currentBooks, 0, 3) as $book): ?>
                                                            <span class="book-tag"><?= htmlspecialchars($book) ?></span>
                                                        <?php endforeach; ?>
                                                        <?php if (count($currentBooks) > 3): ?>
                                                            <span class="book-tag book-tag-more">
                                                                +<?= count($currentBooks) - 3 ?> more
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="no-books">No active loans</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <div class="table-info">
                            <i class="fa fa-chart-bar"></i>
                            Showing <?= count($topUsers) ?> top user<?= count($topUsers) !== 1 ? 's' : '' ?> by borrowing activity
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa fa-users fa-3x"></i>
                        <h3>No Users Found</h3>
                        <p>There are no patrons registered in the system yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- View User Modal -->
<div id="viewUserModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fa fa-user-circle"></i> User Details</h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="user-profile">
                <div class="user-avatar-large">
                    <span id="viewUserInitial">U</span>
                </div>
                <div class="user-profile-info">
                    <h3 id="viewUserName">Loading...</h3>
                    <p class="user-role" id="viewUserRole">Loading...</p>
                </div>
            </div>
            
            <div class="user-details-grid">
                <div class="detail-item">
                    <div class="detail-label">
                        <i class="fa fa-user"></i> Username
                    </div>
                    <div class="detail-value" id="viewUserUsername">-</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">
                        <i class="fa fa-envelope"></i> Email
                    </div>
                    <div class="detail-value" id="viewUserEmail">-</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">
                        <i class="fa fa-phone"></i> Phone
                    </div>
                    <div class="detail-value" id="viewUserPhone">-</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">
                        <i class="fa fa-calendar"></i> Created
                    </div>
                    <div class="detail-value" id="viewUserCreated">-</div>
                </div>
                <div class="detail-item full-width">
                    <div class="detail-label">
                        <i class="fa fa-shield-alt"></i> Status
                    </div>
                    <div class="detail-value">
                        <span class="status-badge" id="viewUserStatus">-</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close</button>
            <button type="button" class="btn btn-primary" id="viewUserEditBtn">
                <i class="fa fa-edit"></i> Edit User
            </button>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fa fa-user-edit"></i> Edit User</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form id="editUserForm">
            <div class="modal-body">
                <input type="hidden" id="userEditId" />
                
                <div class="form-group">
                    <label for="userEditName">Full Name *</label>
                    <input type="text" id="userEditName" class="form-control" required>
                    <div class="form-hint">User's full name</div>
                </div>
                
                <div class="form-group">
                    <label for="userEditUsername">Username *</label>
                    <input type="text" id="userEditUsername" class="form-control" required>
                    <div class="form-hint">Unique login username</div>
                </div>
                
                <div class="form-group">
                    <label for="userEditEmail">Email Address *</label>
                    <input type="email" id="userEditEmail" class="form-control" required>
                    <div class="form-hint">Valid email address</div>
                </div>
                
                <div class="form-group">
                    <label for="userEditPhone">Phone Number</label>
                    <input type="tel" id="userEditPhone" class="form-control">
                    <div class="form-hint">Optional contact number</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="userEditRole">Role *</label>
                        <select id="userEditRole" class="form-control" required>
                            <option value="">Select Role</option>
                            <option value="admin">Administrator</option>
                            <option value="librarian">Librarian</option>
                            <option value="assistant">Assistant</option>
                            <option value="teacher">Teacher</option>
                            <option value="student">Student</option>
                            <option value="non_staff">Non-Staff</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="userEditStatus">Status *</label>
                        <select id="userEditStatus" class="form-control" required>
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="userEditPassword">New Password</label>
                    <input type="password" id="userEditPassword" class="form-control" placeholder="Leave blank to keep current">
                    <div class="form-hint">Leave blank to keep current password</div>
                </div>
                
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i>
                    Fields marked with * are required
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fa fa-user-plus"></i> Add New User</h3>
            <button class="modal-close" onclick="closeAddModal()">&times;</button>
        </div>
        <form id="addUserForm">
            <div class="modal-body">
                <div class="form-group">
                    <label for="userAddName">Full Name *</label>
                    <input type="text" id="userAddName" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="userAddUsername">Username *</label>
                    <input type="text" id="userAddUsername" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="userAddEmail">Email Address *</label>
                    <input type="email" id="userAddEmail" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="userAddPhone">Phone Number</label>
                    <input type="tel" id="userAddPhone" class="form-control">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="userAddRole">Role *</label>
                        <select id="userAddRole" class="form-control" required>
                            <option value="">Select Role</option>
                            <option value="admin">Administrator</option>
                            <option value="librarian">Librarian</option>
                            <option value="assistant">Assistant</option>
                            <option value="teacher">Teacher</option>
                            <option value="student">Student</option>
                            <option value="non_staff">Non-Staff</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="userAddStatus">Status *</label>
                        <select id="userAddStatus" class="form-control" required>
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="userAddPassword">Password *</label>
                    <input type="password" id="userAddPassword" class="form-control" required minlength="6">
                </div>
                
                <div class="form-group">
                    <label for="userAddConfirmPassword">Confirm Password *</label>
                    <input type="password" id="userAddConfirmPassword" class="form-control" required>
                </div>
                
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i>
                    All fields marked with * are required. Password must be at least 6 characters.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-plus"></i> Create User
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.page-header h2 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #2d3748;
}

.header-actions {
    display: flex;
    gap: 0.5rem;
}

/* Tabs */
.tabs-container {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
}

.tabs {
    display: flex;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 0;
}

.tab-btn {
    padding: 1rem 1.5rem;
    background: none;
    border: none;
    font-size: 0.9375rem;
    font-weight: 500;
    color: #64748b;
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border-bottom: 3px solid transparent;
}

.tab-btn:hover {
    color: #3b82f6;
    background: #f1f5f9;
}

.tab-btn.active {
    color: #3b82f6;
    font-weight: 600;
    background: #fff;
    border-bottom-color: #3b82f6;
}

.tab-content {
    display: none;
    padding: 0;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Card Styles */
.card {
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
}

.card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f9fafb;
}

.card-header h3 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #374151;
}

.card-search {
    position: relative;
    width: 300px;
}

.card-search input {
    width: 100%;
    padding: 0.625rem 1rem 0.625rem 2.5rem;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.card-search input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.card-search i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
}

/* Stats Summary */
.stats-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    padding: 1.5rem;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
}

.stat-card {
    background: #fff;
    border-radius: 8px;
    padding: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    border: 1px solid #e5e7eb;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.stat-primary .stat-icon { background: #dbeafe; color: #1d4ed8; }
.stat-info .stat-icon { background: #e0f2fe; color: #0369a1; }
.stat-success .stat-icon { background: #dcfce7; color: #15803d; }
.stat-warning .stat-icon { background: #fef3c7; color: #d97706; }

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1;
    color: #1f2937;
}

.stat-label {
    font-size: 0.875rem;
    color: #6b7280;
    margin-top: 0.25rem;
}

/* Table Styles */
.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
}

.data-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #475569;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.2s;
}

.data-table tbody tr:hover {
    background-color: #f8fafc;
}

.data-table td {
    padding: 1rem;
    color: #334155;
    vertical-align: middle;
}

.user-id-cell {
    font-weight: 600;
    color: #6b7280;
}

.email-cell {
    color: #3b82f6;
}

/* User Info Styles */
.user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 14px;
    flex-shrink: 0;
}

.user-avatar-large {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 2rem;
    margin: 0 auto 1.5rem;
}

.user-avatar-top {
    width: 40px;
    height: 40px;
    font-size: 16px;
}

.user-details {
    flex: 1;
    min-width: 0;
}

.user-name {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.9375rem;
    margin-bottom: 0.125rem;
}

.user-id {
    font-size: 0.8125rem;
    color: #64748b;
}

/* Status & Role Badges */
.role-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.role-admin { background: #fee2e2; color: #dc2626; }
.role-librarian { background: #dbeafe; color: #2563eb; }
.role-assistant { background: #f0f9ff; color: #0ea5e9; }
.role-teacher { background: #f0fdf4; color: #16a34a; }
.role-student { background: #fef3c7; color: #d97706; }
.role-non_staff { background: #f3f4f6; color: #6b7280; }

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.status-active { 
    background: #d1fae5; 
    color: #065f46; 
}

.status-active i { 
    color: #10b981; 
    font-size: 0.625rem;
}

.status-disabled { 
    background: #fee2e2; 
    color: #991b1b; 
}

.status-disabled i { 
    color: #ef4444; 
    font-size: 0.625rem;
}

/* Table Actions */
.table-actions {
    display: flex;
    gap: 0.375rem;
    justify-content: flex-start;
}

.action-btn {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.action-btn:hover {
    transform: translateY(-1px);
}

.btn-view { 
    background: #dbeafe; 
    color: #2563eb; 
}

.btn-view:hover { 
    background: #bfdbfe; 
}

.btn-edit { 
    background: #fef3c7; 
    color: #d97706; 
}

.btn-edit:hover { 
    background: #fde68a; 
}

.btn-delete { 
    background: #fee2e2; 
    color: #dc2626; 
}

.btn-delete:hover { 
    background: #fecaca; 
}

/* Top Users Specific */
.user-contact {
    font-size: 0.875rem;
    line-height: 1.5;
}

.user-contact .email {
    color: #3b82f6;
    word-break: break-all;
}

.user-contact .phone {
    color: #64748b;
}

.user-academic {
    font-size: 0.875rem;
    line-height: 1.5;
}

.user-academic .department {
    font-weight: 500;
    color: #1e293b;
}

.user-academic .semester {
    color: #64748b;
    font-size: 0.8125rem;
}

.stat-numbers {
    display: flex;
    gap: 0.75rem;
    align-items: center;
}

.borrow-stat {
    text-align: center;
    min-width: 60px;
}

.stat-count {
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1;
}

.stat-count.total { color: #3b82f6; }
.stat-count.returned { color: #10b981; }
.stat-count.pending { color: #f59e0b; }

.stat-label-small {
    font-size: 0.75rem;
    color: #64748b;
    margin-top: 0.125rem;
}

.current-books {
    max-width: 300px;
    font-size: 0.875rem;
    line-height: 1.5;
}

.book-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-top: 0.25rem;
}

.book-tag {
    background: #e0e7ff;
    color: #3730a3;
    padding: 0.25rem 0.625rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.book-tag-more {
    background: #f3f4f6;
    color: #6b7280;
}

.no-books {
    color: #94a3b8;
    font-style: italic;
    font-size: 0.875rem;
}

/* Table Footer */
.table-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}

.table-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #6b7280;
    font-size: 0.875rem;
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.375rem;
    font-weight: 500;
    color: #374151;
    font-size: 0.875rem;
}

.form-control {
    width: 100%;
    padding: 0.625rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.875rem;
    transition: border-color 0.15s;
}

.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-hint {
    font-size: 0.75rem;
    color: #6b7280;
    margin-top: 0.25rem;
}

.alert {
    padding: 0.75rem 1rem;
    border-radius: 6px;
    margin: 1rem 0;
    font-size: 0.875rem;
}

.alert-info {
    background: #eff6ff;
    border: 1px solid #dbeafe;
    color: #1e40af;
}

.alert i {
    margin-right: 0.5rem;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    align-items: center;
    justify-content: center;
    z-index: 1000;
    backdrop-filter: blur(4px);
}

.modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    animation: modalSlideIn 0.3s ease;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    flex-shrink: 0;
}

.modal-header h3 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #1f2937;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #6b7280;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: background-color 0.2s;
}

.modal-close:hover {
    background: #f3f4f6;
    color: #374151;
}

.modal-body {
    padding: 1.5rem;
    overflow-y: auto;
    flex: 1;
}

.modal-footer {
    padding: 1.25rem 1.5rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    flex-shrink: 0;
}

/* User Profile in View Modal */
.user-profile {
    text-align: center;
    margin-bottom: 2rem;
}

.user-profile-info h3 {
    margin: 0 0 0.5rem 0;
    color: #1f2937;
}

.user-role {
    color: #6b7280;
    font-size: 0.875rem;
    margin: 0;
}

.user-details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-top: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-item.full-width {
    grid-column: 1 / -1;
}

.detail-label {
    font-size: 0.75rem;
    color: #6b7280;
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.detail-value {
    font-size: 0.9375rem;
    color: #1f2937;
    font-weight: 500;
}

/* Button Styles */
.btn {
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
    font-size: 0.875rem;
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: #6b7280;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 1rem;
    color: #d1d5db;
}

.empty-state h3 {
    margin: 0 0 0.5rem;
    color: #374151;
    font-size: 1.25rem;
}

.empty-state p {
    margin: 0 0 1.5rem;
    color: #6b7280;
    font-size: 0.9375rem;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .tabs {
        overflow-x: auto;
        white-space: nowrap;
    }
    
    .tab-btn {
        padding: 0.75rem 1rem;
        font-size: 0.875rem;
    }
    
    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .card-search {
        width: 100%;
    }
    
    .stats-summary {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 0.75rem;
    }
    
    .modal-content {
        width: 95%;
        margin: 1rem;
        max-height: 85vh;
    }
    
    .user-details-grid {
        grid-template-columns: 1fr;
    }
    
    .table-actions {
        flex-wrap: wrap;
    }
}

@media (max-width: 480px) {
    .modal-body {
        padding: 1rem;
    }
    
    .modal-footer {
        flex-direction: column;
    }
    
    .modal-footer .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
(function(){
    // Tab switching functionality
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.getAttribute('data-tab');
            
            // Update active tab button
            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            // Show active tab content
            tabContents.forEach(content => {
                content.classList.remove('active');
                if (content.id === tabId) {
                    content.classList.add('active');
                }
            });
        });
    });
    
    // Search functionality for manage users
    const userSearch = document.getElementById('userSearch');
    const usersTable = document.getElementById('usersTable');
    
    if (userSearch && usersTable) {
        userSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = usersTable.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    }
    
    // Search functionality for top users
    const topUserSearch = document.getElementById('topUserSearch');
    const topUsersTable = document.getElementById('topUsersTable');
    
    if (topUserSearch && topUsersTable) {
        topUserSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = topUsersTable.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    }
    
    // Modal references
    const viewModal = document.getElementById('viewUserModal');
    const editModal = document.getElementById('editUserModal');
    const addModal = document.getElementById('addUserModal');
    let currentId = null;
    
    // Modal functions
    function closeViewModal() {
        viewModal.style.display = 'none';
    }
    
    function closeEditModal() {
        editModal.style.display = 'none';
        document.getElementById('editUserForm').reset();
    }
    
    function closeAddModal() {
        addModal.style.display = 'none';
        document.getElementById('addUserForm').reset();
    }
    
    function showAddUserModal() {
        addModal.style.display = 'flex';
    }
    
    // View user button handlers
    document.querySelectorAll('.view-user-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.getAttribute('data-user-id');
            currentId = id;
            
            try {
                const res = await fetch('../api/dispatch.php?resource=users&id=' + id, {
                    credentials: 'same-origin'
                });
                
                if (!res.ok) throw new Error('Failed to fetch user data');
                
                const user = await res.json();
                
                // Update view modal content
                document.getElementById('viewUserInitial').textContent = 
                    (user.name || 'U').charAt(0).toUpperCase();
                document.getElementById('viewUserName').textContent = user.name || 'N/A';
                document.getElementById('viewUserRole').textContent = user.role || 'N/A';
                document.getElementById('viewUserUsername').textContent = user.username || 'N/A';
                document.getElementById('viewUserEmail').textContent = user.email || 'N/A';
                document.getElementById('viewUserPhone').textContent = user.phone || 'N/A';
                document.getElementById('viewUserCreated').textContent = 
                    new Date(user.created_at).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    });
                
                // Update status badge
                const statusBadge = document.getElementById('viewUserStatus');
                statusBadge.className = 'status-badge ' + (user.status === 'active' ? 'status-active' : 'status-disabled');
                statusBadge.innerHTML = `<i class="fa fa-circle"></i> ${user.status}`;
                
                // Update edit button
                document.getElementById('viewUserEditBtn').onclick = () => {
                    closeViewModal();
                    setTimeout(() => showEditUserModal(user), 100);
                };
                
                // Show modal
                viewModal.style.display = 'flex';
            } catch(e) {
                console.error(e);
                alert('Failed to load user details');
            }
        });
    });
    
    // Edit user button handlers
    document.querySelectorAll('.edit-user-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.getAttribute('data-user-id');
            
            try {
                const res = await fetch('../api/dispatch.php?resource=users&id=' + id, {
                    credentials: 'same-origin'
                });
                
                if (!res.ok) throw new Error('Failed to fetch user data');
                
                const user = await res.json();
                showEditUserModal(user);
            } catch(e) {
                console.error(e);
                alert('Failed to load user data');
            }
        });
    });
    
    function showEditUserModal(user) {
        currentId = user.id;
        document.getElementById('userEditId').value = currentId;
        
        document.getElementById('userEditName').value = user.name || '';
        document.getElementById('userEditUsername').value = user.username || '';
        document.getElementById('userEditEmail').value = user.email || '';
        document.getElementById('userEditPhone').value = user.phone || '';
        document.getElementById('userEditRole').value = user.role || '';
        document.getElementById('userEditStatus').value = user.status || '';
        
        editModal.style.display = 'flex';
    }
    
    // Delete user button handlers
    document.querySelectorAll('.delete-user-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.getAttribute('data-user-id');
            
            if (!confirm('Are you sure you want to delete this user?\nThis action cannot be undone.')) {
                return;
            }
            
            const csrf = sessionStorage.getItem('csrf') || '';
            
            try {
                const res = await fetch('../api/dispatch.php?resource=users&id=' + id, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': csrf
                    }
                });
                
                const out = await res.json();
                
                if (!res.ok) {
                    throw new Error(out.error || 'Delete failed');
                }
                
                // Show success message and reload
                alert('User deleted successfully');
                window.location.reload();
            } catch(err) {
                console.error(err);
                alert(err.message || 'Failed to delete user');
            }
        });
    });
    
    // Edit form submission
    document.getElementById('editUserForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const csrf = sessionStorage.getItem('csrf') || '';
        const payload = {
            name: document.getElementById('userEditName').value.trim(),
            username: document.getElementById('userEditUsername').value.trim(),
            email: document.getElementById('userEditEmail').value.trim(),
            phone: document.getElementById('userEditPhone').value.trim(),
            role: document.getElementById('userEditRole').value,
            status: document.getElementById('userEditStatus').value
        };
        
        // Add password if provided
        const password = document.getElementById('userEditPassword').value.trim();
        if (password) {
            payload.password = password;
        }
        
        try {
            const res = await fetch('../api/dispatch.php?resource=users&id=' + currentId, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify(payload)
            });
            
            const out = await res.json();
            
            if (!res.ok) {
                throw new Error(out.error || 'Update failed');
            }
            
            alert('User updated successfully');
            window.location.reload();
        } catch(err) {
            console.error(err);
            alert(err.message || 'Failed to update user');
        }
    });
    
    // Add form submission
    document.getElementById('addUserForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const password = document.getElementById('userAddPassword').value;
        const confirmPassword = document.getElementById('userAddConfirmPassword').value;
        
        if (password !== confirmPassword) {
            alert('Passwords do not match');
            return;
        }
        
        if (password.length < 6) {
            alert('Password must be at least 6 characters');
            return;
        }
        
        const csrf = sessionStorage.getItem('csrf') || '';
        const payload = {
            name: document.getElementById('userAddName').value.trim(),
            username: document.getElementById('userAddUsername').value.trim(),
            email: document.getElementById('userAddEmail').value.trim(),
            phone: document.getElementById('userAddPhone').value.trim(),
            role: document.getElementById('userAddRole').value,
            status: document.getElementById('userAddStatus').value,
            password: password
        };
        
        try {
            const res = await fetch('../api/dispatch.php?resource=users', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify(payload)
            });
            
            const out = await res.json();
            
            if (!res.ok) {
                throw new Error(out.error || 'Create failed');
            }
            
            alert('User created successfully');
            window.location.reload();
        } catch(err) {
            console.error(err);
            alert(err.message || 'Failed to create user');
        }
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', (e) => {
        if (e.target === viewModal) closeViewModal();
        if (e.target === editModal) closeEditModal();
        if (e.target === addModal) closeAddModal();
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeViewModal();
            closeEditModal();
            closeAddModal();
        }
    });
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>