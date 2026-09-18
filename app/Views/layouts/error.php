<section class="panel">
    <div class="panel__head">
        <h2><?= e($title ?? 'Страница недоступна') ?></h2>
    </div>
    <div class="empty-state">
        <strong>Что можно сделать</strong>
        <p><?= e($message ?? 'Не удалось открыть этот раздел. Проверьте ссылку или вернитесь в «Мой день».') ?></p>
        <?php if (!empty($reference)): ?><p class="error-reference">Код обращения: <code><?= e($reference) ?></code></p><?php endif; ?>
        <a class="btn" href="<?= url('/my-day') ?>">Вернуться в «Мой день»</a>
    </div>
</section>
