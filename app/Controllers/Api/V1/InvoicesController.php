<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\DTO\InvoiceDTO;
use App\Models\BillingHashModel;
use App\Models\SubmissionsModel;
use App\Services\VerifactuAeatPayloadBuilder;
use OpenApi\Attributes as OA;

final class InvoicesController extends BaseApiController
{
    #[OA\Post(
        path: '/invoices/preview',
        summary: 'Valida el payload, crea borrador y devuelve metadatos (sin enviar a AEAT)',
        tags: ['Invoices'],
        security: [['ApiKey' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/InvoiceInput')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Draft created',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoicePreviewResponse')
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 422,
                description: 'Unprocessable Entity',
                content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
            ),
            new OA\Response(
                response: 409,
                description: 'Conflict (idempotency)',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoicePreviewResponse')
            ),
        ]
    )]
    public function preview()
    {
        // Idempotency-Key
        $idem = $this->request->getHeaderLine('Idempotency-Key') ?: null;

        try {
            $payload = $this->request->getJSON(true);
            if (!is_array($payload)) {
                return $this->problem(400, 'Bad Request', 'Body must be JSON');
            }

            // 1) Validación DTO
            $dto = \App\DTO\InvoiceDTO::fromArray($payload);

            // 2) Modelos y contexto empresa
            $model = new \App\Models\BillingHashModel();
            $ctx = service('requestContext');
            $company = $ctx->getCompany();
            $companyId = (int)($company['id'] ?? 0);


            // 3) Calcular desglose y totales de líneas
            $builder = new VerifactuAeatPayloadBuilder();
            [$detalle, $cuotaTotal, $importeTotal] = $builder->buildDesgloseYTotalesFromJson($dto->lines);

            // Puedes guardarlo en el DTO para reutilizar luego
            $dto->detalle = $detalle;
            $dto->totals = [
                'vat'   => $cuotaTotal,
                'gross' => $importeTotal,
            ];

            // 3) Idempotencia (si el cliente repite la misma clave, devolvemos el existente)
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
            [$prevHash, $nextIdx] = $model->getPrevHashAndNextIndex($companyId, $dto->issuerNif, $dto->series);

            // 5) Transacción: insert + calcular cadena/huella + update
            $db = db_connect();
            $db->transBegin();

            $linesJson = null;
            if (!empty($dto->lines) && is_array($dto->lines)) {
                $linesJson = json_encode($dto->lines, JSON_UNESCAPED_UNICODE);
            }

            // 5.1) Insert borrador mínimo (kind='alta' si añadiste la columna)
            $id = $model->insert([
                'company_id'      => $companyId,
                'issuer_nif'      => $dto->issuerNif,
                'series'          => $dto->series,
                'number'          => $dto->number,
                'issue_date'      => $dto->issueDate,
                'external_id'     => $dto->externalId ?? null,
                'kind'            => 'alta',
                'status'          => 'draft',
                'idempotency_key' => $idem,
                'raw_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'detalle_json'    => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                'vat_total'     => $cuotaTotal,
                'gross_total'   => $importeTotal,
                'lines_json'     => $linesJson,
            ], true);

            // 5.2) Construir cadena de ALTA y calcular huella
            //      NumSerieFactura: usa tu formato (serie+numero). Cambia si lo formateas distinto.
            $numSerieFactura = $dto->series . $dto->number;


            [$cadena, $ts] = \App\Services\VerifactuCanonicalService::buildCadenaAlta([
                'issuer_nif'    => $dto->issuerNif,       // NIF EMISOR (Obligado)
                'num_serie_factura' => $numSerieFactura,
                'issue_date'    => $dto->issueDate,       // YYYY-MM-DD
                'invoice_type'  => $dto->invoiceType,     // Tipo de factura (F1,F2,F3,R1,R2,R3,R4)
                'vat_total'   => $cuotaTotal,
                'gross_total' => $importeTotal,
                'prev_hash'     => $prevHash ?? ''
            ]);

            $hash = \App\Services\VerifactuCanonicalService::sha256Upper($cadena);
            // 5.3) Actualizar con encadenamiento y trazabilidad
            $model->update($id, [
                'prev_hash'   => $prevHash,
                'chain_index' => $nextIdx,
                'hash'        => $hash,
                'csv_text'    => $cadena,
                'fecha_huso'  => $ts,
            ]);

            $row = $model->find($id);

            // 5.3.1) XML de previsualización
            $xmlPath = service('verifactuXmlBuilder')->buildAndSavePreview($row);

            $model->update($id, ['xml_path' => $xmlPath]);

            $qrUrl = '/api/v1/invoices/' . $id . '/qr';
            $model->update($id, ['qr_url' => $qrUrl]);

            // 5.4) (Opcional) Auto-cola según flags de empresa o query/header
            $autoQueue = false;
            $company   = (new \App\Models\CompaniesModel())->find($companyId);
            if ($company) {
                $verifactuEnabled = (int)($company['verifactu_enabled'] ?? 0) === 1;
                $sendToAeat       = (int)($company['send_to_aeat'] ?? 0) === 1;
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
                'queued'      => $autoQueue,
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
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/InvoicePreviewResponse')),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(response: 404, description: 'Not Found', content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')),
        ]
    )]
    public function show($id = null)
    {
        $ctx = service('requestContext');
        $company = $ctx->getCompany();
        $companyId = (int)($company['id'] ?? 0);

        $model = new BillingHashModel();
        $row = $model->where([
            'id' => $id,
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
            new OA\Response(response: 404, description: 'Not Found', content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')),
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
        summary: 'Devuelve el QR VERI*FACTU de la factura',
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
                description: 'PNG del código QR',
                content: new OA\MediaType(
                    mediaType: 'image/png'
                )
            ),
            new OA\Response(response: 404, description: 'Not Found'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function qr($id = null)
    {
        $ctx     = service('requestContext');
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

        // Ruta determinista, sin guardar en BD
        $base = WRITEPATH . 'verifactu/qr';
        $path = $base . '/' . (int)$row['id'] . '.png';

        if (!is_file($path)) {
            $path = service('verifactuQr')->buildForInvoice($row);
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return $this->problem(500, 'Internal Server Error', 'QR not available', 'about:blank', 'VF500');
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
            new OA\Response(
                response: 404,
                description: 'Not Found',
                content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
            ),
        ]
    )]
    public function verifactu($id = null)
    {
        $ctx     = service('requestContext');
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
        $lines   = !empty($row['lines_json'])   ? json_decode((string)$row['lines_json'], true)   : null;
        $detalle = !empty($row['detalle_json']) ? json_decode((string)$row['detalle_json'], true) : null;

        // Último envío, si existe
        $subModel = new SubmissionsModel();
        $lastSub  = $subModel
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

            'issuer_nif'  => $row['issuer_nif'] ?? null,
            'series'      => $row['series'] ?? null,
            'number'      => isset($row['number']) ? (int)$row['number'] : null,
            'issue_date'  => $row['issue_date'] ?? null,

            'hash'        => (string)$row['hash'],
            'prev_hash'   => $row['prev_hash'] ?? null,
            'chain_index' => isset($row['chain_index']) ? (int)$row['chain_index'] : null,
            'csv_text'    => $row['csv_text'] ?? null,
            'fecha_huso'  => $row['fecha_huso'] ?? null,
            'aeat_csv'    => $row['aeat_csv'] ?? null,

            'qr_url'   => $row['qr_url'] ?? null,
            'qr_path'  => $row['qr_path'] ?? null,
            'xml_path' => $row['xml_path'] ?? null,

            'totals' => [
                'vat_total'   => isset($row['vat_total'])   ? (float)$row['vat_total']   : null,
                'gross_total' => isset($row['gross_total']) ? (float)$row['gross_total'] : null,
            ],
            'detalle' => $detalle,
            'lines'   => $lines,

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
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function pdf($id = null)
    {
        $ctx = service('requestContext');
        $company = $ctx->getCompany();
        $companyId = (int) ($company['id'] ?? 0);

        $model = new BillingHashModel();
        $row = $model->where([
            'id' => $id,
            'company_id' => $companyId,
        ])->first();

        if (!$row) {
            return $this->problem(404, 'Not Found', 'document not found', 'about:blank', 'VF404');
        }

        $service = service('verifactuPdf');
        $pdfPath = $service->buildPdf($row, $company);

        return $this->response
            ->download($pdfPath, null)
            ->setFileName("Factura-{$row['series']}{$row['number']}.pdf");
    }
}
