<?php

require_once __DIR__ . '/case_events.php';
require_once __DIR__ . '/bank_accounts.php';

function legalpro_payments_portal_load(PDO $pdo): array
{
    $message = '';
    $messageType = '';
    if (isset($_GET['msg']) && isset($_GET['type'])) {
        $message = urldecode($_GET['msg']);
        $messageType = $_GET['type'];
    }

    $selectedCaseId = isset($_GET['case_id']) ? (int) $_GET['case_id'] : 0;

    $caseMigrations = [
        "ADD COLUMN user_id INT NULL AFTER client_id",
        "ADD COLUMN priority VARCHAR(50) DEFAULT 'Normal' AFTER status",
        "ADD COLUMN category VARCHAR(50) DEFAULT 'Civil' AFTER priority",
        "ADD COLUMN estimated_fees DECIMAL(12,2) DEFAULT 0.00 AFTER category",
        "ADD COLUMN start_date DATE NULL AFTER estimated_fees",
        "ADD COLUMN expected_completion DATE NULL AFTER start_date",
    ];

    foreach ($caseMigrations as $migration) {
        try {
            $pdo->query('ALTER TABLE cases ' . $migration);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'duplicate column') === false) {
                throw $e;
            }
        }
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                case_id INT NOT NULL,
                client_id INT NOT NULL,
                invoice_id INT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                method VARCHAR(50) DEFAULT 'cash',
                reference VARCHAR(100),
                notes TEXT,
                payment_date DATE DEFAULT NULL,
                recorded_by VARCHAR(100),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        $message = 'Unable to prepare payments table: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }

    try {
        $pdo->query('ALTER TABLE payments ADD COLUMN invoice_id INT NULL AFTER client_id');
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false) {
            throw $e;
        }
    }

    try {
        $pdo->query('ALTER TABLE payments ADD COLUMN bank_account_slot TINYINT UNSIGNED NULL AFTER invoice_id');
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false) {
            throw $e;
        }
    }

    try {
        $pdo->query('ALTER TABLE payments ADD CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL');
    } catch (PDOException $e) {
        $msgText = $e->getMessage();
        if (stripos($msgText, 'Duplicate') === false && stripos($msgText, 'already exists') === false && stripos($msgText, 'errno: 150') === false) {
            throw $e;
        }
    }

    $prefillAmount = isset($_GET['amount']) ? (float) $_GET['amount'] : 0;
    $selectedInvoiceId = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;

    $caseInvoices = [];
    try {
        $invoiceRows = $pdo->query("
            SELECT
                i.id,
                i.case_id,
                i.invoice_number,
                i.amount,
                COALESCE(SUM(p.amount), 0) AS paid_total
            FROM invoices i
            LEFT JOIN payments p ON p.invoice_id = i.id
            WHERE i.case_id IS NOT NULL
            GROUP BY i.id, i.case_id, i.invoice_number, i.amount
            ORDER BY i.id ASC
        ")->fetchAll();
        foreach ($invoiceRows as $row) {
            $caseIdKey = (int) $row['case_id'];
            $invoiceAmount = (float) $row['amount'];
            $paidTotal = (float) $row['paid_total'];
            $balance = max($invoiceAmount - $paidTotal, 0);
            $invoiceNumber = !empty($row['invoice_number'])
                ? $row['invoice_number']
                : 'INV-' . str_pad((string) $row['id'], 4, '0', STR_PAD_LEFT);

            if (!isset($caseInvoices[$caseIdKey])) {
                $caseInvoices[$caseIdKey] = [];
            }

            $caseInvoices[$caseIdKey][] = [
                'id' => (int) $row['id'],
                'number' => $invoiceNumber,
                'amount_raw' => $invoiceAmount,
                'paid_raw' => $paidTotal,
                'balance_raw' => $balance,
                'amount' => formatCurrency($invoiceAmount),
                'balance' => formatCurrency($balance),
                'label' => $balance <= 0.01
                    ? $invoiceNumber . ' · ' . formatCurrency($invoiceAmount) . ' (paid in full)'
                    : $invoiceNumber . ' · ' . formatCurrency($invoiceAmount) . ' (' . formatCurrency($balance) . ' remaining)',
                'is_paid' => $balance <= 0.01,
            ];
        }
    } catch (PDOException $e) {
        $caseInvoices = [];
    }

    $formData = [
        'case_id' => $selectedCaseId ?: '',
        'invoice_id' => $selectedInvoiceId ?: '',
        'bank_account_slot' => '',
        'amount' => $prefillAmount > 0 ? $prefillAmount : '',
        'method' => 'cash',
        'reference' => '',
        'notes' => '',
        'payment_date' => date('Y-m-d'),
        'recorded_by' => 'admin',
    ];

    $configuredBankAccounts = [];
    foreach (getBankAccounts() as $account) {
        $slot = (int) ($account['slot'] ?? 0);
        if ($slot < 1 || !bank_account_is_configured($account)) {
            continue;
        }
        $configuredBankAccounts[$slot] = bank_account_option_label($account, $slot);
    }
    $showBankAccountSelector = count($configuredBankAccounts) >= 3;
    $bankAccountOptions = '<option value="">Select receiving account</option>';
    if ($showBankAccountSelector) {
        foreach ($configuredBankAccounts as $slot => $label) {
            $selected = ((string) $formData['bank_account_slot'] === (string) $slot) ? ' selected' : '';
            $bankAccountOptions .= '<option value="' . $slot . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
    }

    if ($selectedCaseId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST' && $prefillAmount <= 0) {
        $prefilled = false;

        if ($selectedInvoiceId > 0) {
            foreach ($caseInvoices[$selectedCaseId] ?? [] as $invoiceOption) {
                if ($invoiceOption['id'] === $selectedInvoiceId && $invoiceOption['balance_raw'] > 0) {
                    $formData['amount'] = $invoiceOption['balance_raw'];
                    $prefilled = true;
                    break;
                }
            }
        }

        if (!$prefilled && !empty($caseInvoices[$selectedCaseId])) {
            foreach ($caseInvoices[$selectedCaseId] as $invoiceOption) {
                if ($invoiceOption['balance_raw'] <= 0.01) {
                    continue;
                }
                if (!$selectedInvoiceId) {
                    $selectedInvoiceId = $invoiceOption['id'];
                    $formData['invoice_id'] = $invoiceOption['id'];
                }
                if ((int) $formData['invoice_id'] === $invoiceOption['id']) {
                    $formData['amount'] = $invoiceOption['balance_raw'];
                    $prefilled = true;
                    break;
                }
            }
        }

        if (!$prefilled) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(c.estimated_fees, 0) AS estimated_fees,
                           COALESCE(SUM(p.amount), 0) AS paid_total
                    FROM cases c
                    LEFT JOIN payments p ON p.case_id = c.id
                    WHERE c.id = ?
                    GROUP BY c.id, c.estimated_fees
                ");
                $stmt->execute([$selectedCaseId]);
                $casePrefill = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($casePrefill) {
                    $remaining = max((float) $casePrefill['estimated_fees'] - (float) $casePrefill['paid_total'], 0);
                    if ($remaining > 0) {
                        $formData['amount'] = $remaining;
                    }
                }
            } catch (PDOException $e) {
                // Continue without amount prefill
            }
        }
    }

    $allowedMethods = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'card' => 'Card',
        'cheque' => 'Cheque',
        'mobile' => 'Mobile Payment',
        'invoice' => 'Invoice',
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $caseId = isset($_POST['case_id']) ? (int) $_POST['case_id'] : 0;
        $invoiceId = isset($_POST['invoice_id']) && $_POST['invoice_id'] !== '' ? (int) $_POST['invoice_id'] : 0;
        $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
        $bankAccountSlot = isset($_POST['bank_account_slot']) && $_POST['bank_account_slot'] !== ''
            ? (int) $_POST['bank_account_slot']
            : 0;
        $method = isset($_POST['method']) ? trim($_POST['method']) : 'cash';
        $reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $paymentDate = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : date('Y-m-d');
        $recordedBy = isset($_POST['recorded_by']) ? trim($_POST['recorded_by']) : 'admin';

        $formData = [
            'case_id' => $caseId ?: '',
            'invoice_id' => $invoiceId ?: '',
            'bank_account_slot' => $bankAccountSlot ?: '',
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'notes' => $notes,
            'payment_date' => $paymentDate,
            'recorded_by' => $recordedBy,
        ];

        if (empty($caseId) || $amount <= 0) {
            $message = 'Case and a positive amount are required.';
            $messageType = 'danger';
        } elseif (!isset($allowedMethods[$method])) {
            $message = 'Invalid payment method selected.';
            $messageType = 'danger';
        } elseif ($showBankAccountSelector && $bankAccountSlot > 0 && !isset($configuredBankAccounts[$bankAccountSlot])) {
            $message = 'Please select a valid receiving bank account.';
            $messageType = 'danger';
        } else {
            $dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
            if (!$dateObj) {
                $message = 'Invalid payment date.';
                $messageType = 'danger';
            } else {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.client_id, COALESCE(c.estimated_fees, 0) AS estimated_fees,
                           c.title, cl.first_name, cl.last_name
                    FROM cases c
                    LEFT JOIN clients cl ON cl.id = c.client_id
                    WHERE c.id = ?
                ");
                $stmt->execute([$caseId]);
                $caseRow = $stmt->fetch();

                if (!$caseRow) {
                    $message = 'Case not found.';
                    $messageType = 'danger';
                } else {
                    $canInsert = true;

                    $unpaidInvoices = [];
                    foreach ($caseInvoices[$caseId] ?? [] as $invoiceOption) {
                        if ($invoiceOption['balance_raw'] > 0.01) {
                            $unpaidInvoices[] = $invoiceOption;
                        }
                    }

                    if ($invoiceId <= 0 && count($unpaidInvoices) === 1) {
                        $invoiceId = $unpaidInvoices[0]['id'];
                        $formData['invoice_id'] = $invoiceId;
                    } elseif ($invoiceId <= 0 && count($unpaidInvoices) > 1) {
                        $message = 'Please select which invoice this payment applies to.';
                        $messageType = 'danger';
                        $canInsert = false;
                    }

                    if ($canInsert && $invoiceId > 0) {
                        $invStmt = $pdo->prepare("
                            SELECT i.id, i.case_id, i.amount, i.invoice_number,
                                   COALESCE(SUM(p.amount), 0) AS paid_total
                            FROM invoices i
                            LEFT JOIN payments p ON p.invoice_id = i.id
                            WHERE i.id = ?
                            GROUP BY i.id, i.case_id, i.amount, i.invoice_number
                        ");
                        $invStmt->execute([$invoiceId]);
                        $invoiceRow = $invStmt->fetch();

                        if (!$invoiceRow || (int) $invoiceRow['case_id'] !== (int) $caseRow['id']) {
                            $message = 'Selected invoice does not belong to this case.';
                            $messageType = 'danger';
                            $canInsert = false;
                        } else {
                            $invoiceBalance = max((float) $invoiceRow['amount'] - (float) $invoiceRow['paid_total'], 0);
                            if ($invoiceBalance <= 0.01) {
                                $invoiceLabel = !empty($invoiceRow['invoice_number'])
                                    ? $invoiceRow['invoice_number']
                                    : 'This invoice';
                                $message = $invoiceLabel . ' has already been paid.';
                                $messageType = 'warning';
                                $canInsert = false;
                            } elseif ($amount > $invoiceBalance + 0.001) {
                                $message = 'Payment exceeds the remaining invoice balance of ' . formatCurrency($invoiceBalance) . '.';
                                $messageType = 'danger';
                                $canInsert = false;
                            }
                        }
                    } elseif ($canInsert) {
                        $estimatedFees = isset($caseRow['estimated_fees']) ? (float) $caseRow['estimated_fees'] : 0;
                        if ($estimatedFees > 0) {
                            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE case_id = ?');
                            $sumStmt->execute([$caseRow['id']]);
                            $paidTotal = (float) $sumStmt->fetchColumn();
                            $remaining = max($estimatedFees - $paidTotal, 0);
                            if ($remaining <= 0.01) {
                                $message = 'This case is already fully paid.';
                                $messageType = 'warning';
                                $canInsert = false;
                            } elseif ($amount > $remaining) {
                                $message = 'Payment exceeds the remaining balance of ' . formatCurrency($remaining) . '.';
                                $messageType = 'danger';
                                $canInsert = false;
                            }
                        }
                    }

                    if ($canInsert) {
                        try {
                            $insert = $pdo->prepare("
                                INSERT INTO payments (case_id, client_id, invoice_id, bank_account_slot, amount, method, reference, notes, payment_date, recorded_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insert->execute([
                                $caseRow['id'],
                                $caseRow['client_id'],
                                $invoiceId > 0 ? $invoiceId : null,
                                ($showBankAccountSelector && $bankAccountSlot > 0) ? $bankAccountSlot : null,
                                $amount,
                                $method,
                                $reference,
                                $notes,
                                $dateObj->format('Y-m-d'),
                                $recordedBy,
                            ]);

                            if ($invoiceId > 0) {
                                $checkPaid = $pdo->prepare("
                                    SELECT i.amount, COALESCE(SUM(p.amount), 0) AS paid_total
                                    FROM invoices i
                                    LEFT JOIN payments p ON p.invoice_id = i.id
                                    WHERE i.id = ?
                                    GROUP BY i.id, i.amount
                                ");
                                $checkPaid->execute([$invoiceId]);
                                $invoicePaid = $checkPaid->fetch();
                                if ($invoicePaid && (float) $invoicePaid['paid_total'] >= (float) $invoicePaid['amount'] - 0.01) {
                                    $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = ?")->execute([$invoiceId]);
                                }
                            }

                            CaseEvents::trackPaymentAdded($caseRow['id'], [
                                'amount' => $amount,
                                'method' => $method,
                                'reference' => $reference,
                                'invoice_id' => $invoiceId > 0 ? $invoiceId : null,
                            ]);

                            $msg = 'Payment recorded successfully.';
                            header('Location: payments-record.php?msg=' . urlencode($msg) . '&type=success');
                            exit;
                        } catch (PDOException $e) {
                            $message = 'Unable to save payment: ' . htmlspecialchars($e->getMessage());
                            $messageType = 'danger';
                        }
                    }
                }
            }
        }
    }

    $cases = [];
    $caseOptions = '<option value="">Select case</option>';
    $ledgerOptions = '<option value="">View case...</option>';
    $caseLedger = [];
    $outstandingCount = 0;

    try {
        $stmt = $pdo->query("
            SELECT
                c.id,
                c.title,
                c.status,
                COALESCE(c.estimated_fees, 0) AS estimated_fees,
                CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
                COALESCE(SUM(p.amount), 0) AS paid_total,
                MAX(p.payment_date) AS last_payment
            FROM cases c
            LEFT JOIN clients cl ON cl.id = c.client_id
            LEFT JOIN payments p ON p.case_id = c.id
            GROUP BY c.id, c.title, c.status, c.estimated_fees, cl.first_name, cl.last_name
            ORDER BY c.created_at DESC
        ");
        $cases = $stmt->fetchAll();
    } catch (PDOException $e) {
        $cases = [];
        if (!$message) {
            $message = 'Unable to load cases list: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }

    $totalOutstanding = 0;

    foreach ($cases as $case) {
        $caseId = (int) $case['id'];
        $caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
        $estimated = isset($case['estimated_fees']) ? (float) $case['estimated_fees'] : 0;
        $paid = isset($case['paid_total']) ? (float) $case['paid_total'] : 0;
        $balance = max($estimated - $paid, 0);
        $lastPayment = isset($case['last_payment']) && $case['last_payment'] ? $case['last_payment'] : '—';
        $clientName = isset($case['client_name']) && $case['client_name'] ? $case['client_name'] : 'Unknown Client';

        $isFullyPaid = $estimated > 0 && $balance <= 0.01;

        if (!$isFullyPaid) {
            $selectedAttr = $formData['case_id'] == $caseId ? ' selected' : '';
            $caseOptions .= '<option value="' . $caseId . '"' . $selectedAttr . '>' . htmlspecialchars($caseNumber . ' · ' . $case['title'] . ' (' . $clientName . ')') . '</option>';
        }

        $ledgerOptions .= '<option value="' . $caseId . '">' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</option>';

        $caseLedger[$caseId] = [
            'case_number' => $caseNumber,
            'title' => $case['title'],
            'client' => $clientName,
            'estimated' => formatCurrency($estimated),
            'estimated_raw' => $estimated,
            'paid' => formatCurrency($paid),
            'paid_raw' => $paid,
            'balance' => formatCurrency($balance),
            'balance_raw' => $balance,
            'status' => $case['status'],
            'last_payment' => $lastPayment,
        ];

        if ($balance > 0.01) {
            $outstandingCount++;
            $totalOutstanding += $balance;
        }
    }

    try {
        $totalCollected = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) AS total FROM payments')->fetchColumn();
    } catch (PDOException $e) {
        $totalCollected = 0;
    }

    $activePaymentPlans = $outstandingCount;

    try {
        $paymentsThisMonth = (float) $pdo->query("
            SELECT COALESCE(SUM(amount), 0) FROM payments
            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ")->fetchColumn();
    } catch (PDOException $e) {
        $paymentsThisMonth = 0;
    }

    $methodsOptions = '';
    foreach ($allowedMethods as $value => $label) {
        $selected = $formData['method'] === $value ? ' selected' : '';
        $methodsOptions .= '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }

    if ($showBankAccountSelector) {
        $bankAccountOptions = '<option value="">Select receiving account</option>';
        foreach ($configuredBankAccounts as $slot => $label) {
            $selected = ((string) $formData['bank_account_slot'] === (string) $slot) ? ' selected' : '';
            $bankAccountOptions .= '<option value="' . $slot . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
    }

    $casesPaidOff = 0;
    foreach ($cases as $case) {
        $estimated = isset($case['estimated_fees']) ? (float) $case['estimated_fees'] : 0;
        $paid = isset($case['paid_total']) ? (float) $case['paid_total'] : 0;
        if ($estimated > 0 && $paid >= $estimated) {
            $casesPaidOff++;
        }
    }

    require_once __DIR__ . '/../inc/legalpro-icons.php';
    $iconStatCollected = legalpro_icon('banknote');
    $iconStatOutstanding = legalpro_icon('clock');
    $iconStatPaidOff = legalpro_icon('circle-check');
    $iconStatInstallments = legalpro_icon('briefcase');

    return [
        'message' => $message,
        'messageType' => $messageType,
        'formData' => $formData,
        'caseOptions' => $caseOptions,
        'showBankAccountSelector' => $showBankAccountSelector,
        'bankAccountOptions' => $bankAccountOptions,
        'ledgerOptions' => $ledgerOptions,
        'caseLedger' => $caseLedger,
        'caseInvoices' => $caseInvoices,
        'cases' => $cases,
        'totalCollected' => $totalCollected,
        'totalOutstanding' => $totalOutstanding,
        'activePaymentPlans' => $activePaymentPlans,
        'paymentsThisMonth' => $paymentsThisMonth,
        'casesPaidOff' => $casesPaidOff,
        'methodsOptions' => $methodsOptions,
        'allowedMethods' => $allowedMethods,
        'iconStatCollected' => $iconStatCollected,
        'iconStatOutstanding' => $iconStatOutstanding,
        'iconStatPaidOff' => $iconStatPaidOff,
        'iconStatInstallments' => $iconStatInstallments,
        'currencyZero' => formatCurrency(0),
        'caseDataJson' => json_encode($caseLedger),
        'caseInvoicesJson' => json_encode($caseInvoices),
    ];
}
