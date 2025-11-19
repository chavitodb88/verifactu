<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddIssuerNifToCompanies extends Migration
{
    public function up()
    {
        $this->forge->addColumn('companies', [
            'issuer_nif' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'after'      => 'name',
            ],
        ]);

        //add is_active default 1 to existing records

        $this->forge->addColumn('companies', [
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'after'      => 'issuer_nif',
            ],
        ]);

        // Índice opcional para búsqueda por NIF emisor
        $this->db->query('CREATE INDEX idx_companies_issuer_nif ON companies (issuer_nif)');


    }

    public function down()
    {
        $this->forge->dropColumn('companies', 'issuer_nif');
    }
}
