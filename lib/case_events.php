<?php
/**
 * Case Events Tracking System
 * Tracks all activities and changes for cases
 */

class CaseEvents {

    /**
     * Get current user information from session
     */
    private static function getCurrentUser() {
        $userId = null;
        $userType = 'system';

        if (isset($_SESSION['admin_id'])) {
            $userId = $_SESSION['admin_id'];
            $userType = 'admin';
        } elseif (isset($_SESSION['lawyer_user_id'])) {
            $userId = $_SESSION['lawyer_user_id'];
            $userType = 'lawyer';
        } elseif (isset($_SESSION['client_user_id'])) {
            $userId = $_SESSION['client_user_id'];
            $userType = 'client';
        }

        return ['id' => $userId, 'type' => $userType];
    }

    /**
     * Log an event for a case
     */
    public static function logEvent($caseId, $eventType, $description, $oldValue = null, $newValue = null) {
        global $pdo;

        $user = self::getCurrentUser();

        try {
            // Always try to create the table first
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `case_events` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `case_id` INT NOT NULL,
                  `user_id` INT NULL,
                  `event_type` VARCHAR(100) NOT NULL,
                  `event_description` TEXT NOT NULL,
                  `old_value` TEXT,
                  `new_value` TEXT,
                  `ip_address` VARCHAR(45),
                  `user_agent` TEXT,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $stmt = $pdo->prepare("
                INSERT INTO case_events
                (case_id, user_id, event_type, event_description, old_value, new_value, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([
                (int)$caseId, // Ensure case_id is stored as integer
                $user['id'],
                $eventType,
                $description,
                $oldValue,
                $newValue,
                isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
                isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null
            ]);

            return $result;
        } catch (PDOException $e) {
            // Log error but don't fail the main operation
            error_log("Failed to log case event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get events for a specific case
     */
    public static function getCaseEvents($caseId, $limit = null, $offset = 0) {
        global $pdo;

        try {
            $stmt = $pdo->prepare("
                SELECT
                    ce.*,
                    CASE
                        WHEN ce.user_id IS NULL THEN 'System'
                        WHEN u.role = 'admin' THEN 'Admin'
                        WHEN u.role = 'lawyer' THEN 'Lawyer'
                        WHEN u.role = 'client' THEN 'Client'
                        ELSE 'System'
                    END as user_display_name
                FROM case_events ce
                LEFT JOIN users u ON ce.user_id = u.id
                WHERE ce.case_id = ?
                ORDER BY ce.created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$caseId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Failed to get case events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Track case field changes
     */
    public static function trackCaseUpdate($caseId, $oldData, $newData) {
        $changedFields = [];

        // Compare fields that should be tracked
        $trackableFields = [
            'title' => 'Case Title',
            'description' => 'Description',
            'status' => 'Status',
            'priority' => 'Priority',
            'category' => 'Category',
            'start_date' => 'Start Date',
            'expected_completion' => 'Expected Completion Date'
        ];

        foreach ($trackableFields as $field => $displayName) {
            if (isset($oldData[$field]) && isset($newData[$field]) && $oldData[$field] != $newData[$field]) {
                $oldValue = isset($oldData[$field]) ? $oldData[$field] : '';
                $newValue = isset($newData[$field]) ? $newData[$field] : '';

                // Format certain fields for better display
                if (in_array($field, ['start_date', 'expected_completion'])) {
                    $oldValue = $oldValue ? date('M j, Y', strtotime($oldValue)) : '';
                    $newValue = $newValue ? date('M j, Y', strtotime($newValue)) : '';
                }

                self::logEvent(
                    $caseId,
                    'case_updated',
                    "Updated {$displayName}",
                    $oldValue,
                    $newValue
                );
            }
        }
    }

    /**
     * Track case creation
     */
    public static function trackCaseCreation($caseId, $caseData) {
        self::logEvent(
            $caseId,
            'case_created',
            "Case created: {$caseData['title']}",
            null,
            "Status: {$caseData['status']}, Priority: {$caseData['priority']}"
        );
    }

    /**
     * Track service changes
     */
    public static function trackServiceAdded($caseId, $serviceData) {
        self::logEvent(
            $caseId,
            'service_added',
            "Added service: {$serviceData['service_name']}",
            null,
            '$' . number_format($serviceData['price'], 2)
        );
    }

    public static function trackServiceUpdated($caseId, $serviceId, $oldData, $newData) {
        $changes = [];
        if ($oldData['service_name'] != $newData['service_name']) {
            $changes[] = "Name: '{$oldData['service_name']}' → '{$newData['service_name']}'";
        }
        if ($oldData['price'] != $newData['price']) {
            $changes[] = "Price: $" . number_format($oldData['price'], 2) . " → $" . number_format($newData['price'], 2);
        }

        if (!empty($changes)) {
            self::logEvent(
                $caseId,
                'service_updated',
                "Updated service: {$newData['service_name']}",
                implode(', ', $changes),
                null
            );
        }
    }

    public static function trackServiceDeleted($caseId, $serviceData) {
        self::logEvent(
            $caseId,
            'service_deleted',
            "Removed service: {$serviceData['service_name']}",
            '$' . number_format($serviceData['price'], 2),
            null
        );
    }

    /**
     * Track payment activities
     */
    public static function trackPaymentAdded($caseId, $paymentData) {
        self::logEvent(
            $caseId,
            'payment_added',
            "Payment recorded",
            null,
            '$' . number_format($paymentData['amount'], 2) . " ({$paymentData['method']})"
        );
    }

    /**
     * Track document activities
     */
    public static function trackDocumentUploaded($caseId, $documentData) {
        self::logEvent(
            $caseId,
            'document_uploaded',
            "Document uploaded: {$documentData['filename']}",
            null,
            $documentData['label'] ?: 'No label'
        );
    }

    public static function trackDocumentDeleted($caseId, $documentData) {
        self::logEvent(
            $caseId,
            'document_deleted',
            "Document deleted: {$documentData['filename']}",
            $documentData['label'] ?: 'No label',
            null
        );
    }

    /**
     * Track comment activities
     */
    public static function trackCommentAdded($caseId, $commentData) {
        $commentType = ucfirst($commentData['comment_type']);
        $preview = strlen($commentData['comment']) > 50
            ? substr($commentData['comment'], 0, 50) . '...'
            : $commentData['comment'];

        self::logEvent(
            $caseId,
            'comment_added',
            "{$commentType} comment added",
            null,
            $preview
        );
    }

    /**
     * Track lawyer assignment changes
     */
    public static function trackLawyerAssigned($caseId, $lawyerData) {
        self::logEvent(
            $caseId,
            'lawyer_assigned',
            "Lawyer assigned: {$lawyerData['first_name']} {$lawyerData['last_name']}",
            null,
            $lawyerData['email']
        );
    }

    public static function trackLawyerUnassigned($caseId, $lawyerData) {
        self::logEvent(
            $caseId,
            'lawyer_unassigned',
            "Lawyer unassigned: {$lawyerData['first_name']} {$lawyerData['last_name']}",
            $lawyerData['email'],
            null
        );
    }

    /**
     * Track appointment activities
     */
    public static function trackAppointmentCreated($caseId, $appointmentData) {
        $dateTime = date('M j, Y g:i A', strtotime($appointmentData['starts_at']));
        self::logEvent(
            $caseId,
            'appointment_created',
            "Appointment scheduled",
            null,
            $dateTime
        );
    }

    /**
     * Track task activities
     */
    public static function trackTaskCreated($caseId, $taskData) {
        self::logEvent(
            $caseId,
            'task_created',
            "Task created: {$taskData['title']}",
            null,
            $taskData['title']
        );
    }

    public static function trackTaskUpdated($caseId, $taskId, $oldStatus, $newStatus, $taskTitle) {
        if ($oldStatus !== $newStatus) {
            self::logEvent(
                $caseId,
                'task_status_changed',
                "Task '{$taskTitle}' status changed",
                $oldStatus,
                $newStatus
            );
        }
    }

    public static function trackCourtDateCreated($caseId, $title, $courtDate) {
        self::logEvent(
            $caseId,
            'court_date_created',
            "Court date created: {$title} on " . date('M d, Y g:i A', strtotime($courtDate)),
            null,
            $title
        );
    }

    public static function trackTaskCompleted($caseId, $taskData) {
        self::logEvent(
            $caseId,
            'task_completed',
            "Task completed: {$taskData['title']}",
            'in_progress',
            'completed'
        );
    }

    public static function trackTaskDeleted($caseId, $taskTitle) {
        self::logEvent(
            $caseId,
            'task_deleted',
            "Task deleted: {$taskTitle}",
            $taskTitle,
            null
        );
    }

    public static function trackAppointmentUpdated($caseId, $oldData, $newData) {
        $changes = [];
        if ($oldData['starts_at'] != $newData['starts_at']) {
            $changes[] = "Time: " . date('M j, Y g:i A', strtotime($oldData['starts_at'])) . " → " . date('M j, Y g:i A', strtotime($newData['starts_at']));
        }
        if ($oldData['status'] != $newData['status']) {
            $changes[] = "Status: " . ucfirst($oldData['status']) . " → " . ucfirst($newData['status']);
        }

        if (!empty($changes)) {
            self::logEvent(
                $caseId,
                'appointment_updated',
                "Appointment updated",
                implode(', ', $changes),
                null
            );
        }
    }

    public static function trackAppointmentDeleted($caseId, $appointmentData) {
        self::logEvent(
            $caseId,
            'appointment_deleted',
            "Appointment deleted: " . date('M j, Y g:i A', strtotime($appointmentData['appointment_date'])),
            null,
            "Appointment removed"
        );
    }

    /**
     * Render track of events HTML component
     */
    public static function renderEventsTimeline($caseId, $limit = 50) {
        $events = self::getCaseEvents($caseId, $limit);

        if (empty($events)) {
            return '<div class="text-center text-muted py-4">
                <i class="ni ni-time-alarm text-lg opacity-50 mb-2"></i>
                <p class="mb-0">No events recorded yet for this case.</p>
            </div>';
        }

        $html = '<div class="timeline timeline-one-side">';

        foreach ($events as $event) {
            $badgeClass = self::getEventBadgeClass($event['event_type']);
            $eventTypeLabel = self::getEventTypeLabel($event['event_type']);
            $timestamp = date('M j, Y \a\t g:i A', strtotime($event['created_at']));

            $oldValue = $event['old_value'] ? htmlspecialchars($event['old_value']) : '';
            $newValue = $event['new_value'] ? htmlspecialchars($event['new_value']) : '';

            // Use the user display name from the database query
            $userDisplay = $event['user_display_name'];

            $html .= '
            <div class="timeline-block mb-3">
                <span class="timeline-step">
                    <i class="ni ni-bell-55 text-' . self::getEventIconColor($event['event_type']) . '"></i>
                </span>
                <div class="timeline-content">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="text-dark text-sm font-weight-bold mb-0">' . htmlspecialchars($event['event_description']) . '</h6>
                        <span class="badge badge-sm ' . $badgeClass . '">' . $eventTypeLabel . '</span>
                    </div>
                    <div class="text-xs text-muted mb-2">
                        <i class="ni ni-single-02 me-1"></i>' . htmlspecialchars($userDisplay) . '
                        <i class="ni ni-watch-time ms-3 me-1"></i>' . $timestamp . '
                    </div>';

            // Show old/new values if they exist
            if (!empty($oldValue) || !empty($newValue)) {
                $html .= '<div class="text-xs">';
                if (!empty($oldValue) && !empty($newValue)) {
                    $html .= '<span class="text-danger">From: ' . $oldValue . '</span><br>';
                    $html .= '<span class="text-success">To: ' . $newValue . '</span>';
                } elseif (!empty($newValue)) {
                    $html .= '<span class="text-success">Value: ' . $newValue . '</span>';
                } elseif (!empty($oldValue)) {
                    $html .= '<span class="text-danger">Removed: ' . $oldValue . '</span>';
                }
                $html .= '</div>';
            }

            $html .= '</div></div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Get badge class for event type
     */
    private static function getEventBadgeClass($eventType) {
        $badgeClasses = [
            'case_created' => 'bg-gradient-success',
            'case_updated' => 'bg-gradient-info',
            'service_added' => 'bg-gradient-primary',
            'service_updated' => 'bg-gradient-warning',
            'service_deleted' => 'bg-gradient-danger',
            'payment_added' => 'bg-gradient-success',
            'document_uploaded' => 'bg-gradient-primary',
            'document_deleted' => 'bg-gradient-danger',
            'comment_added' => 'bg-gradient-secondary',
            'lawyer_assigned' => 'bg-gradient-success',
            'lawyer_unassigned' => 'bg-gradient-warning',
            'appointment_created' => 'bg-gradient-info',
            'appointment_updated' => 'bg-gradient-warning'
        ];

        return isset($badgeClasses[$eventType]) ? $badgeClasses[$eventType] : 'bg-gradient-secondary';
    }

    /**
     * Get icon color for event type
     */
    private static function getEventIconColor($eventType) {
        $iconColors = [
            'case_created' => 'success',
            'case_updated' => 'info',
            'service_added' => 'primary',
            'service_updated' => 'warning',
            'service_deleted' => 'danger',
            'payment_added' => 'success',
            'document_uploaded' => 'primary',
            'document_deleted' => 'danger',
            'comment_added' => 'secondary',
            'lawyer_assigned' => 'success',
            'lawyer_unassigned' => 'warning',
            'appointment_created' => 'info',
            'appointment_updated' => 'warning'
        ];

        return isset($iconColors[$eventType]) ? $iconColors[$eventType] : 'secondary';
    }

    /**
     * Get human-readable event type label
     */
    private static function getEventTypeLabel($eventType) {
        $labels = [
            'case_created' => 'Case Created',
            'case_updated' => 'Case Updated',
            'service_added' => 'Service Added',
            'service_updated' => 'Service Updated',
            'service_deleted' => 'Service Removed',
            'payment_added' => 'Payment Added',
            'document_uploaded' => 'Document Uploaded',
            'document_deleted' => 'Document Deleted',
            'comment_added' => 'Comment Added',
            'lawyer_assigned' => 'Lawyer Assigned',
            'lawyer_unassigned' => 'Lawyer Unassigned',
            'appointment_created' => 'Appointment Created',
            'appointment_updated' => 'Appointment Updated'
        ];

        return isset($labels[$eventType]) ? $labels[$eventType] : ucwords(str_replace('_', ' ', $eventType));
    }
}
?>
