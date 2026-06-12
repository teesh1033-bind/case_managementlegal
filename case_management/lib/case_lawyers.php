<?php

function lawyerHasCaseAccess(PDO $pdo, int $caseId, int $lawyerId): bool
{
    if ($caseId <= 0 || $lawyerId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM case_lawyers WHERE case_id = ? AND lawyer_id = ? LIMIT 1');
    $stmt->execute([$caseId, $lawyerId]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM appointments WHERE case_id = ? AND lawyer_id = ? LIMIT 1');
    $stmt->execute([$caseId, $lawyerId]);
    return (bool) $stmt->fetchColumn();
}

function ensureLawyerAssignedToCase(PDO $pdo, int $caseId, int $lawyerId): void
{
    if ($caseId <= 0 || $lawyerId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM case_lawyers WHERE case_id = ? AND lawyer_id = ? LIMIT 1');
    $stmt->execute([$caseId, $lawyerId]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO case_lawyers (case_id, lawyer_id, is_primary) VALUES (?, ?, 0)');
    $stmt->execute([$caseId, $lawyerId]);
}
