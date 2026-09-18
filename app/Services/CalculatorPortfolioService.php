<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class CalculatorPortfolioService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(int $userId, array $payload): array
    {
        $snapshotId = trim((string) ($payload['snapshot_id'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));
        $amount = round((float) ($payload['amount_thousand'] ?? 0), 2);
        $area = isset($payload['area_m2']) && $payload['area_m2'] !== '' ? round((float) $payload['area_m2'], 2) : null;
        $startDate = $this->dateOrNull($payload['start_date'] ?? null);
        $finishDate = $this->dateOrNull($payload['finish_date'] ?? null);

        if (!preg_match('/\A[a-zA-Z0-9._:-]{8,64}\z/', $snapshotId)) {
            throw new \InvalidArgumentException('Некорректный идентификатор расчёта.');
        }
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Укажите название расчёта длиной до 255 символов.');
        }
        if (!is_finite($amount) || $amount <= 0 || $amount > 999999999999.99) {
            throw new \InvalidArgumentException('Расчётная сумма должна быть больше нуля.');
        }
        if ($area !== null && (!is_finite($area) || $area <= 0 || $area > 999999999999.99)) {
            throw new \InvalidArgumentException('Площадь должна быть больше нуля.');
        }
        if ($startDate !== null && $finishDate !== null && $finishDate < $startDate) {
            throw new \InvalidArgumentException('Дата окончания не может быть раньше даты начала.');
        }

        $existing = $this->pdo->prepare('SELECT created_by FROM calculator_portfolio_entries WHERE snapshot_id = ?');
        $existing->execute([$snapshotId]);
        $ownerId = $existing->fetchColumn();
        if ($ownerId !== false && (int) $ownerId !== $userId) {
            throw new \RuntimeException('Этот расчёт принадлежит другому пользователю.');
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO calculator_portfolio_entries (snapshot_id, title, amount_thousand, area_m2, start_date, finish_date, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, "expected", ?)
                    ON CONFLICT(snapshot_id) DO UPDATE SET title = excluded.title, amount_thousand = excluded.amount_thousand,
                    area_m2 = excluded.area_m2, start_date = excluded.start_date, finish_date = excluded.finish_date, updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO calculator_portfolio_entries (snapshot_id, title, amount_thousand, area_m2, start_date, finish_date, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, "expected", ?)
                    ON DUPLICATE KEY UPDATE title = VALUES(title), amount_thousand = VALUES(amount_thousand), area_m2 = VALUES(area_m2),
                    start_date = VALUES(start_date), finish_date = VALUES(finish_date), updated_at = CURRENT_TIMESTAMP';
        }
        $this->pdo->prepare($sql)->execute([$snapshotId, $title, $amount, $area, $startDate, $finishDate, $userId]);

        return ['snapshot_id' => $snapshotId, 'saved' => true];
    }

    public function delete(int $userId, string $snapshotId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM calculator_portfolio_entries WHERE snapshot_id = ? AND created_by = ?');
        $stmt->execute([$snapshotId, $userId]);
        return $stmt->rowCount() > 0;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Некорректная дата расчёта.');
        }
        return $value;
    }
}
