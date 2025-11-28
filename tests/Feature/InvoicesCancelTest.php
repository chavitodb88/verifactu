<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use App\Models\BillingHashModel;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use App\Services\VerifactuService;

/**
 * Cancelación de facturas:
 * - Crea una anulación encadenada (happy path).
 * - Valida que solo se puedan cancelar "alta".
 * - Respeta el aislamiento multiempresa.
 */
final class InvoicesCancelTest extends ApiTestCase
{
    use FeatureTestTrait;

    private array $apiRoutes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiRoutes = [
            ['POST', 'api/v1/invoices/(:num)/cancel', '\App\Controllers\Api\V1\InvoicesController::cancel/$1'],
        ];

        // Asegura que este test usa el VerifactuService real (otros tests pueden haber inyectado mocks).
        Services::injectMock('verifactu', new VerifactuService());
    }

    public function test_cancel_creates_chained_cancellation_ready(): void
    {
        $this->mockRequestContext();

        $billing = new BillingHashModel();

        $origId = $billing->insert([
            'company_id'       => 1,
            'issuer_nif'       => 'B61206934',
            'issuer_name'      => 'ACME S.L.',
            'series'           => 'F2025',
            'number'           => 99,
            'issue_date'       => '2025-11-25',
            'description'      => 'Servicio de transporte',
            'kind'             => 'alta',
            'status'           => 'accepted',
            'hash'             => 'ORIGINALHASH123',
            'prev_hash'        => null,
            'chain_index'      => 1,
            'vat_total'        => 21.00,
            'gross_total'      => 121.00,
            'lines_json'       => json_encode([
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 100, 'vat' => 21],
            ], JSON_UNESCAPED_UNICODE),
            'raw_payload_json' => json_encode([
                'invoiceType' => 'F1',
                'recipient'   => [
                    'name' => 'Cliente Demo S.L.',
                    'nif'  => 'B12345678',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at'       => '2025-11-25 10:00:00',
            'updated_at'       => '2025-11-25 10:00:00',
        ], true);

        $payload = [
            'reason' => 'Cliente solicita anulación',
        ];

        $result = $this->postJson(
            '/api/v1/invoices/' . $origId . '/cancel',
            $payload,
            $this->apiRoutes
        );

        $result->assertStatus(201);

        $json = json_decode($result->getJSON(), true);

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('meta', $json);

        $data = $json['data'];

        $this->assertIsInt($data['document_id'] ?? null);
        $this->assertSame('anulacion', $data['kind'] ?? null);
        $this->assertSame('ready', $data['status'] ?? null);
        $this->assertNotEmpty($data['hash'] ?? null);

        $cancelId  = (int) $data['document_id'];
        $cancelRow = $billing->find($cancelId);

        $this->assertNotNull($cancelRow);
        $this->assertSame(1, (int) $cancelRow['company_id']);
        $this->assertSame('anulacion', $cancelRow['kind']);
        $this->assertSame('ready', $cancelRow['status']);
        $this->assertSame($origId, (int) $cancelRow['original_billing_hash_id']);
        $this->assertSame('F2025', $cancelRow['series']);
        $this->assertSame(99, (int) $cancelRow['number']);
        $this->assertSame(0.0, (float) $cancelRow['vat_total']);
        $this->assertSame(0.0, (float) $cancelRow['gross_total']);
        $this->assertNotEmpty($cancelRow['hash'] ?? null);
        // Encadenamiento: apunta al hash anterior y aumenta el índice de cadena.
        $this->assertNotNull($cancelRow['prev_hash']);
        $this->assertGreaterThanOrEqual(2, (int) $cancelRow['chain_index']);
    }

    public function test_cancel_returns_400_if_original_is_not_alta(): void
    {
        $this->mockRequestContext();

        $billing = new BillingHashModel();

        $id = $billing->insert([
            'company_id'  => 1,
            'issuer_nif'  => 'B61206934',
            'series'      => 'F2025',
            'number'      => 100,
            'issue_date'  => '2025-11-25',
            'kind'        => 'anulacion',
            'status'      => 'draft',
            'vat_total'   => 0.0,
            'gross_total' => 0.0,
            'created_at'  => '2025-11-25 11:00:00',
            'updated_at'  => '2025-11-25 11:00:00',
        ], true);

        $result = $this->postJson(
            '/api/v1/invoices/' . $id . '/cancel',
            [],
            $this->apiRoutes
        );

        $result->assertStatus(400);

        $json = json_decode($result->getJSON(), true);

        $this->assertSame(
            'Only alta invoices can be cancelled',
            $json['messages']['error'] ?? null
        );
    }

    public function test_cancel_returns_404_if_invoice_not_found(): void
    {
        $this->mockRequestContext();

        $result = $this->postJson(
            '/api/v1/invoices/999999/cancel',
            [],
            $this->apiRoutes
        );

        $result->assertStatus(404);

        $json = json_decode($result->getJSON(), true);

        $this->assertSame(404, $json['status'] ?? null);
        $this->assertSame('VF404', $json['code'] ?? null);
        $this->assertSame('document not found', $json['detail'] ?? null);
    }

    public function test_cancel_returns_404_if_invoice_belongs_to_other_company(): void
    {
        // Empresa adicional creada directamente por DB.
        $this->db->table('companies')->insert([
            'slug'              => 'other-co',
            'name'              => 'Other Co S.L.',
            'issuer_nif'        => 'B99999999',
            'verifactu_enabled' => 1,
            'send_to_aeat'      => 0,
            'storage_driver'    => 'fs',
            'storage_base_path' => 'other/',
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $otherCompanyId = (int) $this->db->insertID();

        $billing = new BillingHashModel();

        $foreignId = $billing->insert([
            'company_id'  => $otherCompanyId,
            'issuer_nif'  => 'B99999999',
            'series'      => 'F2025',
            'number'      => 200,
            'issue_date'  => '2025-11-25',
            'kind'        => 'alta',
            'status'      => 'accepted',
            'hash'        => 'FOREIGNHASH123',
            'chain_index' => 1,
            'vat_total'   => 21.0,
            'gross_total' => 121.0,
            'created_at'  => '2025-11-25 12:00:00',
            'updated_at'  => '2025-11-25 12:00:00',
        ], true);

        // El contexto sigue siendo empresa 1 (ACME), así que debe devolver 404.
        $this->mockRequestContext();

        $result = $this->postJson(
            '/api/v1/invoices/' . $foreignId . '/cancel',
            [],
            $this->apiRoutes
        );

        $result->assertStatus(404);

        $json = json_decode($result->getJSON(), true);

        $this->assertSame(404, $json['status'] ?? null);
        $this->assertSame('VF404', $json['code'] ?? null);
        $this->assertSame('document not found', $json['detail'] ?? null);
    }
}
