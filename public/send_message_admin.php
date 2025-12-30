<?php
// Send Message to Admin page.  Provides a simple chat interface for
// students and non‑staff to communicate with the system administrator.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
$u = current_user();
// Only students and non‑staff should access this page.  Staff and
// administrators may access future admin‑facing messaging modules.
if (!in_array($u['role'], ['student','non_staff'], true)) {
    header('Location: dashboard.php');
    exit;
}
include __DIR__ . '/_header.php';
?>

<h2 style="text-align:center;">Send Message to Admin</h2>

<!--
  Enhanced chat UI for students contacting the administrator.  The interface
  now includes an attachment button, a voice button (placeholder) and a
  stylised send button with an arrow icon.  Uploaded files are sent via
  api/upload.php and referenced in the message content using a special
  `[file]` prefix followed by the URL and original filename separated by a
  pipe character.  When rendering messages the client detects this
  pattern and displays a link or image accordingly.
-->
<div id="chatContainer" style="max-width:640px; margin:0 auto; border:1px solid #e5e7eb; border-radius:8px; padding:16px; background:#fff; height:500px; display:flex; flex-direction:column; gap:8px;">
  <!-- Header displays the conversation partner -->
  <div id="chatHeader" style="font-size:16px; font-weight:600;"></div>
  <!-- Messages list -->
  <div id="messages" style="flex:1; overflow-y:auto; padding:8px; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb;"></div>
  <!-- Composer row -->
  <div style="display:flex; align-items:center; gap:6px; position:relative;">
    <!-- Attachment wrapper with pop‑up menu -->
    <div id="attachWrapper" style="position:relative;">
      <button id="attachBtn" type="button" title="Add files or take photo" style="background:none; border:none; padding:4px; cursor:pointer; color:#374151;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      </button>
      <!-- Attachment menu, hidden by default -->
      <div id="attachMenu" style="display:none; position:absolute; bottom:120%; left:0; background:#ffffff; border:1px solid #e5e7eb; border-radius:6px; box-shadow:0 2px 4px rgba(0,0,0,0.1); z-index:10;">
        <button id="optAddFile" type="button" style="display:block; width:100%; padding:6px 12px; border:none; background:none; text-align:left; font-size:14px; cursor:pointer;">Add files</button>
        <button id="optTakePhoto" type="button" style="display:block; width:100%; padding:6px 12px; border:none; background:none; text-align:left; font-size:14px; cursor:pointer;">Take photo</button>
      </div>
    </div>
    <!-- Hidden file inputs -->
    <input type="file" id="fileInput" style="display:none;" />
    <input type="file" id="photoInput" accept="image/*" capture="environment" style="display:none;" />
    <!-- Text input -->
    <input id="msgInput" class="input" placeholder="Type your message..." style="flex:1; padding:8px; border:1px solid #e5e7eb; border-radius:6px;"/>
    <!-- Voice button (placeholder) -->
    <button id="voiceBtn" type="button" title="Voice message" style="background:none; border:none; padding:4px; cursor:pointer; color:#374151;">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 1a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
        <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
        <line x1="12" y1="19" x2="12" y2="23"></line>
        <line x1="8" y1="23" x2="16" y2="23"></line>
      </svg>
    </button>
    <!-- Send button -->
    <button id="sendBtn" type="button" title="Send message" style="background:#111827; color:#fff; border:none; padding:8px 12px; border-radius:6px; display:flex; align-items:center; justify-content:center;">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
    </button>
  </div>
</div>

<script>
function escapeHtml(s){
  const map = {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"};
  return String(s).replace(/[&<>"']/g, (m) => map[m]);
}
// Grab DOM elements for the chat functionality
const msgList = document.getElementById('messages');
const input = document.getElementById('msgInput');
const sendBtn = document.getElementById('sendBtn');
const attachBtn = document.getElementById('attachBtn');
const fileInput = document.getElementById('fileInput');
const photoInput = document.getElementById('photoInput');
const attachMenu = document.getElementById('attachMenu');
const optAddFile = document.getElementById('optAddFile');
const optTakePhoto = document.getElementById('optTakePhoto');
const voiceBtn = document.getElementById('voiceBtn');
const chatHeader = document.getElementById('chatHeader');
// Display header indicating chat partner (admin).  The username of the first
// administrator could be fetched from the backend, but as students
// always chat with an admin this static label suffices.
chatHeader.textContent = 'Chat with Admin';

// Scroll to bottom helper
function scrollToBottom(){ msgList.scrollTop = msgList.scrollHeight; }

// Render messages.  Detect file attachments encoded with the [file] prefix.
async function loadMessages(){
  try {
    const res = await fetch('../api/messages.php');
    const msgs = await res.json();
    if (!Array.isArray(msgs)) return;
    msgList.innerHTML = msgs.map(m => {
      const isMe = String(m.sender_id) === String(<?= (int)$u['id'] ?>);
      const name = isMe ? 'You' : escapeHtml(m.sender_name || '');
      const time = escapeHtml(m.created_at || '');
      let contentHtml = '';
      const raw = m.content || '';
      if (raw.startsWith('[file]')) {
        // Format: [file]url|filename
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
    scrollToBottom();
  } catch(e){ console.error(e); }
}

// Send a plain text message
async function sendTextMessage(){
  const text = input.value.trim();
  if (!text) return;
  input.value='';
  try {
    await fetch('../api/messages.php', {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({ content: text })
    });
    await loadMessages();
  } catch(e){ console.error(e); }
}

// Handle attachment selection and upload
// Toggle the attachment menu on attach button click
attachBtn.addEventListener('click', (e) => {
  e.stopPropagation();
  attachMenu.style.display = attachMenu.style.display === 'block' ? 'none' : 'block';
});
// Clicking outside the menu hides it
document.addEventListener('click', () => {
  attachMenu.style.display = 'none';
});
// Option: add file
optAddFile.addEventListener('click', (e) => {
  e.stopPropagation();
  attachMenu.style.display = 'none';
  fileInput.click();
});
// Option: take photo
optTakePhoto.addEventListener('click', (e) => {
  e.stopPropagation();
  attachMenu.style.display = 'none';
  photoInput.click();
});
async function uploadAndSend(file){
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
      body: JSON.stringify({ content: content })
    });
    await loadMessages();
  } catch(err) {
    console.error(err);
    alert(err.message || 'Failed to upload file');
  }
}
fileInput.addEventListener('change', async () => {
  const file = fileInput.files && fileInput.files[0];
  if (!file) return;
  await uploadAndSend(file);
  fileInput.value = '';
});
photoInput.addEventListener('change', async () => {
  const file = photoInput.files && photoInput.files[0];
  if (!file) return;
  await uploadAndSend(file);
  photoInput.value = '';
});

// Placeholder for voice messages; show a simple alert for now
voiceBtn.addEventListener('click', () => {
  alert('Voice messages are not implemented yet.');
});

// Send text on button click or Enter key
sendBtn.addEventListener('click', sendTextMessage);
input.addEventListener('keypress', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    sendTextMessage();
  }
});

// Initial load and periodic refresh every 5 seconds
loadMessages();
setInterval(loadMessages, 5000);
</script>

<?php include __DIR__ . '/_footer.php'; ?>