<?php

declare(strict_types=1);

use App\Core\Database;

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Database::pdo();
$existingAdmin = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if ($existingAdmin !== false) {
    echo "Administrator already exists; credentials were not changed.\n";
    exit(0);
}

$tab = trim((string) (getenv('LOCIA_ADMIN_LOGIN') ?: '0001'));
$name = trim((string) (getenv('LOCIA_ADMIN_NAME') ?: 'Администратор'));
$email = trim((string) (getenv('LOCIA_ADMIN_EMAIL') ?: ''));
$password = (string) (getenv('LOCIA_ADMIN_PASSWORD') ?: '');

$errors = [];
if ($tab === '' || mb_strlen($tab) > 20) {
    $errors[] = 'LOCIA_ADMIN_LOGIN must contain 1-20 characters';
}
if ($name === '' || mb_strlen($name) > 200) {
    $errors[] = 'LOCIA_ADMIN_NAME must contain 1-200 characters';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'LOCIA_ADMIN_EMAIL must be a valid email address';
}
if (strlen($password) < 14 || preg_match('/^(change|replace|admin|password|secret)/i', $password)) {
    $errors[] = 'LOCIA_ADMIN_PASSWORD must be at least 14 characters and not a placeholder';
}
if ($errors !== []) {
    fwrite(STDERR, "First-start administrator configuration is invalid:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

$stmt = $pdo->prepare('
    INSERT INTO users (tab_number, name, email, password_hash, role, department, must_change_password, is_active)
    VALUES (:tab_number, :name, :email, :password_hash, "admin", "Администрация", 0, 1)
');
$stmt->execute([
    'tab_number' => $tab,
    'name' => $name,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

echo "Administrator created: {$tab} / {$email}\n";
