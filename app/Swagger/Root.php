<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: "VERI*FACTU Middleware API",
    version: "1.0.0",
    description: "Middleware multiempresa para VERI*FACTU (AEAT)"
)]
#[OA\Server(
    url: "/api/v1",
    description: "v1 base path"
)]

#[OA\Components(
    securitySchemes: [
        new OA\SecurityScheme(
            securityScheme: "ApiKey",
            type: "apiKey",
            in: "header",
            name: "X-API-Key",
            description: "Autenticación mediante API Key"
        ),
        new OA\SecurityScheme(
            securityScheme: "BearerAuth",
            type: "http",
            scheme: "bearer",
            bearerFormat: "JWT",
            description: "Autenticación alternativa vía Authorization: Bearer <token>"
        ),
    ],
    responses: [
        new OA\Response(
            response: "Unauthorized",
            description: "No autorizado",
            content: new OA\JsonContent(ref: "#/components/schemas/ProblemDetails")
        )
    ],
    schemas: [
        new OA\Schema(
            schema: "ProblemDetails",
            type: "object",
            required: ["title", "status"],
            properties: [
                new OA\Property(property: "type", type: "string", example: "about:blank"),
                new OA\Property(property: "title", type: "string", example: "Unauthorized"),
                new OA\Property(property: "status", type: "integer", example: 401),
                new OA\Property(property: "detail", type: "string", example: "Missing API key"),
                new OA\Property(property: "instance", type: "string", example: "/api/v1/health"),
                new OA\Property(property: "code", type: "string", example: "VF401")
            ]
        ),
        new OA\Schema(
            schema: "HealthResponse",
            type: "object",
            properties: [
                new OA\Property(
                    property: "data",
                    type: "object",
                    properties: [
                        new OA\Property(property: "status", type: "string", example: "ok"),
                        new OA\Property(property: "company", type: "object", nullable: true)
                    ]
                ),
                new OA\Property(
                    property: "meta",
                    type: "object",
                    properties: [
                        new OA\Property(property: "request_id", type: "string", example: "a1b2c3d4e5f6a7b8"),
                        new OA\Property(property: "ts", type: "integer", example: 1731400000)
                    ]
                )
            ]
        )
    ]
)]
final class Root {}
