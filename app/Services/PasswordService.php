<?php

declare(strict_types=1);

namespace App\Services;

final class PasswordService
{
    private const BASE = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    private const SPECIAL = '!#$%&*+-=?@';

    public static function generate(int $length = 12, bool $special = false): string
    {
        $length = max(8, min(20, $length));
        $alphabet = self::BASE . ($special ? self::SPECIAL : '');
        $password = '';
        $max = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
