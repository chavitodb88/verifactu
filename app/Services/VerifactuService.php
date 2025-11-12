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

        $payloadAlta = service('verifactuPayload')->buildAlta([
            'issuer_name'       => $issuerName,
            'issuer_nif'        => (string)$row['issuer_nif'],
            'num_serie_factura' => $numSerie,
            'issue_date'        => (string)$row['issue_date'],
            'tipo_factura'      => 'F1',
            'cuota_total'       => 0.0,               // TODO: cuando tengas totales en BD
            'importe_total'     => 0.0,               // idem
            'prev_hash'         => $row['prev_hash'] ?: null,
            'huella'            => (string)$row['hash'],
        ]);

        [$reqPath, $resPath] = $this->ensurePaths((int)$row['id']);

        $sendReal = strtolower((string) getenv('VERIFACTU_SEND_REAL')) === '1';

        if ($sendReal) {
            $client  = service('verifactuSoap');
            try {
                $result   = $client->sendInvoice($payloadAlta);

                echo 'Última URL: ' . $client->__getLastRequestHeaders();

                file_put_contents($reqPath, $result['request_xml']);
                file_put_contents($resPath, $result['response_xml']);

                $status = 'sent'; // luego parsear y mapear a accepted/rejected
                (new \App\Models\SubmissionsModel())->insert([
                    'billing_hash_id' => (int)$row['id'],
                    'type'            => 'register',
                    'status'          => $status,
                    'attempt_number'  => 1 + (int)(new \App\Models\SubmissionsModel())->where('billing_hash_id', (int)$row['id'])->countAllResults(),
                    'request_ref'     => basename($reqPath),
                    'response_ref'    => basename($resPath),
                    'raw_req_path'    => $reqPath,
                    'raw_res_path'    => $resPath,
                ]);
                $bhModel->update((int)$row['id'], ['status' => $status, 'processing_at' => null, 'next_attempt_at' => null]);
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
}
