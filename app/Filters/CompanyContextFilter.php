<?php

declare(strict_types=1);

namespace App\Filters;

use App\Billing\Auth\CompanyResolverManager;
use App\Services\RequestContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\CompanyContext;
use Config\Services as ConfigServices;

final class CompanyContextFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        /** @var CompanyContext $cfg */
        $cfg = config(CompanyContext::class);

        $resolvers = [];
        foreach ($cfg->resolverFactories as $factory) {
            // Cada factory devuelve una instancia de CompanyResolverInterface
            $resolvers[] = $factory();
        }

        $manager = new CompanyResolverManager($resolvers);

        $company = $manager->resolve($request);

        if (! $company) {
            return ConfigServices::response()
                ->setStatusCode(401)
                ->setJSON([
                    'error'   => 'company_not_resolved',
                    'message' => 'Unable to resolve company from request',
                ]);
        }

        /** @var RequestContext $ctx */
        $ctx = service('requestContext');
        $ctx->setCompany($company);

        return null; // continúa el flujo
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nada que hacer después
    }
}
