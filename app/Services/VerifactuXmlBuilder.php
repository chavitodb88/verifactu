<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\VerifactuFormatter;

final class VerifactuXmlBuilder
{
    /**
     * Genera un XML de previsualización a partir de la fila de billing_hashes
     * y lo guarda en WRITEPATH/verifactu/previews/{id}-preview.xml
     * Devuelve la ruta absoluta del fichero guardado.
     *
     * Requisitos mínimos en $row:
     * - id, issuer_nif, issuer_name, series, number, issue_date (YYYY-MM-DD)
     * - hash, fecha_huso (misma que se usó para calcular la huella)
     * - detalle_json/vat_total/gross_total  (preferente)
     *   o en su defecto lines_json (entonces se calcula desglose con el builder)
     * - prev_hash (opcional)
     */
    public function buildAndSavePreview(array $row): string
    {
        // 0) Campos base
        $id         = (int) $row['id'];
        $issuerNif  = (string) $row['issuer_nif'];
        $issuerName = (string) ($row['issuer_name'] ?? 'Empresa');
        $numSerie   = (string) (($row['series'] ?? '') . ($row['number'] ?? ''));
        $issueDate  = (string) $row['issue_date']; // YYYY-MM-DD
        $fechaAeat  = VerifactuFormatter::toAeatDate($issueDate); // dd-mm-YYYY
        $fechaHuso  = (string) ($row['fecha_huso'] ?? '');
        $hash       = (string) $row['hash'];
        $prevHash   = $row['prev_hash'] ?? null;

        // 1) Desglose y totales (prioridad a detalle_json + totales guardados)
        $detalle = null;
        $cuotaTotal = (float) ($row['vat_total'] ?? 0.0);
        $importeTotal = (float) ($row['gross_total'] ?? 0.0);

        if (!empty($row['detalle_json'])) {
            $detalle = json_decode((string) $row['detalle_json'], true) ?: [];
            // Se asume que vat_total/gross_total vienen ya en la fila (no recalcular)
        } else {
            // Calcular desde lines_json SOLO para preview si no hay detalle_json
            $lines = [];
            if (!empty($row['lines_json'])) {
                $lines = json_decode((string) $row['lines_json'], true) ?: [];
            }
            [$detalleCalc, $cuotaTotal, $importeTotal] =
                (new VerifactuAeatPayloadBuilder())->buildDesgloseYTotalesFromJson($lines);

            // Normalizamos formato de detalle al esperado en el XML
            $detalle = array_map(static function (array $g) {
                return [
                    'ClaveRegimen'                  => (string)$g['ClaveRegimen'],
                    'CalificacionOperacion'         => (string)$g['CalificacionOperacion'],
                    'TipoImpositivo'                => (float)$g['TipoImpositivo'],
                    'BaseImponibleOimporteNoSujeto' => (float)$g['BaseImponibleOimporteNoSujeto'],
                    'CuotaRepercutida'              => (float)$g['CuotaRepercutida'],
                ];
            }, $detalleCalc);
        }

        // 2) Encadenamiento (mismo criterio que el payload real)
        $enc = ($prevHash === null || $prevHash === '')
            ? ['PrimerRegistro' => 'S']
            : [
                'RegistroAnterior' => [
                    'IDEmisorFactura'        => $issuerNif,
                    'NumSerieFactura'        => $numSerie,
                    'FechaExpedicionFactura' => $fechaAeat,
                    'Huella'                 => (string) $prevHash,
                ],
            ];

        // 3) SistemaInformatico (defaults seguros)
        $sif = VerifactuAeatPayloadBuilder::buildSistemaInformatico();

        // 4) Estructura de preview (idéntica al payload de ALTA)
        $payload = [
            'Cabecera' => [
                'ObligadoEmision' => [
                    'NombreRazon' => $issuerName,
                    'NIF'         => $issuerNif,
                ],
            ],
            'RegistroFactura' => [
                'RegistroAlta' => [
                    'IDVersion' => '1.0',
                    'IDFactura' => [
                        'IDEmisorFactura'        => $issuerNif,
                        'NumSerieFactura'        => $numSerie,
                        'FechaExpedicionFactura' => $fechaAeat,
                    ],
                    'NombreRazonEmisor'        => $issuerName,
                    'TipoFactura'              => (string)($row['invoice_type'] ?? 'F1'),
                    'DescripcionOperacion'     => (string)($row['description'] ?? 'Transferencia VTC'),
                    'Desglose' => [
                        'DetalleDesglose' => $detalle,
                    ],
                    'CuotaTotal'               => $this->nf($cuotaTotal),
                    'ImporteTotal'             => $this->nf($importeTotal),
                    'Encadenamiento'           => $enc,
                    'FechaHoraHusoGenRegistro' => $fechaHuso,
                    'TipoHuella'               => '01',
                    'Huella'                   => $hash,
                    'SistemaInformatico'       => $sif,
                ],
            ],
        ];

        // 5) Guardado
        $base = rtrim(WRITEPATH, '/')
            . '/verifactu/previews';
        @mkdir($base, 0775, true);
        $path = $base . '/' . $id . '-preview.xml';

        $xml = $this->arrayToPrettyXml('RegFactuSistemaFacturacion', $payload);
        file_put_contents($path, $xml);

        return $path;
    }

    /** number_format a 2 decimales con punto */
    private function nf(float $n): string
    {
        return number_format($n, 2, '.', '');
    }

    /** Render simple de array → XML legible (sin namespaces; solo preview) */
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
                $childName = is_int($k) ? 'item' : (string)$k;
                $node->appendChild($this->arrayToNode($dom, $childName, $v));
            }
        } else {
            $node->appendChild($dom->createTextNode((string)$value));
        }
        return $node;
    }
}
