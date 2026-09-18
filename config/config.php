<?php

$env = static function (string $key, mixed $default = null): mixed {
    $value = getenv($key);
    return $value === false ? $default : $value;
};

$isPlaceholder = static function (mixed $value): bool {
    $value = trim((string) $value);
    return $value === '' || in_array(strtolower($value), ['xxx', 'secret'], true) || str_starts_with($value, 'CHANGE_ME');
};

$appEnv = (string) $env('APP_ENV', 'local');
$isProduction = in_array($appEnv, ['production', 'prod'], true);
$appDebug = filter_var($env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
$appUrl = rtrim((string) $env('APP_URL', 'http://localhost:8080'), '/');

$dbConnection = (string) $env('DB_CONNECTION', 'mysql');
$dbUsername = (string) $env('DB_USERNAME', $isProduction ? '' : 'root');
$dbPassword = (string) $env('DB_PASSWORD', '');

$mspSyncEnabled = filter_var($env('MSP_SYNC_ENABLED', false), FILTER_VALIDATE_BOOL);
$timViewer2Url = rtrim((string) $env('TIM_VIEWER_2_URL', ''), '/');
$defaultModelFolder = (string) $env('LOCIA_DEFAULT_MODEL_FOLDER', BASE_PATH . '/models');
$atlasPublicPath = (string) $env('ATLAS_PUBLIC_PATH', BASE_PATH . '/public/locia-atlas');
$calculatorBundlePath = trim((string) $env('CALCULATOR_BUNDLE_PATH', ''));
if ($calculatorBundlePath === '') {
    $calculatorBundlePath = BASE_PATH . '/resources/calculator';
}
$mailUsername = (string) $env('MAIL_USERNAME', '');
$mailFromEmail = (string) $env('MAIL_FROM_EMAIL', $mailUsername !== '' ? $mailUsername : 'locia@example.local');
$defaultCaBundle = BASE_PATH . '/resources/certs/locia-ca.pem';
$configuredCaBundle = trim((string) $env('LOCIA_CA_BUNDLE', ''));
$caBundle = $configuredCaBundle !== '' && is_file($configuredCaBundle)
    ? $configuredCaBundle
    : (is_file($defaultCaBundle) ? $defaultCaBundle : '');
$versionInfo = is_file(BASE_PATH . '/config/version.php')
    ? require BASE_PATH . '/config/version.php'
    : ['version' => 'dev', 'date' => date('Y-m-d'), 'title' => 'Локальная сборка', 'changes' => []];

if ($isProduction) {
    $errors = [];
    if ($dbConnection !== 'sqlite') {
        if ($dbUsername === '' || strtolower($dbUsername) === 'root') {
            $errors[] = 'DB_USERNAME must be set to a non-root user';
        }
        if ($isPlaceholder($dbPassword)) {
            $errors[] = 'DB_PASSWORD must be set to a non-placeholder value';
        }
    }
    if ($errors !== []) {
        throw new RuntimeException("Production configuration is incomplete:\n- " . implode("\n- ", $errors));
    }
}

defined('MSP_SYNC_ENABLED') || define('MSP_SYNC_ENABLED', $mspSyncEnabled);

return [
    'app' => [
        'env' => $appEnv,
        'debug' => $appDebug,
        'url' => $appUrl,
        'timezone' => (string) $env('APP_TIMEZONE', 'Europe/Moscow'),
        'version' => $versionInfo,
        'default_model_folder' => $defaultModelFolder,
        'atlas_public_path' => $atlasPublicPath,
        'calculator_bundle_path' => $calculatorBundlePath,
        // Passwordless "Демо доступ" on the login page. OFF unless DEMO_MODE is
        // explicitly truthy AND not a production environment — never in prod.
        'demo_mode' => !$isProduction && filter_var($env('DEMO_MODE', false), FILTER_VALIDATE_BOOL),
    ],
    'db' => [
        'connection' => $dbConnection,
        'host' => (string) $env('DB_HOST', '127.0.0.1'),
        'port' => (string) $env('DB_PORT', '3306'),
        'database' => (string) $env('DB_DATABASE', 'dpr_pm'),
        'username' => $dbUsername,
        'password' => $dbPassword,
        'charset' => (string) $env('DB_CHARSET', 'utf8mb4'),
        'sqlite_path' => (string) $env('DB_SQLITE_PATH', BASE_PATH . '/storage/app.sqlite'),
    ],
    'security' => [
        // Keep authenticated sessions alive through a normal workday. The
        // bootstrap applies this value at runtime as well, so an update fixes
        // existing standalone installations without rebuilding php.ini.
        'session_lifetime_seconds' => max(1440, (int) $env('SESSION_LIFETIME_SECONDS', 43200)),
        // 32 random bytes encoded as Base64. Used for application-layer
        // encryption of recoverable secrets such as the SMTP password.
        'data_key' => (string) $env('APP_DATA_KEY', ''),
    ],
    'integrations' => [
        'msp_sync_enabled' => $mspSyncEnabled,
        'tim_viewer_2_url' => $timViewer2Url,
        // External Atlas/TIM viewer URL. Empty (default) ⇒ use the bundled
        // /locia-atlas/. Set e.g. to a standalone viewer when the bundled SPA
        // can't run (e.g. under a sub-path mount).
        'atlas_url' => rtrim((string) $env('ATLAS_URL', ''), '/'),
    ],
    'mail' => [
        'enabled' => filter_var($env('MAIL_ENABLED', false), FILTER_VALIDATE_BOOL),
        'host' => (string) $env('MAIL_HOST', ''),
        'port' => (int) $env('MAIL_PORT', '465'),
        'username' => $mailUsername,
        'password' => (string) $env('MAIL_PASSWORD', ''),
        'from_email' => $mailFromEmail,
        'from_name' => (string) $env('MAIL_FROM_NAME', 'Лемма'),
        'encryption' => strtolower((string) $env('MAIL_ENCRYPTION', 'ssl')),
        'timeout' => (int) $env('MAIL_TIMEOUT', '20'),
    ],
    'tls' => [
        'ca_bundle' => $caBundle,
        'bundled_ca_bundle' => is_file($defaultCaBundle) ? $defaultCaBundle : '',
    ],
    'database_export' => [
        'storage_dir' => (string) $env('DATABASE_EXPORT_STORAGE_DIR', BASE_PATH . '/storage/database-exports'),
    ],
    'revit' => [
        'enabled' => filter_var($env('REVIT_INTEGRATION_ENABLED', false), FILTER_VALIDATE_BOOL),
        'storage_dir' => (string) $env('REVIT_MODEL_STORAGE_DIR', BASE_PATH . '/storage/revit-models'),
        'upload_dir' => (string) $env('REVIT_UPLOAD_STORAGE_DIR', BASE_PATH . '/storage/revit-uploads'),
        'max_file_bytes' => (int) $env('REVIT_IFC_MAX_BYTES', 2 * 1024 * 1024 * 1024),
        'chunk_bytes' => (int) $env('REVIT_UPLOAD_CHUNK_BYTES', 8 * 1024 * 1024),
        'activation_ttl_seconds' => (int) $env('REVIT_ACTIVATION_TTL_SECONDS', 600),
        'upload_ttl_seconds' => (int) $env('REVIT_UPLOAD_TTL_SECONDS', 86400),
    ],
];
