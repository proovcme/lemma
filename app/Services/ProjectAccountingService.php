<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class ProjectAccountingService
{
    public static function forProjectPage(int $projectId): array
    {
        $pdo = Database::pdo();

        return [
            'pp' => self::ppCodes($pdo, $projectId, false),
            'btp' => self::btpCodes($pdo, $projectId, false),
            'uts' => self::utsFacts($pdo, $projectId),
        ];
    }

    public static function forTaskForm(?int $projectId = null, array $visibleProjectIds = []): array
    {
        $pdo = Database::pdo();
        $projectIds = $projectId ? [$projectId] : array_values(array_unique(array_filter(array_map('intval', $visibleProjectIds))));
        if ($projectIds === []) {
            return ['pp' => [], 'btp' => []];
        }

        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $pp = $pdo->prepare('
            SELECT pp.*, p.code AS project_code
            FROM project_pp_codes pp
            INNER JOIN projects p ON p.id = pp.project_id
            WHERE pp.active = 1 AND pp.project_id IN (' . $placeholders . ')
            ORDER BY pp.project_id, pp.sort_order, pp.code
        ');
        $pp->execute($projectIds);

        $btp = $pdo->prepare('
            SELECT btp.*, pp.code AS pp_code, pp.title AS pp_title, p.code AS project_code
            FROM project_btp_codes btp
            INNER JOIN project_pp_codes pp ON pp.id = btp.pp_code_id
            INNER JOIN projects p ON p.id = btp.project_id
            WHERE btp.active = 1 AND btp.project_id IN (' . $placeholders . ')
            ORDER BY btp.project_id, pp.sort_order, pp.code, btp.sort_order, btp.code
        ');
        $btp->execute($projectIds);

        return [
            'pp' => $pp->fetchAll(),
            'btp' => $btp->fetchAll(),
        ];
    }

    public static function ppPayload(array $source): array
    {
        return [
            'code' => trim((string) ($source['code'] ?? '')),
            'title' => trim((string) ($source['title'] ?? '')),
            'notes' => trim((string) ($source['notes'] ?? '')),
            'active' => isset($source['active']) ? 1 : 1,
            'sort_order' => (int) ($source['sort_order'] ?? 0),
        ];
    }

    public static function btpPayload(array $source): array
    {
        return [
            'pp_code_id' => (int) ($source['pp_code_id'] ?? 0),
            'code' => trim((string) ($source['code'] ?? '')),
            'title' => trim((string) ($source['title'] ?? '')),
            'notes' => trim((string) ($source['notes'] ?? '')),
            'active' => isset($source['active']) ? 1 : 1,
            'sort_order' => (int) ($source['sort_order'] ?? 0),
        ];
    }

    public static function utsPayload(array $source): array
    {
        return [
            'pp_code_id' => (int) ($source['pp_code_id'] ?? 0),
            'btp_code_id' => ((string) ($source['btp_code_id'] ?? '') !== '') ? (int) $source['btp_code_id'] : null,
            'fact_date' => self::nullableDate($source['fact_date'] ?? ''),
            'amount' => self::decimal($source['amount'] ?? 0),
            'description' => trim((string) ($source['description'] ?? '')),
            'document_ref' => trim((string) ($source['document_ref'] ?? '')),
        ];
    }

    public static function savePp(int $projectId, array $data): void
    {
        $pdo = Database::pdo();
        if (self::isSqlite($pdo)) {
            $stmt = $pdo->prepare('
                INSERT INTO project_pp_codes (project_id, code, title, notes, active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(project_id, code) DO UPDATE SET
                    title = excluded.title,
                    notes = excluded.notes,
                    active = excluded.active,
                    sort_order = excluded.sort_order,
                    updated_at = CURRENT_TIMESTAMP
            ');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO project_pp_codes (project_id, code, title, notes, active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE title = VALUES(title), notes = VALUES(notes), active = VALUES(active), sort_order = VALUES(sort_order)
            ');
        }
        $stmt->execute([$projectId, $data['code'], $data['title'], $data['notes'], $data['active'], $data['sort_order']]);
    }

    public static function saveBtp(int $projectId, array $data): void
    {
        self::assertPpBelongsToProject($projectId, (int) $data['pp_code_id']);
        $pdo = Database::pdo();
        if (self::isSqlite($pdo)) {
            $stmt = $pdo->prepare('
                INSERT INTO project_btp_codes (project_id, pp_code_id, code, title, notes, active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(project_id, pp_code_id, code) DO UPDATE SET
                    title = excluded.title,
                    notes = excluded.notes,
                    active = excluded.active,
                    sort_order = excluded.sort_order,
                    updated_at = CURRENT_TIMESTAMP
            ');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO project_btp_codes (project_id, pp_code_id, code, title, notes, active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE title = VALUES(title), notes = VALUES(notes), active = VALUES(active), sort_order = VALUES(sort_order)
            ');
        }
        $stmt->execute([$projectId, $data['pp_code_id'], $data['code'], $data['title'], $data['notes'], $data['active'], $data['sort_order']]);
    }

    public static function saveUts(int $projectId, array $data, int $userId): void
    {
        self::assertPpBelongsToProject($projectId, (int) $data['pp_code_id']);
        if ($data['btp_code_id'] !== null) {
            self::assertBtpBelongsToProject($projectId, (int) $data['btp_code_id'], (int) $data['pp_code_id']);
        }

        $stmt = Database::pdo()->prepare('
            INSERT INTO project_uts_facts (project_id, pp_code_id, btp_code_id, fact_date, amount, description, document_ref, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $projectId,
            $data['pp_code_id'],
            $data['btp_code_id'],
            $data['fact_date'],
            $data['amount'],
            $data['description'],
            $data['document_ref'],
            $userId > 0 ? $userId : null,
        ]);
    }

    public static function btpCodeValue(int $projectId, ?int $btpCodeId): string
    {
        if (!$btpCodeId) {
            return '';
        }
        $stmt = Database::pdo()->prepare('SELECT code FROM project_btp_codes WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$btpCodeId, $projectId]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    public static function resolveTaskSelection(int $projectId, ?int $ppCodeId, ?int $btpCodeId, string $fallbackBtp): array
    {
        $ppCodeId = $ppCodeId && $ppCodeId > 0 ? $ppCodeId : null;
        $btpCodeId = $btpCodeId && $btpCodeId > 0 ? $btpCodeId : null;
        $btpCode = trim($fallbackBtp);

        if ($btpCodeId !== null) {
            $stmt = Database::pdo()->prepare('
                SELECT id, pp_code_id, code
                FROM project_btp_codes
                WHERE id = ? AND project_id = ?
                LIMIT 1
            ');
            $stmt->execute([$btpCodeId, $projectId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new \InvalidArgumentException('Выберите БТП этого проекта.');
            }
            if ($ppCodeId !== null && (int) $row['pp_code_id'] !== $ppCodeId) {
                throw new \InvalidArgumentException('БТП должна относиться к выбранному ПП.');
            }
            $ppCodeId = (int) $row['pp_code_id'];
            $btpCode = (string) $row['code'];
        } elseif ($ppCodeId !== null) {
            self::assertPpBelongsToProject($projectId, $ppCodeId);
        }

        return [
            'pp_code_id' => $ppCodeId,
            'btp_code_id' => $btpCodeId,
            'btp' => $btpCode,
        ];
    }

    private static function ppCodes(PDO $pdo, int $projectId, bool $activeOnly): array
    {
        $where = $activeOnly ? ' AND active = 1' : '';
        $stmt = $pdo->prepare('SELECT * FROM project_pp_codes WHERE project_id = ?' . $where . ' ORDER BY sort_order, code');
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    private static function btpCodes(PDO $pdo, int $projectId, bool $activeOnly): array
    {
        $where = $activeOnly ? ' AND btp.active = 1' : '';
        $stmt = $pdo->prepare('
            SELECT btp.*, pp.code AS pp_code, pp.title AS pp_title
            FROM project_btp_codes btp
            INNER JOIN project_pp_codes pp ON pp.id = btp.pp_code_id
            WHERE btp.project_id = ?' . $where . '
            ORDER BY pp.sort_order, pp.code, btp.sort_order, btp.code
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    private static function utsFacts(PDO $pdo, int $projectId): array
    {
        $stmt = $pdo->prepare('
            SELECT uts.*, pp.code AS pp_code, pp.title AS pp_title, btp.code AS btp_code, btp.title AS btp_title, u.name AS created_by_name
            FROM project_uts_facts uts
            INNER JOIN project_pp_codes pp ON pp.id = uts.pp_code_id
            LEFT JOIN project_btp_codes btp ON btp.id = uts.btp_code_id
            LEFT JOIN users u ON u.id = uts.created_by
            WHERE uts.project_id = ?
            ORDER BY uts.fact_date DESC, uts.id DESC
            LIMIT 200
        ');
        $stmt->execute([$projectId]);

        return $stmt->fetchAll();
    }

    private static function assertPpBelongsToProject(int $projectId, int $ppCodeId): void
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM project_pp_codes WHERE id = ? AND project_id = ?');
        $stmt->execute([$ppCodeId, $projectId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new \InvalidArgumentException('Выберите ПП этого проекта.');
        }
    }

    private static function assertBtpBelongsToProject(int $projectId, int $btpCodeId, int $ppCodeId): void
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM project_btp_codes WHERE id = ? AND project_id = ? AND pp_code_id = ?');
        $stmt->execute([$btpCodeId, $projectId, $ppCodeId]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new \InvalidArgumentException('Выберите БТП из выбранного ПП.');
        }
    }

    private static function decimal(mixed $value): float
    {
        return (float) str_replace(',', '.', trim((string) $value));
    }

    private static function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private static function isSqlite(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
}
