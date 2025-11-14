<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\VerifactuFormatter;

final class VerifactuAeatPayloadBuilder
{
    /**
     * Encadenamiento según exista prev_hash.
     * Espera: issuer_nif, num_serie_factura, issue_date (YYYY-MM-DD), prev_hash|null
     */
    private static function buildEncadenamiento(array $in): array
    {
        $prev = $in['prev_hash'] ?? null;
        if ($prev === null || $prev === '') {
            return ['PrimerRegistro' => 'S'];
        }
        return [
            'RegistroAnterior' => [
                'IDEmisorFactura'        => (string) $in['issuer_nif'],
                'NumSerieFactura'        => (string) $in['num_serie_factura'],
                'FechaExpedicionFactura' => VerifactuFormatter::toAeatDate((string) $in['issue_date']),
                'Huella'                 => (string) $prev,
            ],
        ];
    }



    protected static function getNumeroInstalacion(): string
    {
        $ctx = service('requestContext');
        $company = is_object($ctx) ? $ctx->getCompany() : [];
        $companyId = (int)($company['id'] ?? 999);

        return str_pad((string)$companyId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Construye el bloque SistemaInformatico (inyectado desde config/empresa)
     */
    public static function buildSistemaInformatico(): array
    {
        /** @var \Config\Verifactu $cfg */
        $cfg = config('Verifactu');

        $numeroInstalacion = $cfg->installNumber
            ?: self::getNumeroInstalacion();
        return [
            'NombreRazon'              => (string) ($cfg->systemNameReason ?? ''),
            'NIF'                      => (string) ($cfg->systemNif ?? ''),
            'NombreSistemaInformatico' => (string) ($cfg->systemName ?? ''),
            'IdSistemaInformatico'     => (string) ($cfg->systemId ?? ''),
            'Version'                  => (string) ($cfg->systemVersion ?? ''),
            'NumeroInstalacion'        => (string) ($numeroInstalacion),
            'TipoUsoPosibleSoloVerifactu' => (string) ($cfg->onlyVerifactu ?? ''),
            'TipoUsoPosibleMultiOT'    => (string) ($cfg->multiOt ?? ''),
            'IndicadorMultiplesOT'     => (string) ($cfg->multiplesOt ?? ''),
        ];
    }

    /**
     * A partir de las líneas JSON del preview:
     *   [{"desc":"Servicio","qty":1,"price":100,"vat":21,"discount":0}]
     * devuelve [detalleDesglose[], cuotaTotal, importeTotal]
     */
    public function buildDesgloseYTotalesFromJson(array $lines): array
    {
        $detalleDesglose = [];
        $cuotaTotal = 0.0;
        $importeTotal = 0.0;

        foreach ($lines as $line) {
            $precioUnit = (float) ($line['price'] ?? 0);
            $qty        = (float) ($line['qty'] ?? 0);
            $iva        = (float) ($line['vat'] ?? 0);
            $dto        = (float) ($line['discount'] ?? 0);

            $totalSinDto   = $precioUnit * $qty;
            $descuento     = $totalSinDto * ($dto / 100);
            $baseImponible = round($totalSinDto - $descuento, 2);
            $cuota         = round($baseImponible * ($iva / 100), 2);

            $claveRegimen  = '01';
            $calificacion  = 'S1';
            $key = "{$claveRegimen}|{$calificacion}|{$iva}";

            if (!isset($detalleDesglose[$key])) {
                $detalleDesglose[$key] = [
                    'ClaveRegimen' => $claveRegimen,
                    'CalificacionOperacion' => $calificacion,
                    'TipoImpositivo' => $iva,
                    'BaseImponibleOimporteNoSujeto' => 0.0,
                    'CuotaRepercutida' => 0.0,
                ];
            }
            $detalleDesglose[$key]['BaseImponibleOimporteNoSujeto'] += $baseImponible;
            $detalleDesglose[$key]['CuotaRepercutida']               += $cuota;

            $cuotaTotal   += $cuota;
            $importeTotal += $baseImponible + $cuota;
        }

        foreach ($detalleDesglose as &$g) {
            $g['BaseImponibleOimporteNoSujeto'] = round($g['BaseImponibleOimporteNoSujeto'], 2);
            $g['CuotaRepercutida']               = round($g['CuotaRepercutida'], 2);
        }

        return [array_values($detalleDesglose), round($cuotaTotal, 2), round($importeTotal, 2)];
    }

    /**
     * Construye el payload de ALTA (RegFactuSistemaFacturacion)
     * Espera en $in:
     *  - issuer_nif, issuer_name
     *  - num_serie_factura (serie+numero ya formateado)
     *  - issue_date (YYYY-MM-DD)
     *  - invoice_type (por defecto 'F1')
     *  - lines (array para desglose)
     *  - prev_hash|null
     *  - huella (SHA-256 en mayúsculas de la cadena canónica)
     *  - sistema_informatico (array para buildSistemaInformatico)
     *  - descripcion (opcional)
     */
    public function buildAlta(array $in): array
    {
        $enc = self::buildEncadenamiento($in);
        $invoiceType = (string)($in['invoice_type'] ?? 'F1');

        // 1) Si no viene detalle precocinado, calcúlalo desde lines
        $detalle = [];
        $cuotaTotal = 0.0;
        $importeTotal = 0.0;

        if (!empty($in['detalle']) && is_array($in['detalle'])) {
            // Ya viene agrupado por claves → úsalo tal cual
            $detalle = array_map(static function (array $g) {
                return [
                    'ClaveRegimen'                  => (string)$g['ClaveRegimen'],
                    'CalificacionOperacion'         => (string)$g['CalificacionOperacion'],
                    'TipoImpositivo'                => (float)$g['TipoImpositivo'],
                    'BaseImponibleOimporteNoSujeto' => (float)$g['BaseImponibleOimporteNoSujeto'],
                    'CuotaRepercutida'              => (float)$g['CuotaRepercutida'],
                ];
            }, $in['detalle']);
            $cuotaTotal   = (float)($in['vat_total']   ?? 0.0);
            $importeTotal = (float)($in['gross_total'] ?? 0.0);
        } else {

            // TODO: este else quizás lo pueda eliminar si siempre se envía detalle_json
            [$detalleCalc, $cuotaTotal, $importeTotal] = $this->buildDesgloseYTotalesFromJson($in['lines'] ?? []);

            $detalle = array_map(static function (array $g) {
                return [
                    'ClaveRegimen'                  => (string)$g['ClaveRegimen'],
                    'CalificacionOperacion'         => (string)$g['CalificacionOperacion'],
                    'TipoImpositivo'                => (float)$g['TipoImpositivo'],
                    'BaseImponibleOimporteNoSujeto' => (float)$g['BaseImponibleOimporteNoSujeto'],
                    'CuotaRepercutida'              => (float)$g['CuotaRepercutida'],
                ];
            }, $detalleCalc);
        }

        // --- Destinatarios ---
        $destinatarios = null;
        $recipient = is_array($in['recipient'] ?? null) ? $in['recipient'] : [];

        $name    = $recipient['name']    ?? null;
        $nif     = $recipient['nif']     ?? null;
        $country = $recipient['country'] ?? null;
        $idType  = $recipient['idType']  ?? null;
        $idNum   = $recipient['idNumber'] ?? null;

        // Regla sencilla:
        // - Si hay NIF y nombre → IDDestinatario con NIF
        // - Si NO hay NIF pero sí IDOtro → usamos IDOtro
        // - Si no hay nada → no mandamos Destinatarios (útil para futuros F3)
        if ($name && $nif) {
            $destinatarios = [
                'IDDestinatario' => [
                    'NombreRazon' => (string)$name,
                    'NIF'         => (string)$nif,
                ],
            ];
        } elseif ($name && $country && $idType && $idNum) {
            $destinatarios = [
                'IDDestinatario' => [
                    'NombreRazon' => (string)$name,
                    'IDOtro1' => [
                        'CodigoPais' => (string)$country,
                        'IDType'     => (string)$idType,
                        'IDNumero'   => (string)$idNum,
                    ],
                ],
            ];
        } else {
            // Para F1, más adelante haremos que esto sea inválido
            // y falle en validación antes de llegar aquí.
            $destinatarios = null;
        }

        $registroAlta = [
            'IDVersion' => '1.0',
            'IDFactura' => [
                'IDEmisorFactura'        => (string)($in['issuer_nif'] ?? ''),
                'NumSerieFactura'        => (string)$in['num_serie_factura'],
                'FechaExpedicionFactura' => VerifactuFormatter::toAeatDate((string)$in['issue_date']),
            ],
            'NombreRazonEmisor'       => (string)($in['issuer_name'] ?? 'Empresa'),
            'TipoFactura'             => $invoiceType,
            'DescripcionOperacion'    => (string)($in['descripcion'] ?? 'Transferencia VTC'),
            'Desglose' => [
                'DetalleDesglose' => $detalle,
            ],
            'CuotaTotal'              => number_format($cuotaTotal, 2, '.', ''),
            'ImporteTotal'            => number_format($importeTotal, 2, '.', ''),
            'Encadenamiento'          => $enc,
            'FechaHoraHusoGenRegistro' => (string)($in['fecha_huso'] ?? ''),
            'TipoHuella'              => '01',
            'Huella'                  => (string)$in['huella'],
            'SistemaInformatico'      => $this->buildSistemaInformatico(),
        ];

        if ($destinatarios !== null) {
            $registroAlta['Destinatarios'] = $destinatarios;
        }

        return [
            'Cabecera' => [
                'ObligadoEmision' => [
                    'NombreRazon' => (string)($in['issuer_name'] ?? ''),
                    'NIF'         => (string)($in['issuer_nif'] ?? ''),
                ],
            ],
            'RegistroFactura' => [
                'RegistroAlta' => $registroAlta,
            ],
        ];
    }
}
