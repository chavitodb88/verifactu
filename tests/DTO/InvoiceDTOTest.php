<?php

declare(strict_types=1);

namespace Tests\DTO;

use App\Domain\Verifactu\RectifyMode;
use App\DTO\InvoiceDTO;
use CodeIgniter\Test\CIUnitTestCase;

final class InvoiceDTOTest extends CIUnitTestCase
{
    public function test_it_maps_basic_fields_and_defaults(): void
    {
        $payload = [
            'issuer' => [
                'nif'  => 'b61206934',
                'name' => 'ACME S.L.',
            ],
            'series'      => 'F2025',
            'number'      => 10,
            'issueDate'   => '2025-11-20',
            'description' => 'Servicio de transporte',
            // sin invoiceType, taxRegimeCode ni operationQualification
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 100, 'vat' => 21],
            ],
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
        ];

        $dto = InvoiceDTO::fromArray($payload);

        // issuer
        $this->assertSame('B61206934', $dto->issuerNif);
        $this->assertSame('ACME S.L.', $dto->issuerName);

        // invoice core
        $this->assertSame('F2025', $dto->series);
        $this->assertSame(10, $dto->number);
        $this->assertSame('2025-11-20', $dto->issueDate);
        $this->assertSame('Servicio de transporte', $dto->description);

        // defaults
        $this->assertSame('F1', $dto->invoiceType);
        $this->assertSame('01', $dto->taxRegimeCode);
        $this->assertSame('S1', $dto->operationQualification);

        // lines
        $this->assertIsArray($dto->lines);
        $this->assertCount(1, $dto->lines);
        $this->assertEquals(21, $dto->lines[0]['vat']);
    }

    public function test_it_builds_recipient_with_nif(): void
    {
        $payload = [
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'ACME S.L.',
            ],
            'series'      => 'F2025',
            'number'      => 11,
            'issueDate'   => '2025-11-21',
            'invoiceType' => 'F1',
            'recipient' => [
                'name'       => 'Cliente Demo S.L.',
                'nif'        => 'b61206934',
                'country'    => 'ES',
                'address'    => 'C/ Mayor 1',
                'postalCode' => '28001',
                'city'       => 'Madrid',
                'province'   => 'Madrid',
            ],
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 50, 'vat' => 21],
            ],
        ];

        $dto = InvoiceDTO::fromArray($payload);

        $this->assertSame('Cliente Demo S.L.', $dto->recipientName);
        $this->assertSame('B61206934', $dto->recipientNif);
        $this->assertNull($dto->recipientIdNumber ?? null);
        $this->assertSame('ES', $dto->recipientCountry);
        $this->assertSame('C/ Mayor 1', $dto->recipientAddress);
        $this->assertSame('28001', $dto->recipientPostalCode);
        $this->assertSame('Madrid', $dto->recipientCity);
        $this->assertSame('Madrid', $dto->recipientProvince);
    }

    public function test_it_builds_recipient_with_idotro_when_no_nif(): void
    {
        $payload = [
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'ACME S.L.',
            ],
            'series'      => 'F2025',
            'number'      => 12,
            'issueDate'   => '2025-11-22',
            'invoiceType' => 'F1',
            'recipient' => [
                'name'     => 'John Smith',
                'nif'      => null,
                'country'  => 'GB',
                'idType'   => '02',
                'idNumber' => 'AB1234567',
            ],
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 80, 'vat' => 21],
            ],
        ];

        $dto = InvoiceDTO::fromArray($payload);

        $this->assertSame('John Smith', $dto->recipientName);
        $this->assertNull($dto->recipientNif);
        $this->assertSame('GB', $dto->recipientCountry);
        $this->assertSame('02', $dto->recipientIdType);
        $this->assertSame('AB1234567', $dto->recipientIdNumber);
    }

    public function test_it_throws_when_recipient_has_nif_and_idotro_together(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient cannot have both nif and IDOtro at the same time.');

        InvoiceDTO::fromArray([
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'Test',
            ],
            'series'      => 'A',
            'number'      => 10,
            'issueDate'   => '2025-11-20',
            'invoiceType' => 'F1',
            'recipient' => [
                'name'     => 'John',
                'nif'      => 'B61206934',
                'country'  => 'GB',
                'idType'   => '02',
                'idNumber' => 'AB1234567',
            ],
            'lines' => [
                ['desc' => 'X', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_it_parses_rectification_block_for_r2(): void
    {
        $payload = [
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'ACME S.L.',
            ],
            'series'      => 'FR2025',
            'number'      => 5,
            'issueDate'   => '2025-11-25',
            'description' => 'Rectificación por cambio de precio',
            'invoiceType' => 'R2',
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
            'rectify' => [
                'mode' => 'substitution',
                'original' => [
                    'series'    => 'F2025',
                    'number'    => 73,
                    'issueDate' => '2025-11-20',
                ],
            ],
            'lines' => [
                ['desc' => 'Servicio corregido', 'qty' => 1, 'price' => 90, 'vat' => 21],
            ],
        ];

        $dto = InvoiceDTO::fromArray($payload);

        $this->assertSame('R2', $dto->invoiceType);
        $this->assertTrue($dto->isRectification());

        $this->assertNotNull($dto->rectify);
        $this->assertSame(RectifyMode::SUBSTITUTION, $dto->rectify->mode);

        $this->assertSame('F2025', $dto->rectify->originalSeries);
        $this->assertSame(73, $dto->rectify->originalNumber);
        $this->assertSame('2025-11-20', $dto->rectify->originalIssueDate);
    }

    public function test_rectificative_without_original_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rectificative invoices (R1–R5) require a "rectify" block with original invoice data.');

        InvoiceDTO::fromArray([
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'ACME S.L.',
            ],
            'series'      => 'FR2025',
            'number'      => 5,
            'issueDate'   => '2025-11-25',
            'invoiceType' => 'R2',
            // Ojo: sin bloque rectify
            'lines' => [
                ['desc' => 'Servicio corregido', 'qty' => 1, 'price' => 90, 'vat' => 21],
            ],
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
        ]);
    }

    public function test_unknown_invoice_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invoiceType must be one of: F1, F2, F3, R1, R2, R3, R4, R5');

        InvoiceDTO::fromArray([
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'ACME S.L.',
            ],
            'series'      => 'X2025',
            'number'      => 1,
            'issueDate'   => '2025-11-20',
            'invoiceType' => 'ZZ', // tipo inválido
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_non_array_payload_throws(): void
    {
        $this->expectException(\TypeError::class);

        /** @var mixed $payload */
        $payload = null;

        InvoiceDTO::fromArray($payload);
    }

    public function test_lines_are_required_and_not_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing field: lines');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
        ]);
    }

    public function test_lines_cannot_be_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lines[] is required and must be non-empty');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'lines' => [],
        ]);
    }

    public function test_lines_validate_qty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid line values: qty must be > 0, vat must be >= 0'
        );

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'lines' => [
                ['desc' => 'Servicio', 'qty' => -1, 'price' => 10, 'vat' => 21],
            ],
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
        ]);
    }

    public function test_lines_validate_price_non_rectificative_still_requires_non_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid line values: price must be >= 0');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'invoiceType' => 'F1',
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => -10, 'vat' => 21],
            ],
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
        ]);
    }

    public function test_lines_validate_vat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid line values: qty must be > 0, vat must be >= 0'
        );

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 10, 'vat' => -21],
            ],
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
        ]);
    }

    public function test_f1_requires_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('For invoiceType F1 you must provide recipient.name + recipient.nif or a full IDOtro (country, idType, idNumber).');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'invoiceType' => 'F1',
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_f3_requires_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('For invoiceType F3 you must provide recipient.name + recipient.nif or a full IDOtro (country, idType, idNumber).');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'invoiceType' => 'F3',
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_r1_requires_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('For invoiceType R1 you must provide recipient.name + recipient.nif or a full IDOtro (country, idType, idNumber).');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'invoiceType' => 'R1',
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_r4_requires_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('For invoiceType R4 you must provide recipient.name + recipient.nif or a full IDOtro (country, idType, idNumber).');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'invoiceType' => 'R4',
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_f2_forbids_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('For invoiceType F2/R5 the recipient block must be empty (AEAT: no Destinatarios).');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'TCK2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'invoiceType' => 'F2',
            'recipient' => [
                'name' => 'Cliente Demo',
                'nif'  => 'B61206934',
            ],
            'lines' => [
                ['desc' => 'Ticket', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_r5_forbids_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('For invoiceType F2/R5 the recipient block must be empty (AEAT: no Destinatarios).');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'R52025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'invoiceType' => 'R5',
            'recipient' => [
                'name' => 'Cliente Demo',
                'nif'  => 'B61206934',
            ],
            'lines' => [
                ['desc' => 'Rectificación ticket', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_idotro_not_allowed_for_spanish_country(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('For Spanish recipients you must use recipient.nif (not IDOtro)');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'invoiceType' => 'F1',
            'recipient' => [
                'name'     => 'Cliente ES sin NIF',
                'nif'      => null,
                'country'  => 'ES',
                'idType'   => '02',
                'idNumber' => 'AB1234567',
            ],
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_invalid_id_type_for_idotro_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient.idType must be one of: 02, 03, 04, 05, 06, 07');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'F2025',
            'number' => 1,
            'issueDate' => '2025-11-20',
            'invoiceType' => 'F1',
            'recipient' => [
                'name'     => 'John Smith',
                'nif'      => null,
                'country'  => 'GB',
                'idType'   => '99', // fuera del catálogo
                'idNumber' => 'AB1234567',
            ],
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    public function test_it_parses_rectification_block_for_r3_difference(): void
    {
        $payload = [
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'ACME S.L.',
            ],
            'series'      => 'FR2025',
            'number'      => 6,
            'issueDate'   => '2025-11-26',
            'description' => 'Rectificación por diferencias',
            'invoiceType' => 'R3',
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
            'rectify' => [
                'mode' => 'difference',
                'original' => [
                    'series'    => 'F2025',
                    'number'    => 74,
                    'issueDate' => '2025-11-21',
                ],
            ],
            'lines' => [
                ['desc' => 'Ajuste', 'qty' => 1, 'price' => 5, 'vat' => 21],
            ],
        ];

        $dto = InvoiceDTO::fromArray($payload);

        $this->assertSame('R3', $dto->invoiceType);
        $this->assertTrue($dto->isRectification());
        $this->assertNotNull($dto->rectify);

        $this->assertSame(RectifyMode::DIFFERENCE, $dto->rectify->mode);
        $this->assertSame('F2025', $dto->rectify->originalSeries);
        $this->assertSame(74, $dto->rectify->originalNumber);
        $this->assertSame('2025-11-21', $dto->rectify->originalIssueDate);
    }

    public function test_unknown_rectify_mode_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rectify.mode must be "substitution" or "difference"');

        InvoiceDTO::fromArray([
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'ACME S.L.',
            ],
            'series'      => 'FR2025',
            'number'      => 6,
            'issueDate'   => '2025-11-26',
            'invoiceType' => 'R2',
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
            'rectify' => [
                'mode' => 'invalid-mode',
                'original' => [
                    'series'    => 'F2025',
                    'number'    => 74,
                    'issueDate' => '2025-11-21',
                ],
            ],
            'lines' => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 10, 'vat' => 21],
            ],
        ]);
    }

    // -------------------------
    // NUEVOS TESTS (cambio de regla de price)
    // -------------------------

    public function test_difference_rectification_allows_negative_price(): void
    {
        $payload = [
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'FR2025',
            'number' => 99,
            'issueDate' => '2025-11-30',
            'invoiceType' => 'R3',
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
            'rectify' => [
                'mode' => 'difference',
                'original' => [
                    'series'    => 'F2025',
                    'number'    => 1,
                    'issueDate' => '2025-11-20',
                ],
            ],
            'lines' => [
                ['desc' => 'Devolución parcial', 'qty' => 1, 'price' => -4.13223140, 'vat' => 21],
            ],
        ];

        $dto = InvoiceDTO::fromArray($payload);

        $this->assertSame('R3', $dto->invoiceType);
        $this->assertSame(RectifyMode::DIFFERENCE, $dto->rectify->mode);
        $this->assertSame(-4.13223140, (float) $dto->lines[0]['price']);
    }

    public function test_difference_rectification_rejects_zero_price(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid line values: in difference rectifications, price must be != 0');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'FR2025',
            'number' => 100,
            'issueDate' => '2025-11-30',
            'invoiceType' => 'R3',
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
            'rectify' => [
                'mode' => 'difference',
                'original' => [
                    'series'    => 'F2025',
                    'number'    => 2,
                    'issueDate' => '2025-11-20',
                ],
            ],
            'lines' => [
                ['desc' => 'Ajuste cero', 'qty' => 1, 'price' => 0, 'vat' => 21],
            ],
        ]);
    }

    public function test_substitution_rectification_rejects_negative_price(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid line values: price must be >= 0');

        InvoiceDTO::fromArray([
            'issuer' => ['nif' => 'B61206934', 'name' => 'ACME S.L.'],
            'series' => 'FR2025',
            'number' => 101,
            'issueDate' => '2025-11-30',
            'invoiceType' => 'R2',
            'recipient' => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'B61206934',
            ],
            'rectify' => [
                'mode' => 'substitution',
                'original' => [
                    'series'    => 'F2025',
                    'number'    => 3,
                    'issueDate' => '2025-11-20',
                ],
            ],
            'lines' => [
                ['desc' => 'No permitido', 'qty' => 1, 'price' => -1, 'vat' => 21],
            ],
        ]);
    }
}
