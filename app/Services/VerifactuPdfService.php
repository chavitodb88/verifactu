<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BillingHashModel;
use Dompdf\Dompdf;
use Dompdf\Options;

final class VerifactuPdfService
{
    public function buildPdf(array $invoice, array $company): string
    {
        $id = (int) $invoice['id'];

        // 0) Si ya tenemos pdf_path y el fichero existe, reutilizar
        if (!empty($invoice['pdf_path']) && is_file($invoice['pdf_path'])) {
            return $invoice['pdf_path'];
        }

        // 1) QR en base64 (si existe el PNG generado previamente)
        $qrData = null;
        $qrFile = WRITEPATH . 'verifactu/qr/' . $id . '.png';
        if (is_file($qrFile)) {
            $qrData = 'data:image/png;base64,' . base64_encode(file_get_contents($qrFile));
        }

        // 2) Detalle y líneas
        $detalle = json_decode($invoice['detalle_json'] ?? '[]', true) ?: [];
        $lines   = json_decode($invoice['lines_json'] ?? '[]', true) ?: [];

        // 3) Renderizar vista HTML
        $html = view('pdfs/verifactu_invoice', [
            'invoice' => $invoice,
            'company' => $company,
            'qrData'  => $qrData,
            'detalle' => $detalle,
            'lines'   => $lines,
        ]);

        // 4) Dompdf
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Numeración de páginas
        $font = $dompdf->getFontMetrics()->get_font('helvetica', 'normal');
        $dompdf->getCanvas()->page_text(
            30,
            820,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $font,
            10,
            [136, 136, 136]
        );

        // 5) Guardar en disco
        $output = $dompdf->output();
        $base   = WRITEPATH . 'verifactu/pdfs';
        @mkdir($base, 0775, true);
        $pdfPath = $base . '/' . $id . '.pdf';

        file_put_contents($pdfPath, $output);

        // 6) Actualizar billing_hashes.pdf_path
        $model = new BillingHashModel();
        $model->update($id, ['pdf_path' => $pdfPath]);

        return $pdfPath;
    }
}
