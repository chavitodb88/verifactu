<?php

declare(strict_types=1);

namespace App\Helpers;

final class VerifactuFormatter
{
    /**
     * Convierte 'YYYY-MM-DD' a 'DD-MM-YYYY' para AEAT.
     */
    public static function toAeatDate(string $yyyy_mm_dd): string
    {
        $p = explode('-', $yyyy_mm_dd);

        // Si no tiene exactamente 3 partes, lo devolvemos igual (robusto)
        return count($p) === 3
            ? "{$p[2]}-{$p[1]}-{$p[0]}"
            : $yyyy_mm_dd;
    }

    /**
     * Formatea números con 2 decimales, punto decimal, sin miles.
     * Requerido por AEAT: 1234.50
     */
    public static function fmt2($n): string
    {
        return number_format((float)$n, 2, '.', '');
    }
}
