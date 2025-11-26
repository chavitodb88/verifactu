<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

final class ApiKeyAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('X-API-Key');
        if ($header === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = trim((string) $_SERVER['HTTP_AUTHORIZATION']);
            if (stripos($auth, 'Bearer ') === 0) {
                $header = substr($auth, 7);
            }
        }

        if ($header === '') {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['error' => 'Missing X-API-Key']);
        }

        $db = db_connect();
        $builder = $db->table('api_keys');
        $row = $builder
            ->select('api_keys.*, companies.slug AS company_slug, companies.issuer_nif AS company_issuer_nif')
            ->join('companies', 'companies.id = api_keys.company_id', 'inner')
            ->where('api_keys.api_key', $header)
            ->where('companies.is_active', 1)
            ->get()
            ->getRowArray();


        if (!$row) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['error' => 'Invalid API key']);
        }

        $ctx = service('requestContext');
        $ctx->setApiKey($header);
        $ctx->setCompany([
            'id'         => (int) $row['company_id'],
            'slug'       => (string) $row['company_slug'],
            'issuer_nif' => (string) $row['company_issuer_nif'],
        ]);


        return null; // ok
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
