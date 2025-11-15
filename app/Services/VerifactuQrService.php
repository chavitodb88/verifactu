<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\VerifactuFormatter;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

final class VerifactuQrService
{
    private bool $isTest;

    public function __construct(?bool $isTest = null)
    {
        $this->isTest = $isTest ?? (strtolower((string) getenv('verifactu.isTest')) !== 'false');
    }

    /**
     * Genera (o regenera) el PNG del QR para una factura
     * y devuelve la ruta absoluta al fichero.
     *
     * @param array $row Fila de billing_hashes (id, csv_aeat, hash, etc.)
     * @return string Ruta absoluta al fichero PNG generado
     * @throws \Endroid\QrCode\Exception\WriterException
     * @throws \Endroid\QrCode\Exception\EncodingException
     * @throws \Endroid\QrCode\Exception\ImageFunctionException
     * @throws \Endroid\QrCode\Exception\InvalidPathException
     * 
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
     * @param array $row Fila de billing_hashes (id, csv_aeat, hash, etc.)
     * @return string URL completa con parámetros
     * Ejemplo:
     * https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=XXXXXXXXX&numserie=XXXXXX&importe=XXXXXX&fecha=DD-MM-YYYY 
     */
    private function buildUrlData(array $row): string
    {
        $config = config('Verifactu');
        $base = $this->isTest
            ? $config->qrBaseUrlTest
            : $config->qrBaseUrlProd;

        $nif = (string) $row['issuer_nif'];

        $numSeries = (string) ($row['series'] . $row['number']);

        $total = VerifactuFormatter::fmt2((float) ($row['gross_total'] ?? 0));

        $date = \App\Helpers\VerifactuFormatter::toAeatDate(
            (string) $row['issue_date']
        );

        // cspell:disable
        $params = [
            'nif'      => $nif,
            'numserie' => $numSeries,
            'importe'  => $total,
            'fecha'    => $date,
            // 'formato' => 'json',
        ];

        return $base . 'wlpl/TIKE-CONT/ValidarQR?' . http_build_query($params);
    }
}
