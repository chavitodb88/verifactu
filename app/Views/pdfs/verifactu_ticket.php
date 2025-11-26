<?php

use App\Helpers\HumanFormatter;

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Ticket <?= esc(($invoice['series'] ?? '') . ($invoice['number'] ?? '')) ?></title>
    <style>
        @page {
            size: 80mm auto;
            margin: 5mm 3mm;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 9px;
            width: 80mm;
            margin: 0;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .header {
            margin-bottom: 4mm;
        }

        .lines-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2mm;
        }

        .lines-table th,
        .lines-table td {
            padding: 1mm 0;
        }

        .totals {
            margin-top: 3mm;
            border-top: 1px dashed #000;
            padding-top: 2mm;
        }

        .qr {
            margin-top: 4mm;
            text-align: center;
        }

        .qr img {
            width: 25mm;
            height: 25mm;
        }
    </style>
</head>

<body>
    <?php
    $date = $invoice['issue_date'] ?? '';
if ($date && strpos($date, '-') !== false) {
    [$y, $m, $d] = explode('-', $date);
    $dateFormatted = $d . '/' . $m . '/' . $y;
} else {
    $dateFormatted = $date;
}

$numberFormatted = trim(($invoice['series'] ?? '') . ($invoice['number'] ?? ''));
?>

    <div class="header center">
        <strong><?= esc($company['name'] ?? 'Empresa') ?></strong><br>
        <?= esc($company['nif'] ?? '') ?><br>
        <?= esc($company['address'] ?? '') ?><br>
        <?= esc(($company['postal_code'] ?? '') . ' ' . ($company['city'] ?? '')) ?>
    </div>

    <div class="center">
        <strong>FACTURA SIMPLIFICADA</strong><br>
        Nº <?= esc($numberFormatted) ?> — <?= esc($dateFormatted) ?>
    </div>

    <table class="lines-table">
        <thead>
            <tr>
                <th>Desc</th>
                <th class="right">Imp.</th>
            </tr>
        </thead>
        <tbody>
            <?php
        $totalGross = 0.0;
foreach ($lines as $line):
    $qty = (float)($line['qty'] ?? 0);
    $price = (float)($line['price'] ?? 0);  // base sin IVA
    $vat = (float)($line['vat'] ?? 0);
    $desc = (string)($line['desc'] ?? '');

    $base = $qty * $price;
    $vatAmt = $base * $vat / 100;
    $lineGross = $base + $vatAmt;
    $totalGross += $lineGross;
    ?>
                <tr>
                    <td><?= esc($desc) ?> x<?= $qty ?> (<?= $vat ?>%)</td>
                    <td class="right"><?= HumanFormatter::money($lineGross) ?> €</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <table style="width: 100%;">
            <tr>
                <td class="right"><strong>Total</strong></td>
                <td class="right"><strong><?= HumanFormatter::money($totalGross) ?> €</strong></td>
            </tr>
        </table>
    </div>

    <?php if (!empty($qrData)): ?>
        <div class="qr">
            <div>QR VERI*FACTU</div>
            <img src="<?= $qrData ?>" alt="QR Verifactu">
        </div>
    <?php endif; ?>
</body>

</html>