<?php

declare(strict_types=1);

namespace Tests\App\Services;

use App\Services\VerifactuAeatPayloadBuilder;
use CodeIgniter\Test\CIUnitTestCase;

final class VerifactuAeatPayloadBuilderTest extends CIUnitTestCase
{
    public function testBuildAltaHappyPath(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B56893324',
            'issuer_name'         => 'Mytransfer APP S.L.',
            'full_invoice_number' => 'F100',
            'issue_date'          => '2025-11-13',
            'invoice_type'        => 'F1',
            'description'         => 'Servicio de transporte',
            'lines'               => [
                [
                    'desc'     => 'Servicio 1',
                    'qty'      => 1,
                    'price'    => 100.0,
                    'vat'      => 21,
                    'discount' => 0.0,
                ],
            ],
            'prev_hash'              => null,
            'hash'                   => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
            'datetime_offset'        => '2025-11-13T10:00:00+01:00',
        ];

        $payload = $builder->buildRegistration($in);

        // 1) Cabecera ObligadoEmision
        $this->assertSame('Mytransfer APP S.L.', $payload['Cabecera']['ObligadoEmision']['NombreRazon']);
        $this->assertSame('B56893324', $payload['Cabecera']['ObligadoEmision']['NIF']);

        // 2) IDFactura
        $idFactura = $payload['RegistroFactura']['RegistroAlta']['IDFactura'];
        $this->assertSame('B56893324', $idFactura['IDEmisorFactura']);
        $this->assertSame('F100', $idFactura['NumSerieFactura']);
        // buildRegistration usa toAeatDate => dd-mm-YYYY
        $this->assertSame('13-11-2025', $idFactura['FechaExpedicionFactura']);

        // 3) Desglose
        $desglose = $payload['RegistroFactura']['RegistroAlta']['Desglose']['DetalleDesglose'];
        $this->assertCount(1, $desglose);

        $detail = $desglose[0];
        $this->assertSame('01', $detail['ClaveRegimen']);
        $this->assertSame('S1', $detail['CalificacionOperacion']);

        $this->assertSame(21.0, $detail['TipoImpositivo']);
        $this->assertSame(100.0, $detail['BaseImponibleOimporteNoSujeto']);
        $this->assertSame(21.0, $detail['CuotaRepercutida']);

        // 4) Totales
        $registro = $payload['RegistroFactura']['RegistroAlta'];
        $this->assertSame('21.00', $registro['CuotaTotal']);
        $this->assertSame('121.00', $registro['ImporteTotal']);

        // 5) Encadenamiento: como prev_hash = null, debe ser PrimerRegistro
        $enc = $registro['Encadenamiento'];
        $this->assertArrayHasKey('PrimerRegistro', $enc);
        $this->assertSame('S', $enc['PrimerRegistro']);

        // 6) Huella y FechaHoraHusoGenRegistro
        $this->assertSame('01', $registro['TipoHuella']);
        $this->assertSame($in['hash'], $registro['Huella']);
        $this->assertSame($in['datetime_offset'], $registro['FechaHoraHusoGenRegistro']);

        // 7) SistemaInformatico
        $sis = $registro['SistemaInformatico'];
        $this->assertArrayHasKey('NombreRazon', $sis);
        $this->assertArrayHasKey('NIF', $sis);
        $this->assertArrayHasKey('NombreSistemaInformatico', $sis);
        $this->assertArrayHasKey('IdSistemaInformatico', $sis);
        $this->assertArrayHasKey('Version', $sis);
        $this->assertArrayHasKey('NumeroInstalacion', $sis);
    }

    public function testBuildRectificativaSubstitutionR2(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B56893324',
            'issuer_name'         => 'Mytransfer APP S.L.',
            'full_invoice_number' => 'R2001',
            'issue_date'          => '2025-11-19',
            'invoice_type'        => 'R2',
            'description'         => 'Rectificativa sustitutiva',
            'lines'               => [
                [
                    'desc'     => 'Servicio rectificado',
                    'qty'      => 1,
                    'price'    => 100.0,
                    'vat'      => 21,
                    'discount' => 0.0,
                ],
            ],
            'prev_hash'       => null,
            'hash'            => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
            'datetime_offset' => '2025-11-19T10:00:00+01:00',

            // Bloque de rectificación
            'rectify_mode'       => 'S', // Sustitución
            'rectified_invoices' => [
                [
                    'issuer_nif' => 'B56893324',
                    'series'     => 'F',
                    'number'     => 62,
                    'issueDate'  => '2025-11-19',
                ],
            ],
        ];

        $payload = $builder->buildRegistration($in);

        $registro = $payload['RegistroFactura']['RegistroAlta'];

        // Tipo de factura y tipo rectificativa
        $this->assertSame('R2', $registro['TipoFactura']);
        $this->assertSame('S', $registro['TipoRectificativa']);

        // Bloque FacturasRectificadas
        $this->assertArrayHasKey('FacturasRectificadas', $registro);
        $ids = $registro['FacturasRectificadas']['IDFacturaRectificada'];
        $this->assertIsArray($ids);
        $this->assertCount(1, $ids);

        $id0 = $ids[0];
        $this->assertSame('B56893324', $id0['IDEmisorFactura']);
        $this->assertSame('F62', $id0['NumSerieFactura']);
        $this->assertSame('19-11-2025', $id0['FechaExpedicionFactura']);

        // Totales esperados de la rectificativa:
        // base 100, IVA 21% -> cuota 21, importe 121
        $importe = $registro['ImporteRectificacion'];
        $this->assertSame('100.00', $importe['BaseRectificada']);
        $this->assertSame('21.00', $importe['CuotaRectificada']);
        $this->assertSame('121.00', $importe['ImporteRectificacion']);
    }

    public function testBuildRectificativaDifferenceR3(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B56893324',
            'issuer_name'         => 'Mytransfer APP S.L.',
            'full_invoice_number' => 'R3001',
            'issue_date'          => '2025-11-20',
            'invoice_type'        => 'R3',
            'description'         => 'Rectificativa por diferencias',
            'lines'               => [
                [
                    'desc'     => 'Rectificación servicio',
                    'qty'      => 1,
                    'price'    => 80.0,
                    'vat'      => 21,
                    'discount' => 0.0,
                ],
            ],
            'prev_hash'       => null,
            'hash'            => 'FEDCBA0987654321FEDCBA0987654321FEDCBA0987654321FEDCBA0987654321',
            'datetime_offset' => '2025-11-20T10:00:00+01:00',

            'rectify_mode'       => 'I', // Diferencias
            'rectified_invoices' => [
                [
                    'issuer_nif' => 'B56893324',
                    'series'     => 'F',
                    'number'     => 62,
                    'issueDate'  => '2025-11-19',
                ],
            ],
        ];

        $payload = $builder->buildRegistration($in);

        $registro = $payload['RegistroFactura']['RegistroAlta'];

        // Tipo de factura y tipo rectificativa
        $this->assertSame('R3', $registro['TipoFactura']);
        $this->assertSame('I', $registro['TipoRectificativa']);

        // Bloque FacturasRectificadas
        $this->assertArrayHasKey('FacturasRectificadas', $registro);
        $ids = $registro['FacturasRectificadas']['IDFacturaRectificada'];
        $this->assertIsArray($ids);
        $this->assertCount(1, $ids);

        $id0 = $ids[0];
        $this->assertSame('B56893324', $id0['IDEmisorFactura']);
        $this->assertSame('F62', $id0['NumSerieFactura']);
        $this->assertSame('19-11-2025', $id0['FechaExpedicionFactura']);

        $this->assertArrayNotHasKey('ImporteRectificacion', $registro);
    }

}
