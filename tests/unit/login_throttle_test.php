<?php

declare(strict_types=1);

use App\Services\LoginThrottleService;

/**
 * Тесты файлового троттлинга логина. Используют уникальный логин и REMOTE_ADDR,
 * прибираются за собой через clear(). Проверяют порог и сброс.
 */

test('LoginThrottleService блокирует после превышения лимита и сбрасывается', function (): void {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7'; // тестовый IP (TEST-NET-3)
    $login = 'throttle_test_' . getmypid();

    LoginThrottleService::clear($login);
    assert_same(false, LoginThrottleService::tooManyAttempts($login), 'чистый ключ — не заблокирован');

    // 9 неудач — ещё под лимитом (порог 10)
    for ($i = 0; $i < 9; $i++) {
        LoginThrottleService::registerFailure($login);
    }
    assert_same(false, LoginThrottleService::tooManyAttempts($login), '9 < 10 — ещё можно');

    // 10-я неудача — лимит достигнут
    LoginThrottleService::registerFailure($login);
    assert_same(true, LoginThrottleService::tooManyAttempts($login), '10 >= 10 — блок');

    // успешный вход (clear) снимает блок
    LoginThrottleService::clear($login);
    assert_same(false, LoginThrottleService::tooManyAttempts($login), 'после clear() — снова можно');
});

test('LoginThrottleService разделяет ключи по логину', function (): void {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
    $a = 'throttle_a_' . getmypid();
    $b = 'throttle_b_' . getmypid();
    LoginThrottleService::clear($a);
    LoginThrottleService::clear($b);

    for ($i = 0; $i < 10; $i++) {
        LoginThrottleService::registerFailure($a);
    }
    assert_same(true, LoginThrottleService::tooManyAttempts($a), 'логин A заблокирован');
    assert_same(false, LoginThrottleService::tooManyAttempts($b), 'логин B не затронут');

    LoginThrottleService::clear($a);
    LoginThrottleService::clear($b);
});
