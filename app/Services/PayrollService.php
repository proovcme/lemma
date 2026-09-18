<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Модуль «Свод трудозатрат и ФОТ» (Фаза 1: справочники + расчёт ставки).
 * Расчётные методы чистые (тестируемые); доступ к данным — через статические хелперы.
 */
final class PayrollService
{
    /** Норма часов в месяце по умолчанию (можно переопределить при генерации свода). */
    public const DEFAULT_NORM_HOURS = 176.0;

    /**
     * Ставка чел-часа сотрудника в юрлице.
     * Если задано rate_override (>0) — используется оно. Иначе — сумма окладных
     * компонент, делённая на норму часов месяца.
     *
     * @param array<string,mixed> $assignment строка employee_legal_entities
     */
    public static function hourlyRate(array $assignment, float $normHours = self::DEFAULT_NORM_HOURS): float
    {
        $override = $assignment['rate_override'] ?? null;
        if ($override !== null && $override !== '' && (float) $override > 0) {
            return round((float) $override, 2);
        }
        $oklad = (float) ($assignment['base_oklad'] ?? 0)
            + (float) ($assignment['base_nadbavka'] ?? 0)
            + (float) ($assignment['premium'] ?? 0)
            + (float) ($assignment['project_nadbavka'] ?? 0);
        if ($normHours <= 0) {
            return 0.0;
        }
        return round($oklad / $normHours, 2);
    }

    /** @return array<int,array<string,mixed>> */
    public static function legalEntities(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM legal_entities';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, code';
        return Database::pdo()->query($sql)->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public static function articles(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM writeoff_articles';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, code';
        return Database::pdo()->query($sql)->fetchAll();
    }

    /**
     * Назначения сотрудник×юрлицо с именами сотрудника и юрлица.
     * @return array<int,array<string,mixed>>
     */
    public static function assignments(): array
    {
        return Database::pdo()->query(
            'SELECT ele.*, u.name AS user_name, u.tab_number, u.department,
                    le.code AS entity_code, le.name AS entity_name
             FROM employee_legal_entities ele
             JOIN users u ON u.id = ele.user_id
             JOIN legal_entities le ON le.id = ele.legal_entity_id
             ORDER BY u.name, le.sort_order, le.code'
        )->fetchAll();
    }
}
