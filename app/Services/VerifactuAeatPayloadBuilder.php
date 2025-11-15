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


    //TODO mirar que se hace aqui finalmente depende en weclub y en telelavo

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

        $installationNumber = $cfg->installNumber
            ?: self::getNumeroInstalacion();
        return [
            'NombreRazon'              => (string) ($cfg->systemNameReason ?? ''),
            'NIF'                      => (string) ($cfg->systemNif ?? ''),
            'NombreSistemaInformatico' => (string) ($cfg->systemName ?? ''),
            'IdSistemaInformatico'     => (string) ($cfg->systemId ?? ''),
            'Version'                  => (string) ($cfg->systemVersion ?? ''),
            'NumeroInstalacion'        => (string) ($installationNumber),
            'TipoUsoPosibleSoloVerifactu' => (string) ($cfg->onlyVerifactu ?? ''),
            'TipoUsoPosibleMultiOT'    => (string) ($cfg->multiOt ?? ''),
            'IndicadorMultiplesOT'     => (string) ($cfg->multiplesOt ?? ''),
        ];
    }

    /**
     * A partir de las líneas JSON del preview:
     *   [{"desc":"Servicio","qty":1,"price":100,"vat":21,"discount":0}]
     * devuelve [detailedBreakdown[], vatTotal, grossTotal]
     */
    public function buildDesgloseYTotalesFromJson(array $lines): array
    {
        $detailedBreakdown = [];
        $vatTotal = 0.0;
        $grossTotal = 0.0;

        foreach ($lines as $line) {
            $priceUnit = (float) ($line['price'] ?? 0);
            $qty        = (float) ($line['qty'] ?? 0);
            $vat        = (float) ($line['vat'] ?? 0);
            $dto        = (float) ($line['discount'] ?? 0);

            $totalSinDto   = $priceUnit * $qty;
            $discount     = $totalSinDto * ($dto / 100);
            $taxableBase = round($totalSinDto - $discount, 2);
            $fee         = round($taxableBase * ($vat / 100), 2);

            $claveRegimen  = '01'; //TODO mirar documentación AEAT para otros posibles valores
            $qualification  = 'S1'; //TODO mirar documentación AEAT para otros posibles valores
            $key = "{$claveRegimen}|{$qualification}|{$vat}";

            if (!isset($detailedBreakdown[$key])) {
                $detailedBreakdown[$key] = [
                    'ClaveRegimen' => $claveRegimen,
                    'CalificacionOperacion' => $qualification,
                    'TipoImpositivo' => $vat,
                    'BaseImponibleOimporteNoSujeto' => 0.0,
                    'CuotaRepercutida' => 0.0,
                ];
            }
            $detailedBreakdown[$key]['BaseImponibleOimporteNoSujeto'] += $taxableBase;
            $detailedBreakdown[$key]['CuotaRepercutida']               += $fee;

            $vatTotal   += $fee;
            $grossTotal += $taxableBase + $fee;
        }

        foreach ($detailedBreakdown as &$g) {
            $g['BaseImponibleOimporteNoSujeto'] = round($g['BaseImponibleOimporteNoSujeto'], 2);
            $g['CuotaRepercutida']               = round($g['CuotaRepercutida'], 2);
        }

        return [array_values($detailedBreakdown), round($vatTotal, 2), round($grossTotal, 2)];
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
        $detail = [];
        $vatTotal = 0.0;
        $grossTotal = 0.0;

        if (!empty($in['detalle']) && is_array($in['detalle'])) {
            // Ya viene agrupado por claves → úsalo tal cual
            $detail = array_map(static function (array $g) {
                return [
                    'ClaveRegimen'                  => (string)$g['ClaveRegimen'],
                    'CalificacionOperacion'         => (string)$g['CalificacionOperacion'],
                    'TipoImpositivo'                => (float)$g['TipoImpositivo'],
                    'BaseImponibleOimporteNoSujeto' => (float)$g['BaseImponibleOimporteNoSujeto'],
                    'CuotaRepercutida'              => (float)$g['CuotaRepercutida'],
                ];
            }, $in['detalle']);
            $vatTotal   = (float)($in['vat_total']   ?? 0.0);
            $grossTotal = (float)($in['gross_total'] ?? 0.0);
        } else {

            // TODO: este else quizás lo pueda eliminar si siempre se envía detalle_json
            [$detailCalc, $vatTotal, $grossTotal] = $this->buildDesgloseYTotalesFromJson($in['lines'] ?? []);

            $detail = array_map(static function (array $g) {
                return [
                    'ClaveRegimen'                  => (string)$g['ClaveRegimen'],
                    'CalificacionOperacion'         => (string)$g['CalificacionOperacion'],
                    'TipoImpositivo'                => (float)$g['TipoImpositivo'],
                    'BaseImponibleOimporteNoSujeto' => (float)$g['BaseImponibleOimporteNoSujeto'],
                    'CuotaRepercutida'              => (float)$g['CuotaRepercutida'],
                ];
            }, $detailCalc);
        }

        // --- Destinatarios ---
        $recipients = null;
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
            $recipients = [
                'IDDestinatario' => [
                    'NombreRazon' => (string)$name,
                    'NIF'         => (string)$nif,
                ],
            ];
        } elseif ($name && $country && $idType && $idNum) {
            $recipients = [
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
            $recipients = null;
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
                'DetalleDesglose' => $detail,
            ],
            'CuotaTotal'              => VerifactuFormatter::fmt2($vatTotal),
            'ImporteTotal'            => VerifactuFormatter::fmt2($grossTotal),
            'Encadenamiento'          => $enc,
            'FechaHoraHusoGenRegistro' => (string)($in['datetime_offset'] ?? ''),
            'TipoHuella'              => '01',
            'Huella'                  => (string)$in['huella'],
            'SistemaInformatico'      => $this->buildSistemaInformatico(),
        ];

        if ($recipients !== null) {
            $registroAlta['Destinatarios'] = $recipients;
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
