<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use App\Models\BillingHashModel;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use App\Services\VerifactuQrService;

/**
 * Generación de QR:
 * - Devuelve 200.
 * - Genera el PNG en la ruta determinista y lo limpia.
 */
final class InvoicesQrTest extends ApiTestCase
{
    use FeatureTestTrait;

    private array $apiRoutes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiRoutes = [
            ['GET', 'api/v1/invoices/(:num)/qr', '\App\Controllers\Api\V1\InvoicesController::qr/$1'],
        ];

        // Fuerza el uso del servicio real, por si otros tests han inyectado mocks.
        Services::injectMock('verifactuQr', new VerifactuQrService());
    }

    public function test_qr_generates_png_file(): void
    {
        $this->mockRequestContext();

        $model = new BillingHashModel();

        $id = $model->insert([
            'company_id'  => 1,
            'issuer_nif'  => 'B61206934',
            'issuer_name' => 'ACME S.L.',
            'series'      => 'F2025',
            'number'      => 91,
            'issue_date'  => '2025-11-25',
            'kind'        => 'alta',
            'status'      => 'accepted',
            'hash'        => 'FAKEHASH1234567890',
            'chain_index' => 1,
            'vat_total'   => 21.00,
            'gross_total' => 121.00,
            'created_at'  => '2025-11-25 10:00:00',
            'updated_at'  => '2025-11-25 10:00:00',
        ], true);

        $result = $this
            ->withRoutes($this->apiRoutes)
            ->get("/api/v1/invoices/{$id}/qr");

        $result->assertStatus(200);

        $path = WRITEPATH . 'verifactu/qr/' . $id . '.png';
        $this->assertFileExists($path);

        // Limpieza del fichero generado durante el test.
        if (is_file($path)) {
            unlink($path);
        }

        $this->assertFileDoesNotExist($path);
    }
}
