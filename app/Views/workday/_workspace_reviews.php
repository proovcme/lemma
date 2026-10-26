<?php if (!empty($performanceReviewActions['personal']) || !empty($performanceReviewActions['manager_ready'])): ?>
    <section class="workday-review-callouts" aria-label="Действия Performance Review">
        <?php foreach (($performanceReviewActions['personal'] ?? []) as $review): ?>
            <?php
            $reviewAction = empty($review['self_questionnaire_submitted_at'])
                ? 'Заполнить анкету'
                : (empty($review['self_matrix_submitted_at']) ? 'Оценить компетенции' : 'Открыть результаты');
            ?>
            <article class="panel workday-review-callout">
                <div class="workday-review-callout__mark" aria-hidden="true">PR</div>
                <div>
                    <span><?= ($review['cycle_kind'] ?? 'annual') === 'test' ? 'Тестовый Performance Review' : 'Performance Review открыт' ?></span>
                    <h2><?= e($review['cycle_title'] ?? '') ?></h2>
                    <p>Ваш этап уже запущен<?= !empty($review['response_deadline']) ? '. Заполните до ' . e(format_date((string) $review['response_deadline'])) : '' ?>.</p>
                </div>
                <a class="btn btn--red" href="<?= url('/performance-review/' . (int) $review['id']) ?>"><?= e($reviewAction) ?></a>
            </article>
        <?php endforeach; ?>
        <?php if ($managerMatrixActions !== []): ?>
            <article class="panel workday-review-callout workday-review-callout--manager">
                <div class="workday-review-callout__mark" aria-hidden="true"><?= count($managerMatrixActions) ?></div>
                <div>
                    <span>Оценки сотрудников</span>
                    <h2>Самооценка завершена</h2>
                    <p>Можно провести независимую оценку. Ответы сотрудников останутся скрыты до отправки вашей оценки.</p>
                </div>
                <a class="btn btn--red" href="<?= url('/performance-review/manager') ?>">Перейти к оценкам</a>
            </article>
        <?php endif; ?>
        <?php if ($managerMeetingActions !== []): ?>
            <article class="panel workday-review-callout workday-review-callout--manager">
                <div class="workday-review-callout__mark" aria-hidden="true"><?= count($managerMeetingActions) ?></div>
                <div>
                    <span>Performance Review сотрудников</span>
                    <h2>Зафиксировать итоговую встречу</h2>
                    <p>Обе независимые оценки завершены. Заполните итоги встречи и шаги сотрудника на следующий год.</p>
                </div>
                <a class="btn btn--red" href="<?= url('/performance-review/manager') ?>">Перейти к итогам</a>
            </article>
        <?php endif; ?>
    </section>
<?php endif; ?>

