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
