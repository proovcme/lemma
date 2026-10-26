<?php

declare(strict_types=1);
namespace App\Services;

use PDO;
use InvalidArgumentException;

/** Composition changes retain the section identity, assignments and task links. */
final class ProjectSectionWorkspaceService
{
    public function __construct(private PDO $pdo) {}

    public function removed(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT s.*, st.code AS stage_code FROM project_sections s LEFT JOIN project_stages st ON st.id = s.stage_id WHERE s.project_id = ? AND s.active = 0 ORDER BY s.code, s.id');
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public function catalogOptions(): array
    {
        $catalog = (new ProjectStructureService($this->pdo))->catalog();
        $options = [];
        foreach ([...$catalog['templates']['pp87'], ...$catalog['templates']['rd'], ...$catalog['sections']] as $row) {
            $options[$this->code((string) $row['value'])] = (string) $row['label'];
        }
        ksort($options);
        return $options;
    }

    public function add(int $projectId, ?int $stageId, string $catalogCode, string $code, string $title, bool $saveToCatalog, bool $canManageCatalog): int
    {
        if ($saveToCatalog && !$canManageCatalog) throw new InvalidArgumentException('Пополнять общий справочник может руководитель с правом управления сотрудниками. Создайте раздел только для проекта.');
        $options = $this->catalogOptions();
        if ($catalogCode !== '') {
            $code = $this->code($catalogCode);
            if (!isset($options[$code])) throw new InvalidArgumentException('Раздела нет в справочнике. Выберите другой или создайте свой.');
            $title = $options[$code];
            $saveToCatalog = false;
        }
        $code = $this->code($code);
        $title = trim($title);
        if ($code === '' || $title === '' || mb_strlen($code) > 120 || mb_strlen($title) > 255) throw new InvalidArgumentException('Укажите код до 120 символов и название до 255 символов.');
        if ($saveToCatalog && isset($options[$code])) throw new InvalidArgumentException('Такой код уже есть в справочнике. Добавьте раздел из списка.');
        $this->pdo->beginTransaction();
        try {
            $this->assertProjectActive($projectId);
            $stmt = $this->pdo->prepare('SELECT id, code FROM project_sections WHERE project_id = ? AND COALESCE(stage_id, 0) = (? + 0) AND work_kind = ?');
            $stmt->execute([$projectId, (int) $stageId, 'section']);
            foreach ($stmt->fetchAll() as $row) {
                if ($this->code((string) $row['code']) === $code) throw new InvalidArgumentException('Раздел уже есть в проекте. Если он убран, восстановите его ниже — назначения и история сохранятся.');
            }
            $id = (new ProjectStructureService($this->pdo))->addWorkItem($projectId, $stageId, 'section', $code, $title, $saveToCatalog);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function setActive(int $projectId, int $sectionId, bool $active): void
    {
        $this->assertProjectActive($projectId);
        $stmt = $this->pdo->prepare('SELECT s.id, s.stage_id, st.active AS stage_active FROM project_sections s LEFT JOIN project_stages st ON st.id = s.stage_id WHERE s.project_id = ? AND s.id = ?');
        $stmt->execute([$projectId, $sectionId]);
        $row = $stmt->fetch();
        if (!$row) throw new InvalidArgumentException('Раздел не найден в этом проекте.');
        if ($active && !empty($row['stage_id']) && (int) $row['stage_active'] !== 1) throw new InvalidArgumentException('Сначала восстановите стадию проекта.');
        $stmt = $this->pdo->prepare('UPDATE project_sections SET active = ? WHERE project_id = ? AND id = ?');
        $stmt->execute([(int) $active, $projectId, $sectionId]);
    }

    private function assertProjectActive(int $projectId): void
    {
        $stmt = $this->pdo->prepare('SELECT status FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $status = $stmt->fetchColumn();
        if ($status === false || $status === 'archived') throw new InvalidArgumentException('Состав архивного или недоступного проекта менять нельзя.');
    }

    private function code(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)) ?? '', 'UTF-8');
    }
}
