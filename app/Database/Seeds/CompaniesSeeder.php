<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class CompaniesSeeder extends Seeder
{
    public function run()
    {
        $this->db->table('companies')->insert([
            'slug'                       => 'acme',
            'name'                       => 'ACME S.L.',
            'issuer_nif'                 => 'B61206934',
            'verifactu_enabled'          => 1,
            'send_to_aeat'               => 0,
            'nif_colaborador'            => null,
            'storage_driver'             => 'fs',
            'storage_base_path'          => 'acme/',
            'created_at'                 => date('Y-m-d H:i:s'),
            'updated_at'                 => date('Y-m-d H:i:s'),
        ]);
    }
}
