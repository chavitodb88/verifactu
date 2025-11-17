<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\VerifactuFormatter;

final class VerifactuAeatPayloadBuilder
{
    /**
     * Encadenamiento según exista prev_hash.
     * Espera: $in con:
     *  - issuer_nif
     *  - full_invoice_number
     *  - issue_date
     *  - prev_hash|null
     * 
     * Devuelve el array adecuado para el bloque Encadenamiento
     */
    private static function buildChainingBlock(array $in): array
    {
        $prev = $in['prev_hash'] ?? null;
        if ($prev === null || $prev === '') {
            return ['PrimerRegistro' => 'S'];
        }
        return [
            'RegistroAnterior' => [
                'IDEmisorFactura'        => (string) $in['issuer_nif'],
                'NumSerieFactura'        => (string) $in['full_invoice_number'],
                'FechaExpedicionFactura' => VerifactuFormatter::toAeatDate((string) $in['issue_date']),
                'Huella'                 => (string) $prev,
            ],
        ];
    }


    //TODO mirar que se hace aqui finalmente depende en weclub y en telelavo
    protected static function getInstallationNumber(): string
    {
        $ctx = service('requestContext');
        $company = is_object($ctx) ? $ctx->getCompany() : [];
        $companyId = (int)($company['id'] ?? 999);

        return str_pad((string)$companyId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Construye el bloque SistemaInformatico (inyectado desde config/empresa)
     */
    public static function buildSoftwareSystemBlock(): array
    {
        /** @var \Config\Verifactu $cfg */
        $cfg = config('Verifactu');

        $installationNumber = $cfg->installNumber
            ?: self::getInstallationNumber();
        return [
            'NombreRazon'              => (string) ($cfg->systemNameReason),
            'NIF'                      => (string) ($cfg->systemNif),
            'NombreSistemaInformatico' => (string) ($cfg->systemName),
            'IdSistemaInformatico'     => (string) ($cfg->systemId),
            'Version'                  => (string) ($cfg->systemVersion),
            'NumeroInstalacion'        => (string) ($installationNumber),
            'TipoUsoPosibleSoloVerifactu' => (string) ($cfg->onlyVerifactu),
            'TipoUsoPosibleMultiOT'    => (string) ($cfg->multiOt),
            'IndicadorMultiplesOT'     => (string) ($cfg->multiplesOt),
        ];
    }

    /**
     * A partir de las líneas JSON del preview:
     *   [{"desc":"Servicio","qty":1,"price":100,"vat":21,"discount":0}]
     * devuelve [detailedBreakdown[], vatTotal, grossTotal]
     * 
     * detailedBreakdown: array con desglose por tipos impositivo
     * vatTotal: total IVA
     * grossTotal: total bruto (base + IVA)
     */
    public function buildBreakdownAndTotalsFromJson(array $lines): array
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

            $claveRegimen  = '01'; // TODO mirar documentación AEAT para otros posibles valores
            $qualification  = 'S1'; // TODO mirar documentación AEAT para otros posibles valores
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
     *  - full_invoice_number (serie+numero ya formateado)
     *  - issue_date (YYYY-MM-DD)
     *  - invoice_type (por defecto 'F1')
     *  - lines (array para desglose)
     *  - prev_hash|null
     *  - hash (SHA-256 en mayúsculas de la cadena canónica)
     *  - sistema_informatico (array para buildSoftwareSystemBlock)
     *  - description (opcional)
     */
    public function buildRegistration(array $in): array
    {
        $enc = self::buildChainingBlock($in);
        $invoiceType = (string)($in['invoice_type'] ?? 'F1');

        $detail = [];
        $vatTotal = 0.0;
        $grossTotal = 0.0;

        if (!empty($in['detail']) && is_array($in['detail'])) {
            $detail = array_map(static function (array $g) {
                return [
                    'ClaveRegimen'                  => (string)$g['ClaveRegimen'],
                    'CalificacionOperacion'         => (string)$g['CalificacionOperacion'],
                    'TipoImpositivo'                => (float)$g['TipoImpositivo'],
                    'BaseImponibleOimporteNoSujeto' => (float)$g['BaseImponibleOimporteNoSujeto'],
                    'CuotaRepercutida'              => (float)$g['CuotaRepercutida'],
                ];
            }, $in['detail']);
            $vatTotal   = (float)($in['vat_total']   ?? 0.0);
            $grossTotal = (float)($in['gross_total'] ?? 0.0);
        } else {

            // TODO: este else quizás lo pueda eliminar si siempre se envía details_json
            [$detailCalc, $vatTotal, $grossTotal] = $this->buildBreakdownAndTotalsFromJson($in['lines'] ?? []);

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

        $recipients = null;
        $recipient = is_array($in['recipient'] ?? null) ? $in['recipient'] : [];

        $name    = $recipient['name']    ?? null;
        $nif     = $recipient['nif']     ?? null;
        $country = $recipient['country'] ?? null;
        $idType  = $recipient['idType']  ?? null;
        $idNum   = $recipient['idNumber'] ?? null;


        /**
         * Regla sencilla:
         * - Si hay NIF y nombre → IDDestinatario con NIF
         * - Si NO hay NIF pero sí IDOtro → usamos IDOtro
         * - Si no hay nada → no mandamos Destinatarios (útil para futuros
         */
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
            /**
             * Para F1, más adelante haremos que esto sea inválido y falle en validación antes de llegar aquí.
             */
            $recipients = null;
        }

        $registroAlta = [
            'IDVersion' => '1.0',
            'IDFactura' => [
                'IDEmisorFactura'        => (string)($in['issuer_nif']),
                'NumSerieFactura'        => (string)$in['full_invoice_number'],
                'FechaExpedicionFactura' => VerifactuFormatter::toAeatDate((string)$in['issue_date']),
            ],
            'NombreRazonEmisor'       => (string)($in['issuer_name']),
            'TipoFactura'             => $invoiceType,
            'DescripcionOperacion'    => (string)($in['description']),
            'Desglose' => [
                'DetalleDesglose' => $detail,
            ],
            'CuotaTotal'              => VerifactuFormatter::fmt2($vatTotal),
            'ImporteTotal'            => VerifactuFormatter::fmt2($grossTotal),
            'Encadenamiento'          => $enc,
            'FechaHoraHusoGenRegistro' => (string)($in['datetime_offset']),
            'TipoHuella'              => '01',
            'Huella'                  => (string)$in['hash'],
            'SistemaInformatico'      => $this->buildSoftwareSystemBlock(),
        ];

        if ($recipients !== null) {
            $registroAlta['Destinatarios'] = $recipients;
        }

        return [
            'Cabecera' => [
                'ObligadoEmision' => [
                    'NombreRazon' => (string)($in['issuer_name']),
                    'NIF'         => (string)($in['issuer_nif']),
                ],
            ],
            'RegistroFactura' => [
                'RegistroAlta' => $registroAlta,
            ],
        ];
    }

    public function buildCancellation(array $in): array
    {
        $issuerNif   = (string)$in['issuer_nif'];
        $issuerName  = (string)$in['issuer_name'];
        $fullNumber  = (string)$in['full_invoice_number'];
        $issueDate   = (string)$in['issue_date'];
        $prevHash    = $in['prev_hash'] ?? null;
        $hash        = (string)$in['hash'];
        $generatedAt = (string)$in['datetime_offset'];

        $cabecera = [
            'Cabecera' => [
                'ObligadoEmision' => [
                    'NombreRazon' => $issuerName,
                    'NIF'         => $issuerNif,
                ],
            ],
        ];

        if ($prevHash === null || $prevHash === '') {
            $encadenamiento = [
                'PrimerRegistro' => 'S',
            ];
        } else {
            $encadenamiento = [
                'RegistroAnterior' => [
                    'IDEmisorFactura'        => $issuerNif,
                    'NumSerieFactura'        => $fullNumber,
                    'FechaExpedicionFactura' => VerifactuFormatter::toAeatDate($issueDate),
                    'Huella'                 => $prevHash,
                ],
            ];
        }

        $registroAnulacion = [
            'RegistroAnulacion' => [
                'IDVersion' => '1.0',
                'IDFactura' => [
                    'IDEmisorFacturaAnulada'        => $issuerNif,
                    'NumSerieFacturaAnulada'        => $fullNumber,
                    'FechaExpedicionFacturaAnulada' => VerifactuFormatter::toAeatDate($issueDate),
                ],
                /**
                 * Flags SinRegistroPrevio / RechazoPrevio
                 * De momento no los usamos, sería algo así:
                 * 'SinRegistroPrevio' => 'S' / 'RechazoPrevio' => 'S'
                 * 
                 */
                'Encadenamiento'           => $encadenamiento,
                'SistemaInformatico'      => $this->buildSoftwareSystemBlock(),
                'FechaHoraHusoGenRegistro' => $generatedAt,
                'TipoHuella'               => '01',
                'Huella'                   => $hash,
            ],
        ];

        return array_merge(
            $cabecera,
            ['RegistroFactura' => $registroAnulacion]
        );
    }
}
