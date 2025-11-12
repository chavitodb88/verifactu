<?php

declare(strict_types=1);

namespace App\Services;

final class VerifactuAeatPayloadBuilder
{
    /** YYYY-MM-DD -> dd-mm-YYYY */
    public static function toAeatDate(string $yyyy_mm_dd): string
    {
        $p = explode('-', $yyyy_mm_dd);
        return count($p) === 3 ? "{$p[2]}-{$p[1]}-{$p[0]}" : $yyyy_mm_dd;
    }

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
                'IDEmisorFactura'        => 'B56893324',
                'NumSerieFactura'        => (string) $in['num_serie_factura'],
                'FechaExpedicionFactura' => self::toAeatDate((string) $in['issue_date']),
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
    public static function buildSistemaInformatico(array $si = []): array
    {
        // $si debe traer: nombre_razon, nif, nombre_sif, id_sif, version, numero_instalacion, solo_verifactu(S/N), multi_ot(S/N), multiples_ot(S/N)
        return [
            'NombreRazon'              => (string) ($si['nombre_razon'] ?? 'Mytransfer APP S'),
            'NIF'                      => (string) ($si['nif'] ?? 'B56893324'),
            'NombreSistemaInformatico' => (string) ($si['nombre_sif'] ?? 'MyTransferApp'),
            'IdSistemaInformatico'     => (string) ($si['id_sif'] ?? '77'),
            'Version'                  => (string) ($si['version'] ?? '1.0.3'),
            'NumeroInstalacion'        => (string) ($si['numero_instalacion'] ?? self::getNumeroInstalacion()),
            'TipoUsoPosibleSoloVerifactu' => (string) ($si['solo_verifactu'] ?? 'S'),
            'TipoUsoPosibleMultiOT'    => (string) ($si['multi_ot'] ?? 'S'),
            'IndicadorMultiplesOT'     => (string) ($si['multiples_ot'] ?? 'S'),
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
     *  - tipo_factura (por defecto 'F1')
     *  - lines (array para desglose)
     *  - prev_hash|null
     *  - huella (SHA-256 en mayúsculas de la cadena canónica)
     *  - sistema_informatico (array para buildSistemaInformatico)
     *  - descripcion (opcional)
     */
    public function buildAlta(array $in): array
    {
        $enc = self::buildEncadenamiento($in);

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
            $cuotaTotal   = (float)($in['cuota_total']   ?? 0.0);
            $importeTotal = (float)($in['importe_total'] ?? 0.0);
        } else {
            // Calcular desde lines
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

        return [
            'Cabecera' => [
                'ObligadoEmision' => [
                    'NombreRazon' => 'Mytransfer APP S.L.', //(string)($in['issuer_name'] ?? 'Mytransfer APP S.L.'),
                    'NIF'         => 'B56893324' //(string)($in['issuer_nif'] ?? 'B56893324'),
                ],
            ],
            'RegistroFactura' => [
                'RegistroAlta' => [
                    'IDVersion' => '1.0',
                    'IDFactura' => [
                        'IDEmisorFactura'        => 'B56893324', //(string)$in['issuer_nif'],
                        'NumSerieFactura'        => (string)$in['num_serie_factura'],
                        'FechaExpedicionFactura' => self::toAeatDate((string)$in['issue_date']),
                    ],
                    'NombreRazonEmisor'       => (string)($in['issuer_name'] ?? 'Empresa'),
                    'TipoFactura'             => (string)($in['tipo_factura'] ?? 'F1'),
                    'DescripcionOperacion'    => (string)($in['descripcion'] ?? 'Transferencia VTC'),
                    'Desglose' => [
                        'DetalleDesglose' => $detalle,
                    ],
                    'Destinatarios' => [
                        'IDDestinatario' => [
                            'NombreRazon' => 'Cliente prueba', //Alfanumérico (120) Nombre-razón social del destinatario (a veces también denominado contraparte, es decir, el cliente) de la operación.
                            'NIF' => 'B36864114', //FormatoNIF (9) Identificador del NIF del destinatario (a veces también denominado contraparte, es decir, el cliente) de la operación.
                            //TODO: ver como gestiono las facturas con clientes internacionales
                            // 'IDOtro1' => [
                            //     'CodigoPais' => '', //Alfanumérico (2) (ISO 3166-1 alpha-2 codes) Código del país del destinatario (a veces también denominado contraparte, es decir, el cliente) de la operación de la factura expedida.
                            //     'IDType' => '', //Alfanumérico (2) L7 Clave para establecer el tipo de identificación, en el país de residencia, del destinatario (a veces también denominado contraparte, es decir, el cliente) de la operación de la factura expedida.
                            //     'IDNumero' => '', //Alfanumérico (20) Número de identificación, en el país de residencia, del destinatario (a veces también denominado contraparte, es decir, el cliente) de la operación de la factura expedida.
                            // ]
                        ],
                    ],
                    'CuotaTotal'              => number_format($cuotaTotal, 2, '.', ''),
                    'ImporteTotal'            => number_format($importeTotal, 2, '.', ''),
                    'Encadenamiento'          => $enc,
                    'FechaHoraHusoGenRegistro' => (string)($in['fecha_huso'] ?? ''),
                    'TipoHuella'              => '01',
                    'Huella'                  => (string)$in['huella'],
                    'SistemaInformatico'      => $this->buildSistemaInformatico(),
                ],
            ],
        ];
    }
}
