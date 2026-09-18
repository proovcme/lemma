<?php

declare(strict_types=1);

use App\Services\ModelFolderService;

test('ModelFolderService сканирует папку моделей и предпочитает готовый FRAG', function (): void {
    $root = sys_get_temp_dir() . '/locia-model-folder-test-' . getmypid();
    cleanup_model_folder_test_dir($root);
    mkdir($root . '/nested', 0775, true);
    file_put_contents($root . '/building.ifc', 'ifc');
    file_put_contents($root . '/building.frag', 'frag');
    file_put_contents($root . '/nested/archive.ifc.zip', 'zip');
    file_put_contents($root . '/nested/section.ifczip', 'zip');
    file_put_contents($root . '/nested/AP.nwc', 'nwc');
    file_put_contents($root . '/ignore.txt', 'text');
    mkdir($root . '/blocked', 0775, true);
    @chmod($root . '/blocked', 0000);

    $service = new ModelFolderService();
    $models = $service->scan($root);
    $scan = $service->scanDetailed($root);

    assert_same(3, count($models), 'одинаковые IFC/FRAG считаются одной моделью');
    assert_same('building.frag', $models[0]['rel']);
    assert_same('frag', $models[0]['kind']);
    assert_true((int) ($models[0]['mtime'] ?? 0) > 0, 'модель содержит дату изменения файла');
    assert_same('nested/archive.ifc.zip', $models[1]['rel']);
    assert_same('ifczip', $models[1]['kind']);
    assert_same('ifc.zip', $models[1]['ext']);
    assert_same('nested/section.ifczip', $models[2]['rel']);
    assert_same('ifczip', $models[2]['kind']);
    assert_same(3, count($scan['models']), 'ошибка вложенной папки не обнуляет найденные модели');
    assert_true((bool) $scan['accessible'], 'корень папки доступен');
    assert_same(6, (int) ($scan['files_seen'] ?? 0), 'диагностика считает увиденные файлы без дублей при двойном обходе папки');
    assert_true((int) (($scan['extension_counts']['ifc'] ?? 0)) >= 1, 'диагностика считает IFC');
    assert_true((int) (($scan['extension_counts']['ifc.zip'] ?? 0)) >= 1, 'диагностика считает IFC ZIP');
    assert_same(1, (int) (($scan['extension_counts']['nwc'] ?? 0)), 'диагностика видит Navisworks-файлы отдельно от моделей Атласа');
    assert_true(in_array('building.frag', (array) ($scan['supported_files'] ?? []), true), 'диагностика показывает найденные поддерживаемые модели');
    assert_same(realpath($root . '/building.frag'), $service->resolve($root, 'building.frag'));
    assert_same(null, $service->resolve($root, '../outside.frag'), 'нельзя выйти за корень папки');
    assert_same(null, $service->resolve($root, 'ignore.txt'), 'не модельные расширения не отдаются');
    assert_same('application/octet-stream', $service->mimeFor($root . '/building.frag'));

    $cachePath = $service->fragmentCachePath('Project 42', 'nested/section.ifczip', $root . '/nested/section.ifczip');
    assert_true(is_string($cachePath) && str_contains($cachePath, '/storage/atlas-fragments/project-42-'), 'cache path включает безопасный scope');

    @chmod($root . '/blocked', 0775);
    cleanup_model_folder_test_dir($root);
});

function cleanup_model_folder_test_dir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    @chmod($path, 0775);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        @chmod($item->getPathname(), 0775);
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}
