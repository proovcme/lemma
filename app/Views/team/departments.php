<?php require __DIR__ . '/_tabs.php'; ?>

<section class="team-structure-summary" aria-label="Сводка структуры">
    <article class="metric-card"><span>Отделы</span><strong><?= count($departments) ?></strong></article>
    <article class="metric-card"><span>Группы</span><strong><?= count($groups) ?></strong></article>
    <article class="metric-card"><span>Сотрудники</span><strong><?= array_sum(array_map(static fn(array $d): int => (int) $d['people_count'], $departments)) ?></strong></article>
</section>

<div class="team-structure-grid">
    <section class="panel">
        <div class="panel__head"><div><h2>Отделы</h2><p class="muted">Код используется в проектах, отчётах и стоимостных группах.</p></div></div>
        <form class="form-grid team-compact-form" method="post" action="<?= url('/team/departments') ?>">
            <?= csrf_field() ?><input type="hidden" name="return_to" value="/team/departments">
            <label><span>Код</span><input name="code" maxlength="20" placeholder="ОВ" required></label>
            <label><span>Название</span><input name="name" placeholder="Отопление и вентиляция" required></label>
            <label><span>Руководитель</span><select name="head_user_id"><option value="">Не назначен</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e($user['name']) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn--red" type="submit">Добавить отдел</button>
        </form>
        <div class="team-structure-list">
            <?php foreach ($departments as $department): ?>
                <details class="team-structure-item">
                    <summary><span class="mono"><?= e($department['code']) ?></span><strong><?= e($department['name']) ?></strong><small><?= (int) $department['people_count'] ?> чел.</small></summary>
                    <form class="form-grid team-compact-form" method="post" action="<?= url('/team/departments/' . (int) $department['id']) ?>">
                        <?= csrf_field() ?><input type="hidden" name="return_to" value="/team/departments">
                        <label><span>Название</span><input name="name" value="<?= e($department['name']) ?>" required></label>
                        <label><span>Руководитель</span><select name="head_user_id"><option value="">Не назначен</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"<?= selected((string) ($department['head_user_id'] ?? ''), (string) $user['id']) ?>><?= e($user['name']) ?></option><?php endforeach; ?></select></label>
                        <button class="btn btn-outline" type="submit">Сохранить</button>
                    </form>
                    <form method="post" action="<?= url('/team/departments/' . (int) $department['id'] . '/delete') ?>" onsubmit="return confirm('Удалить отдел и отвязать его сотрудников?')">
                        <?= csrf_field() ?><input type="hidden" name="return_to" value="/team/departments"><button class="btn btn-sm btn-danger" type="submit">Удалить отдел</button>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><div><h2>Группы</h2><p class="muted">Внутренняя структура отдела и руководители групп.</p></div></div>
        <form class="form-grid team-compact-form" method="post" action="<?= url('/team/department-groups') ?>">
            <?= csrf_field() ?><input type="hidden" name="return_to" value="/team/departments#groups">
            <label><span>Отдел</span><select name="department_code" required><option value="">Выберите</option><?php foreach ($departments as $department): ?><option value="<?= e($department['code']) ?>"><?= e($department['code']) ?> · <?= e($department['name']) ?></option><?php endforeach; ?></select></label>
            <label><span>Название</span><input name="name" placeholder="Группа 1" required></label>
            <label><span>Руководитель</span><select name="lead_user_id"><option value="">Не назначен</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e($user['name']) ?></option><?php endforeach; ?></select></label>
            <input type="hidden" name="sort_order" value="100"><button class="btn btn--red" type="submit">Добавить группу</button>
        </form>
        <div class="team-structure-list" id="groups">
            <?php foreach ($groups as $group): ?>
                <details class="team-structure-item">
                    <summary><span class="mono"><?= e($group['department_code']) ?></span><strong><?= e($group['name']) ?></strong><small><?= (int) $group['people_count'] ?> чел.</small></summary>
                    <form class="form-grid team-compact-form" method="post" action="<?= url('/team/department-groups/' . (int) $group['id']) ?>">
                        <?= csrf_field() ?><input type="hidden" name="return_to" value="/team/departments#groups">
                        <label><span>Отдел</span><select name="department_code"><?php foreach ($departments as $department): ?><option value="<?= e($department['code']) ?>"<?= selected((string) $group['department_code'], (string) $department['code']) ?>><?= e($department['code']) ?></option><?php endforeach; ?></select></label>
                        <label><span>Название</span><input name="name" value="<?= e($group['name']) ?>" required></label>
                        <label><span>Руководитель</span><select name="lead_user_id"><option value="">Не назначен</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"<?= selected((string) ($group['lead_user_id'] ?? ''), (string) $user['id']) ?>><?= e($user['name']) ?></option><?php endforeach; ?></select></label>
                        <input type="hidden" name="sort_order" value="<?= (int) $group['sort_order'] ?>"><button class="btn btn-outline" type="submit">Сохранить</button>
                    </form>
                    <form method="post" action="<?= url('/team/department-groups/' . (int) $group['id'] . '/delete') ?>" onsubmit="return confirm('Удалить группу?')">
                        <?= csrf_field() ?><input type="hidden" name="return_to" value="/team/departments#groups"><button class="btn btn-sm btn-danger" type="submit">Удалить группу</button>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</div>
