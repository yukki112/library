<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_role(['admin', 'librarian', 'assistant']);

$pdo = DB::conn();
$current_user = current_user();

$reportId = $_POST['report_id'] ?? 0;
$adminNotes = $_POST['admin_notes'] ?? '';

try {
    $pdo->beginTransaction();
    
    // Get report details
    $sql = "SELECT r.*, b.price AS book_price, b.title, p.id as patron_id, 
                   p.name as patron_name, p.library_id, bc.id as copy_id
            FROM lost_damaged_reports r
            JOIN books b ON r.book_id = b.id
            JOIN patrons p ON r.patron_id = p.id
            LEFT JOIN book_copies bc ON r.book_copy_id = bc.id
            WHERE r.id = ? AND r.status = 'pending'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        throw new Exception('Report not found or already processed');
    }
    
    $isLost = $report['report_type'] === 'lost';
    $bookPrice = floatval($report['book_price']);
    $lostFee = $bookPrice * 1.5;
    
    // Calculate damage fee based on damage_types column
    $damageFee = 0;
    $damageTypeDetails = [];
    
    if (!$isLost && !empty($report['damage_types'])) {
        // Parse the damage types JSON from the damage_types column
        $damageTypeIds = json_decode($report['damage_types'], true);
        
        if (is_array($damageTypeIds) && !empty($damageTypeIds)) {
            // Create placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($damageTypeIds), '?'));
            
            // Get fee amounts for each damage type
            $damageTypeSql = "SELECT id, name, fee_amount FROM damage_types WHERE id IN ($placeholders)";
            $damageTypeStmt = $pdo->prepare($damageTypeSql);
            $damageTypeStmt->execute($damageTypeIds);
            $damageTypesData = $damageTypeStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Sum up all damage fees
            foreach ($damageTypesData as $damageType) {
                $damageFee += floatval($damageType['fee_amount']);
                $damageTypeDetails[] = [
                    'id' => $damageType['id'],
                    'name' => $damageType['name'],
                    'fee_amount' => $damageType['fee_amount']
                ];
            }
        }
    }
    
    // IMPORTANT FIX: If damage fee is already calculated (e.g., from frontend), use that value
    // Check if fee_charged already has a value from the submission
    if (!$isLost && $damageFee == 0 && !empty($report['fee_charged'])) {
        $damageFee = floatval($report['fee_charged']);
        // Try to get the damage type details from the damage_types field
        if (!empty($report['damage_types'])) {
            try {
                $damageTypeIds = json_decode($report['damage_types'], true);
                if (is_array($damageTypeIds) && !empty($damageTypeIds)) {
                    $placeholders = implode(',', array_fill(0, count($damageTypeIds), '?'));
                    $damageTypeSql = "SELECT id, name, fee_amount FROM damage_types WHERE id IN ($placeholders)";
                    $damageTypeStmt = $pdo->prepare($damageTypeSql);
                    $damageTypeStmt->execute($damageTypeIds);
                    $damageTypesData = $damageTypeStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($damageTypesData as $damageType) {
                        $damageTypeDetails[] = [
                            'id' => $damageType['id'],
                            'name' => $damageType['name'],
                            'fee_amount' => $damageType['fee_amount']
                        ];
                    }
                }
            } catch (Exception $e) {
                // If we can't parse damage types, just use general damage
                $damageTypeDetails[] = [
                    'name' => 'general_damage',
                    'fee_amount' => $damageFee
                ];
            }
        }
    }
    
    // If still no damage fee and it's a damaged report, check if we can get it from the submission
    if (!$isLost && $damageFee == 0) {
        // Try to get the damage types and calculate fee
        if (!empty($report['damage_types'])) {
            try {
                $damageTypeIds = json_decode($report['damage_types'], true);
                if (is_array($damageTypeIds) && !empty($damageTypeIds)) {
                    $placeholders = implode(',', array_fill(0, count($damageTypeIds), '?'));
                    $damageTypeSql = "SELECT SUM(fee_amount) as total_fee FROM damage_types WHERE id IN ($placeholders)";
                    $damageTypeStmt = $pdo->prepare($damageTypeSql);
                    $damageTypeStmt->execute($damageTypeIds);
                    $damageFeeData = $damageTypeStmt->fetch(PDO::FETCH_ASSOC);
                    $damageFee = floatval($damageFeeData['total_fee'] ?? 0);
                    
                    // Also get the damage type details
                    $damageTypeDetailsSql = "SELECT id, name, fee_amount FROM damage_types WHERE id IN ($placeholders)";
                    $damageTypeDetailsStmt = $pdo->prepare($damageTypeDetailsSql);
                    $damageTypeDetailsStmt->execute($damageTypeIds);
                    $damageTypesData = $damageTypeDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($damageTypesData as $damageType) {
                        $damageTypeDetails[] = [
                            'id' => $damageType['id'],
                            'name' => $damageType['name'],
                            'fee_amount' => $damageType['fee_amount']
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Error calculating damage fee: " . $e->getMessage());
            }
        }
        
        // If still 0, use minimum damage fee
        if ($damageFee == 0) {
            $damageFee = 500; // Default minimum damage fee
            $damageTypeDetails[] = [
                'name' => 'general_damage',
                'fee_amount' => $damageFee
            ];
        }
    }
    
    $totalFee = $isLost ? $lostFee : $damageFee;
    
    // Update report with calculated fee and damage type details
    $updateSql = "UPDATE lost_damaged_reports 
                  SET fee_charged = ?, damage_types_fees = ?, status = 'resolved', updated_at = NOW()
                  WHERE id = ?";
    
    $damageTypesFeesJson = !$isLost ? json_encode($damageTypeDetails) : null;
    
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([$totalFee, $damageTypesFeesJson, $reportId]);
    
    // Update book copy status
    if ($report['copy_id']) {
        $copyStatus = $isLost ? 'lost' : 'damaged';
        $copyCondition = $isLost ? 'lost' : 'damaged';
        
        $updateCopySql = "UPDATE book_copies 
                          SET status = ?, book_condition = ?, updated_at = NOW()
                          WHERE id = ?";
        $copyStmt = $pdo->prepare($updateCopySql);
        $copyStmt->execute([$copyStatus, $copyCondition, $report['copy_id']]);
        
        // Log copy transaction
        $transactionSql = "INSERT INTO copy_transactions 
                          (book_copy_id, transaction_type, from_status, to_status, notes, created_at)
                          VALUES (?, ?, 'available', ?, ?, NOW())";
        $transStmt = $pdo->prepare($transactionSql);
        $transStmt->execute([
            $report['copy_id'],
            $isLost ? 'lost' : 'damaged',
            $copyStatus,
            $isLost ? 'Book marked as lost' : 'Book marked as damaged - Damage types: ' . 
            (!empty($damageTypeDetails) ? implode(', ', array_column($damageTypeDetails, 'name')) : 'General damage')
        ]);
    }
    
    // If book is currently borrowed, update borrow log
    if ($report['copy_id']) {
        $borrowSql = "SELECT id FROM borrow_logs 
                      WHERE book_copy_id = ? AND status IN ('borrowed', 'overdue') 
                      ORDER BY borrowed_at DESC LIMIT 1";
        $borrowStmt = $pdo->prepare($borrowSql);
        $borrowStmt->execute([$report['copy_id']]);
        $borrowLog = $borrowStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($borrowLog) {
            $updateBorrowSql = "UPDATE borrow_logs 
                                SET penalty_fee = ?, 
                                    lost_status = IF(? = 1, 'confirmed_lost', lost_status),
                                    lost_date = IF(? = 1, CURDATE(), lost_date),
                                    lost_fee = IF(? = 1, ?, lost_fee),
                                    updated_at = NOW()
                                WHERE id = ?";
            $borrowUpdateStmt = $pdo->prepare($updateBorrowSql);
            $borrowUpdateStmt->execute([
                $totalFee,
                $isLost ? 1 : 0,
                $isLost ? 1 : 0,
                $isLost ? 1 : 0,
                $lostFee,
                $borrowLog['id']
            ]);
        }
    }
    
    // Generate receipt
    $receiptNumber = 'LDR' . date('Ymd') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    
    // Insert receipt
    $receiptSql = "INSERT INTO receipts 
                    (receipt_number, patron_id, total_amount, 
                     damage_fee, late_fee, payment_date, status, notes, created_at) 
                   VALUES (?, ?, ?, ?, 0, NOW(), 'paid', ?, NOW())";
    $receiptStmt = $pdo->prepare($receiptSql);
    $receiptStmt->execute([
        $receiptNumber,
        $report['patron_id'],
        $totalFee,
        $totalFee,
        $adminNotes ?: "Processed lost/damaged report #{$reportId}"
    ]);
    
    $receiptId = $pdo->lastInsertId();
    
    // Generate PDF receipt
    $pdfPath = generateReceiptPDF($receiptNumber, $report, [
        'report_type' => $report['report_type'],
        'fee_charged' => $totalFee,
        'book_price' => $bookPrice,
        'lost_fee' => $lostFee,
        'damage_fee' => $damageFee,
        'damage_types' => $damageTypeDetails,
        'is_lost' => $isLost,
        'admin_notes' => $adminNotes,
        'damage_type_details' => $damageTypeDetails
    ]);
    
    // Update receipt with PDF path
    $updateReceipt = "UPDATE receipts SET pdf_path = ? WHERE id = ?";
    $updReceiptStmt = $pdo->prepare($updateReceipt);
    $updReceiptStmt->execute([$pdfPath, $receiptId]);
    
    // Create notification for patron
    $patronNotifSql = "INSERT INTO notifications 
                       (user_id, type, message, meta, created_at)
                       VALUES (?, 'report_resolved', 
                               'Your lost/damaged book report has been processed', 
                               ?, NOW())";
    
    // Find user ID from patron
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE patron_id = ?");
    $userStmt->execute([$report['patron_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $notifMeta = json_encode([
            'report_id' => $reportId,
            'receipt_number' => $receiptNumber,
            'fee_charged' => $totalFee,
            'report_type' => $report['report_type'],
            'pdf_path' => $pdfPath
        ]);
        
        $patronNotifStmt = $pdo->prepare($patronNotifSql);
        $patronNotifStmt->execute([$user['id'], $notifMeta]);
    }
    
    // Log the action
    $auditSql = "INSERT INTO audit_logs (user_id, action, entity, entity_id, details, created_at) 
                 VALUES (?, 'update', 'lost_damaged_reports', ?, ?, NOW())";
    $auditStmt = $pdo->prepare($auditSql);
    $auditStmt->execute([
        $current_user['id'],
        $reportId,
        json_encode([
            'action' => 'processed',
            'fee_charged' => $totalFee,
            'report_type' => $report['report_type'],
            'receipt_number' => $receiptNumber,
            'damage_types' => $damageTypeDetails,
            'damage_fee_breakdown' => !$isLost ? $damageTypeDetails : null
        ])
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Report processed successfully',
        'receipt_number' => $receiptNumber,
        'receipt_pdf' => $pdfPath,
        'fee_charged' => $totalFee,
        'damage_fee_breakdown' => !$isLost ? $damageTypeDetails : null
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Report processing error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error processing report: ' . $e->getMessage()
    ]);
}

function generateReceiptPDF($receiptNumber, $report, $data) {
    require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
    
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Arial', 'B', 24);
    $pdf->SetTextColor(67, 97, 238);
    $pdf->Cell(0, 15, 'LOST/DAMAGED BOOK REPORT RECEIPT', 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'Receipt No: ' . $receiptNumber, 0, 1, 'C');
    $pdf->Cell(0, 6, 'Date: ' . date('F d, Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Book Information
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'BOOK INFORMATION', 0, 1);
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(60, 60, 60);
    
    $pdf->Cell(40, 7, 'Book Title:', 0, 0);
    $pdf->MultiCell(0, 7, $report['title'], 0, 1);
    
    $pdf->Cell(40, 7, 'Report Type:', 0, 0);
    $pdf->Cell(0, 7, ucfirst($report['report_type']), 0, 1);
    
    $pdf->Cell(40, 7, 'Patron Name:', 0, 0);
    $pdf->Cell(0, 7, $report['patron_name'], 0, 1);
    
    $pdf->Cell(40, 7, 'Library ID:', 0, 0);
    $pdf->Cell(0, 7, $report['library_id'], 0, 1);
    
    $pdf->Cell(40, 7, 'Book Price:', 0, 0);
    $pdf->Cell(0, 7, '₱' . number_format($data['book_price'], 2), 0, 1);
    
    if ($data['is_lost']) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(220, 53, 69);
        $pdf->Cell(0, 10, '*** BOOK MARKED AS LOST ***', 0, 1, 'C');
        $pdf->SetFont('Arial', '', 11);
        $pdf->SetTextColor(60, 60, 60);
    }
    
    $pdf->Ln(10);
    
    // Fee Summary
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'FEE SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 11);
    
    // Table header
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetDrawColor(200, 200, 200);
    
    if ($data['is_lost']) {
        $pdf->Cell(120, 10, 'Description', 1, 0, 'L', true);
        $pdf->Cell(40, 10, 'Amount', 1, 1, 'R', true);
        
        $pdf->Cell(120, 8, 'Book Price', 1, 0, 'L');
        $pdf->Cell(40, 8, '₱' . number_format($data['book_price'], 2), 1, 1, 'R');
        
        $pdf->Cell(120, 8, 'Lost Book Fee (150% of price)', 1, 0, 'L');
        $pdf->Cell(40, 8, '₱' . number_format($data['lost_fee'], 2), 1, 1, 'R');
    } else {
        $pdf->Cell(120, 10, 'Description', 1, 0, 'L', true);
        $pdf->Cell(40, 10, 'Amount', 1, 1, 'R', true);
        
        // Show individual damage fees if available
        if (!empty($data['damage_type_details'])) {
            foreach ($data['damage_type_details'] as $damageType) {
                $damageName = ucwords(str_replace('_', ' ', $damageType['name']));
                $pdf->Cell(120, 8, 'Damage: ' . $damageName, 1, 0, 'L');
                $pdf->Cell(40, 8, '₱' . number_format($damageType['fee_amount'], 2), 1, 1, 'R');
            }
        } else {
            $pdf->Cell(120, 8, 'Damage Assessment Fee', 1, 0, 'L');
            $pdf->Cell(40, 8, '₱' . number_format($data['damage_fee'], 2), 1, 1, 'R');
        }
    }
    
    // Total
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(120, 10, 'TOTAL PAYABLE', 1, 0, 'L', true);
    $pdf->Cell(40, 10, '₱' . number_format($data['fee_charged'], 2), 1, 1, 'R', true);
    
    $pdf->Ln(15);
    
    // Important Notes
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(100, 100, 100);
    
    if ($data['is_lost']) {
        $pdf->MultiCell(0, 5, 'IMPORTANT: This book has been permanently marked as LOST in the library system and will be removed from inventory. The patron is responsible for paying 150% of the book price as a replacement fee.', 0, 1);
        $pdf->Ln(5);
    } else {
        $pdf->MultiCell(0, 5, 'IMPORTANT: This book has been marked as DAMAGED. The damaged copy must be returned to the library for assessment. Failure to return the damaged copy may result in additional charges.', 0, 1);
        $pdf->Ln(5);
        
        // Show damage details if available
        if (!empty($data['damage_type_details'])) {
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetTextColor(60, 60, 60);
            $pdf->MultiCell(0, 5, 'Damage Assessment:', 0, 1);
            foreach ($data['damage_type_details'] as $damageType) {
                $damageName = ucwords(str_replace('_', ' ', $damageType['name']));
                $pdf->MultiCell(0, 5, '• ' . $damageName . ' - ₱' . number_format($damageType['fee_amount'], 2), 0, 1);
            }
            $pdf->Ln(5);
        }
    }
    
    if ($data['admin_notes']) {
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->MultiCell(0, 5, 'Admin Notes: ' . $data['admin_notes'], 0, 1);
        $pdf->Ln(5);
    }
    
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'This receipt is computer generated and serves as proof of payment.', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Thank you for your cooperation in maintaining library resources.', 0, 1, 'C');
    
    // Save PDF
    $filename = 'lost_damage_receipt_' . $receiptNumber . '_' . date('Ymd_His') . '.pdf';
    $filepath = '../receipts/' . $filename;
    
    // Create receipts directory if it doesn't exist
    if (!file_exists('../receipts')) {
        mkdir('../receipts', 0777, true);
    }
    
    $pdf->Output('F', $filepath);
    
    return $filepath;
}