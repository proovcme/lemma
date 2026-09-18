<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AuditService;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, array $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => '/' . trim($pattern, '/'),
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $routeMethod = $method === 'HEAD' ? 'GET' : $method;
        $path = '/' . trim($path, '/');
        if ($this->isDemoHiddenPath($path)) {
            http_response_code(404);
            view('layouts/error', ['title' => 'Страница не найдена', 'message' => 'Раздел недоступен в демо.']);
            return;
        }

        $this->dropDemoAdminSession($path);
        verify_csrf();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $routeMethod) {
                continue;
            }

            $params = $this->match($route['pattern'], $path);
            if ($params === null) {
                continue;
            }

            AuditService::scheduleHttpMutation($method, $path, $route['pattern'], $params);
            [$class, $action] = $route['handler'];
            $controller = new $class();
            $controller->{$action}(...array_values($params));
            return;
        }

        if ($method === 'POST') {
            AuditService::record('unmatched_http_mutation', ['status' => 404]);
        }
        http_response_code(404);
        view('layouts/error', ['title' => 'Страница не найдена', 'message' => 'Маршрут ' . $path . ' не найден.']);
    }

    private function isDemoHiddenPath(string $path): bool
    {
        if (!function_exists('app_is_demo_mode') || !app_is_demo_mode()) {
            return false;
        }

        return $path === '/admin'
            || str_starts_with($path, '/admin/')
            || $path === '/locia'
            || $path === '/team'
            || str_starts_with($path, '/team/')
            || str_starts_with($path, '/manual')
            || $path === '/knowledge'
            || str_starts_with($path, '/knowledge/')
            || $path === '/motivation'
            || str_starts_with($path, '/motivation/')
            || $path === '/hr'
            || str_starts_with($path, '/hr/')
            || $path === '/performance-review'
            || str_starts_with($path, '/performance-review/')
            || $path === '/competencies'
            || str_starts_with($path, '/competencies/')
            || $path === '/payroll'
            || str_starts_with($path, '/payroll/')
            || $path === '/settings'
            || str_starts_with($path, '/settings/');
    }

    private function dropDemoAdminSession(string $path): void
    {
        if (!function_exists('app_is_demo_mode') || !app_is_demo_mode()) {
            return;
        }
        if (in_array($path, ['/login', '/demo-login', '/logout'], true)) {
            return;
        }

        $user = function_exists('current_user') ? current_user() : null;
        if (($user['role'] ?? '') !== 'admin') {
            return;
        }

        \App\Core\Auth::logout();
        redirect('/login');
    }

    private function match(string $pattern, string $path): ?array
    {
        $names = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)}/', static function (array $matches) use (&$names): string {
            $names[] = $matches[1];
            return '([^/]+)';
        }, $pattern);

        if (!preg_match('#^' . $regex . '$#', $path, $matches)) {
            return null;
        }

        array_shift($matches);
        $params = [];
        foreach ($names as $index => $name) {
            $params[$name] = ctype_digit($matches[$index]) ? (int) $matches[$index] : $matches[$index];
        }

        return $params;
    }
}
