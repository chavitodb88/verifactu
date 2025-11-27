<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

use CodeIgniter\Test\FeatureTestTrait;

abstract class ApiTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    /**
     * Ejecuta las migraciones antes de los tests
     */
    protected $migrate = true;

    /**
     * Si quieres que reinicie la DB en cada test:
     * - crea las tablas al principio de cada test
     * - las limpia al final
     */
    protected $refresh = true;

    /**
     * Namespace de tus migraciones (App\Database\Migrations)
     */
    protected $namespace = 'App';
    protected $seed = \App\Database\Seeds\TestSeeder::class;

    protected function mockRequestContext(array $companyOverride = []): void
    {
        $company = array_merge([
            'id'                => 1,
            'issuer_nif'        => 'B61206934',
            'verifactu_enabled' => 1,
            'send_to_aeat'      => 0,
        ], $companyOverride);

        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getCompany'])
            ->getMock();

        $mock->method('getCompany')->willReturn($company);

        Services::injectMock('requestContext', $mock);
    }

    protected function getJson(string $uri, array $routes = [], array $headers = [])
    {
        return $this
            ->withRoutes($routes)
            ->withHeaders(array_merge([
                'Accept' => 'application/json',
            ], $headers))
            ->get($uri);
    }

    protected function postJson(string $uri, array $payload, array $routes = [], array $headers = [])
    {
        return $this
            ->withRoutes($routes)
            ->withBody(json_encode($payload))
            ->withHeaders(array_merge([
                'Content-Type' => 'application/json',
            ], $headers))
            ->post($uri);
    }
}
