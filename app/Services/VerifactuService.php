<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BillingHashModel;
use App\Models\SubmissionsModel;

final class VerifactuService
{
    public function sendToAeat(int $billingHashId): void
    {
        $bhModel = new \App\Models\BillingHashModel();
        $row = $bhModel->find($billingHashId);
        if (!$row) throw new \RuntimeException('billing_hash not found');
        if (!in_array((string)$row['status'], ['ready', 'error'], true)) return;

        $numSerie = (string)($row['series'] . $row['number']);

        // Datos mínimos para payload (ajusta issuer_name según tabla companies)
        $company = (new \App\Models\CompaniesModel())->find((int)$row['company_id']);
        $issuerName = $company['name'] ?? 'Empresa';
        if ($row['lines_json']) {
            $row['lines'] = json_decode($row['lines_json'], true);
        }

        $detalle = $row['detalle_json'] ? json_decode($row['detalle_json'], true) : null;

        $payloadAlta = service('verifactuPayload')->buildAlta([
            'issuer_nif'        => (string)$row['issuer_nif'],
            'issuer_name'       => (string)($issuerName),
            'num_serie_factura' => $numSerie,
            'issue_date'        => (string)$row['issue_date'],
            'tipo_factura'      => 'F1',
            'descripcion'       => $row['description'] ?? 'Servicio',
            'detalle'           => $detalle,
            'lines'             => $detalle ? [] : ($row['lines'] ?? []),
            'cuota_total'       => (float)$row['cuota_total'],
            'importe_total'     => (float)$row['importe_total'],
            'prev_hash'         => $row['prev_hash'] ?: null,
            'huella'            => (string)$row['hash'],
            'fecha_huso'        => (string)$row['fecha_huso'],
            // 'sistema_informatico' => [
            //     'nombre_razon'       => 'Mytransfer APP SL',
            //     'nif'                 => 'B56893324',
            //     'nombre_sif'          => 'MyTransferApp',
            //     'id_sif'              => '77',
            //     'version'             => '1.0.3',
            //     'numero_instalacion'  => '0999',      // o el que toque por empresa
            //     'solo_verifactu'      => 'S',
            //     'multi_ot'            => 'S',
            //     'multiples_ot'        => 'S',
            // ],
        ]);

        [$reqPath, $resPath] = $this->ensurePaths((int)$row['id']);

        $sendReal = strtolower((string) getenv('VERIFACTU_SEND_REAL')) === '1';

        if ($sendReal) {
            $client  = service('verifactuSoap');
            $submissions = new \App\Models\SubmissionsModel();
            try {
                $result   = $client->sendInvoice($payloadAlta);

                file_put_contents($reqPath, $result['request_xml']);
                file_put_contents($resPath, $result['response_xml']);

                $parsed = $this->parseAeatResponse($result['raw_response']);

                $estadoEnvio    = $parsed['estado_envio'];      // Correcto / ParcialmenteCorrecto / Incorrecto
                $estadoRegistro = $parsed['estado_registro'];   // Correcto / AceptadoConErrores / Incorrecto
                $csv            = $parsed['csv'];
                $codigoError    = $parsed['codigo_error'];
                $descError      = $parsed['descripcion_error'];

                // Mapear a estados internos
                $submissionStatus = 'sent';
                $billingStatus    = 'sent';

                if ($estadoEnvio === 'Correcto' && $estadoRegistro === 'Correcto') {
                    $submissionStatus = 'accepted';
                    $billingStatus    = 'accepted';
                } elseif ($estadoRegistro === 'AceptadoConErrores' || $estadoEnvio === 'ParcialmenteCorrecto') {
                    $submissionStatus = 'accepted_with_errors';
                    $billingStatus    = 'accepted_with_errors';
                } else {
                    // EstadoEnvio Incorrecto o EstadoRegistro Incorrecto → error funcional (no reintentar solo)
                    $submissionStatus = 'rejected';
                    $billingStatus    = 'error';
                }

                // Opcional: si tienes columna csv en billing_hashes, podrías guardarlo aquí.
                $updateData = [
                    'status'        => $billingStatus,
                    'processing_at' => null,
                    'next_attempt_at' => null,
                    'aeat_csv'              => $parsed['csv'],
                    'aeat_estado_envio'     => $estadoEnvio,
                    'aeat_estado_registro'  => $estadoRegistro,
                    'aeat_codigo_error'     => $parsed['codigo_error'],
                    'aeat_descripcion_error' => $parsed['descripcion_error']
                ];

                $bhModel->update((int)$row['id'], $updateData);

                $submissions->insert([
                    'billing_hash_id' => (int)$row['id'],
                    'type'            => 'register',
                    'status'          => $submissionStatus,
                    'attempt_number'  => 1 + (int)$submissions
                        ->where('billing_hash_id', (int)$row['id'])
                        ->countAllResults(),
                    'request_ref'     => basename($reqPath),
                    'response_ref'    => basename($resPath),
                    'raw_req_path'    => $reqPath,
                    'raw_res_path'    => $resPath,
                    'error_code'      => $codigoError,
                    'error_message'   => $descError,
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
            // Simulación
            file_put_contents($reqPath, $this->arrayToPrettyXml('RegFactuSistemaFacturacion', $payloadAlta));
            file_put_contents($resPath, json_encode([
                'http_status' => 200,
                'aeat_status' => 'ACCEPTED',
                'message' => 'Simulated OK',
                'ts' => date('c')
            ], JSON_PRETTY_PRINT));
            (new \App\Models\SubmissionsModel())->insert([
                'billing_hash_id' => (int)$row['id'],
                'type' => 'register',
                'status' => 'sent',
                'attempt_number' => 1 + (int)(new \App\Models\SubmissionsModel())->where('billing_hash_id', (int)$row['id'])->countAllResults(),
                'request_ref' => basename($reqPath),
                'response_ref' => basename($resPath),
                'raw_req_path' => $reqPath,
                'raw_res_path' => $resPath,
            ]);
            $bhModel->update((int)$row['id'], ['status' => 'sent', 'processing_at' => null, 'next_attempt_at' => null]);
        }
    }

    private function scheduleRetry(array $row, \App\Models\BillingHashModel $bhModel, string $err): void
    {
        (new \App\Models\SubmissionsModel())->insert([
            'billing_hash_id' => (int)$row['id'],
            'type' => 'register',
            'status' => 'error',
            'attempt_number' => 1 + (int)(new \App\Models\SubmissionsModel())->where('billing_hash_id', (int)$row['id'])->countAllResults(),
            'raw_req_path' => null,
            'raw_res_path' => null,
            'error_code' => null,
            'error_message' => $err,
        ]);
        $bhModel->update((int)$row['id'], [
            'status' => 'error',
            'processing_at' => null,
            'next_attempt_at' => date('Y-m-d H:i:s', time() + 15 * 60),
        ]);
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
        // Puede venir como stdClass o array
        if (is_object($raw)) {
            $raw = (array) $raw;
        }

        // A veces el root viene envuelto en otra clave
        if (isset($raw['RespuestaRegFactuSistemaFacturacion'])) {
            $raw = (array) $raw['RespuestaRegFactuSistemaFacturacion'];
        }

        $estadoEnvio = (string)($raw['EstadoEnvio'] ?? '');

        // RespuestaLinea puede ser objeto o array de objetos
        $linea = $raw['RespuestaLinea'] ?? null;

        if (is_array($linea) && isset($linea[0])) {
            // Si es array de líneas, cogemos la primera (para tu caso basta)
            $linea = $linea[0];
        }

        if (is_object($linea)) {
            $linea = (array) $linea;
        } elseif (!is_array($linea)) {
            $linea = [];
        }

        $estadoRegistro   = (string)($linea['EstadoRegistro'] ?? '');
        $codigoError      = isset($linea['CodigoErrorRegistro']) ? (string)$linea['CodigoErrorRegistro'] : null;
        $descripcionError = isset($linea['DescripcionErrorRegistro']) ? (string)$linea['DescripcionErrorRegistro'] : null;

        $csv = isset($raw['CSV']) ? (string)$raw['CSV'] : null;

        return [
            'estado_envio'      => $estadoEnvio,       // Correcto, ParcialmenteCorrecto, Incorrecto
            'estado_registro'   => $estadoRegistro,    // Correcto, AceptadoConErrores, Incorrecto
            'csv'               => $csv,
            'codigo_error'      => $codigoError,
            'descripcion_error' => $descripcionError,
            'raw_linea'         => $linea,
        ];
    }
}
