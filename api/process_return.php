<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fpdf/fpdf.php'; // FPDF library
require_login();

$pdo = DB::conn();

// Start transaction
$pdo->beginTransaction();

try {
    $borrowId = $_POST['borrow_id'] ?? 0;
    $damageTypes = json_decode($_POST['damage_types'] ?? '[]', true);
    $damageDescription = $_POST['damage_description'] ?? '';
    $returnCondition = $_POST['return_condition'] ?? 'good';
    $lateFee = floatval($_POST['late_fee'] ?? 0);
    $damageFee = floatval($_POST['damage_fee'] ?? 0);
    $totalFee = floatval($_POST['total_fee'] ?? 0);
    
    // Get borrow details
    $sql = "SELECT bl.*, b.title, b.author, p.name AS patron_name, p.library_id 
            FROM borrow_logs bl
            JOIN books b ON bl.book_id = b.id
            JOIN patrons p ON bl.patron_id = p.id
            WHERE bl.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$borrowId]);
    $borrow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$borrow) {
        throw new Exception('Borrow record not found');
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
                    return_status = CASE ? 
                        WHEN 'lost' THEN 'lost' 
                        WHEN 'damaged' THEN 'damaged' 
                        ELSE 'available' 
                    END
                  WHERE id = ?";
    
    $damageTypesJson = json_encode($damageTypes);
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute([
        $lateFee, 
        $damageFee, 
        $damageTypesJson, 
        $damageDescription, 
        $returnCondition,
        $returnCondition,
        $borrowId
    ]);
    
    // Update book copy status if copy exists
    if (!empty($borrow['book_copy_id'])) {
        $copyStatus = ($returnCondition === 'lost') ? 'lost' : 
                     (($returnCondition === 'damaged') ? 'damaged' : 'available');
        
        $copyCondition = ($returnCondition === 'good') ? 'good' : 
                        (($returnCondition === 'fair') ? 'fair' : 
                        (($returnCondition === 'poor') ? 'poor' : 'damaged'));
        
        $updateCopy = "UPDATE book_copies SET 
                        status = ?,
                        book_condition = ?
                      WHERE id = ?";
        $stmt = $pdo->prepare($updateCopy);
        $stmt->execute([$copyStatus, $copyCondition, $borrow['book_copy_id']]);
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
        'return_condition' => $returnCondition
    ]);
    
    // Update receipt with PDF path
    $updateReceipt = "UPDATE receipts SET pdf_path = ? WHERE receipt_number = ?";
    $stmt = $pdo->prepare($updateReceipt);
    $stmt->execute([$pdfPath, $receiptNumber]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Book returned successfully',
        'receipt_pdf' => $pdfPath,
        'receipt_number' => $receiptNumber
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Return processing error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function generateReceiptPDF($receiptNumber, $borrow, $feeData) {
    $pdf = new FPDF();
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'LIBRARY BOOK RETURN RECEIPT', 0, 1, 'C');
    $pdf->Ln(5);
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, 'Receipt No: ' . $receiptNumber, 0, 1);
    $pdf->Cell(0, 6, 'Date: ' . date('F d, Y H:i:s'), 0, 1);
    $pdf->Ln(10);
    
    // Line separator
    $pdf->SetLineWidth(0.5);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(5);
    
    // Book Information
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'BOOK INFORMATION', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(40, 6, 'Book Title:', 0, 0);
    $pdf->Cell(0, 6, $borrow['title'], 0, 1);
    $pdf->Cell(40, 6, 'Author:', 0, 0);
    $pdf->Cell(0, 6, $borrow['author'], 0, 1);
    $pdf->Cell(40, 6, 'Borrow Date:', 0, 0);
    $pdf->Cell(0, 6, date('M d, Y', strtotime($borrow['borrowed_at'])), 0, 1);
    $pdf->Cell(40, 6, 'Due Date:', 0, 0);
    $pdf->Cell(0, 6, date('M d, Y', strtotime($borrow['due_date'])), 0, 1);
    $pdf->Cell(40, 6, 'Return Date:', 0, 0);
    $pdf->Cell(0, 6, date('M d, Y H:i:s'), 0, 1);
    $pdf->Ln(5);
    
    // Patron Information
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'PATRON INFORMATION', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(40, 6, 'Name:', 0, 0);
    $pdf->Cell(0, 6, $borrow['patron_name'], 0, 1);
    $pdf->Cell(40, 6, 'Library ID:', 0, 0);
    $pdf->Cell(0, 6, $borrow['library_id'], 0, 1);
    $pdf->Ln(5);
    
    // Condition Assessment
    if (!empty($feeData['damage_types']) || !empty($feeData['damage_description'])) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'CONDITION ASSESSMENT', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        
        if (!empty($feeData['damage_types'])) {
            $pdf->Cell(40, 6, 'Damage Types:', 0, 0);
            $pdf->MultiCell(0, 6, implode(', ', $feeData['damage_types']), 0, 1);
        }
        
        if (!empty($feeData['damage_description'])) {
            $pdf->Cell(40, 6, 'Description:', 0, 0);
            $pdf->MultiCell(0, 6, $feeData['damage_description'], 0, 1);
        }
        
        $pdf->Cell(40, 6, 'Return Condition:', 0, 0);
        $pdf->Cell(0, 6, ucfirst($feeData['return_condition']), 0, 1);
        $pdf->Ln(5);
    }
    
    // Fee Summary
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'FEE SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $pdf->Cell(100, 6, 'Description', 1, 0, 'L');
    $pdf->Cell(40, 6, 'Amount (₱)', 1, 1, 'R');
    
    if ($feeData['late_fee'] > 0) {
        $pdf->Cell(100, 6, 'Overdue Fee', 1, 0, 'L');
        $pdf->Cell(40, 6, number_format($feeData['late_fee'], 2), 1, 1, 'R');
    }
    
    if ($feeData['damage_fee'] > 0) {
        $pdf->Cell(100, 6, 'Damage Fee', 1, 0, 'L');
        $pdf->Cell(40, 6, number_format($feeData['damage_fee'], 2), 1, 1, 'R');
    }
    
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(100, 6, 'TOTAL PAYABLE', 1, 0, 'L');
    $pdf->Cell(40, 6, number_format($feeData['total_fee'], 2), 1, 1, 'R');
    $pdf->Ln(10);
    
    // Footer
    $pdf->SetFont('Arial', 'I', 8);
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