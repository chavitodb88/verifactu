<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

/**
 * @OA\Info(title="VERI*FACTU Middleware API", version="1.0.0")
 * @OA\Server(url="/api/v1", description="v1 base path")
 */
final class HealthController extends ResourceController
{
    /**
     * @OA\Get(
     *   path="/health",
     *   summary="Healthcheck",
     *   tags={"Meta"},
     *   @OA\Response(response=200, description="OK")
     * )
     */
    public function index()
    {
        // Company inyectada por el filtro (si quieres devolverla para debug)
        $company = isset($this->request->company) ? $this->request->company : null;

        return $this->respond([
            'status'  => 'ok',
            'company' => $company,
            'ts'      => time(),
        ]);
    }
}
