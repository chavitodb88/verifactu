<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddFechaHusoToBillingHashes extends Migration
{
    public function up()
    {
        $this->forge->addColumn('billing_hashes', [
            'datetime_offset' => [
                'type'       => 'VARCHAR',
                'constraint' => 35, // ej: 2025-11-12T20:12:52+01:00
                'null'       => true,
                'after'      => 'csv_text',
            ],
        ]);

        if (ENVIRONMENT !== 'testing') {
            $this->db->query('CREATE INDEX idx_bh_datetime_offset ON billing_hashes (datetime_offset)');
        }
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', 'datetime_offset');
        // El índice cae con la columna, pero por claridad:
        // $this->db->query('DROP INDEX idx_bh_datetime_offset ON billing_hashes');
    }
}
