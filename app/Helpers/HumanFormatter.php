<?php

declare(strict_types=1);

namespace App\Helpers;

final class HumanFormatter
{
    /**
     * Formato español: 1234.5 → "1.234,50"
     */
    public static function money(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }
}
