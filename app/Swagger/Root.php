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

        new OA\Schema(
            schema: "DetalleItem",
            type: "object",
            required: ["ClaveRegimen", "CalificacionOperacion", "TipoImpositivo", "BaseImponibleOimporteNoSujeto", "CuotaRepercutida"],
            properties: [
                new OA\Property(property: "ClaveRegimen", type: "string", example: "01"),
                new OA\Property(property: "CalificacionOperacion", type: "string", example: "S1"),
                new OA\Property(property: "TipoImpositivo", type: "number", format: "float", example: 21),
                new OA\Property(property: "BaseImponibleOimporteNoSujeto", type: "number", format: "float", example: 100),
                new OA\Property(property: "CuotaRepercutida", type: "number", format: "float", example: 21),
            ]
        ),

        new OA\Schema(
            schema: "InvoiceInput",
            type: "object",
            required: ["issuerNif", "series", "number", "issueDate", "lines"],
            properties: [
                new OA\Property(property: "issuerNif", type: "string", example: "B12345678"),
                new OA\Property(property: "issuerName", type: "string", example: "ACME S.L."),
                new OA\Property(property: "series", type: "string", example: "F"),
                new OA\Property(property: "number", type: "integer", example: 5),
                new OA\Property(property: "issueDate", type: "string", example: "2025-11-12", description: "YYYY-MM-DD"),
                new OA\Property(property: "description", type: "string", example: "Servicio de transporte"),
                new OA\Property(
                    property: "lines",
                    type: "array",
                    items: new OA\Items(
                        type: "object",
                        required: ["desc", "qty", "price", "vat"],
                        properties: [
                            new OA\Property(property: "desc", type: "string", example: "Servicio"),
                            new OA\Property(property: "qty", type: "number", format: "float", example: 1),
                            new OA\Property(property: "price", type: "number", format: "float", example: 100),
                            new OA\Property(property: "vat", type: "number", format: "float", example: 21),
                            new OA\Property(property: "discount", type: "number", format: "float", nullable: true, example: 0)
                        ]
                    )
                ),
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
                        new OA\Property(
                            property: "totals",
                            type: "object",
                            properties: [
                                new OA\Property(property: "cuota_total", type: "number", format: "float", example: 21.00),
                                new OA\Property(property: "importe_total", type: "number", format: "float", example: 121.00)
                            ]
                        ),
                        new OA\Property(
                            property: "detalle_desglose",
                            type: "array",
                            items: new OA\Items(ref: "#/components/schemas/DetalleItem")
                        )
                    ]
                ),
                new OA\Property(property: "meta", type: "object", properties: [
                    new OA\Property(property: "request_id", type: "string"),
                    new OA\Property(property: "ts", type: "integer")
                ])
            ]
        ),
    ]
)]
final class Root {}
