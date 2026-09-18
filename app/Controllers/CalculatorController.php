<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RoleService;
use App\Services\CalculatorRateService;
use App\Services\CalculatorPortfolioService;

final class CalculatorController extends BaseController
{
    private const ALLOWED_ROLES = [
        RoleService::ADMIN,
        RoleService::DIRECTOR,
        RoleService::ADJACENT_DIRECTOR,
        RoleService::DEPUTY_DIRECTOR,
    ];

    public function index(): void
    {
        $this->requireCalculatorAccess();
        $index = $this->bundleRoot() . '/index.html';
        if (!is_file($index) || !is_readable($index)) {
            $this->unavailable();
        }

        $this->securityHeaders('text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        readfile($index);
    }

    public function asset(string $file): void
    {
        $this->requireCalculatorAccess();
        $asset = $this->resolveAsset($file);
        if ($asset === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Файл калькулятора не найден';
            return;
        }

        $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };

        $this->securityHeaders($contentType);
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string) filesize($asset));
        readfile($asset);
    }

    public function rates(): void
    {
        $this->requireCalculatorAccess();
        $this->securityHeaders('application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        $payload = (new CalculatorRateService($this->db()))->current();
        $payload['csrf_token'] = csrf_token();
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function savePortfolio(): void
    {
        $user = $this->requireCalculatorAccess();
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->json(['ok' => false, 'error' => 'Некорректный JSON.'], 422);
        }
        try {
            $result = (new CalculatorPortfolioService($this->db()))->save((int) $user['id'], $payload);
            $this->json(['ok' => true] + $result);
        } catch (\InvalidArgumentException $error) {
            $this->json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (\RuntimeException $error) {
            $this->json(['ok' => false, 'error' => $error->getMessage()], 409);
        }
    }

    public function deletePortfolio(string $snapshotId): void
    {
        $user = $this->requireCalculatorAccess();
        $deleted = (new CalculatorPortfolioService($this->db()))->delete((int) $user['id'], $snapshotId);
        $this->json(['ok' => true, 'deleted' => $deleted]);
    }

    public static function canAccessRole(?string $role): bool
    {
        return RoleService::isAny($role, self::ALLOWED_ROLES);
    }

    private function requireCalculatorAccess(): array
    {
        if (current_user() === null) {
            redirect('/login?next=' . rawurlencode('/calculator'));
        }

        $user = require_auth();
        if (!self::canAccessRole($user['role'] ?? null)) {
            http_response_code(403);
            view('layouts/error', [
                'title' => 'Нет доступа',
                'message' => 'Калькулятор доступен только администраторам, директорам и заместителям директора.',
            ]);
            exit;
        }

        return $user;
    }

    private function bundleRoot(): string
    {
        return rtrim((string) config('app.calculator_bundle_path', BASE_PATH . '/resources/calculator'), '/\\');
    }

    private function resolveAsset(string $file): ?string
    {
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $file)) {
            return null;
        }

        $asset = $this->bundleRoot() . '/assets/' . $file;
        return is_file($asset) && is_readable($asset) ? $asset : null;
    }

    private function securityHeaders(string $contentType): void
    {
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Resource-Policy: same-origin');
    }

    private function unavailable(): never
    {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Калькулятор временно недоступен');
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        $this->securityHeaders('application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
