<?php

declare(strict_types=1);

namespace Tests\App\Services;

use App\Services\VerifactuAeatPayloadBuilder;
use CodeIgniter\Test\CIUnitTestCase;

final class VerifactuAeatPayloadBuilderTest extends CIUnitTestCase
{
    public function test_it_builds_f1_alta_payload_happy_path(): void
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
            'prev_hash'       => null,
            'hash'            => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
            'datetime_offset' => '2025-11-13T10:00:00+01:00',
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

    public function test_it_builds_r2_rectificativa_substitution_payload(): void
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

    public function test_it_builds_r3_rectificativa_difference_payload(): void
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

    public function test_it_builds_cancellation_payload_as_first_in_chain(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B56893324',
            'issuer_name'         => 'Mytransfer APP S.L.',
            'full_invoice_number' => 'F100',
            'issue_date'          => '2025-11-19',
            'prev_hash'           => null,
            'hash'                => 'CANCELHASH1234567890CANCELHASH1234567890CANCELHASH1234567890CANCEL',
            'datetime_offset'     => '2025-11-19T12:00:00+01:00',
            'cancellation_mode'   => \App\Domain\Verifactu\CancellationMode::AEAT_REGISTERED,
        ];

        $payload = $builder->buildCancellation($in);

        // 1) Cabecera ObligadoEmision
        $this->assertSame('Mytransfer APP S.L.', $payload['Cabecera']['ObligadoEmision']['NombreRazon']);
        $this->assertSame('B56893324', $payload['Cabecera']['ObligadoEmision']['NIF']);

        // 2) RegistroAnulacion
        $this->assertArrayHasKey('RegistroFactura', $payload);
        $this->assertArrayHasKey('RegistroAnulacion', $payload['RegistroFactura']);

        $regAnul = $payload['RegistroFactura']['RegistroAnulacion'];

        // IDFactura (bloque de la factura anulada)
        $this->assertArrayHasKey('IDFactura', $regAnul);
        $idFact = $regAnul['IDFactura'];

        $this->assertSame('B56893324', $idFact['IDEmisorFacturaAnulada']);
        $this->assertSame('F100', $idFact['NumSerieFacturaAnulada']);
        $this->assertSame('19-11-2025', $idFact['FechaExpedicionFacturaAnulada']);

        // 3) Encadenamiento: como prev_hash = null, debe ser PrimerRegistro
        $this->assertArrayHasKey('Encadenamiento', $regAnul);
        $enc = $regAnul['Encadenamiento'];

        $this->assertArrayHasKey('PrimerRegistro', $enc);
        $this->assertSame('S', $enc['PrimerRegistro']);

        // 4) Huella y FechaHoraHusoGenRegistro
        $this->assertSame('01', $regAnul['TipoHuella']);
        $this->assertSame($in['hash'], $regAnul['Huella']);
        $this->assertSame($in['datetime_offset'], $regAnul['FechaHoraHusoGenRegistro']);

        // 5) SistemaInformatico presente
        $this->assertArrayHasKey('SistemaInformatico', $regAnul);
        $sis = $regAnul['SistemaInformatico'];
        $this->assertArrayHasKey('NombreRazon', $sis);
        $this->assertArrayHasKey('NIF', $sis);
        $this->assertArrayHasKey('NombreSistemaInformatico', $sis);
        $this->assertArrayHasKey('IdSistemaInformatico', $sis);
        $this->assertArrayHasKey('Version', $sis);
        $this->assertArrayHasKey('NumeroInstalacion', $sis);
    }


    public function test_it_builds_cancellation_payload_with_previous_hash(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B56893324',
            'issuer_name'         => 'Mytransfer APP S.L.',
            'full_invoice_number' => 'F100',
            'issue_date'          => '2025-11-19',
            'hash'                => 'NEWHASH9999999999NEWHASH9999999999NEWHASH9999999999NEWHASH999999',
            'prev_hash'           => 'OLDHASH1111111111OLDHASH1111111111OLDHASH1111111111OLDHASH111111',
            'datetime_offset'     => '2025-11-19T13:00:00+01:00',
            'cancellation_mode'   => \App\Domain\Verifactu\CancellationMode::AEAT_REGISTERED,
        ];

        $payload = $builder->buildCancellation($in);

        $this->assertArrayHasKey('RegistroFactura', $payload);
        $this->assertArrayHasKey('RegistroAnulacion', $payload['RegistroFactura']);

        $regAnul = $payload['RegistroFactura']['RegistroAnulacion'];

        // IDFactura (bloque de la anulada)
        $this->assertArrayHasKey('IDFactura', $regAnul);
        $idFact = $regAnul['IDFactura'];

        $this->assertSame('B56893324', $idFact['IDEmisorFacturaAnulada']);
        $this->assertSame('F100', $idFact['NumSerieFacturaAnulada']);
        $this->assertSame('19-11-2025', $idFact['FechaExpedicionFacturaAnulada']);

        // Encadenamiento: ahora debe existir RegistroAnterior
        $this->assertArrayHasKey('Encadenamiento', $regAnul);
        $enc = $regAnul['Encadenamiento'];

        $this->assertArrayHasKey('RegistroAnterior', $enc);
        $prev = $enc['RegistroAnterior'];

        $this->assertSame('B56893324', $prev['IDEmisorFactura']);
        $this->assertSame('F100', $prev['NumSerieFactura']);
        $this->assertSame('19-11-2025', $prev['FechaExpedicionFactura']);
        $this->assertSame($in['prev_hash'], $prev['Huella']);

        // Huella + fecha generación
        $this->assertSame('01', $regAnul['TipoHuella']);
        $this->assertSame($in['hash'], $regAnul['Huella']);
        $this->assertSame($in['datetime_offset'], $regAnul['FechaHoraHusoGenRegistro']);
    }

    public function test_it_builds_f2_alta_without_recipient(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B56893324',
            'issuer_name'         => 'Mytransfer APP S.L.',
            'full_invoice_number' => 'TCK100',
            'issue_date'          => '2025-11-19',
            'invoice_type'        => 'F2',
            'description'         => 'Factura simplificada sin destinatario',
            // OJO: NO pasamos 'recipient' → F2 sin destinatario
            'lines' => [
                [
                    'desc'     => 'Servicio 1',
                    'qty'      => 1,
                    'price'    => 100.0, // base imponible
                    'vat'      => 21,
                    'discount' => 0.0,
                ],
            ],
            'prev_hash'       => null,
            'hash'            => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
            'datetime_offset' => '2025-11-19T10:00:00+01:00',
        ];

        $payload = $builder->buildRegistration($in);

        // 1) Cabecera ObligadoEmision
        $this->assertSame('Mytransfer APP S.L.', $payload['Cabecera']['ObligadoEmision']['NombreRazon']);
        $this->assertSame('B56893324', $payload['Cabecera']['ObligadoEmision']['NIF']);

        // 2) RegistroAlta + TipoFactura = F2
        $registroAlta = $payload['RegistroFactura']['RegistroAlta'];

        $this->assertSame('F2', $registroAlta['TipoFactura']);
        $this->assertSame('TCK100', $registroAlta['IDFactura']['NumSerieFactura']);
        $this->assertSame('19-11-2025', $registroAlta['IDFactura']['FechaExpedicionFactura']);

        // 3) Desglose: un solo tramo al 21%
        $desglose = $registroAlta['Desglose']['DetalleDesglose'];
        $this->assertCount(1, $desglose);

        $detail = $desglose[0];
        $this->assertSame('01', $detail['ClaveRegimen']);
        $this->assertSame('S1', $detail['CalificacionOperacion']);
        $this->assertSame(21.0, $detail['TipoImpositivo']);
        $this->assertSame(100.0, $detail['BaseImponibleOimporteNoSujeto']);
        $this->assertSame(21.0, $detail['CuotaRepercutida']);

        // 4) Totales
        $this->assertSame('21.00', $registroAlta['CuotaTotal']);
        $this->assertSame('121.00', $registroAlta['ImporteTotal']);

        // 5) F2 sin destinatario → NO debe existir 'Destinatarios'
        $this->assertArrayNotHasKey('Destinatarios', $registroAlta);

        // 6) Encadenamiento / huella
        $enc = $registroAlta['Encadenamiento'];
        $this->assertArrayHasKey('PrimerRegistro', $enc);
        $this->assertSame('S', $enc['PrimerRegistro']);

        $this->assertSame('01', $registroAlta['TipoHuella']);
        $this->assertSame($in['hash'], $registroAlta['Huella']);
        $this->assertSame($in['datetime_offset'], $registroAlta['FechaHoraHusoGenRegistro']);

        // 7) SistemaInformatico (solo comprobamos que tenga las claves)
        $sis = $registroAlta['SistemaInformatico'];
        $this->assertArrayHasKey('NombreRazon', $sis);
        $this->assertArrayHasKey('NIF', $sis);
        $this->assertArrayHasKey('NombreSistemaInformatico', $sis);
        $this->assertArrayHasKey('IdSistemaInformatico', $sis);
        $this->assertArrayHasKey('Version', $sis);
        $this->assertArrayHasKey('NumeroInstalacion', $sis);
    }

    public function test_it_builds_f3_alta_with_recipient(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B56893324',
            'issuer_name'         => 'Mytransfer APP S.L.',
            'full_invoice_number' => 'F3001',
            'issue_date'          => '2025-11-20',
            'invoice_type'        => 'F3',
            'description'         => 'Factura F3 con destinatario',
            'recipient'           => [
                'name'     => 'Cliente Demo S.L.',
                'nif'      => 'B12345678',
                'country'  => 'ES',
                'idType'   => null,
                'idNumber' => null,
            ],
            'lines' => [
                [
                    'desc'     => 'Servicio 1',
                    'qty'      => 1,
                    'price'    => 200.0, // base imponible
                    'vat'      => 21,
                    'discount' => 0.0,
                ],
            ],
            'prev_hash'       => null,
            'hash'            => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
            'datetime_offset' => '2025-11-20T10:00:00+01:00',
        ];

        $payload = $builder->buildRegistration($in);

        // 1) Cabecera ObligadoEmision
        $this->assertSame('Mytransfer APP S.L.', $payload['Cabecera']['ObligadoEmision']['NombreRazon']);
        $this->assertSame('B56893324', $payload['Cabecera']['ObligadoEmision']['NIF']);

        // 2) RegistroAlta + TipoFactura = F3
        $registroAlta = $payload['RegistroFactura']['RegistroAlta'];

        $this->assertSame('F3', $registroAlta['TipoFactura']);
        $this->assertSame('F3001', $registroAlta['IDFactura']['NumSerieFactura']);
        $this->assertSame('20-11-2025', $registroAlta['IDFactura']['FechaExpedicionFactura']);

        // 3) Destinatarios: DEBE existir y llevar NIF + nombre
        $this->assertArrayHasKey('Destinatarios', $registroAlta);
        $this->assertArrayHasKey('IDDestinatario', $registroAlta['Destinatarios']);

        $idDest = $registroAlta['Destinatarios']['IDDestinatario'];
        $this->assertSame('Cliente Demo S.L.', $idDest['NombreRazon']);
        $this->assertSame('B12345678', $idDest['NIF']);

        // 4) Desglose 21%
        $desglose = $registroAlta['Desglose']['DetalleDesglose'];
        $this->assertCount(1, $desglose);

        $detail = $desglose[0];
        $this->assertSame('01', $detail['ClaveRegimen']);
        $this->assertSame('S1', $detail['CalificacionOperacion']);
        $this->assertSame(21.0, $detail['TipoImpositivo']);
        $this->assertSame(200.0, $detail['BaseImponibleOimporteNoSujeto']);
        $this->assertSame(42.0, $detail['CuotaRepercutida']);

        // 5) Totales
        $this->assertSame('42.00', $registroAlta['CuotaTotal']);
        $this->assertSame('242.00', $registroAlta['ImporteTotal']);

        // 6) Encadenamiento / Huella
        $enc = $registroAlta['Encadenamiento'];
        $this->assertArrayHasKey('PrimerRegistro', $enc);
        $this->assertSame('S', $enc['PrimerRegistro']);

        $this->assertSame('01', $registroAlta['TipoHuella']);
        $this->assertSame($in['hash'], $registroAlta['Huella']);
        $this->assertSame($in['datetime_offset'], $registroAlta['FechaHoraHusoGenRegistro']);

        // 7) SistemaInformatico (solo presencia de claves)
        $sis = $registroAlta['SistemaInformatico'];
        $this->assertArrayHasKey('NombreRazon', $sis);
        $this->assertArrayHasKey('NIF', $sis);
        $this->assertArrayHasKey('NombreSistemaInformatico', $sis);
        $this->assertArrayHasKey('IdSistemaInformatico', $sis);
        $this->assertArrayHasKey('Version', $sis);
        $this->assertArrayHasKey('NumeroInstalacion', $sis);
    }

    public function test_it_builds_f1_alta_with_idotro_recipient(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B56893324',
            'issuer_name'         => 'Test S.L.',
            'full_invoice_number' => 'X100',
            'issue_date'          => '2025-11-20',
            'invoice_type'        => 'F1',
            'description'         => 'Servicio internacional',
            'recipient'           => [
                'name'     => 'John Smith',
                'nif'      => null,
                'country'  => 'GB',
                'idType'   => '02',
                'idNumber' => 'AB1234567',
            ],
            'lines' => [
                [
                    'desc'  => 'Servicio internacional',
                    'qty'   => 1,
                    'price' => 200.0,
                    'vat'   => 21,
                ],
            ],
            'prev_hash'       => null,
            'hash'            => 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF',
            'datetime_offset' => '2025-11-20T10:00:00+01:00',
        ];

        $payload = $builder->buildRegistration($in);

        $registro = $payload['RegistroFactura']['RegistroAlta'];

        $dest = $registro['Destinatarios']['IDDestinatario'];

        // No NIF
        $this->assertArrayNotHasKey('NIF', $dest);

        // Sí IDOtro
        $this->assertArrayHasKey('IDOtro', $dest);
        $ido = $dest['IDOtro'];

        $this->assertSame('GB', $ido['CodigoPais']);
        $this->assertSame('02', $ido['IDType']);
        $this->assertSame('AB1234567', $ido['ID']);
    }

    public function test_it_builds_r5_substitution_over_simplified_ticket(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B61206934',
            'issuer_name'         => 'ACME S.L.',
            'full_invoice_number' => 'R5001',
            'issue_date'          => '2025-11-20',
            'invoice_type'        => 'R5',
            'description'         => 'Rectificación ticket simplificado',
            // Totales de la rectificativa
            'detail' => [
                [
                    'ClaveRegimen'                  => '01',
                    'CalificacionOperacion'         => 'S1',
                    'TipoImpositivo'                => 21.0,
                    'BaseImponibleOimporteNoSujeto' => 80.0,
                    'CuotaRepercutida'              => 16.8,
                ],
            ],
            'vat_total'       => 16.80,
            'gross_total'     => 96.80,
            'prev_hash'       => null,
            'hash'            => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
            'datetime_offset' => '2025-11-20T10:00:00+01:00',

            // Rectificación de una F2 ya emitida
            'rectify_mode'       => 'S', // sustitución
            'rectified_invoices' => [
                [
                    'issuer_nif' => 'B61206934',
                    'series'     => 'F2',
                    'number'     => 50,
                    'issueDate'  => '2025-11-19',
                ],
            ],

            // OJO: F2/R5 no llevan destinatario → no pasamos 'recipient'
        ];

        $payload = $builder->buildRegistration($in);

        $registro = $payload['RegistroFactura']['RegistroAlta'];

        // TipoFactura R5
        $this->assertSame('R5', $registro['TipoFactura']);

        // No debe haber Destinatarios (F2/R5)
        $this->assertArrayNotHasKey('Destinatarios', $registro);

        // Factura rectificada
        $this->assertArrayHasKey('FacturaRectificada', $registro);
        $fr = $registro['FacturaRectificada'];

        $this->assertSame('B61206934', $fr['IDEmisorFactura']);
        $this->assertSame('F250', $fr['NumSerieFactura']);
        $this->assertSame('19-11-2025', $fr['FechaExpedicionFactura']);

        // ImporteRectificacion obligatorio en modo 'S'
        $this->assertArrayHasKey('ImporteRectificacion', $registro);
        $imp = $registro['ImporteRectificacion'];

        $this->assertSame('80.00', $imp['BaseRectificada']);
        $this->assertSame('16.80', $imp['CuotaRectificada']);
        $this->assertSame('96.80', $imp['ImporteRectificacion']);
    }

    public function test_it_builds_r5_difference_over_simplified_ticket_without_importe_rectificacion(): void
    {
        $builder = new VerifactuAeatPayloadBuilder();

        $in = [
            'issuer_nif'          => 'B61206934',
            'issuer_name'         => 'ACME S.L.',
            'full_invoice_number' => 'R5002',
            'issue_date'          => '2025-11-20',
            'invoice_type'        => 'R5',
            'description'         => 'Rectificación ticket simplificado (diferencias)',
            'detail'              => [
                [
                    'ClaveRegimen'                  => '01',
                    'CalificacionOperacion'         => 'S1',
                    'TipoImpositivo'                => 21.0,
                    'BaseImponibleOimporteNoSujeto' => 10.0,
                    'CuotaRepercutida'              => 2.1,
                ],
            ],
            'vat_total'       => 2.10,
            'gross_total'     => 12.10,
            'prev_hash'       => 'AAAABBBBCCCCDDDDEEEE',
            'hash'            => 'FFFEEE1111222233334444555566667777888899990000AAAABBBBCCCCDDDD',
            'datetime_offset' => '2025-11-20T10:05:00+01:00',

            'rectify_mode'       => 'I', // diferencias
            'rectified_invoices' => [
                [
                    'issuer_nif' => 'B61206934',
                    'series'     => 'F2',
                    'number'     => 51,
                    'issueDate'  => '2025-11-19',
                ],
            ],
        ];

        $payload = $builder->buildRegistration($in);

        $registro = $payload['RegistroFactura']['RegistroAlta'];

        $this->assertSame('R5', $registro['TipoFactura']);

        // Factura rectificada presente
        $this->assertArrayHasKey('FacturaRectificada', $registro);

        // En modo 'I' NO debe existir ImporteRectificacion
        $this->assertArrayNotHasKey('ImporteRectificacion', $registro);
    }
}
