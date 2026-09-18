<?php

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = preg_replace('/^\xEF\xBB\xBF/', '', trim($key));
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function app_version_info(): array
{
    $info = config('app.version', []);
    if (!is_array($info)) {
        return ['version' => 'dev', 'date' => '', 'title' => '', 'changes' => []];
    }

    return [
        'version' => (string) ($info['version'] ?? 'dev'),
        'date' => (string) ($info['date'] ?? ''),
        'title' => (string) ($info['title'] ?? ''),
        'changes' => array_values(array_filter((array) ($info['changes'] ?? []), static fn (mixed $item): bool => trim((string) $item) !== '')),
    ];
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Sub-path prefix the app is mounted under, derived from APP_URL's path component.
 * Empty for a root deployment (the default); e.g. "/locia" when APP_URL is
 * "https://pm.example.org/lemma". A reverse proxy is expected to strip this prefix
 * before requests reach PHP (e.g. Caddy `handle_path /locia/*`), so routing and
 * request_path() stay root-relative while generated links carry the prefix.
 */
function base_path(): string
{
    static $bp = null;
    if ($bp === null) {
        $path = parse_url((string) config('app.url'), PHP_URL_PATH) ?: '';
        $bp = rtrim($path, '/');
    }
    return $bp;
}

/** Scheme + host (+ port) of APP_URL, without any sub-path. */
function app_origin(): string
{
    static $origin = null;
    if ($origin === null) {
        $parts = parse_url((string) config('app.url'));
        if (!empty($parts['host'])) {
            $origin = ($parts['scheme'] ?? 'http') . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
        } else {
            $origin = '';
        }
    }
    return $origin;
}

function url(string $path = ''): string
{
    $wasAbsolute = preg_match('#^https?://#i', $path) === 1;
    if ($wasAbsolute) {
        $parts = parse_url($path);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        if (is_array($parts) && isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        if (is_array($parts) && isset($parts['fragment'])) {
            $path .= '#' . $parts['fragment'];
        }
    }

    $path = '/' . ltrim($path, '/');
    $prefix = base_path();
    if ($path === '/') {
        return $prefix === '' ? '/' : $prefix;
    }
    if ($wasAbsolute && $prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
        return $path;
    }
    return $prefix . $path;
}

function app_url(string $path = ''): string
{
    $origin = app_origin();
    $relative = url($path);

    return $origin === '' ? $relative : $origin . ($relative === '/' ? '' : $relative);
}

function app_is_demo_mode(): bool
{
    return (bool) config('app.demo_mode', false);
}

function app_theme_color(): string
{
    return '#1F5FBF';
}

function app_title_default(): string
{
    return app_is_demo_mode() ? 'Лемма' : 'Лемма';
}

function app_brand_mark(): string
{
    return app_is_demo_mode() ? 'ЛЕММА' : 'ЛЕММА';
}

function app_guest_brand_mark(): string
{
    return app_is_demo_mode() ? 'ЛЕММА' : 'ЛЕММА';
}

function app_product_name(): string
{
    return app_is_demo_mode() ? 'Лемма' : 'Лемма';
}

function app_product_name_prepositional(): string
{
    return app_is_demo_mode() ? 'Лемме' : 'Лемме';
}

function app_product_full_name(): string
{
    return app_is_demo_mode() ? 'Лемма PM' : 'Лемма';
}

function app_demo_mask_text(string $text): string
{
    if (!app_is_demo_mode()) {
        return $text;
    }

    return strtr($text, [
        'Контур PM' => 'Лемма PM',
        'Лемма' => 'Лемма PM',
        'ЛЕММА' => 'ЛЕММА',
        'Лемме' => 'Лемме',
        'Лемму' => 'Лемму',
        'Лемма' => 'Лемма',
    ]);
}

function app_primary_nav_label(): string
{
    return app_is_demo_mode() ? 'Рабочий стол' : 'ЛЕММА';
}

function app_task_hub_title(): string
{
    return app_is_demo_mode() ? 'Рабочий стол' : 'Лемма';
}

function app_task_hub_path(): string
{
    return app_is_demo_mode() ? '/work' : '/locia';
}

function session_cookie_options(): array
{
    $path = base_path();
    $appScheme = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: ''));
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $httpsFlag = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $isSecure = $appScheme === 'https'
        || $forwardedProto === 'https'
        || ($httpsFlag !== '' && $httpsFlag !== 'off');

    return [
        'lifetime' => 0,
        'path' => $path === '' ? '/' : $path,
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Link to the Atlas/TIM viewer. Honours config integrations.atlas_url when set
 * (an external standalone viewer); otherwise falls back to the bundled
 * /locia-atlas/. $suffix is appended after the base (path and/or query),
 * e.g. atlas_url('?locia_return=/projects/5').
 */
function atlas_url(string $suffix = ''): string
{
    $base = rtrim((string) config('integrations.atlas_url', ''), '/');
    if ($base !== '') {
        return $base . '/' . ltrim($suffix, '/');
    }
    return url('/locia-atlas/' . ltrim($suffix, '/'));
}

function asset(string $path): string
{
    $assetPath = ltrim($path, '/');
    $publicFile = dirname(__DIR__) . '/public/assets/' . $assetPath;
    $version = is_file($publicFile) ? '?v=' . filemtime($publicFile) : '';

    return url('/assets/' . $assetPath) . $version;
}

function file_link_href(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '#';
    }

    $localPath = file_link_local_path($value);
    if ($localPath !== null) {
        return 'dpr-open://open?path=' . rawurlencode($localPath);
    }

    $allowed = ['http', 'https', 'file', 'dpr-open'];
    $scheme = parse_url($value, PHP_URL_SCHEME);
    if ($scheme && !in_array(strtolower($scheme), $allowed, true)) {
        return '#';
    }

    if ($scheme) {
        return $value;
    }

    return $value;
}

function file_link_local_path(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (str_starts_with($value, '\\\\') || preg_match('#^[a-zA-Z]:[\\\\/]#', $value) || str_starts_with($value, '/')) {
        return str_replace('/', DIRECTORY_SEPARATOR, $value);
    }

    $scheme = parse_url($value, PHP_URL_SCHEME);
    if ($scheme === null || strtolower($scheme) !== 'file') {
        return null;
    }

    if (str_starts_with($value, 'file:////')) {
        return '\\\\' . str_replace('/', '\\', rawurldecode(substr($value, 9)));
    }

    $host = parse_url($value, PHP_URL_HOST);
    $path = parse_url($value, PHP_URL_PATH);
    if (is_string($host) && $host !== '' && strtolower($host) !== 'localhost') {
        $decodedPath = is_string($path) ? rawurldecode($path) : '';
        return '\\\\' . $host . str_replace('/', '\\', $decodedPath);
    }

    if (!is_string($path) || $path === '') {
        return null;
    }

    $decoded = rawurldecode($path);
    if (preg_match('#^/[a-zA-Z]:/#', $decoded)) {
        $decoded = substr($decoded, 1);
    }

    return str_replace('/', DIRECTORY_SEPARATOR, $decoded);
}

function file_path_join(?string $base, string $relative): string
{
    $base = rtrim(trim((string) $base), "\\/");
    $relative = trim($relative, "\\/");
    if ($base === '') {
        return $relative;
    }
    if ($relative === '') {
        return $base;
    }

    if (str_starts_with($base, '\\\\') || preg_match('#^[a-zA-Z]:\\\\#', $base)) {
        return $base . '\\' . str_replace('/', '\\', $relative);
    }

    return $base . '/' . str_replace('\\', '/', $relative);
}

function project_folder_template(): array
{
    return [
        [
            'label' => '00_Управление',
            'path' => '00_Управление',
            'children' => [
                ['label' => '01_Договорная_документация', 'path' => '00_Управление/01_Договорная_документация'],
                ['label' => '02_Бухгалтерская_документация', 'path' => '00_Управление/02_Бухгалтерская_документация'],
                ['label' => '03_ИРД', 'path' => '00_Управление/03_ИРД (ГПЗУ, АГР, Правоустановка)'],
                ['label' => '04_Задания_на_проектирование', 'path' => '00_Управление/04_Задания_на_проектирование (ТЗ)'],
                ['label' => '05_Технические_условия', 'path' => '00_Управление/05_Технические_условия'],
                [
                    'label' => '06_Инженерные_изыскания',
                    'path' => '00_Управление/06_Инженерные_изыскания',
                    'children' => [
                        ['label' => '01_Геодезические', 'path' => '00_Управление/06_Инженерные_изыскания/01_Геодезические'],
                        ['label' => '02_Геологические', 'path' => '00_Управление/06_Инженерные_изыскания/02_Геологические'],
                        ['label' => '03_Экологические', 'path' => '00_Управление/06_Инженерные_изыскания/03_Экологические'],
                        ['label' => '04_Гидрометеорологические', 'path' => '00_Управление/06_Инженерные_изыскания/04_Гидрометеорологические'],
                        ['label' => '05_Археологические', 'path' => '00_Управление/06_Инженерные_изыскания/05_Археологические'],
                        ['label' => '06_Обследования_зданий', 'path' => '00_Управление/06_Инженерные_изыскания/06_Обследования_зданий'],
                    ],
                ],
                ['label' => '07_Планирование_и_графики', 'path' => '00_Управление/07_Планирование_и_графики'],
                [
                    'label' => '08_Переписка',
                    'path' => '00_Управление/08_Переписка',
                    'children' => [
                        ['label' => '01_Входящие', 'path' => '00_Управление/08_Переписка/01_Входящие'],
                        ['label' => '02_Исходящие', 'path' => '00_Управление/08_Переписка/02_Исходящие'],
                    ],
                ],
                ['label' => '09_Протоколы_совещаний', 'path' => '00_Управление/09_Протоколы_совещаний'],
            ],
        ],
        [
            'label' => '01_В_работе',
            'path' => '01_В_работе',
            'children' => [
                [
                    'label' => 'Стадия_П',
                    'path' => '01_В_работе/Стадия_П (Постановление №87)',
                    'children' => [
                        ['label' => 'Раздел_01_ПЗ', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_01_ПЗ'],
                        ['label' => 'Раздел_02_ПЗУ', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_02_ПЗУ'],
                        ['label' => 'Раздел_03_АР', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_03_АР'],
                        ['label' => 'Раздел_04_КР', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_04_КР'],
                        [
                            'label' => 'Раздел_05_ИОС',
                            'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_05_ИОС',
                            'children' => [
                                ['label' => 'ИОС1_Электроснабжение', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_05_ИОС/ИОС1_Электроснабжение'],
                                ['label' => 'ИОС2_Водоснабжение', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_05_ИОС/ИОС2_Водоснабжение'],
                                ['label' => 'ИОС3_Водоотведение', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_05_ИОС/ИОС3_Водоотведение'],
                                ['label' => 'ИОС4_ОВ_и_ТС', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_05_ИОС/ИОС4_ОВ_и_ТС'],
                                ['label' => 'ИОС5_Сети_связи', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_05_ИОС/ИОС5_Сети_связи'],
                                ['label' => 'ИОС6_Газоснабжение', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_05_ИОС/ИОС6_Газоснабжение'],
                                ['label' => 'ИОС7_ТХ', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_05_ИОС/ИОС7_ТХ'],
                            ],
                        ],
                        ['label' => 'Раздел_06_ПОС', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_06_ПОС'],
                        ['label' => 'Раздел_07_ПОД', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_07_ПОД'],
                        ['label' => 'Раздел_08_ООС', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_08_ООС'],
                        ['label' => 'Раздел_09_ППБ', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_09_ППБ'],
                        ['label' => 'Раздел_10_ОДИ', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_10_ОДИ'],
                        ['label' => 'Раздел_11_Сметы', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_11_Сметы'],
                        ['label' => 'Раздел_12_Иная_док', 'path' => '01_В_работе/Стадия_П (Постановление №87)/Раздел_12_Иная_док'],
                    ],
                ],
                [
                    'label' => 'Стадия_Р',
                    'path' => '01_В_работе/Стадия_Р (Марки)',
                    'children' => [
                        ['label' => 'F_ГП_Генплан', 'path' => '01_В_работе/Стадия_Р (Марки)/F_ГП_Генплан'],
                        ['label' => 'F_АР_Архитектура', 'path' => '01_В_работе/Стадия_Р (Марки)/F_АР_Архитектура'],
                        ['label' => 'F_КЖ_Бетон', 'path' => '01_В_работе/Стадия_Р (Марки)/F_КЖ_Бетон'],
                        ['label' => 'F_КМ_Металл', 'path' => '01_В_работе/Стадия_Р (Марки)/F_КМ_Металл'],
                        ['label' => 'F_ВК_Водопровод_и_Кан', 'path' => '01_В_работе/Стадия_Р (Марки)/F_ВК_Водопровод_и_Кан'],
                        ['label' => 'F_ОВ_Отопление_Вент', 'path' => '01_В_работе/Стадия_Р (Марки)/F_ОВ_Отопление_Вент'],
                        ['label' => 'F_ЭОМ_Электрика', 'path' => '01_В_работе/Стадия_Р (Марки)/F_ЭОМ_Электрика'],
                        ['label' => 'F_СС_Связь', 'path' => '01_В_работе/Стадия_Р (Марки)/F_СС_Связь'],
                    ],
                ],
            ],
        ],
        [
            'label' => '02_Общие_данные',
            'path' => '02_Общие_данные (SHARED)',
            'children' => [
                ['label' => 'Стадия_П / F_ЗАДАНИЯ', 'path' => '02_Общие_данные (SHARED)/Стадия_П/F_ЗАДАНИЯ_Исходящие'],
                ['label' => 'Стадия_П / F_ПОДОСНОВЫ', 'path' => '02_Общие_данные (SHARED)/Стадия_П/F_ПОДОСНОВЫ'],
                ['label' => 'Стадия_П / F_КООРДИНАЦИЯ', 'path' => '02_Общие_данные (SHARED)/Стадия_П/F_КООРДИНАЦИЯ'],
                ['label' => 'Стадия_П / F_ТИМ_МОДЕЛИ', 'path' => '02_Общие_данные (SHARED)/Стадия_П/F_ТИМ_МОДЕЛИ'],
                ['label' => 'Стадия_Р / F_ЗАДАНИЯ', 'path' => '02_Общие_данные (SHARED)/Стадия_Р/F_ЗАДАНИЯ_Исходящие'],
                ['label' => 'Стадия_Р / F_ПОДОСНОВЫ', 'path' => '02_Общие_данные (SHARED)/Стадия_Р/F_ПОДОСНОВЫ'],
                ['label' => 'Стадия_Р / F_КООРДИНАЦИЯ', 'path' => '02_Общие_данные (SHARED)/Стадия_Р/F_КООРДИНАЦИЯ'],
                ['label' => 'Стадия_Р / F_ТИМ_МОДЕЛИ', 'path' => '02_Общие_данные (SHARED)/Стадия_Р/F_ТИМ_МОДЕЛИ'],
            ],
        ],
        [
            'label' => '03_Выпуск',
            'path' => '03_Выпуск (PUBLISHED)',
            'children' => [
                ['label' => 'Стадия_П', 'path' => '03_Выпуск (PUBLISHED)/Стадия_П'],
                ['label' => 'Стадия_Р', 'path' => '03_Выпуск (PUBLISHED)/Стадия_Р'],
            ],
        ],
        [
            'label' => '04_Архив',
            'path' => '04_Архив (ARCHIVE)',
            'children' => [
                ['label' => 'Стадия_П_Архив', 'path' => '04_Архив (ARCHIVE)/Стадия_П_Архив'],
                ['label' => 'Стадия_Р_Архив', 'path' => '04_Архив (ARCHIVE)/Стадия_Р_Архив'],
                ['label' => 'Архив_Заданий', 'path' => '04_Архив (ARCHIVE)/Архив_Заданий'],
                ['label' => 'Архив_ИРД_и_ТУ', 'path' => '04_Архив (ARCHIVE)/Архив_ИРД_и_ТУ'],
            ],
        ],
    ];
}

function custom_link_entries(mixed $value): array
{
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        $entries = array_is_list($decoded) ? $decoded : [$decoded];
        return array_values(array_filter(array_map(static function (mixed $entry): ?array {
            if (!is_array($entry)) {
                return null;
            }

            $url = trim((string) ($entry['url'] ?? ''));
            if ($url === '') {
                return null;
            }

            return [
                'label' => trim((string) ($entry['label'] ?? '')),
                'url' => $url,
            ];
        }, $entries)));
    }

    return [['label' => '', 'url' => $value]];
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 302);
    exit;
}

function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return '/' . trim($path, '/');
}

function request_method(): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    return $method === 'HEAD' ? 'GET' : $method;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_recovery_path(string $path): ?string
{
    if ($path === '/login') {
        return '/login?csrf=expired';
    }

    if (preg_match('#^/performance-review/(\d+)/questionnaire/(?:draft|submit)$#', $path, $matches) === 1) {
        return '/performance-review/' . (int) $matches[1] . '?csrf=expired';
    }

    return null;
}

function csrf_recovery_message(string $path): string
{
    if ($path === '/login') {
        return 'Срок формы входа истёк. Введите данные ещё раз.';
    }

    return 'Срок формы истёк. Ответы сохранены в этом браузере: проверьте их и отправьте анкету ещё раз.';
}

function verify_csrf(): void
{
    if (request_method() !== 'POST') {
        return;
    }

    $path = request_path();
    if (str_starts_with($path, '/api/revit/v1/')) {
        return;
    }
    if (preg_match('#^/(?:projects/\d+/(?:models/\d+|model-folder)|locia-atlas/default-folder)/fragments$#', $path)) {
        return;
    }

    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['_csrf'] ?? '', (string) $token)) {
        if (class_exists(\App\Services\AuditService::class)) {
            \App\Services\AuditService::record('csrf_mismatch', ['status' => 419]);
        }
        $recoveryPath = csrf_recovery_path($path);
        if ($recoveryPath !== null) {
            flash('error', csrf_recovery_message($path));
            redirect($recoveryPath);
        }
        http_response_code(419);
        exit('CSRF token mismatch');
    }
}

function flash(string $type, ?string $message = null): ?string
{
    if ($message !== null) {
        // Сессия могла быть закрыта рано на read-only запросах (см. bootstrap.php).
        // Перед записью flash переоткрываем её, чтобы сообщение точно сохранилось.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['flash'][$type] = $message;
        return null;
    }

    // Чтение идёт из снимка, снятого в bootstrap.php до возможного закрытия сессии.
    $message = $GLOBALS['_flash'][$type] ?? ($_SESSION['flash'][$type] ?? null);
    unset($GLOBALS['_flash'][$type], $_SESSION['flash'][$type]);
    return $message;
}

function view(string $template, array $data = [], string $layout = 'layouts/app'): void
{
    extract($data, EXTR_SKIP);
    $viewFile = BASE_PATH . '/app/Views/' . $template . '.php';

    if (!is_file($viewFile)) {
        throw new RuntimeException('View not found: ' . $template);
    }

    ob_start();
    require $viewFile;
    $content = ob_get_clean();

    if ($layout === '') {
        echo $content;
        return;
    }

    require BASE_PATH . '/app/Views/' . $layout . '.php';
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function current_user(): ?array
{
    return \App\Core\Auth::user();
}

function require_auth(): array
{
    return \App\Core\Auth::require();
}

function has_role(array|string $roles): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }

    return \App\Services\RoleService::isAny($user['role'] ?? null, (array) $roles);
}

function role_home_path(?array $user = null): string
{
    $user ??= current_user();
    if (!$user) {
        return '/login';
    }

    $home = \App\Services\RoleService::homePath($user['role'] ?? null);
    if (app_is_demo_mode() && $home === '/locia') {
        return app_task_hub_path();
    }

    return $home;
}

function role_sidebar_label(?string $role): string
{
    return \App\Services\RoleService::sidebarLabel($role);
}

function role_label(?string $role): string
{
    return \App\Services\RoleService::label($role);
}

function task_status_label(?string $status): string
{
    return [
        'new' => 'Новая',
        'in_progress' => 'В работе',
        'review' => 'На проверке',
        'correction' => 'Корректировка',
        'pending_close' => 'Ожидает принятия',
        'done' => 'Закрыта',
        'blocked' => 'Заблокирована',
        'overdue' => 'Просрочена',
    ][(string) $status] ?? (string) $status;
}

function task_type_label(?string $type): string
{
    return [
        'work' => 'Работа',
        'assignment' => 'Задание',
        'issuance' => 'Выдача',
        'labor_estimate' => 'Оценка трудозатрат',
        'delegation' => 'Делегирование',
        'bim_family_request' => 'Заявка на семейство ТИМ',
        'note' => 'Заметка',
        'review' => 'Проверка',
    ][(string) $type] ?? (string) $type;
}

function labor_estimate_status_label(?string $status): string
{
    return [
        'draft' => 'Черновик отдела',
        'department_submitted' => 'Подана отделом',
        'returned_to_department' => 'Возврат отделу',
        'gip_adjusted' => 'Скорректирована ГИПом',
        'assigned' => 'Назначена',
        'submitted' => 'Подана исполнителем',
        'returned_to_responsible' => 'Возврат ответственному',
        'gip_approved' => 'Проверена ГИПом',
        'returned_to_gip' => 'Возврат ГИПу',
        'director_approved' => 'Утверждена директором',
    ][(string) $status] ?? (string) $status;
}

function labor_estimate_status_class(?string $status): string
{
    return [
        'draft' => 'new',
        'department_submitted' => 'review',
        'returned_to_department' => 'correction',
        'gip_adjusted' => 'pending_close',
        'assigned' => 'new',
        'submitted' => 'review',
        'returned_to_responsible' => 'correction',
        'gip_approved' => 'pending_close',
        'returned_to_gip' => 'correction',
        'director_approved' => 'done',
    ][(string) $status] ?? 'new';
}

function task_approval_stage_label(?string $stage): string
{
    return [
        'draft' => 'У исполнителя',
        'review_lead' => 'Промежуточное согласование',
        'review_gip' => 'ГИП',
        'review_task' => 'Проверка результата',
        'approved' => 'Согласована',
        'issued' => 'Выдана',
        'close_author' => 'Приёмка постановщиком',
        'close_gip' => 'Приёмка ГИП',
    ][(string) $stage] ?? (string) $stage;
}

// Русские подписи полей и значений для блока «История» задачи.
function task_log_field_label(?string $field): string
{
    return [
        'status' => 'Статус',
        'attachments' => 'Файлы и фото',
        'approval_stage' => 'Этап согласования',
        'review_task' => 'Проверка результата',
        'reviewer_id' => 'Проверяющий',
        'assignee_id' => 'Исполнитель',
        'author_id' => 'Постановщик',
        'title' => 'Название',
        'description' => 'Описание',
        'task_type' => 'Тип задачи',
        'project_id' => 'Проект',
        'parent_id' => 'Зависимость',
        'discipline' => 'Дисциплина',
        'volume' => 'Том',
        'section' => 'Шифр / раздел',
        'priority' => 'Важность',
        'date_start' => 'Дата начала',
        'date_end' => 'Срок',
        'planned_hours' => 'План, ч',
        'actual_hours' => 'Факт, ч',
    ][(string) $field] ?? (string) $field;
}
function task_log_value_label(?string $field, ?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    return match ($field) {
        'status' => task_status_label($value),
        'approval_stage' => task_approval_stage_label($value),
        'task_type' => task_type_label($value),
        default => $value,
    };
}

// Релевантные разделы «Регламента работы в Лемме» для задачи данного типа.
// Возвращает [['no' => '3', 'title' => 'Постановка задач'], ...]; ссылки ведут
// на /manual/regulation#reg-<no>.
function task_regulation_refs(?string $taskType): array
{
    $refs = [
        ['no' => '3', 'title' => 'Постановка задач'],
        ['no' => '6', 'title' => 'Проверка и согласование'],
    ];
    switch ((string) $taskType) {
        case 'issuance':
            $refs[] = ['no' => '7', 'title' => 'Выдача томов'];
            break;
        case 'assignment':
            $refs[] = ['no' => '8', 'title' => 'Обмен заданиями'];
            break;
        case 'bim_family_request':
            $refs[] = ['no' => '8', 'title' => 'Обмен заданиями (ТИМ)'];
            break;
    }
    return $refs;
}

function task_approval_decision_label(?string $decision): string
{
    return [
        'approved' => 'Согласовано',
        'rejected' => 'Возвращено',
        'issued' => 'Выдано',
    ][(string) $decision] ?? (string) $decision;
}

function labor_approval_status_label(?string $status): string
{
    return [
        'draft' => 'Черновик',
        'pending_director' => 'Ждёт директора',
        'approved' => 'Подтверждено',
        'rejected' => 'Возвращено',
    ][(string) $status] ?? (string) $status;
}

function labor_approval_status_class(?string $status): string
{
    return [
        'draft' => 'new',
        'pending_director' => 'pending_close',
        'approved' => 'done',
        'rejected' => 'blocked',
    ][(string) $status] ?? 'new';
}

function issue_status_label(?string $status): string
{
    return [
        'open' => 'Открыт',
        'in_progress' => 'В работе',
        'done' => 'Закрыт',
    ][(string) $status] ?? (string) $status;
}

function data_status_label(?string $status): string
{
    return [
        'waiting' => 'Ждём',
        'received' => 'Получено',
        'not_needed' => 'Не требуется',
    ][(string) $status] ?? (string) $status;
}

function data_status_class(?string $status): string
{
    return [
        'waiting' => 'review',
        'received' => 'done',
        'not_needed' => 'new',
    ][(string) $status] ?? 'new';
}

function exchange_status_label(?string $status): string
{
    return [
        'pending' => 'Ожидает',
        'in_progress' => 'В работе',
        'done' => 'Готово',
        'blocked' => 'Блокер',
    ][(string) $status] ?? (string) $status;
}

function exchange_status_class(?string $status): string
{
    return [
        'pending' => 'review',
        'in_progress' => 'in_progress',
        'done' => 'done',
        'blocked' => 'overdue',
    ][(string) $status] ?? 'new';
}

function task_issuance_status_label(?string $status): string
{
    return [
        'issued' => 'Выдана',
        'remarks' => 'Замечания',
        'accepted' => 'Принята',
    ][(string) $status] ?? (string) $status;
}

function task_issuance_status_badge(?string $status): string
{
    return [
        'issued' => 'Выдана',
        'remarks' => 'Замечания ⚠',
        'accepted' => 'Принята ✓',
    ][(string) $status] ?? (string) $status;
}

function task_id_list(mixed $value): array
{
    if (!preg_match_all('/\d+/', (string) $value, $matches)) {
        return [];
    }

    $ids = array_map('intval', $matches[0]);
    $ids = array_filter($ids, static fn (int $id): bool => $id > 0);

    return array_values(array_unique($ids));
}

function priority_label(?string $value): string
{
    return [
        'low' => 'Низкая',
        'mid' => 'Средняя',
        'high' => 'Высокая',
    ][(string) $value] ?? (string) $value;
}

function progress_fill_class(int|float|null $progress): string
{
    $value = max(0, min(100, (int) $progress));

    if ($value <= 30) {
        return '';
    }

    return $value <= 70 ? 'prog-fill--mid' : 'prog-fill--done';
}

function deadline_state_class(?string $date, ?string $today = null): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'date-empty';
    }

    $timestamp = strtotime(substr($date, 0, 10));
    if ($timestamp === false) {
        return 'date-empty';
    }

    $deadline = date('Y-m-d', $timestamp);
    $today = $today ?: date('Y-m-d');
    $soon = date('Y-m-d', strtotime($today . ' +3 days'));

    if ($deadline < $today) {
        return 'date-red';
    }

    return $deadline <= $soon ? 'date-amber' : 'date-normal';
}

function avatar_color(?string $name): string
{
    $first = mb_strtoupper(mb_substr(trim((string) $name), 0, 1, 'UTF-8'), 'UTF-8');

    return match (true) {
        in_array($first, ['А', 'Б', 'В'], true) => '#1a5a9a',
        in_array($first, ['Г', 'Д', 'Е'], true) => '#1a7a4a',
        in_array($first, ['Ж', 'З', 'И'], true) => '#7a4a9a',
        in_array($first, ['К', 'Л', 'М'], true) => '#b06010',
        in_array($first, ['Н', 'О', 'П'], true) => '#555555',
        default => 'var(--red)',
    };
}

function working_hours(?string $dateStart, ?string $dateEnd): float
{
    if (!$dateStart || !$dateEnd) {
        return 0.0;
    }

    $start = new DateTimeImmutable($dateStart);
    $end = new DateTimeImmutable($dateEnd);
    if ($end < $start) {
        return 0.0;
    }

    $days = 0;
    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        if ((int) $date->format('N') <= 5) {
            $days++;
        }
    }

    return (float) ($days * 8);
}

function initials(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '--';
    }

    $parts = preg_split('/\s+/u', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_substr($part, 0, 1, 'UTF-8');
    }

    return mb_strtoupper($letters, 'UTF-8');
}

function active_link(string $path): string
{
    return str_starts_with(request_path(), $path) ? ' is-active' : '';
}

function active_link_any(array|string $paths): string
{
    foreach ((array) $paths as $path) {
        if (str_starts_with(request_path(), $path)) {
            return ' is-active';
        }
    }

    return '';
}

function format_date(?string $date): string
{
    if (!$date) {
        return '';
    }

    return (new DateTimeImmutable($date))->format('d.m.Y');
}

function format_datetime(?string $date): string
{
    if (!$date) {
        return '';
    }
    $timestamp = strtotime($date);
    return $timestamp !== false ? date('d.m.Y H:i', $timestamp) : '';
}

function format_day_month(?string $date): string
{
    if (!$date) {
        return '';
    }

    return (new DateTimeImmutable($date))->format('d.m');
}

function selected(mixed $actual, mixed $expected): string
{
    return (string) $actual === (string) $expected ? ' selected' : '';
}

function checked(mixed $actual): string
{
    return $actual ? ' checked' : '';
}
