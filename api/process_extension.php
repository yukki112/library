<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$extension_id = $_POST['extension_id'] ?? null;
$action = $_POST['action'] ?? null;
$admin_notes = $_POST['admin_notes'] ?? '';

if (!$extension_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $pdo = DB::conn();
    
    // Get extension request details
    $stmt = $pdo->prepare("
        SELECT er.*, bl.book_id, bl.book_copy_id, bl.patron_id, bl.due_date as current_borrow_due,
               b.title as book_title, b.author, bc.copy_number, bc.barcode, 
               p.name as patron_name, p.library_id
        FROM extension_requests er
        JOIN borrow_logs bl ON er.borrow_log_id = bl.id
        JOIN books b ON bl.book_id = b.id
        JOIN book_copies bc ON er.book_copy_id = bc.id
        JOIN patrons p ON er.patron_id = p.id
        WHERE er.id = ? AND er.status = 'pending'
    ");
    $stmt->execute([$extension_id]);
    $extension = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$extension) {
        echo json_encode(['success' => false, 'message' => 'Extension request not found or already processed']);
        exit;
    }
    
    $current_user_id = $_SESSION['user_id'] ?? 1;
    
    if ($action === 'approve') {
        // Update extension request status
        $stmt = $pdo->prepare("
            UPDATE extension_requests 
            SET status = 'approved', 
                approved_by = ?, 
                approved_at = NOW(),
                admin_notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$current_user_id, $admin_notes, $extension_id]);
        
        // Update borrow log due date
        $stmt = $pdo->prepare("
            UPDATE borrow_logs 
            SET due_date = ?, 
                extension_attempts = extension_attempts + 1,
                last_extension_date = CURDATE(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$extension['requested_extension_date'], $extension['borrow_log_id']]);
        
        // Generate receipt number
        $receipt_number = 'BCP' . date('Ymd') . rand(1000, 9999);
        
        // Generate PDF receipt
        $pdf_path = generateExtensionReceipt($extension, $receipt_number);
        
        // Update extension request with receipt info
        $stmt = $pdo->prepare("
            UPDATE extension_requests 
            SET receipt_number = ?
            WHERE id = ?
        ");
        $stmt->execute([$receipt_number, $extension_id]);
        
        // Create receipt record - FIXED: Set borrow_log_id to actual value
        $stmt = $pdo->prepare("
            INSERT INTO receipts (
                receipt_number, borrow_log_id, extension_request_id, patron_id, 
                total_amount, extension_fee, payment_date, status, pdf_path, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'paid', ?, NOW())
        ");
        
        $total_amount = $extension['extension_fee'];
        $stmt->execute([
            $receipt_number,
            $extension['borrow_log_id'],  // ACTUAL borrow_log_id
            $extension_id,
            $extension['patron_id'],
            $total_amount,
            $extension['extension_fee'],
            $pdf_path
        ]);
        
        // Log transaction
        $stmt = $pdo->prepare("
            INSERT INTO copy_transactions (
                book_copy_id, transaction_type, notes, created_at
            ) VALUES (?, 'extension_approved', ?, NOW())
        ");
        
        $notes = "Extension approved for borrow #{$extension['borrow_log_id']}. ";
        $notes .= "Days: {$extension['extension_days']}. ";
        $notes .= "New due date: {$extension['requested_extension_date']}. ";
        $notes .= "Fee: ₱{$extension['extension_fee']}";
        
        $stmt->execute([$extension['book_copy_id'], $notes]);
        
        // Create notification for patron
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, message, meta, created_at)
            SELECT u.id, 'extension_approved', ?, ?, NOW()
            FROM users u 
            WHERE u.patron_id = ?
        ");
        
        $meta = json_encode([
            'extension_request_id' => $extension_id,
            'borrow_log_id' => $extension['borrow_log_id'],
            'book_copy_id' => $extension['book_copy_id'],
            'extension_days' => $extension['extension_days'],
            'extension_fee' => $extension['extension_fee'],
            'new_due_date' => $extension['requested_extension_date'],
            'receipt_number' => $receipt_number,
            'pdf_path' => $pdf_path
        ]);
        
        $message = "Your extension request has been approved";
        $stmt->execute([$message, $meta, $extension['patron_id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Extension approved successfully',
            'receipt_pdf' => $pdf_path,
            'receipt_number' => $receipt_number
        ]);
        
    } else if ($action === 'reject') {
        // Update extension request status
        $stmt = $pdo->prepare("
            UPDATE extension_requests 
            SET status = 'rejected', 
                admin_notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$admin_notes, $extension_id]);
        
        // Log transaction
        $stmt = $pdo->prepare("
            INSERT INTO copy_transactions (
                book_copy_id, transaction_type, notes, created_at
            ) VALUES (?, 'extension_rejected', ?, NOW())
        ");
        
        $notes = "Extension rejected for borrow #{$extension['borrow_log_id']}. ";
        $notes .= "Reason: {$admin_notes}";
        
        $stmt->execute([$extension['book_copy_id'], $notes]);
        
        // Create notification for patron
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, message, meta, created_at)
            SELECT u.id, 'extension_rejected', ?, ?, NOW()
            FROM users u 
            WHERE u.patron_id = ?
        ");
        
        $meta = json_encode([
            'extension_request_id' => $extension_id,
            'borrow_log_id' => $extension['borrow_log_id'],
            'book_copy_id' => $extension['book_copy_id'],
            'admin_notes' => $admin_notes
        ]);
        
        $message = "Your extension request has been rejected";
        $stmt->execute([$message, $meta, $extension['patron_id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Extension rejected successfully'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Process extension error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

function generateExtensionReceipt($extension, $receipt_number) {
    require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
    
    // Create PDF
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    
    // BCP Header
    $pdf->Cell(0, 10, 'BESTLINK COLLEGE OF THE PHILIPPINES', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'LIBRARY MANAGEMENT SYSTEM', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'BOOK EXTENSION RECEIPT', 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Receipt Number: ' . $receipt_number, 0, 1, 'C');
    $pdf->Ln(5);
    
    // Transaction Information
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Transaction Information', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, 'Date: ' . date('Y-m-d H:i:s'), 0, 1);
    $pdf->Cell(0, 5, 'Transaction Type: Book Extension Approval', 0, 1);
    $pdf->Ln(10);
    
    // Book Information
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Book Information', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $pdf->Cell(50, 7, 'Book Title:', 0, 0);
    $pdf->Cell(0, 7, $extension['book_title'], 0, 1);
    
    $pdf->Cell(50, 7, 'Author:', 0, 0);
    $pdf->Cell(0, 7, $extension['author'], 0, 1);
    
    $pdf->Cell(50, 7, 'Copy Number:', 0, 0);
    $pdf->Cell(0, 7, $extension['copy_number'], 0, 1);
    
    $pdf->Cell(50, 7, 'Barcode:', 0, 0);
    $pdf->Cell(0, 7, $extension['barcode'], 0, 1);
    
    $pdf->Ln(5);
    
    // Patron Information
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Patron Information', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $pdf->Cell(50, 7, 'Name:', 0, 0);
    $pdf->Cell(0, 7, $extension['patron_name'], 0, 1);
    
    $pdf->Cell(50, 7, 'Library ID:', 0, 0);
    $pdf->Cell(0, 7, $extension['library_id'], 0, 1);
    
    $pdf->Ln(5);
    
    // Extension Details
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Extension Details', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $current_due = date('M d, Y', strtotime($extension['current_due_date']));
    $new_due = date('M d, Y', strtotime($extension['requested_extension_date']));
    
    $pdf->Cell(50, 7, 'Extension ID:', 0, 0);
    $pdf->Cell(0, 7, $extension['id'], 0, 1);
    
    $pdf->Cell(50, 7, 'Current Due Date:', 0, 0);
    $pdf->Cell(0, 7, $current_due, 0, 1);
    
    $pdf->Cell(50, 7, 'New Due Date:', 0, 0);
    $pdf->Cell(0, 7, $new_due, 0, 1);
    
    $pdf->Cell(50, 7, 'Extension Period:', 0, 0);
    $pdf->Cell(0, 7, $extension['extension_days'] . ' days', 0, 1);
    
    $pdf->Cell(50, 7, 'Extension Reason:', 0, 0);
    $pdf->MultiCell(0, 5, $extension['reason'] ?? 'No reason provided', 0, 1);
    
    $pdf->Ln(5);
    
    // Payment Details
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Payment Information', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $pdf->Cell(50, 7, 'Extension Fee:', 0, 0);
    $pdf->Cell(0, 7, '₱' . number_format($extension['extension_fee'], 2), 0, 1);
    
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(50, 10, 'Total Amount Due:', 0, 0);
    $pdf->Cell(0, 10, '₱' . number_format($extension['extension_fee'], 2), 0, 1);
    
    $pdf->Ln(10);
    
    // Important Notice
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(200, 0, 0);
    $pdf->Cell(0, 10, 'IMPORTANT PAYMENT INSTRUCTIONS:', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell(0, 6, 'This receipt confirms your approved extension request. Please present this receipt to the BCP Library Cashier for payment within 24 hours. Your extension will not be processed until payment is confirmed and receipt is stamped.', 0, 'C');
    
    $pdf->Ln(5);
    
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, 'Payment Location: BCP Library Cashier Desk', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Payment Hours: 8:00 AM - 5:00 PM, Monday to Friday', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Terms and Conditions
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 5, 'Terms: 1. Payment must be made within 24 hours of approval. 2. Unpaid extensions will be automatically cancelled. 3. Receipt must be presented for payment validation. 4. No refunds for extension fees.', 0, 'L');
    
    $pdf->Ln(10);
    
    // Authorization Section
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, 'Approved by: BCP Library Management System', 0, 1);
    $pdf->Cell(0, 5, 'Authorization Code: BCP-' . $receipt_number, 0, 1);
    $pdf->Cell(0, 5, 'Date of Approval: ' . date('Y-m-d H:i:s'), 0, 1);
    
    $pdf->Ln(10);
    
    // Footer
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 5, 'This is an official BCP Library receipt. Computer-generated. No signature required.', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Bestlink College of the Philippines - Library Division', 0, 1, 'C');
    $pdf->Cell(0, 5, 'For inquiries, contact: library@bcp.edu.ph', 0, 1, 'C');
    
    // Ensure receipts directory exists
    $receipts_dir = __DIR__ . '/../receipts/';
    if (!is_dir($receipts_dir)) {
        mkdir($receipts_dir, 0755, true);
    }
    
    // Save PDF
    $filename = 'bcp_receipt_extension_' . $receipt_number . '_' . date('Ymd_His') . '.pdf';
    $filepath = $receipts_dir . $filename;
    $pdf->Output('F', $filepath);
    
    return '../receipts/' . $filename;
}
?>