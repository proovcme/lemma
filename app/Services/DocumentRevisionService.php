<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;

final class DocumentRevisionService
{
    public static function revisionNumberForIssue(int $issueNumber): int
    {
        return max(0, $issueNumber - 1);
    }

    public static function validateReason(int $issueNumber, string $reason): string
    {
        $reason = trim($reason);
        if ($issueNumber > 1 && $reason === '') {
            throw new InvalidArgumentException('Для повторной выдачи укажите основание изменения.');
        }

        return $reason !== '' ? $reason : 'Первичная выдача';
    }

    public static function createForIssuance(PDO $pdo, array $task, int $issuanceId, int $issueNumber, int $createdBy, string $reason, string $summary): int
    {
        $revisionNo = self::revisionNumberForIssue($issueNumber);
        $reason = self::validateReason($issueNumber, $reason);
        $summary = trim($summary);

        $stmt = $pdo->prepare('
            INSERT INTO document_revisions (project_id, task_id, issuance_id, revision_no, reason, summary, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            (int) ($task['project_id'] ?? 0),
            (int) ($task['id'] ?? 0),
            $issuanceId,
            $revisionNo,
            $reason,
            $summary !== '' ? $summary : null,
            $createdBy,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public static function listForTask(PDO $pdo, int $taskId): array
    {
        $stmt = $pdo->prepare('
            SELECT r.*, i.issue_number, i.issued_at, i.status AS issuance_status, u.name AS created_by_name
            FROM document_revisions r
            INNER JOIN task_issuances i ON i.id = r.issuance_id
            LEFT JOIN users u ON u.id = r.created_by
            WHERE r.task_id = ?
            ORDER BY r.revision_no ASC, r.id ASC
        ');
        $stmt->execute([$taskId]);

        return $stmt->fetchAll();
    }
}
