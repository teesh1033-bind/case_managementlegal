<?php

/**
 * Random alphanumeric invoice and receipt reference numbers.
 */

function legalpro_random_finance_segment(int $length = 8): string
{
    $length = max(4, min(16, $length));
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $maxIndex = strlen($chars) - 1;
    $segment = '';

    for ($i = 0; $i < $length; $i++) {
        $segment .= $chars[random_int(0, $maxIndex)];
    }

    return $segment;
}

function legalpro_build_finance_reference(string $prefix, int $segmentLength = 8): string
{
    $prefix = strtoupper(trim($prefix));
    $prefix = rtrim($prefix, '-');

    return $prefix . '-' . legalpro_random_finance_segment($segmentLength);
}

function legalpro_finance_reference_exists(PDO $pdo, string $table, string $column, string $value): bool
{
    $allowedTables = ['invoices' => 'invoice_number', 'payments' => 'receipt_number'];
    if (!isset($allowedTables[$table]) || $allowedTables[$table] !== $column) {
        throw new InvalidArgumentException('Unsupported finance reference lookup.');
    }

    $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
    $stmt->execute([$value]);

    return (bool) $stmt->fetchColumn();
}

function legalpro_generate_unique_finance_reference(
    PDO $pdo,
    string $table,
    string $column,
    string $prefix,
    int $segmentLength = 8,
    int $maxAttempts = 30
): string {
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $candidate = legalpro_build_finance_reference($prefix, $segmentLength);
        if (!legalpro_finance_reference_exists($pdo, $table, $column, $candidate)) {
            return $candidate;
        }
    }

    return legalpro_build_finance_reference($prefix, $segmentLength + 2);
}

function legalpro_generate_invoice_number(PDO $pdo): string
{
    return legalpro_generate_unique_finance_reference($pdo, 'invoices', 'invoice_number', 'INV');
}

function legalpro_ensure_payment_receipt_number_column(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE payments ADD COLUMN receipt_number VARCHAR(100) NULL AFTER reference');
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false) {
            throw $e;
        }
    }

    $ensured = true;
}

function legalpro_generate_receipt_number(PDO $pdo): string
{
    legalpro_ensure_payment_receipt_number_column($pdo);

    return legalpro_generate_unique_finance_reference($pdo, 'payments', 'receipt_number', 'RCP');
}

function legalpro_resolve_receipt_number(PDO $pdo, int $paymentId, ?array $payment = null): string
{
    legalpro_ensure_payment_receipt_number_column($pdo);

    if ($payment === null && $paymentId > 0) {
        $stmt = $pdo->prepare('SELECT id, receipt_number FROM payments WHERE id = ? LIMIT 1');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!is_array($payment) || (int) ($payment['id'] ?? 0) <= 0) {
        return legalpro_generate_receipt_number($pdo);
    }

    $existing = trim((string) ($payment['receipt_number'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }

    $number = legalpro_generate_receipt_number($pdo);
    $update = $pdo->prepare('UPDATE payments SET receipt_number = ? WHERE id = ?');
    $update->execute([$number, (int) $payment['id']]);

    return $number;
}

function legalpro_legacy_receipt_number(int $paymentId): string
{
    return 'RC-' . str_pad((string) max(0, $paymentId), 6, '0', STR_PAD_LEFT);
}
