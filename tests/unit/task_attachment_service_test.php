<?php

declare(strict_types=1);

use App\Services\TaskAttachmentService;

test('вложения задачи проходят allowlist, сохраняются вне public и удаляются', function (): void {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        user_id INTEGER,
        filename TEXT NOT NULL,
        path TEXT NOT NULL,
        size INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec("INSERT INTO users (id, name) VALUES (7, 'Тестовый сотрудник')");

    $source = tempnam(sys_get_temp_dir(), 'locia-attachment-');
    file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    $prepared = TaskAttachmentService::validateIncoming([
        'name' => ['Фото площадки.png'],
        'tmp_name' => [$source],
        'error' => [UPLOAD_ERR_OK],
        'size' => [filesize($source)],
        'type' => ['image/png'],
    ]);
    $rows = TaskAttachmentService::storePrepared(987654321, 7, $prepared, $pdo);
    $stored = TaskAttachmentService::forTask(987654321, $pdo);

    assert_same(1, count($rows), 'должен сохраниться один файл');
    assert_same('Фото площадки.png', $stored[0]['filename']);
    assert_true((bool) $stored[0]['is_image'], 'PNG должен открываться как изображение');
    assert_true(!str_starts_with((string) $stored[0]['path'], 'public/'), 'файл нельзя хранить в публичном каталоге');
    assert_true(is_file(TaskAttachmentService::absolutePath((string) $stored[0]['path'])), 'файл должен существовать в защищённом storage');

    TaskAttachmentService::delete(987654321, (int) $rows[0]['id'], $pdo);
    assert_same([], TaskAttachmentService::forTask(987654321, $pdo));
    @unlink($source);
    @rmdir(BASE_PATH . '/storage/uploads/tasks/987654321');
});

test('вложения отвергают исполняемый файл и обход каталога', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'locia-attachment-bad-');
    file_put_contents($source, '<?php echo 1;');

    assert_throws(static fn () => TaskAttachmentService::validateIncoming([
        'name' => ['payload.php'],
        'tmp_name' => [$source],
        'error' => [UPLOAD_ERR_OK],
        'size' => [filesize($source)],
        'type' => ['application/x-httpd-php'],
    ]), 'PHP нельзя принимать как вложение');
    assert_throws(static fn () => TaskAttachmentService::absolutePath('../.env'), 'нельзя выйти из каталога загрузок');
    @unlink($source);
});
