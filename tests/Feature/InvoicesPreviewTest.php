<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use App\Models\BillingHashModel;
use CodeIgniter\Test\FeatureTestTrait;

final class InvoicesPreviewTest extends ApiTestCase
{
    use FeatureTestTrait;
    private array $apiRoutes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiRoutes = [
            ['POST', 'api/v1/invoices/preview', '\App\Controllers\Api\V1\InvoicesController::preview'],
        ];
    }

    public function test_it_creates_f1_draft_invoice(): void
    {
        $this->mockRequestContext([
            'issuer_nif' => 'B61206934',
        ]);

        $payload = [
            'issuer' => [
                'nif'        => 'B61206934',
                'name'       => 'MyTransfer Demo, S.L.',
                'address'    => 'Calle Mayor 1',
                'postalCode' => '28001',
                'city'       => 'Madrid',
                'province'   => 'Madrid',
                'country'    => 'ES',
            ],
            'series'      => 'F2025',
            'number'      => 73,
            'issueDate'   => '2025-11-20',
            'description' => 'Servicio de transporte aeropuerto',
            'invoiceType' => 'F1',
            'recipient'   => [
                'name'       => 'Cliente Demo S.L.',
                'nif'        => 'D41054115',
                'country'    => 'ES',
                'address'    => 'C/ Gran Vía 1',
                'postalCode' => '28001',
                'city'       => 'Madrid',
                'province'   => 'Madrid',
            ],
            'taxRegimeCode'          => '01',
            'operationQualification' => 'S1',
            'lines' => [
                [
                    'desc'  => 'Traslado aeropuerto',
                    'qty'   => 1,
                    'price' => 100.00,
                    'vat'   => 21,
                ],
            ],
            'externalId' => 'ERP-2025-00073',
        ];

        $result = $this
            ->postJson(
                '/api/v1/invoices/preview',
                $payload,
                $this->apiRoutes
            );

        $result->assertStatus(201);

        $json = json_decode($result->getJSON(), true);

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('meta', $json);

        $this->assertIsInt($json['data']['document_id']);
        $this->assertSame('draft', $json['data']['status']);
        $this->assertNotEmpty($json['data']['hash']);

        // Existe en BD y con totales correctos
        $model = new BillingHashModel();
        $row   = $model->find($json['data']['document_id']);

        $this->assertNotNull($row);
        $this->assertSame('draft', $row['status']);
        $this->assertSame('F2025', $row['series']);
        $this->assertSame(73, (int) $row['number']);
        $this->assertSame('2025-11-20', $row['issue_date']);
        $this->assertSame(21.00, (float) $row['vat_total']);
        $this->assertSame(121.00, (float) $row['gross_total']);
    }

    public function test_preview_is_idempotent_with_same_key(): void
    {
        $this->mockRequestContext();

        $payload = [
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'MyTransfer Demo, S.L.',
            ],
            'series'      => 'F2025',
            'number'      => 99,
            'issueDate'   => '2025-11-21',
            'invoiceType' => 'F1',
            'recipient'   => [
                'name'       => 'Cliente Demo S.L.',
                'nif'        => 'D41054115',
                'country'    => 'ES',
                'address'    => 'C/ Gran Vía 1',
                'postalCode' => '28001',
                'city'       => 'Madrid',
                'province'   => 'Madrid',
            ],
            'lines'      => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 100, 'vat' => 21],
            ],
        ];

        $idem = 'test-idem-123';

        // 1ª vez → 201
        $first = $this
            ->postJson('/api/v1/invoices/preview', $payload, $this->apiRoutes, [
                'Idempotency-Key' => $idem,
            ]);

        $first->assertStatus(201);
        $firstJson = json_decode($first->getJSON(), true);
        $firstId   = $firstJson['data']['document_id'] ?? null;

        $this->assertNotNull($firstId);

        // 2ª vez → 409 con idempotent = true
        $second = $this
            ->postJson('/api/v1/invoices/preview', $payload, $this->apiRoutes, [
                'Idempotency-Key' => $idem,
            ]);

        $second->assertStatus(409);

        $secondJson = json_decode($second->getJSON(), true);

        $this->assertSame($firstId, $secondJson['data']['document_id']);
        $this->assertTrue($secondJson['meta']['idempotent']);
    }

    public function test_preview_fails_when_issuer_does_not_match_context(): void
    {
        // Contexto con un NIF
        $this->mockRequestContext([
            'issuer_nif' => 'B61206934',
        ]);

        $payload = [
            'issuer' => [
                'nif'        => '16111259N', // distinto al del contexto
                'name'       => 'MyTransfer Demo, S.L.',
                'address'    => 'Calle Mayor 1',
                'postalCode' => '28001',
                'city'       => 'Madrid',
                'province'   => 'Madrid',
                'country'    => 'ES',
            ],
            'series'      => 'F2025',
            'number'      => 1,
            'issueDate'   => '2025-11-21',
            'invoiceType' => 'F1',
            'recipient'   => [
                'name'       => 'Cliente Demo S.L.',
                'nif'        => 'D41054115',
                'country'    => 'ES',
                'address'    => 'C/ Gran Vía 1',
                'postalCode' => '28001',
                'city'       => 'Madrid',
                'province'   => 'Madrid',
            ],
            'lines'      => [
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 50, 'vat' => 21],
            ],
        ];

        $result = $this
            ->postJson(
                '/api/v1/invoices/preview',
                $payload,
                $this->apiRoutes
            );

        // Aquí devuelves 400 con failValidationErrors
        $result->assertStatus(400);

        $json = json_decode($result->getJSON(), true);

        $this->assertSame(
            'issuerNif does not match the emitter assigned to this API key',
            $json['messages']['issuerNif'] ?? null
        );
    }

    public function test_preview_fails_with_non_json_body(): void
    {
        $payload = 'this is not json';

        $result = $this
            ->withRoutes($this->apiRoutes)
            ->withBody($payload)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('/api/v1/invoices/preview');

        $result->assertStatus(400);

        $json = json_decode($result->getJSON(), true);
        $this->assertSame(400, $json['status'] ?? null);
        $this->assertSame('Bad Request', $json['title'] ?? null);
    }

    public function test_preview_creates_r2_rectification_linked_to_original(): void
    {
        $this->mockRequestContext();

        // Creamos primero la original F1
        $model = new BillingHashModel();
        $origId = $model->insert([
            'company_id'   => 1,
            'issuer_nif'   => 'B61206934',
            'series'       => 'F2025',
            'number'       => 73,
            'issue_date'   => '2025-11-20',
            'invoice_type' => 'F1',
            'kind'         => 'alta',
            'status'       => 'accepted',
            'hash'         => 'ORIGINALHASH',
        ], true);

        // Ahora enviamos una R2 que referencia esa factura
        $payload = [
            'issuer' => [
                'nif'  => 'B61206934',
                'name' => 'MyTransfer Demo, S.L.',
            ],
            'series'      => 'FR2025',
            'number'      => 5,
            'issueDate'   => '2025-11-25',
            'description' => 'Rectificación por cambio de precio',
            'invoiceType' => 'R2',
            'recipient'   => [
                'name' => 'Cliente Demo S.L.',
                'nif'  => 'D41054115',
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
                [
                    'desc'  => 'Servicio corregido',
                    'qty'   => 1,
                    'price' => 90.00,
                    'vat'   => 21,
                ],
            ],
        ];

        $result = $this->postJson(
            '/api/v1/invoices/preview',
            $payload,
            $this->apiRoutes
        );
        $result->assertStatus(201);

        $json = json_decode($result->getJSON(), true);
        $rectId = $json['data']['document_id'];

        $row = $model->find($rectId);

        $this->assertSame('R2', $row['invoice_type']);
        $this->assertSame($origId, (int)$row['rectified_billing_hash_id']);
        $this->assertNotEmpty($row['rectified_meta_json']);
    }
}
