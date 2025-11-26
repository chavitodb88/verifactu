<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddClientAndMetaFieldsToBillingHashes extends Migration
{
    public function up()
    {
        $fields = [
            'issuer_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'issuer_nif',
            ],

            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'issue_date',
            ],

            // Datos básicos de cliente para PDF / filtros / panel
            'client_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'client_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'client_postal_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => true,
            ],
            'client_city' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'client_province' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'client_country_code' => [
                'type'       => 'CHAR',
                'constraint' => 2,
                'null'       => true,
            ],
            'client_document' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                // aquí irá normalmente el NIF o IDOtro
            ],

            // Régimen y calificación (por ahora fijos a 01 / S1, pero ya preparados)
            'tax_regime_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 4,
                'null'       => false,
                'default'    => '01',
            ],
            'operation_qualification' => [
                'type'       => 'VARCHAR',
                'constraint' => 4,
                'null'       => false,
                'default'    => 'S1',
            ],
        ];

        $this->forge->addColumn('billing_hashes', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', [
            'issuer_name',
            'description',
            'client_name',
            'client_address',
            'client_postal_code',
            'client_city',
            'client_province',
            'client_country_code',
            'client_document',
            'tax_regime_code',
            'operation_qualification',
        ]);
    }
}
