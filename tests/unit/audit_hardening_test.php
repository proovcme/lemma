<?php

declare(strict_types=1);

use App\Controllers\ProjectController;
use App\Services\MspImportService;
use App\Services\ProjectFolderService;
use App\Services\SbcCatalogService;

test('offline seed СБЦ импортируется из bundled snapshot без внешней сети', function (): void {
    $seed = require __DIR__ . '/../../config/sbc_seed.php';
    assert_true(count($seed['items'] ?? []) >= 300, 'snapshot должен содержать полноценный набор позиций, а не демо-строки');

    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
    $pdo->exec('INSERT INTO users (id) VALUES (1)');
    $pdo->exec("CREATE TABLE sbc_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reference_hash TEXT NOT NULL UNIQUE,
        collection_code TEXT NOT NULL DEFAULT '',
        collection_name TEXT NOT NULL,
        edition TEXT,
        table_code TEXT,
        item_code TEXT NOT NULL DEFAULT '',
        work_name TEXT NOT NULL,
        unit TEXT,
        base_price REAL NOT NULL DEFAULT 0,
        price_level TEXT,
        default_labor_hours REAL NOT NULL DEFAULT 0,
        formula TEXT,
        note TEXT,
        source_ref TEXT,
        justification_template TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE sbc_indices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_key TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        index_value REAL NOT NULL DEFAULT 1,
        source_ref TEXT,
        source_date TEXT,
        comment TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        updated_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    )");

    $result = (new SbcCatalogService())->importBundled($pdo, 1);

    assert_same(count($seed['items']), (int) $result['created']);
    assert_same(0, (int) $result['skipped']);
    assert_same(count($seed['items']), (int) $pdo->query('SELECT COUNT(*) FROM sbc_items')->fetchColumn());
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM sbc_indices')->fetchColumn());
});

test('MSP import parser handles ISO duration without turning zero into planned zero', function (): void {
    $method = new ReflectionMethod(MspImportService::class, 'parseWorkHours');
    $service = new MspImportService();

    $hours = $method->invoke($service, 'P1W2DT3H30M15S');
    assert_true(abs((float) $hours - 59.5041666667) < 0.0001, 'weeks/days/hours/minutes/seconds should be converted to hours');
    assert_same(null, $method->invoke($service, 'PT0H0M0S'), 'zero work should fall back to date-based working hours');
    assert_same(null, $method->invoke($service, 'not-a-duration'));
});

test('обычная форма проекта не может перевести проект в архив', function (): void {
    $oldPost = $_POST;
    $_POST = [
        'code' => 'P-1',
        'title' => 'Project',
        'status' => 'archived',
    ];

    try {
        $method = new ReflectionMethod(ProjectController::class, 'payload');
        $payload = $method->invoke(new ProjectController());
        assert_same('active', $payload['status']);
    } finally {
        $_POST = $oldPost;
    }
});

test('folder open validates path and does not require server-side file manager launch', function (): void {
    $root = sys_get_temp_dir() . '/locia-folder-open-' . bin2hex(random_bytes(4));
    $child = $root . '/models';
    mkdir($child, 0775, true);

    try {
        $opened = (new ProjectFolderService())->open($root, 'models');
        assert_same((string) realpath($child), (string) realpath($opened));
    } finally {
        @rmdir($child);
        @rmdir($root);
    }
});

test('локальный файл модели разрешается только внутри папок проекта', function (): void {
    $root = sys_get_temp_dir() . '/locia-model-root-' . bin2hex(random_bytes(4));
    $inside = $root . '/model.ifc';
    $outside = sys_get_temp_dir() . '/locia-model-outside-' . bin2hex(random_bytes(4)) . '.ifc';
    mkdir($root, 0775, true);
    file_put_contents($inside, 'IFC');
    file_put_contents($outside, 'IFC');

    try {
        $method = new ReflectionMethod(ProjectController::class, 'isProjectModelPathAllowed');
        $controller = new ProjectController();
        $project = ['model_folder_url' => $root, 'file_folder_url' => ''];

        assert_same(true, $method->invoke($controller, $inside, $project));
        assert_same(false, $method->invoke($controller, $outside, $project));
    } finally {
        @unlink($inside);
        @unlink($outside);
        @rmdir($root);
    }
});

test('публичная модель разрешается только из общей папки Атласа', function (): void {
    $oldConfig = $GLOBALS['config'];
    $publicRoot = sys_get_temp_dir() . '/locia-public-model-root-' . bin2hex(random_bytes(4));
    $projectRoot = sys_get_temp_dir() . '/locia-project-model-root-' . bin2hex(random_bytes(4));
    $outside = sys_get_temp_dir() . '/locia-public-model-outside-' . bin2hex(random_bytes(4)) . '.ifc';
    $inside = $publicRoot . '/shared.ifc';
    mkdir($publicRoot, 0775, true);
    mkdir($projectRoot, 0775, true);
    file_put_contents($inside, 'IFC');
    file_put_contents($outside, 'IFC');
    $GLOBALS['config']['app']['default_model_folder'] = $publicRoot;

    try {
        $method = new ReflectionMethod(ProjectController::class, 'resolveModelFilePath');
        $controller = new ProjectController();

        $publicModel = [
            'model_scope' => 'public',
            'model_folder_url' => $projectRoot,
            'file_folder_url' => '',
        ];
        $projectModel = [
            'model_scope' => 'project',
            'model_folder_url' => $projectRoot,
            'file_folder_url' => '',
        ];

        assert_same((string) realpath($inside), (string) realpath((string) $method->invoke($controller, 'shared.ifc', $publicModel)));
        assert_same(null, $method->invoke($controller, $outside, $publicModel));
        assert_same(null, $method->invoke($controller, 'shared.ifc', $projectModel));
    } finally {
        $GLOBALS['config'] = $oldConfig;
        @unlink($inside);
        @unlink($outside);
        @rmdir($publicRoot);
        @rmdir($projectRoot);
    }
});
