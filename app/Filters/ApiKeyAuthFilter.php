<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
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
                ->setJSON(['error' => 'Missing API key']);
        }

        $db = db_connect();
        $row = $db->table('api_keys')
            ->select('api_keys.*, companies.slug AS company_slug, companies.id AS company_id')
            ->join('companies', 'companies.id = api_keys.company_id', 'left')
            ->getWhere(['api_key' => $header, 'active' => 1])
            ->getRowArray();

        if (!$row) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON(['error' => 'Invalid API key']);
        }

        // Inyecta company en request (atributo compartido)
        // En CI4 puedes usar Services::request() o asignar globalmente:
        $request->company = [
            'id'   => (int) $row['company_id'],
            'slug' => (string) $row['company_slug'],
        ];

        return null; // ok
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
