<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/helpers.php';

load_env(BASE_PATH . '/.env');

if (getenv('APP_ENV') === 'production' && ini_get('display_errors')) {
    ini_set('display_errors', '0');
}

$GLOBALS['config'] = require BASE_PATH . '/config/config.php';

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require_once BASE_PATH . '/vendor/autoload.php';
}

date_default_timezone_set(config('app.timezone', 'Europe/Moscow'));

// -----------------------------------------------------------------------------
// Content-Security-Policy для динамических страниц.
// Прочие заголовки (X-Frame-Options SAMEORIGIN, X-Content-Type-Options nosniff,
// Referrer-Policy) уже ставит Apache (httpd.conf). CSP здесь, в PHP, потому что
// доставляется обычным hotfix-пакетом без регенерации конфига Apache, и потому
// что статика Лемма Атлас (public/locia-atlas/) раздаётся Apache напрямую, минуя
// этот код, — wasm/worker Атласа CSP не затрагивает. 'unsafe-inline' оставлен:
// в шаблонах есть инлайновые стили/обработчики, всё с одного origin (офлайн-контур).
// -----------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"
    );
}

$isProduction = in_array(config('app.env'), ['production', 'prod'], true);
if (config('app.debug') && !$isProduction) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
if ($isProduction && ini_get('display_errors')) {
    ini_set('display_errors', '0');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = BASE_PATH . '/app/' . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// -----------------------------------------------------------------------------
// Глобальная обработка ошибок (Фикс 3)
// Без неё неперехваченное исключение на проде (display_errors=0) даёт пустой
// экран без записи в лог. Здесь — дружелюбная страница + лог в storage/logs.
// Семантику warning/notice НЕ меняем (не превращаем их в исключения), чтобы не
// дестабилизировать существующий код — перехватываем только реальные сбои.
// -----------------------------------------------------------------------------
$renderFatal = static function (string $detail, ?\Throwable $exception = null) use ($isProduction): void {
    static $rendered = false;
    try {
        $incidentId = \App\Services\IncidentLogService::report($exception ?? $detail);
    } catch (\Throwable) {
        $incidentId = 'ERR-' . date('Ymd') . '-LOGFAIL';
        error_log($incidentId . ' ' . $detail);
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $incidentId . ' ' . $detail . PHP_EOL);
        exit(1);
    }

    if ($rendered || headers_sent()) {
        return;
    }
    $rendered = true;

    http_response_code(500);
    $debug = (bool) config('app.debug') && !$isProduction;
    $body = $debug ? $detail : \App\Services\IncidentLogService::userMessage($incidentId, 'открыть страницу');

    // Самодостаточная страница в стиле Shtab: не зависит от БД и основного макета,
    // поэтому показывается даже при падении базы данных.
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Страница временно недоступна</title><style>'
        . 'body{font-family:"PT Sans",Arial,sans-serif;background:#f4f4f4;color:#222;margin:0;'
        . 'display:flex;min-height:100vh;align-items:center;justify-content:center}'
        . '.box{background:#fff;border-top:3px solid #A81A1A;border-radius:3px;padding:32px 36px;'
        . 'max-width:560px;box-shadow:0 1px 4px rgba(0,0,0,.12)}'
        . 'h1{font-family:"PT Sans Narrow","PT Sans",Arial,sans-serif;font-size:22px;margin:0 0 12px;color:#A81A1A}'
        . 'p{line-height:1.5;margin:0 0 18px}pre{white-space:pre-wrap;word-break:break-word;'
        . 'background:#f7f7f7;border:1px solid #e2e2e2;border-radius:2px;padding:10px;font-size:12px}'
        . 'code{display:inline-block;font-weight:700;letter-spacing:.04em;background:#f3f3f3;padding:3px 6px;border-radius:3px}'
        . 'a{display:inline-block;background:#A81A1A;color:#fff;text-decoration:none;padding:10px 16px;border-radius:2px}'
        . '</style></head><body><div class="box"><h1>Страница временно недоступна</h1>'
        . ($debug ? '<pre>' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
                  : '<p>' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p>Код обращения: <code>' . htmlspecialchars($incidentId, ENT_QUOTES, 'UTF-8') . '</code></p>')
        . '<a href="/my-day">Вернуться в «Мой день»</a></div></body></html>';

    exit(1);
};

set_exception_handler(static function (\Throwable $e) use ($renderFatal): void {
    $renderFatal(sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()), $e);
});

register_shutdown_function(static function () use ($renderFatal): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $renderFatal(sprintf('Fatal %d: %s in %s:%d', $err['type'], $err['message'], $err['file'], $err['line']));
    }
});

session_name(app_is_demo_mode() ? 'locia_demo_session' : 'locia_session');
ini_set('session.gc_maxlifetime', (string) config('security.session_lifetime_seconds', 43200));
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(session_cookie_options());
session_start();

// -----------------------------------------------------------------------------
// Раннее снятие блокировки сессии (Фикс 1)
// PHP держит эксклюзивную блокировку файла сессии на всё время запроса, из-за
// чего параллельные запросы одного пользователя (drawer-iframe + AJAX)
// сериализуются. Для read-only трафика (GET вне /admin) фиксируем CSRF-токен и
// одноразовые flash-сообщения, после чего закрываем сессию — лок освобождается
// на время тяжёлых запросов и рендера. /admin исключён: его GET-страницы
// потребляют одноразовые данные сессии (пароли, результаты импорта).
// -----------------------------------------------------------------------------
csrf_token();
$GLOBALS['_flash'] = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

if (request_method() === 'GET' && !str_starts_with(request_path(), '/admin')) {
    session_write_close();
}
