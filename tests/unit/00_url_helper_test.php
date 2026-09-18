<?php

declare(strict_types=1);

test('возврат на форму по абсолютному HTTP_REFERER остаётся внутренним маршрутом', function (): void {
    assert_same(
        '/tasks/new?project_id=2',
        url('http://192.0.2.10:57391/tasks/new?project_id=2'),
        'абсолютный referer не должен превращаться в /http://... при redirect()'
    );
});
