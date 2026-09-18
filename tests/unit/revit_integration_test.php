<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\RevitIntegrationService;

function revit_test_context(): array
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY, name TEXT, email TEXT, role TEXT, department TEXT, is_active INTEGER
    )');
    $pdo->exec('CREATE TABLE projects (
        id INTEGER PRIMARY KEY, code TEXT, title TEXT, status TEXT, kind TEXT, gip_user_id INTEGER, rp_user_id INTEGER
    )');
    $pdo->exec('CREATE TABLE project_members (
        id INTEGER PRIMARY KEY, project_id INTEGER, user_id INTEGER, active INTEGER
    )');
    $pdo->exec('CREATE TABLE revit_activation_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, code_hash TEXT UNIQUE, expires_at TEXT, used_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE revit_api_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, device_name TEXT, plugin_version TEXT,
        last_used_at TEXT, revoked_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE project_model_series (
        id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, name TEXT, discipline TEXT, next_version_number INTEGER DEFAULT 1,
        current_version_id INTEGER, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(project_id, name)
    )');
    $pdo->exec('CREATE TABLE project_model_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, model_series_id INTEGER, version_number INTEGER, file_relative_path TEXT,
        original_filename TEXT, byte_size INTEGER, sha256 TEXT, comment TEXT, revit_version TEXT, document_title TEXT,
        document_unique_id TEXT, view_name TEXT, view_unique_id TEXT, ifc_profile TEXT, created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(model_series_id, version_number)
    )');
    $pdo->exec('CREATE TABLE revit_upload_sessions (
        id TEXT PRIMARY KEY, model_series_id INTEGER, user_id INTEGER, idempotency_key TEXT, original_filename TEXT,
        expected_size INTEGER, expected_sha256 TEXT, metadata_json TEXT, chunk_size INTEGER, chunk_count INTEGER,
        received_chunks_json TEXT, status TEXT DEFAULT "uploading", completed_version_id INTEGER, expires_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, idempotency_key)
    )');
    $pdo->exec('CREATE TABLE activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT, project_id INTEGER, task_id INTEGER, user_id INTEGER,
        action TEXT, title TEXT, body TEXT, meta_json TEXT
    )');
    $pdo->exec("INSERT INTO users VALUES (1, 'ГИП Тест', 'gip@example.local', 'gip', 'ГИП', 1)");
    $pdo->exec("INSERT INTO projects VALUES (10, 'P-10', 'Тестовый проект', 'active', 'project', 1, NULL)");
    $pdo->exec('INSERT INTO project_members VALUES (1, 10, 1, 1)');
    Database::useConnection($pdo);

    $root = sys_get_temp_dir() . '/locia-revit-test-' . bin2hex(random_bytes(4));
    mkdir($root, 0770, true);
    $old = $GLOBALS['config'];
    $GLOBALS['config']['db']['connection'] = 'sqlite';
    $GLOBALS['config']['revit']['storage_dir'] = $root . '/models';
    $GLOBALS['config']['revit']['upload_dir'] = $root . '/uploads';
    $GLOBALS['config']['revit']['chunk_bytes'] = 1024 * 1024;
    $GLOBALS['config']['revit']['max_file_bytes'] = 4 * 1024 * 1024;
    return [$pdo, new RevitIntegrationService($pdo), $root, $old];
}

function revit_test_remove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (glob($path . '/*') ?: [] as $item) {
        is_dir($item) ? revit_test_remove($item) : @unlink($item);
    }
    @rmdir($path);
}

test('Revit одноразовый код обменивается на отзывной Bearer token', function (): void {
    [$pdo, $service, $root, $old] = revit_test_context();
    try {
        $code = $service->issueActivationCode(1);
        assert_same(8, strlen($code));
        $result = $service->exchangeActivationCode($code, 'TEST-PC', '1.0.0');
        assert_true(strlen((string) $result['token']) >= 40);
        assert_same(1, (int) $service->authenticate((string) $result['token'])['id']);
        assert_throws(static fn () => $service->exchangeActivationCode($code, 'OTHER-PC', '1.0.0'));
        $tokenRow = $service->tokensForUser(1)[0];
        $service->revokeToken(1, (int) $tokenRow['id']);
        assert_throws(static fn () => $service->authenticate((string) $result['token']));
    } finally {
        $GLOBALS['config'] = $old;
        Database::reset();
        revit_test_remove($root);
    }
});

test('Revit IFC загружается частями, получает неизменяемую v001 и становится текущим', function (): void {
    [$pdo, $service, $root, $old] = revit_test_context();
    try {
        $user = $pdo->query('SELECT * FROM users WHERE id = 1')->fetch();
        $model = $service->createModelSeries($user, 10, 'АР', 'АР');
        $bytes = str_repeat('A', 1024 * 1024) . 'tail';
        $upload = $service->startUpload($user, (int) $model['id'], [
            'filename' => 'AR.ifc',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'idempotency_key' => 'revit-test-001',
            'comment' => 'Первая публикация',
            'revit_version' => '2025',
            'view_name' => '{3D Лемма}',
            'ifc_profile' => 'Лемма IFC4',
        ]);
        assert_same(2, (int) $upload['chunk_count']);
        $service->storeChunk((string) $upload['id'], 1, 0, substr($bytes, 0, 1024 * 1024));
        $service->storeChunk((string) $upload['id'], 1, 1, substr($bytes, 1024 * 1024));
        $version = $service->completeUpload((string) $upload['id'], $user);
        assert_same('v001', $version['version_code']);
        assert_same(hash('sha256', $bytes), $version['sha256']);
        assert_true(is_file($root . '/models/' . $version['file_relative_path']));
        $series = $service->modelSeriesWithVersions(10)[0];
        assert_same((int) $version['id'], (int) $series['current_version_id']);
        assert_same(1, count($series['versions']));
        $same = $service->completeUpload((string) $upload['id'], $user);
        assert_same((int) $version['id'], (int) $same['id'], 'повтор complete должен быть идемпотентным');
    } finally {
        $GLOBALS['config'] = $old;
        Database::reset();
        revit_test_remove($root);
    }
});
