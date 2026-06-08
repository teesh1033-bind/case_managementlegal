<?php
/**
 * Case Events Logging Functions
 * Tracks all changes made to cases for audit trail
 */

// Function to log case events
function logCaseEvent($pdo, $caseId, $eventType, $description, $oldValue = null, $newValue = null, $userId = null) {
    try {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO case_events
            (case_id, user_id, event_type, event_description, old_value, new_value, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $caseId,
            $userId,
            $eventType,
            $description,
            $oldValue,
            $newValue,
            $ipAddress,
            $userAgent
        ]);

        return true;
    } catch (PDOException $e) {
        // Log error but don't fail the main operation
        error_log("Failed to log case event: " . $e->getMessage());
        return false;
    }
}

// Function to get case events
function getCaseEvents($pdo, $caseId, $limit = 50) {
    try {
        $stmt = $pdo->prepare("
            SELECT ce.*,
                   u.username,
                   CASE
                       WHEN ce.user_id IS NULL THEN 'System'
                       ELSE COALESCE(u.username, 'Unknown User')
                   END as user_name
            FROM case_events ce
            LEFT JOIN users u ON u.id = ce.user_id
            WHERE ce.case_id = ?
            ORDER BY ce.created_at DESC
            LIMIT ?
        ");

        $stmt->execute([$caseId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Function to log case field changes
function logCaseFieldChange($pdo, $caseId, $fieldName, $oldValue, $newValue, $userId = null) {
    if ($oldValue === $newValue) {
        return false; // No change
    }

    $description = "Case {$fieldName} updated";
    if (is_null($oldValue) && !is_null($newValue)) {
        $description = "Case {$fieldName} set to: {$newValue}";
    } elseif (!is_null($oldValue) && is_null($newValue)) {
        $description = "Case {$fieldName} cleared (was: {$oldValue})";
    } elseif (!is_null($oldValue) && !is_null($newValue)) {
        $description = "Case {$fieldName} changed from '{$oldValue}' to '{$newValue}'";
    }

    return logCaseEvent($pdo, $caseId, 'field_update', $description, $oldValue, $newValue, $userId);
}

// Function to log case status changes
function logCaseStatusChange($pdo, $caseId, $oldStatus, $newStatus, $userId = null) {
    if ($oldStatus === $newStatus) {
        return false;
    }

    $description = "Case status changed from '{$oldStatus}' to '{$newStatus}'";
    return logCaseEvent($pdo, $caseId, 'status_change', $description, $oldStatus, $newStatus, $userId);
}

// Function to log lawyer assignments
function logLawyerAssignment($pdo, $caseId, $lawyerId, $lawyerName, $action = 'assigned', $userId = null) {
    $description = "Lawyer {$lawyerName} {$action} to case";
    return logCaseEvent($pdo, $caseId, 'lawyer_' . $action, $description, null, $lawyerName, $userId);
}

// Function to log document uploads
function logDocumentUpload($pdo, $caseId, $documentName, $uploadedBy, $userId = null) {
    $description = "Document '{$documentName}' uploaded";
    return logCaseEvent($pdo, $caseId, 'document_upload', $description, null, $documentName, $userId);
}

// Function to log comment additions
function logCommentAdded($pdo, $caseId, $commentType, $userId = null) {
    $description = "New {$commentType} comment added";
    return logCaseEvent($pdo, $caseId, 'comment_added', $description, null, null, $userId);
}

// Function to log invoice creation
function logInvoiceCreated($pdo, $caseId, $invoiceNumber, $amount, $userId = null) {
    $description = "Invoice {$invoiceNumber} created for $" . number_format($amount, 2);
    return logCaseEvent($pdo, $caseId, 'invoice_created', $description, null, $invoiceNumber, $userId);
}

// Function to log payment received
function logPaymentReceived($pdo, $caseId, $amount, $method, $userId = null) {
    $description = "Payment of $" . number_format($amount, 2) . " received via {$method}";
    return logCaseEvent($pdo, $caseId, 'payment_received', $description, null, number_format($amount, 2), $userId);
}

// Function to log appointment scheduling
function logAppointmentScheduled($pdo, $caseId, $appointmentDate, $lawyerName, $userId = null) {
    $description = "Appointment scheduled for " . date('M j, Y g:i A', strtotime($appointmentDate));
    if ($lawyerName) {
        $description .= " with {$lawyerName}";
    }
    return logCaseEvent($pdo, $caseId, 'appointment_scheduled', $description, null, $appointmentDate, $userId);
}

// Function to format event description for display
function formatCaseEvent($event) {
    $timestamp = date('M j, Y g:i A', strtotime($event['created_at']));
    $user = htmlspecialchars($event['user_name']);
    $description = htmlspecialchars($event['event_description']);

    // Add badges for different event types
    $badgeClass = 'bg-secondary';
    switch ($event['event_type']) {
        case 'status_change':
            $badgeClass = 'bg-warning';
            break;
        case 'field_update':
            $badgeClass = 'bg-info';
            break;
        case 'lawyer_assigned':
        case 'lawyer_unassigned':
            $badgeClass = 'bg-success';
            break;
        case 'document_upload':
            $badgeClass = 'bg-primary';
            break;
        case 'comment_added':
            $badgeClass = 'bg-light text-dark';
            break;
        case 'invoice_created':
            $badgeClass = 'bg-danger';
            break;
        case 'payment_received':
            $badgeClass = 'bg-success';
            break;
        case 'appointment_scheduled':
            $badgeClass = 'bg-info';
            break;
    }

    $eventTypeLabel = ucwords(str_replace('_', ' ', $event['event_type']));

    return [
        'timestamp' => $timestamp,
        'user' => $user,
        'description' => $description,
        'event_type' => $eventTypeLabel,
        'badge_class' => $badgeClass
    ];
}
?>
