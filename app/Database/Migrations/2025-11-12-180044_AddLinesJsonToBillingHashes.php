<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddLinesJsonToBillingHashes extends Migration
{
    public function up()
    {
        // Para MySQL 5.7+ existe tipo JSON.
        // Si algún entorno raro no lo soporta, usa LONGTEXT como fallback.
        $driver = strtolower($this->db->DBDriver ?? '');
        $jsonType = 'JSON';
        if ($driver === 'mysqli') {
            // opcional: puedes inspeccionar versión para decidir JSON vs LONGTEXT
            // $v = $this->db->getVersion(); // e.g. '8.0.37'
            // if (version_compare($v, '5.7.0', '<')) { $jsonType = 'LONGTEXT'; }
        }

        $this->forge->addColumn('billing_hashes', [
            'lines_json' => [
                'type'       => $jsonType,
                'null'       => true,
                'after'      => 'qr_url', // o el último campo que tengas
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', 'lines_json');
    }
}
