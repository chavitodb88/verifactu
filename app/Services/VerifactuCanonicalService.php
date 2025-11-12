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
        $p = explode('-', $yyyy_mm_dd);
        return (count($p) === 3) ? "{$p[2]}-{$p[1]}-{$p[0]}" : $yyyy_mm_dd; // dd-mm-YYYY
    }

    public static function nowAeatDateTime(): string
    {
        $dt = new \DateTime('now', new \DateTimeZone('Europe/Madrid'));
        return $dt->format('Y-m-d\TH:i:sP'); // ISO 8601 con TZ
    }

    public static function sha256Upper(string $s): string
    {
        $utf8 = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        return strtoupper(hash('sha256', $utf8));
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
    public function buildCadenaAlta(array $alta): string
    {
        $params = [
            'IDEmisorFactura'           => (string) $alta['IDEmisorFactura'],
            'NumSerieFactura'           => (string) $alta['NumSerieFactura'],
            'FechaExpedicionFactura'    => self::toAeatDate((string) $alta['FechaExpedicionFactura']),
            'TipoFactura'               => (string) ($alta['TipoFactura'] ?? 'F1'),
            'CuotaTotal'                => rtrim(rtrim(number_format((float)$alta['CuotaTotal'],   2, '.', ''), '0'), '.'),
            'ImporteTotal'              => rtrim(rtrim(number_format((float)$alta['ImporteTotal'], 2, '.', ''), '0'), '.'),
            'Huella'                    => '',
            'FechaHoraHusoGenRegistro'  => self::nowAeatDateTime(),
        ];

        return urldecode(http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }
}
