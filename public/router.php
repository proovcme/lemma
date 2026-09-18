<?php

declare(strict_types=1);

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$basePath = rtrim((string) (parse_url((string) (getenv('APP_URL') ?: ''), PHP_URL_PATH) ?: ''), '/');
$strippedBasePath = false;
if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $path = substr($path, strlen($basePath)) ?: '/';
    $query = parse_url($requestUri, PHP_URL_QUERY);
    $_SERVER['REQUEST_URI'] = $path . ($query !== null && $query !== '' ? '?' . $query : '');
    $strippedBasePath = true;
}
$file = __DIR__ . $path;
$isDemoMode = (string) (getenv('DEMO_MODE') ?: '') === '1';

if (preg_match('#^/(?:\\.env|\\.git|app|config|database|deploy|dev|docs|scripts|storage|tests|vendor|composer\\.(?:json|lock)|package(?:-lock)?\\.json)(?:/|$)#', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

if ($isDemoMode && preg_match('#^/locia-atlas(?:/|$)#', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

if ($path !== '/' && (is_file($file) || (is_dir($file) && is_file(rtrim($file, '/') . '/index.html')))) {
    if ($strippedBasePath && is_file($file)) {
        $types = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'webmanifest' => 'application/manifest+json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
        ];
        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
        readfile($file);
        exit;
    }
    return false;
}

require __DIR__ . '/index.php';
