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
     * - hash, datetime_offset (misma que se usó para calcular la huella)
     * - details_json/vat_total/gross_total  (preferente)
     *   o en su defecto lines_json (entonces se calcula desglose con el builder)
     * - prev_hash (opcional)
     */
    public function buildAndSavePreview(array $row): string
    {
        $id         = (int) $row['id'];
        $issuerNif  = (string) $row['issuer_nif'];
        $issuerName = (string) ($row['issuer_name']);
        $numSeries   = (string) (($row['series']) . ($row['number']));
        $issueDate  = (string) $row['issue_date']; // YYYY-MM-DD
        $dateAeat  = VerifactuFormatter::toAeatDate($issueDate); // dd-mm-YYYY
        $datetimeOffset  = (string) ($row['datetime_offset']);
        $hash       = (string) $row['hash'];
        $prevHash   = $row['prev_hash'] ?? null;

        $detail = null;
        $cuotaTotal = (float) ($row['vat_total'] ?? 0.0);
        $importeTotal = (float) ($row['gross_total'] ?? 0.0);
        /**
         * Si hay details_json, usarlo directamente (y los totales ya guardados).
         * Si no, calcular desde lines_json (solo para previsualización).
         */
        if (!empty($row['details_json'])) {
            $detail = json_decode((string) $row['details_json'], true) ?: [];
        } else {
            $lines = [];
            if (!empty($row['lines_json'])) {
                $lines = json_decode((string) $row['lines_json'], true) ?: [];
            }
            [$detailCalc, $cuotaTotal, $importeTotal] =
                (new VerifactuAeatPayloadBuilder())->buildBreakdownAndTotalsFromJson($lines);

            $detail = array_map(static function (array $g) {
                return [
                    'ClaveRegimen'                  => (string)$g['ClaveRegimen'],
                    'CalificacionOperacion'         => (string)$g['CalificacionOperacion'],
                    'TipoImpositivo'                => (float)$g['TipoImpositivo'],
                    'BaseImponibleOimporteNoSujeto' => (float)$g['BaseImponibleOimporteNoSujeto'],
                    'CuotaRepercutida'              => (float)$g['CuotaRepercutida'],
                ];
            }, $detailCalc);
        }

        $enc = ($prevHash === null || $prevHash === '')
            ? ['PrimerRegistro' => 'S']
            : [
                'RegistroAnterior' => [
                    'IDEmisorFactura'        => $issuerNif,
                    'NumSerieFactura'        => $numSeries,
                    'FechaExpedicionFactura' => $dateAeat,
                    'Huella'                 => (string) $prevHash,
                ],
            ];

        $sif = VerifactuAeatPayloadBuilder::buildSoftwareSystemBlock();

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
                        'NumSerieFactura'        => $numSeries,
                        'FechaExpedicionFactura' => $dateAeat,
                    ],
                    'NombreRazonEmisor'        => $issuerName,
                    'TipoFactura'              => (string)($row['invoice_type']),
                    'DescripcionOperacion'     => (string)($row['description']),
                    'Desglose' => [
                        'DetalleDesglose' => $detail,
                    ],
                    'CuotaTotal'               => VerifactuFormatter::fmt2($cuotaTotal),
                    'ImporteTotal'             => VerifactuFormatter::fmt2($importeTotal),
                    'Encadenamiento'           => $enc,
                    'FechaHoraHusoGenRegistro' => $datetimeOffset,
                    'TipoHuella'               => '01',
                    'Huella'                   => $hash,
                    'SistemaInformatico'       => $sif,
                ],
            ],
        ];

        $base = rtrim(WRITEPATH, '/')
            . '/verifactu/previews';
        @mkdir($base, 0775, true);
        $path = $base . '/' . $id . '-preview.xml';

        $xml = $this->arrayToPrettyXml('RegFactuSistemaFacturacion', $payload);
        file_put_contents($path, $xml);

        return $path;
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
