<?php

declare(strict_types=1);

namespace App\Billing\Auth;

use App\Models\CompaniesModel;
use CodeIgniter\HTTP\RequestInterface;
use Config\Services;

/**
 * Resolver genérico que:
 * 1) Lee Authorization: Bearer de la request
 * 2) Llama a un endpoint HTTP (baseUrl + endpointPath)
 * 3) Parsea JSON
 * 4) Delega en extractCompanyLookupKey() para obtener la clave de búsqueda
 * 5) Busca la company en companies
 */
abstract class AbstractHttpTokenCompanyResolver implements CompanyResolverInterface
{
    protected string $baseUrl;
    protected string $endpointPath;
    protected int $timeout;

    public function __construct(string $baseUrl, string $endpointPath, int $timeout = 3)
    {
        $this->baseUrl      = rtrim($baseUrl, '/');
        $this->endpointPath = $endpointPath;
        $this->timeout      = $timeout;
    }

    public function resolve(RequestInterface $request): ?array
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (! preg_match('/^Bearer\s+(.+)$/i', $authHeader)) {
            // No hay Bearer token, este resolver no aplica
            return null;
        }

        $client = Services::curlrequest([
            'baseURI' => $this->baseUrl,
            'timeout' => $this->timeout,
            'verify'  => false,
        ]);

        try {
            $response = $client->get($this->endpointPath, [
                'headers' => [
                    'Authorization' => $authHeader,
                    'Accept'        => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = (string) $response->getBody();
        $json = json_decode($body, true);

        if (! is_array($json)) {
            return null;
        }

        // La subclase decide cómo obtener la clave de búsqueda (ej: ['issuer_nif' => 'B1696...'])
        $lookupKey = $this->extractCompanyLookupKey($json);

        if (! $lookupKey) {
            return null;
        }

        $companies = new CompaniesModel();

        return $this->findCompany($companies, $lookupKey);
    }

    /**
     * Devuelve un array asociativo con la(s) columna(s) por las que buscar.
     * Ejemplo típico: ['issuer_nif' => 'B16963753']
     *
     * Si no se puede extraer una clave válida, devuelve null.
     */
    abstract protected function extractCompanyLookupKey(array $json): ?array;

    /**
     * Búsqueda por defecto en companies, usando la clave proporcionada.
     *
     * @param CompaniesModel $companies
     * @param array          $key      Ej: ['issuer_nif' => 'B16963753']
     */
    protected function findCompany(CompaniesModel $companies, array $key): ?array
    {
        $builder = $companies;

        foreach ($key as $column => $value) {
            $builder = $builder->where($column, $value);
        }

        $row = $builder->first();

        return $row ?: null;
    }
}
