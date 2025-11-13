<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class BillingHashModel extends Model
{
    protected $table         = 'billing_hashes';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'company_id',
        'issuer_nif',
        'series',
        'number',
        'issue_date',
        'external_id',
        'kind',
        'hash',
        'prev_hash',
        'chain_index',
        'qr_url',
        'csv_text',
        'xml_path',
        'status',
        'idempotency_key',
        'next_attempt_at',
        'processing_at',
        'fecha_huso',
        'lines_json',
        'detalle_json',
        'cuota_total',
        'importe_total',
        'aeat_csv',
        'aeat_estado_envio',
        'aeat_estado_registro',
        'aeat_codigo_error',
        'aeat_descripcion_error',
        'created_at',
        'updated_at',
        'pdf_path'
    ];

    public function getPrevHashAndNextIndex(int $companyId, ?string $issuerNif = null, ?string $series = null): array
    {
        $builder = $this->select('hash, chain_index')
            ->where('company_id', $companyId)
            ->where('hash IS NOT NULL', null, false);

        if ($issuerNif !== null) {
            $builder->where('issuer_nif', $issuerNif);
        }
        if ($series !== null) {
            $builder->where('series', $series);
        }

        $row = $builder->orderBy('chain_index', 'DESC')->first();

        if (!$row) {
            return [null, 1];
        }
        return [(string)$row['hash'], (int)$row['chain_index'] + 1];
    }
}
