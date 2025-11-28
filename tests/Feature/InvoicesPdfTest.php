<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use App\Models\BillingHashModel;
use CodeIgniter\Test\FeatureTestTrait;
use Spatie\PdfToText\Pdf;

/**
 * Generación de PDF de factura:
 * - Devuelve 200.
 * - Persiste pdf_path.
 * - Genera el fichero físico y lo limpia al final.
 */
final class InvoicesPdfTest extends ApiTestCase
{
    use FeatureTestTrait;

    private array $apiRoutes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiRoutes = [
            ['GET', 'api/v1/invoices/(:num)/pdf', '\App\Controllers\Api\V1\InvoicesController::pdf/$1'],
        ];
    }

    public function test_pdf_generates_file_and_updates_billing_hash(): void
    {
        // Contexto empresa ACME (id=1 del TestSeeder).
        $this->mockRequestContext();

        $model = new BillingHashModel();

        $id = $model->insert([
            'company_id'      => 1,
            'issuer_nif'      => 'B61206934',
            'issuer_name'     => 'ACME S.L.',
            'issuer_address'  => 'Calle Mayor 1',
            'issuer_postal_code' => '28001',
            'issuer_city'        => 'Madrid',
            'issuer_province'    => 'Madrid',
            'client_nif'      => 'B12345678',
            'client_name'     => 'Cliente Demo S.L.',
            'client_address'  => 'Avenida Siempre Viva 742',
            'series'         => 'F2025',
            'number'         => 73,
            'issue_date'     => '2025-11-20',
            'kind'           => 'alta',
            'status'         => 'accepted',
            'hash'           => 'D86BEFBDACF9E8FC',
            'prev_hash'      => null,
            'chain_index'    => 1,
            'vat_total'      => 21.00,
            'gross_total'    => 121.00,
            'details_json'   => json_encode([
                [
                    'ClaveRegimen'                  => '01',
                    'CalificacionOperacion'         => 'S1',
                    'TipoImpositivo'                => 21,
                    'BaseImponibleOimporteNoSujeto' => 100,
                    'CuotaRepercutida'              => 21,
                ],
            ], JSON_UNESCAPED_UNICODE),
            'lines_json'     => json_encode([
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 100, 'vat' => 21],
            ], JSON_UNESCAPED_UNICODE),
            'raw_payload_json' => json_encode([
                'invoiceType' => 'F1',
                'recipient'   => [
                    'name'    => 'Cliente Demo S.L.',
                    'nif'     => 'B12345678',
                    'country' => 'ES',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at'     => '2025-11-20 10:00:00',
            'updated_at'     => '2025-11-20 10:00:00',
        ], true);

        $result = $this
            ->withRoutes($this->apiRoutes)
            ->get('/api/v1/invoices/' . $id . '/pdf');

        $result->assertStatus(200);

        $row = $model->find($id);

        $this->assertNotEmpty($row['pdf_path'] ?? null, 'pdf_path debe haberse guardado en billing_hashes');

        $pdfPath = $row['pdf_path'];
        $text = Pdf::getText($pdfPath);

        $this->assertFileExists($pdfPath);


        // QR generado
        $qrPath = WRITEPATH . 'verifactu/qr/' . $id . '.png';
        $this->assertFileExists($qrPath, 'El QR de la factura debería existir tras generar el PDF');


        $pdfContent = file_get_contents($pdfPath);
        $this->assertNotFalse($pdfContent);

        $this->assertStringContainsString('ACME S.L.', $text);
        $this->assertStringContainsString('Cliente Demo S.L.', $text);
        $this->assertStringContainsString('Servicio', $text);
        $this->assertStringContainsString('121', $text);


        if (is_file($pdfPath)) {
            unlink($pdfPath);
        }
        if (is_file($qrPath)) {
            unlink($qrPath);
        }

        $this->assertFileDoesNotExist($pdfPath);
        $this->assertFileDoesNotExist($qrPath);
    }
}
