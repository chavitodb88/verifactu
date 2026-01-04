<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Verifactu\CancellationMode;
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
                'NumSerieFactura'        => (string) $in['prev_full_invoice_number'],
                'FechaExpedicionFactura' => VerifactuFormatter::toAeatDate((string) $in['prev_issue_date']),
                'Huella'                 => (string) $prev,
            ],
        ];
    }


    protected static function getInstallationNumber(): string
    {
        /** @var \Config\Verifactu $cfg */
        $cfg = config('Verifactu');

        // Si está configurado en .env/Config, usamos eso
        if ($cfg->installNumber !== '') {
            return (string) $cfg->installNumber;
        }

        // Fallback muy simple y estable:
        return '0001';
    }

    private static function normalizeFlag(string $value, string $default = 'S'): string
    {
        $v = strtoupper(trim($value));

        return in_array($v, ['S', 'N'], true) ? $v : $default;
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
            'NombreRazon'                 => (string) $cfg->systemNameReason,
            'NIF'                         => (string) $cfg->systemNif,
            'NombreSistemaInformatico'    => (string) $cfg->systemName,
            'IdSistemaInformatico'        => (string) $cfg->systemId,
            'Version'                     => (string) $cfg->systemVersion,
            'NumeroInstalacion'           => (string) $installationNumber,
            'TipoUsoPosibleSoloVerifactu' => self::normalizeFlag($cfg->onlyVerifactu, 'S'),
            'TipoUsoPosibleMultiOT'       => self::normalizeFlag($cfg->multiOt, 'S'),
            'IndicadorMultiplesOT'        => self::normalizeFlag($cfg->multiplesOt, 'S'),
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
     *
     *
     */
    public function buildBreakdownAndTotalsFromJson(
        array $lines,
        string $taxRegimeCode = '01',
        string $operationQualification = 'S1'
    ): array {
        $detailedBreakdown = [];
        $vatTotal = 0.0;
        $grossTotal = 0.0;

        foreach ($lines as $line) {
            $priceUnit = (float) ($line['price'] ?? 0);
            $qty = (float) ($line['qty'] ?? 0);
            $vat = (float) ($line['vat'] ?? 0);
            $dto = (float) ($line['discount'] ?? 0);

            $totalSinDto = $priceUnit * $qty;
            $discount = $totalSinDto * ($dto / 100);

            $baseRaw = $totalSinDto - $discount;          // sin redondear (ej: 52.0248)
            $fee     = round($baseRaw * ($vat / 100), 2); // 10.93
            $gross   = round($baseRaw + $fee, 2);         // 62.95
            $taxableBase = round($gross - $fee, 2);       // 52.02 (cuadra con el total)

            $claveRegimen = $taxRegimeCode;
            $qualification = $operationQualification;

            $key = "{$claveRegimen}|{$qualification}|{$vat}";

            if (!isset($detailedBreakdown[$key])) {
                $detailedBreakdown[$key] = [
                    'ClaveRegimen'                  => $claveRegimen,
                    'CalificacionOperacion'         => $qualification,
                    'TipoImpositivo'                => $vat,
                    'BaseImponibleOimporteNoSujeto' => 0.0,
                    'CuotaRepercutida'              => 0.0,
                ];
            }

            $detailedBreakdown[$key]['BaseImponibleOimporteNoSujeto'] += $taxableBase;
            $detailedBreakdown[$key]['CuotaRepercutida'] += $fee;

            $vatTotal += $fee;
            $grossTotal += $gross;
        }

        foreach ($detailedBreakdown as &$item) {
            $item['BaseImponibleOimporteNoSujeto'] = round((float)$item['BaseImponibleOimporteNoSujeto'], 2);
            $item['CuotaRepercutida'] = round((float)$item['CuotaRepercutida'], 2);
        }
        unset($item);

        return [array_values($detailedBreakdown), round($vatTotal, 2), round($grossTotal, 2)];
    }

    /**
     * Construye el payload de ALTA (RegFactuSistemaFacturacion)
     * Espera en $in:
     *  - issuer_nif, issuer_name
     *  - full_invoice_number (serie+numero ya formateado)
     *  - issue_date (YYYY-MM-DD)
     *  - invoice_type (por defecto 'F1')
     *  - detail (desglose ya calculado) o lines (array para desglose)
     *  - vat_total, gross_total
     *  - prev_hash|null
     *  - hash (SHA-256 en mayúsculas de la cadena canónica)
     *  - datetime_offset (FechaHoraHusoGenRegistro)
     *  - recipient (bloque destinatario, opcional)
     *  - rectify_mode ('S'|'I', solo rectificativas, opcional)
     *  - rectified_invoices (array de facturas originales, opcional)
     */
    public function buildRegistration(array $in): array
    {
        $enc = self::buildChainingBlock($in);
        $invoiceType = (string)($in['invoice_type'] ?? 'F1');

        // --- Desglose / totales ---
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

            $vatTotal = (float)($in['vat_total'] ?? 0.0);
            $grossTotal = (float)($in['gross_total'] ?? 0.0);
        } else {
            // Fallback: recalcular desde lines
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

        // --- Destinatarios ---
        $recipients = null;
        $recipient = is_array($in['recipient'] ?? null) ? $in['recipient'] : [];

        $name = $recipient['name'] ?? null;
        $nif = $recipient['nif'] ?? null;
        $country = $recipient['country'] ?? null;
        $idType = $recipient['idType'] ?? null;
        $idNum = $recipient['idNumber'] ?? null;


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
                    'IDOtro'      => [
                        'CodigoPais' => (string)$country,
                        'IDType'     => (string)$idType,
                        'ID'         => (string)$idNum,
                    ],
                ],
            ];
        } else {
            $recipients = null; // no se envía Destinatarios
        }

        // --- Rectificativas (R1–R5) ---
        $rectifyMode = $in['rectify_mode'] ?? null;      // 'S' | 'I'
        $rectifiedInvoices = $in['rectified_invoices'] ?? null;      // array|null
        $facturasRectificadasBlock = null;

        if (
            is_string($invoiceType)
            && str_starts_with($invoiceType, 'R')
            && $rectifyMode !== null
            && is_array($rectifiedInvoices)
            && count($rectifiedInvoices) > 0
        ) {
            $idFacturas = array_map(static function (array $orig): array {
                $issuer = (string)($orig['issuer_nif'] ?? '');
                $series = (string)($orig['series'] ?? '');
                $number = (int)   ($orig['number'] ?? 0);
                $issueDt = (string)($orig['issueDate'] ?? '');

                return [
                    'IDEmisorFactura'        => $issuer,
                    'NumSerieFactura'        => $series . $number,
                    'FechaExpedicionFactura' => VerifactuFormatter::toAeatDate($issueDt),
                ];
            }, $rectifiedInvoices);

            $facturasRectificadasBlock = [
                'FacturasRectificadas' => [
                    'IDFacturaRectificada' => $idFacturas,
                ],
                'TipoRectificativa' => $rectifyMode, // 'S' (sustitutiva) / 'I' (diferencias)
            ];
        }

        // --- RegistroAlta base ---
        $registroAlta = [
            'IDVersion' => '1.0',
            'IDFactura' => [
                'IDEmisorFactura'        => (string)($in['issuer_nif']),
                'NumSerieFactura'        => (string)$in['full_invoice_number'],
                'FechaExpedicionFactura' => VerifactuFormatter::toAeatDate((string)$in['issue_date']),
            ],
            'NombreRazonEmisor'    => (string)($in['issuer_name']),
            'TipoFactura'          => $invoiceType,
            'DescripcionOperacion' => (string)($in['description']),
            'Desglose'             => [
                'DetalleDesglose' => $detail,
            ],
            'CuotaTotal'               => VerifactuFormatter::fmt2($vatTotal),
            'ImporteTotal'             => VerifactuFormatter::fmt2($grossTotal),
            'Encadenamiento'           => $enc,
            'FechaHoraHusoGenRegistro' => (string)($in['datetime_offset']),
            'TipoHuella'               => '01',
            'Huella'                   => (string)$in['hash'],
            'SistemaInformatico'       => $this->buildSoftwareSystemBlock(),
        ];

        if ($recipients !== null) {
            $registroAlta['Destinatarios'] = $recipients;
        }

        if ($facturasRectificadasBlock !== null) {
            // mergea FacturasRectificadas + TipoRectificativa en el RegistroAlta
            $registroAlta = array_merge($registroAlta, $facturasRectificadasBlock);
        }

        // --- Bloque de rectificativas (R1-R4) ---
        if (str_starts_with($invoiceType, 'R')) {
            $rectifyMode = $in['rectify_mode'] ?? null;    // 'S' (sustitución) | 'I' (diferencias)
            $rectifiedInvoices = $in['rectified_invoices'] ?? null;   // array de facturas originales

            // 1) Facturas rectificadas
            if (is_array($rectifiedInvoices) && !empty($rectifiedInvoices)) {
                // AEAT permite varias, nosotros de momento usamos la primera
                $first = $rectifiedInvoices[0];

                $registroAlta['FacturaRectificada'] = [
                    'IDEmisorFactura'        => (string)$first['issuer_nif'],
                    'NumSerieFactura'        => (string)($first['series'] . $first['number']),
                    'FechaExpedicionFactura' => VerifactuFormatter::toAeatDate((string)$first['issueDate']),
                ];
                // Si quisieras soportar varias:
                // 'FacturasRectificadas' => [ [ ... ], [ ... ], ... ]
            }

            // 2) ImporteRectificacion es OBLIGATORIO si mode = 'S' (substitution)
            if ($rectifyMode === 'S') {
                // Sustitutiva: ImporteRectificacion debe llevar el importe "nuevo" de la factura
                $registroAlta['ImporteRectificacion'] = [
                    'BaseRectificada'      => VerifactuFormatter::fmt2($grossTotal - $vatTotal), // base
                    'CuotaRectificada'     => VerifactuFormatter::fmt2($vatTotal),
                    'ImporteRectificacion' => VerifactuFormatter::fmt2($grossTotal),
                ];
            } elseif ($rectifyMode === 'I') {
                /**
                 * Segun la documentación AEAT:
                 * no hay que enviar ImporteRectificacion en las rectificativas de diferencias (I)
                 */
            }
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
        $issuerNif = (string)$in['issuer_nif'];
        $issuerName = (string)$in['issuer_name'];
        $fullNumber = (string)$in['full_invoice_number'];
        $issueDate = (string)$in['issue_date'];
        $prevHash = $in['prev_hash'] ?? null;
        $hash = (string)$in['hash'];
        $generatedAt = (string)$in['datetime_offset'];

        /** @var CancellationMode $mode */
        $mode = $in['cancellation_mode'] ?? CancellationMode::AEAT_REGISTERED;

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
        $flags = [];

        switch ($mode) {
            case CancellationMode::NO_AEAT_RECORD:
                // No existe registro previo en AEAT → "SinRegistroPrevio"
                $flags['SinRegistroPrevio'] = 'S';

                break;

            case CancellationMode::PREVIOUS_CANCELLATION_REJECTED:
                // Hubo una anulación rechazada previamente → "RechazoPrevio"
                $flags['RechazoPrevio'] = 'S';

                break;

            case CancellationMode::AEAT_REGISTERED:
            default:
                // Caso normal: registro de alta previo aceptado en AEAT.
                // No enviamos flags adicionales.
                break;
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
                'SistemaInformatico'       => $this->buildSoftwareSystemBlock(),
                'FechaHoraHusoGenRegistro' => $generatedAt,
                'TipoHuella'               => '01',
                'Huella'                   => $hash,
            ],
        ];

        if (count($flags) > 0) {
            $registroAnulacion['RegistroAnulacion'] = array_merge(
                $registroAnulacion['RegistroAnulacion'],
                $flags
            );
        }

        return array_merge(
            $cabecera,
            ['RegistroFactura' => $registroAnulacion]
        );
    }
}
