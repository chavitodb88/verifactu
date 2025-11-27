<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use App\Models\BillingHashModel;
use CodeIgniter\Test\FeatureTestTrait;


final class InvoicesPreviewTest extends ApiTestCase
{
    use FeatureTestTrait;
    public function test_it_creates_f1_draft_invoice(): void
    {
        $this->mockRequestContext([
            'issuer_nif' => 'B61206934',
        ]);

        $routes = [
            ['POST', 'api/v1/invoices/preview', '\App\Controllers\Api\V1\InvoicesController::preview'],
        ];

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

        $result = $this->withRoutes($routes)
            ->withBody(json_encode($payload))
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post('api/v1/invoices/preview');

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
}
