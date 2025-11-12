<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class SubmissionsModel extends Model
{
    protected $table         = 'submissions';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;

    protected $allowedFields = [
        'billing_hash_id',
        'type',
        'status',
        'request_ref',
        'response_ref',
        'error_code',
        'error_message',
        'raw_req_path',
        'raw_res_path',
        'attempt_number'
    ];
}
