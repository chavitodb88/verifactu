<?php

declare(strict_types=1);

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

final class VerifactuQrService
{
    private bool $isTest;

    public function __construct(?bool $isTest = null)
    {
        // Igual que en VerifactuSoapClient
        $this->isTest = $isTest ?? (strtolower((string) getenv('verifactu.isTest')) !== 'false');
    }
    /**
     * Genera (o regenera) el PNG del QR para una factura
     * y devuelve la ruta absoluta al fichero.
     *
     * @param array $row Fila de billing_hashes (id, csv_aeat, hash, etc.)
     */
    public function buildForInvoice(array $row): string
    {
        $data = $this->buildUrlData($row);

        $result = (new Builder(
            writer: new PngWriter(),
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10
        ))->build();

        $base = WRITEPATH . 'verifactu/qr';
        @mkdir($base, 0775, true);

        $path = $base . '/' . (int)$row['id'] . '.png';
        $result->saveToFile($path);

        return $path;
    }

    /**
     * Construye la URL oficial de validación de QR de la AEAT
     * a partir del registro de billing_hashes.
     */
    private function buildUrlData(array $row): string
    {
        $config = config('Verifactu');
        $base = $this->isTest
            ? $config->qrBaseUrlTest
            : $config->qrBaseUrlProd;

        // NIF emisor
        $nif = (string) $row['issuer_nif'];

        // Numserie = serie+numero (mismo criterio que para la huella)
        $numSerie = (string) ($row['series'] . $row['number']);

        // Importe total con IVA, 2 decimales, punto como separador
        $importe = number_format((float) ($row['gross_total'] ?? 0), 2, '.', '');

        // Fecha en formato AEAT dd-mm-YYYY
        $fecha = \App\Helpers\VerifactuFormatter::toAeatDate(
            (string) $row['issue_date']
        );

        $params = [
            'nif'      => $nif,
            'numserie' => $numSerie,
            'importe'  => $importe,
            'fecha'    => $fecha,
            // si quisieras debug en navegador:
            // 'formato' => 'json',
        ];

        return $base . 'wlpl/TIKE-CONT/ValidarQR?' . http_build_query($params);
    }
}
