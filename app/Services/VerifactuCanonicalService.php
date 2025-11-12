<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Genera cadenas canónicas VERI*FACTU (alta) y calcula la huella SHA-256 (en MAYÚSCULAS),
 * siguiendo el patrón que que requiere hacienda (http_build_query RFC3986 + urldecode).
 * Compatible PHP 7.4+.
 */
final class VerifactuCanonicalService
{
    public static function toAeatDate(string $yyyy_mm_dd): string
    {
        [$y, $m, $d] = explode('-', $yyyy_mm_dd);
        return "{$d}-{$m}-{$y}";
    }

    /** 2 decimales con punto */
    public static function fmt2($n): string
    {
        return number_format((float)$n, 2, '.', '');
    }

    public static function nowAeatDateTime(): string
    {
        $dt = new \DateTime('now', new \DateTimeZone('Europe/Madrid'));
        return $dt->format('Y-m-d\TH:i:sP'); // ISO 8601 con TZ
    }

    /** SHA-256 en mayúsculas sobre UTF-8 */
    public static function sha256Upper(string $s): string
    {
        return strtoupper(hash('sha256', mb_convert_encoding($s, 'UTF-8')));
    }

    /**
     * Cadena de ALTA (registro).
     * Espera:
     *  - IDEmisorFactura (NIF)
     *  - NumSerieFactura (serie+número ya formateado como tú definas)
     *  - FechaExpedicionFactura (YYYY-MM-DD)
     *  - TipoFactura (F1 por defecto)
     *  - CuotaTotal (decimal con 2)
     *  - ImporteTotal (decimal con 2)
     *  - (Huella siempre vacía en la cadena)
     */
    public static function buildCadenaAlta(array $in): array
    {
        // Genera EL MISMO TS que pondrás en el XML
        $ts = (new \DateTime('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m-d\TH:i:sP');

        $idEmisor   = (string)$in['issuer_nif'];                    // NIF del obligado (NO el del productor)
        $numSerie   = (string)$in['num_serie_factura'];             // p.ej. "F20" o "F0005" (exacto al XML)
        $fecExp     = self::toAeatDate((string)$in['issue_date']);  // dd-mm-YYYY
        $tipo       = (string)($in['tipo_factura'] ?? 'F1');
        $cuota      = self::fmt2($in['cuota_total']);               // 21.00
        $importe    = self::fmt2($in['importe_total']);             // 121.00
        $prev       = (string)($in['prev_hash'] ?? '');             // vacío si no hay

        $cadena =
            'IDEmisorFactura=' . $idEmisor .
            '&NumSerieFactura=' . $numSerie .
            '&FechaExpedicionFactura=' . $fecExp .
            '&TipoFactura=' . $tipo .
            '&CuotaTotal=' . $cuota .
            '&ImporteTotal=' . $importe .
            '&Huella=' . $prev .
            '&FechaHoraHusoGenRegistro=' . $ts;

        return [$cadena, $ts];
    }
}
