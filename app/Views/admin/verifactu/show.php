<?php

/**
 * @var array $row
 * @var array $paths
 * @var array $submissions
 */

use function esc;

?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>VERI*FACTU · Detalle #<?= esc($row['id']) ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 1.5rem;
            background: #f5f5f7;
            color: #222;
        }

        a {
            color: #2563eb;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        h1 {
            margin-bottom: 0.25rem;
        }

        .subtitle {
            color: #666;
            margin-bottom: 1rem;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(0, 1.5fr);
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .card {
            background: #fff;
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        .card h2 {
            font-size: 1rem;
            margin: 0 0 0.75rem;
        }

        dl {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.25rem 0.75rem;
            font-size: 0.85rem;
        }

        dt {
            color: #6b7280;
            text-align: right;
            white-space: nowrap;
        }

        dd {
            margin: 0;
        }

        .badge {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: .02em;
        }

        .badge.ready {
            background: #fbbf24;
            color: #78350f;
        }

        .badge.sent {
            background: #22c55e;
            color: #064e3b;
        }

        .badge.error {
            background: #f97373;
            color: #7f1d1d;
        }

        .badge.small {
            font-size: 0.65rem;
        }

        .file-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 0.85rem;
        }

        .file-list li {
            margin-bottom: 0.25rem;
        }

        .file-list span.status {
            display: inline-block;
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 999px;
            margin-right: 0.3rem;
            vertical-align: middle;
        }

        .file-list span.ok {
            background: #22c55e;
        }

        .file-list span.missing {
            background: #9ca3af;
        }

        .qr-box {
            text-align: center;
        }

        .qr-box img {
            max-width: 180px;
            border-radius: 0.5rem;
            background: #fff;
            padding: 0.5rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        th,
        td {
            padding: 0.4rem 0.5rem;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #f9fafb;
            font-weight: 600;
            color: #555;
        }

        .back {
            margin-bottom: 0.75rem;
        }
    </style>
</head>

<body>
    <div class="back">
        <a href="<?= site_url('admin/verifactu') ?>">← Volver al listado</a>
    </div>

    <h1>Factura / registro #<?= esc($row['id']) ?></h1>
    <div class="subtitle">
        <?= esc($row['series'] ?? '') ?>-<?= esc($row['number'] ?? '') ?>
        · Empresa <?= esc($row['company_id'] ?? '') ?>
        · NIF <?= esc($row['issuer_nif'] ?? '') ?>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Datos generales</h2>
            <?php
            $status = (string) ($row['status'] ?? '');
$statusClass = 'badge';
if ($status === 'ready') {
    $statusClass .= ' ready';
} elseif ($status === 'sent') {
    $statusClass .= ' sent';
} elseif ($status === 'error') {
    $statusClass .= ' error';
}
?>
            <dl>
                <dt>Status interno</dt>
                <dd>
                    <?php if ($status !== ''): ?>
                        <span class="<?= esc($statusClass) ?>"><?= esc($status) ?></span>
                    <?php endif; ?>
                </dd>

                <dt>Tipo / kind</dt>
                <dd><?= esc($row['kind'] ?? '') ?></dd>

                <dt>External ID</dt>
                <dd><?= esc($row['external_id'] ?? '') ?></dd>

                <dt>Fecha expedición</dt>
                <dd><?= esc($row['issue_date'] ?? '') ?> (<?= esc($row['datetime_offset'] ?? '') ?>)</dd>

                <dt>Importe bruto</dt>
                <dd><strong><?= esc(number_format((float) ($row['gross_total'] ?? 0), 2)) ?> €</strong></dd>

                <dt>IVA total</dt>
                <dd><?= esc(number_format((float) ($row['vat_total'] ?? 0), 2)) ?> €</dd>

                <dt>Huella (hash)</dt>
                <dd><code><?= esc($row['hash'] ?? '') ?></code></dd>

                <dt>Prev hash</dt>
                <dd><code><?= esc($row['prev_hash'] ?? '') ?></code></dd>

                <dt>Index cadena</dt>
                <dd><?= esc($row['chain_index'] ?? '') ?></dd>

                <dt>Original billing_hash_id</dt>
                <dd><?= esc($row['original_billing_hash_id'] ?? '') ?></dd>

                <dt>Created at</dt>
                <dd><?= esc($row['created_at'] ?? '') ?></dd>

                <dt>Updated at</dt>
                <dd><?= esc($row['updated_at'] ?? '') ?></dd>
            </dl>
        </div>

        <div class="card">
            <h2>Estados AEAT / QR / ficheros</h2>

            <dl>
                <dt>AEAT send_status</dt>
                <dd><span class="badge small"><?= esc($row['aeat_send_status'] ?? '') ?></span></dd>

                <dt>AEAT register_status</dt>
                <dd><span class="badge small"><?= esc($row['aeat_register_status'] ?? '') ?></span></dd>

                <dt>AEAT CSV</dt>
                <dd><code><?= esc($row['aeat_csv'] ?? '') ?></code></dd>

                <dt>AEAT error</dt>
                <dd>
                    <code><?= esc($row['aeat_error_code'] ?? '') ?></code>
                    <?= esc($row['aeat_error_message'] ?? '') ?>
                </dd>

                <dt>QR URL (AEAT)</dt>
                <dd style="word-break: break-all;">
                    <a href="<?= esc($row['qr_url'] ?? '') ?>" target="_blank" rel="noreferrer">
                        <?= esc($row['qr_url'] ?? '') ?>
                    </a>
                </dd>
            </dl>

            <div class="qr-box">
                <?php if (! empty($paths['qr'])): ?>
                    <p><strong>QR generado (PNG local)</strong></p>
                    <img src="<?= site_url('admin/verifactu/qr/' . (int) $row['id']) ?>" alt="QR factura">
                <?php else: ?>
                    <p><em>No hay PNG de QR generado todavía.</em></p>
                <?php endif; ?>
            </div>

            <div class="file-list">
                <h3>Artefactos técnicos</h3>
                <ul>
                    <li>
                        <span class="status <?= ! empty($paths['preview']) ? 'ok' : 'missing' ?>"></span>
                        XML previsualización
                        <?php if (! empty($paths['preview'])): ?>
                            – <a href="<?= site_url('admin/verifactu/file/' . (int) $row['id'] . '/preview') ?>">descargar</a>
                        <?php endif; ?>
                    </li>
                    <li>
                        <span class="status <?= ! empty($paths['request']) ? 'ok' : 'missing' ?>"></span>
                        SOAP request a AEAT
                        <?php if (! empty($paths['request'])): ?>
                            – <a href="<?= site_url('admin/verifactu/file/' . (int) $row['id'] . '/request') ?>">descargar</a>
                        <?php endif; ?>
                    </li>
                    <li>
                        <span class="status <?= ! empty($paths['response']) ? 'ok' : 'missing' ?>"></span>
                        SOAP response de AEAT
                        <?php if (! empty($paths['response'])): ?>
                            – <a href="<?= site_url('admin/verifactu/file/' . (int) $row['id'] . '/response') ?>">descargar</a>
                        <?php endif; ?>
                    </li>
                    <li>
                        <span class="status <?= ! empty($paths['pdf']) ? 'ok' : 'missing' ?>"></span>
                        PDF generado
                        <?php if (! empty($paths['pdf'])): ?>
                            – <a href="<?= site_url('admin/verifactu/file/' . (int) $row['id'] . '/pdf') ?>">descargar</a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Histórico de envíos (tabla <code>submissions</code>)</h2>
        <?php if (! $submissions): ?>
            <p>No hay submissions registrados todavía para este billing_hash.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th>Intento</th>
                        <th>Error AEAT</th>
                        <th>Request/Response ref</th>
                        <th>Fechas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $s): ?>
                        <tr>
                            <td><?= esc($s['id'] ?? '') ?></td>
                            <td><?= esc($s['type'] ?? '') ?></td>
                            <td><?= esc($s['status'] ?? '') ?></td>
                            <td><?= esc($s['attempt_number'] ?? '') ?></td>
                            <td>
                                <code><?= esc($s['error_code'] ?? '') ?></code>
                                <?= esc($s['error_message'] ?? '') ?>
                            </td>
                            <td>
                                <small>
                                    req: <?= esc($s['request_ref'] ?? '') ?><br>
                                    res: <?= esc($s['response_ref'] ?? '') ?>
                                </small>
                            </td>
                            <td>
                                <small>
                                    created: <?= esc($s['created_at'] ?? '') ?><br>
                                    updated: <?= esc($s['updated_at'] ?? '') ?>
                                </small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>

</html>