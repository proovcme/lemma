<?php

declare(strict_types=1);

use App\Core\Database;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (config('db.connection') !== 'sqlite') {
    fwrite(STDERR, "DB_CONNECTION must be sqlite for the demo privacy audit.\n");
    exit(1);
}

$pdo = Database::pdo();
$failures = [];
$count = static function (string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};
$tableExists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
};
$assertZero = static function (string $label, int $value) use (&$failures): void {
    if ($value !== 0) {
        $failures[] = $label . ': ' . $value;
    }
};

$assertZero('non-reserved user emails', $count("SELECT COUNT(*) FROM users WHERE email NOT LIKE '%@example.local'"));
$assertZero('person-like demo names', $count("SELECT COUNT(*) FROM users WHERE name NOT LIKE 'Демо · %'"));
$assertZero('non-demo projects', $count("SELECT COUNT(*) FROM projects WHERE code NOT LIKE 'D-%'"));
$assertZero('project file or model paths', $count(
    "SELECT COUNT(*) FROM projects
     WHERE COALESCE(file_folder_url, '') <> '' OR COALESCE(model_folder_url, '') <> ''"
));

foreach (['project_contacts', 'counterparties', 'attachments', 'public_links', 'revit_api_tokens', 'project_model_versions'] as $table) {
    if ($tableExists($table)) {
        $assertZero($table, $count('SELECT COUNT(*) FROM "' . $table . '"'));
    }
}

$forbiddenPatterns = [
    '/\/Users\//u',
    '/[A-Z]:\\\\Users\\\\/ui',
    '/\\\\\\\\[^\\\\\s]+\\\\/u',
    '/(?:\+7|8)\s*\(?\d{3}\)?[\s.-]+\d{3}/u',
];
$tables = $pdo->query(
    "SELECT name FROM sqlite_master
     WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
     ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $quotedTable = '"' . str_replace('"', '""', (string) $table) . '"';
    $columns = $pdo->query('PRAGMA table_info(' . $quotedTable . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        $type = strtoupper((string) ($column['type'] ?? ''));
        if (!str_contains($type, 'TEXT') && !str_contains($type, 'CHAR') && !str_contains($type, 'CLOB')) {
            continue;
        }
        $name = (string) $column['name'];
        $quotedColumn = '"' . str_replace('"', '""', $name) . '"';
        $values = $pdo->query(
            'SELECT ' . $quotedColumn . ' FROM ' . $quotedTable
            . ' WHERE ' . $quotedColumn . ' IS NOT NULL AND ' . $quotedColumn . " <> ''"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($values as $value) {
            $text = (string) $value;
            if (str_contains($text, '@') && !preg_match('/^[^\s@]+@example\.local$/i', $text)) {
                $failures[] = $table . '.' . $name . ': non-reserved email-like value';
                break;
            }
            foreach ($forbiddenPatterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    $failures[] = $table . '.' . $name . ': forbidden personal or internal marker';
                    break 2;
                }
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Demo privacy audit failed:\n- " . implode("\n- ", array_unique($failures)) . "\n");
    exit(1);
}

printf(
    "Demo privacy audit passed: users=%d projects=%d contacts=0 attachments=0 model_versions=0\n",
    $count('SELECT COUNT(*) FROM users'),
    $count('SELECT COUNT(*) FROM projects'),
);
