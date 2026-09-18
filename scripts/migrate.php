<?php

declare(strict_types=1);

use App\Core\Database;

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Database::pdo();
$dir = BASE_PATH . '/database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$applied = array_flip($pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN));

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        echo "skip {$name}\n";
        continue;
    }

    echo "apply {$name}\n";
    $statements = preg_split('/;\s*(?:\r?\n|$)/', (string) file_get_contents($file)) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }

        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            if (!is_duplicate_schema_change($statement, $e)) {
                throw $e;
            }
            echo "skip duplicate schema change in {$name}\n";
        }
    }

    $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$name]);
}

echo "Migrations are up to date.\n";

function is_duplicate_schema_change(string $statement, PDOException $e): bool
{
    if (!preg_match('/^\s*ALTER\s+TABLE\b/i', $statement)) {
        return false;
    }

    $driverCode = (int) ($e->errorInfo[1] ?? 0);
    if ($driverCode === 1005) {
        return str_contains(strtolower($e->getMessage()), 'errno: 121');
    }

    return in_array($driverCode, [1060, 1061, 1826], true);
}
