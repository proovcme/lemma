<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LoginThrottleService;
use App\Services\RevitIntegrationService;
use RuntimeException;

final class RevitIntegrationController extends BaseController
{
    public function capabilities(): void
    {
        $this->api(function (RevitIntegrationService $service): array {
            return $service->capabilities();
        }, false);
    }

    public function exchange(): void
    {
        $this->api(function (RevitIntegrationService $service, array $input): array {
            $throttleKey = 'revit-activation';
            if (LoginThrottleService::tooManyAttempts($throttleKey)) {
                throw new RuntimeException('Слишком много попыток подключения. Повторите через 15 минут.');
            }
            try {
                $result = $service->exchangeActivationCode(
                    (string) ($input['code'] ?? ''),
                    (string) ($input['device_name'] ?? ''),
                    (string) ($input['plugin_version'] ?? '')
                );
                LoginThrottleService::clear($throttleKey);
                return $result;
            } catch (RuntimeException $e) {
                LoginThrottleService::registerFailure($throttleKey);
                throw $e;
            }
        }, false);
    }

    public function projects(): void
    {
        $this->api(function (RevitIntegrationService $service, array $input, array $user): array {
            return ['projects' => $service->projectsForUser($user)];
        });
    }

    public function models(int $projectId): void
    {
        $this->api(function (RevitIntegrationService $service, array $input, array $user) use ($projectId): array {
            $allowed = array_column($service->projectsForUser($user), null, 'id');
            if (!isset($allowed[$projectId])) {
                throw new RuntimeException('Проект недоступен для публикации.');
            }
            return ['models' => $service->modelSeriesForProject($projectId)];
        });
    }

    public function createModel(int $projectId): void
    {
        $this->api(function (RevitIntegrationService $service, array $input, array $user) use ($projectId): array {
            return ['model' => $service->createModelSeries(
                $user,
                $projectId,
                (string) ($input['name'] ?? ''),
                (string) ($input['discipline'] ?? '')
            )];
        }, true, 201);
    }

    public function startUpload(int $modelId): void
    {
        $this->api(function (RevitIntegrationService $service, array $input, array $user) use ($modelId): array {
            return ['upload' => $service->startUpload($user, $modelId, $input)];
        }, true, 201);
    }

    public function uploadStatus(string $uploadId): void
    {
        $this->api(function (RevitIntegrationService $service, array $input, array $user) use ($uploadId): array {
            return ['upload' => $service->uploadStatus($uploadId, (int) $user['id'])];
        });
    }

    public function uploadChunk(string $uploadId, int $index): void
    {
        $this->api(function (RevitIntegrationService $service, array $input, array $user) use ($uploadId, $index): array {
            $body = (string) file_get_contents('php://input');
            return ['upload' => $service->storeChunk($uploadId, (int) $user['id'], $index, $body)];
        });
    }

    public function completeUpload(string $uploadId): void
    {
        $this->api(function (RevitIntegrationService $service, array $input, array $user) use ($uploadId): array {
            return ['version' => $service->completeUpload($uploadId, $user)];
        });
    }

    public function issueCode(): void
    {
        $user = require_auth();
        $code = $this->service()->issueActivationCode((int) $user['id']);
        $_SESSION['revit_activation_code'] = $code;
        $_SESSION['revit_activation_expires_at'] = time() + max(60, (int) config('revit.activation_ttl_seconds', 600));
        flash('success', 'Одноразовый код создан. Он действует 10 минут.');
        redirect('/profile#revit-integration');
    }

    public function revokeToken(int $tokenId): void
    {
        $user = require_auth();
        $this->service()->revokeToken((int) $user['id'], $tokenId);
        flash('success', 'Подключение Revit отозвано.');
        redirect('/profile#revit-integration');
    }

    public function setCurrent(int $projectId, int $seriesId, int $versionId): void
    {
        $user = require_auth();
        try {
            $this->service()->setCurrentVersion($user, $projectId, $seriesId, $versionId);
            flash('success', 'Текущая версия модели изменена.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/projects/' . $projectId . '#revit-models');
    }

    public function deleteVersion(int $projectId, int $seriesId, int $versionId): void
    {
        $user = require_auth();
        try {
            $this->service()->deleteVersion($user, $projectId, $seriesId, $versionId);
            flash('success', 'Версия модели удалена.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/projects/' . $projectId . '#revit-models');
    }

    public function versionFile(int $versionId): void
    {
        $user = require_auth();
        try {
            [$version, $path] = $this->service()->versionFile($user, $versionId);
        } catch (RuntimeException $e) {
            http_response_code(404);
            echo 'Файл версии модели не найден.';
            return;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $version['original_filename']) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    private function service(): RevitIntegrationService
    {
        return new RevitIntegrationService($this->db());
    }

    private function api(callable $callback, bool $requireBody = false, int $successStatus = 200): never
    {
        $requestId = bin2hex(random_bytes(8));
        header('X-Request-Id: ' . $requestId);
        try {
            if (!(bool) config('revit.enabled', true)) {
                throw new RuntimeException('Интеграция Revit отключена.');
            }
            $service = $this->service();
            $input = [];
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            if (str_contains($contentType, 'application/json')) {
                $raw = (string) file_get_contents('php://input');
                $decoded = $raw !== '' ? json_decode($raw, true) : [];
                if (!is_array($decoded)) {
                    throw new RuntimeException('Тело запроса должно быть корректным JSON.');
                }
                $input = $decoded;
            }
            if ($requireBody && $input === []) {
                throw new RuntimeException('Пустое тело запроса.');
            }

            $user = [];
            $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));
            if ($reflection->getNumberOfParameters() >= 3) {
                $user = $service->authenticate($this->bearerToken());
            }
            $result = $callback($service, $input, $user);
            json_response(['ok' => true, 'request_id' => $requestId] + $result, $successStatus);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status = str_contains($message, 'Токен') || str_contains($message, 'Bearer') ? 401 : 422;
            if (str_contains($message, 'Слишком много попыток')) {
                $status = 429;
            }
            if (str_contains($message, 'Недостаточно прав') || str_contains($message, 'недоступен')) {
                $status = 403;
            }
            json_response([
                'ok' => false,
                'request_id' => $requestId,
                'error' => [
                    'code' => $this->errorCode($status),
                    'message' => $message,
                    'retryable' => false,
                ],
            ], $status);
        } catch (\Throwable $e) {
            error_log('Revit API ' . $requestId . ': ' . $e->getMessage());
            json_response([
                'ok' => false,
                'request_id' => $requestId,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Внутренняя ошибка Лемме.',
                    'retryable' => true,
                ],
            ], 500);
        }
    }

    private function bearerToken(): string
    {
        $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return '';
        }
        return trim($matches[1]);
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            401 => 'unauthorized',
            403 => 'forbidden',
            429 => 'rate_limited',
            default => 'validation_error',
        };
    }
}
