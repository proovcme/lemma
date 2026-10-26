<?php

declare(strict_types=1);
namespace App\Services;

use PDO;
use InvalidArgumentException;

final class ProjectCompositionTemplateService
{
    public function __construct(private PDO $pdo) {}

    public function forUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, created_at FROM project_composition_templates WHERE owner_user_id = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function save(int $projectId, int $userId, string $name): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Укажите название шаблона до 120 символов.');
        $items = [];
        foreach ((new ProjectStructureService($this->pdo))->structure($projectId) as $group) {
            foreach ($group['sections'] as $row) {
                $items[] = ['code' => $row['code'], 'title' => $row['title'], 'kind' => $row['work_kind'],
                    'stage_code' => $row['stage_code'] ?: '', 'stage_title' => $row['stage_title'] ?: ''];
            }
        }
        if (!$items) throw new InvalidArgumentException('Сначала добавьте в проект хотя бы один раздел.');
        if (count($items) > 500) throw new InvalidArgumentException('В одном шаблоне допускается до 500 разделов и общих активностей.');
        $stmt = $this->pdo->prepare('INSERT INTO project_composition_templates (owner_user_id, name, items_json) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $name, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function preview(int $id, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM project_composition_templates WHERE id = ? AND owner_user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) throw new InvalidArgumentException('Шаблон не найден или недоступен.');
        $row['items'] = json_decode((string) $row['items_json'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    /** Add only selected rows; existing or removed composition is never overwritten. */
    public function apply(int $projectId, int $id, int $userId, array $selected): array
    {
        $template = $this->preview($id, $userId);
        $selected = array_values(array_unique(array_map('strval', $selected)));
        if (!$selected) throw new InvalidArgumentException('Выберите хотя бы один раздел шаблона.');
        foreach ($selected as $index) {
            if (!ctype_digit($index) || !isset($template['items'][(int) $index])) throw new InvalidArgumentException('Состав шаблона изменился. Откройте предварительный просмотр заново.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT status FROM projects WHERE id = ?');
            $stmt->execute([$projectId]);
            $status = $stmt->fetchColumn();
            if ($status === false || $status === 'archived') throw new InvalidArgumentException('Шаблон применяется только к активному проекту.');
            $stmt = $this->pdo->prepare('SELECT * FROM project_stages WHERE project_id = ?');
            $stmt->execute([$projectId]);
            $stages = [];
            foreach ($stmt->fetchAll() as $row) $stages[$this->key($row['code'])] = $row;
            $stmt = $this->pdo->prepare('SELECT * FROM project_sections WHERE project_id = ?');
            $stmt->execute([$projectId]);
            $existing = [];
            foreach ($stmt->fetchAll() as $row) $existing[(int) $row['stage_id'] . ':' . $row['work_kind'] . ':' . $this->key($row['code'])] = true;
            $service = new ProjectStructureService($this->pdo);
            $added = $skipped = 0;
            foreach ($selected as $index) {
                $item = $template['items'][(int) $index];
                $stageId = null;
                if ($item['stage_code'] !== '' && $item['kind'] !== 'activity') {
                    $stageKey = $this->key($item['stage_code']);
                    if (isset($stages[$stageKey]) && !(int) $stages[$stageKey]['active']) throw new InvalidArgumentException('Стадия ' . $item['stage_code'] . ' убрана из проекта. Сначала восстановите её или исключите её разделы из выбора.');
                    if (!isset($stages[$stageKey])) {
                        $stages[$stageKey] = ['id' => $service->addStage($projectId, $item['stage_code'], $item['stage_title'], false), 'active' => 1];
                    }
                    $stageId = (int) $stages[$stageKey]['id'];
                }
                $key = (int) $stageId . ':' . $item['kind'] . ':' . $this->key($item['code']);
                if (isset($existing[$key])) { $skipped++; continue; }
                $service->addWorkItem($projectId, $stageId, $item['kind'], $item['code'], $item['title'], false);
                $existing[$key] = true;
                $added++;
            }
            $this->pdo->commit();
            return ['added' => $added, 'skipped' => $skipped];
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    private function key(string $code): string { return mb_strtoupper(trim($code), 'UTF-8'); }
}
