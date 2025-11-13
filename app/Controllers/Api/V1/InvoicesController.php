<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\DTO\InvoiceDTO;
use App\Models\BillingHashModel;
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
                'cuota_total'     => $cuotaTotal,
                'importe_total'   => $importeTotal,
                'lines_json'     => $linesJson,
            ], true);

            // 5.2) Construir cadena de ALTA y calcular huella
            //      NumSerieFactura: usa tu formato (serie+numero). Cambia si lo formateas distinto.
            $numSerieFactura = $dto->series . $dto->number;


            [$cadena, $ts] = \App\Services\VerifactuCanonicalService::buildCadenaAlta([
                'issuer_nif'    => 'B56893324', //$dto->issuerNif,       // NIF EMISOR (Obligado)
                'num_serie_factura' => $numSerieFactura,
                'issue_date'    => $dto->issueDate,       // YYYY-MM-DD
                'tipo_factura'  => 'F1',
                'cuota_total'   => $cuotaTotal,
                'importe_total' => $importeTotal,
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
        $row = $model->where([
            'id'         => (int)$id,
            'company_id' => (int)($this->request->company['id'] ?? 0),
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
        path: "/invoices/{id}/verifactu",
        summary: "Obtiene la información VERI*FACTU de una factura",
        description: "Devuelve hash, encadenamiento, CSV, XML, estado AEAT y todo el historial de envíos.",
        tags: ["Invoices"],
        security: [["ApiKey" => []]],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                description: "ID interno del documento",
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Información VERI*FACTU",
                content: new OA\JsonContent()
            ),
            new OA\Response(
                response: 404,
                description: "No encontrado"
            ),
            new OA\Response(
                response: 403,
                description: "Prohibido para otra empresa"
            )
        ]
    )]

    public function verifactu(int $id)
    {
        $model = new \App\Models\BillingHashModel();
        $row = $model->find($id);

        if (!$row) {
            return $this->failNotFound("Document not found");
        }

        // Seguridad: comprobar que pertenece a la empresa del API key
        $ctx     = service('requestContext');
        $company = $ctx->getCompany();
        $companyId = (int)($company['id'] ?? 0);
        if ((int)$row['company_id'] !== $companyId) {
            return $this->failForbidden("Not allowed for this company");
        }

        // Submissions (historial de intentos)
        $subModel = new \App\Models\SubmissionsModel();
        $attempts = $subModel->where('billing_hash_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();

        // Paths para request/response
        $xmlPreview      = $row['xml_path'] ?? null;
        $latestReqXml    = null;
        $latestResXml    = null;

        foreach ($attempts as $a) {
            if (!empty($a['raw_req_path']) && file_exists($a['raw_req_path'])) {
                $latestReqXml = file_get_contents($a['raw_req_path']);
            }
            if (!empty($a['raw_res_path']) && file_exists($a['raw_res_path'])) {
                $latestResXml = file_get_contents($a['raw_res_path']);
            }
        }

        return $this->respond([
            'document' => [
                'id'             => (int) $row['id'],
                'issuer_nif'     => $row['issuer_nif'],
                'series'         => $row['series'],
                'number'         => $row['number'],
                'issue_date'     => $row['issue_date'],
                'lines'          => $row['lines_json'] ? json_decode($row['lines_json'], true) : [],
                'detalle'        => $row['detalle_json'] ? json_decode($row['detalle_json'], true) : [],
                'totals' => [
                    'vat'   => (float) $row['cuota_total'],
                    'gross' => (float) $row['importe_total'],
                ],
            ],
            'chain' => [
                'prev_hash'   => $row['prev_hash'],
                'hash'        => $row['hash'],
                'chain_index' => (int) $row['chain_index'],
                'fecha_huso'  => $row['fecha_huso'],
                'csv_text'    => $row['csv_text'],
                'qr_url'      => $row['qr_url'],
            ],
            'aeat' => [
                'csv'              => $row['aeat_csv'],
                'estado_envio'     => $row['aeat_estado_envio'],
                'estado_registro'  => $row['aeat_estado_registro'],
                'codigo_error'     => $row['aeat_codigo_error'],
                'descripcion_error' => $row['aeat_descripcion_error'],
            ],
            'xml' => [
                'preview'        => $xmlPreview ? file_get_contents($xmlPreview) : null,
                'last_request'   => $latestReqXml,
                'last_response'  => $latestResXml,
            ],
            'attempts' => $attempts,
        ]);
    }
}
