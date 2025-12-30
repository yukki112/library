<?php
// Add Book page. Provides a simple form for adding a book record.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
require_login();
include __DIR__ . '/_header.php';
?>

<h2>Add Book</h2>
<form method="post" enctype="multipart/form-data" style="max-width:600px;background:#fff;padding:16px;border-radius:8px;border:1px solid #e5e7eb;">
    <label style="display:block;font-weight:600;margin-top:10px;">Book Image</label>
    <input type="file" name="book_image" accept="image/*" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;" />

    <label style="display:block;font-weight:600;margin-top:10px;">Author</label>
    <input type="text" name="author" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;" />

    <label style="display:block;font-weight:600;margin-top:10px;">Publication Name</label>
    <input type="text" name="publication" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;" />

    <label style="display:block;font-weight:600;margin-top:10px;">Quantity</label>
    <input type="number" name="quantity" min="0" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;" />

    <label style="display:block;font-weight:600;margin-top:10px;">Availability</label>
    <select name="availability" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;">
        <option value="1">Available</option>
        <option value="0">Unavailable</option>
    </select>

    <label style="display:block;font-weight:600;margin-top:10px;">Book Number</label>
    <input type="number" name="book_number" min="0" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;" />

    <button type="submit" class="btn" style="margin-top:16px;background:#111827;color:#fff;border:none;padding:10px 14px;border-radius:8px;">Save</button>
    <p style="margin-top:8px;font-size:12px;color:#6b7280;">Note: This form is a placeholder and does not persist data.</p>
</form>

<?php include __DIR__ . '/_footer.php'; ?>