<?php

function ensure_task_support_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $pdo->query('ALTER TABLE tasks ADD COLUMN task_comment TEXT NULL');
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false && stripos($e->getMessage(), 'duplicate column name') === false) {
            // Column may already exist from manual migration.
        }
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS task_lawyers (
                task_id INT NOT NULL,
                lawyer_id INT NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (task_id, lawyer_id),
                INDEX idx_task_lawyers_lawyer_id (lawyer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            INSERT IGNORE INTO task_lawyers (task_id, lawyer_id)
            SELECT id, assigned_lawyer_id
            FROM tasks
            WHERE assigned_lawyer_id IS NOT NULL AND assigned_lawyer_id > 0
        ");
    } catch (PDOException $e) {
        // task_lawyers optional; assigned_lawyer_id remains the fallback.
    }

    $ready = true;
}

function lawyer_has_task_access(PDO $pdo, int $taskId, int $lawyerId): bool
{
    if ($taskId <= 0 || $lawyerId <= 0) {
        return false;
    }

    ensure_task_support_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT t.id
        FROM tasks t
        LEFT JOIN task_lawyers tl ON tl.task_id = t.id AND tl.lawyer_id = ?
        WHERE t.id = ?
          AND (t.assigned_lawyer_id = ? OR tl.lawyer_id IS NOT NULL)
        LIMIT 1
    ");
    $stmt->execute([$lawyerId, $taskId, $lawyerId]);

    return (bool) $stmt->fetchColumn();
}

function lawyer_task_access_sql(): string
{
    return '(t.assigned_lawyer_id = ? OR EXISTS (
        SELECT 1 FROM task_lawyers tl
        WHERE tl.task_id = t.id AND tl.lawyer_id = ?
    ))';
}

function render_admin_task_comment_html(string $comment): string
{
    $comment = trim($comment);
    if ($comment === '') {
        return '<span class="text-muted">—</span>';
    }

    return '<div class="admin-task-comment mt-2">'
        . '<span class="admin-task-comment__label">Commentaire avocat</span>'
        . '<p class="admin-task-comment__text mb-0">' . nl2br(htmlspecialchars($comment)) . '</p>'
        . '</div>';
}

function ensure_task_comment_files_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    ensure_task_support_schema($pdo);

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS task_comment_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                file_size INT UNSIGNED NOT NULL DEFAULT 0,
                uploaded_by_lawyer_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_task_comment_files_task_id (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Table may already exist.
    }

    $ready = true;
}

function lawyer_task_week_bounds(): array
{
    $today = new DateTime('today');
    $dayOfWeek = (int) $today->format('N');
    $weekStart = (clone $today)->modify('-' . ($dayOfWeek - 1) . ' days');
    $weekEnd = (clone $weekStart)->modify('+6 days');

    return [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')];
}

function lawyer_task_is_open_status(string $status): bool
{
    $status = strtolower(str_replace(' ', '_', trim($status)));

    return !in_array($status, ['closed', 'completed', 'cancelled'], true);
}

function lawyer_task_due_state(?string $dueDate, string $status): string
{
    if ($dueDate === null || trim($dueDate) === '' || !lawyer_task_is_open_status($status)) {
        return 'none';
    }

    $dueTs = strtotime($dueDate);
    if ($dueTs === false) {
        return 'none';
    }

    $today = strtotime(date('Y-m-d'));
    if ($dueTs < $today) {
        return 'overdue';
    }
    if ($dueTs === $today) {
        return 'today';
    }

    [$weekStart, $weekEnd] = lawyer_task_week_bounds();
    if ($dueDate >= $weekStart && $dueDate <= $weekEnd) {
        return 'this_week';
    }

    return 'upcoming';
}

function lawyer_task_due_filter_sql(string $dueFilter): array
{
    $dueFilter = strtolower(trim($dueFilter));
    if (!in_array($dueFilter, ['overdue', 'today', 'this_week'], true)) {
        return ['', []];
    }

    $base = " AND t.due_date IS NOT NULL
        AND LOWER(COALESCE(t.status, 'pending')) NOT IN ('closed', 'completed', 'cancelled')";

    if ($dueFilter === 'overdue') {
        return [$base . ' AND t.due_date < CURDATE()', []];
    }

    if ($dueFilter === 'today') {
        return [$base . ' AND t.due_date = CURDATE()', []];
    }

    [$weekStart, $weekEnd] = lawyer_task_week_bounds();

    return [$base . ' AND t.due_date BETWEEN ? AND ?', [$weekStart, $weekEnd]];
}

function lawyer_task_due_counts(PDO $pdo, int $lawyerId): array
{
    if ($lawyerId <= 0) {
        return ['overdue' => 0, 'today' => 0, 'this_week' => 0];
    }

    ensure_task_support_schema($pdo);
    [$weekStart, $weekEnd] = lawyer_task_week_bounds();

    $sql = "
        SELECT
            SUM(CASE WHEN t.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count,
            SUM(CASE WHEN t.due_date = CURDATE() THEN 1 ELSE 0 END) AS today_count,
            SUM(CASE WHEN t.due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS week_count
        FROM tasks t
        WHERE " . lawyer_task_access_sql() . "
          AND t.due_date IS NOT NULL
          AND LOWER(COALESCE(t.status, 'pending')) NOT IN ('closed', 'completed', 'cancelled')
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$lawyerId, $lawyerId, $weekStart, $weekEnd]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'overdue' => (int) ($row['overdue_count'] ?? 0),
            'today' => (int) ($row['today_count'] ?? 0),
            'this_week' => (int) ($row['week_count'] ?? 0),
        ];
    } catch (PDOException $e) {
        return ['overdue' => 0, 'today' => 0, 'this_week' => 0];
    }
}

function lawyer_fetch_task_comment_files(PDO $pdo, array $taskIds): array
{
    ensure_task_comment_files_schema($pdo);

    $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
    if ($taskIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, task_id, original_name, stored_path, file_size, created_at
        FROM task_comment_files
        WHERE task_id IN ($placeholders)
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute($taskIds);

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $taskId = (int) ($row['task_id'] ?? 0);
        if ($taskId <= 0) {
            continue;
        }
        if (!isset($grouped[$taskId])) {
            $grouped[$taskId] = [];
        }
        $grouped[$taskId][] = $row;
    }

    return $grouped;
}

function lawyer_task_comment_allowed_extensions(): array
{
    return ['pdf', 'doc', 'docx', 'txt', 'png', 'jpg', 'jpeg', 'gif'];
}

function lawyer_save_task_comment_file(PDO $pdo, int $taskId, int $lawyerId, array $fileInfo): array
{
    ensure_task_comment_files_schema($pdo);

    if ($taskId <= 0 || !lawyer_has_task_access($pdo, $taskId, $lawyerId)) {
        return ['ok' => false, 'error' => 'Task not found or access denied.'];
    }

    if (!isset($fileInfo['error']) || (int) $fileInfo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Please choose a valid file to upload.'];
    }

    $originalName = trim((string) ($fileInfo['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, lawyer_task_comment_allowed_extensions(), true)) {
        return [
            'ok' => false,
            'error' => 'Unsupported file type. Allowed: ' . implode(', ', lawyer_task_comment_allowed_extensions()),
        ];
    }

    $maxBytes = 5 * 1024 * 1024;
    if ((int) ($fileInfo['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'File is too large. Maximum size is 5 MB.'];
    }

    $uploadRoot = dirname(__DIR__) . '/uploads/task_comments';
    if (!is_dir($uploadRoot)) {
        mkdir($uploadRoot, 0755, true);
    }

    $safeBase = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    if ($safeBase === '') {
        $safeBase = 'attachment';
    }
    $storedName = $safeBase . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $uploadRoot . '/' . $storedName;
    if (!move_uploaded_file($fileInfo['tmp_name'], $targetPath)) {
        return ['ok' => false, 'error' => 'Unable to store the uploaded file.'];
    }

    $relativePath = 'uploads/task_comments/' . $storedName;
    $stmt = $pdo->prepare("
        INSERT INTO task_comment_files (task_id, original_name, stored_path, file_size, uploaded_by_lawyer_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $taskId,
        $originalName,
        $relativePath,
        (int) ($fileInfo['size'] ?? 0),
        $lawyerId > 0 ? $lawyerId : null,
    ]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

function lawyer_get_task_comment_file(PDO $pdo, int $fileId, int $lawyerId): ?array
{
    ensure_task_comment_files_schema($pdo);

    if ($fileId <= 0 || $lawyerId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT f.*
        FROM task_comment_files f
        INNER JOIN tasks t ON t.id = f.task_id
        WHERE f.id = ?
          AND " . lawyer_task_access_sql() . "
        LIMIT 1
    ");
    $stmt->execute([$fileId, $lawyerId, $lawyerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function lawyer_render_task_due_alert_badge(string $dueState): string
{
    switch ($dueState) {
        case 'overdue':
            return '<span class="lt-task-due-alert lt-task-due-alert--overdue">Overdue</span>';
        case 'today':
            return '<span class="lt-task-due-alert lt-task-due-alert--today">Due today</span>';
        case 'this_week':
            return '<span class="lt-task-due-alert lt-task-due-alert--week">This week</span>';
        default:
            return '';
    }
}

function lawyer_render_task_comment_files_html(array $files): string
{
    if ($files === []) {
        return '';
    }

    $html = '<div class="lt-task-comment-files">';
    foreach ($files as $file) {
        $fileId = (int) ($file['id'] ?? 0);
        $name = htmlspecialchars((string) ($file['original_name'] ?? 'Attachment'));
        $html .= '<a class="lt-task-comment-file" href="tasks.php?action=download_task_file&amp;file_id=' . $fileId . '">'
            . '<i class="fas fa-paperclip me-1" aria-hidden="true"></i>' . $name . '</a>';
    }
    $html .= '</div>';

    return $html;
}

function lawyer_build_tasks_filter_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    unset($params['action'], $params['file_id']);

    return 'tasks.php' . ($params !== [] ? '?' . http_build_query($params) : '');
}
