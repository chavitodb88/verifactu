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
            'issuer_nif'        => 'B56893324',
            'issuer_name'       => 'Mytransfer APP S.L.',
            'num_serie_factura' => 'F100',
            'issue_date'        => '2025-11-13',
            'tipo_factura'      => 'F1',
            'descripcion'       => 'Servicio de transporte',
            'lines'             => [
                [
                    'desc'     => 'Servicio 1',
                    'qty'      => 1,
                    'price'    => 100.0,
                    'vat'      => 21,
                    'discount' => 0.0,
                ],
            ],
            'prev_hash'         => null,
            'huella'            => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
            'fecha_huso'        => '2025-11-13T10:00:00+01:00',
        ];

        $payload = $builder->buildAlta($in);

        // 1) Cabecera ObligadoEmision
        $this->assertSame('Mytransfer APP S.L.', $payload['Cabecera']['ObligadoEmision']['NombreRazon']);
        $this->assertSame('B56893324', $payload['Cabecera']['ObligadoEmision']['NIF']);

        // 2) IDFactura
        $idFactura = $payload['RegistroFactura']['RegistroAlta']['IDFactura'];
        $this->assertSame('B56893324', $idFactura['IDEmisorFactura']);
        $this->assertSame('F100', $idFactura['NumSerieFactura']);
        // buildAlta usa toAeatDate => dd-mm-YYYY
        $this->assertSame('13-11-2025', $idFactura['FechaExpedicionFactura']);

        // 3) Desglose
        $desglose = $payload['RegistroFactura']['RegistroAlta']['Desglose']['DetalleDesglose'];
        $this->assertCount(1, $desglose);

        $detalle = $desglose[0];
        $this->assertSame('01', $detalle['ClaveRegimen']);
        $this->assertSame('S1', $detalle['CalificacionOperacion']);

        $this->assertSame(21.0, $detalle['TipoImpositivo']);
        $this->assertSame(100.0, $detalle['BaseImponibleOimporteNoSujeto']);
        $this->assertSame(21.0, $detalle['CuotaRepercutida']);

        // 4) Totales
        $registro = $payload['RegistroFactura']['RegistroAlta'];
        $this->assertSame("21.00", $registro['CuotaTotal']);
        $this->assertSame("121.00", $registro['ImporteTotal']);

        // 5) Encadenamiento: como prev_hash = null, debe ser PrimerRegistro
        $enc = $registro['Encadenamiento'];
        $this->assertArrayHasKey('PrimerRegistro', $enc);
        $this->assertSame('S', $enc['PrimerRegistro']);

        // 6) Huella y FechaHoraHusoGenRegistro
        $this->assertSame('01', $registro['TipoHuella']);
        $this->assertSame($in['huella'], $registro['Huella']);
        $this->assertSame($in['fecha_huso'], $registro['FechaHoraHusoGenRegistro']);

        // 7) SistemaInformatico
        $sis = $registro['SistemaInformatico'];
        $this->assertArrayHasKey('NombreRazon', $sis);
        $this->assertArrayHasKey('NIF', $sis);
        $this->assertArrayHasKey('NombreSistemaInformatico', $sis);
        $this->assertArrayHasKey('IdSistemaInformatico', $sis);
        $this->assertArrayHasKey('Version', $sis);
        $this->assertArrayHasKey('NumeroInstalacion', $sis);
    }
}
