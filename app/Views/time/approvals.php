<?php
use App\Services\TimeApprovalService;
use App\Services\TimeService;
use App\Services\RoleService;

$reviews = $reviews ?? [];
$monthStart = (string) ($monthStart ?? date('Y-m-01'));
$monthEnd = (string) ($monthEnd ?? date('Y-m-t'));
$prevMonth = (string) ($prevMonth ?? $monthStart);
$nextMonth = (string) ($nextMonth ?? $monthStart);
$canDirectorApprove = (bool) ($canDirectorApprove ?? false);
$viewer = is_array($viewer ?? null) ? $viewer : [];
$viewerRole = (string) ($viewer['role'] ?? '');
$canGipApprove = RoleService::isAny($viewerRole, [RoleService::GIP, RoleService::PROJECT_MANAGER, RoleService::DIRECTOR, RoleService::ADMIN]);
$canDepartmentApprove = RoleService::isAny($viewerRole, [RoleService::DEPARTMENT_HEAD, RoleService::DEPUTY_DEPARTMENT_HEAD, RoleService::DIRECTOR, RoleService::ADMIN]);
$hours = static fn (int $minutes): string => TimeService::minutesToHours($minutes) ?: '0';
?>

<section class="panel">
    <div class="panel__head">
        <h2><?= e(format_date($monthStart)) ?> — <?= e(format_date($monthEnd)) ?></h2>
        <div class="toolbar__actions">
            <a class="btn btn-outline" href="<?= url('/time/approvals?month=' . $prevMonth) ?>">Назад</a>
            <a class="btn btn-outline" href="<?= url('/time/approvals?month=' . date('Y-m-01')) ?>">Текущий</a>
            <a class="btn btn-outline" href="<?= url('/time/approvals?month=' . $nextMonth) ?>">Вперёд</a>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table" data-no-column-filters>
            <thead>
            <tr>
                <th>Сотрудник</th>
                <th>Отдел</th>
                <th>Проекты</th>
                <th>Часы</th>
                <th>Статус</th>
                <th>Срез</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($reviews as $review): ?>
                <?php
                $status = (string) ($review['status'] ?? 'open');
                $isLocked = $status === 'locked';
                $userId = (int) ($review['user_id'] ?? 0);
                ?>
                <tr>
                    <td><strong><?= e($review['name'] ?? '') ?></strong><br><small><?= e($review['email'] ?? '') ?></small></td>
                    <td><?= e($review['department'] ?? '') ?></td>
                    <td><?= e($review['project_codes'] ?: 'Без проекта') ?></td>
                    <td><strong><?= e($hours((int) ($review['total_minutes'] ?? 0))) ?></strong></td>
                    <td><span class="status-pill"><?= e(TimeApprovalService::reviewStatusLabel($review)) ?></span></td>
                    <td>
                        <small class="muted">
                            Зафиксировано: <?= e($hours((int) ($review['locked_minutes'] ?? 0))) ?> ч
                            <?php if (!empty($review['gip_approved_at'])): ?><br>ГИП подтвердил: <?= e(format_date((string) $review['gip_approved_at'])) ?><?php endif; ?>
                            <?php if (!empty($review['department_approved_at'])): ?><br>Руководитель отдела подтвердил: <?= e(format_date((string) $review['department_approved_at'])) ?><?php endif; ?>
                            <?php if (!empty($review['director_approved_at'])): ?><br>Месяц закрыт: <?= e(format_date((string) $review['director_approved_at'])) ?><?php endif; ?>
                            <?php if (!empty($review['return_comment'])): ?><br>Возврат: <?= e((string) $review['return_comment']) ?><?php endif; ?>
                        </small>
                    </td>
                    <td class="user-actions">
                        <?php if (!$isLocked && $canGipApprove && empty($review['gip_approved_at']) && (int) ($review['total_minutes'] ?? 0) > 0): ?>
                            <form method="post" action="<?= url('/time/approvals/' . $userId . '/gip-approve') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="month" value="<?= e($monthStart) ?>">
                                <button class="btn btn--red" type="submit">Подтвердить ГИПом</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$isLocked && $canDepartmentApprove && empty($review['department_approved_at'])): ?>
                            <form method="post" action="<?= url('/time/approvals/' . $userId . '/department-approve') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="month" value="<?= e($monthStart) ?>">
                                <button class="btn" type="submit">Подтвердить руководителем отдела</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canDirectorApprove && !$isLocked): ?>
                            <form method="post" action="<?= url('/time/approvals/' . $userId . '/director-approve') ?>" onsubmit="return confirm('Закрыть месяц и заблокировать факт? После закрытия пользователь не сможет править эти часы.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="month" value="<?= e($monthStart) ?>">
                                <button class="btn btn--red" type="submit">Закрыть месяц</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$isLocked && ($review['id'] ?? null)): ?>
                            <form method="post" action="<?= url('/time/approvals/' . $userId . '/return') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="month" value="<?= e($monthStart) ?>">
                                <input name="comment" maxlength="1000" placeholder="Причина возврата">
                                <button class="btn btn-outline" type="submit">Вернуть</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canDirectorApprove && $isLocked): ?>
                            <form method="post" action="<?= url('/time/approvals/' . $userId . '/reopen') ?>" onsubmit="return confirm('Открыть закрытый месяц на корректировку? Часы снова станут редактируемыми и временно уйдут из ДБ-отчёта.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="month" value="<?= e($monthStart) ?>">
                                <input name="comment" maxlength="1000" placeholder="Причина корректировки" required>
                                <button class="btn btn-outline" type="submit">Открыть корректировку</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reviews): ?>
                <tr><td colspan="7" class="muted">Нет сотрудников в доступной области просмотра.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
