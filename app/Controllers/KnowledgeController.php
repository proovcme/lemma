<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\KnowledgeBaseService;

final class KnowledgeController extends BaseController
{
    public function index(?int $id = null): void
    {
        $user = require_auth();
        $canEdit = KnowledgeBaseService::canEdit($user) && !app_is_demo_mode();
        $folder = $id ? KnowledgeBaseService::folder($this->db(), $id) : null;
        if ($id && !$folder) {
            $this->notFound();
        }
        $query = trim((string) ($_GET['q'] ?? ''));
        $folders = KnowledgeBaseService::folders($this->db());
        $documents = KnowledgeBaseService::documents($this->db(), $id, $query, $canEdit);

        $actions = [];
        if ($canEdit) {
            $actions[] = ['label' => '+ Папка', 'href' => '/knowledge/folders/new' . ($id ? '?parent_id=' . $id : ''), 'class' => 'btn-outline'];
            $actions[] = ['label' => '+ Документ', 'href' => '/knowledge/documents/new' . ($id ? '?folder_id=' . $id : ''), 'class' => 'btn-red'];
        }

        $this->render('knowledge/index', [
            'title' => 'База знаний',
            'subtitle' => 'Рабочие инструкции, регламенты и важные документы.',
            'headerActions' => $actions,
            'user' => $user,
            'canEdit' => $canEdit,
            'folders' => $folders,
            'folderOptions' => KnowledgeBaseService::folderOptions($folders),
            'currentFolder' => $folder,
            'breadcrumbs' => KnowledgeBaseService::breadcrumbs($this->db(), $id),
            'documents' => $documents,
            'pinnedDocuments' => $id === null && $query === '' ? KnowledgeBaseService::pinned($this->db(), $canEdit) : [],
            'query' => $query,
        ]);
    }

    public function showDocument(int $id): void
    {
        $user = require_auth();
        $canEdit = KnowledgeBaseService::canEdit($user) && !app_is_demo_mode();
        $document = KnowledgeBaseService::document($this->db(), $id, $canEdit);
        if (!$document) {
            $this->notFound();
        }
        $actions = [];
        if ($canEdit) {
            $actions[] = ['label' => 'Редактировать', 'href' => '/knowledge/documents/' . $id . '/edit', 'class' => 'btn-red'];
        }
        $this->render('knowledge/show', [
            'title' => (string) $document['title'],
            'subtitle' => (string) ($document['summary'] ?? ''),
            'headerActions' => $actions,
            'document' => $document,
            'canEdit' => $canEdit,
            'breadcrumbs' => KnowledgeBaseService::breadcrumbs($this->db(), (int) ($document['folder_id'] ?? 0)),
        ]);
    }

    public function newDocument(): void
    {
        $user = $this->requireEditor();
        $folders = KnowledgeBaseService::folders($this->db());
        $this->render('knowledge/editor', [
            'title' => 'Новый документ',
            'subtitle' => 'Сначала сохраните черновик, затем опубликуйте готовый текст.',
            'document' => null,
            'folderOptions' => KnowledgeBaseService::folderOptions($folders),
            'selectedFolderId' => (int) ($_GET['folder_id'] ?? 0),
            'revisions' => [],
            'user' => $user,
        ]);
    }

    public function storeDocument(): void
    {
        $user = $this->requireEditor();
        try {
            $id = KnowledgeBaseService::createDocument($this->db(), $_POST, (int) $user['id']);
            flash('success', 'Черновик создан.');
            redirect('/knowledge/documents/' . $id . '/edit');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/knowledge/documents/new');
        }
    }

    public function editDocument(int $id): void
    {
        $this->requireEditor();
        $document = KnowledgeBaseService::document($this->db(), $id, true);
        if (!$document) {
            $this->notFound();
        }
        $folders = KnowledgeBaseService::folders($this->db());
        $this->render('knowledge/editor', [
            'title' => 'Редактирование документа',
            'subtitle' => 'Автосохранение меняет только черновик. Читатели увидят текст после публикации.',
            'document' => $document,
            'folderOptions' => KnowledgeBaseService::folderOptions($folders),
            'selectedFolderId' => (int) ($document['draft_folder_id'] ?? $document['folder_id'] ?? 0),
            'revisions' => KnowledgeBaseService::revisions($this->db(), $id),
        ]);
    }

    public function saveDraft(int $id): void
    {
        $user = $this->requireEditor();
        try {
            KnowledgeBaseService::saveDraft($this->db(), $id, $_POST, (int) $user['id']);
            flash('success', 'Черновик сохранён.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/knowledge/documents/' . $id . '/edit');
    }

    public function autosave(int $id): void
    {
        $user = $this->requireEditor();
        try {
            KnowledgeBaseService::saveDraft($this->db(), $id, $_POST, (int) $user['id']);
            json_response(['ok' => true, 'saved_at' => date('H:i')]);
        } catch (\Throwable $e) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function publish(int $id): void
    {
        $user = $this->requireEditor();
        try {
            $version = KnowledgeBaseService::publish($this->db(), $id, $_POST, (int) $user['id']);
            flash('success', 'Опубликована версия ' . $version . '.');
            redirect('/knowledge/documents/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/knowledge/documents/' . $id . '/edit');
        }
    }

    public function archive(int $id): void
    {
        $user = $this->requireEditor();
        try {
            KnowledgeBaseService::archive($this->db(), $id, (int) $user['id']);
            flash('success', 'Документ перенесён в архив.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/knowledge');
    }

    public function restoreRevision(int $id, int $revisionId): void
    {
        $user = $this->requireEditor();
        try {
            KnowledgeBaseService::restoreRevision($this->db(), $id, $revisionId, (int) $user['id']);
            flash('success', 'Версия восстановлена в черновик. Проверьте её и опубликуйте.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/knowledge/documents/' . $id . '/edit');
    }

    public function newFolder(): void
    {
        $this->requireEditor();
        $folders = KnowledgeBaseService::folders($this->db());
        $this->render('knowledge/folder_form', [
            'title' => 'Новая папка',
            'subtitle' => 'Папки помогают собрать документы в понятную рабочую структуру.',
            'folder' => null,
            'folderOptions' => KnowledgeBaseService::folderOptions($folders),
            'selectedParentId' => (int) ($_GET['parent_id'] ?? 0),
        ]);
    }

    public function storeFolder(): void
    {
        $user = $this->requireEditor();
        try {
            $id = KnowledgeBaseService::createFolder($this->db(), $_POST, (int) $user['id']);
            flash('success', 'Папка создана.');
            redirect('/knowledge/folders/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/knowledge/folders/new');
        }
    }

    public function editFolder(int $id): void
    {
        $this->requireEditor();
        $folder = KnowledgeBaseService::folder($this->db(), $id);
        if (!$folder) {
            $this->notFound();
        }
        $folders = KnowledgeBaseService::folders($this->db());
        $this->render('knowledge/folder_form', [
            'title' => 'Настройка папки',
            'subtitle' => 'Можно переименовать папку, изменить порядок или переместить её.',
            'folder' => $folder,
            'folderOptions' => KnowledgeBaseService::folderOptions($folders, $id),
            'selectedParentId' => (int) ($folder['parent_id'] ?? 0),
        ]);
    }

    public function updateFolder(int $id): void
    {
        $user = $this->requireEditor();
        try {
            KnowledgeBaseService::updateFolder($this->db(), $id, $_POST, (int) $user['id']);
            flash('success', 'Папка обновлена.');
            redirect('/knowledge/folders/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/knowledge/folders/' . $id . '/edit');
        }
    }

    private function requireEditor(): array
    {
        $user = require_auth();
        if (app_is_demo_mode() || !KnowledgeBaseService::canEdit($user)) {
            $this->forbidden();
        }
        return $user;
    }

    private function notFound(): never
    {
        http_response_code(404);
        view('layouts/error', ['title' => 'Не найдено', 'message' => 'Документ или папка не найдены.']);
        exit;
    }

    private function forbidden(): never
    {
        http_response_code(403);
        view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Редактировать базу знаний может только администратор.']);
        exit;
    }
}
