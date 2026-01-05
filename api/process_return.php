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
    
    // Get borrow details with copy information
    $sql = "SELECT bl.*, b.title, b.author, bc.id as copy_id, bc.status as copy_status,
                   p.name AS patron_name, p.library_id 
            FROM borrow_logs bl
            JOIN books b ON bl.book_id = b.id
            LEFT JOIN book_copies bc ON bl.book_copy_id = bc.id
            JOIN patrons p ON bl.patron_id = p.id
            WHERE bl.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$borrowId]);
    $borrow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$borrow) {
        throw new Exception('Borrow record not found');
    }
    
    // Determine the new copy status based on return condition
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
            $copyStatus = 'maintenance'; // Needs maintenance
            $copyCondition = 'poor';
            break;
        case 'damaged':
            $copyStatus = 'damaged';
            $copyCondition = 'damaged';
            break;
        case 'lost':
            $copyStatus = 'lost';
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
        $totalFee,
        $borrowId
    ]);
    
    // Update book copy status if copy exists
    if (!empty($borrow['copy_id'])) {
        $updateCopy = "UPDATE book_copies SET 
                        status = ?,
                        book_condition = ?
                      WHERE id = ?";
        $stmt = $pdo->prepare($updateCopy);
        $stmt->execute([$copyStatus, $copyCondition, $borrow['copy_id']]);
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
        'copy_status' => $copyStatus
    ]);
    
    // Update receipt with PDF path
    $updateReceipt = "UPDATE receipts SET pdf_path = ? WHERE receipt_number = ?";
    $stmt = $pdo->prepare($updateReceipt);
    $stmt->execute([$pdfPath, $receiptNumber]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Book returned successfully. Copy marked as ' . $copyStatus . '.',
        'receipt_pdf' => $pdfPath,
        'receipt_number' => $receiptNumber
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
    
    $pdf->Cell(40, 7, 'Borrow Date:', 0, 0);
    $pdf->Cell(0, 7, date('M d, Y', strtotime($borrow['borrowed_at'])), 0, 1);
    
    $pdf->Cell(40, 7, 'Due Date:', 0, 0);
    $pdf->Cell(0, 7, date('M d, Y', strtotime($borrow['due_date'])), 0, 1);
    
    $pdf->Cell(40, 7, 'Return Date:', 0, 0);
    $pdf->Cell(0, 7, date('M d, Y H:i:s'), 0, 1);
    
    $pdf->Cell(40, 7, 'Copy Status:', 0, 0);
    $pdf->SetTextColor(67, 97, 238);
    $pdf->Cell(0, 7, ucfirst($feeData['copy_status']), 0, 1);
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
    $pdf->Cell(0, 8, 'FEE SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    
    // Table header
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Cell(120, 10, 'Description', 1, 0, 'L', true);
    $pdf->Cell(40, 10, 'Amount (₱)', 1, 1, 'R', true);
    
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
    
    // Footer
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'This receipt is computer generated and does not require a signature.', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Book copy status: ' . ucfirst($feeData['copy_status']), 0, 1, 'C');
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