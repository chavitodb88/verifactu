<?php

/**
 * @var array $rows
 * @var array $filters
 * @var array $statusCounts
 * @var array $filesById
 * @var array $statusOptions
 * @var int   $perPage
 * @var CodeIgniter\Pager\Pager $pager
 */

use function esc;

?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>VERI*FACTU · Dashboard</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 1.5rem;
            background: #f5f5f7;
            color: #222;
        }

        h1 {
            margin-bottom: 0.25rem;
        }

        .subtitle {
            color: #666;
            margin-bottom: 1.5rem;
        }

        .cards {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .card {
            background: #fff;
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        .card.small {
            min-width: 160px;
        }

        .card h2 {
            font-size: 1rem;
            margin: 0 0 0.25rem;
        }

        .card .big {
            font-size: 1.6rem;
            font-weight: 600;
        }

        form.filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            align-items: end;
        }

        form.filters label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #555;
            display: block;
            margin-bottom: 0.25rem;
        }

        form.filters input,
        form.filters select {
            width: 100%;
            padding: 0.35rem 0.5rem;
            border-radius: 0.375rem;
            border: 1px solid #ccc;
            font-size: 0.85rem;
        }

        form.filters .buttons {
            display: flex;
            gap: 0.5rem;
        }

        button,
        .btn {
            border-radius: 999px;
            padding: 0.4rem 0.9rem;
            border: none;
            background: #2563eb;
            color: #fff;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn.secondary {
            background: #e5e7eb;
            color: #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }

        thead {
            background: #f9fafb;
        }

        th,
        td {
            padding: 0.55rem 0.75rem;
            font-size: 0.8rem;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        th {
            text-align: left;
            font-weight: 600;
            color: #555;
            white-space: nowrap;
        }

        tbody tr:hover {
            background: #f3f4ff;
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

        .dot {
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.25rem;
        }

        .dot.ok {
            background: #22c55e;
        }

        .dot.missing {
            background: #9ca3af;
        }

        .files-cell {
            white-space: nowrap;
        }

        .files-cell a {
            font-size: 0.75rem;
            margin-right: 0.25rem;
        }

        .pager {
            margin-top: 0.75rem;
            font-size: 0.8rem;
        }

        .pager ul {
            list-style: none;
            padding: 0;
            margin: 0.5rem 0 0;
            display: flex;
            gap: 0.35rem;
        }

        .pager li {
            margin: 0;
        }

        .pager a,
        .pager span {
            display: inline-block;
            min-width: 1.8rem;
            text-align: center;
            padding: 0.25rem 0.45rem;
            border-radius: 999px;
            border: 1px solid #d1d5db;
            font-size: 0.75rem;
            text-decoration: none;
            color: #374151;
        }

        .pager a:hover {
            background: #e5e7eb;
        }

        .pager .active a,
        .pager .active span {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }
    </style>
</head>

<body>
    <h1>VERI*FACTU · Dashboard técnico</h1>
    <div class="subtitle">
        Vista rápida de lo que se ha generado, enviado y aceptado por AEAT (tabla <code>billing_hashes</code> + artefactos en <code>/writable/verifactu</code>).
    </div>

    <div class="cards">
        <div class="card small">
            <h2>Total registros (filtrados)</h2>
            <div class="big"><?= esc($totalRegistros) ?></div>
        </div>

        <div class="card small">
            <h2>ready (en cola)</h2>
            <div class="big"><?= esc($statusCounts['ready'] ?? 0) ?></div>
        </div>

        <div class="card small">
            <h2>sent (enviado)</h2>
            <div class="big"><?= esc($statusCounts['sent'] ?? 0) ?></div>
        </div>

        <div class="card small">
            <h2>error</h2>
            <div class="big"><?= esc($statusCounts['error'] ?? 0) ?></div>
        </div>

    </div>

    <form method="get" class="filters">
        <div>
            <label for="company_id">Company ID</label>
            <input type="number" name="company_id" id="company_id" value="<?= esc($filters['company_id']) ?>">
        </div>
        <div>
            <label for="issuer_nif">NIF emisor</label>
            <input type="text" name="issuer_nif" id="issuer_nif" value="<?= esc($filters['issuer_nif']) ?>">
        </div>
        <div>
            <label for="series">Serie</label>
            <input type="text" name="series" id="series" value="<?= esc($filters['series']) ?>">
        </div>
        <div>
            <label for="aeat_send_status">AEAT send_status</label>
            <input type="text" name="aeat_send_status" id="aeat_send_status" placeholder="Correcto / Incorrecto…" value="<?= esc($filters['aeat_send_status']) ?>">
        </div>
        <div>
            <label for="aeat_register_status">AEAT register_status</label>
            <input type="text" name="aeat_register_status" id="aeat_register_status" placeholder="Correcto / AceptadoConErrores…" value="<?= esc($filters['aeat_register_status']) ?>">
        </div>
        <div>
            <label for="date_from">Fecha desde (issue_date)</label>
            <input type="date" name="date_from" id="date_from" value="<?= esc($filters['date_from']) ?>">
        </div>
        <div>
            <label for="date_to">Fecha hasta (issue_date)</label>
            <input type="date" name="date_to" id="date_to" value="<?= esc($filters['date_to']) ?>">
        </div>
        <div>
            <label for="per_page">Por página</label>
            <input type="number" name="per_page" id="per_page" value="<?= esc($perPage) ?>">
        </div>
        <div class="buttons">
            <button type="submit">Filtrar</button>
            <a class="btn secondary" href="<?= current_url() ?>">Limpiar</a>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Empresa</th>
                <th>Nº factura</th>
                <th>Fecha</th>
                <th>Importe</th>
                <th>Status</th>
                <th>AEAT send</th>
                <th>AEAT reg</th>
                <th>AEAT CSV</th>
                <th>Artefactos</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (! $rows): ?>
                <tr>
                    <td colspan="11">No hay registros que coincidan con el filtro actual.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id         = (int) $row['id'];
                    $files      = $filesById[$id] ?? [];
                    $status     = (string) ($row['status'] ?? '');
                    $badgeClass = 'badge';
                    if ($status === 'ready') {
                        $badgeClass .= ' ready';
                    } elseif ($status === 'sent') {
                        $badgeClass .= ' sent';
                    } elseif ($status === 'error') {
                        $badgeClass .= ' error';
                    }
                    ?>
                    <tr>
                        <td><?= esc($id) ?></td>
                        <td><?= esc($row['company_id'] ?? '') ?></td>
                        <td>
                            <?= esc($row['series'] ?? '') ?>-<?= esc($row['number'] ?? '') ?><br>
                            <small><?= esc($row['external_id'] ?? '') ?></small>
                        </td>
                        <td>
                            <?= esc($row['issue_date'] ?? '') ?><br>
                            <small><?= esc($row['datetime_offset'] ?? '') ?></small>
                        </td>
                        <td>
                            <strong><?= esc(number_format((float) ($row['gross_total'] ?? 0), 2)) ?> €</strong><br>
                            <small>IVA: <?= esc(number_format((float) ($row['vat_total'] ?? 0), 2)) ?> €</small>
                        </td>
                        <td>
                            <?php if ($status !== ''): ?>
                                <span class="<?= esc($badgeClass) ?>"><?= esc($status) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge small"><?= esc($row['aeat_send_status'] ?? '') ?></span>
                        </td>
                        <td>
                            <span class="badge small"><?= esc($row['aeat_register_status'] ?? '') ?></span>
                        </td>
                        <td>
                            <code><?= esc($row['aeat_csv'] ?? '') ?></code>
                        </td>
                        <td class="files-cell">
                            <span class="dot <?= isset($files['preview']) && $files['preview'] ? 'ok' : 'missing' ?>" title="XML preview"></span>
                            <a href="<?= site_url('admin/verifactu/file/' . $id . '/preview') ?>" title="Descargar preview" <?= empty($files['preview']) ? ' style="opacity:.3;pointer-events:none;"' : '' ?>>prev</a>

                            <span class="dot <?= isset($files['request']) && $files['request'] ? 'ok' : 'missing' ?>" title="SOAP request"></span>
                            <a href="<?= site_url('admin/verifactu/file/' . $id . '/request') ?>" title="Request AEAT" <?= empty($files['request']) ? ' style="opacity:.3;pointer-events:none;"' : '' ?>>req</a>

                            <span class="dot <?= isset($files['response']) && $files['response'] ? 'ok' : 'missing' ?>" title="SOAP response"></span>
                            <a href="<?= site_url('admin/verifactu/file/' . $id . '/response') ?>" title="Response AEAT" <?= empty($files['response']) ? ' style="opacity:.3;pointer-events:none;"' : '' ?>>res</a>

                            <span class="dot <?= isset($files['pdf']) && $files['pdf'] ? 'ok' : 'missing' ?>" title="PDF"></span>
                            <a href="<?= site_url('admin/verifactu/file/' . $id . '/pdf') ?>" title="PDF" <?= empty($files['pdf']) ? ' style="opacity:.3;pointer-events:none;"' : '' ?>>pdf</a>
                        </td>
                        <td>
                            <a class="btn secondary" href="<?= site_url('admin/verifactu/' . $id) ?>">Detalle</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="pager">
        <?= $pager->links() ?>
    </div>
</body>

</html>