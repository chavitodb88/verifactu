<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Verifactu\CancellationMode;
use App\Models\SubmissionsModel;

final class VerifactuService
{
    /**
     * Envía la factura a la AEAT mediante el servicio SOAP
     * y actualiza el estado en billing_hashes.
     * @param int $billingHashId ID del registro en billing_hashes
     * @throws \RuntimeException Si no se encuentra el billing_hashØ
     */
    public function sendToAeat(int $billingHashId): void
    {
        $bhModel = new \App\Models\BillingHashModel();
        $row = $bhModel->find($billingHashId);
        if (!$row) {
            throw new \RuntimeException('billing_hash not found');
        }
        if (!in_array((string)$row['status'], ['ready', 'error'], true)) {
            return;
        }
        $prevInvoiceNumber = null;
        $prevIssueDate     = null;

        if (!empty($row['prev_hash'])) {
            $prevRow = $bhModel
                ->where('issuer_nif', (string)$row['issuer_nif'])
                ->where('hash', (string)$row['prev_hash'])
                ->first();

            if (!$prevRow) {
                throw new \RuntimeException(
                    'prev_hash presente pero no existe el registro anterior en billing_hashes'
                );
            }

            $prevInvoiceNumber = (string)($prevRow['series'] . $prevRow['number']);
            $prevIssueDate     = (string)$prevRow['issue_date'];
        }


        $rawPayload = [];
        if (!empty($row['raw_payload_json'])) {
            $rawPayload = json_decode((string)$row['raw_payload_json'], true) ?: [];
        }

        $recipient = $rawPayload['recipient'] ?? null;
        $invoiceType = $row['invoice_type'] ?? ($rawPayload['invoiceType'] ?? 'F1');

        $numSeries = (string)($row['series'] . $row['number']);
        $company = (new \App\Models\CompaniesModel())->find((int)$row['company_id']);
        $issuerName = $company['name'] ?? '';

        if ($row['lines_json']) {
            $row['lines'] = json_decode($row['lines_json'], true);
        }

        $detail = $row['details_json'] ? json_decode($row['details_json'], true) : null;
        $kind = (string)($row['kind'] ?? 'alta');

        $payload = [];
        $submissionType = 'register';

        if ($kind === 'anulacion') {
            $modeString = (string)($row['cancellation_mode'] ?? CancellationMode::AEAT_REGISTERED->value);

            $mode = match ($modeString) {
                CancellationMode::NO_AEAT_RECORD->value                 => CancellationMode::NO_AEAT_RECORD,
                CancellationMode::PREVIOUS_CANCELLATION_REJECTED->value => CancellationMode::PREVIOUS_CANCELLATION_REJECTED,
                default                                                 => CancellationMode::AEAT_REGISTERED,
            };

            $payload = service('verifactuPayload')->buildCancellation([
                'issuer_nif'          => (string)$row['issuer_nif'],
                'issuer_name'         => (string)$issuerName,
                'full_invoice_number' => $numSeries,
                'issue_date'          => (string)$row['issue_date'],       // fecha de la factura original
                'prev_hash'           => $row['prev_hash'] ?: null,
                'hash'                => (string)$row['hash'],
                'datetime_offset'     => (string)$row['datetime_offset'],  // FechaHoraHusoGenRegistro
                'cancellation_mode'   => $mode,
            ]);

            $submissionType = 'cancel';
        } else {
            // 🔹 ALTA (F1/F2/F3 + rectificativas R1–R5)

            $rectifyMode = null; // 'S' | 'I'
            $rectifiedInvoices = null; // array de facturas originales

            if (is_string($invoiceType) && str_starts_with($invoiceType, 'R')) {
                $meta = !empty($row['rectified_meta_json'])
                    ? json_decode((string)$row['rectified_meta_json'], true)
                    : null;

                if (is_array($meta)) {
                    $rectifyMode = $this->mapRectifyMode($meta['mode'] ?? null);

                    $orig = $meta['original'] ?? null;
                    if (is_array($orig)) {
                        $rectifiedInvoices = [[
                            'issuer_nif' => (string)$row['issuer_nif'],                  // asumimos mismo emisor
                            'series'     => (string)($orig['series'] ?? ''),
                            'number'     => (int)($orig['number'] ?? 0),
                            'issueDate'  => (string)($orig['issueDate'] ?? ''),
                        ]];
                    }
                }
            }

            $payload = service('verifactuPayload')->buildRegistration([
                'issuer_nif'          => (string)$row['issuer_nif'],
                'issuer_name'         => (string)$issuerName,
                'full_invoice_number' => $numSeries,
                'issue_date'          => (string)$row['issue_date'],
                'invoice_type'        => (string)$invoiceType,
                'description'         => $row['description'] ?? 'Service',
                'detail'              => $detail,
                'lines'               => $detail ? [] : ($row['lines'] ?? []),
                'vat_total'           => (float)$row['vat_total'],
                'gross_total'         => (float)$row['gross_total'],
                'prev_hash'           => $row['prev_hash'] ?: null,
                'hash'                => (string)$row['hash'],
                'datetime_offset'     => (string)$row['datetime_offset'],

                'prev_full_invoice_number' => $prevInvoiceNumber,
                'prev_issue_date'          => $prevIssueDate,

                'recipient'           => $recipient,

                'rectify_mode'       => $rectifyMode,
                'rectified_invoices' => $rectifiedInvoices,
            ]);

            $submissionType = 'register';
        }

        [$reqPath, $resPath] = $this->ensurePaths((int)$row['id']);

        $cfg = config('Verifactu');
        $sendReal = (bool) ($cfg->sendReal ?? false);

        if ($sendReal) {
            $client = service('verifactuSoap');
            $submissions = new \App\Models\SubmissionsModel();

            try {
                $result = $client->sendInvoice($payload);

                file_put_contents($reqPath, $result['request_xml']);
                file_put_contents($resPath, $result['response_xml']);

                $parsed = $this->parseAeatResponse($result['raw_response']);

                $sendStatus = $parsed['send_status'];      // Correcto / ParcialmenteCorrecto / Incorrecto
                $registerStatus = $parsed['register_status'];  // Correcto / AceptadoConErrores / Incorrecto
                $csv = $parsed['csv'];
                $errorCode = $parsed['error_code'];
                $descError = $parsed['error_message'];

                $submissionStatus = 'sent';
                $billingStatus = 'sent';

                if ($sendStatus === 'Correcto' && $registerStatus === 'Correcto') {
                    $submissionStatus = 'accepted';
                    $billingStatus = 'accepted';
                } elseif ($registerStatus === 'AceptadoConErrores' || $sendStatus === 'ParcialmenteCorrecto') {
                    $submissionStatus = 'accepted_with_errors';
                    $billingStatus = 'accepted_with_errors';
                } else {
                    $submissionStatus = 'rejected';
                    $billingStatus = 'error';
                }

                $updateData = [
                    'status'               => $billingStatus,
                    'processing_at'        => null,
                    'next_attempt_at'      => null,
                    'aeat_csv'             => $csv,
                    'aeat_send_status'     => $sendStatus,
                    'aeat_register_status' => $registerStatus,
                    'aeat_error_code'      => $errorCode,
                    'aeat_error_message'   => $descError,
                ];

                $bhModel->update((int)$row['id'], $updateData);

                $submissions->insert([
                    'billing_hash_id' => (int)$row['id'],
                    'type'            => $submissionType, // 'register' o 'cancel'
                    'status'          => $submissionStatus,
                    'attempt_number'  => 1 + (int)$submissions
                        ->where('billing_hash_id', (int)$row['id'])
                        ->countAllResults(),
                    'request_ref'   => basename($reqPath),
                    'response_ref'  => basename($resPath),
                    'raw_req_path'  => $reqPath,
                    'raw_res_path'  => $resPath,
                    'error_code'    => $errorCode,
                    'error_message' => $descError,
                ]);
            } catch (\Throwable $e) {
                $lastReq = method_exists($client, 'getLastSignedRequest') ? $client->getLastSignedRequest() : '';
                $lastRes = method_exists($client, '__getLastResponse') ? ($client->__getLastResponse() ?: '') : '';
                file_put_contents($reqPath, $lastReq);
                file_put_contents($resPath, $lastRes);

                $this->scheduleRetry($row, $bhModel, $e->getMessage());

                return;
            }
        } else {
            // Simulation
            file_put_contents($reqPath, $this->arrayToPrettyXml('RegFactuSistemaFacturacion', $payload));
            file_put_contents($resPath, json_encode([
                'http_status' => 200,
                'aeat_status' => 'ACCEPTED',
                'message'     => 'Simulated OK',
                'ts'          => date('c'),
            ], JSON_PRETTY_PRINT));

            (new \App\Models\SubmissionsModel())->insert([
                'billing_hash_id' => (int)$row['id'],
                'type'            => $submissionType,
                'status'          => 'sent',
                'attempt_number'  => 1 + (int)(new \App\Models\SubmissionsModel())
                    ->where('billing_hash_id', (int)$row['id'])
                    ->countAllResults(),
                'request_ref'  => basename($reqPath),
                'response_ref' => basename($resPath),
                'raw_req_path' => $reqPath,
                'raw_res_path' => $resPath,
            ]);

            $bhModel->update((int)$row['id'], [
                'status'          => 'sent',
                'processing_at'   => null,
                'next_attempt_at' => null,
            ]);
        }
    }


    private function scheduleRetry(array $row, \App\Models\BillingHashModel $bhModel, string $err): void
    {
        (new \App\Models\SubmissionsModel())->insert([
            'billing_hash_id' => (int)$row['id'],
            'type'            => 'register',
            'status'          => 'error',
            'attempt_number'  => 1 + (int)(new \App\Models\SubmissionsModel())->where('billing_hash_id', (int)$row['id'])->countAllResults(),
            'raw_req_path'    => null,
            'raw_res_path'    => null,
            'error_code'      => null,
            'error_message'   => $err,
        ]);
        $bhModel->update((int)$row['id'], [
            'status'          => 'error',
            'processing_at'   => null,
            'next_attempt_at' => date('Y-m-d H:i:s', time() + 15 * 60),
        ]);
    }

    /**
     * Crea un nuevo registro de anulación (kind = 'anulacion') encadenado a la factura original.
     *
     * @param array $originalRow Fila de billing_hashes de la factura de alta
     * @return array Fila recién creada de billing_hashes (anulación)
     */
    public function createCancellation(array $originalRow, ?string $reason = null): array
    {
        $bhModel = new \App\Models\BillingHashModel();

        $companyId = (int)$originalRow['company_id'];
        $issuerNif = (string)$originalRow['issuer_nif'];
        $series = (string)$originalRow['series'];
        $number = (string)$originalRow['number'];
        $issueDate = (string)$originalRow['issue_date'];
        $fullNumber = $series . $number;

        $mode = $this->determineCancellationMode((int)$originalRow['id']);

        [$prevHash, $nextIdx] = $bhModel->getPrevHashAndNextIndex(
            $companyId,
            $issuerNif
        );

        [$chain, $generatedAt] = \App\Services\VerifactuCanonicalService::buildCancellationChain([
            'issuer_nif'          => $issuerNif,
            'full_invoice_number' => $fullNumber,
            'issue_date'          => $issueDate,
            'prev_hash'           => $prevHash ?? '',
        ]);

        $hash = \App\Services\VerifactuCanonicalService::sha256Upper($chain);

        $data = [
            'company_id'               => $companyId,
            'issuer_nif'               => $issuerNif,
            'series'                   => $series,
            'number'                   => $number,
            'issue_date'               => $issueDate,
            'external_id'              => $originalRow['external_id'] ?? null,
            'kind'                     => 'anulacion',
            'status'                   => 'ready',
            'original_billing_hash_id' => (int)$originalRow['id'],
            'cancel_reason'            => $reason,
            'cancellation_mode'        => $mode->value,
            'prev_hash'                => $prevHash,
            'chain_index'              => $nextIdx,
            'hash'                     => $hash,
            'csv_text'                 => $chain,
            'datetime_offset'          => $generatedAt,
            'vat_total'                => 0,
            'gross_total'              => 0,
        ];

        $id = $bhModel->insert($data, true);

        $cancelRow = $bhModel->find($id);
        if (!is_array($cancelRow)) {
            throw new \RuntimeException('Error creating cancellation row');
        }

        return $cancelRow;
    }

    private function ensurePaths(int $id): array
    {
        $base = WRITEPATH . 'verifactu';
        @mkdir($base . '/requests', 0775, true);
        @mkdir($base . '/responses', 0775, true);

        return [$base . "/requests/{$id}-request.xml", $base . "/responses/{$id}-response.xml"];
    }

    private function arrayToPrettyXml(string $root, array $data): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->appendChild($this->arrayToNode($dom, $root, $data));

        return $dom->saveXML() ?: '';
    }
    private function arrayToNode(\DOMDocument $dom, string $name, $value): \DOMNode
    {
        $node = $dom->createElement($name);
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $node->appendChild($this->arrayToNode($dom, is_int($k) ? 'item' : (string)$k, $v));
            }
        } else {
            $node->appendChild($dom->createTextNode((string)$value));
        }

        return $node;
    }

    private function parseAeatResponse($raw): array
    {
        if (is_object($raw)) {
            $raw = (array) $raw;
        }

        if (isset($raw['RespuestaRegFactuSistemaFacturacion'])) {
            $raw = (array) $raw['RespuestaRegFactuSistemaFacturacion'];
        }

        $sendStatus = (string)($raw['EstadoEnvio'] ?? '');

        $line = $raw['RespuestaLinea'] ?? null;

        if (is_array($line) && isset($line[0])) {
            $line = $line[0];
        }

        if (is_object($line)) {
            $line = (array) $line;
        } elseif (!is_array($line)) {
            $line = [];
        }

        $registerStatus = (string)($line['EstadoRegistro'] ?? '');
        $errorCode = isset($line['CodigoErrorRegistro']) ? (string)$line['CodigoErrorRegistro'] : null;
        $errorMessage = isset($line['DescripcionErrorRegistro']) ? (string)$line['DescripcionErrorRegistro'] : null;

        $csv = isset($raw['CSV']) ? (string)$raw['CSV'] : null;

        return [
            'send_status'     => $sendStatus,       // Correcto, ParcialmenteCorrecto, Incorrecto
            'register_status' => $registerStatus,    // Correcto, AceptadoConErrores, Incorrecto
            'csv'             => $csv,
            'error_code'      => $errorCode,
            'error_message'   => $errorMessage,
            'raw_line'        => $line,
        ];
    }

    private function determineCancellationMode(int $originalBillingHashId): CancellationMode
    {
        $subs = new SubmissionsModel();

        $hasRejectedCancel = $subs
            ->where('billing_hash_id', $originalBillingHashId)
            ->where('type', 'cancel')
            ->where('status', 'rejected')
            ->countAllResults() > 0;

        if ($hasRejectedCancel) {
            return CancellationMode::PREVIOUS_CANCELLATION_REJECTED;
        }

        // 2) ¿Hay un alta aceptada (o aceptada con errores)?
        $hasAcceptedRegister = (new SubmissionsModel())
            ->where('billing_hash_id', $originalBillingHashId)
            ->where('type', 'register')
            ->whereIn('status', ['accepted', 'accepted_with_errors'])
            ->countAllResults() > 0;

        if ($hasAcceptedRegister) {
            return CancellationMode::AEAT_REGISTERED;
        }

        // 3) Si no hay alta aceptada → asumimos que NO hay registro previo en AEAT
        return CancellationMode::NO_AEAT_RECORD;
    }

    private function mapRectifyMode(?string $mode): ?string
    {
        // from body: 'substitution' | 'difference' → AEAT: 'S' | 'I'
        return match ($mode) {
            'substitution' => 'S',
            'difference'   => 'I',
            default        => null,
        };
    }
}
