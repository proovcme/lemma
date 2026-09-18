<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class RevitIntegrationService
{
    private const API_VERSION = '1.0';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function capabilities(): array
    {
        return [
            'api_version' => self::API_VERSION,
            'enabled' => (bool) config('revit.enabled', true),
            'chunk_bytes' => $this->chunkBytes(),
            'max_file_bytes' => $this->maxFileBytes(),
            'supported_revit_versions' => ['2024', '2025', '2026'],
            'supported_formats' => ['ifc'],
        ];
    }

    public function issueActivationCode(int $userId): string
    {
        $this->pdo->prepare('DELETE FROM revit_activation_codes WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $code = strtoupper(bin2hex(random_bytes(4)));
        $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int) config('revit.activation_ttl_seconds', 600)));
        $stmt = $this->pdo->prepare('
            INSERT INTO revit_activation_codes (user_id, code_hash, expires_at)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([$userId, $this->activationHash($code), $expiresAt]);
        return $code;
    }

    public function exchangeActivationCode(string $code, string $deviceName, string $pluginVersion): array
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-F0-9]{8}$/', $code)) {
            throw new RuntimeException('Код подключения имеет неверный формат.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                SELECT c.id, c.user_id, u.name, u.email
                FROM revit_activation_codes c
                INNER JOIN users u ON u.id = c.user_id
                WHERE c.code_hash = ?
                  AND c.used_at IS NULL
                  AND c.expires_at >= ?
                  AND u.is_active = 1
                LIMIT 1
            ');
            $stmt->execute([$this->activationHash($code), date('Y-m-d H:i:s')]);
            $activation = $stmt->fetch();
            if (!$activation) {
                throw new RuntimeException('Код подключения истёк или уже использован.');
            }

            $token = $this->base64Url(random_bytes(32));
            $this->pdo->prepare('UPDATE revit_activation_codes SET used_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), (int) $activation['id']]);
            $insert = $this->pdo->prepare('
                INSERT INTO revit_api_tokens (user_id, token_hash, device_name, plugin_version)
                VALUES (?, ?, ?, ?)
            ');
            $insert->execute([
                (int) $activation['user_id'],
                hash('sha256', $token),
                $this->cleanText($deviceName, 190) ?: 'Revit',
                $this->cleanText($pluginVersion, 40),
            ]);
            $this->pdo->commit();

            return [
                'token' => $token,
                'user' => [
                    'id' => (int) $activation['user_id'],
                    'name' => (string) $activation['name'],
                    'email' => (string) $activation['email'],
                ],
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function authenticate(string $bearer): array
    {
        $bearer = trim($bearer);
        if ($bearer === '') {
            throw new RuntimeException('Требуется Bearer token.');
        }
        $stmt = $this->pdo->prepare('
            SELECT t.id AS token_id, t.user_id, u.*
            FROM revit_api_tokens t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = ? AND t.revoked_at IS NULL AND u.is_active = 1
            LIMIT 1
        ');
        $stmt->execute([hash('sha256', $bearer)]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('Токен недействителен или отозван.');
        }
        $this->pdo->prepare('UPDATE revit_api_tokens SET last_used_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), (int) $user['token_id']]);
        return $user;
    }

    public function tokensForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, device_name, plugin_version, last_used_at, revoked_at, created_at
            FROM revit_api_tokens
            WHERE user_id = ?
            ORDER BY created_at DESC, id DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function revokeToken(int $userId, int $tokenId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE revit_api_tokens SET revoked_at = ?
            WHERE id = ? AND user_id = ? AND revoked_at IS NULL
        ');
        $stmt->execute([date('Y-m-d H:i:s'), $tokenId, $userId]);
    }

    public function projectsForUser(array $user): array
    {
        [$where, $params] = PermissionService::projectScopeWhere($user, 'p', 'revit_scope_task');
        $stmt = $this->pdo->prepare('
            SELECT p.id, p.code, p.title, p.status, p.gip_user_id, p.rp_user_id
            FROM projects p
            WHERE p.status != "archived"
              AND COALESCE(p.kind, "project") = "project"
              AND ' . $where . '
            ORDER BY p.code, p.title
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['gip_user_id'] = $row['gip_user_id'] !== null ? (int) $row['gip_user_id'] : null;
            $row['rp_user_id'] = $row['rp_user_id'] !== null ? (int) $row['rp_user_id'] : null;
            $row['can_create_model'] = PermissionService::canManageProjectModels($user, $row);
            $row['can_upload'] = $this->canUpload($user, $row);
        }
        unset($row);
        return array_values(array_filter($rows, static fn (array $row): bool => !empty($row['can_upload'])));
    }

    public function modelSeriesForProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT s.*, v.version_number AS current_version_number, v.created_at AS current_version_at,
                   v.byte_size AS current_version_size, u.name AS current_version_author
            FROM project_model_series s
            LEFT JOIN project_model_versions v ON v.id = s.current_version_id
            LEFT JOIN users u ON u.id = v.created_by
            WHERE s.project_id = ?
            ORDER BY s.discipline, s.name, s.id
        ');
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            foreach (['id', 'project_id', 'created_by', 'current_version_id'] as $field) {
                $row[$field] = $row[$field] !== null ? (int) $row[$field] : null;
            }
            foreach (['current_version_number', 'current_version_size'] as $field) {
                $row[$field] = $row[$field] !== null ? (int) $row[$field] : null;
            }
        }
        unset($row);
        return $rows;
    }

    public function modelSeriesWithVersions(int $projectId): array
    {
        $series = $this->modelSeriesForProject($projectId);
        $versionsStmt = $this->pdo->prepare('
            SELECT v.*, u.name AS created_by_name
            FROM project_model_versions v
            LEFT JOIN users u ON u.id = v.created_by
            WHERE v.model_series_id = ?
            ORDER BY v.version_number DESC
        ');
        foreach ($series as &$item) {
            $versionsStmt->execute([(int) $item['id']]);
            $item['versions'] = $versionsStmt->fetchAll();
        }
        unset($item);
        return $series;
    }

    public function createModelSeries(array $user, int $projectId, string $name, string $discipline): array
    {
        $project = $this->projectForUser($user, $projectId);
        if (!$project || !PermissionService::canManageProjectModels($user, $project)) {
            throw new RuntimeException('Недостаточно прав для создания карточки модели.');
        }
        $name = $this->cleanText($name, 190);
        if ($name === '') {
            throw new RuntimeException('Укажите название модели.');
        }
        $stmt = $this->pdo->prepare('
            INSERT INTO project_model_series (project_id, name, discipline, created_by)
            VALUES (?, ?, ?, ?)
        ');
        try {
            $stmt->execute([$projectId, $name, $this->cleanText($discipline, 80), (int) $user['id']]);
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new RuntimeException('Карточка модели с таким названием уже существует.');
            }
            throw $e;
        }
        return $this->modelSeries((int) $this->pdo->lastInsertId());
    }

    public function startUpload(array $user, int $modelId, array $input): array
    {
        $model = $this->modelSeries($modelId);
        $project = $this->projectForUser($user, (int) $model['project_id']);
        if (!$project || !$this->canUpload($user, $project)) {
            throw new RuntimeException('Недостаточно прав для публикации модели.');
        }

        $size = (int) ($input['byte_size'] ?? 0);
        $sha = strtolower(trim((string) ($input['sha256'] ?? '')));
        $filename = $this->safeIfcFilename((string) ($input['filename'] ?? 'model.ifc'));
        $idempotencyKey = $this->cleanText((string) ($input['idempotency_key'] ?? ''), 100);
        if ($size <= 0 || $size > $this->maxFileBytes()) {
            throw new RuntimeException('Размер IFC отсутствует или превышает лимит сервера.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new RuntimeException('Некорректный SHA-256 файла.');
        }
        if ($idempotencyKey === '') {
            throw new RuntimeException('Требуется idempotency_key.');
        }

        $existing = $this->pdo->prepare('SELECT * FROM revit_upload_sessions WHERE user_id = ? AND idempotency_key = ? LIMIT 1');
        $existing->execute([(int) $user['id'], $idempotencyKey]);
        $row = $existing->fetch();
        if ($row) {
            if ((int) $row['model_series_id'] !== $modelId
                || (int) $row['expected_size'] !== $size
                || !hash_equals((string) $row['expected_sha256'], $sha)) {
                throw new RuntimeException('idempotency_key уже использован для другой загрузки.');
            }
            return $this->uploadPayload($row);
        }

        $chunkSize = $this->chunkBytes();
        $chunkCount = (int) ceil($size / $chunkSize);
        $sessionId = $this->uuidV4();
        $metadata = [
            'comment' => $this->cleanText((string) ($input['comment'] ?? ''), 4000),
            'revit_version' => $this->cleanText((string) ($input['revit_version'] ?? ''), 40),
            'document_title' => $this->cleanText((string) ($input['document_title'] ?? ''), 255),
            'document_unique_id' => $this->cleanText((string) ($input['document_unique_id'] ?? ''), 255),
            'view_name' => $this->cleanText((string) ($input['view_name'] ?? ''), 255),
            'view_unique_id' => $this->cleanText((string) ($input['view_unique_id'] ?? ''), 255),
            'ifc_profile' => $this->cleanText((string) ($input['ifc_profile'] ?? ''), 255),
        ];
        $stmt = $this->pdo->prepare('
            INSERT INTO revit_upload_sessions
            (id, model_series_id, user_id, idempotency_key, original_filename, expected_size,
             expected_sha256, metadata_json, chunk_size, chunk_count, received_chunks_json, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $sessionId,
            $modelId,
            (int) $user['id'],
            $idempotencyKey,
            $filename,
            $size,
            $sha,
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $chunkSize,
            $chunkCount,
            '[]',
            date('Y-m-d H:i:s', time() + max(3600, (int) config('revit.upload_ttl_seconds', 86400))),
        ]);
        $this->ensureDirectory($this->uploadSessionDir($sessionId));
        return $this->uploadSession($sessionId, (int) $user['id']);
    }

    public function uploadStatus(string $sessionId, int $userId): array
    {
        return $this->uploadPayload($this->uploadSessionRow($sessionId, $userId));
    }

    public function storeChunk(string $sessionId, int $userId, int $index, string $body): array
    {
        $session = $this->uploadSessionRow($sessionId, $userId);
        if ((string) $session['status'] !== 'uploading') {
            return $this->uploadPayload($session);
        }
        $chunkCount = (int) $session['chunk_count'];
        if ($index < 0 || $index >= $chunkCount) {
            throw new RuntimeException('Неверный индекс части файла.');
        }
        $expected = $index === $chunkCount - 1
            ? (int) $session['expected_size'] - ($index * (int) $session['chunk_size'])
            : (int) $session['chunk_size'];
        if (strlen($body) !== $expected) {
            throw new RuntimeException('Размер части файла не совпадает с ожидаемым.');
        }
        $dir = $this->uploadSessionDir($sessionId);
        $this->ensureDirectory($dir);
        $target = $dir . '/' . $index . '.part';
        $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $body, LOCK_EX) !== strlen($body) || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Сервер не смог сохранить часть IFC.');
        }
        $received = $this->receivedChunks($session);
        $received[$index] = true;
        ksort($received, SORT_NUMERIC);
        $this->pdo->prepare('
            UPDATE revit_upload_sessions SET received_chunks_json = ?, updated_at = ?
            WHERE id = ? AND user_id = ?
        ')->execute([
            json_encode(array_map('intval', array_keys($received))),
            date('Y-m-d H:i:s'),
            $sessionId,
            $userId,
        ]);
        return $this->uploadStatus($sessionId, $userId);
    }

    public function completeUpload(string $sessionId, array $user): array
    {
        $session = $this->uploadSessionRow($sessionId, (int) $user['id']);
        $model = $this->modelSeries((int) $session['model_series_id']);
        $project = $this->projectForUser($user, (int) $model['project_id']);
        if (!$project || !$this->canUpload($user, $project)) {
            throw new RuntimeException('Недостаточно прав для публикации модели.');
        }
        if ((string) $session['status'] === 'completed' && !empty($session['completed_version_id'])) {
            return $this->version((int) $session['completed_version_id']);
        }
        $received = $this->receivedChunks($session);
        if (count($received) !== (int) $session['chunk_count']) {
            throw new RuntimeException('Получены не все части IFC.');
        }

        $dir = $this->uploadSessionDir($sessionId);
        $assembled = $dir . '/assembled.ifc';
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new RuntimeException('Сервер не смог собрать IFC.');
        }
        try {
            for ($i = 0; $i < (int) $session['chunk_count']; $i++) {
                $part = $dir . '/' . $i . '.part';
                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new RuntimeException('Не найдена часть IFC: ' . $i . '.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
        if ((int) filesize($assembled) !== (int) $session['expected_size']
            || !hash_equals((string) $session['expected_sha256'], hash_file('sha256', $assembled))) {
            @unlink($assembled);
            throw new RuntimeException('Контрольная сумма или размер собранного IFC не совпали.');
        }

        $driver = Database::driver();
        $sqliteImmediate = $driver === 'sqlite';
        $transactionActive = false;
        if ($sqliteImmediate) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
        $transactionActive = true;
        $finalPath = '';
        try {
            $seriesSql = 'SELECT * FROM project_model_series WHERE id = ?';
            if ($driver !== 'sqlite') {
                $seriesSql .= ' FOR UPDATE';
            }
            $stmt = $this->pdo->prepare($seriesSql);
            $stmt->execute([(int) $session['model_series_id']]);
            $series = $stmt->fetch();
            if (!$series) {
                throw new RuntimeException('Карточка модели не найдена.');
            }
            $versionNumber = (int) $series['next_version_number'];
            $relative = (int) $series['project_id'] . '/' . (int) $series['id']
                . '/v' . str_pad((string) $versionNumber, 3, '0', STR_PAD_LEFT) . '/model.ifc';
            $finalPath = rtrim((string) config('revit.storage_dir', BASE_PATH . '/storage/revit-models'), '/\\') . '/' . $relative;
            $this->ensureDirectory(dirname($finalPath));
            if (!rename($assembled, $finalPath)) {
                throw new RuntimeException('Не удалось переместить IFC в постоянное хранилище.');
            }

            $meta = json_decode((string) $session['metadata_json'], true) ?: [];
            $insert = $this->pdo->prepare('
                INSERT INTO project_model_versions
                (model_series_id, version_number, file_relative_path, original_filename, byte_size, sha256,
                 comment, revit_version, document_title, document_unique_id, view_name, view_unique_id,
                 ifc_profile, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insert->execute([
                (int) $series['id'],
                $versionNumber,
                $relative,
                (string) $session['original_filename'],
                (int) $session['expected_size'],
                (string) $session['expected_sha256'],
                (string) ($meta['comment'] ?? ''),
                (string) ($meta['revit_version'] ?? ''),
                (string) ($meta['document_title'] ?? ''),
                (string) ($meta['document_unique_id'] ?? ''),
                (string) ($meta['view_name'] ?? ''),
                (string) ($meta['view_unique_id'] ?? ''),
                (string) ($meta['ifc_profile'] ?? ''),
                (int) $user['id'],
            ]);
            $versionId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('
                UPDATE project_model_series
                SET current_version_id = ?, next_version_number = ?, updated_at = ?
                WHERE id = ?
            ')->execute([$versionId, $versionNumber + 1, date('Y-m-d H:i:s'), (int) $series['id']]);
            $this->pdo->prepare('
                UPDATE revit_upload_sessions
                SET status = "completed", completed_version_id = ?, updated_at = ?
                WHERE id = ?
            ')->execute([$versionId, date('Y-m-d H:i:s'), $sessionId]);
            if ($sqliteImmediate) {
                $this->pdo->exec('COMMIT');
            } else {
                $this->pdo->commit();
            }
            $transactionActive = false;
            $this->removeChunkParts($dir);
            ActivityLogService::recordProject(
                (int) $series['project_id'],
                (int) $user['id'],
                'project.revit_model_version_published',
                'Опубликована версия модели из Revit',
                (string) $series['name'] . ' · v' . str_pad((string) $versionNumber, 3, '0', STR_PAD_LEFT)
            );
            return $this->version($versionId);
        } catch (\Throwable $e) {
            if ($transactionActive) {
                if ($sqliteImmediate) {
                    $this->pdo->exec('ROLLBACK');
                } elseif ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }
            if ($finalPath !== '' && is_file($finalPath)) {
                @rename($finalPath, $assembled);
            }
            throw $e;
        }
    }

    public function setCurrentVersion(array $user, int $projectId, int $seriesId, int $versionId): void
    {
        $project = $this->projectForUser($user, $projectId);
        if (!$project || !PermissionService::canManageProjectModels($user, $project)) {
            throw new RuntimeException('Недостаточно прав для смены текущей версии.');
        }
        $stmt = $this->pdo->prepare('
            SELECT v.id FROM project_model_versions v
            INNER JOIN project_model_series s ON s.id = v.model_series_id
            WHERE v.id = ? AND s.id = ? AND s.project_id = ?
        ');
        $stmt->execute([$versionId, $seriesId, $projectId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Версия модели не найдена.');
        }
        $this->pdo->prepare('UPDATE project_model_series SET current_version_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$versionId, date('Y-m-d H:i:s'), $seriesId]);
    }

    public function deleteVersion(array $user, int $projectId, int $seriesId, int $versionId): void
    {
        $project = $this->projectForUser($user, $projectId);
        if (!$project || !PermissionService::canManageProjectModels($user, $project)) {
            throw new RuntimeException('Недостаточно прав для удаления версии.');
        }
        $version = $this->version($versionId);
        if ((int) $version['model_series_id'] !== $seriesId || (int) $version['project_id'] !== $projectId) {
            throw new RuntimeException('Версия модели не найдена.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE project_model_series SET current_version_id = NULL WHERE id = ? AND current_version_id = ?')
                ->execute([$seriesId, $versionId]);
            $this->pdo->prepare('DELETE FROM project_model_versions WHERE id = ? AND model_series_id = ?')
                ->execute([$versionId, $seriesId]);
            $latest = $this->pdo->prepare('SELECT id FROM project_model_versions WHERE model_series_id = ? ORDER BY version_number DESC LIMIT 1');
            $latest->execute([$seriesId]);
            $latestId = $latest->fetchColumn();
            $this->pdo->prepare('
                UPDATE project_model_series SET current_version_id = COALESCE(current_version_id, ?), updated_at = ? WHERE id = ?
            ')->execute([$latestId !== false ? (int) $latestId : null, date('Y-m-d H:i:s'), $seriesId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $path = $this->versionAbsolutePath($version);
        @unlink($path);
        @unlink($path . '.frag');
    }

    public function versionFile(array $user, int $versionId): array
    {
        $version = $this->version($versionId);
        $project = $this->projectForUser($user, (int) $version['project_id']);
        if (!$project) {
            throw new RuntimeException('Нет доступа к версии модели.');
        }
        $path = $this->versionAbsolutePath($version);
        if (!is_file($path) || !hash_equals((string) $version['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Файл версии отсутствует или повреждён.');
        }
        return [$version, $path];
    }

    public function cleanupExpiredUploads(): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM revit_upload_sessions WHERE status != "completed" AND expires_at < ?');
        $stmt->execute([date('Y-m-d H:i:s')]);
        $ids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            $this->removeDirectory($this->uploadSessionDir($id));
        }
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare('DELETE FROM revit_upload_sessions WHERE id IN (' . $marks . ')')->execute($ids);
        }
        return count($ids);
    }

    private function canUpload(array $user, array $project): bool
    {
        if ((string) ($project['status'] ?? '') === 'archived') {
            return false;
        }
        $userId = (int) ($user['id'] ?? 0);
        if (in_array($userId, [(int) ($project['gip_user_id'] ?? 0), (int) ($project['rp_user_id'] ?? 0)], true)) {
            return true;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? AND active = 1 LIMIT 1');
        $stmt->execute([(int) $project['id'], $userId]);
        return (bool) $stmt->fetchColumn() || PermissionService::canManageProjectModels($user, $project);
    }

    private function projectForUser(array $user, int $projectId): ?array
    {
        [$where, $params] = PermissionService::projectScopeWhere($user, 'p', 'revit_project_task');
        $stmt = $this->pdo->prepare('SELECT p.* FROM projects p WHERE p.id = :revit_project_id AND ' . $where . ' LIMIT 1');
        $stmt->execute(['revit_project_id' => $projectId] + $params);
        return $stmt->fetch() ?: null;
    }

    private function modelSeries(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM project_model_series WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Карточка модели не найдена.');
        }
        return $row;
    }

    private function version(int $id): array
    {
        $stmt = $this->pdo->prepare('
            SELECT v.*, s.project_id, s.name AS model_name, s.discipline, s.current_version_id,
                   u.name AS created_by_name
            FROM project_model_versions v
            INNER JOIN project_model_series s ON s.id = v.model_series_id
            LEFT JOIN users u ON u.id = v.created_by
            WHERE v.id = ?
            LIMIT 1
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Версия модели не найдена.');
        }
        $row['version_code'] = 'v' . str_pad((string) $row['version_number'], 3, '0', STR_PAD_LEFT);
        return $row;
    }

    private function uploadSession(string $id, int $userId): array
    {
        return $this->uploadPayload($this->uploadSessionRow($id, $userId));
    }

    private function uploadSessionRow(string $id, int $userId): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            throw new RuntimeException('Некорректный идентификатор загрузки.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM revit_upload_sessions WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Сессия загрузки не найдена.');
        }
        if ((string) $row['status'] !== 'completed' && strtotime((string) $row['expires_at']) < time()) {
            throw new RuntimeException('Сессия загрузки истекла.');
        }
        return $row;
    }

    private function uploadPayload(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'model_id' => (int) $row['model_series_id'],
            'status' => (string) $row['status'],
            'chunk_bytes' => (int) $row['chunk_size'],
            'chunk_count' => (int) $row['chunk_count'],
            'received_chunks' => array_map('intval', array_keys($this->receivedChunks($row))),
            'completed_version_id' => !empty($row['completed_version_id']) ? (int) $row['completed_version_id'] : null,
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    private function receivedChunks(array $session): array
    {
        $items = json_decode((string) ($session['received_chunks_json'] ?? '[]'), true);
        $received = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $index = (int) $item;
            if ($index >= 0 && $index < (int) $session['chunk_count']) {
                $received[$index] = true;
            }
        }
        return $received;
    }

    private function activationHash(string $code): string
    {
        $pepper = (string) config('security.data_key', '');
        if ($pepper === '') {
            $pepper = (string) config('app.url', 'locia');
        }
        return hash_hmac('sha256', $code, $pepper);
    }

    private function chunkBytes(): int
    {
        return max(1024 * 1024, min(32 * 1024 * 1024, (int) config('revit.chunk_bytes', 8 * 1024 * 1024)));
    }

    private function maxFileBytes(): int
    {
        return max($this->chunkBytes(), (int) config('revit.max_file_bytes', 2 * 1024 * 1024 * 1024));
    }

    private function uploadSessionDir(string $sessionId): string
    {
        return rtrim((string) config('revit.upload_dir', BASE_PATH . '/storage/revit-uploads'), '/\\') . '/' . $sessionId;
    }

    private function versionAbsolutePath(array $version): string
    {
        $relative = str_replace('\\', '/', (string) $version['file_relative_path']);
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('Некорректный путь версии модели.');
        }
        return rtrim((string) config('revit.storage_dir', BASE_PATH . '/storage/revit-models'), '/\\') . '/' . $relative;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог хранения Revit.');
        }
    }

    private function removeChunkParts(string $dir): void
    {
        foreach (glob($dir . '/*.part') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    private function cleanText(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '');
        return mb_substr($value, 0, $max);
    }

    private function safeIfcFilename(string $value): string
    {
        $name = basename(str_replace('\\', '/', trim($value)));
        $name = preg_replace('/[^A-Za-z0-9._ -]+/u', '_', $name) ?: 'model.ifc';
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'ifc') {
            throw new RuntimeException('Разрешены только файлы IFC.');
        }
        return mb_substr($name, 0, 255);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
