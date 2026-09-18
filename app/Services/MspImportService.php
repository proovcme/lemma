<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use SimpleXMLElement;

final class MspImportService
{
    private const MAX_IMPORTED_TASKS = 5000;
    private const MPP_CONVERT_TIMEOUT_SECONDS = 120;

    public function importFile(int $projectId, string $path, string $originalName, int $authorId): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'mpp') {
            $xmlPath = $this->convertMppToXml($path);
            try {
                $result = $this->import($projectId, $xmlPath, $authorId);
                $result['source'] = 'mpp';

                return $result;
            } finally {
                @unlink($xmlPath);
            }
        }

        $result = $this->import($projectId, $path, $authorId);
        $result['source'] = 'xml';

        return $result;
    }

    public function import(int $projectId, string $xmlPath, int $authorId): array
    {
        $previousUseErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlPath, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (!$xml instanceof SimpleXMLElement) {
            $message = $xmlErrors ? trim($xmlErrors[0]->message) : 'неизвестная ошибка XML';
            throw new \RuntimeException('Не удалось прочитать XML MS Project: ' . $message . '.');
        }

        $pdo = Database::pdo();
        $departmentHeads = DepartmentAssignmentService::departmentHeadMap($pdo);
        $tasks = [];
        foreach ($xml->Tasks->Task ?? [] as $task) {
            $uid = trim((string) $task->UID);
            $name = trim((string) $task->Name);
            $outline = (int) $task->OutlineLevel;
            if ($uid === '' || $name === '' || $outline < 1) {
                continue;
            }
            if (count($tasks) >= self::MAX_IMPORTED_TASKS) {
                throw new \RuntimeException('XML MS Project содержит слишком много задач.');
            }

            $tasks[] = [
                'uid' => $uid,
                'msp_id' => (int) $task->ID,
                'name' => $name,
                'start' => substr((string) $task->Start, 0, 10) ?: null,
                'finish' => substr((string) $task->Finish, 0, 10) ?: null,
                'outline' => $outline,
                'work' => $this->parseWorkHours((string) $task->Work),
                'depends_on' => $this->predecessors($task),
                'department' => DepartmentAssignmentService::detectDepartment($name),
            ];
        }

        // Предзагрузка существующих задач для исключения SELECT в цикле
        $existingTasks = [];
        $stmt = $pdo->prepare('SELECT id, msp_task_uid, assignee_id, discipline FROM tasks WHERE project_id = ?');
        $stmt->execute([$projectId]);
        foreach ($stmt->fetchAll() as $row) {
            $uid = trim((string) ($row['msp_task_uid'] ?? ''));
            if ($uid !== '') {
                $existingTasks[$uid] = $row;
            }
        }

        // Предзагрузка существующих смарт-задач для исключения SELECT в цикле
        $existingSmart = [];
        $stmt = $pdo->prepare('SELECT task_id FROM task_smart WHERE task_id IN (SELECT id FROM tasks WHERE project_id = ?)');
        $stmt->execute([$projectId]);
        foreach ($stmt->fetchAll() as $row) {
            $existingSmart[(int) $row['task_id']] = true;
        }

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Подготовка выражений ОДИН раз вне цикла
            $updateStmt = $pdo->prepare('
                UPDATE tasks
                SET title = ?, parent_id = ?, assignee_id = ?, discipline = ?, date_start = ?, date_end = ?, planned_hours = ?, msp_task_id = ?, msp_outline_level = ?
                WHERE id = ?
            ');
            $insertStmt = $pdo->prepare('
                INSERT INTO tasks (title, project_id, parent_id, assignee_id, author_id, discipline, status, date_start, date_end, date_end_original, planned_hours, msp_task_uid, msp_task_id, msp_outline_level)
                VALUES (?, ?, ?, ?, ?, ?, "new", ?, ?, ?, ?, ?, ?, ?)
            ');
            $smartUpdateStmt = $pdo->prepare('UPDATE task_smart SET what = ?, when_due = ?, depends_on = ? WHERE task_id = ?');
            $smartInsertStmt = $pdo->prepare('INSERT INTO task_smart (task_id, what, when_due, why, depends_on) VALUES (?, ?, ?, NULL, ?)');

            $stack = [];
            $departmentStack = [];
            $result = ['created' => 0, 'updated' => 0, 'items' => []];

            foreach ($tasks as $item) {
                $parentId = $stack[$item['outline'] - 1] ?? null;
                $department = $item['department'] ?: ($departmentStack[$item['outline'] - 1] ?? null);
                $mappedAssigneeId = $department ? ($departmentHeads[$department] ?? null) : null;

                $existingTask = $existingTasks[$item['uid']] ?? null;
                $taskId = $existingTask ? (int) $existingTask['id'] : null;
                $assigneeId = $mappedAssigneeId ?: ($existingTask['assignee_id'] ?? null);
                $discipline = $department ?: ($existingTask['discipline'] ?? null);
                $plannedHours = $item['work'] !== null ? $item['work'] : working_hours($item['start'], $item['finish']);

                if ($taskId) {
                    $updateStmt->execute([$item['name'], $parentId, $assigneeId, $discipline, $item['start'], $item['finish'], $plannedHours, $item['msp_id'], $item['outline'], $taskId]);
                    $result['updated']++;
                } else {
                    $insertStmt->execute([$item['name'], $projectId, $parentId, $assigneeId, $authorId, $discipline, $item['start'], $item['finish'], $item['finish'], $plannedHours, $item['uid'], $item['msp_id'], $item['outline']]);
                    $taskId = (int) $pdo->lastInsertId();
                    $result['created']++;
                }

                if (isset($existingSmart[$taskId])) {
                    $smartUpdateStmt->execute([$item['name'], $item['finish'] ?: 'По графику MS Project', $item['depends_on'], $taskId]);
                } else {
                    $smartInsertStmt->execute([$taskId, $item['name'], $item['finish'] ?: 'По графику MS Project', $item['depends_on']]);
                    $existingSmart[$taskId] = true;
                }

                $stack[$item['outline']] = (int) $taskId;
                $departmentStack[$item['outline']] = $department;
                foreach (array_keys($stack) as $outline) {
                    if ($outline > $item['outline']) {
                        unset($stack[$outline]);
                    }
                }
                foreach (array_keys($departmentStack) as $outline) {
                    if ($outline > $item['outline']) {
                        unset($departmentStack[$outline]);
                    }
                }
                $result['items'][] = ['uid' => $item['uid'], 'title' => $item['name'], 'task_id' => (int) $taskId, 'department' => $department, 'assignee_id' => $assigneeId];
            }

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    private function parseWorkHours(string $work): ?float
    {
        $work = trim($work);
        if ($work === '') {
            return null;
        }

        if (preg_match('/^P(?:(\d+(?:\.\d+)?)W)?(?:(\d+(?:\.\d+)?)D)?(?:T(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?)?$/i', $work, $matches)) {
            $hours = ((float) ($matches[1] ?? 0)) * 40
                + ((float) ($matches[2] ?? 0)) * 8
                + (float) ($matches[3] ?? 0)
                + ((float) ($matches[4] ?? 0)) / 60
                + ((float) ($matches[5] ?? 0)) / 3600;

            return $hours > 0 ? $hours : null;
        }

        return null;
    }

    private function predecessors(SimpleXMLElement $task): string
    {
        $uids = [];
        foreach ($task->PredecessorLink ?? [] as $link) {
            $uid = trim((string) $link->PredecessorUID);
            if ($uid !== '') {
                $uids[] = $uid;
            }
        }

        return implode(',', array_unique($uids));
    }

    private function convertMppToXml(string $mppPath): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException('Импорт MPP доступен только на Windows-сервере с установленным Microsoft Project.');
        }
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('Импорт MPP требует доступной функции proc_open для запуска Microsoft Project.');
        }

        $tempBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dpr_msp_' . bin2hex(random_bytes(8));
        $localMpp = $tempBase . '.mpp';
        $xmlPath = $tempBase . '.xml';
        $scriptPath = $tempBase . '.vbs';

        if (!copy($mppPath, $localMpp)) {
            throw new \RuntimeException('Не удалось подготовить MPP-файл для конвертации.');
        }

        $script = implode("\r\n", [
            'On Error Resume Next',
            'Dim svc, before, process, app',
            'Set svc = GetObject("winmgmts:\\\\.\\root\\cimv2")',
            'Set before = CreateObject("Scripting.Dictionary")',
            "For Each process In svc.ExecQuery(\"Select ProcessId From Win32_Process Where Name='WINPROJ.EXE'\")",
            '  before.Add CStr(process.ProcessId), True',
            'Next',
            'Set app = CreateObject("MSProject.Application")',
            'If Err.Number <> 0 Then WScript.Echo "ERR create: " & Err.Description: WScript.Quit 1',
            'Err.Clear',
            'app.Visible = False',
            'app.DisplayAlerts = False',
            'app.FileOpenEx "' . $this->vbScriptString($localMpp) . '", True',
            'If Err.Number <> 0 Then WScript.Echo "ERR open: " & Err.Description: WScript.Quit 2',
            'Err.Clear',
            'app.FileSaveAs "' . $this->vbScriptString($xmlPath) . '", , , , , , , , , "MSProject.XML"',
            'If Err.Number <> 0 Then WScript.Echo "ERR save: " & Err.Description: WScript.Quit 3',
            'Set app = Nothing',
            "For Each process In svc.ExecQuery(\"Select ProcessId From Win32_Process Where Name='WINPROJ.EXE'\")",
            '  If Not before.Exists(CStr(process.ProcessId)) Then process.Terminate',
            'Next',
            'WScript.Echo "XML=' . $this->vbScriptString($xmlPath) . '"',
        ]);
        file_put_contents($scriptPath, $script);

        try {
            $command = 'cscript.exe //NoLogo ' . escapeshellarg($scriptPath);
            $this->runProcess($command, self::MPP_CONVERT_TIMEOUT_SECONDS);
            if (!is_file($xmlPath) || filesize($xmlPath) <= 0) {
                throw new \RuntimeException('Microsoft Project не создал XML-файл.');
            }

            return $xmlPath;
        } catch (\RuntimeException $e) {
            @unlink($xmlPath);
            throw new \RuntimeException('Не удалось конвертировать MPP в XML: ' . $e->getMessage(), previous: $e);
        } finally {
            @unlink($localMpp);
            @unlink($scriptPath);
        }
    }

    private function runProcess(string $command, int $timeoutSeconds): string
    {
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('не удалось запустить PowerShell.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $error = '';
        $startedAt = time();

        while (true) {
            $status = proc_get_status($process);
            $output .= stream_get_contents($pipes[1]) ?: '';
            $error .= stream_get_contents($pipes[2]) ?: '';

            if (!$status['running']) {
                break;
            }
            if (time() - $startedAt > $timeoutSeconds) {
                proc_terminate($process);
                throw new \RuntimeException('конвертация MPP превысила таймаут ' . $timeoutSeconds . ' сек.');
            }
            usleep(100000);
        }

        $output .= stream_get_contents($pipes[1]) ?: '';
        $error .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $message = trim($error) !== '' ? trim($error) : trim($output);
            throw new \RuntimeException($message !== '' ? $message : 'PowerShell завершился с кодом ' . $exitCode . '.');
        }

        return $output;
    }

    private function vbScriptString(string $value): string
    {
        return str_replace('"', '""', $value);
    }
}
