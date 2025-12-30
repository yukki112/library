<?php
// Send Message to Student page. Allows staff to compose a message to a student user.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
// Only staff roles should access this page.
if (!in_array(current_user()['role'], ['admin','librarian','assistant'], true)) {
    header('Location: dashboard.php');
    exit;
}
$pdo = DB::conn();
// Fetch list of student accounts
$stmt = $pdo->prepare("SELECT id, username, name FROM users WHERE role = 'student' ORDER BY username");
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine if a specific student ID was requested via query parameter.
$selectedStudentId = null;
if (isset($_GET['with']) && $_GET['with'] !== '') {
    $selectedStudentId = (int)$_GET['with'];
}
include __DIR__ . '/_header.php';
?>

<!-- Chat interface for staff to communicate with students.  Administrators,
     librarians and assistants can select a student from the list on the left
     and exchange messages in real time on the right.  Attachments are
     supported via the upload endpoint and will appear as links or inline
     images. -->
<h2 style="text-align:center;">Student Messaging</h2>
<div style="display:flex; gap:16px; max-width:1000px; margin:0 auto;">
  <!-- Student list -->
  <div id="studentsList" style="width:240px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; overflow-y:auto; height:500px;">
    <?php foreach ($students as $s): ?>
      <?php
        $stuId = (int)$s['id'];
        $label = htmlspecialchars($s['username']);
        if (!empty($s['name'])) $label .= ' - ' . htmlspecialchars($s['name']);
      ?>
      <div class="student-item" data-id="<?= $stuId ?>" data-label="<?= htmlspecialchars($label) ?>" style="padding:10px 12px; border-bottom:1px solid #f3f4f6; cursor:pointer;">
        <?= $label ?>
      </div>
    <?php endforeach; ?>
  </div>
  <!-- Chat section -->
  <div id="chatSection" style="flex:1; border:1px solid #e5e7eb; border-radius:8px; background:#fff; padding:16px; height:500px; display:flex; flex-direction:column; gap:8px;">
    <!-- Display the name of the current chat partner -->
    <div id="chatHeader" style="font-size:16px; font-weight:600; margin-bottom:4px;"></div>
    <div id="messages" style="flex:1; overflow-y:auto; padding:8px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;"></div>
    <div style="display:flex; align-items:center; gap:6px; position:relative;">
      <!-- Attachment wrapper with menu -->
      <div id="attachWrapper" style="position:relative;">
        <button id="attachBtn" type="button" title="Add files or take photo" style="background:none; border:none; padding:4px; cursor:pointer; color:#374151;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        </button>
        <div id="attachMenu" style="display:none; position:absolute; bottom:120%; left:0; background:#ffffff; border:1px solid #e5e7eb; border-radius:6px; box-shadow:0 2px 4px rgba(0,0,0,0.1); z-index:10;">
          <button id="optAddFile" type="button" style="display:block; width:100%; padding:6px 12px; border:none; background:none; text-align:left; font-size:14px; cursor:pointer;">Add files</button>
          <button id="optTakePhoto" type="button" style="display:block; width:100%; padding:6px 12px; border:none; background:none; text-align:left; font-size:14px; cursor:pointer;">Take photo</button>
        </div>
      </div>
      <input type="file" id="fileInput" style="display:none;" />
      <input type="file" id="photoInput" accept="image/*" capture="environment" style="display:none;" />
      <input id="msgInput" class="input" placeholder="Type your message..." style="flex:1; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" />
      <button id="voiceBtn" type="button" title="Voice message" style="background:none; border:none; padding:4px; cursor:pointer; color:#374151;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 1a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
          <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
          <line x1="12" y1="19" x2="12" y2="23"></line>
          <line x1="8" y1="23" x2="16" y2="23"></line>
        </svg>
      </button>
      <button id="sendBtn" type="button" title="Send message" style="background:#111827; color:#fff; border:none; padding:8px 12px; border-radius:6px; display:flex; align-items:center; justify-content:center;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
      </button>
    </div>
  </div>
</div>

<script>
function escapeHtml(s){
  const map = {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"};
  return String(s).replace(/[&<>"']/g, (m) => map[m]);
}
const msgList = document.getElementById('messages');
const input = document.getElementById('msgInput');
const sendBtn = document.getElementById('sendBtn');
const attachBtn = document.getElementById('attachBtn');
const fileInput = document.getElementById('fileInput');
const photoInput = document.getElementById('photoInput');
const voiceBtn = document.getElementById('voiceBtn');
const attachMenu = document.getElementById('attachMenu');
const optAddFile = document.getElementById('optAddFile');
const optTakePhoto = document.getElementById('optTakePhoto');
const studentItems = document.querySelectorAll('.student-item');
let currentStudentId = null;
const chatHeader = document.getElementById('chatHeader');

// Highlight selected student
function selectStudent(el){
  studentItems.forEach(item => { item.style.background = ''; item.style.fontWeight = 'normal'; });
  el.style.background = '#f3f4f6';
  el.style.fontWeight = '600';
  // Update chat header with selected student's label
  const label = el.getAttribute('data-label') || '';
  chatHeader.textContent = label ? 'Chat with ' + label : '';
}

// Load messages for the current selected student
async function loadMessages(){
  if (!currentStudentId) return;
  try {
    const res = await fetch('../api/messages.php?with=' + currentStudentId);
    const msgs = await res.json();
    if (!Array.isArray(msgs)) { msgList.innerHTML = ''; return; }
    msgList.innerHTML = msgs.map(m => {
      const isMe = String(m.sender_id) === String(<?= (int)current_user()['id'] ?>);
      const name = isMe ? 'You' : escapeHtml(m.sender_name || '');
      const time = escapeHtml(m.created_at || '');
      let contentHtml = '';
      const raw = m.content || '';
      if (raw.startsWith('[file]')) {
        const payload = raw.substring(6);
        const parts = payload.split('|');
        const fileUrl = parts[0] || '';
        const fileName = parts[1] || 'attachment';
        const lower = fileUrl.toLowerCase();
        if (/(\.png|\.jpg|\.jpeg|\.gif|\.webp)$/.test(lower)) {
          contentHtml = `<div><a href="${escapeHtml(fileUrl)}" target="_blank"><img src="${escapeHtml(fileUrl)}" alt="${escapeHtml(fileName)}" style="max-width:200px; border-radius:4px;" /></a><div style="font-size:12px; color:#6b7280; margin-top:4px;">${escapeHtml(fileName)}</div></div>`;
        } else {
          contentHtml = `<a href="${escapeHtml(fileUrl)}" target="_blank" style="color:#2563eb; text-decoration:underline;">${escapeHtml(fileName)}</a>`;
        }
      } else {
        contentHtml = escapeHtml(raw);
      }
      return `<div style="margin-bottom:12px;">
        <div style="font-size:12px; color:#6b7280; margin-bottom:2px;">${name} <span style="float:right;">${time}</span></div>
        <div style="background:${isMe?'#3b82f6':'#e5e7eb'}; color:${isMe?'#fff':'#111827'}; padding:8px; border-radius:6px; display:inline-block; max-width:80%; word-wrap:break-word;">${contentHtml}</div>
      </div>`;
    }).join('');
    // Scroll to bottom
    msgList.scrollTop = msgList.scrollHeight;
  } catch(e){ console.error(e); }
}

// Send a plain text message to current student
async function sendTextMessage(){
  const text = input.value.trim();
  if (!text || !currentStudentId) return;
  input.value = '';
  try {
    await fetch('../api/messages.php', {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({ content: text, receiver_id: parseInt(currentStudentId) })
    });
    await loadMessages();
  } catch(err){ console.error(err); }
}

// Toggle menu on attach button click
attachBtn.addEventListener('click', (e) => {
  e.stopPropagation();
  attachMenu.style.display = attachMenu.style.display === 'block' ? 'none' : 'block';
});
// Clicking outside closes the menu
document.addEventListener('click', () => {
  attachMenu.style.display = 'none';
});
// Choose add file
optAddFile.addEventListener('click', (e) => {
  e.stopPropagation(); attachMenu.style.display = 'none'; fileInput.click();
});
// Choose take photo
optTakePhoto.addEventListener('click', (e) => {
  e.stopPropagation(); attachMenu.style.display = 'none'; photoInput.click();
});
async function uploadAndSend(file){
  if (!file || !currentStudentId) return;
  try {
    const formData = new FormData();
    formData.append('file', file);
    const res = await fetch('../api/upload.php', { method:'POST', body: formData });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || 'Upload failed');
    const content = '[file]' + out.url + '|' + out.name;
    await fetch('../api/messages.php', {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({ content: content, receiver_id: parseInt(currentStudentId) })
    });
    await loadMessages();
  } catch(err){ console.error(err); alert(err.message || 'Failed to upload file'); }
}
fileInput.addEventListener('change', async () => {
  const file = fileInput.files && fileInput.files[0];
  await uploadAndSend(file);
  fileInput.value = '';
});
photoInput.addEventListener('change', async () => {
  const file = photoInput.files && photoInput.files[0];
  await uploadAndSend(file);
  photoInput.value = '';
});

voiceBtn.addEventListener('click', () => { alert('Voice messages are not implemented yet.'); });
sendBtn.addEventListener('click', sendTextMessage);
input.addEventListener('keypress', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); sendTextMessage(); }
});

// Handle student selection
studentItems.forEach(item => {
  item.addEventListener('click', () => {
    const id = item.getAttribute('data-id');
    currentStudentId = id;
    selectStudent(item);
    loadMessages();
  });
});

// Auto-select the first student or the one specified via PHP query param
(function(){
  if (!studentItems.length) return;
  let toSelect = studentItems[0];
  const requestedId = <?= $selectedStudentId !== null ? (int)$selectedStudentId : 'null' ?>;
  if (requestedId) {
    studentItems.forEach(item => {
      if (String(item.getAttribute('data-id')) === String(requestedId)) {
        toSelect = item;
      }
    });
  }
  currentStudentId = toSelect.getAttribute('data-id');
  selectStudent(toSelect);
  loadMessages();
})();

// Refresh messages every 5 seconds
setInterval(loadMessages, 5000);
</script>

<?php include __DIR__ . '/_footer.php'; ?>