<?php
$notifications = $notifications ?? [];
$formatNotificationTime = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d.m.Y H:i', $timestamp);
};
?>
<section class="panel push-settings" data-push-settings data-push-state="loading">
    <div class="push-settings__mark" aria-hidden="true"><img src="<?= url('/pwa/icon-192.png') ?>" alt=""></div>
    <div class="push-settings__copy">
        <span class="eyebrow">На этом устройстве</span>
        <h2>Push-уведомления</h2>
        <p data-push-message>Проверяем поддержку системных уведомлений…</p>
    </div>
    <div class="push-settings__actions">
        <button class="btn btn--red" type="button" data-push-enable hidden>Включить</button>
        <button class="btn btn-outline" type="button" data-push-test hidden>Проверить</button>
        <button class="btn btn-outline" type="button" data-push-disable hidden>Выключить</button>
    </div>
</section>
<section class="panel notification-center">
    <div class="section-head">
        <div>
            <h2>Центр уведомлений</h2>
            <p>Последние события по задачам и согласованиям.</p>
        </div>
        <?php if ($notifications): ?>
            <form method="post" action="<?= url('/notifications/read') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline" type="submit">Все прочитано</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!$notifications): ?>
        <p class="muted">Новых уведомлений нет.</p>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <?php $isUnread = empty($notification['read_at']); ?>
                <article class="notification-item<?= $isUnread ? ' is-unread' : '' ?>">
                    <div class="notification-item__body">
                        <div class="notification-item__meta">
                            <span><?= e($formatNotificationTime($notification['created_at'] ?? '')) ?></span>
                            <?php if (!empty($notification['project_code'])): ?><span><?= e($notification['project_code']) ?></span><?php endif; ?>
                            <?php if (!empty($notification['type'])): ?><span><?= e($notification['type']) ?></span><?php endif; ?>
                        </div>
                        <p><?= e($notification['body'] ?? '') ?></p>
                        <?php if (!empty($notification['task_id'])): ?>
                            <a href="<?= url('/tasks/' . (int) $notification['task_id']) ?>">Открыть задачу<?= !empty($notification['task_title']) ? ': ' . e($notification['task_title']) : '' ?></a>
                        <?php elseif (!empty($notification['target_url'])): ?>
                            <a href="<?= url((string) $notification['target_url']) ?>">Открыть</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($isUnread): ?>
                        <form method="post" action="<?= url('/notifications/read') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                            <button class="btn btn-outline btn-sm" type="submit">Прочитано</button>
                        </form>
                    <?php else: ?>
                        <span class="notification-item__read">Прочитано</span>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
