<?php

declare(strict_types=1);

namespace App\Billing\Auth;

use CodeIgniter\HTTP\RequestInterface;

interface CompanyResolverInterface
{
    /**
     * Intenta resolver la empresa a partir de la request.
     *
     * Si no aplica o no consigue resolverla, debe devolver null.
     *
     * @return array|null Fila de companies (array asociativo) o null
     */
    public function resolve(RequestInterface $request): ?array;
}
