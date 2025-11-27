<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;


abstract class ApiTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

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
}
