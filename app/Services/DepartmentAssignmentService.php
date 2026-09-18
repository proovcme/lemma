<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class DepartmentAssignmentService
{
    public const DEPARTMENTS = ['ОВ', 'ЭОМ', 'СС', 'ГИП', 'BIM', 'АСУ', 'ВК', 'КР', 'АР'];

    private const SOURCE_PATTERN = 'ОВ|ЭОМ|ССБ?|ГИП|BIM|БИМ|АСУ|ВК|КР|АР|КЖ|КМ';

    private const EXACT_MARKERS = [
        'ГИП' => ['ГИП'],
        'BIM' => ['BIM', 'БИМ'],
        'АСУ' => ['АСУ'],
        'ЭОМ' => ['ЭОМ'],
        'ОВ' => ['ОВ'],
        'СС' => ['ССБ', 'СС', 'СКС'],
        'ВК' => ['ВК'],
        'КР' => ['КР', 'КЖ', 'КМ'],
        'АР' => ['АР', 'АС'],
    ];

    private const KEYWORDS = [
        'ЭОМ' => ['электроснабж', 'электрооборуд'],
        'ОВ' => ['отоплен', 'вентиляц', 'кондиционирован', 'холодоснабж', 'ХОВС'],
        'СС' => ['связи', 'связь', 'КИТСО'],
        'АСУ' => ['автоматизац', 'диспетчеризац'],
        'ВК' => ['водоснабжен', 'водоотведен', 'канализац'],
        'КР' => ['конструктивн', 'железобетон', 'металлоконстр'],
        'АР' => ['архитектурн', 'архитектур'],
    ];

    public static function detectDepartment(string $title): ?string
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        if (preg_match('/(?<![\p{L}\p{N}])Задани[ея]\s+от\s+(' . self::SOURCE_PATTERN . ')(?![\p{L}\p{N}])/iu', $title, $matches)) {
            return self::normalizeDepartment($matches[1]);
        }

        if (preg_match('/(?<![\p{L}\p{N}])(?:в|для)\s+(' . self::SOURCE_PATTERN . ')(?![\p{L}\p{N}])/iu', $title, $matches)) {
            return self::normalizeDepartment($matches[1]);
        }

        foreach (self::EXACT_MARKERS as $department => $markers) {
            foreach ($markers as $marker) {
                if (self::containsMarker($title, $marker)) {
                    return $department;
                }
            }
        }

        foreach (self::KEYWORDS as $department => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_stripos($title, $keyword, 0, 'UTF-8') !== false) {
                    return $department;
                }
            }
        }

        return null;
    }

    public static function departmentHeadMap(PDO $pdo): array
    {
        $map = [];
        try {
            $stmt = $pdo->query("
                SELECT code, head_user_id
                FROM departments
                WHERE head_user_id IS NOT NULL
            ");
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $map[(string) $row['code']] = (int) $row['head_user_id'];
                }
            }
        } catch (\Throwable $e) {
            // Fallback in case table doesn't exist yet
        }

        $fallbackMap = [];
        $placeholders = implode(', ', array_fill(0, count(self::DEPARTMENTS), '?'));
        $fallbackStmt = $pdo->prepare("
            SELECT id, role, department
            FROM users
            WHERE is_active = 1 AND department IN ({$placeholders})
            ORDER BY
                CASE
                    WHEN department = 'ГИП' AND role = 'gip' THEN 0
                    WHEN role = 'department_head' THEN 1
                    WHEN role = 'head' THEN 1
                    WHEN role = 'group_lead' THEN 2
                    WHEN role = 'lead' THEN 2
                    WHEN role = 'chief_specialist' THEN 3
                    WHEN role = 'engineer' THEN 4
                    WHEN role = 'designer' THEN 4
                    ELSE 9
                END,
                id
        ");
        $fallbackStmt->execute(self::DEPARTMENTS);

        foreach ($fallbackStmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
            $department = (string) $user['department'];
            if (!isset($fallbackMap[$department])) {
                $fallbackMap[$department] = (int) $user['id'];
            }
        }

        return array_merge($fallbackMap, $map);
    }

    private static function containsMarker(string $title, string $marker): bool
    {
        $marker = preg_quote($marker, '/');

        return (bool) preg_match('/(?<![\p{L}\p{N}])' . $marker . '(?![\p{L}\p{N}])/iu', $title);
    }

    private static function normalizeDepartment(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');

        return match ($value) {
            'ССБ' => 'СС',
            'БИМ' => 'BIM',
            default => $value,
        };
    }
}
