<?php

use App\Helpers\HumanFormatter;

/** @var array $invoice */
/** @var array $company */
/** @var array $companyDisplay */
/** @var array $rectification */
/** @var string $dateFormatted */
/** @var string $numberFormatted */
/** @var string $docLabel */

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>
        Factura <?php echo esc($numberFormatted) ?>
    </title>
    <style>
        @page {
            margin: 120px 30px 100px 30px;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding-top: 100px;
        }

        header {
            position: fixed;
            top: -100px;
            left: 0;
            right: 0;
            height: 100px;
        }

        footer {
            position: fixed;
            bottom: -60px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            color: #888;
        }

        .data-protection {
            margin: 0;
            padding: 0;
            text-align: left;
            font-size: 8px;
            line-height: 1.2;
        }

        .pagenum::before {
            content: "Página " counter(page);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .company,
        .client,
        .qr {
            width: 33%;
            display: inline-block;
            vertical-align: top;
        }

        .client {
            text-align: right;
        }

        .qr {
            text-align: center;
        }

        .qr-title,
        .qr-footer {
            font-size: 11px;
            margin: 0;
        }

        .qr-title {
            margin-bottom: 5px;
            font-weight: bold;
        }

        .qr-footer {
            margin-top: 5px;
        }

        /* CSV AEAT bajo el QR */
        .csv-label {
            margin: 6px 0 0 0;
            font-size: 9px;
            font-weight: bold;
        }

        .csv-value {
            margin: 2px 0 0 0;
            font-size: 8px;
            word-break: break-all;
        }

        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .invoice-table th {
            background-color: #333;
            color: #fff;
            padding: 8px;
            text-align: center;
        }

        .invoice-table td {
            border: 1px solid #ddd;
            padding: 8px 8px 5px 8px;
            vertical-align: top;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .concept-cell {
            white-space: pre-line;
            word-break: break-word;
        }

        .total-box {
            margin-top: 20px;
        }

        .totals-table {
            width: 50%;
            float: right;
            border-collapse: collapse;
        }

        .totals-table th,
        .totals-table td {
            border: 1px solid #ccc;
            padding: 6px;
            font-size: 12px;
        }

        .totals-table th {
            background-color: #333;
            color: #fff;
        }

        .total-row {
            text-align: right;
            font-weight: bold;
        }

        .invoice-summary {
            font-size: 12px;
            width: 170px;
            margin-bottom: 10px;
            margin-left: auto;
        }

        .invoice-title {
            background-color: #333;
            color: white;
            text-align: center;
            font-weight: bold;
            font-size: 16px;
            padding: 6px 0;
        }

        .meta-label {
            font-weight: bold;
            font-size: 10px;
            color: #333;
        }

        .meta-table {
            width: 100%;
            font-size: 10px;
            background-color: #e5e5e5;
            border-collapse: collapse;
        }

        .meta-table td {
            text-align: center;
            padding: 4px;
        }

        .qr img {
            width: 40mm;
            height: 40mm;
        }
    </style>
</head>

<body>
    <header>
        <div class="header-content">
            <div class="company">
                <h2><?= esc($companyDisplay['name']) ?></h2>
                <p>
                    <?= esc($companyDisplay['nif']) ?><br>
                    <?= esc($companyDisplay['address']) ?><br>
                    <?= esc($companyDisplay['postal']) ?>
                    <?= esc($companyDisplay['city']) ?>
                    <?= $companyDisplay['province']
                        ? '(' . esc($companyDisplay['province']) . ')'
                        : '' ?>
                </p>
            </div>


            <?php if (! empty($qrData)): ?>
                <div class="qr">
                    <p class="qr-title">QR tributario</p>
                    <img src="<?php echo $qrData ?>" alt="QR Verifactu" />
                    <p class="qr-footer">VERI*FACTU</p>

                    <?php if (! empty($invoice['aeat_csv'])): ?>
                        <p class="csv-label">CSV AEAT</p>
                        <p class="csv-value"><?php echo esc($invoice['aeat_csv']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="client">
                <div class="invoice-summary">
                    <div class="invoice-title"><?= esc($docLabel) ?></div>
                    <table class="meta-table">
                        <tr>
                            <td>
                                <div class="meta-label">FECHA</div>
                                <div><?= esc($dateFormatted) ?></div>
                            </td>
                            <td>
                                <div class="meta-label">NÚMERO</div>
                                <div><?= esc($numberFormatted) ?></div>
                            </td>
                        </tr>
                    </table>

                    <?php if ($rectification['has']): ?>
                        <div style="margin-top: 8px; font-size: 9px; text-align: left;">
                            <strong>Rectifica a:</strong>
                            <?= esc(trim($rectification['series'] . ' ' . $rectification['number'])) ?>
                            <?= $rectification['date']
                                ? ' — ' . esc($rectification['date'])
                                : '' ?>
                            <?php if ($rectification['mode']): ?>
                                <br><span>Modo de rectificación: <?= esc(strtoupper((string)$rectification['mode'])) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <h3>Cliente</h3>
                <p>
                    <?php echo esc($invoice['client_name'] ?? 'Cliente VERI*FACTU') ?><br>
                    <?php echo esc($invoice['client_address'] ?? '') ?><br>
                    <?php echo esc($invoice['client_postal_code'] ?? '') ?>
                    <?php echo esc($invoice['client_city'] ?? '') ?>
                    <?php echo ! empty($invoice['client_province']) ? '(' . esc($invoice['client_province']) . ')' : '' ?><br>
                    <?php echo esc($invoice['client_document'] ?? '') ?>
                </p>
            </div>
        </div>
    </header>

    <footer>
        <!-- Si en el futuro quieres meter cláusula LOPD por empresa, puedes añadirla aquí -->
        <span class="pagenum" style="float: right;"></span>
    </footer>

    <main>
        <table class="invoice-table">
            <thead>
                <tr>
                    <th style="width: 6%;">Cant.</th>
                    <th style="width: 58%;">Concepto</th>
                    <th style="width: 12%;">Precio</th>
                    <th style="width: 8%;">IVA</th>
                    <th style="width: 16%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $bases = [];
                $ivas = [];
                $totalGeneral = 0.0;

                foreach ($lines as $line) {
                    $qty = (float) ($line['qty'] ?? 0);
                    $price = (float) ($line['price'] ?? 0);
                    $vat = (float) ($line['vat'] ?? 0);
                    $desc = (string) ($line['desc'] ?? '');

                    $amount = $price * $qty;
                    $ivaAmount = $amount * $vat / 100;
                    $totalLine = $amount + $ivaAmount;

                    $bases[$vat] = ($bases[$vat] ?? 0) + $amount;
                    $ivas[$vat] = ($ivas[$vat] ?? 0) + $ivaAmount;
                    $totalGeneral += $totalLine;
                ?>
                    <tr>
                        <td class="center"><?php echo esc($qty) ?></td>
                        <td class="concept-cell"><?php echo nl2br(esc($desc)) ?></td>
                        <td class="right"><?php echo HumanFormatter::money($price) ?> €</td>
                        <td class="center"><?php echo esc($vat) ?>%</td>
                        <td class="right"><?php echo HumanFormatter::money($totalLine) ?> €</td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>

        <div class="total-box">
            <table class="totals-table">
                <thead>
                    <tr>
                        <th>Base imponible</th>
                        <th>IVA %</th>
                        <th>IVA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bases as $vat => $base):
                        $ivaImporte = $ivas[$vat] ?? 0;
                    ?>
                        <tr>
                            <td class="right"><?php echo HumanFormatter::money($base) ?> €</td>
                            <td class="center"><?php echo esc($vat) ?>%</td>
                            <td class="right"><?php echo HumanFormatter::money($ivaImporte) ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td colspan="2" class="total-row">TOTAL</td>
                        <td class="right total-row"><?php echo HumanFormatter::money($totalGeneral) ?> €</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </main>

</body>

</html>