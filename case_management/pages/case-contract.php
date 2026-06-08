<?php
require_once __DIR__ . '/../inc/db.php';

$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($caseId <= 0) {
    http_response_code(400);
    echo 'Invalid case ID.';
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
            cl.email AS client_email,
            cl.phone AS client_phone,
            u.username AS lawyer_username,
            u.email AS lawyer_email
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.id = ?
    ");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Unable to load case: ' . htmlspecialchars($e->getMessage());
    exit;
}

if (!$case) {
    http_response_code(404);
    echo 'Case not found.';
    exit;
}

$caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
$clientName = $case['client_name'] ?: 'Client';
$clientEmail = $case['client_email'] ?: 'N/A';
$clientPhone = $case['client_phone'] ?: 'N/A';
$lawyerName = $case['lawyer_username'] ?: 'Assigned Counsel';
$lawyerEmail = $case['lawyer_email'] ?: 'N/A';
$category = $case['category'] ?: 'General';
$priority = ucfirst($case['priority'] ?: 'Normal');
$status = ucfirst($case['status'] ?: 'Open');
$startDate = $case['start_date'] ? date('F d, Y', strtotime($case['start_date'])) : 'Not specified';
$dueDate = $case['expected_completion'] ? date('F d, Y', strtotime($case['expected_completion'])) : 'Not specified';
$feeFormatted = formatCurrency($case['estimated_fees'] ?: 0);
$today = date('F d, Y');
$description = trim($case['description']) ?: 'Scope of representation as agreed between the firm and the client.';

$title = 'LegalPro Engagement Contract';
$bodyHtml = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 32px; color: #111; background: #f7f7f7; }
        .wrapper { max-width: 820px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 40px 48px; box-shadow: 0 15px 35px rgba(15, 23, 42, 0.08); }
        h1 { font-size: 24px; margin-bottom: 6px; letter-spacing: 0.5px; }
        h2 { font-size: 16px; text-transform: uppercase; color: #6b7280; letter-spacing: 1px; margin-top: 32px; margin-bottom: 8px; }
        p { line-height: 1.6; margin: 0 0 10px; }
        .meta, .section { margin-bottom: 24px; }
        .meta table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 6px 0; font-size: 14px; }
        .meta td.label { color: #6b7280; width: 30%; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
        .signature { margin-top: 48px; display: flex; justify-content: space-between; }
        .signature div { width: 45%; text-align: center; font-size: 13px; color: #6b7280; }
        .signature span { display: block; margin-top: 48px; border-top: 1px solid #d1d5db; padding-top: 6px; color: #111; }
        .actions { text-align: right; margin-bottom: 24px; }
        .btn-print { background: #111827; color: #fff; border: none; border-radius: 6px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
        .btn-print:hover { opacity: 0.9; }
        @media print { body { background: #fff; padding: 0; } .wrapper { box-shadow: none; border: none; border-radius: 0; } .actions { display: none; } }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="actions">
            <button class="btn-print" onclick="window.print()">Print</button>
        </div>
        <h1>' . htmlspecialchars($title) . '</h1>
        <p style="color:#6b7280;">Case Reference · ' . htmlspecialchars($caseNumber) . '</p>

        <div class="meta">
            <table>
                <tr><td class="label">Date</td><td>' . htmlspecialchars($today) . '</td></tr>
                <tr><td class="label">Case Title</td><td>' . htmlspecialchars($case['title']) . '</td></tr>
                <tr><td class="label">Client</td><td>' . htmlspecialchars($clientName) . ' · ' . htmlspecialchars($clientEmail) . ' · ' . htmlspecialchars($clientPhone) . '</td></tr>
                <tr><td class="label">Counsel</td><td>' . htmlspecialchars($lawyerName) . ' · ' . htmlspecialchars($lawyerEmail) . '</td></tr>
                <tr><td class="label">Category</td><td>' . htmlspecialchars($category) . '</td></tr>
                <tr><td class="label">Priority</td><td>' . htmlspecialchars($priority) . '</td></tr>
                <tr><td class="label">Status</td><td>' . htmlspecialchars($status) . '</td></tr>
                <tr><td class="label">Timeline</td><td>Start ' . htmlspecialchars($startDate) . ' · Completion ' . htmlspecialchars($dueDate) . '</td></tr>
                <tr><td class="label">Total Fees</td><td>' . htmlspecialchars($feeFormatted) . '</td></tr>
            </table>
        </div>

        <div class="section">
            <h2>Engagement Scope</h2>
            <p>' . nl2br(htmlspecialchars($description)) . '</p>
        </div>

        <div class="section">
            <h2>Mandate</h2>
            <p>
                LegalPro Case Manager is engaged to represent the client in the matter described above. The firm
                commits to providing diligent, confidential, and professional legal services, while the client agrees
                to furnish accurate information and timely instructions.
            </p>
        </div>

        <div class="section">
            <h2>Financial Terms</h2>
            <p>
                The total professional fee for this mandate is ' . htmlspecialchars($feeFormatted) . '. Additional
                disbursements (court fees, travel, experts) will be invoiced separately upon prior notice.
                Payment schedules and retainer deposits may be arranged according to mutual agreement.
            </p>
        </div>

        <div class="section">
            <h2>General Conditions</h2>
            <p>
                1. The firm will keep the client informed of progress and material developments.<br>
                2. The client will provide prompt responses and complete documentation to support the case.<br>
                3. Either party may terminate the engagement upon written notice; accrued fees remain payable.<br>
                4. Confidentiality is maintained in accordance with professional obligations and law.
            </p>
        </div>

        <div class="signature">
            <div>
                <span>Client Signature</span>
                Date: ____________________
            </div>
            <div>
                <span>Firm Representative</span>
                Date: ____________________
            </div>
        </div>
    </div>
</body>
</html>';

$fileName = 'Case-Contract-' . preg_replace('/[^A-Za-z0-9_\-]/', '', $caseNumber) . '.html';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($bodyHtml));
echo $bodyHtml;
exit;

