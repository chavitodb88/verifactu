<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class CompaniesModel extends Model
{
    protected $table = 'companies';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
}
