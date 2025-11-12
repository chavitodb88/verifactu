<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\DTO\InvoiceDTO;
use App\Models\BillingHashModel;
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
            $companyId = (int) ($this->request->company['id'] ?? 0);

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

            // 5.1) Insert borrador mínimo (kind='alta' si añadiste la columna)
            $id = $model->insert([
                'company_id'      => $companyId,
                'issuer_nif'      => $dto->issuerNif,
                'series'          => $dto->series,
                'number'          => $dto->number,
                'issue_date'      => $dto->issueDate,
                'external_id'     => $dto->externalId,
                'kind'            => 'alta',
                'status'          => 'draft',
                'idempotency_key' => $idem,
            ], true);
            // 5.2) Construir cadena de ALTA y calcular huella
            //      NumSerieFactura: usa tu formato (serie+numero). Cambia si lo formateas distinto.
            $numSerieFactura = $dto->series . $dto->number;

            $canonAlta = service('verifactuCanonical')->buildCadenaAlta([
                'IDEmisorFactura'        => $dto->issuerNif,
                'NumSerieFactura'        => $numSerieFactura,
                'FechaExpedicionFactura' => $dto->issueDate,        // YYYY-MM-DD (el service lo convierte a dd-mm-YYYY)
                'TipoFactura'            => 'F1',                   // REVISAR: Alfanumérico (2) Especificación del tipo de factura: factura completa, factura simplificada, factura emitida en sustitución de facturas simplificadas o factura rectificativa.
                'CuotaTotal'             => $dto->totals['vat'],    // tu “cuota” = IVA repercutido total
                'ImporteTotal'           => $dto->totals['gross'],  // total factura
            ]);

            $hash = \App\Services\VerifactuCanonicalService::sha256Upper($canonAlta);

            // 5.3) Actualizar con encadenamiento y trazabilidad
            $model->update($id, [
                'prev_hash'   => $prevHash,
                'chain_index' => $nextIdx,
                'hash'        => $hash,
                'csv_text'    => $canonAlta, // opcional: auditoría
            ]);

            // 5.3.1) XML de previsualización
            $xmlPath = service('verifactuXmlBuilder')->buildAndSavePreview($id, [
                'issuer_nif'        => $dto->issuerNif,
                'num_serie_factura' => $numSerieFactura,
                'fecha_aeat'        => \App\Services\VerifactuCanonicalService::toAeatDate($dto->issueDate),
                'tipo_factura'      => 'F1',
                'cuota_total'       => number_format((float)$dto->totals['vat'], 2, '.', ''),
                'importe_total'     => number_format((float)$dto->totals['gross'], 2, '.', ''),
                'chain_index'       => $nextIdx,
                'prev_hash'         => $prevHash,
                'hash'              => $hash,
            ]);

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
                'qr_url'      => null, // ya lo añadiremos
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
        $model = new BillingHashModel();
        $row = $model->where([
            'id' => $id,
            'company_id' => (int) ($this->request->company['id'] ?? 0),
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
        summary: 'Devuelve el QR de la factura (placeholder por ahora)',
        tags: ['Invoices'],
        security: [['ApiKey' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 501,
                description: 'Not Implemented',
                content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
            ),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(
                response: 404,
                description: 'Not Found',
                content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
            ),
        ]
    )]
    public function qr($id = null)
    {
        // Verifica que el documento existe y pertenece a la empresa de la request
        $model = new \App\Models\BillingHashModel();
        $row = $model->where([
            'id'         => (int) $id,
            'company_id' => (int) ($this->request->company['id'] ?? 0),
        ])->first();

        if (!$row) {
            return $this->problem(404, 'Not Found', 'document not found', 'about:blank', 'VF404');
        }

        // Aún no implementado el render real del QR
        return $this->problem(
            501,
            'Not Implemented',
            'QR generation not implemented yet',
            'about:blank',
            'VF501'
        );
    }
}
