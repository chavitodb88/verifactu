<?php

declare(strict_types=1);

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'VERI*FACTU Middleware API',
    version: '1.0.0',
    description: 'Middleware multiempresa para VERI*FACTU (AEAT)'
)]
#[OA\Server(
    url: '/api/v1',
    description: 'v1 base path'
)]

#[OA\Components(
    securitySchemes: [
        new OA\SecurityScheme(
            securityScheme: 'ApiKey',
            type: 'apiKey',
            in: 'header',
            name: 'X-API-Key',
            description: 'Autenticación mediante API Key'
        ),
        new OA\SecurityScheme(
            securityScheme: 'BearerAuth',
            type: 'http',
            scheme: 'bearer',
            bearerFormat: 'JWT',
            description: 'Autenticación alternativa vía Authorization: Bearer <token>'
        ),
    ],
    responses: [
        // 401 común
        new OA\Response(
            response: 'Unauthorized',
            description: 'No autorizado',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        // 403 común
        new OA\Response(
            response: 'Forbidden',
            description: 'Forbidden',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        // 404 común
        new OA\Response(
            response: 'NotFound',
            description: 'Not Found',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        // 422 común (útil para validaciones de DTO)
        new OA\Response(
            response: 'UnprocessableEntity',
            description: 'Unprocessable Entity',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        // 409 común (idempotencia / conflicto)
        new OA\Response(
            response: 'Conflict',
            description: 'Conflict',
            content: new OA\JsonContent(ref: '#/components/schemas/InvoicePreviewResponse')
        ),
    ],
    schemas: [
        // --- RFC7807 estándar ---
        new OA\Schema(
            schema: 'ProblemDetails',
            type: 'object',
            required: ['title', 'status'],
            properties: [
                new OA\Property(property: 'type', type: 'string', example: 'about:blank'),
                new OA\Property(property: 'title', type: 'string', example: 'Unauthorized'),
                new OA\Property(property: 'status', type: 'integer', example: 401),
                new OA\Property(property: 'detail', type: 'string', example: 'Missing API key'),
                new OA\Property(property: 'instance', type: 'string', example: '/api/v1/health'),
                new OA\Property(property: 'code', type: 'string', example: 'VF401')
            ]
        ),

        // --- Respuesta del health ---
        new OA\Schema(
            schema: 'HealthResponse',
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'company', type: 'object', nullable: true)
                    ]
                ),
                new OA\Property(
                    property: 'meta',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'request_id', type: 'string', example: 'a1b2c3d4e5f6a7b8'),
                        new OA\Property(property: 'ts', type: 'integer', example: 1731400000)
                    ]
                )
            ]
        ),

        new OA\Schema(
            schema: 'DetalleItem',
            type: 'object',
            required: ['ClaveRegimen', 'CalificacionOperacion', 'TipoImpositivo', 'BaseImponibleOimporteNoSujeto', 'CuotaRepercutida'],
            properties: [
                new OA\Property(property: 'ClaveRegimen', type: 'string', example: '01'),
                new OA\Property(property: 'CalificacionOperacion', type: 'string', example: 'S1'),
                new OA\Property(property: 'TipoImpositivo', type: 'number', format: 'float', example: 21),
                new OA\Property(property: 'BaseImponibleOimporteNoSujeto', type: 'number', format: 'float', example: 100),
                new OA\Property(property: 'CuotaRepercutida', type: 'number', format: 'float', example: 21),
            ]
        ),

        new OA\Schema(
            schema: 'InvoiceOriginalRef',
            type: 'object',
            required: ['series', 'number', 'issueDate'],
            properties: [
                new OA\Property(
                    property: 'series',
                    type: 'string',
                    example: 'F',
                    description: 'Serie de la factura original rectificada'
                ),
                new OA\Property(
                    property: 'number',
                    type: 'integer',
                    example: 56,
                    description: 'Número de la factura original rectificada'
                ),
                new OA\Property(
                    property: 'issueDate',
                    type: 'string',
                    example: '2025-11-04',
                    description: 'Fecha de expedición de la factura original (YYYY-MM-DD)'
                ),
            ]
        ),

        new OA\Schema(
            schema: 'InvoiceRectify',
            type: 'object',
            nullable: true,
            description: 'Información de rectificación para facturas R1–R4',
            required: ['mode', 'original'],
            properties: [
                new OA\Property(
                    property: 'mode',
                    type: 'string',
                    enum: ['substitution', 'difference'],
                    example: 'substitution',
                    description: 'Modo de rectificación: sustitución completa o rectificación por diferencias'
                ),
                new OA\Property(
                    property: 'original',
                    type: 'object',
                    required: ['series', 'number', 'issueDate'],
                    properties: [
                        new OA\Property(
                            property: 'series',
                            type: 'string',
                            example: 'F',
                            description: 'Serie de la factura original rectificada'
                        ),
                        new OA\Property(
                            property: 'number',
                            type: 'integer',
                            example: 56,
                            description: 'Número de la factura original rectificada'
                        ),
                        new OA\Property(
                            property: 'issueDate',
                            type: 'string',
                            example: '2025-11-04',
                            description: 'Fecha de expedición de la factura original (YYYY-MM-DD)'
                        ),
                    ]
                ),
            ]
        ),



        new OA\Schema(
            schema: 'InvoiceInput',
            type: 'object',
            required: ['issuer', 'series', 'number', 'issueDate', 'lines'],
            properties: [
                new OA\Property(
                    property: 'issuer',
                    type: 'object',
                    description: 'Datos del emisor (Obligado a facturar / software)',
                    required: ['nif', 'name'],
                    properties: [
                        new OA\Property(property: 'nif', type: 'string', example: 'B61206934'),
                        new OA\Property(property: 'name', type: 'string', example: 'Empresa Demo, S.L.'),
                        new OA\Property(property: 'address', type: 'string', nullable: true, example: 'Calle Mayor 1'),
                        new OA\Property(property: 'postalCode', type: 'string', nullable: true, example: '28001'),
                        new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Madrid'),
                        new OA\Property(property: 'province', type: 'string', nullable: true, example: 'Madrid'),
                        new OA\Property(property: 'country', type: 'string', nullable: true, example: 'ES'),
                    ]
                ),

                new OA\Property(property: 'series', type: 'string', example: 'F2025'),
                new OA\Property(property: 'number', type: 'integer', example: 73),
                new OA\Property(
                    property: 'issueDate',
                    type: 'string',
                    example: '2025-11-20',
                    description: 'Fecha de expedición de la factura (YYYY-MM-DD)'
                ),

                new OA\Property(
                    property: 'description',
                    type: 'string',
                    nullable: true,
                    example: 'Servicio de transporte aeropuerto'
                ),

                new OA\Property(
                    property: 'invoiceType',
                    type: 'string',
                    nullable: true,
                    description: 'Tipo de factura VERI*FACTU: F1, F2, F3, R1, R2, R3, R4, R5. Por defecto F1. F2 y R5 no permiten bloque recipient.',
                    example: 'F1'
                ),

                new OA\Property(
                    property: 'recipient',
                    type: 'object',
                    nullable: true,
                    description: 'Destinatario de la factura. En F2 y R5 no se envía.',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Cliente Demo S.L.'),
                        new OA\Property(property: 'nif', type: 'string', nullable: true, example: 'B12345678'),
                        new OA\Property(property: 'country', type: 'string', nullable: true, example: 'ES'),
                        new OA\Property(property: 'idType', type: 'string', nullable: true, example: '02', description: 'Tipo de identificación alternativa (IDOtro)'),
                        new OA\Property(property: 'idNumber', type: 'string', nullable: true, example: 'DE123456789'),
                        new OA\Property(property: 'address', type: 'string', nullable: true, example: 'C/ Gran Vía 1'),
                        new OA\Property(property: 'postalCode', type: 'string', nullable: true, example: '28001'),
                        new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Madrid'),
                        new OA\Property(property: 'province', type: 'string', nullable: true, example: 'Madrid'),
                    ]
                ),

                new OA\Property(
                    property: 'rectify',
                    ref: '#/components/schemas/InvoiceRectify',
                    nullable: true
                ),

                new OA\Property(
                    property: 'taxRegimeCode',
                    type: 'string',
                    nullable: true,
                    example: '01',
                    description: 'Clave de régimen (ClaveRegimen). Por defecto 01 (Régimen común).'
                ),

                new OA\Property(
                    property: 'operationQualification',
                    type: 'string',
                    nullable: true,
                    example: 'S1',
                    description: 'Calificación de la operación (CalificacionOperacion). Por defecto S1.'
                ),

                new OA\Property(
                    property: 'lines',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        required: ['desc', 'qty', 'price', 'vat'],
                        properties: [
                            new OA\Property(property: 'desc', type: 'string', example: 'Servicio de transporte aeropuerto'),
                            new OA\Property(property: 'qty', type: 'number', format: 'float', example: 1),
                            new OA\Property(property: 'price', type: 'number', format: 'float', example: 100.00),
                            new OA\Property(property: 'vat', type: 'number', format: 'float', example: 21),
                            new OA\Property(property: 'discount', type: 'number', format: 'float', nullable: true, example: 0),
                        ]
                    )
                ),

                new OA\Property(
                    property: 'externalId',
                    type: 'string',
                    nullable: true,
                    example: 'ERP-2025-00073',
                    description: 'Identificador externo opcional (id de tu ERP, CRM, etc.)'
                ),
            ],
            example: [
                'issuer' => [
                    'nif'        => 'B61206934',
                    'name'       => 'Empresa Demo, S.L.',
                    'address'    => 'Calle Mayor 1',
                    'postalCode' => '28001',
                    'city'       => 'Madrid',
                    'province'   => 'Madrid',
                    'country'    => 'ES',
                ],
                'series'       => 'F2025',
                'number'       => 73,
                'issueDate'    => '2025-11-20',
                'description'  => 'Servicio de transporte aeropuerto',
                'invoiceType'  => 'F1',
                'recipient'    => [
                    'name'       => 'Cliente Demo S.L.',
                    'nif'        => 'B12345678',
                    'country'    => 'ES',
                    'address'    => 'C/ Gran Vía 1',
                    'postalCode' => '28001',
                    'city'       => 'Madrid',
                    'province'   => 'Madrid',
                ],
                'taxRegimeCode'        => '01',
                'operationQualification' => 'S1',
                'lines' => [
                    [
                        'desc'     => 'Traslado aeropuerto',
                        'qty'      => 1,
                        'price'    => 100.00,
                        'vat'      => 21,
                        'discount' => 0,
                    ],
                ],
                'externalId' => 'ERP-2025-00073',
            ]
        ),


        new OA\Schema(
            schema: 'InvoicePreviewResponse',
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'document_id', type: 'integer', example: 123),
                        new OA\Property(property: 'status', type: 'string', example: 'draft', description: 'Estado técnico local: draft, ready, sent, accepted, error...'),
                        new OA\Property(property: 'hash', type: 'string', nullable: true, example: 'D86BEFBDACF9E8FC...'),
                        new OA\Property(property: 'prev_hash', type: 'string', nullable: true, example: 'A12B34C56D78...'),
                        new OA\Property(property: 'qr_url', type: 'string', nullable: true, example: '/api/v1/invoices/123/qr'),
                        new OA\Property(
                            property: 'xml_path',
                            type: 'string',
                            nullable: true,
                            example: 'writable/verifactu/xml/123-preview.xml'
                        ),
                    ]
                ),
                new OA\Property(property: 'meta', type: 'object', properties: [
                    new OA\Property(property: 'request_id', type: 'string', nullable: true),
                    new OA\Property(property: 'ts', type: 'integer', nullable: true),
                    new OA\Property(
                        property: 'queued',
                        type: 'boolean',
                        nullable: true,
                        example: true,
                        description: 'Indica si se ha dejado en cola para envío automático a AEAT (201 Created)'
                    ),
                    new OA\Property(
                        property: 'idempotent',
                        type: 'boolean',
                        nullable: true,
                        example: true,
                        description: 'true cuando se devuelve un draft ya existente por Idempotency-Key (409 Conflict)'
                    ),
                ])
            ]
        ),

        new OA\Schema(
            schema: 'InvoiceVerifactuResponse',
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'document_id', type: 'integer', example: 123),
                        new OA\Property(property: 'status', type: 'string', example: 'accepted'),

                        new OA\Property(property: 'issuer_nif', type: 'string', nullable: true, example: 'B12345678'),
                        new OA\Property(property: 'series', type: 'string', nullable: true, example: 'F'),
                        new OA\Property(property: 'number', type: 'integer', nullable: true, example: 29),
                        new OA\Property(property: 'issue_date', type: 'string', format: 'date', nullable: true, example: '2025-11-12'),

                        new OA\Property(property: 'hash', type: 'string', example: 'D86BEFBDACF9E8FC...'),
                        new OA\Property(property: 'prev_hash', type: 'string', nullable: true),
                        new OA\Property(property: 'chain_index', type: 'integer', nullable: true),
                        new OA\Property(property: 'csv_text', type: 'string', nullable: true),
                        new OA\Property(property: 'datetime_offset', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'aeat_csv', type: 'string', nullable: true, example: 'A-SZWHB3PKWQD32A'),

                        new OA\Property(property: 'qr_url', type: 'string', nullable: true, example: '/api/v1/invoices/29/qr'),
                        new OA\Property(property: 'qr_path', type: 'string', nullable: true, example: 'writable/verifactu/qr/29.png'),
                        new OA\Property(property: 'xml_path', type: 'string', nullable: true, example: 'writable/verifactu/xml/29-preview.xml'),

                        new OA\Property(
                            property: 'totals',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'vat_total', type: 'number', format: 'float', nullable: true, example: 21.00),
                                new OA\Property(property: 'gross_total', type: 'number', format: 'float', nullable: true, example: 121.00),
                            ]
                        ),

                        new OA\Property(
                            property: 'detail',
                            type: 'array',
                            nullable: true,
                            items: new OA\Items(ref: '#/components/schemas/DetalleItem')
                        ),

                        new OA\Property(
                            property: 'lines',
                            type: 'array',
                            nullable: true,
                            items: new OA\Items(type: 'object')
                        ),

                        new OA\Property(
                            property: 'last_submission',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'type', type: 'string', example: 'register'),
                                new OA\Property(property: 'status', type: 'string', example: 'sent'),
                                new OA\Property(property: 'attempt_number', type: 'integer', example: 1),
                                new OA\Property(property: 'error_code', type: 'string', nullable: true, example: '2000'),
                                new OA\Property(property: 'error_message', type: 'string', nullable: true),
                                new OA\Property(property: 'request_ref', type: 'string', nullable: true, example: '29-request.xml'),
                                new OA\Property(property: 'response_ref', type: 'string', nullable: true, example: '29-response.xml'),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                            ]
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'meta',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'request_id',
                            type: 'string',
                            nullable: true,
                            example: 'a1b2c3d4e5f6a7b8'
                        ),
                        new OA\Property(
                            property: 'ts',
                            type: 'integer',
                            nullable: true,
                            example: 1731400000
                        ),
                    ]
                )
            ]
        ),
        new OA\Schema(
            schema: 'InvoiceCancelRequest',
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'reason',
                    type: 'string',
                    nullable: true,
                    description: 'Motivo interno de anulación (no se envía a AEAT)',
                    example: 'Factura emitida por error'
                ),
            ]
        ),

        // --- Cancel: respuesta 201 ---
        new OA\Schema(
            schema: 'InvoiceCancelResponse',
            type: 'object',
            properties: [
                new OA\Property(property: 'document_id', type: 'integer', example: 456),
                new OA\Property(property: 'kind', type: 'string', example: 'anulacion'),
                new OA\Property(property: 'status', type: 'string', example: 'ready'),
                new OA\Property(property: 'hash', type: 'string', example: 'ABCDEF1234...'),
                new OA\Property(property: 'prev_hash', type: 'string', nullable: true),
                new OA\Property(property: 'aeat_status', type: 'string', nullable: true, example: 'Correcto'),
            ]
        ),
    ]
)]
final class Root {}
