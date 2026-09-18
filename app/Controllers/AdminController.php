<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DictionaryService;
use App\Services\DatabaseExportService;
use App\Services\IncidentLogService;
use App\Services\MailSettingsService;
use App\Services\NotificationOutboxService;
use App\Services\NotificationTemplateService;
use App\Services\OrgService;
use App\Services\PasswordService;
use App\Services\PermissionService;
use App\Services\PositionService;
use App\Services\RoleService;
use App\Services\SbcCatalogService;

final class AdminController extends BaseController
{
    private const SBC_IMPORT_MAX_BYTES = 32 * 1024 * 1024;
    private const USER_CSV_IMPORT_MAX_BYTES = 2 * 1024 * 1024;
    private const USER_CSV_IMPORT_MAX_ROWS = 5000;

    /** @var array<string, bool>|null */
    private ?array $userColumnMap = null;

    public function users(): void
    {
        $user = $this->requireUsersAdmin();
        $generatedCredentials = is_array($_SESSION['generated_credentials'] ?? null) ? $_SESSION['generated_credentials'] : [];
        $importSkipped = is_array($_SESSION['import_skipped'] ?? null) ? $_SESSION['import_skipped'] : [];
        $this->render('admin/users', [
            'title' => 'Пользователи',
            'users' => $this->usersWithRates(),
            'positions' => OrgService::positions($this->db()),
            'managers' => $this->activeUsersForSelect(),
            'departments' => $this->db()->query('SELECT * FROM departments ORDER BY name')->fetchAll(),
            'generatedCredentials' => $generatedCredentials,
            'generatedPassword' => $_SESSION['generated_password'] ?? ($generatedCredentials[0]['password'] ?? null),
            'importSkipped' => $importSkipped,
            'admin' => $user,
            'canManageRates' => PermissionService::canManageEmployeeRates($user),
        ]);
        unset($_SESSION['generated_credentials']);
        unset($_SESSION['generated_password']);
        unset($_SESSION['import_skipped']);
    }

    public function access(): void
    {
        $this->requireAdmin();
        $this->render('admin/access', [
            'title' => 'Доступы',
            'roleModel' => RoleService::all(),
            'capabilityLabels' => RoleService::capabilityLabels(),
            'syncGroups' => RoleService::accessSyncGroups(),
        ]);
    }

    public function updateAccess(): void
    {
        $user = $this->requireAdmin();
        $matrix = RoleService::normalizeAccessMatrix((array) ($_POST['access'] ?? []));
        $driver = (string) config('db.connection');
        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare('
                    INSERT INTO role_access_permissions (role, capability, enabled, updated_by, updated_at)
                    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(role, capability) DO UPDATE SET
                        enabled = excluded.enabled,
                        updated_by = excluded.updated_by,
                        updated_at = CURRENT_TIMESTAMP
                ');
                $positionStmt = $pdo->prepare('SELECT id FROM positions WHERE role_key = ? AND is_system = 1 LIMIT 1');
                $positionAccessStmt = $pdo->prepare('INSERT INTO position_access_permissions (position_id, capability, enabled, updated_by, updated_at)
                    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(position_id, capability) DO UPDATE SET enabled = excluded.enabled, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP');
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO role_access_permissions (role, capability, enabled, updated_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        enabled = VALUES(enabled),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ');
                $positionStmt = $pdo->prepare('SELECT id FROM positions WHERE role_key = ? AND is_system = 1 LIMIT 1');
                $positionAccessStmt = $pdo->prepare('INSERT INTO position_access_permissions (position_id, capability, enabled, updated_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP');
            }

            foreach ($matrix as $role => $capabilities) {
                $positionStmt->execute([$role]);
                $positionId = $positionStmt->fetchColumn();
                foreach ($capabilities as $capability => $enabled) {
                    $stmt->execute([$role, $capability, $enabled ? 1 : 0, (int) $user['id']]);
                    if ($positionId) {
                        $positionAccessStmt->execute([(int) $positionId, $capability, $enabled ? 1 : 0, (int) $user['id']]);
                    }
                }
            }
            $pdo->commit();
            RoleService::resetCache();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        flash('success', 'Матрица доступов сохранена.');
        redirect('/admin/access');
    }

    public function storeUser(): void
    {
        $admin = $this->requireUsersAdmin();
        $wantsJson = $this->wantsJsonResponse();
        $password = PasswordService::generate(12);
        $positionId = $this->nullableInt($_POST['position_id'] ?? null);
        $positionRole = PositionService::roleKeyForPosition($positionId, $this->db());
        $payload = [
            'tab_number' => trim((string) ($_POST['tab_number'] ?? '')),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'role' => $positionRole ?? $this->postedRole(),
            'department' => trim((string) ($_POST['department'] ?? '')),
            'position_id' => $positionId,
            'manager_id' => $this->nullableInt($_POST['manager_id'] ?? null),
        ];

        if ($payload['tab_number'] === '' || $payload['name'] === '' || $payload['email'] === '') {
            $this->failStoreUser('Заполните табельный номер, ФИО и email.');
        }
        if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            $this->failStoreUser('Укажите корректный email.');
        }
        if ($this->userFieldExists('tab_number', $payload['tab_number'])) {
            $this->failStoreUser('Такой табельный номер уже используется.');
        }
        if ($this->userFieldExists('email', $payload['email'])) {
            $this->failStoreUser('Такой email уже используется.');
        }

        $hasCredentialTracking = $this->hasUserCredentialTracking();
        $stmt = $hasCredentialTracking
            ? $this->db()->prepare('
                INSERT INTO users (tab_number, name, email, password_hash, role, department, position_id, manager_id, must_change_password, password_reset_at, password_reset_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP, ?)
            ')
            : $this->db()->prepare('
                INSERT INTO users (tab_number, name, email, password_hash, role, department, position_id, manager_id, must_change_password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ');
        try {
            $params = [
                $payload['tab_number'],
                $payload['name'],
                $payload['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $payload['role'],
                $payload['department'],
                $payload['position_id'],
                $payload['manager_id'],
            ];
            if ($hasCredentialTracking) {
                $params[] = (int) $admin['id'];
            }
            $stmt->execute($params);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $this->failStoreUser('Пользователь с таким табельным номером или email уже существует.');
            }
            throw $e;
        }
        $payload['id'] = (int) $this->db()->lastInsertId();
        $payload['is_active'] = 1;
        $payload['must_change_password'] = 1;
        $payload['last_login'] = null;
        $payload['password_reset_at'] = $hasCredentialTracking ? date('Y-m-d H:i:s') : null;
        $payload['password_reset_by'] = $hasCredentialTracking ? (int) $admin['id'] : null;
        $payload['password_reset_by_name'] = $hasCredentialTracking ? (string) $admin['name'] : '';
        $payload['credentials_mail_marked_sent_at'] = null;
        $payload['credentials_mail_marked_sent_by_name'] = '';

        if ($wantsJson) {
            json_response([
                'ok' => true,
                'message' => 'Пользователь создан.',
                'user' => $this->userPayload($payload),
                'credential' => $this->credentialPayload($payload, $password),
            ]);
        }

        $_SESSION['generated_password'] = $password;
        $_SESSION['generated_credentials'] = [$this->credentialPayload($payload, $password)];
        flash('success', 'Пользователь создан. Пароль показан один раз, письмо можно скопировать или отправить через SMTP-очередь.');
        redirect($this->safeReturnTo('/admin/users'));
    }

    public function updateRole(int $id): void
    {
        $this->requireUsersAdmin();
        $role = $this->postedRole();
        if (RoleService::normalize($role) !== RoleService::DIRECTOR && $this->wouldRemoveLastActiveDirector([$id])) {
            flash('error', 'Нельзя снять должность с последнего активного директора.');
            redirect('/admin/users');
        }
        $positionStmt = $this->db()->prepare('SELECT id FROM positions WHERE role_key = ? AND is_active = 1 LIMIT 1');
        $positionStmt->execute([$role]);
        $positionId = $positionStmt->fetchColumn();
        $this->db()->prepare('UPDATE users SET role = ?, position_id = COALESCE(?, position_id) WHERE id = ?')->execute([$role, $positionId ?: null, $id]);
        if ($this->wantsJsonResponse()) {
            json_response([
                'ok' => true,
                'message' => 'Роль обновлена.',
                'role' => $role,
                'role_label' => role_label($role),
            ]);
        }
        flash('success', 'Роль обновлена.');
        redirect('/admin/users');
    }

    public function updateUserIdentity(int $id): void
    {
        $this->requireUsersAdmin();

        $tabNumber = trim((string) ($_POST['tab_number'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));

        if ($tabNumber === '' || $name === '' || $email === '') {
            $this->failUserIdentity('Заполните табельный номер, ФИО и email.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->failUserIdentity('Укажите корректный email.');
        }
        if ($this->userFieldExistsExcept('tab_number', $tabNumber, $id)) {
            $this->failUserIdentity('Такой табельный номер уже используется.');
        }
        if ($this->userFieldExistsExcept('email', $email, $id)) {
            $this->failUserIdentity('Такой email уже используется.');
        }

        $stmt = $this->db()->prepare('UPDATE users SET tab_number = ?, name = ?, email = ? WHERE id = ?');
        $stmt->execute([$tabNumber, $name, $email, $id]);

        if ($this->wantsJsonResponse()) {
            $user = $this->loadUserForPayload($id);
            json_response([
                'ok' => true,
                'message' => 'Данные пользователя обновлены.',
                'user' => $user ? $this->userPayload($user) : [
                    'id' => $id,
                    'tab_number' => $tabNumber,
                    'name' => $name,
                    'email' => $email,
                ],
            ]);
        }

        flash('success', 'Данные пользователя обновлены.');
        redirect('/admin/users');
    }

    public function resetPassword(int $id): void
    {
        $admin = $this->requireUsersAdmin();
        $stmt = $this->db()->prepare('SELECT id, tab_number, name, email, role, department, is_active, must_change_password, last_login FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            flash('error', 'Пользователь не найден.');
            redirect('/admin/users');
        }

        $password = PasswordService::generate(12);
        if ($this->hasUserCredentialTracking()) {
            $this->db()->prepare('
                UPDATE users
                SET password_hash = ?,
                    must_change_password = 1,
                    password_reset_at = CURRENT_TIMESTAMP,
                    password_reset_by = ?,
                    credentials_mail_marked_sent_at = NULL,
                    credentials_mail_marked_sent_by = NULL
                WHERE id = ?
            ')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $admin['id'], $id]);
        } else {
            $this->db()->prepare('
                UPDATE users
                SET password_hash = ?,
                    must_change_password = 1
                WHERE id = ?
            ')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        }
        $targetUser = array_merge($this->loadUserForPayload($id) ?? $targetUser, [
            'password_reset_at' => date('Y-m-d H:i:s'),
            'password_reset_by' => (int) $admin['id'],
            'password_reset_by_name' => (string) $admin['name'],
            'credentials_mail_marked_sent_at' => null,
            'credentials_mail_marked_sent_by_name' => '',
        ]);
        if ($this->wantsJsonResponse()) {
            $targetUser['must_change_password'] = 1;
            json_response([
                'ok' => true,
                'message' => 'Пароль сброшен.',
                'credential' => $this->credentialPayload($targetUser, $password),
                'user' => $this->userPayload($targetUser),
            ]);
        }
        $_SESSION['generated_password'] = $password;
        $_SESSION['generated_credentials'] = [$this->credentialPayload($targetUser, $password)];
        flash('success', 'Пароль сброшен и показан один раз, письмо можно скопировать или отправить через SMTP-очередь.');
        redirect('/admin/users');
    }

    public function resetPasswords(): void
    {
        $admin = $this->requireUsersAdmin();
        $ids = $this->postedBulkUserIds();
        if ($ids === []) {
            flash('error', 'Выберите хотя бы одного пользователя.');
            redirect('/admin/users');
        }

        $action = (string) ($_POST['bulk_action'] ?? 'reset_passwords');
        if ($action === 'role') {
            $role = $this->postedRoleValue((string) ($_POST['bulk_role'] ?? ''));
            if (RoleService::normalize($role) !== RoleService::DIRECTOR && $this->wouldRemoveLastActiveDirector($ids)) {
                flash('error', 'Нельзя снять должность с последнего активного директора.');
                redirect('/admin/users');
            }
            $positionStmt = $this->db()->prepare('SELECT id FROM positions WHERE role_key = ? AND is_active = 1 LIMIT 1');
            $positionStmt->execute([$role]);
            $positionId = $positionStmt->fetchColumn();
            $this->updateBulkUsers('role = ?, position_id = COALESCE(?, position_id)', [$role, $positionId ?: null], $ids);
            flash('success', 'Роль назначена выбранным пользователям: ' . count($ids) . '.');
            redirect('/admin/users');
        }

        if ($action === 'department') {
            $department = trim((string) ($_POST['bulk_department'] ?? ''));
            if ($department === '') {
                $department = null;
            } elseif (!$this->departmentExists($department)) {
                flash('error', 'Отдел не найден.');
                redirect('/admin/users');
            }
            $this->updateBulkUsers('department = ?, group_id = NULL', [$department], $ids);
            flash('success', 'Отдел назначен выбранным пользователям: ' . count($ids) . '.');
            redirect('/admin/users');
        }

        if ($action === 'active') {
            $active = (int) ($_POST['bulk_active'] ?? 0) === 1 ? 1 : 0;
            if ($active === 0) {
                $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== (int) $admin['id']));
                if ($ids === []) {
                    flash('error', 'Нельзя уволить текущего администратора.');
                    redirect('/admin/users');
                }
                if ($this->wouldRemoveLastActiveDirector($ids)) {
                    flash('error', 'Нельзя уволить последнего активного директора.');
                    redirect('/admin/users');
                }
            }
            $this->updateBulkUsers('is_active = ?', [$active], $ids);
            flash('success', ($active === 1 ? 'Пользователи возвращены: ' : 'Пользователи уволены: ') . count($ids) . '.');
            redirect('/admin/users');
        }

        if ($action !== 'reset_passwords') {
            flash('error', 'Неизвестное пакетное действие.');
            redirect('/admin/users');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare("
            SELECT id, tab_number, name, email, role, department, is_active, must_change_password, last_login
            FROM users
            WHERE is_active = 1 AND id IN ({$placeholders})
            ORDER BY department, name
        ");
        $stmt->execute($ids);
        $users = $stmt->fetchAll();
        if ($users === []) {
            flash('error', 'Активные пользователи не найдены.');
            redirect('/admin/users');
        }

        $credentials = [];
        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $hasCredentialTracking = $this->hasUserCredentialTracking();
            $update = $hasCredentialTracking
                ? $pdo->prepare('
                    UPDATE users
                    SET password_hash = ?,
                        must_change_password = 1,
                        password_reset_at = CURRENT_TIMESTAMP,
                        password_reset_by = ?,
                        credentials_mail_marked_sent_at = NULL,
                        credentials_mail_marked_sent_by = NULL
                    WHERE id = ?
                ')
                : $pdo->prepare('
                    UPDATE users
                    SET password_hash = ?,
                        must_change_password = 1
                    WHERE id = ?
                ');
            foreach ($users as $targetUser) {
                $password = PasswordService::generate(12);
                $hasCredentialTracking
                    ? $update->execute([password_hash($password, PASSWORD_DEFAULT), (int) $admin['id'], $targetUser['id']])
                    : $update->execute([password_hash($password, PASSWORD_DEFAULT), $targetUser['id']]);
                $targetUser['password_reset_at'] = $hasCredentialTracking ? date('Y-m-d H:i:s') : null;
                $targetUser['password_reset_by'] = $hasCredentialTracking ? (int) $admin['id'] : null;
                $targetUser['password_reset_by_name'] = $hasCredentialTracking ? (string) $admin['name'] : '';
                $targetUser['credentials_mail_marked_sent_at'] = null;
                $targetUser['credentials_mail_marked_sent_by_name'] = '';
                $credentials[] = $this->credentialPayload($targetUser, $password);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $_SESSION['generated_credentials'] = $credentials;
        $_SESSION['generated_password'] = $credentials[0]['password'] ?? null;
        flash('success', 'Временные пароли сгенерированы: ' . count($credentials) . '. Письма можно скопировать или отправить через SMTP-очередь.');
        redirect('/admin/users');
    }

    public function markCredentialsMailSent(int $id): void
    {
        $admin = $this->requireUsersAdmin();
        if (!$this->hasUserCredentialTracking()) {
            $message = 'Учёт отправки писем недоступен: миграция учётных полей пользователей ещё не применена.';
            if ($this->wantsJsonResponse()) {
                json_response(['ok' => false, 'message' => $message], 422);
            }
            flash('error', $message);
            redirect('/admin/users');
        }

        $stmt = $this->db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            $message = 'Пользователь не найден.';
            if ($this->wantsJsonResponse()) {
                json_response(['ok' => false, 'message' => $message], 404);
            }
            flash('error', $message);
            redirect('/admin/users');
        }

        $this->db()->prepare('
            UPDATE users
            SET credentials_mail_marked_sent_at = CURRENT_TIMESTAMP,
                credentials_mail_marked_sent_by = ?
            WHERE id = ?
        ')->execute([(int) $admin['id'], $id]);

        $user = $this->loadUserForPayload($id) ?? ['id' => $id];
        $user['credentials_mail_marked_sent_at'] = date('Y-m-d H:i:s');
        $user['credentials_mail_marked_sent_by'] = (int) $admin['id'];
        $user['credentials_mail_marked_sent_by_name'] = (string) $admin['name'];

        if ($this->wantsJsonResponse()) {
            json_response([
                'ok' => true,
                'message' => 'Отправка письма отмечена.',
                'user' => $this->userPayload($user),
            ]);
        }

        flash('success', 'Отправка письма отмечена.');
        redirect('/admin/users');
    }

    public function fields(): void
    {
        $this->requireAdmin();
        $this->render('admin/fields', [
            'title' => 'Кастомные поля',
            'fields' => $this->db()->query('
                SELECT cf.*, p.code AS project_code
                FROM custom_fields cf
                LEFT JOIN projects p ON p.id = cf.project_id
                ORDER BY cf.project_id IS NOT NULL, cf.sort_order, cf.label
            ')->fetchAll(),
            'projects' => $this->db()->query('SELECT id, code, title FROM projects ORDER BY code')->fetchAll(),
        ]);
    }

    public function storeField(): void
    {
        $this->requireAdmin();
        $type = (string) ($_POST['type'] ?? 'text');
        if (!in_array($type, ['text', 'select', 'date', 'number', 'user', 'bool', 'link', 'links'], true)) {
            $type = 'text';
        }

        $options = trim((string) ($_POST['options'] ?? ''));
        $stmt = $this->db()->prepare('
            INSERT INTO custom_fields (name, label, type, project_id, options, required, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            trim((string) $_POST['name']),
            trim((string) $_POST['label']),
            $type,
            ($_POST['project_id'] ?? '') ?: null,
            $options !== '' ? json_encode(array_map('trim', explode(',', $options)), JSON_UNESCAPED_UNICODE) : null,
            isset($_POST['required']) ? 1 : 0,
            (int) ($_POST['sort_order'] ?? 0),
        ]);
        flash('success', 'Поле добавлено.');
        redirect('/admin/fields');
    }

    public function notifications(): void
    {
        $this->requireAdmin();
        $this->render('admin/notifications', [
            'title'     => 'Шаблоны писем',
            'templates' => NotificationTemplateService::all(),
            'mailSettings' => MailSettingsService::current(),
            'outboxCounters' => MailSettingsService::outboxCounters(),
        ]);
    }

    public function notificationSettingsSave(): void
    {
        $user = $this->requireAdmin();
        try {
            MailSettingsService::save($_POST, (int) $user['id']);
            flash('success', 'SMTP-настройки сохранены.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/notifications');
    }

    public function notificationQueueSend(): void
    {
        $this->requireAdmin();
        $limit = max(1, min(100, (int) ($_POST['limit'] ?? 20)));
        $result = NotificationOutboxService::processPending($limit);
        flash('success', 'Очередь обработана: отправлено ' . (int) $result['sent'] . ', ошибок ' . (int) $result['failed'] . '.');
        redirect('/admin/notifications');
    }

    public function notificationEdit(string $type): void
    {
        $this->requireAdmin();
        $template = NotificationTemplateService::load($type);
        if ($template['subject'] === '' && $template['body'] === '') {
            http_response_code(404);
            $this->render('errors/404', ['title' => 'Не найдено']);
            return;
        }
        $this->render('admin/notification-edit', [
            'title'     => 'Редактировать: ' . $template['label'],
            'type'      => $type,
            'template'  => $template,
            'variables' => NotificationTemplateService::VARIABLES[$type] ?? [],
        ]);
    }

    public function notificationSave(string $type): void
    {
        $this->requireAdmin();
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body    = trim((string) ($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            flash('error', 'Тема и тело письма не могут быть пустыми.');
            redirect('/admin/notifications/' . rawurlencode($type) . '/edit');
            return;
        }

        NotificationTemplateService::save($type, $subject, $body);
        flash('success', 'Шаблон сохранён.');
        redirect('/admin/notifications');
    }

    public function integrations(): void
    {
        $this->requireAdmin();
        $sbcService = new SbcCatalogService();
        $this->render('admin/integrations', [
            'title' => 'ТИМ viewer',
            'sbcResult' => $_SESSION['sbc_import_result'] ?? null,
            'sbcStats' => $sbcService->stats($this->db()),
            'sbcRecent' => $sbcService->recent($this->db()),
        ]);
        unset($_SESSION['sbc_import_result']);
    }

    public function databaseExport(): void
    {
        $this->requireAdmin();
        $this->render('admin/database-export', ['title' => 'Выгрузка базы']);
    }

    public function databaseExportDownload(): void
    {
        $user = $this->requireAdmin();
        // A complete dump plus accumulated journals can legitimately take
        // longer than the normal 60-second web request limit. The archive is
        // still built on local storage and streamed; it is never held in RAM.
        @set_time_limit(0);
        ignore_user_abort(true);
        try {
            $result = (new DatabaseExportService())->export($this->db());
            \App\Services\AuditService::record('database_export', [
                'operator_id' => (int) ($user['id'] ?? 0),
                'driver' => (string) ($result['manifest']['database_driver'] ?? ''),
                'bytes' => (int) ($result['manifest']['bytes'] ?? 0),
            ]);
            $path = (string) $result['path'];
            header('Content-Type: application/zip');
            header('Content-Length: ' . (string) filesize($path));
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Cache-Control: no-store, private');
            readfile($path);
            @unlink($path);
        } catch (\Throwable $e) {
            $incident = IncidentLogService::report($e, ['action' => 'database_export']);
            flash('error', IncidentLogService::userMessage($incident, 'выгрузить базу в ZIP'));
            redirect('/admin/database-export');
        }
    }

    public function dictionaries(): void
    {
        $this->requireAdmin();
        $this->render('admin/dictionaries', [
            'title' => 'Справочники',
            'items' => DictionaryService::all(),
            'projects' => $this->db()->query('SELECT id, code, title FROM projects ORDER BY code')->fetchAll(),
            'kinds' => DictionaryService::kinds(),
            'sections' => DictionaryService::globalSections(),
        ]);
    }

    public function counterparties(): void
    {
        $this->requireAdmin();
        $this->render('admin/counterparties', [
            'title' => 'Контрагенты',
            'items' => $this->db()->query('SELECT * FROM counterparties ORDER BY company, role, representative, id')->fetchAll(),
        ]);
    }

    public function storeCounterparty(): void
    {
        $this->requireAdmin();
        $company = trim((string) ($_POST['company'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? ''));
        $representative = trim((string) ($_POST['representative'] ?? ''));
        $contact = trim((string) ($_POST['contact'] ?? ''));
        if ($company === '') {
            flash('error', 'Название фирмы обязательно.');
            redirect('/admin/counterparties');
        }

        $exists = $this->db()->prepare('
            SELECT COUNT(*)
            FROM counterparties
            WHERE company = ? AND COALESCE(role, "") = ? AND COALESCE(representative, "") = ?
        ');
        $exists->execute([$company, $role, $representative]);
        if ((int) $exists->fetchColumn() > 0) {
            flash('error', 'Такой контрагент уже есть в справочнике.');
            redirect('/admin/counterparties');
        }

        $stmt = $this->db()->prepare('
            INSERT INTO counterparties (company, role, representative, contact)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$company, $role, $representative, $contact]);

        flash('success', 'Контрагент добавлен.');
        redirect('/admin/counterparties');
    }

    public function exchangeTemplates(): void
    {
        $this->requireAdmin();
        $sets = $this->db()->query('
            SELECT s.*,
                   COALESCE(ic.items_count, 0) AS items_count
            FROM exchange_template_sets s
            LEFT JOIN (
                SELECT template_set_id, COUNT(*) AS items_count
                FROM exchange_template_items
                GROUP BY template_set_id
            ) ic ON ic.template_set_id = s.id
            ORDER BY s.sort_order, s.name, s.id
        ')->fetchAll();

        $items = $this->db()->query('
            SELECT *
            FROM exchange_template_items
            ORDER BY template_set_id, sort_order, id
        ')->fetchAll();
        $itemsBySet = [];
        foreach ($items as $item) {
            $itemsBySet[(int) $item['template_set_id']][] = $item;
        }

        $this->render('admin/exchange_templates', [
            'title' => 'Матрицы обмена заданиями',
            'sets' => $sets,
            'itemsBySet' => $itemsBySet,
        ]);
    }

    public function storeExchangeTemplate(): void
    {
        $this->requireAdmin();
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = trim((string) ($_POST['code'] ?? ''));
        $scopeSection = trim((string) ($_POST['scope_section'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 100);
        if ($name === '') {
            flash('error', 'Название матрицы обязательно.');
            redirect('/admin/exchange-templates');
        }

        if ($code === '') {
            $code = 'matrix_' . substr(sha1($name . '|' . microtime(true)), 0, 12);
        }
        $rawCode = $code;
        $code = $this->normalizeTemplateCode($code, 'matrix');
        if ($code === 'matrix' && $rawCode !== 'matrix') {
            $code = 'matrix_' . substr(sha1($name . '|' . $rawCode . '|' . microtime(true)), 0, 12);
        }

        $stmt = $this->db()->prepare('
            INSERT INTO exchange_template_sets (code, name, scope_section, description, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ');
        try {
            $stmt->execute([$code, $name, $scopeSection, $description, $sortOrder]);
        } catch (\PDOException $e) {
            flash('error', 'Матрица с таким кодом уже есть.');
            redirect('/admin/exchange-templates');
        }

        flash('success', 'Матрица обмена создана.');
        redirect('/admin/exchange-templates');
    }

    public function storeExchangeTemplateItem(): void
    {
        $this->requireAdmin();
        $setId = (int) ($_POST['template_set_id'] ?? 0);
        $assignment = trim((string) ($_POST['assignment'] ?? ''));
        if ($setId <= 0 || $assignment === '') {
            flash('error', 'Матрица и содержание задания обязательны.');
            redirect('/admin/exchange-templates');
        }

        $set = $this->db()->prepare('SELECT id FROM exchange_template_sets WHERE id = ?');
        $set->execute([$setId]);
        if (!$set->fetchColumn()) {
            flash('error', 'Матрица не найдена.');
            redirect('/admin/exchange-templates');
        }

        $direction = $this->normalizeExchangeTemplateDirection((string) ($_POST['direction'] ?? 'incoming'));
        $status = $this->normalizeExchangeTemplateStatus((string) ($_POST['default_status'] ?? 'pending'));
        $fromSection = trim((string) ($_POST['from_section'] ?? ''));
        $toSection = trim((string) ($_POST['to_section'] ?? ''));
        $comments = trim((string) ($_POST['comments'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 100);
        $rawItemCode = trim((string) ($_POST['item_code'] ?? ''));
        $itemCode = $this->normalizeTemplateCode($rawItemCode, 'item');
        if ($itemCode === 'item') {
            $itemCode = 'item_' . substr(sha1($setId . '|' . $direction . '|' . $fromSection . '|' . $toSection . '|' . $assignment . '|' . $rawItemCode . '|' . microtime(true)), 0, 12);
        }

        $stmt = $this->db()->prepare('
            INSERT INTO exchange_template_items (template_set_id, item_code, direction, from_section, to_section, assignment, default_status, comments, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        try {
            $stmt->execute([$setId, $itemCode, $direction, $fromSection, $toSection, $assignment, $status, $comments, $sortOrder]);
        } catch (\PDOException $e) {
            flash('error', 'Пункт с таким кодом уже есть в матрице.');
            redirect('/admin/exchange-templates');
        }

        flash('success', 'Пункт матрицы добавлен.');
        redirect('/admin/exchange-templates');
    }

    public function storeDictionary(): void
    {
        $this->requireAdmin();
        $payload = DictionaryService::payload($_POST);
        if ($payload['value'] === '') {
            flash('error', 'Значение справочника обязательно.');
            redirect('/admin/dictionaries');
        }

        DictionaryService::save($payload);
        flash('success', 'Запись справочника сохранена.');
        redirect('/admin/dictionaries');
    }

    private function normalizeTemplateCode(string $value, string $fallback): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^a-z0-9_-]+/u', '_', $value) ?? '';
        $value = trim($value, '_-');

        return $value !== '' ? substr($value, 0, 80) : $fallback;
    }

    private function normalizeExchangeTemplateDirection(string $direction): string
    {
        return in_array($direction, ['incoming', 'outgoing'], true) ? $direction : 'incoming';
    }

    private function normalizeExchangeTemplateStatus(string $status): string
    {
        return in_array($status, ['pending', 'in_progress', 'done', 'blocked'], true) ? $status : 'pending';
    }

    public function sbcImport(): void
    {
        $this->requireAdmin();

        try {
            $file = $_FILES['sbc_file'] ?? null;
            $sourcePath = $this->validatedUploadPath(
                $file,
                ['csv', 'xlsx', 'xls'],
                [
                    'text/csv',
                    'text/x-csv',
                    'text/plain',
                    'application/csv',
                    'application/vnd.ms-excel',
                    'application/vnd.ms-office',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip',
                    'application/octet-stream',
                ],
                self::SBC_IMPORT_MAX_BYTES,
                'Справочник СБЦ'
            );

            $_SESSION['sbc_import_result'] = (new SbcCatalogService())->import($this->db(), $sourcePath, (string) ($file['name'] ?? ''));
            flash('success', 'Справочник СБЦ импортирован.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            flash('error', $e->getMessage());
        }

        redirect('/admin/integrations');
    }

    public function sbcSeedBuiltin(): void
    {
        $user = $this->requireAdmin();

        try {
            $_SESSION['sbc_import_result'] = (new SbcCatalogService())->importBundled($this->db(), (int) $user['id']);
            flash('success', 'Встроенный offline-справочник СБЦ загружен.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            flash('error', $e->getMessage());
        }

        redirect('/admin/integrations');
    }

    public function sbcTemplate(): void
    {
        $this->requireAdmin();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sbc_catalog_template.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, SbcCatalogService::TEMPLATE_COLUMNS, ';', '"', '\\');
        fputcsv($out, [
            '',
            'СБЦП-2001',
            'Справочник базовых цен на проектные работы',
            '2020',
            '1',
            '1.1',
            'Разработка проектной документации раздела',
            'раздел',
            '100.00',
            'база 2001',
            '',
            'БЦ x Кст x Ксл x Индекс',
            'Коэффициенты принять по заданию на проектирование',
            'лист 1',
            'Позиция принята по СБЦП-2001, табл. 1, п. 1.1; показатель - раздел.',
        ], ';', '"', '\\');
        fclose($out);
        exit;
    }

    public function passwordGenerator(): void
    {
        $this->requireAdmin();
        $length = (int) ($_GET['length'] ?? 12);
        $special = isset($_GET['special']);
        $this->render('admin/password-gen', [
            'title' => 'Генератор паролей',
            'password' => PasswordService::generate($length, $special),
            'length' => max(8, min(20, $length)),
            'special' => $special,
        ]);
    }

    private function requireAdmin(): array
    {
        $user = require_auth();
        if (!PermissionService::canManageSettings($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Настройки доступны только ролям с правом управления настройками.']);
            exit;
        }

        return $user;
    }

    private function requireUsersAdmin(): array
    {
        $user = require_auth();
        if (!PermissionService::canManageUsers($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Пользователи доступны только ролям с правом управления пользователями.']);
            exit;
        }

        return $user;
    }

    private function credentialPayload(array $user, string $password): array
    {
        $payload = [
            'id' => (int) ($user['id'] ?? 0),
            'tab_number' => (string) ($user['tab_number'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'role_label' => role_label((string) ($user['role'] ?? '')),
            'department' => (string) ($user['department'] ?? ''),
            'password' => $password,
        ];
        $mail = NotificationTemplateService::render('credentials_mail', [
            '{user_name}' => $payload['name'],
            '{user_email}' => $payload['email'],
            '{password}' => $password,
            '{app_url}' => rtrim((string) config('app.url'), '/'),
        ]);
        $payload['mail_subject'] = $mail['subject'];
        $payload['mail_body'] = $mail['body'];

        return $payload;
    }

    public function importUsersForm(): void
    {
        $this->requireUsersAdmin();
        $this->render('admin/users-import', [
            'title' => 'Импорт пользователей из CSV',
            'departments' => $this->db()->query('SELECT code, name FROM departments ORDER BY code')->fetchAll(),
            'roles' => array_keys(\App\Services\RoleService::all()),
        ]);
    }

    public function importUsers(): void
    {
        $this->requireUsersAdmin();

        try {
            $csvPath = $this->validatedUploadPath(
                $_FILES['csv_file'] ?? null,
                ['csv'],
                ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'text/comma-separated-values'],
                self::USER_CSV_IMPORT_MAX_BYTES,
                'CSV пользователей'
            );
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/admin/users/import');
        }

        $defaultRole       = $this->postedRole();
        $defaultDepartment = trim((string) ($_POST['department'] ?? ''));
        $skipHeader        = isset($_POST['skip_header']);

        // Читаем CSV
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            flash('error', 'Не удалось прочитать файл.');
            redirect('/admin/users/import');
        }

        // Определяем разделитель (запятая или точка с запятой — Excel по умолчанию использует ;)
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = substr_count((string) $firstLine, ';') >= substr_count((string) $firstLine, ',') ? ';' : ',';

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (count($rows) >= self::USER_CSV_IMPORT_MAX_ROWS) {
                fclose($handle);
                flash('error', 'CSV содержит больше ' . self::USER_CSV_IMPORT_MAX_ROWS . ' строк. Разбейте импорт на несколько файлов.');
                redirect('/admin/users/import');
            }
            $rows[] = $row;
        }
        fclose($handle);

        if ($skipHeader && count($rows) > 0) {
            array_shift($rows);
        }

        if (empty($rows)) {
            flash('error', 'CSV-файл пуст или не содержит строк данных.');
            redirect('/admin/users/import');
        }

        // Генерируем следующий табельный номер
        if ((string) config('db.connection') === 'sqlite') {
            $allTabs = $this->db()->query("SELECT tab_number FROM users")->fetchAll(\PDO::FETCH_COLUMN);
            $maxTab = 1000;
            foreach ($allTabs as $t) {
                if (ctype_digit((string) $t) && (int) $t > $maxTab) {
                    $maxTab = (int) $t;
                }
            }
        } else {
            $maxTab = (int) $this->db()->query(
                "SELECT COALESCE(MAX(CAST(tab_number AS UNSIGNED)), 1000) FROM users WHERE tab_number REGEXP '^[0-9]+$'"
            )->fetchColumn();
        }

        $insert = $this->db()->prepare('
            INSERT INTO users (tab_number, name, email, password_hash, role, department, must_change_password, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1, 1)
        ');
        $checkEmail = $this->db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');

        $credentials = [];
        $skipped     = [];
        $nextTab     = $maxTab + 1;

        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $lineNum => $row) {
                // Ожидаем: ФИО, email  (возможно email, ФИО — определяем по наличию @)
                $col0 = trim((string) ($row[0] ?? ''));
                $col1 = trim((string) ($row[1] ?? ''));

                if ($col0 === '' && $col1 === '') {
                    continue; // пустая строка
                }

                // Определяем где ФИО а где email
                if (str_contains($col0, '@')) {
                    $email = $col0;
                    $name  = $col1;
                } elseif (str_contains($col1, '@')) {
                    $name  = $col0;
                    $email = $col1;
                } else {
                    $skipped[] = ['line' => $lineNum + 1, 'data' => $col0 . ' | ' . $col1, 'reason' => 'email не найден'];
                    continue;
                }

                $name  = trim($name);
                $email = strtolower(trim($email));

                if ($name === '') {
                    $skipped[] = ['line' => $lineNum + 1, 'data' => $email, 'reason' => 'имя пустое'];
                    continue;
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped[] = ['line' => $lineNum + 1, 'data' => $email, 'reason' => 'некорректный email'];
                    continue;
                }

                // Проверяем дубликат
                $checkEmail->execute([$email]);
                if ($checkEmail->fetchColumn()) {
                    $skipped[] = ['line' => $lineNum + 1, 'data' => $email, 'reason' => 'уже существует'];
                    continue;
                }

                $tabNumber = (string) $nextTab;
                $password  = PasswordService::generate(12);

                $insert->execute([
                    $tabNumber,
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $defaultRole,
                    $defaultDepartment,
                ]);

                $credentials[] = [
                    'tab_number' => $tabNumber,
                    'name'       => $name,
                    'email'      => $email,
                    'role'       => $defaultRole,
                    'department' => $defaultDepartment,
                    'password'   => $password,
                ];

                $nextTab++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $incidentId = IncidentLogService::report($e, ['operation' => 'users_csv_import']);
            flash('error', IncidentLogService::userMessage($incidentId, 'создать пользователей из CSV'));
            redirect('/admin/users/import');
        }

        if (empty($credentials)) {
            flash('error', 'Ни один пользователь не был создан. ' . (empty($skipped) ? 'Файл пуст.' : 'Причины: ' . implode('; ', array_column($skipped, 'reason'))));
            redirect('/admin/users/import');
        }

        $_SESSION['generated_credentials'] = $credentials;
        $_SESSION['import_skipped']         = $skipped;

        $msg = 'Создано пользователей: ' . count($credentials) . '.';
        if (!empty($skipped)) {
            $msg .= ' Пропущено строк: ' . count($skipped) . '.';
        }
        flash('success', $msg);
        redirect('/admin/users');
    }

    private function postedRole(): string
    {
        return $this->postedRoleValue((string) ($_POST['role'] ?? RoleService::DESIGNER));
    }

    private function postedRoleValue(string $role): string
    {
        if (!RoleService::exists($role)) {
            flash('error', 'Неизвестная роль.');
            redirect('/admin/users');
        }

        return RoleService::normalize($role);
    }

    /**
     * @return list<int>
     */
    private function postedBulkUserIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['user_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param list<mixed> $params
     * @param list<int> $ids
     */
    private function updateBulkUsers(string $setSql, array $params, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare("UPDATE users SET {$setSql} WHERE id IN ({$placeholders})");
        $stmt->execute(array_merge($params, $ids));
    }

    /** @param list<int> $ids */
    private function wouldRemoveLastActiveDirector(array $ids): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return false;
        }
        $activeDirectorIds = [];
        foreach ($this->db()->query('SELECT id, role FROM users WHERE is_active = 1')->fetchAll() as $candidate) {
            if (RoleService::normalize((string) ($candidate['role'] ?? '')) === RoleService::DIRECTOR) {
                $activeDirectorIds[] = (int) $candidate['id'];
            }
        }
        if ($activeDirectorIds === []) {
            return false;
        }
        return count(array_diff($activeDirectorIds, $ids)) === 0;
    }

    public function departments(): void
    {
        $admin = $this->requireUsersAdmin();
        $departments = $this->db()->query('SELECT * FROM departments ORDER BY code')->fetchAll();
        $users = $this->db()->query('SELECT * FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();

        $usersMap = [];
        foreach ($users as $u) {
            $usersMap[(int) $u['id']] = $u;
        }

        $this->render('admin/departments', [
            'title' => 'Отделы',
            'departments' => $departments,
            'users' => $users,
            'usersMap' => $usersMap,
            'admin' => $admin,
        ]);
    }

    public function storeDepartment(): void
    {
        $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/departments');

        $code = mb_strtoupper(trim((string) ($_POST['code'] ?? '')), 'UTF-8');
        $name = trim((string) ($_POST['name'] ?? ''));
        $headUserId = ($_POST['head_user_id'] ?? '') !== '' ? (int) $_POST['head_user_id'] : null;

        if ($code === '' || $name === '') {
            flash('error', 'Код и название отдела обязательны.');
            redirect($returnTo);
        }

        // Validate code pattern (letters, numbers, hyphens, cyrillic allowed)
        if (!preg_match('/^[A-Z0-9А-ЯЁ\-]+$/ui', $code)) {
            flash('error', 'Код отдела может содержать только буквы, цифры и дефис.');
            redirect($returnTo);
        }

        $pdo = $this->db();
        // Check duplicate code
        $stmt = $pdo->prepare('SELECT id FROM departments WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn()) {
            flash('error', "Отдел с кодом '{$code}' уже существует.");
            redirect($returnTo);
        }

        $stmt = $pdo->prepare('
            INSERT INTO departments (code, name, head_user_id)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([$code, $name, $headUserId]);

        flash('success', 'Отдел успешно создан.');
        redirect($returnTo);
    }

    public function updateDepartment(int $id): void
    {
        $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/departments');

        $name = trim((string) ($_POST['name'] ?? ''));
        $headUserId = $this->nullableInt($_POST['head_user_id'] ?? null);
        if ($name === '') {
            flash('error', 'Название отдела обязательно.');
            redirect($returnTo);
        }
        if ($headUserId !== null && !$this->existsById('users', $headUserId)) {
            flash('error', 'Руководитель отдела не найден.');
            redirect($returnTo);
        }

        $stmt = $this->db()->prepare('UPDATE departments SET name = ?, head_user_id = ? WHERE id = ?');
        $stmt->execute([$name, $headUserId, $id]);

        flash('success', 'Отдел обновлён.');
        redirect($returnTo);
    }

    public function deleteDepartment(int $id): void
    {
        $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/departments');

        $pdo = $this->db();

        // Find department code to clear users
        $stmt = $pdo->prepare('SELECT code FROM departments WHERE id = ?');
        $stmt->execute([$id]);
        $code = $stmt->fetchColumn();

        if (!$code) {
            flash('error', 'Отдел не найден.');
            redirect($returnTo);
        }

        $pdo->beginTransaction();
        try {
            // Set department to null or empty for users in this department
            $stmt = $pdo->prepare('UPDATE users SET department = NULL WHERE department = ?');
            $stmt->execute([$code]);

            // Delete department
            $stmt = $pdo->prepare('DELETE FROM departments WHERE id = ?');
            $stmt->execute([$id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        flash('success', "Отдел '{$code}' успешно удален.");
        redirect($returnTo);
    }

    public function storeDepartmentGroup(): void
    {
        $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/org-structure#org-groups');
        $department = trim((string) ($_POST['department_code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $leadUserId = $this->nullableInt($_POST['lead_user_id'] ?? null);
        $sortOrder = (int) ($_POST['sort_order'] ?? 100);

        if ($department === '' || $name === '') {
            flash('error', 'Укажите отдел и название группы.');
            redirect($returnTo);
        }
        if (!$this->departmentExists($department)) {
            flash('error', 'Отдел не найден.');
            redirect($returnTo);
        }
        if ($leadUserId !== null && !$this->existsById('users', $leadUserId)) {
            flash('error', 'Руководитель группы не найден.');
            redirect($returnTo);
        }

        $driver = (string) config('db.connection');
        if ($driver === 'sqlite') {
            $stmt = $this->db()->prepare('
                INSERT INTO department_groups (department_code, name, lead_user_id, sort_order)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(department_code, name) DO UPDATE SET
                    lead_user_id = excluded.lead_user_id,
                    sort_order = excluded.sort_order,
                    updated_at = CURRENT_TIMESTAMP
            ');
        } else {
            $stmt = $this->db()->prepare('
                INSERT INTO department_groups (department_code, name, lead_user_id, sort_order)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    lead_user_id = VALUES(lead_user_id),
                    sort_order = VALUES(sort_order),
                    updated_at = CURRENT_TIMESTAMP
            ');
        }
        $stmt->execute([$department, $name, $leadUserId, $sortOrder]);

        flash('success', 'Группа сохранена.');
        redirect($returnTo);
    }

    public function updateDepartmentGroup(int $id): void
    {
        $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/org-structure#org-groups');
        $department = trim((string) ($_POST['department_code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $leadUserId = $this->nullableInt($_POST['lead_user_id'] ?? null);
        $sortOrder = (int) ($_POST['sort_order'] ?? 100);

        if ($department === '' || $name === '') {
            flash('error', 'Укажите отдел и название группы.');
            redirect($returnTo);
        }
        if (!$this->departmentExists($department)) {
            flash('error', 'Отдел не найден.');
            redirect($returnTo);
        }
        if ($leadUserId !== null && !$this->existsById('users', $leadUserId)) {
            flash('error', 'Руководитель группы не найден.');
            redirect($returnTo);
        }
        if (!$this->departmentGroupExists($id)) {
            flash('error', 'Группа не найдена.');
            redirect($returnTo);
        }

        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE department_groups SET department_code = ?, name = ?, lead_user_id = ?, sort_order = ? WHERE id = ?')
                ->execute([$department, $name, $leadUserId, $sortOrder, $id]);
            $pdo->prepare('UPDATE users SET department = ? WHERE group_id = ?')->execute([$department, $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        flash('success', 'Группа обновлена.');
        redirect($returnTo);
    }

    public function deleteDepartmentGroup(int $id): void
    {
        $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/org-structure#org-groups');

        $pdo = $this->db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET group_id = NULL WHERE group_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM department_groups WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        flash('success', 'Группа удалена.');
        redirect($returnTo);
    }

    public function updateUserDepartment(int $id): void
    {
        $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/users');

        $department = trim((string) ($_POST['department'] ?? ''));
        if ($department === '') {
            $department = null;
        }

        $pdo = $this->db();
        $stmt = $pdo->prepare('UPDATE users SET department = ?, group_id = NULL WHERE id = ?');
        $stmt->execute([$department, $id]);

        if ($this->wantsJsonResponse()) {
            json_response([
                'ok' => true,
                'message' => 'Отдел пользователя обновлен.',
                'department' => $department,
            ]);
        }

        flash('success', 'Отдел пользователя обновлен.');
        redirect($returnTo);
    }

    public function updateUserOrg(int $id): void
    {
        $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/users');
        $positionId = $this->nullableInt($_POST['position_id'] ?? null);
        $managerId = $this->nullableInt($_POST['manager_id'] ?? null);
        if ($managerId === $id) {
            $message = 'Пользователь не может быть руководителем самому себе.';
            if ($this->wantsJsonResponse()) {
                json_response(['ok' => false, 'message' => $message], 422);
            }
            flash('error', $message);
            redirect($returnTo);
        }

        if ($positionId !== null && !$this->existsById('positions', $positionId)) {
            flash('error', 'Должность не найдена.');
            redirect($returnTo);
        }
        if ($managerId !== null && !$this->existsById('users', $managerId)) {
            flash('error', 'Руководитель не найден.');
            redirect($returnTo);
        }
        if ($managerId !== null && $this->managerAssignmentCreatesCycle($id, $managerId)) {
            $message = 'Такое назначение создаст цикл в оргструктуре.';
            if ($this->wantsJsonResponse()) {
                json_response(['ok' => false, 'message' => $message], 422);
            }
            flash('error', $message);
            redirect($returnTo);
        }

        $roleKey = PositionService::roleKeyForPosition($positionId, $this->db());
        if ($positionId !== null && $roleKey === null) {
            flash('error', 'Для должности не настроен уровень полномочий.');
            redirect($returnTo);
        }
        if ($roleKey !== null && RoleService::normalize($roleKey) !== RoleService::DIRECTOR && $this->wouldRemoveLastActiveDirector([$id])) {
            flash('error', 'Нельзя снять должность с последнего активного директора.');
            redirect($returnTo);
        }
        $this->db()->prepare('UPDATE users SET position_id = ?, role = COALESCE(?, role), manager_id = ? WHERE id = ?')
            ->execute([$positionId, $roleKey, $managerId, $id]);

        if ($this->wantsJsonResponse()) {
            json_response([
                'ok' => true,
                'message' => 'Оргданные пользователя обновлены.',
                'position_id' => $positionId,
                'manager_id' => $managerId,
            ]);
        }

        flash('success', 'Оргданные пользователя обновлены.');
        redirect($returnTo);
    }

    public function updateUserOrgPlacement(int $id): void
    {
        $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/org-structure#org-people');
        $department = trim((string) ($_POST['department'] ?? ''));
        $department = $department !== '' ? $department : null;
        $groupId = $this->nullableInt($_POST['group_id'] ?? null);
        $positionId = $this->nullableInt($_POST['position_id'] ?? null);
        $managerId = $this->nullableInt($_POST['manager_id'] ?? null);

        if ($managerId === $id) {
            flash('error', 'Пользователь не может быть руководителем самому себе.');
            redirect($returnTo);
        }
        if ($department !== null && !$this->departmentExists($department)) {
            flash('error', 'Отдел не найден.');
            redirect($returnTo);
        }
        if ($groupId !== null) {
            $group = $this->departmentGroup($groupId);
            if (!$group) {
                flash('error', 'Группа не найдена.');
                redirect($returnTo);
            }
            $groupDepartment = (string) ($group['department_code'] ?? '');
            if ($department === null) {
                $department = $groupDepartment;
            } elseif ($department !== $groupDepartment) {
                flash('error', 'Группа относится к другому отделу.');
                redirect($returnTo);
            }
        }
        if ($positionId !== null && !$this->existsById('positions', $positionId)) {
            flash('error', 'Должность не найдена.');
            redirect($returnTo);
        }
        if ($managerId !== null && !$this->existsById('users', $managerId)) {
            flash('error', 'Руководитель не найден.');
            redirect($returnTo);
        }
        if ($managerId !== null && $this->managerAssignmentCreatesCycle($id, $managerId)) {
            flash('error', 'Такое назначение создаст цикл в оргструктуре.');
            redirect($returnTo);
        }

        $roleKey = PositionService::roleKeyForPosition($positionId, $this->db());
        if ($positionId !== null && $roleKey === null) {
            flash('error', 'Для должности не настроен уровень полномочий.');
            redirect($returnTo);
        }
        if ($roleKey !== null && RoleService::normalize($roleKey) !== RoleService::DIRECTOR && $this->wouldRemoveLastActiveDirector([$id])) {
            flash('error', 'Нельзя снять должность с последнего активного директора.');
            redirect($returnTo);
        }
        $stmt = $this->db()->prepare('UPDATE users SET department = ?, group_id = ?, position_id = ?, role = COALESCE(?, role), manager_id = ? WHERE id = ?');
        $stmt->execute([$department, $groupId, $positionId, $roleKey, $managerId, $id]);

        flash('success', 'Место сотрудника в структуре обновлено.');
        redirect($returnTo);
    }

    public function updateUserRate(int $id): void
    {
        $user = $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/users');
        if (!PermissionService::canManageEmployeeRates($user)) {
            http_response_code(403);
            view('layouts/error', ['title' => 'Нет доступа', 'message' => 'Ставки доступны только директору департамента.']);
            exit;
        }

        $rate = $this->decimal($_POST['hourly_rate'] ?? 0);
        if ($id <= 0 || $rate < 0) {
            $message = 'Укажите сотрудника и ставку.';
            if ($this->wantsJsonResponse()) {
                json_response(['ok' => false, 'message' => $message], 422);
            }
            flash('error', $message);
            redirect($returnTo);
        }

        $stmt = $this->db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            $message = 'Пользователь не найден.';
            if ($this->wantsJsonResponse()) {
                json_response(['ok' => false, 'message' => $message], 404);
            }
            flash('error', $message);
            redirect($returnTo);
        }

        $driver = (string) config('db.connection');
        if ($driver === 'sqlite') {
            $this->db()->prepare('
                INSERT INTO employee_rates (user_id, hourly_rate, updated_by, updated_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(user_id) DO UPDATE SET hourly_rate = excluded.hourly_rate, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP
            ')->execute([$id, $rate, (int) $user['id']]);
        } else {
            $this->db()->prepare('
                INSERT INTO employee_rates (user_id, hourly_rate, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE hourly_rate = VALUES(hourly_rate), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP
            ')->execute([$id, $rate, (int) $user['id']]);
        }

        if ($this->wantsJsonResponse()) {
            json_response([
                'ok' => true,
                'message' => 'Ставка обновлена.',
                'hourly_rate' => $rate,
            ]);
        }

        flash('success', 'Ставка обновлена.');
        redirect($returnTo);
    }

    public function updateUserActive(int $id): void
    {
        $admin = $this->requireUsersAdmin();
        $returnTo = $this->safeReturnTo('/admin/users');
        $active = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

        if ($active === 0 && (int) $admin['id'] === $id) {
            $message = 'Нельзя уволить текущего администратора.';
            if ($this->wantsJsonResponse()) {
                json_response(['ok' => false, 'message' => $message], 422);
            }
            flash('error', $message);
            redirect($returnTo);
        }

        $stmt = $this->db()->prepare('SELECT id, tab_number, name, email, role, department, must_change_password, is_active, last_login FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            $message = 'Пользователь не найден.';
            if ($this->wantsJsonResponse()) {
                json_response(['ok' => false, 'message' => $message], 404);
            }
            flash('error', $message);
            redirect($returnTo);
        }

        if ($active === 0 && RoleService::normalize((string) ($targetUser['role'] ?? '')) === RoleService::DIRECTOR) {
            $activeDirectors = 0;
            foreach ($this->db()->query('SELECT role FROM users WHERE is_active = 1')->fetchAll() as $candidate) {
                if (RoleService::normalize((string) ($candidate['role'] ?? '')) === RoleService::DIRECTOR) {
                    $activeDirectors++;
                }
            }
            if ($activeDirectors <= 1) {
                flash('error', 'Нельзя уволить последнего активного директора.');
                redirect($returnTo);
            }
        }

        $this->db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active, $id]);
        $targetUser['is_active'] = $active;
        $message = $active === 1 ? 'Пользователь возвращен.' : 'Пользователь уволен.';

        if ($this->wantsJsonResponse()) {
            json_response([
                'ok' => true,
                'message' => $message,
                'user' => $this->userPayload($targetUser),
            ]);
        }

        flash('success', $message);
        redirect($returnTo);
    }

    private function wantsJsonResponse(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function userPayload(array $user): array
    {
        $role = RoleService::normalize((string) ($user['role'] ?? ''));
        $isActive = (int) ($user['is_active'] ?? 1) === 1;
        $mustChangePassword = (int) ($user['must_change_password'] ?? 0) === 1;

        return [
            'id' => (int) ($user['id'] ?? 0),
            'tab_number' => (string) ($user['tab_number'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => $role,
            'role_label' => role_label($role),
            'department' => (string) ($user['department'] ?? ''),
            'position_id' => (int) ($user['position_id'] ?? 0),
            'manager_id' => (int) ($user['manager_id'] ?? 0),
            'last_login' => (string) ($user['last_login'] ?? ''),
            'hourly_rate' => (float) ($user['hourly_rate'] ?? 0),
            'is_active' => $isActive,
            'must_change_password' => $mustChangePassword,
            'status_label' => $isActive ? ($mustChangePassword ? 'Смена пароля' : 'Активен') : 'Уволен',
            'password_reset_at' => (string) ($user['password_reset_at'] ?? ''),
            'password_reset_by_name' => (string) ($user['password_reset_by_name'] ?? ''),
            'credentials_mail_marked_sent_at' => (string) ($user['credentials_mail_marked_sent_at'] ?? ''),
            'credentials_mail_marked_sent_by_name' => (string) ($user['credentials_mail_marked_sent_by_name'] ?? ''),
            'credentials_status_label' => ($user['credentials_mail_marked_sent_at'] ?? '') !== '' ? 'Письмо отправлено' : 'Письмо не отмечено',
        ];
    }

    private function usersWithRates(): array
    {
        $hasCredentialTracking = $this->hasUserCredentialTracking();
        $credentialSelect = $hasCredentialTracking
            ? 'reset_by.name AS password_reset_by_name,
                   sent_by.name AS credentials_mail_marked_sent_by_name,'
            : '"" AS password_reset_by_name,
                   "" AS credentials_mail_marked_sent_by_name,';
        $credentialJoins = $hasCredentialTracking
            ? 'LEFT JOIN users reset_by ON reset_by.id = u.password_reset_by
            LEFT JOIN users sent_by ON sent_by.id = u.credentials_mail_marked_sent_by'
            : '';

        return $this->db()->query('
            SELECT u.*,
                   p.title AS position_title,
                   p.grade AS position_grade,
                   manager.name AS manager_name,
                   ' . $credentialSelect . '
                   COALESCE(er.hourly_rate, 0) AS hourly_rate,
                   er.updated_at AS rate_updated_at,
                   rate_updater.name AS rate_updated_by_name
            FROM users u
            LEFT JOIN positions p ON p.id = u.position_id
            LEFT JOIN users manager ON manager.id = u.manager_id
            ' . $credentialJoins . '
            LEFT JOIN employee_rates er ON er.user_id = u.id
            LEFT JOIN users rate_updater ON rate_updater.id = er.updated_by
            ORDER BY u.department, u.name
        ')->fetchAll();
    }

    private function hasUserCredentialTracking(): bool
    {
        foreach (['password_reset_at', 'password_reset_by', 'credentials_mail_marked_sent_at', 'credentials_mail_marked_sent_by'] as $column) {
            if (!$this->userHasColumn($column)) {
                return false;
            }
        }

        return true;
    }

    private function userHasColumn(string $column): bool
    {
        if ($this->userColumnMap === null) {
            $this->userColumnMap = [];
            if ((string) config('db.connection') === 'sqlite') {
                foreach ($this->db()->query('PRAGMA table_info(users)')->fetchAll() as $row) {
                    $this->userColumnMap[(string) $row['name']] = true;
                }
            } else {
                $stmt = $this->db()->query('SHOW COLUMNS FROM users');
                foreach ($stmt->fetchAll() as $row) {
                    $this->userColumnMap[(string) ($row['Field'] ?? '')] = true;
                }
            }
        }

        return isset($this->userColumnMap[$column]);
    }

    private function activeUsersForSelect(): array
    {
        return $this->db()->query('
            SELECT id, name, department
            FROM users
            WHERE is_active = 1
            ORDER BY department, name
        ')->fetchAll();
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function existsById(string $table, int $id): bool
    {
        if (!in_array($table, ['positions', 'users'], true)) {
            return false;
        }
        $stmt = $this->db()->prepare('SELECT id FROM ' . $table . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    private function managerAssignmentCreatesCycle(int $userId, int $managerId): bool
    {
        $parents = [];
        foreach ($this->db()->query('SELECT id, manager_id FROM users')->fetchAll() as $row) {
            $parents[(int) $row['id']] = (int) ($row['manager_id'] ?? 0);
        }

        $seen = [];
        while ($managerId > 0) {
            if ($managerId === $userId || isset($seen[$managerId])) {
                return true;
            }
            $seen[$managerId] = true;
            $managerId = $parents[$managerId] ?? 0;
        }

        return false;
    }

    private function departmentExists(string $code): bool
    {
        $stmt = $this->db()->prepare('SELECT id FROM departments WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        return (bool) $stmt->fetchColumn();
    }

    private function departmentGroupExists(int $id): bool
    {
        return $this->departmentGroup($id) !== null;
    }

    private function departmentGroup(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM department_groups WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $group = $stmt->fetch();
        return is_array($group) ? $group : null;
    }

    private function safeReturnTo(string $fallback): string
    {
        $returnTo = trim((string) ($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
        if ($returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//') || preg_match('/[\r\n]/', $returnTo)) {
            return $fallback;
        }

        return $returnTo;
    }

    private function userFieldExistsExcept(string $field, string $value, int $exceptId): bool
    {
        if (!in_array($field, ['tab_number', 'email'], true)) {
            return false;
        }
        $where = $field === 'email' ? 'LOWER(email) = LOWER(?)' : $field . ' = ?';
        $stmt = $this->db()->prepare('SELECT id FROM users WHERE ' . $where . ' AND id <> ? LIMIT 1');
        $stmt->execute([$value, $exceptId]);
        return (bool) $stmt->fetchColumn();
    }

    private function userFieldExists(string $field, string $value): bool
    {
        if (!in_array($field, ['tab_number', 'email'], true)) {
            return false;
        }
        $where = $field === 'email' ? 'LOWER(email) = LOWER(?)' : $field . ' = ?';
        $stmt = $this->db()->prepare('SELECT id FROM users WHERE ' . $where . ' LIMIT 1');
        $stmt->execute([$value]);
        return (bool) $stmt->fetchColumn();
    }

    private function loadUserForPayload(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return is_array($user) ? $user : null;
    }

    private function failUserIdentity(string $message): void
    {
        if ($this->wantsJsonResponse()) {
            json_response(['ok' => false, 'message' => $message], 422);
        }

        flash('error', $message);
        redirect('/admin/users');
    }

    private function failStoreUser(string $message): void
    {
        if ($this->wantsJsonResponse()) {
            json_response(['ok' => false, 'message' => $message], 422);
        }

        flash('error', $message);
        redirect('/admin/users');
    }

    private function decimal(mixed $value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (float) str_replace(',', '.', (string) $value);
    }
}
