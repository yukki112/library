<?php
// Display detailed information about a single borrow transaction and its originating reservation.
// This page is accessible to the patron who borrowed the book as well as staff roles.  It
// surfaces key dates (borrowed at, due date, returned at) along with any associated
// reservation details such as when the reservation was placed and when it expired.  If the
// borrow originated from a reservation, the reservation ID is stored in the notes field
// (e.g., "Reservation ID 42") and used to look up additional metadata.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
$user = current_user();

// Only logged in users with valid roles can view receipts.  Students and non‑staff can only
// view their own borrow logs.
if (!in_array($user['role'], ['admin','librarian','assistant','student','non_staff'], true)) {
    header('Location: dashboard.php');
    exit;
}

// Grab the borrow log ID from the query string.  Sanitize by casting to int to avoid SQL
// injection.  If the ID is missing or invalid, display a simple message to the user.
$borrowId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($borrowId < 1) {
    include __DIR__ . '/_header.php';
    echo '<h2>Borrow Receipt</h2><p>Invalid borrow record.</p>';
    include __DIR__ . '/_footer.php';
    exit;
}

$pdo = DB::conn();

// Fetch the borrow record joined with the related book title.  Joining on books here keeps
// the page self‑contained and avoids additional queries when displaying the book name.  The
// patron_id is included so we can enforce that students cannot view other patrons' records.
$stmt = $pdo->prepare('SELECT bl.*, b.title AS book_title FROM borrow_logs bl JOIN books b ON bl.book_id = b.id WHERE bl.id = :id');
$stmt->execute([':id' => $borrowId]);
$borrow = $stmt->fetch();

if (!$borrow) {
    include __DIR__ . '/_header.php';
    echo '<h2>Borrow Receipt</h2><p>Borrow record not found.</p>';
    include __DIR__ . '/_footer.php';
    exit;
}

// Restrict students and non‑staff so they can only view their own borrow history.  Staff
// members (admin, librarian, assistant) may view any borrow record.
if (in_array($user['role'], ['student','non_staff'], true)) {
    $userPatronId = isset($user['patron_id']) ? (int)$user['patron_id'] : 0;
    if ((int)$borrow['patron_id'] !== $userPatronId) {
        include __DIR__ . '/_header.php';
        echo '<h2>Borrow Receipt</h2><p>Access denied.</p>';
        include __DIR__ . '/_footer.php';
        exit;
    }
}

// Attempt to locate the associated reservation if one exists.  The dispatch API inserts
// "Reservation ID X" into the notes column when a reservation is approved and converted to
// a borrow.  Use a regular expression to extract the numeric ID and fetch the reservation.
$reservation = null;
if (!empty($borrow['notes']) && preg_match('/Reservation ID\s+(\d+)/i', $borrow['notes'], $match)) {
    $rid = (int)$match[1];
    if ($rid > 0) {
        $rstmt = $pdo->prepare('SELECT * FROM reservations WHERE id = :rid');
        $rstmt->execute([':rid' => $rid]);
        $reservation = $rstmt->fetch();
    }
}

// Render the receipt.  Use the standard header/footer templates for consistent layout.
include __DIR__ . '/_header.php';
?>

<h2>Borrow Receipt</h2>

<div style="background:#fff; padding:20px; border:1px solid #e5e7eb; border-radius:8px; max-width:600px;">
  <h3 style="margin-top:0;">Book: <?= htmlspecialchars($borrow['book_title']) ?> (ID: <?= htmlspecialchars($borrow['book_id']) ?>)</h3>
  <p><strong>Borrowed At:</strong> <?= htmlspecialchars($borrow['borrowed_at']) ?></p>
  <p><strong>Due Date:</strong> <?= htmlspecialchars($borrow['due_date']) ?></p>
  <?php if (!empty($borrow['returned_at'])): ?>
    <p><strong>Returned At:</strong> <?= htmlspecialchars($borrow['returned_at']) ?></p>
  <?php endif; ?>
  <p><strong>Status:</strong> <?= htmlspecialchars($borrow['status']) ?></p>
  <?php if (isset($borrow['late_fee'])): ?>
    <p><strong>Late Fee:</strong> <?= number_format((float)$borrow['late_fee'], 2) ?></p>
  <?php endif; ?>
  <?php if ($reservation): ?>
    <hr style="margin:20px 0;" />
    <h3 style="margin-top:0;">Reservation Details</h3>
    <p><strong>Reservation ID:</strong> <?= htmlspecialchars($reservation['id']) ?></p>
    <p><strong>Reserved At:</strong> <?= htmlspecialchars($reservation['reserved_at']) ?></p>
    <p><strong>Expiration Date:</strong> <?= htmlspecialchars($reservation['expiration_date']) ?></p>
    <p><strong>Reservation Status:</strong> <?= htmlspecialchars($reservation['status']) ?></p>
  <?php endif; ?>
</div>

<p style="margin-top:16px;"><a href="my_borrows.php" style="color:#2563eb; text-decoration:underline;">&larr; Back to My Borrows</a></p>

<?php
include __DIR__ . '/_footer.php';
?>