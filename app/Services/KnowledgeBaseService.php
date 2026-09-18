<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;

final class KnowledgeBaseService
{
    public const MAX_FOLDER_DEPTH = 4;

    public static function canEdit(?array $user): bool
    {
        return in_array(
            RoleService::normalize($user['role'] ?? null),
            [RoleService::DIRECTOR, RoleService::ADMIN],
            true
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function folders(PDO $pdo): array
    {
        $rows = $pdo->query('
            SELECT f.*,
                   (SELECT COUNT(*) FROM knowledge_documents d WHERE d.folder_id = f.id AND d.status = \'published\') AS published_count,
                   (SELECT COUNT(*) FROM knowledge_documents d WHERE d.folder_id = f.id AND d.status = \'draft\') AS draft_count
            FROM knowledge_folders f
            WHERE f.archived_at IS NULL
            ORDER BY f.sort_order, f.name, f.id
        ')->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function folderOptions(array $folders, ?int $excludedId = null): array
    {
        $children = [];
        foreach ($folders as $folder) {
            $children[(int) ($folder['parent_id'] ?? 0)][] = $folder;
        }

        $result = [];
        $walk = static function (int $parentId, int $depth) use (&$walk, &$result, $children, $excludedId): void {
            foreach ($children[$parentId] ?? [] as $folder) {
                $id = (int) $folder['id'];
                if ($excludedId !== null && $id === $excludedId) {
                    continue;
                }
                $folder['depth'] = $depth;
                $result[] = $folder;
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $result;
    }

    public static function folder(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM knowledge_folders WHERE id = ? AND archived_at IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function breadcrumbs(PDO $pdo, ?int $folderId): array
    {
        $result = [];
        $seen = [];
        $currentId = (int) $folderId;
        while ($currentId > 0 && count($result) < self::MAX_FOLDER_DEPTH + 1) {
            if (isset($seen[$currentId])) {
                break;
            }
            $seen[$currentId] = true;
            $folder = self::folder($pdo, $currentId);
            if (!$folder) {
                break;
            }
            array_unshift($result, $folder);
            $currentId = (int) ($folder['parent_id'] ?? 0);
        }
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public static function documents(PDO $pdo, ?int $folderId, string $query, bool $includeDrafts): array
    {
        $where = [];
        $params = [];
        if (!$includeDrafts) {
            $where[] = "d.status = 'published'";
        } else {
            $where[] = "d.status != 'archived'";
        }
        $query = trim($query);
        if ($query !== '') {
            $where[] = '(d.title LIKE :query OR d.summary LIKE :query OR d.body_html LIKE :query OR d.draft_title LIKE :query OR d.draft_summary LIKE :query OR d.draft_body_html LIKE :query)';
            $params['query'] = '%' . $query . '%';
        } elseif ($folderId !== null && $folderId > 0) {
            $where[] = 'd.folder_id = :folder_id';
            $params['folder_id'] = $folderId;
        } else {
            $where[] = 'd.folder_id IS NULL';
        }

        $stmt = $pdo->prepare('
            SELECT d.*, f.name AS folder_name, u.name AS updated_by_name
            FROM knowledge_documents d
            LEFT JOIN knowledge_folders f ON f.id = d.folder_id
            LEFT JOIN users u ON u.id = d.updated_by
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY d.is_pinned DESC, d.sort_order, d.title, d.id
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $source = (string) (($row['summary'] ?? '') !== '' ? $row['summary'] : $row['body_html']);
            $row['excerpt'] = mb_substr(KnowledgeHtmlSanitizer::plainText($source), 0, 220);
        }
        unset($row);
        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public static function pinned(PDO $pdo, bool $includeDrafts): array
    {
        $status = $includeDrafts ? "d.status != 'archived'" : "d.status = 'published'";
        $stmt = $pdo->query('
            SELECT d.*, f.name AS folder_name
            FROM knowledge_documents d
            LEFT JOIN knowledge_folders f ON f.id = d.folder_id
            WHERE d.is_pinned = 1 AND ' . $status . '
            ORDER BY d.sort_order, d.updated_at DESC, d.id
            LIMIT 8
        ');
        return $stmt->fetchAll();
    }

    public static function document(PDO $pdo, int $id, bool $includeDrafts): ?array
    {
        $status = $includeDrafts ? "d.status != 'archived'" : "d.status = 'published'";
        $stmt = $pdo->prepare('
            SELECT d.*, f.name AS folder_name, u.name AS updated_by_name
            FROM knowledge_documents d
            LEFT JOIN knowledge_folders f ON f.id = d.folder_id
            LEFT JOIN users u ON u.id = d.updated_by
            WHERE d.id = ? AND ' . $status . '
            LIMIT 1
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function createFolder(PDO $pdo, array $data, int $userId): int
    {
        $name = self::requiredText($data['name'] ?? '', 160, 'Название папки');
        $parentId = self::nullableId($data['parent_id'] ?? null);
        $sortOrder = max(0, min(100000, (int) ($data['sort_order'] ?? 100)));
        self::assertParent($pdo, null, $parentId);

        $stmt = $pdo->prepare('
            INSERT INTO knowledge_folders (parent_id, name, sort_order, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$parentId, $name, $sortOrder, $userId, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateFolder(PDO $pdo, int $id, array $data, int $userId): void
    {
        if (!self::folder($pdo, $id)) {
            throw new InvalidArgumentException('Папка не найдена.');
        }
        $name = self::requiredText($data['name'] ?? '', 160, 'Название папки');
        $parentId = self::nullableId($data['parent_id'] ?? null);
        $sortOrder = max(0, min(100000, (int) ($data['sort_order'] ?? 100)));
        self::assertParent($pdo, $id, $parentId);

        $stmt = $pdo->prepare('
            UPDATE knowledge_folders
            SET parent_id = ?, name = ?, sort_order = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND archived_at IS NULL
        ');
        $stmt->execute([$parentId, $name, $sortOrder, $userId, $id]);
    }

    public static function createDocument(PDO $pdo, array $data, int $userId): int
    {
        $payload = self::draftPayload($pdo, $data);
        $stmt = $pdo->prepare('
            INSERT INTO knowledge_documents (
                folder_id, title, summary, body_html, status, is_pinned, sort_order,
                draft_folder_id, draft_title, draft_summary, draft_body_html, draft_is_pinned,
                created_by, updated_by, draft_updated_at
            ) VALUES (?, ?, ?, ?, \'draft\', ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            $payload['folder_id'], $payload['title'], $payload['summary'], $payload['body_html'],
            $payload['is_pinned'], $payload['sort_order'], $payload['folder_id'], $payload['title'],
            $payload['summary'], $payload['body_html'], $payload['is_pinned'], $userId, $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function saveDraft(PDO $pdo, int $id, array $data, int $userId): void
    {
        $document = self::document($pdo, $id, true);
        if (!$document) {
            throw new InvalidArgumentException('Документ не найден.');
        }
        $payload = self::draftPayload($pdo, $data);
        $stmt = $pdo->prepare('
            UPDATE knowledge_documents
            SET draft_folder_id = ?, draft_title = ?, draft_summary = ?, draft_body_html = ?,
                draft_is_pinned = ?, sort_order = ?, draft_updated_at = CURRENT_TIMESTAMP,
                updated_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status != \'archived\'
        ');
        $stmt->execute([
            $payload['folder_id'], $payload['title'], $payload['summary'], $payload['body_html'],
            $payload['is_pinned'], $payload['sort_order'], $userId, $id,
        ]);
    }

    public static function publish(PDO $pdo, int $id, array $data, int $userId): int
    {
        self::saveDraft($pdo, $id, $data, $userId);
        $document = self::document($pdo, $id, true);
        if (!$document) {
            throw new InvalidArgumentException('Документ не найден.');
        }
        $body = (string) ($document['draft_body_html'] ?? '');
        if (KnowledgeHtmlSanitizer::plainText($body) === '') {
            throw new InvalidArgumentException('Добавьте текст документа перед публикацией.');
        }
        $version = (int) ($document['current_version'] ?? 0) + 1;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                UPDATE knowledge_documents
                SET folder_id = draft_folder_id,
                    title = draft_title,
                    summary = draft_summary,
                    body_html = draft_body_html,
                    is_pinned = draft_is_pinned,
                    status = \'published\',
                    current_version = ?,
                    published_at = CURRENT_TIMESTAMP,
                    updated_by = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status != \'archived\'
            ');
            $stmt->execute([$version, $userId, $id]);

            $revision = $pdo->prepare('
                INSERT INTO knowledge_document_revisions (
                    document_id, version_no, folder_id, title, summary, body_html, is_pinned, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $revision->execute([
                $id, $version, $document['draft_folder_id'], $document['draft_title'],
                $document['draft_summary'], $document['draft_body_html'], $document['draft_is_pinned'], $userId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $version;
    }

    public static function archive(PDO $pdo, int $id, int $userId): void
    {
        $stmt = $pdo->prepare('
            UPDATE knowledge_documents
            SET status = \'archived\', updated_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$userId, $id]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Документ не найден.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function revisions(PDO $pdo, int $documentId): array
    {
        $stmt = $pdo->prepare('
            SELECT r.*, u.name AS created_by_name
            FROM knowledge_document_revisions r
            LEFT JOIN users u ON u.id = r.created_by
            WHERE r.document_id = ?
            ORDER BY r.version_no DESC, r.id DESC
        ');
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    public static function restoreRevision(PDO $pdo, int $documentId, int $revisionId, int $userId): void
    {
        $stmt = $pdo->prepare('
            SELECT * FROM knowledge_document_revisions
            WHERE id = ? AND document_id = ?
            LIMIT 1
        ');
        $stmt->execute([$revisionId, $documentId]);
        $revision = $stmt->fetch();
        if (!is_array($revision)) {
            throw new InvalidArgumentException('Версия документа не найдена.');
        }
        $update = $pdo->prepare('
            UPDATE knowledge_documents
            SET draft_folder_id = ?, draft_title = ?, draft_summary = ?, draft_body_html = ?,
                draft_is_pinned = ?, draft_updated_at = CURRENT_TIMESTAMP,
                updated_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status != \'archived\'
        ');
        $update->execute([
            $revision['folder_id'], $revision['title'], $revision['summary'], $revision['body_html'],
            $revision['is_pinned'], $userId, $documentId,
        ]);
    }

    private static function draftPayload(PDO $pdo, array $data): array
    {
        $folderId = self::nullableId($data['folder_id'] ?? null);
        if ($folderId !== null && !self::folder($pdo, $folderId)) {
            throw new InvalidArgumentException('Выбранная папка не найдена.');
        }
        return [
            'folder_id' => $folderId,
            'title' => self::requiredText($data['title'] ?? '', 240, 'Название документа'),
            'summary' => self::optionalText($data['summary'] ?? '', 600),
            'body_html' => KnowledgeHtmlSanitizer::sanitize((string) ($data['body_html'] ?? '')),
            'is_pinned' => !empty($data['is_pinned']) ? 1 : 0,
            'sort_order' => max(0, min(100000, (int) ($data['sort_order'] ?? 100))),
        ];
    }

    private static function assertParent(PDO $pdo, ?int $folderId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($folderId !== null && $folderId === $parentId) {
            throw new InvalidArgumentException('Папка не может находиться внутри самой себя.');
        }
        $depth = 1;
        $seen = [];
        $currentId = $parentId;
        while ($currentId > 0) {
            if (isset($seen[$currentId])) {
                throw new InvalidArgumentException('В структуре папок обнаружен цикл.');
            }
            $seen[$currentId] = true;
            if ($folderId !== null && $currentId === $folderId) {
                throw new InvalidArgumentException('Нельзя переместить папку внутрь её дочерней папки.');
            }
            $folder = self::folder($pdo, $currentId);
            if (!$folder) {
                throw new InvalidArgumentException('Родительская папка не найдена.');
            }
            $currentId = (int) ($folder['parent_id'] ?? 0);
            $depth++;
            if ($depth > self::MAX_FOLDER_DEPTH) {
                throw new InvalidArgumentException('Допустимо не больше четырёх уровней папок.');
            }
        }
    }

    private static function nullableId(mixed $value): ?int
    {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private static function requiredText(mixed $value, int $maxLength, string $label): string
    {
        $text = self::optionalText($value, $maxLength);
        if ($text === '') {
            throw new InvalidArgumentException($label . ' обязательно.');
        }
        return $text;
    }

    private static function optionalText(mixed $value, int $maxLength): string
    {
        $text = trim((string) $value);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return mb_substr($text, 0, $maxLength);
    }
}
