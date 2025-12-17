<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Models\BillingHashModel;
use App\Models\CompaniesModel;
use App\Models\SubmissionsModel;
use App\Services\VerifactuAeatPayloadBuilder;
use CodeIgniter\HTTP\ResponseInterface;
use OpenApi\Attributes as OA;

/**
 * @property \CodeIgniter\HTTP\IncomingRequest $request
 */
final class InvoicesController extends BaseApiController
{
    #[OA\Post(
        path: '/invoices/preview',
        summary: 'Valida el payload (incluyendo F1/F2/F3 y R1–R4), genera el registro técnico y opcionalmente lo pone en cola',
        tags: ['Invoices'],
        security: [['ApiKey' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/InvoiceInput',
                examples: [
                    new OA\Examples(
                        example: 'F1',
                        summary: 'Factura completa F1 con destinatario',
                        value: [
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
                            'taxRegimeCode'         => '01',
                            'operationQualification' => 'S1',
                            'lines' => [
                                [
                                    'desc'  => 'Traslado aeropuerto',
                                    'qty'   => 1,
                                    'price' => 100.00,
                                    'vat'   => 21,
                                ],
                            ],
                            'externalId' => 'ERP-2025-00073',
                        ]
                    ),
                    new OA\Examples(
                        example: 'F2',
                        summary: 'Factura simplificada F2 sin destinatario (ticket)',
                        value: [
                            'issuer' => [
                                'nif'  => 'B61206934',
                                'name' => 'Empresa Demo, S.L.',
                            ],
                            'series'       => 'T2025',
                            'number'       => 12,
                            'issueDate'    => '2025-11-20',
                            'description'  => 'Ticket taxi urbano',
                            'invoiceType'  => 'F2',
                            // recipient omitido por ser F2
                            'taxRegimeCode'         => '01',
                            'operationQualification' => 'S1',
                            'lines' => [
                                [
                                    'desc'  => 'Trayecto urbano',
                                    'qty'   => 1,
                                    'price' => 15.00,
                                    'vat'   => 10,
                                ],
                            ],
                        ]
                    ),
                    new OA\Examples(
                        example: 'R2',
                        summary: 'Factura rectificativa R2 por sustitución',
                        value: [
                            'issuer' => [
                                'nif'  => 'B61206934',
                                'name' => 'Empresa Demo, S.L.',
                            ],
                            'series'       => 'FR2025',
                            'number'       => 5,
                            'issueDate'    => '2025-11-25',
                            'description'  => 'Rectificación por cambio de precio',
                            'invoiceType'  => 'R2',
                            'recipient'    => [
                                'name'    => 'Cliente Demo S.L.',
                                'nif'     => 'B12345678',
                                'country' => 'ES',
                            ],
                            'rectify' => [
                                'mode' => 'substitution',
                                'original' => [
                                    'series'    => 'F2025',
                                    'number'    => 73,
                                    'issueDate' => '2025-11-20',
                                ],
                            ],
                            'taxRegimeCode'         => '01',
                            'operationQualification' => 'S1',
                            'lines' => [
                                [
                                    'desc'  => 'Traslado aeropuerto (precio corregido)',
                                    'qty'   => 1,
                                    'price' => 90.00,
                                    'vat'   => 21,
                                ],
                            ],
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Draft created',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoicePreviewResponse')
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/UnprocessableEntity', response: 422),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/InternalServerError', response: 500),
        ]
    )]
    public function preview()
    {
        // Idempotency-Key
        $idem = $this->request->getHeaderLine('Idempotency-Key') ?: null;

        try {
            $payload = $this->request->getJSON(true);
        } catch (\Throwable $e) {
            return $this->problem(400, 'Bad Request', 'Body must be JSON');
        }

        if (!is_array($payload)) {
            return $this->problem(400, 'Bad Request', 'Body must be JSON');
        }

        try {

            // 1) Validación DTO
            $dto = \App\DTO\InvoiceDTO::fromArray($payload);

            // 2) Modelos y contexto empresa
            $ctx = service('requestContext');
            $company = $ctx->getCompany();

            $bodyIssuer = $this->normalizeNif($dto->issuerNif);
            $ctxIssuer = isset($company['issuer_nif']) ? $this->normalizeNif((string)$company['issuer_nif']) : null;

            if ($ctxIssuer !== null && $ctxIssuer !== $bodyIssuer) {
                return $this->failValidationErrors([
                    'issuerNif' => 'issuerNif does not match the emitter assigned to this API key',
                ]);
            }

            $model = new BillingHashModel();
            $companyId = (int)($company['id'] ?? 0);

            // 3) Calcular desglose y totales de líneas
            $builder = new VerifactuAeatPayloadBuilder();
            [$detail, $cuotaTotal, $importeTotal] = $builder->buildBreakdownAndTotalsFromJson(
                $dto->lines,
                $dto->taxRegimeCode ?? '01',
                $dto->operationQualification ?? 'S1'
            );

            // Puedes guardarlo en el DTO para reutilizar luego
            $dto->detail = $detail;
            $dto->totals = [
                'vat'   => $cuotaTotal,
                'gross' => $importeTotal,
            ];

            // 4) Idempotencia (si el cliente repite la misma clave, devolvemos el existente)
            if ($idem !== null) {
                $existing = $model->where([
                    'company_id'      => $companyId,
                    'idempotency_key' => $idem,
                ])->first();

                if ($existing) {
                    return $this->response
                        ->setStatusCode(409)
                        ->setJSON([
                            'data' => [
                                'document_id' => (int) $existing['id'],
                                'status'      => $existing['status'],
                                'hash'        => $existing['hash'],
                                'prev_hash'   => $existing['prev_hash'],
                                'qr_url'      => $existing['qr_url'],
                                'xml_path'    => $existing['xml_path'] ?? null,
                            ],
                            'meta' => [
                                'request_id' => $this->request->getHeaderLine('X-Request-Id') ?: '',
                                'ts'         => time(),
                                'idempotent' => true,
                            ],
                        ]);
                }
            }


            //    Busca el último hash e incrementa chain_index para este emisor/serie (ajusta el filtro si lo quieres solo por empresa)
            [$prevHash, $nextIdx] = $model->getPrevHashAndNextIndex($companyId, $dto->issuerNif);

            // 5) Transacción: insert + calcular cadena/huella + update
            $db = db_connect();
            $db->transBegin();

            $linesJson = null;
            if (!empty($dto->lines) && is_array($dto->lines)) {
                $linesJson = json_encode($dto->lines, JSON_UNESCAPED_UNICODE);
            }

            $isRectify = $dto->isRectification();

            $rectifiedId = null;
            $rectifiedMeta = null;

            if ($isRectify && $dto->rectify !== null) {
                // intentar localizar la original en tu propia tabla
                $orig = $dto->rectify;

                $origRow = $model->where([
                    'company_id' => $companyId,
                    'issuer_nif' => $dto->issuerNif,
                    'series'     => $orig->originalSeries,
                    'number'     => $orig->originalNumber,
                    'issue_date' => $orig->originalIssueDate,
                    'kind'       => 'alta', // original
                ])->first();
                if ($origRow) {
                    $rectifiedId = (int)$origRow['id'];
                }

                $rectifiedMeta = json_encode($orig->toArray(), JSON_UNESCAPED_UNICODE);
            }


            // 5.1) Insert borrador mínimo (kind='alta' si añadiste la columna)
            $id = $model->insert([
                'company_id'   => $companyId,
                'issuer_nif'          => $dto->issuerNif,
                'issuer_name'         => $dto->issuerName,
                'issuer_address'      => $dto->issuerAddress,
                'issuer_postal_code'  => $dto->issuerPostalCode,
                'issuer_city'         => $dto->issuerCity,
                'issuer_province'     => $dto->issuerProvince,
                'issuer_country_code' => $dto->issuerCountry,
                'series'       => $dto->series,
                'number'       => $dto->number,
                'issue_date'   => $dto->issueDate,
                'invoice_type' => $dto->invoiceType,
                'description'  => $dto->description,

                'client_name'     => $dto->recipientName,
                'client_document' => $dto->recipientNif
                    ?? $dto->recipientIdNumber, // NIF o IDOtro, lo que haya
                'client_address'      => $dto->recipientAddress,
                'client_postal_code'  => $dto->recipientPostalCode,
                'client_city'         => $dto->recipientCity,
                'client_province'     => $dto->recipientProvince,
                'client_country_code' => $dto->recipientCountry,

                'tax_regime_code'         => $dto->taxRegimeCode ?? '01',
                'operation_qualification' => $dto->operationQualification ?? 'S1',

                'rectified_billing_hash_id' => $rectifiedId,
                'rectified_meta_json'       => $rectifiedMeta,
                'external_id'               => $dto->externalId ?? null,
                'kind'                      => 'alta',
                'status'                    => 'draft',
                'idempotency_key'           => $idem,
                'raw_payload_json'          => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'details_json'              => json_encode($detail, JSON_UNESCAPED_UNICODE),
                'vat_total'                 => $cuotaTotal,
                'gross_total'               => $importeTotal,
                'lines_json'                => $linesJson,
            ], true);

            // 5.2) Construir cadena de ALTA y calcular huella
            //      NumSerieFactura: usa tu formato (serie+numero). Cambia si lo formateas distinto.
            $numSerieFactura = $dto->series . $dto->number;


            [$chain, $ts] = \App\Services\VerifactuCanonicalService::buildRegistrationChain([
                'issuer_nif'          => $dto->issuerNif,       // NIF EMISOR (Obligado)
                'full_invoice_number' => $numSerieFactura,
                'issue_date'          => $dto->issueDate,       // YYYY-MM-DD
                'invoice_type'        => $dto->invoiceType,     // Tipo de factura (F1,F2,F3,R1,R2,R3,R4)
                'vat_total'           => $cuotaTotal,
                'gross_total'         => $importeTotal,
                'prev_hash'           => $prevHash ?? ''
            ]);

            $hash = \App\Services\VerifactuCanonicalService::sha256Upper($chain);
            // 5.3) Actualizar con encadenamiento y trazabilidad
            $model->update($id, [
                'prev_hash'       => $prevHash,
                'chain_index'     => $nextIdx,
                'hash'            => $hash,
                'csv_text'        => $chain,
                'datetime_offset' => $ts,
            ]);

            $row = $model->find($id);

            $xmlPath = service('verifactuXmlBuilder')->buildAndSavePreview($row);

            $model->update($id, ['xml_path' => $xmlPath]);

            $qrUrl = '/api/v1/invoices/' . $id . '/qr';
            $model->update($id, ['qr_url' => $qrUrl]);

            // 5.4) (Opcional) Auto-cola según flags de empresa o query/header
            $autoQueue = false;
            $company = (new CompaniesModel())->find($companyId);
            if ($company) {
                $verifactuEnabled = (int)($company['verifactu_enabled'] ?? 0) === 1;
                $sendToAeat = (int)($company['send_to_aeat'] ?? 0) === 1;
                if ($verifactuEnabled && $sendToAeat) {
                    $autoQueue = true;
                }
            }
            $forceQueue = $this->request->getGet('queue') === '1'
                || strtolower($this->request->getHeaderLine('X-Queue')) === '1';
            if ($forceQueue) {
                $autoQueue = true;
            }

            if ($autoQueue) {
                $model->update($id, [
                    'status'          => 'ready',
                    'next_attempt_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $db->transCommit();

            // 6) Respuesta
            return $this->created([
                'document_id' => (int) $id,
                'status'      => $autoQueue ? 'ready' : 'draft',
                'hash'        => $hash,
                'prev_hash'   => $prevHash,
                'qr_url'      => $qrUrl,
                'xml_path'    => $xmlPath,
            ], [
                'queued' => $autoQueue,
            ]);
        } catch (\InvalidArgumentException $e) {
            if (isset($db) && $db->transStatus()) {
                $db->transRollback();
            }

            return $this->problem(422, 'Unprocessable Entity', $e->getMessage(), 'https://httpstatuses.com/422', 'VF422');
        } catch (\Throwable $e) {
            if (isset($db) && $db->transStatus()) {
                $db->transRollback();
            }

            return $this->problem(500, 'Internal Server Error', 'Unexpected error', 'about:blank', 'VF500');
        }
    }
    #[OA\Get(
        path: '/invoices/{id}',
        summary: 'Obtiene el estado de un draft/registro local',
        tags: ['Invoices'],
        security: [['ApiKey' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoicePreviewResponse')
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ]

    )]
    public function show($id = null)
    {
        $ctx = service('requestContext');
        $company = $ctx->getCompany();
        $companyId = (int)($company['id'] ?? 0);

        $model = new BillingHashModel();
        $row = $model->where([
            'id'         => $id,
            'company_id' => $companyId,
        ])->first();

        if (!$row) {
            return $this->problem(404, 'Not Found', 'document not found', 'about:blank', 'VF404');
        }

        return $this->ok([
            'document_id' => (int) $row['id'],
            'status'      => $row['status'],
            'hash'        => $row['hash'],
            'prev_hash'   => $row['prev_hash'],
            'qr_url'      => $row['qr_url'],
            'xml_path'    => $row['xml_path'] ?? null,
        ]);
    }
    #[OA\Get(
        path: '/invoices/preview/{id}/xml',
        summary: 'Descarga el XML de previsualización generado en /preview',
        tags: ['Invoices'],
        security: [['ApiKey' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'OK (XML)'),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ]

    )]
    public function xml($id = null)
    {
        $model = new \App\Models\BillingHashModel();
        $ctx = service('requestContext');
        $company = $ctx->getCompany();
        $row = $model->where([
            'id'         => (int)$id,
            'company_id' => (int)($company['id'] ?? 0),
        ])->first();

        if (!$row || empty($row['xml_path']) || !is_file($row['xml_path'])) {
            return $this->problem(404, 'Not Found', 'xml not found', 'about:blank', 'VF404');
        }

        return $this->response
            ->setHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $id . '.xml"')
            ->setBody(file_get_contents($row['xml_path']));
    }
    #[OA\Get(
        path: '/invoices/{id}/qr',
        summary: 'Devuelve el QR VERI*FACTU de la factura (PNG o base64)',
        tags: ['Invoices'],
        security: [['ApiKey' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'format',
                in: 'query',
                required: false,
                description: 'Opcional. Si format=base64 devuelve JSON con el PNG en base64. Si no, devuelve image/png.',
                schema: new OA\Schema(type: 'string', enum: ['base64'])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK. Por defecto devuelve PNG (image/png). Si format=base64 devuelve JSON.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'data',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'document_id', type: 'integer', example: 123),
                                        new OA\Property(property: 'mime', type: 'string', example: 'image/png'),
                                        new OA\Property(property: 'base64', type: 'string', example: 'iVBORw0KGgoAAAANSUhEUgAA...'),
                                        new OA\Property(property: 'data_uri', type: 'string', example: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...'),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'meta',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'request_id', type: 'string', example: 'req_123'),
                                        new OA\Property(property: 'ts', type: 'integer', example: 1731840000),
                                    ]
                                ),
                            ]
                        ),
                        new OA\Schema(
                            // “schema placeholder” para indicar que también puede ser binario.
                            // swagger-php no es perfecto con mixed content-types en una sola response,
                            // pero esto ayuda a que se entienda.
                            type: 'string',
                            format: 'binary'
                        )
                    ]
                )
            ),

            // Alternativa (más clara): define además un 200 explícito para PNG como MediaType:
            new OA\Response(
                response: 200,
                description: 'PNG del código QR (cuando no se usa format=base64)',
                content: new OA\MediaType(mediaType: 'image/png')
            ),

            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/InternalServerError', response: 500),
        ]
    )]

    public function qr($id = null)
    {
        $ctx = service('requestContext');
        $company = $ctx->getCompany();
        $companyId = (int)($company['id'] ?? 0);

        $model = new BillingHashModel();
        $row = $model->where([
            'id'         => (int)$id,
            'company_id' => $companyId,
        ])->first();

        if (!$row) {
            return $this->problem(404, 'Not Found', 'document not found', 'about:blank', 'VF404');
        }

        $base = WRITEPATH . 'verifactu/qr';
        $path = $base . '/' . (int)$row['id'] . '.png';

        if (!is_file($path)) {
            $path = service('verifactuQr')->buildForInvoice($row);
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return $this->problem(500, 'Internal Server Error', 'QR not available', 'about:blank', 'VF500');
        }

        $format = strtolower((string)$this->request->getGet('format')); // "base64" | ""
        if ($format === 'base64') {
            $b64 = base64_encode($content);

            return $this->response
                ->setStatusCode(200)
                ->setJSON([
                    'data' => [
                        'document_id' => (int)$row['id'],
                        'mime'        => 'image/png',
                        'base64'      => $b64,
                        'data_uri'    => 'data:image/png;base64,' . $b64,
                    ],
                    'meta' => [
                        'request_id' => $this->request->getHeaderLine('X-Request-Id') ?: '',
                        'ts'         => time(),
                    ],
                ]);
        }

        return $this->response
            ->setContentType('image/png')
            ->setBody($content);
    }

    /**
     * VERI*FACTU — Estado técnico de la factura
     *
     * Devuelve hash, encadenamiento, CSV, XML, intentos, estado AEAT y trazabilidad completa.
     */
    #[OA\Get(
        path: '/invoices/{id}/verifactu',
        summary: 'Obtiene el detalle técnico VERI*FACTU de una factura',
        tags: ['Invoices'],
        security: [['ApiKey' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoiceVerifactuResponse')
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ]

    )]
    public function verifactu($id = null)
    {
        $ctx = service('requestContext');
        $company = $ctx->getCompany();
        $companyId = (int)($company['id'] ?? 0);

        $billing = new BillingHashModel();

        $row = $billing->where([
            'id'         => (int)$id,
            'company_id' => $companyId,
        ])->first();

        if (!$row) {
            return $this->problem(404, 'Not Found', 'document not found', 'about:blank', 'VF404');
        }

        // JSON opcionales
        $lines = !empty($row['lines_json']) ? json_decode((string)$row['lines_json'], true) : null;
        $detail = !empty($row['details_json']) ? json_decode((string)$row['details_json'], true) : null;

        // Último envío, si existe
        $subModel = new SubmissionsModel();
        $lastSub = $subModel
            ->where('billing_hash_id', (int)$row['id'])
            ->orderBy('id', 'DESC')
            ->first();

        $lastSubmission = null;
        if ($lastSub) {
            $lastSubmission = [
                'type'           => (string)$lastSub['type'],
                'status'         => (string)$lastSub['status'],
                'attempt_number' => (int)$lastSub['attempt_number'],
                'error_code'     => $lastSub['error_code'] ?? null,
                'error_message'  => $lastSub['error_message'] ?? null,
                'request_ref'    => $lastSub['request_ref'] ?? $lastSub['raw_req_path'] ?? null,
                'response_ref'   => $lastSub['response_ref'] ?? $lastSub['raw_res_path'] ?? null,
                'created_at'     => $lastSub['created_at'] ?? null,
            ];
        }

        $data = [
            'document_id' => (int)$row['id'],
            'status'      => (string)$row['status'],

            'issuer_nif' => $row['issuer_nif'] ?? null,
            'series'     => $row['series'] ?? null,
            'number'     => isset($row['number']) ? (int)$row['number'] : null,
            'issue_date' => $row['issue_date'] ?? null,

            'hash'            => (string)$row['hash'],
            'prev_hash'       => $row['prev_hash'] ?? null,
            'chain_index'     => isset($row['chain_index']) ? (int)$row['chain_index'] : null,
            'csv_text'        => $row['csv_text'] ?? null,
            'datetime_offset' => $row['datetime_offset'] ?? null,
            'aeat_csv'        => $row['aeat_csv'] ?? null,

            'qr_url'   => $row['qr_url'] ?? null,
            'qr_path'  => $row['qr_path'] ?? null,
            'xml_path' => $row['xml_path'] ?? null,

            'totals' => [
                'vat_total'   => isset($row['vat_total']) ? (float)$row['vat_total'] : null,
                'gross_total' => isset($row['gross_total']) ? (float)$row['gross_total'] : null,
            ],
            'detail' => $detail,
            'lines'  => $lines,

            'last_submission' => $lastSubmission,
        ];

        $meta = [
            'request_id' => $this->request->getHeaderLine('X-Request-Id') ?: '',
            'ts'         => time(),
        ];

        // Asumiendo que tu BaseController::ok($data, $meta) envuelve como { data, meta }
        return $this->ok($data, $meta);
    }
    #[OA\Get(
        path: '/invoices/{id}/pdf',
        summary: 'Devuelve el PDF oficial VERI*FACTU',
        tags: ['Invoices'],
        security: [['ApiKey' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF'),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ]
    )]
    public function pdf($id = null)
    {
        $ctx = service('requestContext');
        $company = $ctx->getCompany();
        $companyId = (int) ($company['id'] ?? 0);

        $model = new BillingHashModel();
        $row = $model->where([
            'id'         => $id,
            'company_id' => $companyId,
        ])->first();

        if (!$row) {
            return $this->problem(404, 'Not Found', 'document not found', 'about:blank', 'VF404');
        }

        $service = service('verifactuPdf');
        $pdfPath = $service->buildPdf($row);

        return $this->response
            ->download($pdfPath, null)
            ->setFileName("Factura-{$row['series']}{$row['number']}.pdf");
    }

    #[OA\Post(
        path: '/invoices/{id}/cancel',
        summary: 'Crea un registro VERI*FACTU de anulación encadenado a la factura original',
        tags: ['Invoices'],
        security: [['ApiKey' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(ref: '#/components/schemas/InvoiceCancelRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Cancellation draft created',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoiceCancelResponse')
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/UnprocessableEntity', response: 422),
            new OA\Response(ref: '#/components/responses/InternalServerError', response: 500),
        ]
    )]
    public function cancel(int $id): ResponseInterface
    {
        $ctx = service('requestContext');
        $company = $ctx->getCompany();
        $companyId = (int)($company['id'] ?? 0);

        if ($companyId <= 0) {
            return $this->problem(403, 'Forbidden', 'No company in context', 'about:blank', 'VF403');
        }

        $payload = $this->request->getJSON(true) ?? [];

        $reason = $payload['reason'] ?? null;

        try {
            $row = (new BillingHashModel())
                ->where([
                    'id'         => $id,
                    'company_id' => $companyId,
                ])
                ->first();

            if ($row === null) {
                return $this->problem(404, 'Not Found', 'document not found', 'about:blank', 'VF404');
            }

            if (($row['kind'] ?? 'alta') !== 'alta') {
                return $this->failValidationErrors('Only alta invoices can be cancelled');
            }

            $svc = service('verifactu');
            $cancel = $svc->createCancellation($row, $reason);

            return $this->created([
                'document_id' => (int)$cancel['id'],
                'kind'        => $cancel['kind'],
                'status'      => $cancel['status'],
                'hash'        => $cancel['hash'],
                'prev_hash'   => $cancel['prev_hash'] ?? null,
                'aeat_status' => $cancel['aeat_register_status'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage(), 'https://httpstatuses.com/422', 'VF422');
        } catch (\Throwable $e) {
            log_message('error', 'Error cancelling invoice {id}: {msg}', [
                'id'  => $id,
                'msg' => $e->getMessage(),
            ]);

            return $this->problem(500, 'Internal Server Error', 'Unexpected error', 'about:blank', 'VF500');
        }
    }

    private function normalizeNif(string $nif): string
    {
        // Limpia espacios y guiones y pasa a mayúsculas
        $nif = preg_replace('/[\s\-]/', '', $nif) ?? $nif;

        return strtoupper($nif);
    }
}
