<?php

declare(strict_types=1);

namespace App\Services;

final class VerifactuAeatPayloadBuilder
{
    public static function toAeatDate(string $yyyy_mm_dd): string
    {
        $p = explode('-', $yyyy_mm_dd);
        return count($p) === 3 ? "{$p[2]}-{$p[1]}-{$p[0]}" : $yyyy_mm_dd; // dd-mm-YYYY
    }

    private static function enc(array $in): array
    {
        if (!empty($in['prev_hash'])) {
            return [
                'RegistroAnterior' => [
                    'IDEmisorFactura'        => (string)$in['issuer_nif'],
                    'NumSerieFactura'        => (string)$in['num_serie_factura'],
                    'FechaExpedicionFactura' => self::toAeatDate((string)$in['issue_date']),
                    'Huella'                 => (string)$in['prev_hash'],
                ],
            ];
        }
        return ['PrimerRegistro' => 'S'];
    }

    protected function getNumeroInstalacion(): string
    {
        //TODO meter aqui seun company_id o similar
        return str_pad((string) 999, 4, '0', STR_PAD_LEFT);
    }

    private function buildSistemaInformatico(): array
    {
        return [
            'NombreRazon' => 'Mytransfer APP SL', // Alfanumérico (120) Nombre-razón social de la persona o entidad productora (* NOTA: dato de la persona o entidad productora del sistema informático de facturación (SIF) empleado. En el caso de haber varios productores (por ejemplo, cuando el SIF consta de varios componentes de distintos productores) se deberán consignar los datos del productor responsable del componente principal del SIF, según la definición dada en el artículo 1.2.c) de esta orden.).
            'NIF' => 'B56893324', //FormatoNIF (9) //NIF de la persona o entidad productora (* NOTA: dato de la persona o entidad productora del sistema informático de facturación (SIF) empleado. En el caso de haber varios productores (por ejemplo, cuando el SIF consta de varios componentes de distintos productores) se deberán consignar los datos del productor responsable del componente principal del SIF, según la definición dada en el artículo 1.2.c) de esta orden.).
            'NombreSistemaInformatico' => 'MyTransferApp', //Alfanumérico (30) Nombre dado por la persona o entidad productora a su sistema informático de facturación (SIF) que, una vez instalado, se constituye en el SIF utilizado. Obligatorio en registros de facturación de alta y de anulación, y opcional en registros de evento.
            'IdSistemaInformatico' => '77', //Alfanumérico (2) Código identificativo dado por la persona o entidad productora a su sistema informático de facturación (SIF) que, una vez instalado, se constituye en el SIF utilizado. Deberá distinguirlo de otros posibles SIF distintos que produzca esta misma persona o entidad productora. Se detallarán las posibles restricciones a sus valores en la documentación correspondiente en la sede electrónica de la AEAT (documento de validaciones...).
            'Version' => '1.0.3', //Alfanumérico (50) Identificación de la versión del sistema informático de facturación (SIF) que se ejecuta en el sistema informático de facturación utilizado.
            'NumeroInstalacion' => $this->getNumeroInstalacion(), //Alfanumérico (100) Número de instalación del sistema informático de facturación (SIF) utilizado. Deberá distinguirlo de otros posibles SIF utilizados para realizar la facturación del obligado a expedir facturas, es decir, de otras posibles instalaciones de SIF pasadas, presentes o futuras utilizadas para realizar la facturación del obligado a expedir facturas, incluso aunque en dichas instalaciones se emplee el mismo SIF de un productor.
            'TipoUsoPosibleSoloVerifactu' => 'S', //Alfanumérico (1) L4 (S|N) Especifica si para cumplir el Reglamento el sistema informático de facturación solo puede funcionar exclusivamente como «VERI*FACTU» (valor "S") o puede funcionar también como «NO VERI*FACTU» (valor "N"). Obligatorio en registros de facturación de alta y de anulación. No aplica en registros de evento.
            'TipoUsoPosibleMultiOT' => 'S', //Alfanumérico (1) L4 (S|N) Especifica si el sistema informático de facturación permite llevar independientemente la facturación de varios obligados tributarios (valor "S") o solo de uno (valor "N"). Obligatorio en registros de facturación de alta y de anulación, y opcional en registros de evento.
            'IndicadorMultiplesOT' => 'S' //Alfanumérico (1) L4 (S|N) Indicador de que el sistema informático, en el momento de la generación de este registro, está soportando la facturación de más de un obligado tributario. Este valor deberá obtenerlo automáticamente el sistema informático a partir del número de obligados tributarios contenidos y/o gestionados en él en ese momento, independientemente de su estado operativo (alta, baja...), no pudiendo obtenerse a partir de otra información ni ser introducido directamente por el usuario del sistema informático ni cambiado por él. El valor "N" significará que el sistema informático solo contiene y/o gestiona un único obligado tributario (de alta o de baja o en cualquier otro estado), que se corresponderá con el obligado a expedir factura de este registro de facturación. En cualquier otro caso, se deberá informar este campo con el valor "S". Obligatorio en registros de facturación de alta y de anulación, y opcional en registros de evento.
        ];
    }


    public function buildAlta(array $in): array
    {
        $enc = self::enc($in);

        $detalleDesglose = [
            'TipoImpositivo' => '00',
            'BaseImponible'  => (float)($in['importe_total'] ?? 0.0),
            'CuotaRepercutida' => 0.0,
        ];

        return [
            'Cabecera' => [
                'ObligadoEmision' => [
                    'NombreRazon' => 'Mytransfer APP', //(string)($in['issuer_name'] ?? 'Empresa'),
                    'NIF'         => 'B56893324', // (string)$in['issuer_nif'],
                ],
            ],
            'RegistroFactura' => [
                'RegistroAlta' => [
                    'IDVersion' => '1.0',
                    'IDFactura' => [
                        'IDEmisorFactura'        => (string)$in['issuer_nif'],
                        'NumSerieFactura'        => (string)$in['num_serie_factura'],
                        'FechaExpedicionFactura' => self::toAeatDate((string)$in['issue_date']),
                    ],
                    'NombreRazonEmisor'      => (string)($in['issuer_name'] ?? 'Empresa'),
                    'TipoFactura'            => (string)($in['tipo_factura'] ?? 'F1'),
                    'DescripcionOperacion' => 'TODO CAMBIAR ESTO',
                    // TODO: Destinatarios / Desglose reales


                    'Desglose' => [
                        'DetalleDesglose' => $detalleDesglose
                    ],
                    'CuotaTotal'             => (float)($in['cuota_total']   ?? 0.0),
                    'ImporteTotal'           => (float)($in['importe_total'] ?? 0.0),
                    'Encadenamiento'         => $enc,
                    'FechaHoraHusoGenRegistro' => (new \DateTime('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m-d\TH:i:sP'),
                    'TipoHuella'             => '01',
                    'Huella'                 => (string)$in['huella'],
                    'SistemaInformatico'      => $this->buildSistemaInformatico(),
                ],
            ],
        ];
    }
}
