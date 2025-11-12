<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class ApiKeysSeeder extends Seeder
{
    public function run()
    {
        // Ojo: esto es demo. En producción genera y guarda un hash/uuid robusto.
        $apiKey = 'dev_acme_key_00000000000000000000000000000000000000000000000000000000';

        // Busca la empresa 'acme'
        $company = $this->db->table('companies')->getWhere(['slug' => 'acme'])->getRowArray();
        $this->db->table('api_keys')->insert([
            'company_id' => $company ? (int) $company['id'] : 1,
            'api_key'    => $apiKey,
            'scopes'     => json_encode(['invoices:*']),
            'active'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
