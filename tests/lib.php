<?php

declare(strict_types=1);

/**
 * Микро-харнесс для юнит-тестов на чистом PHP (без composer/PHPUnit — контур офлайновый).
 * Стиль согласован с scripts/*_smoke.php: запускается одним `php tests/run.php`,
 * печатает итог и отдаёт корректный exit-code (0 — все зелёные, 1 — есть падения).
 *
 * API: test('имя', fn), внутри — assert_eq / assert_true / assert_same / assert_throws.
 */

final class TestRunner
{
    /** @var array<int, array{name: string, ok: bool, error: ?string}> */
    public static array $results = [];
    private static ?string $current = null;
    private static int $assertions = 0;

    public static function run(string $name, callable $fn): void
    {
        self::$current = $name;
        try {
            $fn();
            self::$results[] = ['name' => $name, 'ok' => true, 'error' => null];
        } catch (\AssertionError $e) {
            self::$results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            self::$results[] = [
                'name' => $name,
                'ok' => false,
                'error' => 'НЕОЖИДАННОЕ ИСКЛЮЧЕНИЕ: ' . $e->getMessage()
                    . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
            ];
        }
        self::$current = null;
    }

    public static function bumpAssertion(): void
    {
        self::$assertions++;
    }

    public static function summary(): int
    {
        $passed = 0;
        $failed = 0;
        foreach (self::$results as $r) {
            if ($r['ok']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        echo PHP_EOL;
        foreach (self::$results as $r) {
            if ($r['ok']) {
                echo "  \033[32mPASS\033[0m  " . $r['name'] . PHP_EOL;
            } else {
                echo "  \033[31mFAIL\033[0m  " . $r['name'] . PHP_EOL;
                echo "        " . $r['error'] . PHP_EOL;
            }
        }

        echo PHP_EOL;
        echo sprintf(
            "Тестов: %d  |  Зелёных: %d  |  Падений: %d  |  Проверок: %d%s",
            count(self::$results),
            $passed,
            $failed,
            self::$assertions,
            PHP_EOL
        );

        if ($failed === 0) {
            echo "\033[32mВсе юнит-тесты прошли.\033[0m" . PHP_EOL;
            return 0;
        }

        echo "\033[31mЕсть падения юнит-тестов.\033[0m" . PHP_EOL;
        return 1;
    }
}

function test(string $name, callable $fn): void
{
    TestRunner::run($name, $fn);
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assert_eq($expected, $actual, string $message = ''): void
{
    TestRunner::bumpAssertion();
    if ($expected != $actual) {
        throw new \AssertionError(
            ($message !== '' ? $message . ' — ' : '')
            . 'ожидалось ' . var_export($expected, true)
            . ', получено ' . var_export($actual, true)
        );
    }
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assert_same($expected, $actual, string $message = ''): void
{
    TestRunner::bumpAssertion();
    if ($expected !== $actual) {
        throw new \AssertionError(
            ($message !== '' ? $message . ' — ' : '')
            . 'ожидалось (строго) ' . var_export($expected, true)
            . ', получено ' . var_export($actual, true)
        );
    }
}

function assert_true($value, string $message = ''): void
{
    TestRunner::bumpAssertion();
    if ($value !== true) {
        throw new \AssertionError(
            ($message !== '' ? $message . ' — ' : '') . 'ожидалось true, получено ' . var_export($value, true)
        );
    }
}

function assert_throws(callable $fn, string $message = ''): void
{
    TestRunner::bumpAssertion();
    try {
        $fn();
    } catch (\Throwable $e) {
        return;
    }
    throw new \AssertionError(
        ($message !== '' ? $message . ' — ' : '') . 'ожидалось исключение, но его не было'
    );
}
