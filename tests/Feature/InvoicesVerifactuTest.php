<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use App\Models\BillingHashModel;
use App\Models\SubmissionsModel;
use CodeIgniter\Test\FeatureTestTrait;

final class InvoicesVerifactuTest extends ApiTestCase
{
    use FeatureTestTrait;
    private array $apiRoutes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiRoutes = [
            ['GET', 'api/v1/invoices/(:num)/verifactu', '\App\Controllers\Api\V1\InvoicesController::verifactu/$1'],
        ];
    }

    public function test_verifactu_returns_full_technical_payload(): void
    {
        $this->mockRequestContext();

        $billing = new BillingHashModel();

        $id = $billing->insert([
            'company_id'       => 1,
            'issuer_nif'       => 'B61206934',
            'series'           => 'F2025',
            'number'           => 29,
            'issue_date'       => '2025-11-12',
            'status'           => 'accepted',
            'hash'             => 'D86BEFBDACF9E8FC',
            'prev_hash'        => 'AAAAA11111',
            'chain_index'      => 10,
            'csv_text'         => 'IDEmisorFactura=B61206934&NumSerieFactura=F202529&...',
            'datetime_offset'  => '2025-11-12T10:20:30+01:00',
            'aeat_csv'         => 'A-SZWHB3PKWQD32A',
            'qr_url'           => '/api/v1/invoices/29/qr',
            'qr_path'          => 'writable/verifactu/qr/29.png',
            'xml_path'         => 'writable/verifactu/xml/29-preview.xml',
            'vat_total'        => 21.00,
            'gross_total'      => 121.00,
            'details_json'     => json_encode([
                [
                    'ClaveRegimen'                  => '01',
                    'CalificacionOperacion'         => 'S1',
                    'TipoImpositivo'                => 21,
                    'BaseImponibleOimporteNoSujeto' => 100,
                    'CuotaRepercutida'              => 21,
                ],
            ], JSON_UNESCAPED_UNICODE),
            'lines_json'       => json_encode([
                ['desc' => 'Servicio', 'qty' => 1, 'price' => 100, 'vat' => 21],
            ], JSON_UNESCAPED_UNICODE),
        ], true);

        // Último envío
        $subs = new SubmissionsModel();
        $subs->insert([
            'billing_hash_id' => $id,
            'type'            => 'register',
            'status'          => 'sent',
            'attempt_number'  => 1,
            'error_code'      => null,
            'error_message'   => null,
            'request_ref'     => '29-request.xml',
            'response_ref'    => '29-response.xml',
            'created_at'      => '2025-11-12 10:21:00',
        ]);

        $result = $this->getJson(
            '/api/v1/invoices/' . $id . '/verifactu',
            $this->apiRoutes
        );


        $result->assertStatus(200);

        $json = json_decode($result->getJSON(), true);

        $data = $json['data'];

        $this->assertSame($id, $data['document_id']);
        $this->assertSame('accepted', $data['status']);
        $this->assertSame('B61206934', $data['issuer_nif']);
        $this->assertSame('F2025', $data['series']);
        $this->assertSame(29, $data['number']);
        $this->assertSame('2025-11-12', $data['issue_date']);

        $this->assertSame('D86BEFBDACF9E8FC', $data['hash']);
        $this->assertSame('AAAAA11111', $data['prev_hash']);
        $this->assertSame(10, $data['chain_index']);
        $this->assertSame('A-SZWHB3PKWQD32A', $data['aeat_csv']);

        $this->assertEquals(21.00, $data['totals']['vat_total']);
        $this->assertEquals(121.00, $data['totals']['gross_total']);

        $this->assertIsArray($data['detail']);
        $this->assertIsArray($data['lines']);

        $this->assertIsArray($data['last_submission']);
        $this->assertSame('register', $data['last_submission']['type']);
        $this->assertSame('sent', $data['last_submission']['status']);
        $this->assertSame(1, $data['last_submission']['attempt_number']);
        $this->assertSame('29-request.xml', $data['last_submission']['request_ref']);
        $this->assertSame('29-response.xml', $data['last_submission']['response_ref']);
    }

    public function test_verifactu_returns_404_when_not_found(): void
    {
        $this->mockRequestContext();
        $result = $this->getJson(
            '/api/v1/invoices/0/verifactu',
            $this->apiRoutes
        );


        $result->assertStatus(404);

        $json = json_decode($result->getJSON(), true);
        $this->assertSame(404, $json['status'] ?? null);
        $this->assertSame('Not Found', $json['title'] ?? null);
    }
}
