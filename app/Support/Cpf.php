<?php

namespace App\Support;

class Cpf
{
    public static function digits(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return is_string($digits) && $digits !== '' ? $digits : null;
    }

    public static function isValid(?string $value): bool
    {
        $digits = self::digits($value);

        if ($digits === null || strlen($digits) !== 11) {
            return false;
        }

        if (preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        for ($length = 9; $length < 11; $length++) {
            $sum = 0;

            for ($index = 0; $index < $length; $index++) {
                $sum += (int) $digits[$index] * ($length + 1 - $index);
            }

            $checkDigit = ((10 * $sum) % 11) % 10;

            if ((int) $digits[$length] !== $checkDigit) {
                return false;
            }
        }

        return true;
    }
}
