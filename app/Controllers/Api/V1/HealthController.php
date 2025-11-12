<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use OpenApi\Attributes as OA;

final class HealthController extends BaseApiController
{
    #[OA\Get(
        path: '/health',
        summary: 'Healthcheck',
        tags: ['Meta'],
        security: [['ApiKey' => []], ['BearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/HealthResponse')
            ),
            new OA\Response(ref: "#/components/schemas/ProblemDetails", response: 401)
        ]
    )]
    public function index()
    {
        $company = $this->request->company ?? null;

        return $this->ok([
            'status'  => 'ok',
            'company' => $company,
        ]);
    }
}
