<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class RequestIdFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $rid = $request->getHeaderLine('X-Request-Id');
        if ($rid === '') {
            $rid = bin2hex(random_bytes(8));
            $request->setHeader('X-Request-Id', $rid);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $rid = $request->getHeaderLine('X-Request-Id');
        if ($rid !== '') {
            $response->setHeader('X-Request-Id', $rid);
        }
    }
}
