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
        // 401 común
        new OA\Response(
            response: "Unauthorized",
            description: "No autorizado",
            content: new OA\JsonContent(ref: "#/components/schemas/ProblemDetails")
        ),
        // 422 común (útil para validaciones de DTO)
        new OA\Response(
            response: "UnprocessableEntity",
            description: "Unprocessable Entity",
            content: new OA\JsonContent(ref: "#/components/schemas/ProblemDetails")
        ),
        // 409 común (idempotencia / conflicto)
        new OA\Response(
            response: "Conflict",
            description: "Conflict",
            content: new OA\JsonContent(ref: "#/components/schemas/InvoicePreviewResponse")
        ),
    ],

    schemas: [
        // --- RFC7807 estándar ---
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

        // --- Respuesta del health ---
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
        ),

        // --- payload y respuesta de /invoices/preview ---
        new OA\Schema(
            schema: "InvoiceInput",
            type: "object",
            required: ["issuer_nif", "invoice"],
            properties: [
                new OA\Property(property: "issuer_nif", type: "string", example: "B12345678"),
                new OA\Property(property: "external_id", type: "string", nullable: true, example: "abc-123"),
                new OA\Property(
                    property: "invoice",
                    type: "object",
                    required: ["series", "number", "issue_date", "totals", "lines"],
                    properties: [
                        new OA\Property(property: "series", type: "string", example: "A"),
                        new OA\Property(property: "number", type: "string", example: "2025-000123"),
                        new OA\Property(property: "issue_date", type: "string", format: "date", example: "2025-11-12"),
                        new OA\Property(property: "customer", type: "object", nullable: true),
                        new OA\Property(
                            property: "lines",
                            type: "array",
                            items: new OA\Items(type: "object")
                        ),
                        new OA\Property(
                            property: "totals",
                            type: "object",
                            required: ["net", "vat", "gross"],
                            properties: [
                                new OA\Property(property: "net", type: "number", format: "float", example: 100),
                                new OA\Property(property: "vat", type: "number", format: "float", example: 21),
                                new OA\Property(property: "gross", type: "number", format: "float", example: 121),
                            ]
                        ),
                        new OA\Property(property: "currency", type: "string", example: "EUR"),
                        new OA\Property(property: "meta", type: "object", nullable: true),
                    ]
                )
            ]
        ),

        new OA\Schema(
            schema: "InvoicePreviewResponse",
            type: "object",
            properties: [
                new OA\Property(
                    property: "data",
                    type: "object",
                    properties: [
                        new OA\Property(property: "document_id", type: "integer", example: 123),
                        new OA\Property(property: "status", type: "string", example: "draft"),
                        new OA\Property(property: "hash", type: "string", nullable: true),
                        new OA\Property(property: "prev_hash", type: "string", nullable: true),
                        new OA\Property(property: "qr_url", type: "string", nullable: true),
                    ]
                ),
                new OA\Property(
                    property: "meta",
                    type: "object",
                    properties: [
                        new OA\Property(property: "request_id", type: "string"),
                        new OA\Property(property: "ts", type: "integer"),
                        new OA\Property(property: "idempotent", type: "boolean", nullable: true),
                    ]
                )
            ]
        ),
    ]
)]
final class Root {}
