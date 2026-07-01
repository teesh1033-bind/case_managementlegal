<?php

function lawyer_task_status_options(): array
{
    $options = [
        'active' => 'Active',
        'pending' => 'Pending',
        'under_review' => 'Under review',
        'closed' => 'Closed',
    ];

    if (!function_exists('lawyer_tf')) {
        return $options;
    }

    $translated = [];
    foreach ($options as $key => $fallback) {
        $translated[$key] = lawyer_tf('status.' . $key, $fallback);
    }

    return $translated;
}

function lawyer_task_priority_options(): array
{
    $options = [
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    if (!function_exists('lawyer_tf')) {
        return $options;
    }

    $translated = [];
    foreach ($options as $key => $fallback) {
        $translated[$key] = lawyer_tf('priority.' . $key, $fallback);
    }

    return $translated;
}

function lawyer_case_status_options(): array
{
    return lawyer_task_status_options();
}

function lawyer_case_priority_options(): array
{
    return lawyer_task_priority_options();
}

function lawyer_task_status_keys(): array
{
    return array_keys(lawyer_task_status_options());
}

function lawyer_task_priority_keys(): array
{
    return array_keys(lawyer_task_priority_options());
}

function ensure_lawyer_task_vocabulary(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    ensure_task_support_schema($pdo);

    try {
        $pdo->exec("
            UPDATE tasks SET status = 'active' WHERE LOWER(status) = 'active'
        ");
        $pdo->exec("
            UPDATE tasks SET status = 'under_review' WHERE LOWER(status) IN ('in_progress', 'under review', 'under_review')
        ");
        $pdo->exec("
            UPDATE tasks SET status = 'closed' WHERE LOWER(status) IN ('completed', 'cancelled', 'closed')
        ");
        $pdo->exec("
            UPDATE tasks SET status = 'pending' WHERE LOWER(status) = 'pending'
        ");

        $pdo->exec("
            UPDATE tasks SET priority = 'normal' WHERE LOWER(priority) IN ('low', 'medium', 'normal')
        ");
        $pdo->exec("
            UPDATE tasks SET priority = 'high' WHERE LOWER(priority) = 'high'
        ");
        $pdo->exec("
            UPDATE tasks SET priority = 'urgent' WHERE LOWER(priority) = 'urgent'
        ");

        $pdo->exec("
            ALTER TABLE tasks
            MODIFY COLUMN status ENUM('active', 'pending', 'under_review', 'closed') NOT NULL DEFAULT 'pending'
        ");
        $pdo->exec("
            ALTER TABLE tasks
            MODIFY COLUMN priority ENUM('normal', 'high', 'urgent') NOT NULL DEFAULT 'normal'
        ");
    } catch (PDOException $e) {
        error_log('Lawyer task vocabulary migration: ' . $e->getMessage());
    }

    $ready = true;
}

function ensure_lawyer_case_vocabulary(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $pdo->exec("
            UPDATE cases SET status = 'active' WHERE LOWER(TRIM(status)) IN ('open', 'active')
        ");
        $pdo->exec("
            UPDATE cases SET status = 'under_review' WHERE LOWER(TRIM(status)) IN ('in_progress', 'in progress', 'under review', 'under_review')
        ");
        $pdo->exec("
            UPDATE cases SET status = 'closed' WHERE LOWER(TRIM(status)) IN ('closed', 'completed', 'cancelled')
        ");
        $pdo->exec("
            UPDATE cases SET status = 'pending' WHERE LOWER(TRIM(status)) = 'pending'
        ");

        $pdo->exec("
            UPDATE cases SET priority = 'Normal' WHERE LOWER(TRIM(priority)) IN ('low', 'medium', 'normal')
        ");
        $pdo->exec("
            UPDATE cases SET priority = 'High' WHERE LOWER(TRIM(priority)) = 'high'
        ");
        $pdo->exec("
            UPDATE cases SET priority = 'Urgent' WHERE LOWER(TRIM(priority)) = 'urgent'
        ");
    } catch (PDOException $e) {
        error_log('Lawyer case vocabulary migration: ' . $e->getMessage());
    }

    $ready = true;
}

function lawyer_normalize_task_status(string $status): string
{
    $key = strtolower(str_replace(' ', '_', trim($status)));
    $map = [
        'open' => 'active',
        'in_progress' => 'under_review',
        'active' => 'active',
        'pending' => 'pending',
        'under_review' => 'under_review',
        'completed' => 'closed',
        'cancelled' => 'closed',
        'closed' => 'closed',
    ];

    return $map[$key] ?? 'pending';
}

function lawyer_normalize_task_priority(string $priority): string
{
    $key = strtolower(trim($priority));
    $map = [
        'low' => 'normal',
        'medium' => 'normal',
        'normal' => 'normal',
        'high' => 'high',
        'urgent' => 'urgent',
    ];

    return $map[$key] ?? 'normal';
}

function lawyer_task_status_badge(string $status): string
{
    if (!function_exists('lawyer_status_label')) {
        require_once __DIR__ . '/lawyer-portal-i18n.php';
    }

    $key = lawyer_normalize_task_status($status);
    $classes = [
        'active' => 'ca-status-pill--scheduled',
        'pending' => 'ca-status-pill--pending',
        'under_review' => 'ca-status-pill--scheduled',
        'closed' => 'ca-status-pill--done',
    ];
    $class = $classes[$key] ?? 'ca-status-pill--muted';

    return '<span class="ca-status-pill ' . $class . '">' . htmlspecialchars(lawyer_status_label($status)) . '</span>';
}

function lawyer_task_priority_badge(string $priority): string
{
    if (!function_exists('lawyer_priority_label')) {
        require_once __DIR__ . '/lawyer-portal-i18n.php';
    }

    $key = lawyer_normalize_task_priority($priority);
    $classes = [
        'normal' => 'ca-status-pill--pending',
        'high' => 'ca-status-pill--declined',
        'urgent' => 'ca-status-pill--declined',
    ];
    $class = $classes[$key] ?? 'ca-status-pill--pending';

    return '<span class="ca-status-pill ' . $class . '">' . htmlspecialchars(lawyer_priority_label($priority)) . '</span>';
}

function lawyer_case_status_badge(string $status): string
{
    return lawyer_task_status_badge($status);
}

function lawyer_case_priority_badge(string $priority): string
{
    return lawyer_task_priority_badge($priority);
}
