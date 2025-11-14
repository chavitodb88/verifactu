<?php

declare(strict_types=1);

namespace App\Services;

final class SpanishIdValidator
{
    public static function isValid(string $value): bool
    {
        $value = strtoupper(trim($value));

        return self::isValidDni($value)
            || self::isValidNie($value)
            || self::isValidCif($value);
    }

    private static function isValidDni(string $dni): bool
    {
        // Formato: 8 dígitos + letra
        if (!preg_match('/^([0-9]{8})([A-Z])$/', $dni, $m)) {
            return false;
        }

        $num = (int) $m[1];
        $letter = $m[2];
        $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';

        return $letters[$num % 23] === $letter;
    }

    private static function isValidNie(string $nie): bool
    {
        // Formato: X/Y/Z + 7 dígitos + letra
        if (!preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $nie)) {
            return false;
        }

        $map = ['X' => '0', 'Y' => '1', 'Z' => '2'];
        $first = $nie[0];
        $replaced = $map[$first] . substr($nie, 1, 7); // 0xxxxx...

        $dniLike = $replaced . $nie[8]; // "0xxxxxxxL"
        return self::isValidDni($dniLike);
    }

    private static function isValidCif(string $cif): bool
    {
        // Letra + 7 dígitos + control (dígito o letra)
        if (!preg_match('/^[A-HJNP-SUVW][0-9]{7}[0-9A-J]$/', $cif)) {
            return false;
        }

        $letter = $cif[0];
        $digits = substr($cif, 1, 7);
        $control = $cif[8];

        // Suma posiciones pares (2,4,6)
        $sumEven = (int)$digits[1] + (int)$digits[3] + (int)$digits[5];

        // Suma posiciones impares (1,3,5,7) *2 y sumar dígitos
        $sumOdd = 0;
        foreach ([0, 2, 4, 6] as $i) {
            $n = (int)$digits[$i] * 2;
            $sumOdd += intdiv($n, 10) + ($n % 10);
        }

        $total = $sumEven + $sumOdd;
        $controlDigit = (10 - ($total % 10)) % 10;
        $controlLetter = 'JABCDEFGHI'[$controlDigit];

        // Tipos según primera letra:
        // - Siempre dígito: A, B, E, H
        // - Siempre letra: K, P, Q, S, N, W
        // - Ambos válidos: resto
        if (strpos('ABEH', $letter) !== false) {
            return $control === (string)$controlDigit;
        }

        if (strpos('KPQSNW', $letter) !== false) {
            return $control === $controlLetter;
        }

        // Resto pueden usar ambos
        return $control === (string)$controlDigit || $control === $controlLetter;
    }
}
