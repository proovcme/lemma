<?php
/** @var array<string, array{label: string, subject: string, body: string, is_custom: bool}> $templates */
$templates = $templates ?? [];
$mailSettings = $mailSettings ?? [];
$outboxCounters = $outboxCounters ?? ['pending' => 0, 'failed' => 0, 'sent' => 0];
?>
<section class="panel">
    <div class="panel__head">
        <div>
            <h1>Почта</h1>
            <span>SMTP-настройки, очередь отправки и шаблоны писем</span>
        </div>
    </div>
    <form class="form-grid mail-settings-form" method="post" action="<?= url('/admin/notifications/settings') ?>">
        <?= csrf_field() ?>
        <label class="checkbox-label form-grid__full">
            <input type="checkbox" name="enabled" value="1"<?= !empty($mailSettings['enabled']) ? ' checked' : '' ?>>
            <span>Включить SMTP-отправку</span>
        </label>
        <label>
            <span>SMTP-хост</span>
            <input name="host" value="<?= e($mailSettings['host'] ?? '') ?>" placeholder="smtp.yandex.ru">
        </label>
        <label>
            <span>Порт</span>
            <input type="number" name="port" min="1" max="65535" value="<?= e($mailSettings['port'] ?? 465) ?>">
        </label>
        <label>
            <span>Шифрование</span>
            <select name="encryption" data-no-search>
                <?php foreach (['ssl' => 'SSL', 'tls' => 'STARTTLS'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= selected((string) ($mailSettings['encryption'] ?? 'ssl'), $value) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Таймаут, сек.</span>
            <input type="number" name="timeout" min="5" max="120" value="<?= e($mailSettings['timeout'] ?? 20) ?>">
        </label>
        <label>
            <span>Логин</span>
            <input name="username" value="<?= e($mailSettings['username'] ?? '') ?>" autocomplete="off">
        </label>
        <label>
            <span>Пароль приложения</span>
            <input type="password" name="password" value="" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять">
        </label>
        <label>
            <span>Email отправителя</span>
            <input type="email" name="from_email" value="<?= e($mailSettings['from_email'] ?? '') ?>" placeholder="lemma@example.local">
        </label>
        <label>
            <span>Имя отправителя</span>
            <input name="from_name" value="<?= e($mailSettings['from_name'] ?? 'Лемма') ?>">
        </label>
        <div class="form-actions form-grid__full">
            <button class="btn btn--red" type="submit">Сохранить SMTP</button>
            <span class="muted">Источник: <?= e(($mailSettings['source'] ?? 'env') === 'db' ? 'настройки в системе' : '.env') ?></span>
        </div>
    </form>
</section>

<section class="panel">
    <div class="panel__head">
        <div>
            <h2>Очередь писем</h2>
            <span>создание задач и подача на проверку пишут письма сюда</span>
        </div>
        <form class="actions-inline" method="post" action="<?= url('/admin/notifications/send-queue') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="limit" value="20">
            <button class="btn btn-outline" type="submit">Отправить очередь</button>
        </form>
    </div>
    <section class="metric-row project-summary-metrics cost-summary-metrics">
        <div class="metric"><span><?= (int) ($outboxCounters['pending'] ?? 0) ?></span><strong>В очереди</strong></div>
        <div class="metric"><span><?= (int) ($outboxCounters['failed'] ?? 0) ?></span><strong>С ошибкой</strong></div>
        <div class="metric"><span><?= (int) ($outboxCounters['sent'] ?? 0) ?></span><strong>Отправлено</strong></div>
    </section>
</section>

<section class="panel">
    <div class="panel__head">
        <div>
            <h2>Шаблоны писем</h2>
            <span>переменные в фигурных скобках заменяются данными задачи</span>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Шаблон</th>
                    <th>Тема (текущая)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $type => $tpl): ?>
                <tr>
                    <td>
                        <strong><?= e($tpl['label']) ?></strong>
                        <?php if ($tpl['is_custom']): ?>
                            <span class="badge badge--green">изменён</span>
                        <?php else: ?>
                            <span class="badge">по умолчанию</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted mail-template-subject"><?= e($tpl['subject']) ?></td>
                    <td>
                        <a class="btn btn--sm" href="<?= url('/admin/notifications/' . rawurlencode($type) . '/edit') ?>">Редактировать</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
