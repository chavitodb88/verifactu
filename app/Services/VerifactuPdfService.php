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
    public function buildPdf(array $invoice): string
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
        $lines = json_decode($invoice['lines_json'] ?? '[]', true) ?: [];

        $raw = json_decode($invoice['raw_payload_json'] ?? '[]', true) ?: [];
        $invoiceType = $raw['invoiceType'] ?? 'F1';

        $isTicket = ($invoiceType) === 'F2';
        $view = $isTicket
            ? 'pdfs/verifactu_ticket'
            : 'pdfs/verifactu_invoice';

        $viewData = $this->buildViewDataForInvoicePdf(
            $invoice,
            $lines,
            $detail,
            $qrData,
            $invoiceType
        );

        $html = view($view, $viewData);

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
        $base = WRITEPATH . 'verifactu/pdfs';
        @mkdir($base, 0775, true);
        $pdfPath = $base . '/' . $id . '.pdf';

        file_put_contents($pdfPath, $output);

        $model = new BillingHashModel();
        $model->update($id, ['pdf_path' => $pdfPath]);

        return $pdfPath;
    }

    /**
     * Prepara los datos listos para la vista del PDF de factura.
     *
     * @param array $invoice
     * @param array $company
     * @param array $lines
     * @param array $detail
     * @param string|null $qrData
     * @param string $invoiceType
     *
     * @return array
     */
    private function buildViewDataForInvoicePdf(
        array $invoice,
        array $lines,
        array $detail,
        ?string $qrData,
        string $invoiceType
    ): array {
        // issue_date → DD/MM/YYYY
        $date = $invoice['issue_date'] ?? '';
        $dateFormatted = $date;
        if ($date && strpos($date, '-') !== false) {
            [$y, $m, $d] = explode('-', $date);
            $dateFormatted = sprintf('%02d/%02d/%04d', (int) $d, (int) $m, (int) $y);
        }

        $numberFormatted = trim(($invoice['series'] ?? '') . ($invoice['number'] ?? ''));

        // Tipo de documento
        $kind = $invoice['kind'] ?? 'alta';
        if ($kind === 'anulacion') {
            $docLabel = 'ANULACIÓN VERI*FACTU';
        } elseif (strtoupper((string) $invoiceType)[0] === 'R') {
            $docLabel = 'FACTURA RECTIFICATIVA';
        } else {
            $docLabel = 'FACTURA';
        }

        // Info de rectificación (si la hay)
        $rectifiedMeta = [];
        if (!empty($invoice['rectified_meta_json'])) {
            $rectifiedMeta = json_decode((string) $invoice['rectified_meta_json'], true) ?: [];
        }

        $orig = $rectifiedMeta['original'] ?? [];
        $origSeries = $orig['series'] ?? '';
        $origNumber = $orig['number'] ?? '';
        $origDate = $orig['issueDate'] ?? '';
        $mode = $rectifiedMeta['mode'] ?? null;
        $hasRectif = $origSeries || $origNumber || $origDate;

        return [
            'invoice'     => $invoice,
            'qrData'      => $qrData,
            'detail'      => $detail,
            'lines'       => $lines,
            'invoiceType' => $invoiceType,

            'dateFormatted'   => $dateFormatted,
            'numberFormatted' => $numberFormatted,
            'docLabel'        => $docLabel,

            'companyDisplay' => [
                'name'     => $invoice['issuer_name'] ?? '',
                'nif'      => $invoice['issuer_nif'] ?? '',
                'address'  => $invoice['issuer_address'] ?? '',
                'postal'   => $invoice['issuer_postal_code'] ?? '',
                'city'     => $invoice['issuer_city'] ?? '',
                'province' => $invoice['issuer_province'] ?? '',
            ],

            'rectification' => [
                'has'    => $hasRectif,
                'series' => $origSeries,
                'number' => $origNumber,
                'date'   => $origDate,
                'mode'   => $mode,
            ],
        ];
    }
}
