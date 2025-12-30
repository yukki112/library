<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$resource = strtolower($_GET['resource'] ?? '');
if (!$resource) { header('Location: dashboard.php'); exit; }

// UI field schema per resource
$SCHEMA = [
    'users' => [
        'title' => 'Users',
        'fields' => [
            ['name'=>'username','label'=>'Username','type'=>'text','required'=>true],
            ['name'=>'email','label'=>'Email','type'=>'email','required'=>true],
            ['name'=>'name','label'=>'Name','type'=>'text'],
            ['name'=>'phone','label'=>'Phone','type'=>'text'],
            ['name'=>'role','label'=>'Role','type'=>'select','options'=>['admin','librarian','assistant','student','non_staff']],
            ['name'=>'status','label'=>'Status','type'=>'select','options'=>['active','disabled']],
            ['name'=>'password','label'=>'Password','type'=>'password'],
        ],
        'columns' => ['id','username','email','role','status','created_at']
    ],
    'patrons' => [
        'title' => 'Patrons',
        'fields' => [
            ['name'=>'name','label'=>'Name','type'=>'text','required'=>true],
            ['name'=>'library_id','label'=>'Library ID','type'=>'text','required'=>true],
            ['name'=>'email','label'=>'Email','type'=>'email'],
            ['name'=>'phone','label'=>'Phone','type'=>'text'],
            ['name'=>'membership_date','label'=>'Membership Date','type'=>'date'],
            ['name'=>'status','label'=>'Status','type'=>'select','options'=>['active','inactive']],
        ],
        'columns' => ['id','name','library_id','email','status','created_at']
    ],
    'books' => [
        'title' => 'Books',
        'fields' => [
            ['name'=>'title','label'=>'Title','type'=>'text','required'=>true],
            ['name'=>'author','label'=>'Author','type'=>'text','required'=>true],
            ['name'=>'isbn','label'=>'ISBN','type'=>'text'],
            ['name'=>'category','label'=>'Category','type'=>'text'],
            ['name'=>'publisher','label'=>'Publisher','type'=>'text'],
            ['name'=>'year_published','label'=>'Year','type'=>'number'],
            ['name'=>'total_copies','label'=>'Total Copies','type'=>'number'],
            ['name'=>'available_copies','label'=>'Available','type'=>'number'],
            ['name'=>'description','label'=>'Description','type'=>'textarea'],
            ['name'=>'is_active','label'=>'Active','type'=>'select','options'=>[1,0]],
        ],
        'columns' => ['id','title','author','isbn','available_copies','total_copies']
    ],
    'ebooks' => [
        'title' => 'Ebooks',
        'fields' => [
            ['name'=>'book_id','label'=>'Book ID','type'=>'number','required'=>true],
            ['name'=>'file_path','label'=>'File Path','type'=>'text','required'=>true],
            ['name'=>'file_format','label'=>'Format','type'=>'text','required'=>true],
            ['name'=>'is_active','label'=>'Active','type'=>'select','options'=>[1,0]],
            ['name'=>'description','label'=>'Description','type'=>'textarea'],
        ],
        'columns' => ['id','book_id','file_format','is_active','created_at']
    ],
    'borrow_logs' => [
        'title' => 'Borrow Logs',
        'fields' => [
            ['name'=>'book_id','label'=>'Book ID','type'=>'number','required'=>true],
            ['name'=>'patron_id','label'=>'Library ID','type'=>'number','required'=>true],
            ['name'=>'borrowed_at','label'=>'Borrowed At','type'=>'datetime-local','required'=>true],
            ['name'=>'due_date','label'=>'Due Date','type'=>'datetime-local','required'=>true],
            ['name'=>'returned_at','label'=>'Returned At','type'=>'datetime-local'],
            ['name'=>'status','label'=>'Status','type'=>'select','options'=>['borrowed','returned','overdue']],
            ['name'=>'notes','label'=>'Notes','type'=>'textarea'],
        ],
        // Include computed late_fee in the listing
        'columns' => ['id','book_id','patron_id','status','borrowed_at','due_date','late_fee']
    ],
    'reservations' => [
        'title' => 'Reservations',
        // The reservation form now supports additional statuses (pending, approved, declined) to support approval flows.
        'fields' => [
            // Provide a placeholder so the student knows they must enter a Book ID manually
            ['name'=>'book_id','label'=>'Book ID','type'=>'number','required'=>true,'placeholder'=>'Enter Book ID'],
            ['name'=>'patron_id','label'=>'Library ID','type'=>'number','required'=>true],
            ['name'=>'reserved_at','label'=>'Reserved At','type'=>'datetime-local'],
            ['name'=>'status','label'=>'Status','type'=>'select','options'=>['pending','approved','fulfilled','cancelled','expired','declined']],
            // Use "Due Date" to clearly indicate when the reservation expires
            ['name'=>'expiration_date','label'=>'Due Date','type'=>'date'],
        ],
        // Show expiration_date/due date in the table so users and staff know
        // when the reservation expires.  The patron_id column may still be
        // hidden for students and non‑staff below.
        'columns' => ['id','book_id','patron_id','status','reserved_at','expiration_date']
    ],

    // Data submissions: allow students to send messages/data to administrators.  Admins and staff can review
    // these submissions and update their status.  Students will only see the fields to enter a title and
    // content; patron_id and status are managed automatically and by staff.
    'lost_damaged_reports' => [
        'title' => 'Lost/Damaged Reports',
        'fields' => [
            ['name'=>'book_id','label'=>'Book ID','type'=>'number','required'=>true],
            ['name'=>'patron_id','label'=>'Library ID','type'=>'number','required'=>true],
            ['name'=>'report_date','label'=>'Report Date','type'=>'date','required'=>true],
            ['name'=>'report_type','label'=>'Type','type'=>'select','options'=>['lost','damaged']],
            ['name'=>'severity','label'=>'Severity','type'=>'select','options'=>['minor','moderate','severe']],
            ['name'=>'description','label'=>'Description','type'=>'textarea'],
            ['name'=>'fee_charged','label'=>'Fee','type'=>'number','step'=>'0.01'],
            ['name'=>'status','label'=>'Status','type'=>'select','options'=>['pending','resolved']],
        ],
        'columns' => ['id','book_id','patron_id','report_type','status','report_date']
    ],
    'clearances' => [
        'title' => 'Clearances',
        'fields' => [
            ['name'=>'patron_id','label'=>'Library ID','type'=>'number','required'=>true],
            ['name'=>'clearance_date','label'=>'Date','type'=>'date','required'=>true],
            ['name'=>'status','label'=>'Status','type'=>'select','options'=>['pending','cleared','blocked']],
            ['name'=>'notes','label'=>'Notes','type'=>'textarea'],
        ],
        'columns' => ['id','patron_id','status','clearance_date']
    ],
];

if (!isset($SCHEMA[$resource])) { header('Location: dashboard.php'); exit; }
$cfg = $SCHEMA[$resource];
$user = current_user();
// Determine if the current logged in user is a student or non‑staff. This is used later to adjust the
// reservation form and available actions. When true, the reservation form will only show the book
// selection and the labels/buttons will change from "Create / Edit" to "Book".
$isStudentRole = in_array($user['role'] ?? '', ['student','non_staff'], true);
// Dynamically adjust columns and fields:
// Remove patron_id column for certain resources. Students and non‑staff should not see or edit the
// patron ID for reservations, borrow logs, lost/damaged reports or clearances.  Staff need to see it.
if (in_array($resource, ['reservations','borrow_logs','lost_damaged_reports','clearances'], true)) {
    // Students and non-staff should not see or edit the patron ID, but staff need to see it.
    if (in_array($user['role'] ?? '', ['student','non_staff'], true)) {
        // Remove 'patron_id' from the list of columns when the user is a student or non‑staff.
        $cfg['columns'] = array_values(array_filter($cfg['columns'], function ($c) {
            return $c !== 'patron_id';
        }));
        // Hide the patron_id field from the form for students and non‑staff.
        $cfg['fields'] = array_values(array_filter($cfg['fields'], function ($f) {
            return ($f['name'] ?? '') !== 'patron_id';
        }));
    }
}
// Remove ISBN column from books listing
if ($resource === 'books') {
    $cfg['columns'] = array_values(array_filter($cfg['columns'], function ($c) {
        return $c !== 'isbn';
    }));
}

// If the resource is reservations and the user is a student/non‑staff, simplify the form. Students
// should only select a book to reserve; fields like reserved_at, status, expiration_date and
// patron_id are either auto‑populated or handled by staff. This removal occurs after the
// generic patron_id filtering above.
if ($resource === 'reservations' && $isStudentRole) {
    // Show only a subset of fields to students: book_id (to choose the book),
    // reserved_at (to choose when the reservation starts) and expiration_date
    // (to specify when the reservation should expire).  Hide other fields
    // like status or patron_id which are managed by staff or defaults.
    $cfg['fields'] = array_values(array_filter($cfg['fields'], function ($f) {
        $name = $f['name'] ?? '';
        return in_array($name, ['book_id','reserved_at','expiration_date'], true);
    }));
}

// No further adjustments needed since data submissions feature has been removed.

// ---------------------------------------------------------------------------
// Additional adjustments for simplified UI.
//
// Remove the primary key `id` column from all resources except books and
// patrons.  The internal identifier is not useful to end users and its
// absence declutters the table.  Book entries should retain the book ID
// and patrons should retain their numeric identifier (library card).
if (!in_array($resource, ['books','patrons'], true)) {
    $cfg['columns'] = array_values(array_filter($cfg['columns'], function ($c) {
        return $c !== 'id';
    }));
}

// Replace `patron_id` with a user name column.  When a resource includes a
// patron identifier we swap it out for a `user` column.  The API populates
// this property with the name of the user (or patron) associated with the
// record.  See api/dispatch.php for details.
if (in_array('patron_id', $cfg['columns'], true)) {
    $cfg['columns'] = array_values(array_map(function ($c) {
        return ($c === 'patron_id') ? 'user' : $c;
    }, $cfg['columns']));
}

include __DIR__ . '/_header.php';
// Ensure a CSRF token is stored in sessionStorage for API calls. Without this, student actions may fail due to an invalid CSRF token.
require_once __DIR__ . '/../includes/csrf.php';
$__csrf_token = csrf_token();
echo '<script>sessionStorage.setItem("csrf", "' . $__csrf_token . '");</script>';
?>

<h2><?= htmlspecialchars($cfg['title']) ?></h2>

<div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">
    <form id="itemForm" style="display:none; flex:1; min-width:300px; background:#fff; padding:16px; border-radius:8px; border:1px solid #e5e7eb;">
        <h3 style="margin-top:0;">
            <?php
            // When students access the reservations page, the form is simplified to selecting a book.
            // Update the heading accordingly. Otherwise default to "Create / Edit".
            echo ($resource === 'reservations' && $isStudentRole) ? 'Book' : 'Create / Edit';
            ?>
        </h3>
        <div id="fields">
            <?php foreach ($cfg['fields'] as $f): ?>
                <label style="display:block; font-weight:600; margin-top:10px;"><?= htmlspecialchars($f['label']) ?></label>
                <?php if (($f['type'] ?? 'text') === 'textarea'): ?>
                    <textarea name="<?= htmlspecialchars($f['name']) ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;"></textarea>
                <?php elseif (($f['type'] ?? 'text') === 'select'): ?>
                    <select name="<?= htmlspecialchars($f['name']) ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
                        <?php foreach ($f['options'] as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="<?= htmlspecialchars($f['type'] ?? 'text') ?>"
                           name="<?= htmlspecialchars($f['name']) ?>"
                           <?= !empty($f['step']) ? 'step="'.htmlspecialchars($f['step']).'"' : '' ?>
                           placeholder="<?= htmlspecialchars($f['placeholder'] ?? '') ?>"
                           style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:12px; display:flex; gap:8px;">
            <button type="submit" class="btn" style="background:#111827;color:#fff;border:none;padding:10px 14px;border-radius:8px;">
                <?php
                // For students reserving books, the primary action is to "Book" a title. Otherwise use
                // the generic "Save" label.
                echo ($resource === 'reservations' && $isStudentRole) ? 'Book' : 'Save';
                ?>
            </button>
            <button type="button" id="resetBtn" class="btn" style="background:#e5e7eb;color:#111827;border:none;padding:10px 14px;border-radius:8px;">Reset</button>
        </div>
        <input type="hidden" id="editingId" />
    </form>

    <div style="flex:2; min-width:360px;">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
            <input id="search" placeholder="Search..." class="search-input" />
            <?php if (in_array(($user['role'] ?? ''), ['admin','librarian','assistant'], true)): ?>
            <a class="btn" href="../api/export.php?resource=<?= urlencode($resource) ?>&format=csv" style="background:#10b981; color:#fff; text-decoration:none; padding:8px 12px; border-radius:8px;">Export CSV</a>
            <a class="btn" href="../api/export.php?resource=<?= urlencode($resource) ?>&format=json" style="background:#3b82f6; color:#fff; text-decoration:none; padding:8px 12px; border-radius:8px;">Export JSON</a>
            <?php endif; ?>
        </div>
        <div style="overflow:auto; border:1px solid #e5e7eb; border-radius:8px; background:#fff;">
            <table class="table" style="width:100%; border-collapse:collapse;">
                <thead style="background:#f9fafb;">
                    <tr>
                        <?php foreach ($cfg['columns'] as $col): ?>
                            <?php
                                // Provide a friendlier header for the user column.  If the key is
                                // "user" then label the column as "User"; otherwise use the
                                // column key itself.  The text-transform style will uppercase it
                                // automatically.
                                $headerLabel = ($col === 'user') ? 'User' : $col;
                            ?>
                            <th style="text-align:left; padding:10px; border-bottom:1px solid #e5e7eb; text-transform:uppercase; font-size:12px; color:#6b7280;"><?= htmlspecialchars($headerLabel) ?></th>
                        <?php endforeach; ?>
                        <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Actions</th>
                    </tr>
                </thead>
                <tbody id="rows"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
const resource = <?= json_encode($resource) ?>;
const columns = <?= json_encode($cfg['columns']) ?>;
const form = document.getElementById('itemForm');
const rows = document.getElementById('rows');
const search = document.getElementById('search');
const resetBtn = document.getElementById('resetBtn');
const editingId = document.getElementById('editingId');
const isStudent = <?= in_array(($user['role'] ?? ''), ['student','non_staff'], true) ? 'true' : 'false' ?>;

function getCSRF(){ return sessionStorage.getItem('csrf') || ''; }

function getFormData(){
  const data = {};
  new FormData(form).forEach((v,k)=>{ data[k] = v; });
  // convert empty strings to nulls for cleaner API
  Object.keys(data).forEach(k => { if (data[k] === '') data[k] = null; });
  return data;
}

async function load(){
  const res = await fetch(`../api/dispatch.php?resource=${resource}`, {
    credentials: 'same-origin'
  });
  const list = await res.json();
  render(list);
}

function render(list){
  const q = (search.value || '').toLowerCase();
  const filtered = list.filter(item => JSON.stringify(item).toLowerCase().includes(q));
  rows.innerHTML = filtered.map(item => {
    const tds = columns.map(c => `<td style=\"padding:8px; border-bottom:1px solid #f3f4f6;\">${escapeHtml(item[c] ?? '')}</td>`).join('');
    let actions = '';
    // Determine the actions per resource and role. Students have limited actions on reservations, and
    // staff can approve/decline reservations in addition to editing or deleting.
    if (resource === 'reservations') {
      if (isStudent) {
        // Students can only cancel their own reservations.
        actions = `<button onclick=\"del(${item.id})\">Cancel</button>`;
      } else {
        // Staff actions: approve or decline only.  Edit/delete functions are removed to enforce
        // admin/staff to simply accept or decline reservations.  Any further changes should be done via the API.
        actions = `<button onclick=\"approve(${item.id})\" style=\"margin-right:6px;\">Approve</button>`+
                  `<button onclick=\"decline(${item.id})\">Decline</button>`;
      }
    } else {
      // Default actions for other resources.  The edit functionality has
      // been removed; only deletion remains.  Borrowing is still
      // available for students on book listings.
      actions = `<button onclick=\"del(${item.id})\">Delete</button>`;
      // Students get an extra borrow button on books.
      if (isStudent && resource === 'books') {
        actions += ` <button onclick=\"borrow(${item.id})\">Borrow</button>`;
      }
    }
    return `<tr>${tds}<td style=\"padding:8px;\">${actions}</td></tr>`;
  }).join('');
}

// The book_id field remains a simple numeric input.  The dynamic dropdown has been removed.

function escapeHtml(s){
  return String(s).replace(/[&<>"]+/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
}

form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const data = getFormData();
  const id = editingId.value;
  const method = id ? 'PUT' : 'POST';
  try {
    const res = await fetch(`../api/dispatch.php?resource=${resource}${id?`&id=${id}`:''}`, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCSRF() },
      body: JSON.stringify(data)
    });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || 'Save failed');
    form.reset(); editingId.value='';
    load();
  } catch(err){ alert(err.message); }
});

resetBtn.addEventListener('click', ()=>{ form.reset(); editingId.value=''; });
search.addEventListener('input', load);

async function edit(id){
  const res = await fetch(`../api/dispatch.php?resource=${resource}&id=${id}`, { credentials: 'same-origin' });
  const item = await res.json();
  if (!item) return;
  editingId.value = id;
  Array.from(form.elements).forEach(el => {
    if (!el.name) return;
    if (item[el.name] == null) el.value = '';
    else if (el.type === 'datetime-local' && item[el.name]) {
      const d = new Date(item[el.name].replace(' ','T'));
      el.value = d.toISOString().slice(0,16);
    } else {
      el.value = item[el.name];
    }
  });
}

async function del(id){
  if (!confirm('Delete this record?')) return;
  try {
    const res = await fetch(`../api/dispatch.php?resource=${resource}&id=${id}`, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': getCSRF() }
    });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || 'Delete failed');
    load();
  } catch(err){ alert(err.message); }
}

load();

// Student borrow action
async function borrow(bookId){
  if (!confirm('Borrow this book?')) return;
  try {
    const res = await fetch(`../api/dispatch.php?resource=borrow_logs`, {
      method:'POST',
      credentials: 'same-origin',
      headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': getCSRF() },
      body: JSON.stringify({ book_id: bookId, status:'borrowed' })
    });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || 'Borrow failed');
    alert('Borrowed. Due on ' + (out.due_date || '(see record)'));
    load();
  } catch(err){ alert(err.message); }
}

// Approve a pending reservation. This is available to staff (admin, librarian, assistant).
async function approve(id){
  if (!confirm('Approve this reservation?')) return;
  try {
    const res = await fetch(`../api/dispatch.php?resource=reservations&id=${id}`, {
      method:'PUT',
      credentials:'same-origin',
      headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': getCSRF() },
      body: JSON.stringify({ status:'approved' })
    });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || 'Approval failed');
    load();
  } catch(err){ alert(err.message); }
}

// Decline a pending reservation. This is available to staff (admin, librarian, assistant).
async function decline(id){
  if (!confirm('Decline this reservation?')) return;
  try {
    const res = await fetch(`../api/dispatch.php?resource=reservations&id=${id}`, {
      method:'PUT',
      credentials:'same-origin',
      headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': getCSRF() },
      body: JSON.stringify({ status:'declined' })
    });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || 'Decline failed');
    load();
  } catch(err){ alert(err.message); }
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
