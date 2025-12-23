<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class AdminAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $cfg = config('Verifactu');
        $user = (string) $cfg->adminUser;
        $pass = (string) $cfg->adminPass;

        if ($user === '' || $pass === '') {
            return service('response')
                ->setStatusCode(503)
                ->setBody('Admin auth no configurado. Define VERIFACTU_ADMIN_USER y VERIFACTU_ADMIN_PASS en .env');
        }

        $sentUser = $_SERVER['PHP_AUTH_USER'] ?? '';
        $sentPass = $_SERVER['PHP_AUTH_PW'] ?? '';

        $ok = hash_equals($user, $sentUser) && hash_equals($pass, $sentPass);

        if (! $ok) {
            return service('response')
                ->setStatusCode(401)
                ->setHeader('WWW-Authenticate', 'Basic realm="Verifactu Admin"')
                ->setBody('Acceso no autorizado');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No hacemos nada en after
    }
}
