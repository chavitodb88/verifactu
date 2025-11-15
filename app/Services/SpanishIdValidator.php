<?php

declare(strict_types=1);

namespace App\Services;

final class SpanishIdValidator
{
    public static function isValid(string $value): bool
    {
        $value = strtoupper(trim($value));
        $value = str_replace([' ', '-'], '', $value);

        return self::isValidDni($value)
            || self::isValidNie($value)
            || self::isValidCif($value);
    }

    /**
     * Valida un DNI.
     * Formato: 8 dígitos + letra
     */
    private static function isValidDni(string $dni): bool
    {
        if (!preg_match('/^([0-9]{8})([A-Z])$/', $dni, $m)) {
            return false;
        }

        $num = (int) $m[1];
        $letter = $m[2];
        $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';

        return $letters[$num % 23] === $letter;
    }

    /**
     * Valida un NIE.
     * Formato: X/Y/Z + 7 dígitos + letra
     */
    private static function isValidNie(string $nie): bool
    {
        if (!preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $nie)) {
            return false;
        }

        $map = ['X' => '0', 'Y' => '1', 'Z' => '2'];
        $first = $nie[0];
        $replaced = $map[$first] . substr($nie, 1, 7); // 0xxxxx...

        $dniLike = $replaced . $nie[8]; // "0xxxxxxxL"
        return self::isValidDni($dniLike);
    }

    /**
     * Valida un CIF.
     * Formato: Letra + 7 dígitos + control (dígito o letra)
     */
    private static function isValidCif(string $cif): bool
    {
        if (!preg_match('/^[A-HJNP-SUVW][0-9]{7}[0-9A-J]$/', $cif)) {
            return false;
        }

        $letter = $cif[0];
        $digits = substr($cif, 1, 7);
        $control = $cif[8];

        // Sum positions even (2,4,6)
        $sumEven = (int)$digits[1] + (int)$digits[3] + (int)$digits[5];

        // Sum positions odd (1,3,5,7) *2 and sum digits
        $sumOdd = 0;
        foreach ([0, 2, 4, 6] as $i) {
            $n = (int)$digits[$i] * 2;
            $sumOdd += intdiv($n, 10) + ($n % 10);
        }

        $total = $sumEven + $sumOdd;
        $controlDigit = (10 - ($total % 10)) % 10;
        $controlLetter = 'JABCDEFGHI'[$controlDigit];

        /**
         * Tipos de CIF según letra inicial:
         * A: Sociedades anónimas
         * B: Sociedades de responsabilidad limitada
         * C: Sociedades colectivas
         * D: Sociedades comanditarias
         * E: Comunidades de bienes y herencias yacentes
         * F: Sociedades cooperativas
         * G: Asociaciones y fundaciones
         * H: Comunidades de propietarios en régimen de propiedad horizontal
         * J: Sociedades civiles, con o sin personalidad jurídica
         * K: Personas físicas con actividad empresarial
         * L: Personas físicas sin actividad empresarial
         * M: Entidades no residentes   
         */
        if (strpos('ABEH', $letter) !== false) {
            return $control === (string)$controlDigit;
        }

        if (strpos('KPQSNW', $letter) !== false) {
            return $control === $controlLetter;
        }

        return $control === (string)$controlDigit || $control === $controlLetter;
    }
}
