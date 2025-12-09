<?php

declare(strict_types=1);

namespace App\Billing\Auth;

use CodeIgniter\HTTP\RequestInterface;
use Config\Services;

final class ApiKeyCompanyResolver implements CompanyResolverInterface
{
    public function resolve(RequestInterface $request): ?array
    {
        // Misma lógica que tu viejo ApiKeyAuthFilter
        $header = $request->getHeaderLine('X-API-Key');

        if ($header === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = trim((string) $_SERVER['HTTP_AUTHORIZATION']);
            if (stripos($auth, 'Bearer ') === 0) {
                $header = substr($auth, 7);
            }
        }

        if ($header === '') {
            // Este resolver no aplica si no hay API key
            return null;
        }

        $db      = db_connect();
        $builder = $db->table('api_keys');

        // Aquí puedes elegir qué columnas quieres de companies.
        // Si quieres TODAS: usa companies.*
        $row = $builder
            ->select('companies.*')
            ->join('companies', 'companies.id = api_keys.company_id', 'inner')
            ->where('api_keys.api_key', $header)
            ->where('api_keys.active', 1)
            ->where('companies.is_active', 1)
            ->get()
            ->getRowArray();

        if (! $row) {
            // API key inválida → este resolver "no resuelve".
            // Ojo: NO devolvemos respuesta HTTP aquí, solo null.
            return null;
        }

        // Si quisieras guardar el apiKey en el contexto, se hace en el filtro,
        // no aquí, para que el resolver siga siendo "puro".
        return $row;
    }
}
