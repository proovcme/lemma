<?php
/** @var string $type */
/** @var array{label: string, subject: string, body: string} $template */
/** @var array<string, string> $variables */
$type      = $type ?? '';
$template  = $template ?? ['label' => '', 'subject' => '', 'body' => ''];
$variables = $variables ?? [];
?>
<section class="panel">
    <div class="panel__head">
        <h1><?= e($template['label']) ?></h1>
    </div>
    <form class="form-grid" method="post" action="<?= url('/admin/notifications/' . rawurlencode($type) . '/edit') ?>">
        <?= csrf_field() ?>

        <label class="form-grid__full" for="nt-subject">
            <span>Тема письма</span>
            <input id="nt-subject" class="mail-template-code" type="text" name="subject" value="<?= e($template['subject']) ?>" required>
        </label>

        <label class="form-grid__full" for="nt-body">
            <span>Тело письма</span>
            <textarea id="nt-body" class="mail-template-code" name="body" rows="14" required><?= e($template['body']) ?></textarea>
        </label>

        <div class="form-actions form-grid__full">
            <button class="btn btn--red" type="submit">Сохранить</button>
            <a class="btn btn-outline" href="<?= url('/admin/notifications') ?>">Отмена</a>
        </div>
    </form>
</section>

<?php if ($variables): ?>
    <section class="panel">
        <div class="panel__head">
            <h2>Доступные переменные</h2>
        </div>
        <div class="mail-template-vars">
            <p class="muted">Кликните по переменной, чтобы скопировать, затем вставьте в тему или тело.</p>
            <table class="data-table data-table--compact">
                <tbody>
                    <?php foreach ($variables as $var => $desc): ?>
                    <tr>
                        <td>
                            <button
                                class="copy-code"
                                type="button"
                                title="Нажмите чтобы скопировать"
                                data-copy-text="<?= e($var) ?>"
                            ><?= e($var) ?></button>
                        </td>
                        <td class="muted"><?= e($desc) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
