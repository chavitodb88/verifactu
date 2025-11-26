<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\VerifactuCanonicalService;
use CodeIgniter\Test\CIUnitTestCase;

final class VerifactuCanonicalServiceTest extends CIUnitTestCase
{
    public function testBuildCadenaAltaMatchesExpectedStringAndHash(): void
    {
        $expectedCsv = 'IDEmisorFactura=B56893324&NumSerieFactura=F38&FechaExpedicionFactura=04-11-2025&TipoFactura=F1&CuotaTotal=21.00&ImporteTotal=121.00&Huella=20B7B977A747B3CBF0021542D83D5EBC95EA32389BDB1A626B8CCAA84D3428BB&FechaHoraHusoGenRegistro=2025-11-13T12:56:46+01:00';
        $expectedHash = 'C1F52F5A58BDC98B6D450073A470B0E77D5B95BBD854A10226E52C7232DD8AF7';
        $fixedTs = '2025-11-13T12:56:46+01:00';

        // Estos valores deben coincidir con los que usaste para esa factura.
        $input = [
            'issuer_nif'          => 'B56893324',            // NIF del obligado (NO el del productor)
            'full_invoice_number' => 'F38',                   // p.ej. "F20" o "F0005" (exacto al XML)
            'issue_date'          => '2025-11-04',   // YYYY-MM-DD
            'invoice_type'        => 'F1',
            'vat_total'           => 21.00,
            'gross_total'         => 121.00,
            'prev_hash'           => '20B7B977A747B3CBF0021542D83D5EBC95EA32389BDB1A626B8CCAA84D3428BB',            // o el prev_hash que tuviera
            'datetime_offset'     => $fixedTs,
        ];

        [$chain, $ts] = VerifactuCanonicalService::buildRegistrationChain($input);

        $this->assertSame(
            $expectedCsv,
            $chain,
            'La cadena canónica generada no coincide con la esperada'
        );

        $hash = VerifactuCanonicalService::sha256Upper($chain);

        $this->assertSame(
            $expectedHash,
            $hash,
            'La huella SHA-256 no coincide con la esperada'
        );

        // De regalo, comprobamos que la datetime_offset tiene formato ISO con zona
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$/',
            $ts,
            'El timestamp de huso no tiene formato esperado YYYY-MM-DDTHH:MM:SS+ZZ:ZZ'
        );
    }
}
