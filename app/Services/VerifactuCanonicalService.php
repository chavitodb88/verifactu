<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\VerifactuFormatter;

/**
 * Genera cadenas canónicas VERI*FACTU (alta) y calcula la huella SHA-256 (en MAYÚSCULAS),
 * siguiendo el patrón que que requiere hacienda (http_build_query RFC3986 + urldecode).
 * Compatible PHP 7.4+.
 */
final class VerifactuCanonicalService
{
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
     *  - Huella (previa, o vacío si no hay)
     *  - FechaHoraHusoGenRegistro (ISO 8601 con TZ)
     */
    public static function buildCadenaAlta(array $in): array
    {
        $ts = isset($in['datetime_offset']) && $in['datetime_offset'] !== ''
            ? (string)$in['datetime_offset']
            : (new \DateTime('now', new \DateTimeZone('Europe/Madrid')))
            ->format('Y-m-d\TH:i:sP');

        $issuerNif   = (string)$in['issuer_nif'];                    // NIF del obligado (NO el del productor)
        $numSeries   = (string)$in['full_invoice_number'];             // p.ej. "F20" o "F0005" (exacto al XML)
        $issueDate     = VerifactuFormatter::toAeatDate((string)$in['issue_date']);  // dd-mm-YYYY
        $invoiceType       = (string)($in['invoice_type'] ?? 'F1');
        $vatTotal      = VerifactuFormatter::fmt2($in['vat_total']);               // 21.00
        $grossTotal    = VerifactuFormatter::fmt2($in['gross_total']);             // 121.00
        $prevHash       = (string)($in['prev_hash'] ?? '');             // vacío si no hay

        $hash =
            'IDEmisorFactura=' . $issuerNif .
            '&NumSerieFactura=' . $numSeries .
            '&FechaExpedicionFactura=' . $issueDate .
            '&TipoFactura=' . $invoiceType .
            '&CuotaTotal=' . $vatTotal .
            '&ImporteTotal=' . $grossTotal .
            '&Huella=' . $prevHash .
            '&FechaHoraHusoGenRegistro=' . $ts;

        return [$hash, $ts];
    }
}
