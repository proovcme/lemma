<?php
$matrix = $matrix ?? ['levels' => [], 'positions' => [], 'competencies' => []];
$levels = $matrix['levels'] ?? [];
$positions = $matrix['positions'] ?? [];
$competencies = $matrix['competencies'] ?? [];
?>

<section class="analytics-module">
    <div class="analytics-head">
        <div>
            <span class="muted">Справочник</span>
            <h2>Матрица компетенций</h2>
        </div>
    </div>
    <p class="muted">
        Уровни:
        <?php foreach ($levels as $n => $label): ?>
            <strong><?= (int) $n ?></strong> — <?= e($label) ?><?= $n < count($levels) ? ' · ' : '' ?>
        <?php endforeach; ?>
    </p>
</section>

<section class="panel">
    <div class="panel__head">
        <h2>Требуемые уровни по должностям</h2>
        <span class="muted"><?= count($competencies) ?> компетенций · <?= count($positions) ?> должностей</span>
    </div>
    <div class="table-wrap">
        <table class="data-table analytics-table">
            <thead>
                <tr>
                    <th class="competency-matrix__name-column">Компетенция</th>
                    <?php foreach ($positions as $p): ?>
                        <th title="<?= e($p['title']) ?>"><?= e($p['title']) ?><br><small class="muted"><?= e($p['grade']) ?></small></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($competencies as $num => $c): ?>
                    <?php
                    $levelItems = array_filter(
                        (array) ($c['levels'] ?? []),
                        static fn (mixed $text): bool => trim((string) $text) !== ''
                    );
                    ?>
                    <tr>
                        <td>
                            <div class="competency-matrix__item">
                                <strong><?= (int) $num ?>. <?= e($c['name']) ?></strong>
                                <?php if (trim((string) ($c['desc'] ?? '')) !== '' || $levelItems): ?>
                                    <details class="competency-matrix__details">
                                        <summary>Пояснения</summary>
                                        <?php if (trim((string) ($c['desc'] ?? '')) !== ''): ?>
                                            <p><?= e($c['desc']) ?></p>
                                        <?php endif; ?>
                                        <?php if ($levelItems): ?>
                                            <div class="competency-matrix__levels" aria-label="Пояснения по уровням компетенции">
                                                <?php foreach ($levelItems as $ln => $ltext): ?>
                                                    <div class="competency-matrix__level">
                                                        <span><?= (int) $ln ?> · <?= e($levels[$ln] ?? '') ?></span>
                                                        <em><?= e($ltext) ?></em>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php foreach ($positions as $idx => $p): ?>
                            <?php $lvl = $c['required'][$idx] ?? null; ?>
                            <td class="competency-matrix__value"><?= $lvl !== null ? (int) $lvl : '—' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
