<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fpdf/fpdf.php';
require_login();

$pdo = DB::conn();
$pdo->beginTransaction();

try {
    $borrowId = $_POST['borrow_id'] ?? 0;
    $damageTypes = json_decode($_POST['damage_types'] ?? '[]', true);
    $damageDescription = $_POST['damage_description'] ?? '';
    $returnCondition = $_POST['return_condition'] ?? 'good';
    $lateFee = floatval($_POST['late_fee'] ?? 0);
    $damageFee = floatval($_POST['damage_fee'] ?? 0);
    $totalFee = floatval($_POST['total_fee'] ?? 0);
    
    // Get borrow details WITH reservation and copy information
    $sql = "SELECT 
                bl.*, 
                b.title, 
                b.author, 
                bc.id as copy_id, 
                bc.status as copy_status, 
                bc.book_condition as current_condition,
                bc.copy_number,
                r.book_copy_id as reservation_copy_id,
                p.name AS patron_name, 
                p.library_id 
            FROM borrow_logs bl
            JOIN books b ON bl.book_id = b.id
            JOIN patrons p ON bl.patron_id = p.id
            LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
            LEFT JOIN reservations r ON r.id = (
                SELECT id FROM reservations 
                WHERE book_id = bl.book_id 
                AND patron_id = bl.patron_id
                AND status = 'approved'
                ORDER BY reserved_at DESC 
                LIMIT 1
            )
            WHERE bl.id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$borrowId]);
    $borrow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$borrow) {
        throw new Exception('Borrow record not found');
    }
    
    // Determine the new copy status and condition based on return condition
    $copyStatus = 'available';
    $copyCondition = 'good';
    
    switch($returnCondition) {
        case 'good':
            $copyStatus = 'available';
            $copyCondition = 'good';
            break;
        case 'fair':
            $copyStatus = 'available';
            $copyCondition = 'fair';
            break;
        case 'poor':
            $copyStatus = 'available';  // Still available, just poor condition
            $copyCondition = 'poor';
            break;
        case 'damaged':
            $copyStatus = 'damaged';  // Mark as damaged - NOT available for borrowing
            $copyCondition = 'damaged';
            break;
        case 'lost':
            $copyStatus = 'lost';  // Mark as lost
            $copyCondition = 'lost';
            break;
    }
    
    // Update borrow log
    $updateSql = "UPDATE borrow_logs SET 
                    status = 'returned',
                    returned_at = NOW(),
                    actual_return_date = NOW(),
                    late_fee = ?,
                    penalty_fee = ?,
                    damage_types = ?,
                    return_damage_description = ?,
                    return_condition = ?,
                    return_status = ?,
                    return_book_condition = ?,
                    fee_paid = CASE WHEN ? > 0 THEN 0 ELSE 1 END
                  WHERE id = ?";
    
    $damageTypesJson = json_encode($damageTypes);
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute([
        $lateFee, 
        $damageFee, 
        $damageTypesJson, 
        $damageDescription, 
        $returnCondition,
        $copyStatus,
        $copyCondition,
        $totalFee,
        $borrowId
    ]);
    
    // CRITICAL: Find the ACTUAL book copy that was borrowed
    // Strategy 1: Check if borrow record already has copy_id
    // Strategy 2: Check reservation for copy_id
    // Strategy 3: Find which copy of this book is currently borrowed by this patron
    
    $actualCopyId = null;
    
    // Strategy 1: Direct copy_id in borrow record
    if (!empty($borrow['copy_id'])) {
        $actualCopyId = $borrow['copy_id'];
    }
    // Strategy 2: Copy_id from reservation
    elseif (!empty($borrow['reservation_copy_id'])) {
        $actualCopyId = $borrow['reservation_copy_id'];
    }
    // Strategy 3: Find the borrowed copy
    else {
        // Find which copy of this book is currently marked as borrowed for this patron
        $findCopySql = "SELECT bc.id 
                        FROM book_copies bc
                        INNER JOIN borrow_logs bl ON bc.id = bl.book_copy_id
                        WHERE bc.book_id = ? 
                        AND bl.patron_id = ?
                        AND bl.status IN ('borrowed', 'overdue')
                        AND bc.status IN ('borrowed', 'reserved')
                        LIMIT 1";
        
        $stmt = $pdo->prepare($findCopySql);
        $stmt->execute([$borrow['book_id'], $borrow['patron_id']]);
        $foundCopy = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($foundCopy && !empty($foundCopy['id'])) {
            $actualCopyId = $foundCopy['id'];
            
            // Update the borrow log to link to this copy
            $updateBorrowCopy = "UPDATE borrow_logs SET book_copy_id = ? WHERE id = ?";
            $stmt = $pdo->prepare($updateBorrowCopy);
            $stmt->execute([$actualCopyId, $borrowId]);
        }
    }
    
    // Now update the actual book copy
    if (!empty($actualCopyId)) {
        // First, get current status of the copy
        $checkCopySql = "SELECT status, book_condition FROM book_copies WHERE id = ?";
        $stmt = $pdo->prepare($checkCopySql);
        $stmt->execute([$actualCopyId]);
        $currentCopy = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currentCopy) {
            $updateCopy = "UPDATE book_copies SET 
                            status = ?,
                            book_condition = ?
                          WHERE id = ?";
            $stmt = $pdo->prepare($updateCopy);
            $stmt->execute([$copyStatus, $copyCondition, $actualCopyId]);
            
            $affectedRows = $stmt->rowCount();
            
            // Verify the update
            $verifySql = "SELECT status, book_condition FROM book_copies WHERE id = ?";
            $stmt = $pdo->prepare($verifySql);
            $stmt->execute([$actualCopyId]);
            $updatedCopy = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Log the transaction
            $transactionSql = "INSERT INTO copy_transactions 
                              (book_copy_id, transaction_type, from_status, to_status, notes)
                              VALUES (?, 'returned', ?, ?, ?)";
            $stmt = $pdo->prepare($transactionSql);
            $stmt->execute([
                $actualCopyId,
                $currentCopy['status'] ?? 'borrowed',
                $copyStatus,
                'Book returned - Copy #' . $actualCopyId . ' marked as ' . $copyStatus
            ]);
            
            // Also update any reservation linked to this copy
            $updateReservationSql = "UPDATE reservations 
                                     SET status = 'fulfilled', 
                                         updated_at = NOW()
                                     WHERE book_copy_id = ? 
                                     AND patron_id = ?
                                     AND status = 'approved'";
            $stmt = $pdo->prepare($updateReservationSql);
            $stmt->execute([$actualCopyId, $borrow['patron_id']]);
        }
    } else {
        // If we still can't find the copy, log an error but continue
        error_log("WARNING: Could not find specific copy for borrow_id: $borrowId");
        
        // At minimum, update the book's available count by finding any borrowed copy
        $updateAnyCopySql = "UPDATE book_copies bc
                             INNER JOIN borrow_logs bl ON bc.id = bl.book_copy_id
                             SET bc.status = ?,
                                 bc.book_condition = ?
                             WHERE bl.id = ? 
                             AND bc.status IN ('borrowed', 'reserved')";
        $stmt = $pdo->prepare($updateAnyCopySql);
        $stmt->execute([$copyStatus, $copyCondition, $borrowId]);
    }
    
    // Generate receipt
    $receiptNumber = 'REC' . date('Ymd') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    
    // Create receipt record
    $receiptSql = "INSERT INTO receipts 
                    (receipt_number, borrow_log_id, patron_id, total_amount, 
                     late_fee, damage_fee, payment_date, status) 
                   VALUES (?, ?, ?, ?, ?, ?, NOW(), 'paid')";
    $stmt = $pdo->prepare($receiptSql);
    $stmt->execute([
        $receiptNumber, $borrowId, $borrow['patron_id'], $totalFee,
        $lateFee, $damageFee
    ]);
    
    // Generate PDF receipt
    $pdfPath = generateReceiptPDF($receiptNumber, $borrow, [
        'late_fee' => $lateFee,
        'damage_fee' => $damageFee,
        'total_fee' => $totalFee,
        'damage_types' => $damageTypes,
        'damage_description' => $damageDescription,
        'return_condition' => $returnCondition,
        'copy_status' => $copyStatus,
        'copy_condition' => $copyCondition,
        'actual_copy_id' => $actualCopyId ?? 'Not found'
    ]);
    
    // Update receipt with PDF path
    $updateReceipt = "UPDATE receipts SET pdf_path = ? WHERE receipt_number = ?";
    $stmt = $pdo->prepare($updateReceipt);
    $stmt->execute([$pdfPath, $receiptNumber]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Book returned successfully. ' . 
                    (empty($actualCopyId) ? 'Note: Specific copy not identified. ' : 'Copy #' . $actualCopyId . ' ') .
                    'marked as ' . $copyStatus . ' (' . $copyCondition . ').',
        'receipt_pdf' => $pdfPath,
        'receipt_number' => $receiptNumber,
        'copy_status' => $copyStatus,
        'copy_condition' => $copyCondition,
        'copy_id' => $actualCopyId
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Return processing error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function generateReceiptPDF($receiptNumber, $borrow, $feeData) {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    
    // Header with logo
    $pdf->SetFont('Arial', 'B', 24);
    $pdf->SetTextColor(67, 97, 238);
    $pdf->Cell(0, 15, 'LIBRARY BOOK RETURN RECEIPT', 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'Receipt No: ' . $receiptNumber, 0, 1, 'C');
    $pdf->Cell(0, 6, 'Date: ' . date('F d, Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Separator line
    $pdf->SetDrawColor(67, 97, 238);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(10);
    
    // Book Information
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'BOOK INFORMATION', 0, 1);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(60, 60, 60);
    
    $pdf->Cell(40, 7, 'Book Title:', 0, 0);
    $pdf->MultiCell(0, 7, $borrow['title'], 0, 1);
    
    $pdf->Cell(40, 7, 'Author:', 0, 0);
    $pdf->Cell(0, 7, $borrow['author'], 0, 1);
    
    if (!empty($borrow['copy_number'])) {
        $pdf->Cell(40, 7, 'Copy Number:', 0, 0);
        $pdf->Cell(0, 7, $borrow['copy_number'], 0, 1);
    }
    
    $pdf->Cell(40, 7, 'Borrow Date:', 0, 0);
    $pdf->Cell(0, 7, date('M d, Y', strtotime($borrow['borrowed_at'])), 0, 1);
    
    $pdf->Cell(40, 7, 'Due Date:', 0, 0);
    $pdf->Cell(0, 7, date('M d, Y', strtotime($borrow['due_date'])), 0, 1);
    
    $pdf->Cell(40, 7, 'Return Date:', 0, 0);
    $pdf->Cell(0, 7, date('M d, Y H:i:s'), 0, 1);
    
    $pdf->Cell(40, 7, 'Copy Status:', 0, 0);
    $pdf->SetTextColor(67, 97, 238);
    $pdf->Cell(0, 7, ucfirst($feeData['copy_status']), 0, 1);
    
    $pdf->Cell(40, 7, 'Copy Condition:', 0, 0);
    $pdf->Cell(0, 7, ucfirst($feeData['copy_condition']), 0, 1);
    
    if (!empty($feeData['actual_copy_id']) && $feeData['actual_copy_id'] !== 'Not found') {
        $pdf->Cell(40, 7, 'Copy ID:', 0, 0);
        $pdf->Cell(0, 7, $feeData['actual_copy_id'], 0, 1);
    }
    
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Ln(8);
    
    // Patron Information
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'USER INFORMATION', 0, 1);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(40, 7, 'Name:', 0, 0);
    $pdf->Cell(0, 7, $borrow['patron_name'], 0, 1);
    
    $pdf->Cell(40, 7, 'Library ID:', 0, 0);
    $pdf->Cell(0, 7, $borrow['library_id'], 0, 1);
    $pdf->Ln(8);
    
    // Condition Assessment
    if (!empty($feeData['damage_types']) || !empty($feeData['damage_description'])) {
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 8, 'CONDITION ASSESSMENT', 0, 1);
        $pdf->SetFont('Arial', '', 11);
        
        if (!empty($feeData['damage_types'])) {
            $pdf->Cell(40, 7, 'Damage Types:', 0, 0);
            $damageList = implode(', ', array_map('ucfirst', 
                array_map('str_replace', 
                    array_fill(0, count($feeData['damage_types']), '_'), 
                    array_fill(0, count($feeData['damage_types']), ' '), 
                    $feeData['damage_types']
                )
            ));
            $pdf->MultiCell(0, 7, $damageList, 0, 1);
        }
        
        if (!empty($feeData['damage_description'])) {
            $pdf->Cell(40, 7, 'Description:', 0, 0);
            $pdf->MultiCell(0, 7, $feeData['damage_description'], 0, 1);
        }
        
        $pdf->Cell(40, 7, 'Return Condition:', 0, 0);
        $pdf->Cell(0, 7, ucfirst($feeData['return_condition']), 0, 1);
        $pdf->Ln(8);
    }
    
    // Fee Summary
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'FEE SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    
    // Table header
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Cell(120, 10, 'Description', 1, 0, 'L', true);
    $pdf->Cell(40, 10, 'Amount', 1, 1, 'R', true);
    
    if ($feeData['late_fee'] > 0) {
        $pdf->Cell(120, 8, 'Overdue Fee', 1, 0, 'L');
        $pdf->Cell(40, 8, number_format($feeData['late_fee'], 2), 1, 1, 'R');
    }
    
    if ($feeData['damage_fee'] > 0) {
        $pdf->Cell(120, 8, 'Damage Fee', 1, 0, 'L');
        $pdf->Cell(40, 8, number_format($feeData['damage_fee'], 2), 1, 1, 'R');
    }
    
    // Total
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(120, 10, 'TOTAL PAYABLE', 1, 0, 'L', true);
    $pdf->Cell(40, 10, number_format($feeData['total_fee'], 2), 1, 1, 'R', true);
    $pdf->Ln(15);
    
    // Footer with status information
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(100, 100, 100);
    
    // Status explanation
    if ($feeData['copy_status'] === 'damaged') {
        $pdf->Cell(0, 6, 'NOTE: This book has been marked as DAMAGED and will not be available for future borrowing.', 0, 1, 'C');
    } elseif ($feeData['copy_status'] === 'lost') {
        $pdf->Cell(0, 6, 'NOTE: This book has been marked as LOST.', 0, 1, 'C');
    } elseif ($feeData['copy_condition'] === 'poor') {
        $pdf->Cell(0, 6, 'NOTE: This book is in POOR condition but remains available for borrowing.', 0, 1, 'C');
    }
    
    $pdf->Cell(0, 6, 'Book copy status: ' . ucfirst($feeData['copy_status']) . ' | Condition: ' . ucfirst($feeData['copy_condition']), 0, 1, 'C');
    $pdf->Cell(0, 6, 'This receipt is computer generated and does not require a signature.', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Thank you for using the library!', 0, 1, 'C');
    
    // Save PDF
    $filename = 'receipt_' . $receiptNumber . '_' . date('Ymd_His') . '.pdf';
    $filepath = '../receipts/' . $filename;
    
    // Create receipts directory if it doesn't exist
    if (!file_exists('../receipts')) {
        mkdir('../receipts', 0777, true);
    }
    
    $pdf->Output('F', $filepath);
    
    return $filepath;
}
?>