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
        'hash',
        'prev_hash',
        'chain_index',
        'qr_url',
        'csv_text',
        'xml_path',
        'status',
        'idempotency_key'
    ];
}
