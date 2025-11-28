<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use App\Models\BillingHashModel;
use App\Models\CompaniesModel;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Tests de lectura de facturas:
 * - Solo devuelve facturas de la empresa del contexto.
 * - Devuelve 404 si no existe o pertenece a otra empresa.
 */
final class InvoicesShowTest extends ApiTestCase
{
    use FeatureTestTrait;

    private array $apiRoutes = [];
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $company = (new CompaniesModel())
            ->where('slug', 'acme')
            ->first();

        $this->companyId = (int) ($company['id'] ?? 1);

        $this->apiRoutes = [
            ['GET', 'api/v1/invoices/(:num)', '\App\Controllers\Api\V1\InvoicesController::show/$1'],
        ];
    }

    public function test_show_returns_invoice_of_current_company(): void
    {
        $model = new BillingHashModel();

        $invoiceId = $model->insert([
            'company_id'   => $this->companyId,
            'issuer_nif'   => 'B61206934',
            'series'       => 'F2025',
            'number'       => 10,
            'issue_date'   => '2025-11-20',
            'invoice_type' => 'F1',
            'kind'         => 'alta',
            'status'       => 'draft',
            'vat_total'    => 21.00,
            'gross_total'  => 121.00,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], true);

        // Contexto de empresa ACME, igual que haría el middleware de API key.
        $this->mockRequestContext([
            'id'         => $this->companyId,
            'issuer_nif' => 'B61206934',
        ]);

        $result = $this->getJson("/api/v1/invoices/{$invoiceId}", $this->apiRoutes);

        $result->assertStatus(200);

        $json = json_decode($result->getJSON(), true);
        $data = $json['data'] ?? null;

        $this->assertNotNull($data);
        $this->assertSame($invoiceId, $data['document_id'] ?? null);
        $this->assertSame('draft', $data['status'] ?? null);
        $this->assertArrayHasKey('hash', $data);
        $this->assertArrayHasKey('prev_hash', $data);
        $this->assertArrayHasKey('qr_url', $data);
        $this->assertArrayHasKey('xml_path', $data);
    }

    public function test_show_returns_404_if_invoice_does_not_exist(): void
    {
        $this->mockRequestContext([
            'id'         => $this->companyId,
            'issuer_nif' => 'B61206934',
        ]);

        $result = $this->getJson('/api/v1/invoices/999999', $this->apiRoutes);

        $result->assertStatus(404);

        $json = json_decode($result->getJSON(), true);
        $this->assertSame(404, $json['status'] ?? null);
        $this->assertSame('VF404', $json['code'] ?? null);
    }

    public function test_show_returns_404_if_invoice_belongs_to_other_company(): void
    {
        // Empresa adicional, creada vía query builder para no depender de allowedFields.
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

        $model = new BillingHashModel();
        $foreignId = $model->insert([
            'company_id'   => $otherCompanyId,
            'issuer_nif'   => 'B99999999',
            'series'       => 'F2025',
            'number'       => 20,
            'issue_date'   => '2025-11-21',
            'invoice_type' => 'F1',
            'kind'         => 'alta',
            'status'       => 'draft',
            'vat_total'    => 21.00,
            'gross_total'  => 121.00,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], true);

        $this->mockRequestContext([
            'id'         => $this->companyId,
            'issuer_nif' => 'B61206934',
        ]);

        $result = $this->getJson("/api/v1/invoices/{$foreignId}", $this->apiRoutes);

        $result->assertStatus(404);

        $json = json_decode($result->getJSON(), true);
        $this->assertSame(404, $json['status'] ?? null);
        $this->assertSame('VF404', $json['code'] ?? null);
    }
}
