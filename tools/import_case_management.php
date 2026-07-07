<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';

$sqlFile = __DIR__ . '/../case_management.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "SQL file not found: {$sqlFile}" . PHP_EOL);
    exit(1);
}

$content = file_get_contents($sqlFile);
if ($content === false) {
    fwrite(STDERR, "Unable to read SQL file." . PHP_EOL);
    exit(1);
}

// Strip block comments and line comments to avoid parsing issues.
$content = preg_replace('!/\*.*?\*/!s', '', $content) ?? $content;
$content = preg_replace('/^\s*--.*$/m', '', $content) ?? $content;

$statements = [];
$buffer = '';
$inSingle = false;
$inDouble = false;
$length = strlen($content);

for ($i = 0; $i < $length; $i++) {
    $char = $content[$i];
    $prev = $i > 0 ? $content[$i - 1] : '';

    if ($char === "'" && !$inDouble && $prev !== '\\') {
        $inSingle = !$inSingle;
    } elseif ($char === '"' && !$inSingle && $prev !== '\\') {
        $inDouble = !$inDouble;
    }

    if ($char === ';' && !$inSingle && !$inDouble) {
        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }
        $buffer = '';
        continue;
    }

    $buffer .= $char;
}

$tail = trim($buffer);
if ($tail !== '') {
    $statements[] = $tail;
}

$executed = 0;

try {
    foreach ($statements as $sql) {
        $normalized = strtoupper(trim($sql));
        if ($normalized === 'START TRANSACTION' || $normalized === 'COMMIT' || $normalized === 'ROLLBACK') {
            continue;
        }
        $pdo->exec($sql);
        $executed++;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Import failed after {$executed} statements: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$countStmt = $pdo->query('SHOW TABLES');
$tables = $countStmt ? $countStmt->fetchAll(PDO::FETCH_COLUMN) : [];

echo "Import completed." . PHP_EOL;
echo "Statements executed: {$executed}" . PHP_EOL;
echo "Tables in database: " . count($tables) . PHP_EOL;
