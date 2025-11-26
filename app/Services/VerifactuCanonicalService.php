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
     *  - IDEmisorFactura (NIF del obligado, NO el del productor)
     *  - NumSerieFactura (serie+número ya formateado como tú definas) p.ej. "F20" o "F0005" (exacto al XML)
     *  - FechaExpedicionFactura (dd-mm-YYYY)
     *  - TipoFactura (F1 por defecto)
     *  - CuotaTotal (decimal con 2)
     *  - ImporteTotal (decimal con 2)
     *  - Huella (previa, o vacío si no hay)
     *  - FechaHoraHusoGenRegistro (ISO 8601 con TZ)
     *
     * Devuelve array con:
     *  - Cadena canónica: IDEmisorFactura=B61206934&NumSerieFactura=F58&FechaExpedicionFactura=04-11-2025&TipoFactura=F1&CuotaTotal=27.31&ImporteTotal=162.61&Huella=4F43C31FB612A4E9D885D4DA425EF3F62B8B0602DDD2251566A62E169B38EB56&FechaHoraHusoGenRegistro=2025-11-15T08:36:04+01:00
     *  - Timestamp usado
     */
    public static function buildRegistrationChain(array $in): array
    {
        $ts = isset($in['datetime_offset']) && $in['datetime_offset'] !== ''
            ? (string)$in['datetime_offset']
            : (new \DateTime('now', new \DateTimeZone('Europe/Madrid')))
            ->format('Y-m-d\TH:i:sP');

        $issuerNif = (string)$in['issuer_nif'];
        $numSeries = (string)$in['full_invoice_number'];
        $issueDate = VerifactuFormatter::toAeatDate((string)$in['issue_date']);
        $invoiceType = (string)($in['invoice_type'] ?? 'F1');
        $vatTotal = VerifactuFormatter::fmt2($in['vat_total']);
        $grossTotal = VerifactuFormatter::fmt2($in['gross_total']);
        $prevHash = (string)($in['prev_hash'] ?? '');

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

    /**
     * Huella de ANULACIÓN según especificación AEAT.
     *
     * Espera:
     *  - IDEmisorFacturaAnulada (NIF del obligado, NO el del productor)
     *  - NumSerieFacturaAnulada (serie+número ya formateado como tú definas) p.ej. "F20" o "F0005" (exacto al XML)
     *  - FechaExpedicionFacturaAnulada (dd-mm-YYYY)
     *  - Huella (previa, o vacío si no hay)
     *
     * Devuelve array con:
     * - Cadena canónica: IDEmisorFacturaAnulada=B61206934&NumSerieFacturaAnulada=F58&FechaExpedicionFacturaAnulada=04-11-2025&Huella=4F43C31FB612A4E9D885D4DA425EF3F62B8B0602DDD2251566A62E169B38EB56&FechaHoraHusoGenRegistro=2025-11-15T08:36:04+01:00
     * - Timestamp usado
     */

    public static function buildCancellationChain(array $in): array
    {
        $ts = isset($in['datetime_offset']) && $in['datetime_offset'] !== ''
            ? (string)$in['datetime_offset']
            : (new \DateTime('now', new \DateTimeZone('Europe/Madrid')))
            ->format('Y-m-d\TH:i:sP');

        $issuerNif = (string)$in['issuer_nif'];
        $fullNumber = (string)$in['full_invoice_number'];
        $issueDate = (string)$in['issue_date'];
        $prevHash = (string)($in['prev_hash'] ?? '');

        $parts = [
            'IDEmisorFacturaAnulada=' . trim($issuerNif),
            'NumSerieFacturaAnulada=' . trim($fullNumber),
            'FechaExpedicionFacturaAnulada=' . VerifactuFormatter::toAeatDate($issueDate),
            'Huella=' . trim($prevHash),
            'FechaHoraHusoGenRegistro=' . $ts,
        ];

        $chain = implode('&', $parts);

        return [$chain, $ts];
    }
}
