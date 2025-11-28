<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\VerifactuFormatter;
use App\Services\VerifactuCanonicalService;
use CodeIgniter\Test\CIUnitTestCase;

final class VerifactuCanonicalServiceTest extends CIUnitTestCase
{
    public function test_it_builds_registration_chain_with_expected_string_and_hash(): void
    {
        $expectedCsv = 'IDEmisorFactura=B56893324&NumSerieFactura=F38&FechaExpedicionFactura=04-11-2025&TipoFactura=F1&CuotaTotal=21.00&ImporteTotal=121.00&Huella=20B7B977A747B3CBF0021542D83D5EBC95EA32389BDB1A626B8CCAA84D3428BB&FechaHoraHusoGenRegistro=2025-11-13T12:56:46+01:00';
        $expectedHash = 'C1F52F5A58BDC98B6D450073A470B0E77D5B95BBD854A10226E52C7232DD8AF7';
        $fixedTs     = '2025-11-13T12:56:46+01:00';

        $input = [
            'issuer_nif'          => 'B56893324',
            'full_invoice_number' => 'F38',
            'issue_date'          => '2025-11-04',
            'invoice_type'        => 'F1',
            'vat_total'           => 21.00,
            'gross_total'         => 121.00,
            'prev_hash'           => '20B7B977A747B3CBF0021542D83D5EBC95EA32389BDB1A626B8CCAA84D3428BB',
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

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$/',
            $ts,
            'El timestamp de huso no tiene formato esperado YYYY-MM-DDTHH:MM:SS+ZZ:ZZ'
        );
    }

    /**
     * Caso extremo: totales grandes con muchos decimales (simulando varios tipos de IVA).
     *
     * Aquí no verificamos la lógica de cálculo de totales (eso es cosa del builder),
     * sino que la cadena canónica respeta exactamente el resultado de VerifactuFormatter::fmt2
     * para importes grandes y con muchos decimales.
     */
    public function test_it_builds_registration_chain_for_big_amounts_with_rounding(): void
    {
        $fixedTs = '2025-11-20T10:00:00+01:00';

        // Simula un total de IVA y total factura que vienen de varios tipos de IVA
        // y con muchos decimales (antes de redondear).
        $rawVatTotal   = 123456.7891;
        $rawGrossTotal = 987654.3219;

        // Usamos el propio helper para no duplicar lógica de formateo
        $expectedVat   = VerifactuFormatter::fmt2($rawVatTotal);
        $expectedGross = VerifactuFormatter::fmt2($rawGrossTotal);

        $input = [
            'issuer_nif'          => 'B61206934',
            'full_invoice_number' => 'F2025-999',
            'issue_date'          => '2025-11-19',
            'invoice_type'        => 'F1',
            'vat_total'           => $rawVatTotal,
            'gross_total'         => $rawGrossTotal,
            'prev_hash'           => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
            'datetime_offset'     => $fixedTs,
        ];

        [$chain, $ts] = VerifactuCanonicalService::buildRegistrationChain($input);

        $expectedPrefix = 'IDEmisorFactura=B61206934'
            . '&NumSerieFactura=F2025-999'
            . '&FechaExpedicionFactura=19-11-2025'
            . '&TipoFactura=F1'
            . '&CuotaTotal=' . $expectedVat
            . '&ImporteTotal=' . $expectedGross
            . '&Huella=ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890'
            . '&FechaHoraHusoGenRegistro=' . $fixedTs;

        $this->assertSame(
            $expectedPrefix,
            $chain,
            'La cadena canónica para importes grandes y con muchos decimales no coincide'
        );

        $hash = VerifactuCanonicalService::sha256Upper($chain);
        $this->assertSame(
            strtoupper($hash),
            $hash,
            'La huella debe generarse en mayúsculas'
        );

        $this->assertSame($fixedTs, $ts, 'El timestamp devuelto debe ser el datetime_offset fijado');
    }

    /**
     * [TEST][Media]
     * Cadenas largas de encadenamiento: simulamos muchos eslabones y comprobamos
     * que cada cadena incluye correctamente el prev_hash del eslabón anterior.
     *
     * El chain_index real se calcula en BD, pero aquí validamos el comportamiento
     * básico de encadenamiento y que todas las huellas son únicas.
     */
    public function test_it_builds_registration_chain_for_long_hash_chain(): void
    {
        $issuerNif = 'B61206934';
        $issueDate = '2025-11-20';
        $fixedTs   = '2025-11-20T10:00:00+01:00';

        $prevHash = '';
        $hashes   = [];

        $numLinks = 50;

        for ($i = 1; $i <= $numLinks; $i++) {
            $fullNumber = 'F2025-' . $i;

            $input = [
                'issuer_nif'          => $issuerNif,
                'full_invoice_number' => $fullNumber,
                'issue_date'          => $issueDate,
                'invoice_type'        => 'F1',
                'vat_total'           => 21.00,
                'gross_total'         => 121.00,
                'prev_hash'           => $prevHash,
                'datetime_offset'     => $fixedTs,
            ];

            [$chain] = VerifactuCanonicalService::buildRegistrationChain($input);
            $currentHash = VerifactuCanonicalService::sha256Upper($chain);

            // El NumSerieFactura debe ser el esperado en cada eslabón
            $this->assertStringContainsString(
                'NumSerieFactura=' . $fullNumber,
                $chain,
                'La cadena canónica no contiene el NumSerieFactura esperado en el eslabón ' . $i
            );

            if ($i === 1) {
                // Primer eslabón: Huella vacía → "Huella=&FechaHoraHusoGenRegistro="
                $this->assertStringContainsString(
                    'Huella=&FechaHoraHusoGenRegistro=',
                    $chain,
                    'En el primer eslabón la huella debería estar vacía'
                );
            } else {
                // A partir del segundo: el prev_hash debe ser la huella del eslabón anterior
                $this->assertStringContainsString(
                    'Huella=' . $prevHash,
                    $chain,
                    'El eslabón ' . $i . ' no contiene la huella del eslabón anterior como prev_hash'
                );
            }

            $hashes[] = $currentHash;
            $prevHash = $currentHash; // siguiente eslabón encadena con este
        }

        // Todas las huellas de la cadena deben ser únicas (no se repiten hashes)
        $this->assertCount(
            $numLinks,
            $hashes,
            'El número de huellas generadas no coincide con el número de eslabones simulados'
        );
        $this->assertCount(
            $numLinks,
            array_unique($hashes),
            'Se han generado huellas duplicadas dentro de la cadena de encadenamiento'
        );
    }
}
