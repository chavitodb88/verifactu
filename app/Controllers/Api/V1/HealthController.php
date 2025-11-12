<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use OpenApi\Attributes as OA;

final class HealthController extends ResourceController
{
    #[OA\Get(
        path: "/health",
        summary: "Healthcheck",
        tags: ["Meta"],
        security: [
            ["ApiKey" => []],
            ["BearerAuth" => []],
        ],
        responses: [
            new OA\Response(response: 200, description: "OK"),
            new OA\Response(ref: "#/components/responses/Unauthorized")
        ]
    )]

    public function index()
    {
        return $this->respond([
            'status'  => 'ok',
            'company' => $this->request->company ?? null,
            'ts'      => time(),
        ]);
    }
}
