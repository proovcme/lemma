<?php
$roleModel = \App\Services\RoleService::all();
$roles = array_keys($roleModel);
$capabilityLabels = \App\Services\RoleService::capabilityLabels();
$roleCapabilities = static function (array $role) use ($capabilityLabels): string {
    $labels = [];
    foreach ($capabilityLabels as $capability => $label) {
        if (in_array($capability, $role['capabilities'] ?? [], true)) {
            $labels[] = $label;
        }
    }

    return implode(' · ', $labels);
};
$generatedCredentials = $generatedCredentials ?? [];
$importSkipped = $importSkipped ?? [];
$positions = $positions ?? [];
$managers = $managers ?? [];
$canManageRates = (bool) ($canManageRates ?? false);
$appUrl = rtrim((string) config('app.url'), '/');
$formatRate = static function (mixed $value): string {
    $formatted = number_format((float) $value, 2, '.', ' ');
    return rtrim(rtrim($formatted, '0'), '.');
};
$statusFilterOptions = [
    'active' => 'Активен',
    'password' => 'Смена пароля',
    'mail_pending' => 'Письмо не отмечено',
    'mail_sent' => 'Письмо отправлено',
    'inactive' => 'Уволен',
];
$renderCredentialMail = static function (array $credential) use ($appUrl): array {
    return \App\Services\NotificationTemplateService::render('credentials_mail', [
        '{user_name}'  => trim((string) ($credential['name'] ?? '')),
        '{user_email}' => (string) ($credential['email'] ?? ''),
        '{password}'   => (string) ($credential['password'] ?? ''),
        '{app_url}'    => $appUrl,
    ]);
};
$credentialMailBody = static function (array $credential) use ($renderCredentialMail): string {
    return $renderCredentialMail($credential)['body'];
};
?>
<?php if ($importSkipped): ?>
    <section class="panel">
        <div class="panel__head">
            <h2>Пропущено при импорте</h2>
            <span><?= count($importSkipped) ?></span>
        </div>
        <div class="table-wrap">
            <table class="data-table data-table--compact">
                <thead><tr><th>Строка</th><th>Данные</th><th>Причина</th></tr></thead>
                <tbody>
                <?php foreach ($importSkipped as $skip): ?>
                    <tr>
                        <td><?= (int) $skip['line'] ?></td>
                        <td><?= e($skip['data']) ?></td>
                        <td class="muted"><?= e($skip['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="panel one-time-password one-time-password--credential" data-admin-credentials<?= $generatedCredentials ? '' : ' hidden' ?>>
        <div class="panel__head">
            <h2>Одноразовые доступы</h2>
            <span data-admin-credential-count><?= count($generatedCredentials) ?></span>
        </div>
        <p class="muted">Пароли показаны один раз. Текст письма можно скопировать вручную; автоматическая отправка идёт только через настроенную SMTP-очередь.</p>
        <div class="credential-list" data-admin-credential-list>
            <?php foreach ($generatedCredentials as $index => $credential): ?>
                <?php
                $passwordId = 'generated-password-' . $index;
                $bodyId = 'generated-email-body-' . $index;
                $body = (string) ($credential['mail_body'] ?? $credentialMailBody($credential));
                ?>
                <article class="credential-card">
                    <div class="credential-card__main">
                        <strong><?= e($credential['name'] ?? '') ?></strong>
                        <span><?= e($credential['email'] ?? '') ?> · таб. <?= e($credential['tab_number'] ?? '') ?> · <?= e(role_label($credential['role'] ?? '')) ?></span>
                    </div>
                    <code id="<?= e($passwordId) ?>"><?= e($credential['password'] ?? '') ?></code>
                    <div class="credential-card__actions">
                        <button class="btn" type="button" data-copy="#<?= e($passwordId) ?>">Копировать пароль</button>
                        <button class="btn" type="button" data-copy="#<?= e($bodyId) ?>" data-copy-label="Копировать текст письма">Копировать текст письма</button>
                    </div>
                    <pre class="credential-card__body" id="<?= e($bodyId) ?>"><?= e($body) ?></pre>
                </article>
            <?php endforeach; ?>
        </div>
</section>
<?php if (!$generatedCredentials && $generatedPassword): ?>
    <section class="panel one-time-password">
        <div class="panel__head"><h2>Одноразовый пароль</h2></div>
        <code id="generated-password"><?= e($generatedPassword) ?></code>
        <button class="btn" type="button" data-copy="#generated-password">Копировать</button>
    </section>
<?php endif; ?>

<section class="panel role-model">
    <div class="panel__head">
        <h2>Ролевая модель</h2>
        <a class="btn" href="<?= url('/admin/access') ?>">Настроить доступы</a>
    </div>
    <div class="table-wrap">
        <table class="data-table data-table--compact">
            <thead><tr><th>Роль</th><th>Область видимости</th><th>Права</th><th>Старт</th></tr></thead>
            <tbody>
            <?php foreach ($roleModel as $roleKey => $role): ?>
                <tr>
                    <td><strong><?= e($role['label']) ?></strong><br><small><?= e($roleKey) ?></small></td>
                    <td><?= e($role['scope']) ?></td>
                    <td><?= e($roleCapabilities($role)) ?></td>
                    <td><code><?= e($role['home']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<form class="panel form-grid" method="post" action="<?= url('/admin/users') ?>" data-admin-user-create>
    <?= csrf_field() ?>
    <div class="panel__head form-grid__full"><h2>Добавить пользователя</h2><button class="btn btn--red" type="submit">Создать</button></div>
    <label><span>Табельный номер</span><input name="tab_number" required></label>
    <label><span>Имя</span><input name="name" required></label>
    <label><span>Email</span><input type="email" name="email" required></label>
    <label><span>Роль</span><select name="role"><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>"><?= e(role_label($role)) ?></option><?php endforeach; ?></select></label>
    <label>
        <span>Отдел</span>
        <select name="department">
            <option value="">-- Без отдела --</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?= e($dept['code']) ?>"><?= e($dept['code']) ?> (<?= e($dept['name']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Должность</span>
        <select name="position_id">
            <option value="">-- Не задана --</option>
            <?php foreach ($positions as $position): ?>
                <option value="<?= (int) $position['id'] ?>"><?= e($position['title']) ?><?= ($position['grade'] ?? '') !== '' ? ' · ' . e($position['grade']) : '' ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Руководитель</span>
        <select name="manager_id">
            <option value="">-- Не задан --</option>
            <?php foreach ($managers as $manager): ?>
                <option value="<?= (int) $manager['id'] ?>"><?= e($manager['name']) ?><?= ($manager['department'] ?? '') !== '' ? ' · ' . e($manager['department']) : '' ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<section class="panel" data-admin-users-panel>
    <div class="panel__head">
        <h2>Список пользователей</h2>
        <div class="toolbar__actions">
            <span data-admin-user-count><?= count($users) ?></span>
            <a class="btn" href="<?= url('/admin/users/import') ?>">Импорт из CSV</a>
            <button class="btn" type="submit" form="bulk-credential-form" name="bulk_action" value="reset_passwords" onclick="return confirm('Сбросить пароли выбранным пользователям и подготовить письма?')">Сбросить выбранным</button>
        </div>
    </div>
    <div class="admin-user-filterbar" data-admin-user-filters>
        <label class="admin-user-filterbar__toggle">
            <input type="checkbox" data-admin-user-hide-inactive checked>
            <span>Скрыть уволенных</span>
        </label>
        <label>
            <span>Поиск</span>
            <input type="search" data-admin-user-filter="text" placeholder="Табельный, имя, email">
        </label>
        <label>
            <span>Роль</span>
            <select data-admin-user-filter="role">
                <option value="">Все</option>
                <?php foreach ($roles as $role): ?><option value="<?= e($role) ?>"><?= e(role_label($role)) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Отдел</span>
            <select data-admin-user-filter="department">
                <option value="">Все</option>
                <option value="__empty">Без отдела</option>
                <?php foreach ($departments as $dept): ?><option value="<?= e($dept['code']) ?>"><?= e($dept['code']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Статус</span>
            <select data-admin-user-filter="status">
                <option value="">Все</option>
                <?php foreach ($statusFilterOptions as $statusKey => $statusText): ?><option value="<?= e($statusKey) ?>"><?= e($statusText) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-outline" type="button" data-admin-user-filter-reset>Сбросить</button>
    </div>
    <form id="bulk-credential-form" method="post" action="<?= url('/admin/users/reset-passwords') ?>">
        <?= csrf_field() ?>
        <div class="admin-user-bulkbar">
            <label>
                <span>Роль выбранным</span>
                <select name="bulk_role">
                    <?php foreach ($roles as $role): ?><option value="<?= e($role) ?>"><?= e(role_label($role)) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button class="btn" type="submit" name="bulk_action" value="role">Назначить роль</button>
            <label>
                <span>Отдел выбранным</span>
                <select name="bulk_department">
                    <option value="">-- Без отдела --</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= e($dept['code']) ?>"><?= e($dept['code']) ?> (<?= e($dept['name']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn" type="submit" name="bulk_action" value="department">Назначить отдел</button>
            <label>
                <span>Статус выбранным</span>
                <select name="bulk_active">
                    <option value="1">Вернуть</option>
                    <option value="0">Уволить</option>
                </select>
            </label>
            <button class="btn btn-outline" type="submit" name="bulk_action" value="active" onclick="return confirm('Изменить статус выбранных пользователей?')">Изменить статус</button>
        </div>
    </form>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th><input type="checkbox" data-check-all=".user-bulk-check" aria-label="Выбрать всех пользователей"></th><th>Табельный</th><th>Имя</th><th>Email</th><th>Роль</th><th>Отдел</th><th>Оргструктура</th><?php if ($canManageRates): ?><th data-admin-user-rate-column>Ставка, руб./ч</th><?php endif; ?><th>Доступ</th><th>Последний вход</th><th>Статус</th><th></th></tr></thead>
            <tbody data-admin-users-list>
            <?php foreach ($users as $item): ?>
                <?php
                $isActive = (int) ($item['is_active'] ?? 1) === 1;
                $statusKey = $isActive ? ((int) ($item['must_change_password'] ?? 0) ? 'password' : 'active') : 'inactive';
                $statusLabel = $statusFilterOptions[$statusKey] ?? 'Активен';
                $mailStatusKey = !empty($item['credentials_mail_marked_sent_at']) ? 'mail_sent' : 'mail_pending';
                $normalizedRole = \App\Services\RoleService::normalize($item['role'] ?? '');
                $department = (string) ($item['department'] ?? '');
                $searchText = trim((string) ($item['tab_number'] ?? '') . ' ' . (string) ($item['name'] ?? '') . ' ' . (string) ($item['email'] ?? '') . ' ' . role_label($normalizedRole) . ' ' . $department . ' ' . $statusLabel . ' ' . ($statusFilterOptions[$mailStatusKey] ?? ''));
                ?>
                <tr data-admin-user-row="<?= (int) $item['id'] ?>"
                    data-user-active="<?= $isActive ? '1' : '0' ?>"
                    data-user-role="<?= e($normalizedRole) ?>"
                    data-user-department="<?= e($department) ?>"
                    data-user-status-key="<?= e($statusKey) ?>"
                    data-user-mail-key="<?= e($mailStatusKey) ?>"
                    data-user-search="<?= e(mb_strtolower($searchText, 'UTF-8')) ?>"<?= $isActive ? '' : ' class="is-inactive"' ?>>
                    <td><input class="user-bulk-check" type="checkbox" name="user_ids[]" value="<?= (int) $item['id'] ?>" form="bulk-credential-form" aria-label="Выбрать <?= e($item['name']) ?>"></td>
                    <?php $identityFormId = 'identity-' . (int) $item['id']; ?>
                    <td data-user-field="tab_number">
                        <form id="<?= e($identityFormId) ?>" method="post" action="<?= url('/admin/users/' . $item['id'] . '/identity') ?>" data-admin-user-form></form>
                        <input type="hidden" form="<?= e($identityFormId) ?>" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input class="admin-user-inline-input" form="<?= e($identityFormId) ?>" name="tab_number" value="<?= e($item['tab_number']) ?>" required aria-label="Табельный номер <?= e($item['name']) ?>">
                    </td>
                    <td data-user-field="name">
                        <input class="admin-user-inline-input admin-user-inline-input--wide" form="<?= e($identityFormId) ?>" name="name" value="<?= e($item['name']) ?>" required aria-label="ФИО">
                    </td>
                    <td data-user-field="email">
                        <div class="admin-user-email-edit">
                            <input class="admin-user-inline-input admin-user-inline-input--wide" form="<?= e($identityFormId) ?>" type="email" name="email" value="<?= e($item['email']) ?>" required aria-label="Email <?= e($item['name']) ?>">
                            <button class="btn btn-sm" form="<?= e($identityFormId) ?>" type="submit">OK</button>
                        </div>
                    </td>
                    <td>
                        <form method="post" action="<?= url('/admin/users/' . $item['id'] . '/role') ?>" data-admin-user-form>
                            <?= csrf_field() ?>
                            <select name="role" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                <?php foreach ($roles as $role): ?><option value="<?= e($role) ?>"<?= selected($normalizedRole, $role) ?>><?= e(role_label($role)) ?></option><?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <form method="post" action="<?= url('/admin/users/' . $item['id'] . '/department') ?>" data-admin-user-form>
                            <?= csrf_field() ?>
                            <select name="department" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                <option value="">-- Без отдела --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= e($dept['code']) ?>"<?= selected($item['department'] ?? '', $dept['code']) ?>><?= e($dept['code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <form method="post" action="<?= url('/admin/users/' . $item['id'] . '/org') ?>" data-admin-user-form>
                            <?= csrf_field() ?>
                            <label class="sr-only" for="position-<?= (int) $item['id'] ?>">Должность</label>
                            <select id="position-<?= (int) $item['id'] ?>" name="position_id" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                <option value="">-- Должность --</option>
                                <?php foreach ($positions as $position): ?>
                                    <option value="<?= (int) $position['id'] ?>"<?= selected((string) ($item['position_id'] ?? ''), (string) $position['id']) ?>>
                                        <?= e($position['title']) ?><?= ($position['grade'] ?? '') !== '' ? ' · ' . e($position['grade']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label class="sr-only" for="manager-<?= (int) $item['id'] ?>">Руководитель</label>
                            <select id="manager-<?= (int) $item['id'] ?>" name="manager_id" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                <option value="">-- Руководитель --</option>
                                <?php foreach ($managers as $manager): ?>
                                    <?php if ((int) $manager['id'] === (int) $item['id']) continue; ?>
                                    <option value="<?= (int) $manager['id'] ?>"<?= selected((string) ($item['manager_id'] ?? ''), (string) $manager['id']) ?>>
                                        <?= e($manager['name']) ?><?= ($manager['department'] ?? '') !== '' ? ' · ' . e($manager['department']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <?php if ($canManageRates): ?>
                        <td>
                            <form class="admin-rate-form" method="post" action="<?= url('/admin/users/' . $item['id'] . '/rate') ?>" data-admin-user-form>
                                <?= csrf_field() ?>
                                <input type="number" min="0" step="0.01" name="hourly_rate" value="<?= e($formatRate($item['hourly_rate'] ?? 0)) ?>" aria-label="Ставка <?= e($item['name']) ?>, рублей в час">
                                <button class="btn btn-sm" type="submit">OK</button>
                            </form>
                            <small class="muted"><?= e($item['rate_updated_at'] ? format_date($item['rate_updated_at']) : '—') ?></small>
                        </td>
                    <?php endif; ?>
                    <td data-user-credential-status>
                        <small>
                            Пароль: <?= e(!empty($item['password_reset_at']) ? format_date((string) $item['password_reset_at']) : '—') ?>
                            <?php if (!empty($item['password_reset_by_name'])): ?><br><span class="muted"><?= e((string) $item['password_reset_by_name']) ?></span><?php endif; ?>
                            <br>Письмо:
                            <?php if (!empty($item['credentials_mail_marked_sent_at'])): ?>
                                <?= e(format_date((string) $item['credentials_mail_marked_sent_at'])) ?>
                                <?php if (!empty($item['credentials_mail_marked_sent_by_name'])): ?><br><span class="muted"><?= e((string) $item['credentials_mail_marked_sent_by_name']) ?></span><?php endif; ?>
                            <?php else: ?>
                                <span class="muted">не отмечено</span>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td><?= e($item['last_login']) ?></td>
                    <td><span class="status-pill<?= $isActive ? '' : ' status-pill--muted' ?>" data-user-status><?= e($statusLabel) ?></span></td>
                    <td class="user-actions">
                        <?php if ($isActive): ?>
                            <a class="btn btn-outline" href="<?= url('/profiles/' . (int) $item['id']) ?>">Профиль</a>
                        <?php endif; ?>
                        <form method="post" action="<?= url('/admin/users/' . $item['id'] . '/reset-password') ?>" data-admin-user-form data-admin-user-reset>
                            <?= csrf_field() ?>
                            <button class="btn" type="submit">Сбросить пароль</button>
                        </form>
                        <form method="post" action="<?= url('/admin/users/' . $item['id'] . '/credentials-mail-sent') ?>" data-admin-user-form data-admin-user-mail-sent>
                            <?= csrf_field() ?>
                            <button class="btn btn-outline" type="submit">Письмо отправлено</button>
                        </form>
                        <form method="post" action="<?= url('/admin/users/' . $item['id'] . '/active') ?>" data-admin-user-form data-admin-user-active>
                            <?= csrf_field() ?>
                            <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
                            <button class="btn<?= $isActive ? ' btn-outline' : ' btn--red' ?>" type="submit" data-active-button><?= $isActive ? 'Уволить' : 'Вернуть' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr data-admin-user-empty hidden>
                    <td colspan="<?= $canManageRates ? 12 : 11 ?>">
                        <div class="empty-state empty-state--compact">
                            <strong>Пользователи не найдены</strong>
                            <p>Измените поиск, роль, отдел или статус.</p>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
