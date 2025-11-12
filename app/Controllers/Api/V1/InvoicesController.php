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

            $dto = InvoiceDTO::fromArray($payload);

            // idempotencia: si viene clave, reutiliza
            $model = new BillingHashModel();
            $companyId = (int) ($this->request->company['id'] ?? 0);

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
                                'ts' => time(),
                                'idempotent' => true,
                            ],
                        ]);
                }
            }

            // (Aún sin calcular hash/prev/qr/xml) -> guardamos draft
            $id = $model->insert([
                'company_id'     => $companyId,
                'issuer_nif'     => $dto->issuerNif,
                'series'         => $dto->series,
                'number'         => $dto->number,
                'issue_date'     => $dto->issueDate,
                'external_id'    => $dto->externalId,
                'status'         => 'draft',
                'idempotency_key' => $idem,
            ], true);

            $autoQueue = false;

            // a) según configuración de la empresa
            $companyId = (int) ($this->request->company['id'] ?? 0);
            $company   = (new \App\Models\CompaniesModel())->find($companyId);
            if ($company) {
                $verifactuEnabled = (int)($company['verifactu_enabled'] ?? 0) === 1;
                $sendToAeat       = (int)($company['send_to_aeat'] ?? 0) === 1;
                // si VERI*FACTU está habilitado y queremos enviar (aunque sea diferido)
                if ($verifactuEnabled && $sendToAeat) {
                    $autoQueue = true;
                }
            }

            // b) opcional: permitir forzar por query/header (útil en tests o por empresa)
            $forceQueue = $this->request->getGet('queue') === '1'
                || strtolower($this->request->getHeaderLine('X-Queue')) === '1';
            if ($forceQueue) {
                $autoQueue = true;
            }

            if ($autoQueue) {
                $model->update($id, [
                    'status'          => 'ready',
                    'next_attempt_at' => date('Y-m-d H:i:s'), // ya elegible
                ]);
            }

            return $this->created([
                'document_id' => (int) $id,
                'status'      => $autoQueue ? 'ready' : 'draft',
                'hash'        => null,
                'prev_hash'   => null,
                'qr_url'      => null,
            ], [
                'queued'      => $autoQueue,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(422, 'Unprocessable Entity', $e->getMessage(), 'https://httpstatuses.com/422', 'VF422');
        } catch (\Throwable $e) {
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
}
