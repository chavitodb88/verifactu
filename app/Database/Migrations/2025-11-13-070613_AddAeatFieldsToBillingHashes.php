<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAeatFieldsToBillingHashes extends Migration
{
    public function up()
    {
        $fields = [
            'aeat_csv' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'after'      => 'hash', // ajústalo si el orden no encaja
            ],
            'aeat_estado_envio' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'after'      => 'aeat_csv',
            ],
            'aeat_estado_registro' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'after'      => 'aeat_estado_envio',
            ],
            'aeat_codigo_error' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'after'      => 'aeat_estado_registro',
            ],
            'aeat_descripcion_error' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'aeat_codigo_error',
            ],
        ];

        $this->forge->addColumn('billing_hashes', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', 'aeat_csv');
        $this->forge->dropColumn('billing_hashes', 'aeat_estado_envio');
        $this->forge->dropColumn('billing_hashes', 'aeat_estado_registro');
        $this->forge->dropColumn('billing_hashes', 'aeat_codigo_error');
        $this->forge->dropColumn('billing_hashes', 'aeat_descripcion_error');
    }
}
