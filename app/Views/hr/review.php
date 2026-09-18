<?php
$statuses = $statuses ?? \App\Services\PerformanceReviewService::REVIEW_STATUSES;
$questions = $review['questions'] ?? [];
$answers = $review['answers'] ?? [];
$matrix = $review['competency_matrix'] ?? [];
$competencies = $matrix['competencies'] ?? [];
$scores = $review['competency_scores'] ?? [];
$visibility = $review['visibility'] ?? ['self' => true, 'manager' => true, 'comparison' => true, 'target' => true];
$status = (string) ($review['status'] ?? '');
$cycleActive = (string) ($review['cycle_status'] ?? '') === 'active';
$viewerId = (int) ($viewer['id'] ?? 0);
$isEmployee = (int) ($review['user_id'] ?? 0) === $viewerId;
$isManager = (int) ($review['manager_id'] ?? 0) === $viewerId;
$questionnaireDone = !empty($review['self_questionnaire_submitted_at']);
$selfMatrixDone = !empty($review['self_matrix_submitted_at']);
$managerMatrixDone = !empty($review['manager_matrix_submitted_at']);
$meetingDone = !empty($review['meeting_completed_at']);
$canEditQuestionnaire = $cycleActive && $isEmployee && !$questionnaireDone;
$canEditSelfMatrix = $cycleActive && $isEmployee && $questionnaireDone && !$selfMatrixDone;
$canEditManagerMatrix = $cycleActive && $isManager && $selfMatrixDone && !$managerMatrixDone;
$canCompleteMeeting = $cycleActive && $isManager && $status === 'hr_review' && $selfMatrixDone && $managerMatrixDone && !$meetingDone;
$showTarget = !empty($visibility['target']) && $selfMatrixDone && $managerMatrixDone;
$reviewContext = (string) ($reviewContext ?? 'self');
$backHref = $reviewContext === 'manager' ? '/performance-review/manager' : ($reviewContext === 'hr' ? '/hr/cycles/' . (int) ($review['cycle_id'] ?? 0) . '/summary' : '/profile#performance-review');
$backLabel = $reviewContext === 'manager' ? 'К оценкам сотрудников' : ($reviewContext === 'hr' ? 'К сводке цикла' : 'В мой профиль');
$positionIndex = is_numeric($review['competency_position_index'] ?? null) ? (int) $review['competency_position_index'] : null;
$levels = $matrix['levels'] ?? [1 => 'начальный', 2 => 'базовый', 3 => 'достаточный', 4 => 'продвинутый', 5 => 'экспертный'];
$questionSections = [];
foreach ($questions as $question) {
    if (!in_array((string) ($question['answer_scope'] ?? 'self'), ['self', 'both'], true)) {
        continue;
    }
    $sectionKey = (string) (($question['section_key'] ?? '') ?: 'questionnaire');
    if (!isset($questionSections[$sectionKey])) {
        $questionSections[$sectionKey] = [
            'label' => (string) (($question['section_label'] ?? '') ?: 'Анкета'),
            'questions' => [],
        ];
    }
    $questionSections[$sectionKey]['questions'][] = $question;
}
$scoreFor = static function (array $scores, int|string $key, string $scope): array {
    return (array) ($scores[(string) $key][$scope] ?? $scores[(int) $key][$scope] ?? []);
};
$requiredFor = static function (array $competency, ?int $positionIndex): ?int {
    if ($positionIndex === null) {
        return null;
    }
    $value = $competency['required'][$positionIndex] ?? null;
    return is_numeric($value) ? (int) $value : null;
};
$stepClass = static fn (bool $done, bool $active): string => $done ? ' is-done' : ($active ? ' is-active' : '');
?>

<section class="project-head project-head--tab performance-review-head">
    <div>
        <span class="muted"><?= e($review['cycle_title']) ?><?= !empty($review['review_year']) ? ' · ' . e((string) $review['review_year']) : '' ?></span>
        <h2><?= $reviewContext === 'self' ? 'Мой Performance Review' : e($review['employee_name']) ?></h2>
    </div>
    <div class="toolbar__actions project-tab-actions">
        <a class="btn" href="<?= url($backHref) ?>"><?= e($backLabel) ?></a>
        <?php if ($selfMatrixDone && $managerMatrixDone): ?><a class="btn btn-outline" href="<?= url('/performance-review/' . (int) $review['id'] . '/export') ?>">Экспорт XLSX</a><?php endif; ?>
        <?php if ($canManage): ?><a class="btn btn-outline" href="<?= url('/hr') ?>">Управление циклами</a><?php endif; ?>
    </div>
</section>

<ol class="review-steps">
    <li class="<?= e($stepClass($questionnaireDone, !$questionnaireDone)) ?>"><strong>1. Анкета самооценки</strong><span><?= $questionnaireDone ? 'Завершена' : 'Ожидает сотрудника' ?></span></li>
    <li class="<?= e($stepClass($selfMatrixDone && $managerMatrixDone, $questionnaireDone && !($selfMatrixDone && $managerMatrixDone))) ?>"><strong>2. Матрица компетенций</strong><span>Сотрудник: <?= $selfMatrixDone ? 'готово' : 'не завершено' ?> · Руководитель: <?= $managerMatrixDone ? 'готово' : 'не завершено' ?></span></li>
    <li class="<?= e($stepClass($meetingDone, $selfMatrixDone && $managerMatrixDone && !$meetingDone)) ?>"><strong>3. Итоговая встреча</strong><span><?= $meetingDone ? 'Итоги зафиксированы' : 'После обеих оценок' ?></span></li>
</ol>

<section class="metric-row project-summary-metrics">
    <div class="metric"><span><?= e($statuses[$status] ?? $status) ?></span><strong>Статус</strong></div>
    <div class="metric"><span><?= e($review['position_title_snapshot'] ?: 'не указана') ?></span><strong>Должность</strong></div>
    <div class="metric"><span><?= e($review['position_grade_snapshot'] ?: 'не указан') ?></span><strong>Грейд</strong></div>
    <div class="metric"><span><?= e($review['manager_name'] ?: 'не назначен') ?></span><strong>Руководитель</strong></div>
    <div class="metric"><span><?= e(!empty($review['response_deadline']) ? format_date((string) $review['response_deadline']) : 'без срока') ?></span><strong>Заполнить до</strong></div>
</section>

<?php if ($canManage && !$meetingDone): ?>
    <details class="panel"<?= empty($review['manager_id']) ? ' open' : '' ?>>
        <summary class="panel__head"><span>Настройка руководителя</span><small>Развернуть / свернуть</small></summary>
        <form class="form-grid" method="post" action="<?= url('/hr/reviews/' . (int) $review['id'] . '/manager-assign') ?>">
            <?= csrf_field() ?>
            <label class="form-grid__full"><span>Оценивающий руководитель</span><select name="manager_id" required><option value="">Выберите руководителя</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= selected((int) ($review['manager_id'] ?? 0), (int) $u['id']) ?>><?= e($u['name']) ?> · <?= e($u['position_title'] ?: role_label($u['role'] ?? '')) ?></option><?php endforeach; ?></select></label>
            <div class="form-grid__full form-actions"><button class="btn btn--red" type="submit">Сохранить руководителя</button></div>
        </form>
    </details>
<?php endif; ?>

<section class="panel review-procedure-note">
    <div class="panel__head"><h2>1. Анкета самооценки</h2><span><?= count($questions) ?> вопросов</span></div>
    <?php if ($isManager && empty($visibility['self'])): ?>
        <p class="review-blind-note">Ответы сотрудника скрыты до завершения вашей оценки по матрице. Ограничение действует на сервере, а не только на этой странице.</p>
    <?php elseif ($canEditQuestionnaire): ?>
        <p class="muted">Сохраняйте черновик в любой момент. Кнопка «Завершить этап» проверит, что заполнены все вопросы, и заблокирует дальнейшие изменения.</p>
        <form class="form-stack" method="post" action="<?= url('/performance-review/' . (int) $review['id'] . '/questionnaire/submit') ?>" data-performance-questionnaire data-review-id="<?= (int) $review['id'] ?>">
            <?= csrf_field() ?>
            <?php foreach ($questionSections as $section): ?>
                <fieldset class="review-question-section">
                    <legend><?= e($section['label']) ?></legend>
                    <?php foreach ($section['questions'] as $question): ?>
                        <?php $qid = (int) $question['id']; $value = (string) ($answers[$qid]['self']['answer_value'] ?? ''); ?>
                        <label><span><?= e($question['label']) ?><?= (int) ($question['is_required'] ?? 0) ? ' *' : '' ?></span><textarea name="answers[<?= $qid ?>]" rows="4"<?= (int) ($question['is_required'] ?? 0) ? ' required' : '' ?>><?= e($value) ?></textarea></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
            <div class="form-actions">
                <button class="btn btn-outline" type="submit" formnovalidate formaction="<?= url('/performance-review/' . (int) $review['id'] . '/questionnaire/draft') ?>">Сохранить черновик</button>
                <button class="btn btn--red" type="submit" onclick="return confirm('Завершить анкету? После отправки ответы нельзя будет изменить.')">Завершить этап 1</button>
            </div>
        </form>
    <?php else: ?>
        <?php foreach ($questionSections as $section): ?>
            <div class="review-answer-section">
                <h3><?= e($section['label']) ?></h3>
                <?php foreach ($section['questions'] as $question): ?>
                    <?php $qid = (int) $question['id']; $value = (string) ($answers[$qid]['self']['answer_value'] ?? ''); ?>
                    <article class="review-answer"><strong><?= e($question['label']) ?></strong><p><?= $value !== '' ? nl2br(e($value)) : '<span class="muted">Ответ не заполнен.</span>' ?></p></article>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$questionnaireDone): ?><p class="muted">Анкета ещё не завершена сотрудником.</p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel__head">
        <div><h2>2. Матрица компетенций</h2><span class="muted">Независимая оценка по шкале 1–5. Целевой уровень откроется только после отправки обеих оценок.</span></div>
    </div>

    <?php if (!$questionnaireDone): ?>
        <p class="muted">Матрица откроется после завершения анкеты самооценки.</p>
    <?php else: ?>
        <?php $editScope = $canEditSelfMatrix ? 'self' : ($canEditManagerMatrix ? 'manager' : null); ?>
        <?php if ($editScope !== null): ?>
            <p class="review-blind-note"><?= $editScope === 'manager' ? 'Оценка сотрудника и его анкета скрыты до завершения вашей оценки.' : 'Оценка руководителя откроется после завершения обеих сторон.' ?></p>
            <form method="post" action="<?= url('/performance-review/' . (int) $review['id'] . '/competencies/' . $editScope . '/submit') ?>">
                <?= csrf_field() ?>
                <div class="table-wrap">
                    <table class="data-table review-matrix-table" data-no-column-filters>
                        <thead><tr><th>Компетенция</th><th>Ваша оценка</th><th>Комментарий</th></tr></thead>
                        <tbody>
                        <?php foreach ($competencies as $key => $competency): ?>
                            <?php $current = $scoreFor($scores, $key, $editScope); ?>
                            <tr>
                                <td><strong><?= e($competency['name'] ?? '') ?></strong><small><?= e($competency['desc'] ?? '') ?></small><details><summary>Пояснения уровней</summary><ol class="review-levels"><?php foreach (($competency['levels'] ?? []) as $level => $description): ?><li><b><?= (int) $level ?> — <?= e($levels[$level] ?? '') ?></b><span><?= e($description) ?></span></li><?php endforeach; ?></ol></details></td>
                                <td><select name="scores[<?= e((string) $key) ?>]" required><option value="">—</option><?php for ($level = 1; $level <= 5; $level++): ?><option value="<?= $level ?>"<?= selected((int) ($current['score'] ?? 0), $level) ?>><?= $level ?> — <?= e($levels[$level] ?? '') ?></option><?php endfor; ?></select></td>
                                <td><textarea name="comments[<?= e((string) $key) ?>]" rows="2" placeholder="Необязательно"><?= e($current['comment'] ?? '') ?></textarea></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-actions">
                    <button class="btn btn-outline" type="submit" formnovalidate formaction="<?= url('/performance-review/' . (int) $review['id'] . '/competencies/' . $editScope . '/draft') ?>">Сохранить черновик</button>
                    <button class="btn btn--red" type="submit" onclick="return confirm('Завершить оценку? После отправки изменить её нельзя.')">Завершить оценку</button>
                </div>
            </form>
        <?php elseif (!empty($visibility['comparison']) || $canManage): ?>
            <div class="table-wrap">
                <table class="data-table review-matrix-table" data-no-column-filters>
                    <thead><tr><th>Компетенция</th><?php if ($showTarget): ?><th>Целевой уровень</th><?php endif; ?><th>Самооценка</th><th>Руководитель</th><th>Разница</th></tr></thead>
                    <tbody>
                    <?php foreach ($competencies as $key => $competency): ?>
                        <?php $selfScore = $scoreFor($scores, $key, 'self'); $managerScore = $scoreFor($scores, $key, 'manager'); $snapshotTarget = $managerScore['required_level_snapshot'] ?? $selfScore['required_level_snapshot'] ?? null; $required = is_numeric($snapshotTarget) ? (int) $snapshotTarget : $requiredFor($competency, $positionIndex); $delta = isset($selfScore['score'], $managerScore['score']) ? (int) $selfScore['score'] - (int) $managerScore['score'] : null; $deltaAbs = $delta === null ? null : abs($delta); $deltaClass = $deltaAbs === null ? '' : ($deltaAbs >= 2 ? 'review-deviation--high' : ($deltaAbs >= 1 ? 'review-deviation--medium' : 'review-deviation--aligned')); ?>
                        <tr class="<?= e($deltaClass) ?>">
                            <td><strong><?= e($competency['name'] ?? '') ?></strong><small><?= e($competency['desc'] ?? '') ?></small><details><summary>Пояснения уровней</summary><ol class="review-levels"><?php foreach (($competency['levels'] ?? []) as $level => $description): ?><li><b><?= (int) $level ?> — <?= e($levels[$level] ?? '') ?></b><span><?= e($description) ?></span></li><?php endforeach; ?></ol></details></td>
                            <?php if ($showTarget): ?><td><?= $required !== null ? (int) $required : '—' ?></td><?php endif; ?>
                            <td><?= isset($selfScore['score']) ? (int) $selfScore['score'] : '—' ?><?php if (!empty($selfScore['comment'])): ?><small><?= e($selfScore['comment']) ?></small><?php endif; ?></td>
                            <td><?= isset($managerScore['score']) ? (int) $managerScore['score'] : '—' ?><?php if (!empty($managerScore['comment'])): ?><small><?= e($managerScore['comment']) ?></small><?php endif; ?></td>
                            <td><span class="review-deviation <?= e($deltaClass) ?>"><?= $delta === null ? '—' : ($delta > 0 ? '+' . $delta : (string) $delta) ?></span><small><?= $deltaAbs === null ? '' : ($deltaAbs >= 2 ? 'существенное' : ($deltaAbs >= 1 ? 'умеренное' : 'совпадает')) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="review-blind-note">Сравнение откроется после того, как обе стороны завершат независимую оценку.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel__head"><div><h2>3. Итоговая встреча с руководителем</h2><span class="muted">Обсуждение результатов, зон роста и шагов на следующий год.</span></div></div>
    <?php if ($meetingDone): ?>
        <div class="review-meeting-result"><article><strong>Итоги встречи</strong><p><?= nl2br(e($review['meeting_notes'] ?? '')) ?></p></article><article><strong>Шаги на следующий год</strong><p><?= nl2br(e($review['next_year_actions'] ?? '')) ?></p></article></div>
    <?php elseif ($canCompleteMeeting): ?>
        <form class="form-grid" method="post" action="<?= url('/performance-review/' . (int) $review['id'] . '/meeting/complete') ?>">
            <?= csrf_field() ?>
            <label class="form-grid__full"><span>Итоги встречи *</span><textarea name="meeting_notes" rows="5" required></textarea></label>
            <label class="form-grid__full"><span>Шаги на следующий год *</span><textarea name="next_year_actions" rows="5" required></textarea></label>
            <div class="form-grid__full form-actions"><button class="btn btn--red" type="submit" onclick="return confirm('Зафиксировать встречу и закрыть ревью?')">Завершить Performance Review</button></div>
        </form>
    <?php else: ?>
        <p class="muted"><?= $selfMatrixDone && $managerMatrixDone ? 'Ожидается фиксация итогов назначенным руководителем' . (!empty($review['manager_name']) ? ' — ' . e((string) $review['manager_name']) . '.' : '.') : 'Этап откроется после завершения обеих оценок по матрице.' ?></p>
    <?php endif; ?>
</section>
