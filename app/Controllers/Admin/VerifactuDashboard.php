<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\BillingHashModel;
use App\Models\SubmissionsModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

final class VerifactuDashboard extends BaseController
{
    /**
     * Listado con filtros y paginación.
     */
    public function index(): string
    {
        $request = $this->request;

        // Modelo base
        $base = new BillingHashModel();

        // Filtros
        $filters = [
            'company_id'           => trim((string) ($request->getGet('company_id') ?? '')),
            'issuer_nif'           => trim((string) ($request->getGet('issuer_nif') ?? '')),
            'series'               => trim((string) ($request->getGet('series') ?? '')),
            'status'               => trim((string) ($request->getGet('status') ?? '')),
            'aeat_send_status'     => trim((string) ($request->getGet('aeat_send_status') ?? '')),
            'aeat_register_status' => trim((string) ($request->getGet('aeat_register_status') ?? '')),
            'date_from'            => trim((string) ($request->getGet('date_from') ?? '')),
            'date_to'              => trim((string) ($request->getGet('date_to') ?? '')),
        ];

        // Aplicar filtros sobre $base (todos los que quieras que afecten a tabla + stats)
        if ($filters['company_id'] !== '') {
            $base->where('company_id', (int) $filters['company_id']);
        }
        if ($filters['issuer_nif'] !== '') {
            $base->where('issuer_nif', $filters['issuer_nif']);
        }
        if ($filters['series'] !== '') {
            $base->where('series', $filters['series']);
        }
        if ($filters['status'] !== '') {
            $base->where('status', $filters['status']); // draft|ready|sent|accepted|...
        }
        if ($filters['aeat_send_status'] !== '') {
            $base->where('aeat_send_status', $filters['aeat_send_status']);
        }
        if ($filters['aeat_register_status'] !== '') {
            $base->where('aeat_register_status', $filters['aeat_register_status']);
        }
        if ($filters['date_from'] !== '') {
            $base->where('issue_date >=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $base->where('issue_date <=', $filters['date_to']);
        }

        // Paginación
        $perPage = (int) ($request->getGet('per_page') ?? 25);
        if ($perPage <= 0 || $perPage > 200) {
            $perPage = 25;
        }

        // IMPORTANTE: clonar ANTES del paginate, para que las stats
        // usen los mismos filtros pero sin romper el pager.
        $statsBuilder = clone $base;

        // Tabla principal
        $rows  = $base->orderBy('id', 'DESC')->paginate($perPage);
        $pager = $base->pager;

        // Stats por status (sobre el mismo filtro que la tabla)
        $statusRows = $statsBuilder
            ->select('status, COUNT(*) AS total')
            ->groupBy('status')
            ->findAll();

        $statusCounts = [];
        foreach ($statusRows as $row) {
            $statusCounts[$row['status']] = (int) $row['total'];
        }

        // Cajas de arriba
        $totalRegistros = array_sum($statusCounts);
        $readyCount     = $statusCounts['ready'] ?? 0;
        $sentCount      = $statusCounts['sent']  ?? 0;
        $errorCount     = $statusCounts['error'] ?? 0;

        // Artefactos existentes por id
        $filesById = [];
        foreach ($rows as $row) {
            $id              = (int) $row['id'];
            $filesById[$id]  = $this->buildPaths($id, $row);
        }

        return view('admin/verifactu/index', [
            'rows'           => $rows,
            'pager'          => $pager,
            'filters'        => $filters,
            'perPage'        => $perPage,
            'statusCounts'   => $statusCounts,
            'filesById'      => $filesById,
            'totalRegistros' => $totalRegistros,
            'readyCount'     => $readyCount,
            'sentCount'      => $sentCount,
            'errorCount'     => $errorCount,
        ]);
    }


    /**
     * Detalle de un registro de billing_hashes + submissions.
     */
    public function show(int $id): string
    {
        $bhModel = new BillingHashModel();
        $row     = $bhModel->find($id);

        if ($row === null) {
            throw new PageNotFoundException('Registro de VERI*FACTU no encontrado');
        }

        $submissions = (new SubmissionsModel())
            ->where('billing_hash_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();

        $paths = $this->buildPaths($id, $row);

        return view('admin/verifactu/show', [
            'row'         => $row,
            'submissions' => $submissions,
            'paths'       => $paths,
        ]);
    }

    /**
     * Descarga segura de ficheros (preview, request, response, pdf).
     */
    public function file(int $id, string $type): ResponseInterface
    {
        $bhModel = new BillingHashModel();
        $row     = $bhModel->find($id);

        if ($row === null) {
            throw new PageNotFoundException('Registro de VERI*FACTU no encontrado');
        }

        $paths = $this->buildPaths($id, $row);

        if (! isset($paths[$type]) || $paths[$type] === null) {
            throw new PageNotFoundException('Fichero no disponible para este registro');
        }

        $path     = $paths[$type];
        $ext      = pathinfo((string) $path, PATHINFO_EXTENSION);
        $fileName = $type . '-' . $id . ($ext ? '.' . $ext : '');

        return $this->response->download($path, null)->setFileName($fileName);
    }

    /**
     * Devolver el PNG del QR embebible en <img>.
     */
    public function qr(int $id): ResponseInterface
    {
        $bhModel = new BillingHashModel();
        $row     = $bhModel->find($id);

        if ($row === null) {
            throw new PageNotFoundException('Registro de VERI*FACTU no encontrado');
        }

        $paths  = $this->buildPaths($id, $row);
        $qrPath = $paths['qr'] ?? null;

        if ($qrPath === null) {
            throw new PageNotFoundException('QR no generado todavía');
        }

        return $this->response
            ->setHeader('Content-Type', 'image/png')
            ->setBody((string) file_get_contents($qrPath));
    }

    /**
     * Construye las rutas de los artefactos técnicos de un registro.
     *
     * - preview: WRITEPATH/verifactu/previews/{id}-preview.xml
     * - request: WRITEPATH/verifactu/requests/{id}-request.xml
     * - response: WRITEPATH/verifactu/responses/{id}-response.xml
     * - pdf: billing_hashes.pdf_path (si existe)
     * - qr: WRITEPATH/verifactu/qr/{id}.png
     */
    private function buildPaths(int $id, array $row): array
    {
        $base = rtrim(WRITEPATH, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'verifactu';

        $previewPath  = $base . DIRECTORY_SEPARATOR . 'previews'  . DIRECTORY_SEPARATOR . $id . '-preview.xml';
        $requestPath  = $base . DIRECTORY_SEPARATOR . 'requests'  . DIRECTORY_SEPARATOR . $id . '-request.xml';
        $responsePath = $base . DIRECTORY_SEPARATOR . 'responses' . DIRECTORY_SEPARATOR . $id . '-response.xml';
        $qrPath       = $base . DIRECTORY_SEPARATOR . 'qr'        . DIRECTORY_SEPARATOR . $id . '.png';

        $pdfPath = $row['pdf_path'] ?? null;

        return [
            'preview'  => is_file($previewPath) ? $previewPath : null,
            'request'  => is_file($requestPath) ? $requestPath : null,
            'response' => is_file($responsePath) ? $responsePath : null,
            'pdf'      => ($pdfPath && is_file((string) $pdfPath)) ? (string) $pdfPath : null,
            'qr'       => is_file($qrPath) ? $qrPath : null,
        ];
    }
}
