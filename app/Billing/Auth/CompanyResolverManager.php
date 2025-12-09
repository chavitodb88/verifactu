<?php

declare(strict_types=1);

namespace App\Billing\Auth;

use CodeIgniter\HTTP\RequestInterface;

final class CompanyResolverManager
{
    /** @var CompanyResolverInterface[] */
    private array $resolvers;

    /**
     * @param CompanyResolverInterface[] $resolvers
     */
    public function __construct(array $resolvers)
    {
        $this->resolvers = $resolvers;
    }

    public function resolve(RequestInterface $request): ?array
    {
        foreach ($this->resolvers as $resolver) {
            $company = $resolver->resolve($request);

            if ($company !== null) {
                return $company;
            }
        }

        return null;
    }
}
