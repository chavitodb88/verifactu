<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(title: "VERI*FACTU Middleware API", version: "1.0.0")]
#[OA\Server(url: "/api/v1", description: "v1 base path")]

# Componentes reutilizables
#[OA\Components(
    securitySchemes: [
        new OA\SecurityScheme(
            securityScheme: "ApiKey",
            type: "apiKey",
            in: "header",
            name: "X-API-Key",
            description: "API key en cabecera X-API-Key"
        ),
        new OA\SecurityScheme(
            securityScheme: "BearerAuth",
            type: "http",
            scheme: "bearer",
            bearerFormat: "JWT",
            description: "Alternativa vía Authorization: Bearer <token>"
        ),
    ],
    responses: [
        new OA\Response(
            response: "Unauthorized",
            description: "Unauthorized",
            content: new OA\JsonContent(
                type: "object",
                properties: [
                    new OA\Property(property: "error", type: "string", example: "Missing API key")
                ]
            )
        )
    ]
)]
final class Root {}
