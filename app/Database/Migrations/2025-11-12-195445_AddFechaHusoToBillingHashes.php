<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddFechaHusoToBillingHashes extends Migration
{
    public function up()
    {
        $this->forge->addColumn('billing_hashes', [
            'fecha_huso' => [
                'type'       => 'VARCHAR',
                'constraint' => 35, // ej: 2025-11-12T20:12:52+01:00
                'null'       => true,
                'after'      => 'csv_text',
            ],
        ]);

        // Index opcional para consultas/depuración
        $this->db->query('CREATE INDEX idx_bh_fecha_huso ON billing_hashes (fecha_huso)');
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', 'fecha_huso');
        // El índice cae con la columna, pero por claridad:
        // $this->db->query('DROP INDEX idx_bh_fecha_huso ON billing_hashes');
    }
}
