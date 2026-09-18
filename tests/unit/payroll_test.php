<?php

declare(strict_types=1);

use App\Services\PayrollService;

/**
 * Тесты расчёта ставки чел-часа модуля ФОТ (чистая логика, без БД).
 */

test('PayrollService::hourlyRate считает из суммы окладных компонент / норму', function (): void {
    // (50000 + 50000 + 60000 + 0) / 176 = 909.09
    $a = ['base_oklad' => 50000, 'base_nadbavka' => 50000, 'premium' => 60000, 'project_nadbavka' => 0, 'rate_override' => null];
    assert_same(909.09, PayrollService::hourlyRate($a, 176.0));

    // другая норма
    $b = ['base_oklad' => 176000, 'base_nadbavka' => 0, 'premium' => 0, 'project_nadbavka' => 0, 'rate_override' => null];
    assert_same(1000.0, PayrollService::hourlyRate($b, 176.0));
});

test('PayrollService::hourlyRate уважает rate_override', function (): void {
    $a = ['base_oklad' => 100000, 'base_nadbavka' => 0, 'premium' => 0, 'project_nadbavka' => 0, 'rate_override' => 682.16];
    assert_same(682.16, PayrollService::hourlyRate($a, 176.0), 'явная ставка перекрывает расчёт');

    // override = 0 или null → считаем из оклада
    $b = ['base_oklad' => 88000, 'base_nadbavka' => 0, 'premium' => 0, 'project_nadbavka' => 0, 'rate_override' => 0];
    assert_same(500.0, PayrollService::hourlyRate($b, 176.0), 'нулевой override игнорируется');
});

test('PayrollService::hourlyRate не делит на ноль', function (): void {
    $a = ['base_oklad' => 100000, 'base_nadbavka' => 0, 'premium' => 0, 'project_nadbavka' => 0, 'rate_override' => null];
    assert_same(0.0, PayrollService::hourlyRate($a, 0.0));
});

test('PayrollService::hourlyRate суммирует проектную надбавку', function (): void {
    // (100000 + 10000 + 20000 + 46000) / 176 = 1000
    $a = ['base_oklad' => 100000, 'base_nadbavka' => 10000, 'premium' => 20000, 'project_nadbavka' => 46000, 'rate_override' => null];
    assert_same(1000.0, PayrollService::hourlyRate($a, 176.0));
});
