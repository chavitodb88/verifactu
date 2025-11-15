<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BillingHashModel;
use Dompdf\Dompdf;
use Dompdf\Options;

final class VerifactuPdfService
{
    /**
     * Genera el PDF de la factura o ticket.
     * 
     * @param array $invoice Datos de la factura
     * @param array $company Datos de la empresa emisora
     * 
     * @throws \Exception
     * 
     * @return string Ruta al fichero PDF generado
     */
    public function buildPdf(array $invoice, array $company): string
    {
        $id = (int) $invoice['id'];

        if (!empty($invoice['pdf_path']) && is_file($invoice['pdf_path'])) {
            return $invoice['pdf_path'];
        }

        $qrData = null;
        $qrFile = WRITEPATH . 'verifactu/qr/' . $id . '.png';

        if (!is_file($qrFile)) {
            $qrPath = service('verifactuQr')->buildForInvoice($invoice);
            $qrFile = $qrPath;
        }

        if (is_file($qrFile)) {
            $qrData = 'data:image/png;base64,' . base64_encode(file_get_contents($qrFile));
        }

        $detail = json_decode($invoice['details_json'] ?? '[]', true) ?: [];
        $lines   = json_decode($invoice['lines_json'] ?? '[]', true) ?: [];

        $raw = json_decode($invoice['raw_payload_json'] ?? '[]', true) ?: [];
        $invoiceType = $raw['invoiceType'] ?? 'F1';

        $isTicket = ($invoiceType) === 'F2';
        $view = $isTicket
            ? 'pdfs/verifactu_ticket'
            : 'pdfs/verifactu_invoice';

        $html = view($view, [
            'invoice' => $invoice,
            'company' => $company,
            'qrData'  => $qrData,
            'detail' => $detail,
            'lines'   => $lines,
            'invoiceType'  => $invoiceType,
        ]);

        // 4) Dompdf
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();


        $font = $dompdf->getFontMetrics()->getFont('helvetica', 'normal');
        $dompdf->getCanvas()->page_text(
            30,
            820,
            'Página {PAGE_NUM} de {PAGE_COUNT}', // cspell:words Página
            $font,
            10,
            [136, 136, 136]
        );

        $output = $dompdf->output();
        $base   = WRITEPATH . 'verifactu/pdfs';
        @mkdir($base, 0775, true);
        $pdfPath = $base . '/' . $id . '.pdf';

        file_put_contents($pdfPath, $output);

        $model = new BillingHashModel();
        $model->update($id, ['pdf_path' => $pdfPath]);

        return $pdfPath;
    }
}
