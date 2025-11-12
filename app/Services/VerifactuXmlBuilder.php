<?php

declare(strict_types=1);

namespace App\Services;

final class VerifactuXmlBuilder
{
    /**
     * Genera un XML de previsualización básico con los campos clave.
     * Guarda el archivo en writable/verifactu/xml/{id}.xml y devuelve la ruta absoluta.
     *
     * @param int   $id            ID local del documento (billing_hashes.id)
     * @param array $previewData   Datos para el XML (emisor, numSerie, fecha, totales, hash, prevHash, chainIndex)
     */
    public function buildAndSavePreview(int $id, array $previewData): string
    {
        $dir = WRITEPATH . 'verifactu/xml';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Montamos un XML simple y legible (luego lo reemplazaremos por el oficial)
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('RegistroFacturaPreview');
        $doc->appendChild($root);

        $alta = $doc->createElement('RegistroAlta');
        $root->appendChild($alta);

        $idFactura = $doc->createElement('IDFactura');
        $alta->appendChild($idFactura);

        $idFactura->appendChild($doc->createElement('IDEmisorFactura', (string)($previewData['issuer_nif'] ?? '')));
        $idFactura->appendChild($doc->createElement('NumSerieFactura', (string)($previewData['num_serie_factura'] ?? '')));
        $idFactura->appendChild($doc->createElement('FechaExpedicionFactura', (string)($previewData['fecha_aeat'] ?? '')));

        $alta->appendChild($doc->createElement('TipoFactura', (string)($previewData['tipo_factura'] ?? 'F1')));

        $totales = $doc->createElement('Totales');
        $totales->appendChild($doc->createElement('CuotaTotal', (string)($previewData['cuota_total'] ?? '0')));
        $totales->appendChild($doc->createElement('ImporteTotal', (string)($previewData['importe_total'] ?? '0')));
        $alta->appendChild($totales);

        $enc = $doc->createElement('Encadenamiento');
        $enc->appendChild($doc->createElement('ChainIndex', (string)($previewData['chain_index'] ?? '1')));
        $enc->appendChild($doc->createElement('PrevHash', (string)($previewData['prev_hash'] ?? '')));
        $alta->appendChild($enc);

        $alta->appendChild($doc->createElement('TipoHuella', '01'));
        $alta->appendChild($doc->createElement('Huella', (string)($previewData['hash'] ?? '')));

        $sif = $doc->createElement('SistemaInformatico');
        $sif->appendChild($doc->createElement('NombreSistemaInformatico', 'VERI*FACTU Middleware'));
        $sif->appendChild($doc->createElement('Version', '1.0.0'));
        $alta->appendChild($sif);

        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $id . '.xml';
        $doc->save($path);

        return $path;
    }
}
