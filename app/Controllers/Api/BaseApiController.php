<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

abstract class BaseApiController extends ResourceController
{
    protected function ok(array $data = [], array $meta = [], int $status = 200)
    {
        $payload = [
            'data' => (object) $data,
            'meta' => array_merge([
                'request_id' => service('request')->getHeaderLine('X-Request-Id') ?: '',
                'ts'         => time(),
            ], $meta),
        ];

        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    protected function created(array $data = [], array $meta = [])
    {
        return $this->ok($data, $meta, 201);
    }

    protected function problem(
        int $status,
        string $title,
        string $detail = '',
        string $type = 'about:blank',
        ?string $code = null
    ) {
        $problem = [
            'type'     => $type,
            'title'    => $title,
            'status'   => $status,
            'detail'   => $detail,
            'instance' => service('request')->getUri()->getPath(),
        ];
        if ($code !== null) {
            $problem['code'] = $code;
        }

        return $this->response
            ->setStatusCode($status)
            ->setContentType('application/problem+json')
            ->setJSON($problem);
    }
}
