<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use App\Models\CompaniesModel;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Healthcheck básico de la API usando el contexto de empresa.
 */
final class HealthEndpointTest extends ApiTestCase
{
    use FeatureTestTrait;

    private array $apiRoutes = [];
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        // Company "acme" creada por el TestSeeder.
        $company = (new CompaniesModel())
            ->where('slug', 'acme')
            ->first();

        $this->companyId = (int) ($company['id'] ?? 1);

        $this->apiRoutes = [
            ['GET', 'api/v1/health', '\App\Controllers\Api\V1\HealthController::index'],
        ];
    }

    public function test_health_returns_ok_and_company_from_context(): void
    {
        // Simula que el middleware de autenticación ya ha resuelto la empresa.
        $this->mockRequestContext([
            'id'           => $this->companyId,
            'slug'         => 'acme',
            'name'         => 'ACME S.L.',
            'issuer_nif'   => 'B61206934',
            'send_to_aeat' => 0,
        ]);

        $result = $this->getJson('/api/v1/health', $this->apiRoutes);

        $result->assertStatus(200);

        $json = json_decode($result->getJSON(), true);

        $this->assertSame('ok', $json['data']['status'] ?? null);

        $company = $json['data']['company'] ?? null;
        $this->assertIsArray($company);

        // Comprueba que el health devuelve la misma empresa que hay en el contexto.
        $this->assertSame($this->companyId, (int) ($company['id'] ?? 0));
        $this->assertSame('acme', $company['slug'] ?? null);
        $this->assertSame('B61206934', $company['issuer_nif'] ?? null);
    }
}
